#!/usr/bin/env python3
"""Live-mode contract check for the modern engine.

This is the same stdin/stdout exchange RPA-Python uses: wait for the live
banner, bracket each instruction with echo markers, dump results to a file,
and send done. It also drives the MCP LiveSession against the real tagui script.
"""

from __future__ import annotations

import os
import select
import subprocess
import sys
import tempfile
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TAGUI = ROOT / "src" / "tagui"
PAGE = ROOT / "src" / "test" / "fixtures" / "modern_page.html"
MCP_SRC = ROOT / "src" / "tagui_mcp"


def fail(message: str) -> None:
    print("FAIL", message, file=sys.stderr)
    raise SystemExit(1)


def read_until(process: subprocess.Popen[bytes], needle: str, timeout: float) -> bytes:
    deadline = time.monotonic() + timeout
    buffer = b""
    assert process.stdout is not None
    while time.monotonic() < deadline:
        if needle.encode() in buffer:
            return buffer
        remaining = deadline - time.monotonic()
        ready, _, _ = select.select([process.stdout], [], [], min(0.5, remaining))
        if not ready:
            if process.poll() is not None:
                break
            continue
        chunk = os.read(process.stdout.fileno(), 4096)
        if chunk == b"":
            break
        buffer += chunk
    tail = buffer[-400:]
    err = b""
    if process.stderr is not None:
        err = process.stderr.read(800) if process.poll() is not None else b""
    fail(f"timed out waiting for {needle!r}. saw {tail!r} stderr {err!r}")
    return buffer


def write(process: subprocess.Popen[bytes], text: str) -> None:
    assert process.stdin is not None
    process.stdin.write(text.encode())
    process.stdin.flush()


def rpa_round_trip(workdir: Path) -> None:
    """Speak the RPA-Python init/send/close sequence to a live flow."""
    flow = workdir / "rpa_python"
    flow.write_text("live\n", encoding="utf-8")
    env = os.environ.copy()
    env["TAGUI_CHROME_ISOLATED"] = "1"
    process = subprocess.Popen(
        [str(TAGUI), str(flow), "headless", "modern", "quiet"],
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        cwd=str(workdir),
        env=env,
    )
    try:
        read_until(process, "LIVE MODE - type done to quit", 40)
        write(process, 'echo "[RPA][STARTED]"\n')
        write(process, 'echo "[RPA][0] - listening for inputs"\n')
        read_until(process, "[RPA][0] - listening for inputs", 20)

        def send(instruction: str, ident: int) -> None:
            safe = instruction.replace("\\", "\\\\").replace('"', '\\"')
            write(process, f'echo "[RPA][{ident}] - {safe}"\n')
            write(process, instruction + "\n")
            write(process, f'echo "[RPA][{ident}] - listening for inputs"\n')
            read_until(process, f"[RPA][{ident}] - listening for inputs", 30)

        send(f"file://{PAGE}", 1)
        send("exist_result = exist('Hello TagUI').toString()", 2)
        dump_path = workdir / "rpa_python.txt"
        send(f"dump exist_result to {dump_path}", 3)
        if dump_path.read_text(encoding="utf-8").strip() != "true":
            fail(f"exist() dump was {dump_path.read_text(encoding='utf-8')!r}")
        send("type name as [clear]live-hello", 4)
        send("click Go", 5)
        send("read #out to read_result", 6)
        send(f"dump read_result to {dump_path}", 7)
        if dump_path.read_text(encoding="utf-8").strip() != "live-hello":
            fail(f"read dump was {dump_path.read_text(encoding='utf-8')!r}")
        send("https://example.com", 8)
        send(f"dump url() to {dump_path}", 9)
        url = dump_path.read_text(encoding="utf-8").strip()
        if not url.startswith("https://example.com"):
            fail(f"url() dump was {url!r}")
        write(process, 'echo "[RPA][FINISHED]"\n')
        write(process, "done\n")
        try:
            process.wait(timeout=20)
        except subprocess.TimeoutExpired:
            fail("tagui did not exit after done")
    finally:
        if process.poll() is None:
            process.kill()
            process.wait(timeout=5)


def mcp_round_trip(workdir: Path) -> None:
    sys.path.insert(0, str(MCP_SRC))
    from tagui_mcp.session import LiveSession

    session = LiveSession(tagui_bin=TAGUI)
    session.start("headless", startup_timeout=60, engine="modern")
    try:
        echoed = session.run_step('echo "from mcp"', timeout=20)
        if echoed.strip() != "from mcp":
            fail(f"mcp echo was {echoed!r}")
        session.run_step(f"file://{PAGE}", timeout=30)
        title = session.dump("title()", workdir / "title.txt", timeout=20)
        if title != "Modern PoC":
            fail(f"mcp title was {title!r}")
    finally:
        session.stop()


def main() -> None:
    os.environ["TAGUI_CHROME_ISOLATED"] = "1"
    with tempfile.TemporaryDirectory(prefix="tagui_modern_live_") as directory:
        workdir = Path(directory)
        rpa_round_trip(workdir)
        print("ok   rpa live contract")
        mcp_round_trip(workdir)
        print("ok   mcp live session")
    print("all live checks passed")


if __name__ == "__main__":
    main()
