"""Persistent TagUI live-mode session.

Live mode is the interactive prompt started by a flow whose only step is
``live`` (see tagui_header.js). Steps are synchronous when Chrome is used:
the prompt reads one line, runs it, then reads the next line. There is no
prompt after each step, so each call writes the step and then an echo marker
and reads stdout until that marker comes back.
"""

from __future__ import annotations

import os
import select
import shutil
import signal
import subprocess
import tempfile
import threading
import time
import uuid
from pathlib import Path
from typing import Literal, NoReturn

from tagui_mcp.runner import TaguiFailure, reap_tagui_chrome, resolve_tagui_executable

BrowserMode = Literal["headless", "chrome"]
LIVE_BANNER = "LIVE MODE - type done to quit"


def _never(value: NoReturn) -> NoReturn:
    raise TaguiFailure(f"unsupported browser mode: {value}")


def browser_flag(mode: BrowserMode) -> str:
    if mode == "headless":
        return "headless"
    if mode == "chrome":
        return "chrome"
    _never(mode)


class LiveSession:
    """One TagUI live-mode process. Not safe to share across threads without a lock."""

    def __init__(self, tagui_bin: str | Path | None = None) -> None:
        self._tagui_bin = tagui_bin
        self._proc: subprocess.Popen[bytes] | None = None
        self._stdout_buf = b""
        self._stderr_chunks: list[bytes] = []
        self._stderr_thread: threading.Thread | None = None
        self._workdir: Path | None = None
        self._mode: BrowserMode | None = None
        self._id = 0
        self._running = False

    @property
    def running(self) -> bool:
        if not self._running or self._proc is None:
            return False
        if self._proc.poll() is not None:
            self._running = False
            return False
        return True

    @property
    def mode(self) -> BrowserMode | None:
        return self._mode

    @property
    def workdir(self) -> Path | None:
        return self._workdir

    def start(self, mode: BrowserMode, startup_timeout: float = 90) -> None:
        if self.running:
            raise TaguiFailure("browser session already running; call browser_stop first")
        self._reap()
        self._workdir = Path(tempfile.mkdtemp(prefix="tagui_mcp_live_"))
        flow_path = self._workdir / "mcp_live"
        flow_path.write_text("// TagUI MCP live session\nlive\n", encoding="utf-8")
        executable = resolve_tagui_executable(self._tagui_bin)
        command = [str(executable), str(flow_path), browser_flag(mode), "quiet"]
        try:
            self._proc = subprocess.Popen(
                command,
                stdin=subprocess.PIPE,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                start_new_session=True,
                cwd=str(self._workdir),
            )
        except OSError as exc:
            self._reap()
            raise TaguiFailure(f"could not start TagUI live mode: {exc}") from exc
        self._start_stderr_drain()
        try:
            self._read_until_contains(LIVE_BANNER, startup_timeout)
            ready = f"TAGUI_MCP_READY_{uuid.uuid4().hex[:8]}"
            self._write(f'echo "{ready}"\n')
            self._read_until_contains(ready, 30)
        except TaguiFailure:
            self._reap()
            raise
        except Exception as exc:
            self._reap()
            raise TaguiFailure(f"could not start TagUI live mode: {exc}") from exc
        self._mode = mode
        self._running = True

    def stop(self) -> None:
        process = self._proc
        if process is not None and process.poll() is None and process.stdin is not None:
            try:
                process.stdin.write(b"done\n")
                process.stdin.flush()
                process.wait(timeout=5)
            except (OSError, subprocess.TimeoutExpired):
                pass
        self._reap()

    def run_step(self, step: str, timeout: float = 45) -> str:
        checked = check_live_step(step)
        if not self.running:
            raise TaguiFailure("browser session is not running; call browser_start first")
        self._id += 1
        marker = f"TAGUI_MCP_END_{self._id}_{uuid.uuid4().hex[:8]}"
        self._write(checked + "\n")
        self._write(f'echo "{marker}"\n')
        output = self._read_until_contains(marker, timeout).replace("\b", "")
        return output.strip("\n")

    def dump(self, expression: str, dest: Path, timeout: float = 45) -> str:
        if any(character in expression for character in "\n\r\"'`"):
            raise TaguiFailure("dump expression contains characters live mode cannot quote")
        if " " in str(dest):
            raise TaguiFailure("dump path cannot contain spaces")
        if dest.exists():
            dest.unlink()
        output = self.run_step(f"dump {expression} to {dest}", timeout=timeout)
        if "ERROR -" in output:
            raise TaguiFailure(output.strip())
        if not dest.is_file():
            return ""
        return dest.read_text(encoding="utf-8", errors="replace").strip()

    def _write(self, text: str) -> None:
        process = self._proc
        if process is None or process.stdin is None:
            raise TaguiFailure("browser session is not running")
        try:
            process.stdin.write(text.encode("utf-8"))
            process.stdin.flush()
        except (BrokenPipeError, OSError) as exc:
            self._reap()
            raise TaguiFailure(f"lost the TagUI process while sending a step ({exc})") from exc

    def _readline(self, timeout: float) -> str:
        """Read one stdout line.

        A buffered readline() consumes past the newline into a hidden buffer.
        select() then waits for bytes that have already been read, so later
        live-mode lines look like they never arrived. Read the pipe directly
        and keep the spare bytes in ``_stdout_buf``.
        """
        process = self._proc
        if process is None or process.stdout is None:
            raise TaguiFailure("browser session is not running")
        deadline = time.monotonic() + timeout
        stream = process.stdout
        while b"\n" not in self._stdout_buf:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise TaguiFailure(f"timed out waiting for TagUI ({self._stderr_tail()})")
            ready, _, _ = select.select([stream], [], [], remaining)
            if not ready:
                raise TaguiFailure(f"timed out waiting for TagUI ({self._stderr_tail()})")
            try:
                chunk = os.read(stream.fileno(), 4096)
            except OSError as exc:
                raise TaguiFailure(f"could not read TagUI output ({exc})") from exc
            if chunk == b"":
                code = process.poll()
                raise TaguiFailure(
                    f"TagUI exited unexpectedly (code {code}). {self._stderr_tail()}"
                )
            self._stdout_buf += chunk
        raw, _, self._stdout_buf = self._stdout_buf.partition(b"\n")
        return raw.decode("utf-8", errors="replace") + "\n"

    def _read_until_contains(self, marker: str, timeout: float) -> str:
        deadline = time.monotonic() + timeout
        collected: list[str] = []
        try:
            while True:
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise TaguiFailure(f"timed out waiting for TagUI ({self._stderr_tail()})")
                line = self._readline(remaining)
                if marker in line:
                    return "".join(collected)
                collected.append(line)
                if sum(len(item) for item in collected) > 200_000:
                    raise TaguiFailure("TagUI produced too much output for one step")
        except TaguiFailure as exc:
            recent = "".join(collected)[-1500:]
            self._reap()
            detail = f"{exc}"
            if recent.strip():
                detail = f"{detail} Recent output: {recent.strip()}"
            raise TaguiFailure(detail) from exc

    def _start_stderr_drain(self) -> None:
        process = self._proc
        if process is None or process.stderr is None:
            return
        stderr = process.stderr
        self._stderr_chunks = []

        def _drain() -> None:
            try:
                while True:
                    chunk = stderr.read(4096)
                    if not chunk:
                        break
                    self._stderr_chunks.append(chunk)
            except OSError:
                return

        self._stderr_thread = threading.Thread(target=_drain, name="tagui-mcp-stderr", daemon=True)
        self._stderr_thread.start()

    def _stderr_tail(self) -> str:
        if not self._stderr_chunks:
            return ""
        text = b"".join(self._stderr_chunks).decode("utf-8", errors="replace").strip()
        if len(text) > 1500:
            return text[-1500:]
        return text

    def _reap(self) -> None:
        process = self._proc
        self._running = False
        self._mode = None
        self._stdout_buf = b""
        if process is not None and process.poll() is None:
            try:
                os.killpg(process.pid, signal.SIGTERM)
            except (ProcessLookupError, PermissionError):
                pass
            try:
                process.wait(timeout=3)
            except subprocess.TimeoutExpired:
                try:
                    os.killpg(process.pid, signal.SIGKILL)
                except (ProcessLookupError, PermissionError):
                    pass
                try:
                    process.wait(timeout=3)
                except subprocess.TimeoutExpired:
                    pass
        reap_tagui_chrome()
        if self._stderr_thread is not None:
            self._stderr_thread.join(timeout=1)
            self._stderr_thread = None
        self._proc = None
        if self._workdir is not None:
            shutil.rmtree(self._workdir, ignore_errors=True)
            self._workdir = None


def check_live_step(step: str) -> str:
    if not isinstance(step, str):
        raise TaguiFailure("step must be a string")
    checked = step.strip()
    if checked == "":
        raise TaguiFailure("step is empty")
    if "\n" in step or "\r" in step or "\x00" in step:
        raise TaguiFailure("a live-mode step must be a single line; use run_steps for a multi-line flow")
    if checked.startswith("done"):
        raise TaguiFailure("a step starting with 'done' would end live mode; call browser_stop instead")
    if len(checked) > 8000:
        raise TaguiFailure("step exceeds 8000 characters")
    return checked
