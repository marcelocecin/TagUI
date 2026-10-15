"""stdio MCP server for TagUI.

stdout is reserved for MCP messages. This module does not print.
"""

from __future__ import annotations

import atexit
import json
import signal

from mcp.server.mcpserver import MCPServer

from tagui_mcp import __version__
from tagui_mcp.service import get_service, reset_service

atexit.register(reset_service)

INSTRUCTIONS = (
    "Drive TagUI, a local RPA tool, on this machine. "
    "Use tagui_status to confirm the tagui executable. "
    "Use run_flow for an existing flow file and run_steps for a one-shot script "
    "in TagUI's step language. "
    "Use browser_start, then browser_go, browser_click, browser_type, browser_read, "
    "page_state, and browser_step for a persistent Chrome session, and browser_stop "
    "when finished. Only one TagUI run can be active at a time. "
    "Flows run with the user's privileges, including any run or py steps in a flow file."
)

mcp = MCPServer(
    "tagui",
    title="TagUI",
    description="Run TagUI flows and drive a live Chrome session.",
    instructions=INSTRUCTIONS,
    version=__version__,
)


def _json(payload: dict[str, object]) -> str:
    return json.dumps(payload, indent=2, ensure_ascii=False)


@mcp.tool()
def tagui_status() -> str:
    """Report the TagUI executable in use and whether a live browser session is open."""
    return _json(get_service().status())


@mcp.tool()
def run_flow(
    flow_file: str,
    options: list[str] | None = None,
    inputs: list[str] | None = None,
    datatable: str | None = None,
    timeout_seconds: float = 120,
) -> str:
    """Run a TagUI flow file and return its output as JSON.

    flow_file is a local path. TagUI accepts no extension, or .txt, .js, or .tagui.
    options are CLI flags: chrome, headless, firefox, quiet, report, speed, debug,
    test, baseline, upload. Omit options to run in quiet mode on TagUI's default
    PhantomJS browser. Pass ["headless"] or ["chrome"] to use Chrome.
    inputs become flow variables p1, p2, ... and cannot contain whitespace.
    options, inputs, and an optional datatable csv share TagUI's limit of 8 extra arguments.
    ok is false when the process fails, times out, or the output contains TagUI ERROR lines.
    TagUI's own exit code stays 0 when parsing succeeds even if a later step fails.
    """
    return _json(
        get_service().run_flow(
            flow_file,
            options=options,
            inputs=inputs,
            datatable=datatable,
            timeout_seconds=timeout_seconds,
        )
    )


@mcp.tool()
def run_steps(
    steps: list[str],
    options: list[str] | None = None,
    inputs: list[str] | None = None,
    datatable: str | None = None,
    timeout_seconds: float = 120,
) -> str:
    """Run ad-hoc TagUI steps as a one-shot flow.

    steps are full TagUI lines, one string per line, including a starting URL when
    the script uses the browser. This uses the normal flow runner, so conditions,
    loops, and wait steps work. The JSON result includes work_directory, where
    snapshots and other files written beside the flow are left.
    """
    return _json(
        get_service().run_steps(
            steps,
            options=options,
            inputs=inputs,
            datatable=datatable,
            timeout_seconds=timeout_seconds,
        )
    )


@mcp.tool()
def browser_start(mode: str = "headless", startup_timeout: float = 90) -> str:
    """Start a live TagUI Chrome session for later browser tools.

    mode is "headless" (default) or "chrome" (visible window). The session keeps
    one browser open so later steps share page state. Requires Chrome, plus the
    PHP, CasperJS, and PhantomJS dependencies TagUI's live mode already uses.
    """
    return _json(get_service().browser_start(mode, startup_timeout=startup_timeout))


@mcp.tool()
def browser_stop() -> str:
    """Close the live browser session and its Chrome process."""
    return _json(get_service().browser_stop())


@mcp.tool()
def browser_go(url: str, timeout_seconds: float = 20) -> str:
    """Open an http(s) URL in the live session and wait until the location changes."""
    return _json(get_service().browser_go(url, timeout_seconds=timeout_seconds))


@mcp.tool()
def browser_click(selector: str, timeout_seconds: float = 45) -> str:
    """Click an element in the live session.

    selector is a TagUI identifier: xpath, css, id, name, class, visible text, or href.
    """
    return _json(get_service().browser_click(selector, timeout_seconds=timeout_seconds))


@mcp.tool()
def browser_type(selector: str, text: str, timeout_seconds: float = 45) -> str:
    """Type text into an element in the live session.

    [clear] at the start clears the field and [enter] presses enter, same as TagUI.
    Single quotes and newlines are rejected; use browser_step for those values.
    """
    return _json(get_service().browser_type(selector, text, timeout_seconds=timeout_seconds))


@mcp.tool()
def browser_read(target: str = "page", timeout_seconds: float = 45) -> str:
    """Read visible text from the live page or from one element.

    target "page" returns the visible page text (not the raw HTML). Any other
    target is a TagUI element identifier and returns that element's text.
    """
    return _json(get_service().browser_read(target, timeout_seconds=timeout_seconds))


@mcp.tool()
def page_state(timeout_seconds: float = 45) -> str:
    """Return the live page URL, title, and a truncated copy of the visible text."""
    return _json(get_service().page_state(timeout_seconds=timeout_seconds))


@mcp.tool()
def browser_step(step: str, timeout_seconds: float = 45) -> str:
    """Send one TagUI live-mode step and return what it echoes.

    Use this for steps the other browser tools do not cover (hover, select, snap,
    dom, js, echo). The step must be a single line. Steps that live mode itself
    rejects, such as wait, check, frame, and run, come back as TagUI ERROR lines.
    A step starting with done is refused because that closes live mode.
    """
    return _json(get_service().browser_step(step, timeout_seconds=timeout_seconds))


def main() -> None:
    """Serve MCP over stdin and stdout."""

    def _shutdown(signum: int, _frame: object) -> None:
        reset_service()
        raise SystemExit(128 + signum)

    signal.signal(signal.SIGTERM, _shutdown)
    signal.signal(signal.SIGINT, _shutdown)
    mcp.run(transport="stdio")
