#!/usr/bin/env python3
"""Live-mode contract check for the modern engine.

This is the same stdin/stdout exchange RPA-Python uses: wait for the live
banner, bracket each instruction with echo markers, dump results to a file,
and send done. It also drives the MCP LiveSession against the real tagui script.
"""

from __future__ import annotations

import os
import select
import socket
import subprocess
import sys
import tempfile
import time
import urllib.request
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


def start_fixture() -> tuple[subprocess.Popen[bytes], str]:
    """Serve the suite pages and return the process plus the page URL."""
    probe = socket.socket()
    probe.bind(("127.0.0.1", 0))
    port = probe.getsockname()[1]
    probe.close()
    fixtures = ROOT / "src" / "test" / "fixtures"
    process = subprocess.Popen(
        ["php", "-S", f"127.0.0.1:{port}", "suite_router.php"],
        cwd=str(fixtures),
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
    )
    page = f"http://127.0.0.1:{port}/org/page.html"
    deadline = time.monotonic() + 5
    while time.monotonic() < deadline:
        try:
            with urllib.request.urlopen(page, timeout=1) as response:
                if b'id="header"' in response.read():
                    return process, page
        except OSError:
            time.sleep(0.1)
    process.kill()
    fail(f"fixture server did not answer on {page}")
    return process, page


def fixture_live_steps(workdir: Path, page: str) -> None:
    """Feed echo, a fixture click, an assignment, and done into modern live mode."""
    flow = workdir / "fixture_live"
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
        output = read_until(process, "LIVE MODE - type done to quit", 60)
        write(process, "echo live-hello\n")
        output += read_until(process, "live-hello", 20)
        write(process, page + "\n")
        write(process, "click #header\n")
        write(process, "seen = 1\n")
        write(process, "echo seen\n")
        output += read_until(process, "1\n", 30)
        write(process, "done\n")
        try:
            stdout, stderr = process.communicate(timeout=20)
        except subprocess.TimeoutExpired:
            fail("tagui did not exit after done")
            return
        text = (output + stdout).decode("utf-8", errors="replace")
        err = stderr.decode("utf-8", errors="replace")
        lines = [line.replace("\x08", "").strip() for line in text.splitlines()]
        if "live-hello" not in lines or "1" not in lines:
            fail(f"live output missing echo or assignment: {text!r}")
        if "ERROR -" in text or "ERROR -" in err:
            fail(f"live step failed: {text!r} {err!r}")
        if process.returncode != 0:
            fail(f"live exit {process.returncode}: {text!r}")
    finally:
        if process.poll() is None:
            process.kill()
            process.wait(timeout=5)


def mcp_tools_round_trip(workdir: Path, page: str) -> None:
    """Start a session, run steps, read the page, and close through the MCP tools."""
    import asyncio
    import json

    from mcp.client.session import ClientSession
    from mcp.client.stdio import StdioServerParameters, stdio_client

    async def run() -> None:
        env = os.environ.copy()
        env["PYTHONPATH"] = str(MCP_SRC)
        env["TAGUI_BIN"] = str(TAGUI)
        env["TAGUI_CHROME_ISOLATED"] = "1"
        env["TAGUI_WORKSPACE"] = str(workdir / "flows")
        params = StdioServerParameters(
            command=sys.executable,
            args=["-m", "tagui_mcp"],
            env=env,
            cwd=str(workdir),
        )
        async with stdio_client(params) as (read, write_stream):
            async with ClientSession(read, write_stream) as session:
                await session.initialize()

                async def call(name: str, arguments: dict[str, object]) -> dict[str, object]:
                    result = await session.call_tool(name, arguments)
                    content = getattr(result, "content")
                    return json.loads(content[0].text)

                started = await call(
                    "browser_start",
                    {"mode": "headless", "startup_timeout": 90, "engine": "modern"},
                )
                if started.get("ok") is not True or started.get("engine") != "modern":
                    fail(f"browser_start {started!r}")
                echoed = await call("browser_step", {"step": 'echo "live-hello"', "timeout_seconds": 20})
                if echoed.get("ok") is not True or "live-hello" not in str(echoed.get("output")):
                    fail(f"browser_step echo {echoed!r}")
                opened = await call("browser_go", {"url": page, "timeout_seconds": 30})
                if opened.get("ok") is not True:
                    fail(f"browser_go {opened!r}")
                clicked = await call("browser_click", {"selector": "#header", "timeout_seconds": 30})
                if clicked.get("ok") is not True:
                    fail(f"browser_click {clicked!r}")
                assigned = await call(
                    "browser_step",
                    {"step": "seen = 1\necho seen", "timeout_seconds": 20},
                )
                assigned_output = str(assigned.get("output")).strip()
                if assigned.get("ok") is not True or assigned_output != "1":
                    fail(f"browser_step assignment {assigned!r}")
                read_header = await call("browser_read", {"target": "#header", "timeout_seconds": 20})
                if read_header.get("ok") is not True or "automation header" not in str(read_header.get("text")):
                    fail(f"browser_read {read_header!r}")
                stopped = await call("browser_stop", {})
                if stopped.get("ok") is not True or stopped.get("browser_running") is not False:
                    fail(f"browser_stop {stopped!r}")

    asyncio.run(run())


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
    server, page = start_fixture()
    try:
        with tempfile.TemporaryDirectory(prefix="tagui_modern_live_") as directory:
            workdir = Path(directory)
            rpa_round_trip(workdir)
            print("ok   rpa live contract")
            fixture_live_steps(workdir, page)
            print("ok   live stdin steps")
            mcp_tools_round_trip(workdir, page)
            print("ok   mcp tools")
            mcp_round_trip(workdir)
            print("ok   mcp live session")
    finally:
        if server.poll() is None:
            server.kill()
            server.wait(timeout=5)
    print("all live checks passed")


if __name__ == "__main__":
    main()
