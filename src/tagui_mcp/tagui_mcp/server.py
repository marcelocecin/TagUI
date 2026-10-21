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
    "Prefer reusable flow files: create_flow or save_flow, "
    "run_workspace_flow, then edit_flow on the failing_line and run again. "
    "run_flow and run_workspace_flow return failing_line, failing_step, error_message, "
    "log, screenshots, and a hint. "
    "Use browser_start for a live Chrome session. browser_step accepts several lines. "
    "wait N and run work in live mode. check, frame, popup, if, for, while, and brace "
    "blocks are replayed as a flow because the live interpreter cannot compile them. "
    "save_session writes the live transcript into the workspace. "
    "Only one TagUI Chrome can run at a time. "
    "Flows run with the user's privileges, including any run or py steps in a flow file. "
    "A URL stored in a variable is a line that is just `url`. "
    "Datatable row number is [iteration]. Variables are visible to flows included with tagui. "
    "echo run_result prints a run step. echo p1 prints an input. "
    "api headers are an array of 'Name: value' strings."
)

mcp = MCPServer(
    "tagui",
    title="TagUI",
    description="Run TagUI flows and drive a live Chrome session.",
    instructions=INSTRUCTIONS,
    version=__version__,
)


# TRUNCATED - need full file
