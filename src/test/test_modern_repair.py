#!/usr/bin/env python3
"""The MCP repair fields work when a modern-engine flow misses an element."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "src" / "tagui_mcp"))

from tagui_mcp.runner import run_flow  # noqa: E402


def main() -> None:
    work = Path("/tmp/tagui_modern_repair")
    work.mkdir(exist_ok=True)
    page = ROOT / "src" / "test" / "fixtures" / "modern_page.html"
    flow = work / "repair_flow"
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
    result = run_flow(
        str(flow),
        options=["headless", "modern", "quiet"],
        timeout_seconds=40,
        tagui_bin=ROOT / "src" / "tagui",
    )
    failures = []
    if result.get("ok") is not False:
        failures.append(f"ok was {result.get('ok')}")
    if result.get("failing_line") != 3:
        failures.append(f"failing_line was {result.get('failing_line')}")
    if result.get("failing_step") != "click missing-button":
        failures.append(f"failing_step was {result.get('failing_step')!r}")
    shots = result.get("screenshots") or []
    if not shots:
        failures.append(f"no screenshots in {result.get('error_message')}")
    if failures:
        print("FAIL", "; ".join(failures), file=sys.stderr)
        print(result.get("output"), file=sys.stderr)
        raise SystemExit(1)
    print("ok   repair failing_line", result["failing_line"])
    print("ok   repair screenshot", shots[0])


if __name__ == "__main__":
    main()
