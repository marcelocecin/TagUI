#!/usr/bin/env python3
"""The MCP repair fields work when a modern-engine flow misses an element."""

from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "src" / "tagui_mcp"))

from tagui_mcp.runner import run_flow  # noqa: E402


def _png(path: Path) -> bool:
    try:
        return path.is_file() and path.read_bytes()[:8] == b"\x89PNG\r\n\x1a\n"
    except OSError:
        return False


def _check(result: dict, line: int, step: str, work: Path, label: str) -> list[str]:
    failures = []
    if result.get("ok") is not False:
        failures.append(f"{label} ok was {result.get('ok')}")
    if result.get("failing_line") != line:
        failures.append(f"{label} failing_line was {result.get('failing_line')}")
    if result.get("failing_step") != step:
        failures.append(f"{label} failing_step was {result.get('failing_step')!r}")
    shots = [str(item) for item in (result.get("screenshots") or [])]
    tab = work / "failure_tab.png"
    desk = work / "failure_desktop.png"
    if str(tab) not in shots or not _png(tab):
        failures.append(f"{label} missing failure_tab.png in {shots}")
    if os.environ.get("DISPLAY") and (str(desk) not in shots or not _png(desk)):
        failures.append(f"{label} missing failure_desktop.png in {shots}")
    if not shots:
        failures.append(f"{label} screenshots empty ({result.get('error_message')})")
    return failures


def main() -> None:
    os.environ["TAGUI_CHROME_ISOLATED"] = "1"
    work = Path("/tmp/tagui_modern_repair")
    work.mkdir(exist_ok=True)
    page = ROOT / "src" / "test" / "fixtures" / "modern_page.html"
    dom = work / "dom"
    dom.mkdir(exist_ok=True)
    flow = dom / "repair_flow"
    flow.write_text(
        "\n".join(
            [
                f"file://{page}",
                "click Go",
                "click missing-button",
                "echo should-not-run",
            ]
        )
        + "\n",
        encoding="utf-8",
    )
    dom_result = run_flow(
        str(flow),
        options=["headless", "modern", "quiet"],
        timeout_seconds=40,
        tagui_bin=ROOT / "src" / "tagui",
    )
    visual = work / "visual"
    visual.mkdir(exist_ok=True)
    visual_flow = visual / "visual_flow"
    visual_flow.write_text(
        "\n".join(
            [
                f"file://{page}",
                "click missing-mark.png",
                "echo should-not-run",
            ]
        )
        + "\n",
        encoding="utf-8",
    )
    os.environ["TAGUI_SIKULI_JAR"] = "/tmp/tagui-missing-oculix.jar"
    visual_result = run_flow(
        str(visual_flow),
        options=["headless", "modern", "quiet"],
        timeout_seconds=40,
        tagui_bin=ROOT / "src" / "tagui",
    )
    failures = _check(dom_result, 3, "click missing-button", dom, "dom")
    failures.extend(_check(visual_result, 2, "click missing-mark.png", visual, "visual"))
    message = str(visual_result.get("error_message") or "")
    if "OculiX" not in message or "cannot find " in message:
        failures.append(f"visual error was {message!r}")
    if failures:
        print("FAIL", "; ".join(failures), file=sys.stderr)
        print(dom_result.get("output"), file=sys.stderr)
        print(visual_result.get("output"), file=sys.stderr)
        raise SystemExit(1)
    print("ok   repair failing_line", dom_result["failing_line"])
    print("ok   repair screenshots", dom_result["screenshots"])
    print("ok   visual failing_line", visual_result["failing_line"])
    print("ok   visual screenshots", visual_result["screenshots"])


if __name__ == "__main__":
    main()
