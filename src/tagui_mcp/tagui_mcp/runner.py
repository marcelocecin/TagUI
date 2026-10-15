"""Run TagUI flow files through the existing command-line runner.

The runner shell script only forwards eight arguments after the flow file
($2 through $9). Options, a datatable path, and p1-p8 inputs share that budget.
"""

from __future__ import annotations

import os
import shutil
import signal
import subprocess
import tempfile
from pathlib import Path

ALLOWED_OPTIONS = frozenset(
    {
        "chrome",
        "headless",
        "firefox",
        "report",
        "upload",
        "speed",
        "quiet",
        "debug",
        "test",
        "baseline",
    }
)
BROWSER_OPTIONS = frozenset({"chrome", "headless", "firefox"})
ALLOWED_EXTENSIONS = frozenset({"", "txt", "js", "tagui", "gui"})
MAX_FORWARDED_ARGS = 8
MAX_OUTPUT_CHARS = 100_000
MAX_STDERR_CHARS = 8_000


class TaguiFailure(Exception):
    """Expected TagUI or usage failure that a tool should report to the caller."""

    def __init__(self, message: str, **details: object) -> None:
        super().__init__(message)
        self.details = details


def tagui_script_name() -> str:
    if os.name == "nt":
        return "tagui.cmd"
    return "tagui"


def resolve_tagui_executable(explicit: str | Path | None = None) -> Path:
    """Locate the tagui script shipped with this repo, or one named by the environment."""
    candidates: list[Path] = []
    if explicit:
        candidates.append(Path(explicit))
    env_bin = os.environ.get("TAGUI_BIN")
    if env_bin:
        candidates.append(Path(env_bin))
    env_src = os.environ.get("TAGUI_SRC")
    if env_src:
        candidates.append(Path(env_src) / tagui_script_name())
    candidates.append(Path(__file__).resolve().parents[2] / tagui_script_name())
    discovered = shutil.which("tagui")
    if discovered:
        candidates.append(Path(discovered))

    for candidate in candidates:
        if candidate.is_file():
            return candidate.resolve()
    raise TaguiFailure(
        "TagUI executable not found. Set TAGUI_SRC to the directory that contains "
        "the tagui script, or TAGUI_BIN to the script itself."
    )


def validate_options(options: list[str] | None) -> list[str]:
    """Return the CLI flags to pass through. None means quiet mode only."""
    if options is None:
        return ["quiet"]
    chosen: list[str] = []
    for option in options:
        if option not in ALLOWED_OPTIONS:
            allowed = ", ".join(sorted(ALLOWED_OPTIONS))
            raise TaguiFailure(f"unsupported option {option!r}. Allowed: {allowed}")
        if option in chosen:
            raise TaguiFailure(f"duplicate option {option!r}")
        chosen.append(option)
    browsers = [item for item in chosen if item in BROWSER_OPTIONS]
    if len(browsers) > 1:
        raise TaguiFailure("choose only one of chrome, headless, firefox")
    return chosen


def validate_inputs(inputs: list[str] | None) -> list[str]:
    if not inputs:
        return []
    cleaned: list[str] = []
    for value in inputs:
        if not isinstance(value, str) or value == "":
            raise TaguiFailure("inputs must be non-empty strings")
        if any(character.isspace() or character == "\x00" for character in value):
            raise TaguiFailure(
                "TagUI splits parameters on whitespace; an input cannot contain spaces or newlines"
            )
        if value in ALLOWED_OPTIONS:
            raise TaguiFailure(
                f"input {value!r} is a TagUI option name and would be consumed as a flag"
            )
        if value.lower().endswith(".csv"):
            raise TaguiFailure("inputs cannot end in .csv; pass a datatable with the datatable argument")
        cleaned.append(value)
    return cleaned


def check_flow_file(flow_file: str) -> Path:
    if not flow_file or not flow_file.strip():
        raise TaguiFailure("flow_file is empty")
    path = Path(flow_file).expanduser().resolve()
    if not path.is_file():
        raise TaguiFailure(f"cannot find flow file: {path}")
    extension = path.suffix.lower().lstrip(".")
    if extension not in ALLOWED_EXTENSIONS:
        raise TaguiFailure(
            f"flow file extension .{extension} is not accepted by TagUI. "
            "Use no extension, .txt, .js, or .tagui"
        )
    return path


def check_datatable(datatable: str | None) -> Path | None:
    if datatable is None or datatable == "":
        return None
    path = Path(datatable).expanduser().resolve()
    if path.suffix.lower() != ".csv":
        raise TaguiFailure("datatable must be a .csv file")
    if not path.is_file():
        raise TaguiFailure(f"cannot find datatable: {path}")
    return path


def _truncate(text: str, limit: int) -> tuple[str, bool]:
    if len(text) <= limit:
        return text, False
    return text[:limit] + "\n...[truncated]", True


def _error_lines(*chunks: str) -> list[str]:
    found: list[str] = []
    seen: set[str] = set()
    for chunk in chunks:
        for line in chunk.splitlines():
            if "ERROR -" not in line:
                continue
            cleaned = line.strip()
            if cleaned in seen:
                continue
            seen.add(cleaned)
            found.append(cleaned)
            if len(found) == 50:
                return found
    return found


def _runtime_problem(stderr: str) -> str | None:
    for needle in ("command not found", "No such file or directory", "cannot find Chrome"):
        if needle in stderr:
            return needle
    return None


def _kill_process_group(pid: int) -> None:
    try:
        os.killpg(pid, signal.SIGTERM)
    except (ProcessLookupError, PermissionError):
        return
    try:
        os.killpg(pid, signal.SIGKILL)
    except (ProcessLookupError, PermissionError):
        return


def reap_tagui_chrome() -> None:
    """Stop a Chrome left behind on TagUI's debugging port and profile.

    TagUI launches Chrome in the background, and Chrome often leaves the
    process group, so killing the runner does not always close the browser.
    The match is the same pair of markers src/end_processes uses.
    """
    if os.name != "posix":
        return
    try:
        listed = subprocess.run(
            ["ps", "x", "-o", "pid=,command="],
            capture_output=True,
            text=True,
            timeout=5,
            check=False,
        )
    except (OSError, subprocess.TimeoutExpired):
        return
    for line in listed.stdout.splitlines():
        chrome = "remote-debugging-port=9222" in line and "tagui_user_profile" in line
        helper = "tagui_chrome.php" in line
        if not chrome and not helper:
            continue
        pid_text = line.strip().split(maxsplit=1)[0]
        if not pid_text.isdigit():
            continue
        try:
            os.kill(int(pid_text), signal.SIGTERM)
        except (ProcessLookupError, PermissionError):
            continue


def run_flow(
    flow_file: str,
    options: list[str] | None = None,
    inputs: list[str] | None = None,
    datatable: str | None = None,
    timeout_seconds: float = 120,
    tagui_bin: str | Path | None = None,
) -> dict[str, object]:
    """Execute one flow file. Returns a JSON-ready result and does not raise TaguiFailure."""
    try:
        return _run_flow(flow_file, options, inputs, datatable, timeout_seconds, tagui_bin)
    except TaguiFailure as exc:
        payload: dict[str, object] = {"ok": False, "error": str(exc)}
        payload.update(exc.details)
        return payload


def _run_flow(
    flow_file: str,
    options: list[str] | None,
    inputs: list[str] | None,
    datatable: str | None,
    timeout_seconds: float,
    tagui_bin: str | Path | None,
) -> dict[str, object]:
    if timeout_seconds <= 0:
        raise TaguiFailure("timeout_seconds must be greater than 0")
    flow_path = check_flow_file(flow_file)
    chosen = validate_options(options)
    parameters = validate_inputs(inputs)
    table_path = check_datatable(datatable)
    extra = len(chosen) + len(parameters) + (1 if table_path else 0)
    if extra > MAX_FORWARDED_ARGS:
        raise TaguiFailure(
            "TagUI only forwards 8 arguments after the flow file. "
            "Reduce options, inputs, or omit the datatable."
        )
    executable = resolve_tagui_executable(tagui_bin)
    command = [str(executable), str(flow_path), *chosen]
    if table_path is not None:
        command.append(str(table_path))
    command.extend(parameters)

    try:
        process = subprocess.Popen(
            command,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            start_new_session=True,
            cwd=str(flow_path.parent),
        )
    except OSError as exc:
        raise TaguiFailure(f"could not start TagUI: {exc}") from exc

    timed_out = False
    try:
        stdout, stderr = process.communicate(timeout=timeout_seconds)
    except subprocess.TimeoutExpired:
        timed_out = True
        _kill_process_group(process.pid)
        reap_tagui_chrome()
        stdout, stderr = process.communicate()
    exit_code = process.returncode
    output, output_truncated = _truncate(stdout or "", MAX_OUTPUT_CHARS)
    error_output, stderr_truncated = _truncate(stderr or "", MAX_STDERR_CHARS)
    log_path = Path(str(flow_path) + ".log")
    log_text = ""
    if log_path.is_file():
        try:
            log_text = log_path.read_text(encoding="utf-8", errors="replace")
        except OSError:
            log_text = ""
    problems = _error_lines(output, log_text, error_output)
    runtime_problem = _runtime_problem(error_output)
    ok = exit_code == 0 and not problems and runtime_problem is None and not timed_out
    result: dict[str, object] = {
        "ok": ok,
        "exit_code": exit_code,
        "output": output,
        "stderr": error_output,
        "error_lines": problems,
        "log_file": str(log_path) if log_path.is_file() else None,
        "flow_file": str(flow_path),
        "command": command,
        "truncated": output_truncated or stderr_truncated,
        "timed_out": timed_out,
    }
    if timed_out:
        result["error"] = f"TagUI run exceeded {timeout_seconds:g}s and was stopped"
    elif runtime_problem is not None:
        result["error"] = f"TagUI dependency problem ({runtime_problem}). See stderr."
    elif problems:
        result["error"] = problems[0]
    elif exit_code not in (0, None):
        result["error"] = f"TagUI exited with code {exit_code}"
    return result


def write_steps_flow(directory: Path, steps: list[str]) -> Path:
    if not steps:
        raise TaguiFailure("steps is empty")
    if len(steps) > 500:
        raise TaguiFailure("steps is limited to 500 lines")
    body = "\n".join(steps)
    if len(body) > 200_000:
        raise TaguiFailure("steps exceed 200000 characters")
    if "\x00" in body:
        raise TaguiFailure("steps cannot contain NUL characters")
    directory.mkdir(parents=True, exist_ok=True)
    flow_path = directory / "mcp_steps"
    flow_path.write_text(body + "\n", encoding="utf-8")
    return flow_path


def run_steps(
    steps: list[str],
    options: list[str] | None = None,
    inputs: list[str] | None = None,
    datatable: str | None = None,
    timeout_seconds: float = 120,
    directory: Path | None = None,
    tagui_bin: str | Path | None = None,
) -> dict[str, object]:
    """Write steps to a temporary flow and run it. The directory is kept for output files."""
    try:
        if directory is None:
            directory = Path(tempfile.mkdtemp(prefix="tagui_mcp_flow_"))
        flow_path = write_steps_flow(directory, steps)
    except TaguiFailure as exc:
        return {"ok": False, "error": str(exc)}
    result = run_flow(
        str(flow_path),
        options=options,
        inputs=inputs,
        datatable=datatable,
        timeout_seconds=timeout_seconds,
        tagui_bin=tagui_bin,
    )
    result["work_directory"] = str(directory)
    return result
