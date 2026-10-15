"""Operations exposed by the MCP tools.

A single service owns the live browser session. Flow runs and the live session
both use TagUI's Chrome debugging port, so they are not allowed to overlap.
"""

from __future__ import annotations

import re
import threading
import time
from collections.abc import Callable
from pathlib import Path

from tagui_mcp.runner import TaguiFailure, resolve_tagui_executable, run_flow, run_steps
from tagui_mcp.session import BrowserMode, LiveSession

_URL = re.compile(r"^https?://\S+$", re.IGNORECASE)
_UNSAFE_SELECTOR = re.compile(r"[\r\n\x00'`]")
_UNSAFE_TEXT = re.compile(r"[\r\n\x00']")
_PAGE_TEXT_LIMIT = 4000


class TaguiService:
    def __init__(self, tagui_bin: str | Path | None = None) -> None:
        self.tagui_bin = tagui_bin
        self.session = LiveSession(tagui_bin=tagui_bin)
        self._lock = threading.Lock()

    def close(self) -> None:
        with self._lock:
            self.session.stop()

    def status(self) -> dict[str, object]:
        with self._lock:
            try:
                executable = resolve_tagui_executable(self.tagui_bin)
            except TaguiFailure as exc:
                return {"ok": False, "error": str(exc), "browser_running": False}
            return {
                "ok": True,
                "tagui": str(executable),
                "browser_running": self.session.running,
                "browser_mode": self.session.mode,
            }

    def run_flow(
        self,
        flow_file: str,
        options: list[str] | None = None,
        inputs: list[str] | None = None,
        datatable: str | None = None,
        timeout_seconds: float = 120,
    ) -> dict[str, object]:
        with self._lock:
            return self._guard(
                lambda: self._run_flow(flow_file, options, inputs, datatable, timeout_seconds)
            )

    def run_steps(
        self,
        steps: list[str],
        options: list[str] | None = None,
        inputs: list[str] | None = None,
        datatable: str | None = None,
        timeout_seconds: float = 120,
    ) -> dict[str, object]:
        with self._lock:
            return self._guard(
                lambda: self._run_steps(steps, options, inputs, datatable, timeout_seconds)
            )

    def browser_start(self, mode: str = "headless", startup_timeout: float = 90) -> dict[str, object]:
        with self._lock:
            return self._guard(lambda: self._browser_start(mode, startup_timeout))

    def browser_stop(self) -> dict[str, object]:
        with self._lock:
            self.session.stop()
            return {"ok": True, "browser_running": False}

    def browser_go(self, url: str, timeout_seconds: float = 20) -> dict[str, object]:
        with self._lock:
            return self._guard(lambda: self._browser_go(url, timeout_seconds))

    def browser_click(self, selector: str, timeout_seconds: float = 45) -> dict[str, object]:
        with self._lock:
            return self._guard(lambda: self._step_result(f"click {self._selector(selector)}", timeout_seconds))

    def browser_type(
        self, selector: str, text: str, timeout_seconds: float = 45
    ) -> dict[str, object]:
        with self._lock:
            return self._guard(
                lambda: self._step_result(
                    f"type {self._selector(selector)} as {self._text(text)}",
                    timeout_seconds,
                )
            )

    def browser_read(self, target: str = "page", timeout_seconds: float = 45) -> dict[str, object]:
        with self._lock:
            return self._guard(lambda: self._browser_read(target, timeout_seconds))

    def page_state(self, timeout_seconds: float = 45) -> dict[str, object]:
        with self._lock:
            return self._guard(lambda: self._page_state(timeout_seconds))

    def browser_step(self, step: str, timeout_seconds: float = 45) -> dict[str, object]:
        with self._lock:
            return self._guard(lambda: self._step_result(step, timeout_seconds))

    def _run_flow(
        self,
        flow_file: str,
        options: list[str] | None,
        inputs: list[str] | None,
        datatable: str | None,
        timeout_seconds: float,
    ) -> dict[str, object]:
        self._ensure_idle()
        return run_flow(
            flow_file,
            options=options,
            inputs=inputs,
            datatable=datatable,
            timeout_seconds=timeout_seconds,
            tagui_bin=self.tagui_bin,
        )

    def _run_steps(
        self,
        steps: list[str],
        options: list[str] | None,
        inputs: list[str] | None,
        datatable: str | None,
        timeout_seconds: float,
    ) -> dict[str, object]:
        self._ensure_idle()
        return run_steps(
            steps,
            options=options,
            inputs=inputs,
            datatable=datatable,
            timeout_seconds=timeout_seconds,
            tagui_bin=self.tagui_bin,
        )

    def _browser_start(self, mode: str, startup_timeout: float) -> dict[str, object]:
        if self.session.running:
            raise TaguiFailure("browser session already running; call browser_stop first")
        selected = parse_mode(mode)
        self.session.start(selected, startup_timeout=startup_timeout)
        workdir = self.session.workdir
        return {
            "ok": True,
            "mode": selected,
            "browser_running": True,
            "work_directory": str(workdir) if workdir is not None else None,
        }

    def _browser_go(self, url: str, timeout_seconds: float) -> dict[str, object]:
        self._ensure_running()
        if not isinstance(url, str) or _URL.match(url.strip()) is None:
            raise TaguiFailure("url must start with http:// or https:// and contain no spaces")
        target = url.strip()
        output = self.session.run_step(target, timeout=timeout_seconds)
        if "ERROR -" in output:
            return {"ok": False, "error": output.strip(), "output": output}
        deadline = time.monotonic() + timeout_seconds
        seen = ""
        while time.monotonic() < deadline:
            seen = self._dump("url()")
            if seen.startswith("http://") or seen.startswith("https://"):
                return {"ok": True, "url": seen, "output": output}
            time.sleep(0.3)
        return {
            "ok": False,
            "error": "navigation did not leave about:blank before the timeout",
            "url": seen,
            "output": output,
        }

    def _browser_read(self, target: str, timeout_seconds: float) -> dict[str, object]:
        self._ensure_running()
        if target.strip().lower() == "page":
            text = self._dump("text()")
            truncated = len(text) > _PAGE_TEXT_LIMIT
            if truncated:
                text = text[:_PAGE_TEXT_LIMIT]
            return {"ok": True, "target": "page", "text": text, "truncated": truncated}
        selector = self._selector(target)
        output = self.session.run_step(f"show {selector}", timeout=timeout_seconds)
        ok = "ERROR -" not in output
        result: dict[str, object] = {"ok": ok, "target": selector, "text": output}
        if not ok:
            result["error"] = output.strip()
        return result

    def _page_state(self, timeout_seconds: float) -> dict[str, object]:
        self._ensure_running()
        url = self._dump("url()", timeout_seconds)
        title = self._dump("title()", timeout_seconds)
        text = self._dump("text()", timeout_seconds)
        truncated = len(text) > _PAGE_TEXT_LIMIT
        if truncated:
            text = text[:_PAGE_TEXT_LIMIT]
        return {
            "ok": True,
            "mode": self.session.mode,
            "url": url,
            "title": title,
            "text": text,
            "truncated": truncated,
        }

    def _step_result(self, step: str, timeout_seconds: float) -> dict[str, object]:
        self._ensure_running()
        output = self.session.run_step(step, timeout=timeout_seconds)
        ok = "ERROR -" not in output
        result: dict[str, object] = {"ok": ok, "output": output}
        if not ok:
            result["error"] = output.strip()
        return result

    def _dump(self, expression: str, timeout_seconds: float = 45) -> str:
        workdir = self.session.workdir
        if workdir is None:
            raise TaguiFailure("browser session is not running")
        dest = workdir / f"mcp_{expression.split('(')[0]}.txt"
        return self.session.dump(expression, dest, timeout=timeout_seconds)

    def _ensure_idle(self) -> None:
        if self.session.running:
            raise TaguiFailure(
                "a browser session is active; call browser_stop before running a flow"
            )

    def _ensure_running(self) -> None:
        if not self.session.running:
            raise TaguiFailure("browser session is not running; call browser_start first")

    @staticmethod
    def _guard(action: Callable[[], dict[str, object]]) -> dict[str, object]:
        try:
            return action()
        except TaguiFailure as exc:
            payload: dict[str, object] = {"ok": False, "error": str(exc)}
            payload.update(exc.details)
            return payload

    @staticmethod
    def _selector(selector: str) -> str:
        if not isinstance(selector, str):
            raise TaguiFailure("selector must be a string")
        value = selector.strip()
        if value == "":
            raise TaguiFailure("selector is empty")
        if _UNSAFE_SELECTOR.search(value):
            raise TaguiFailure(
                "selector contains quotes, backticks, or newlines. "
                "Use browser_step for a selector that needs them."
            )
        if " as " in value or " to " in value:
            raise TaguiFailure("selector cannot contain ' as ' or ' to '")
        if len(value) > 1000:
            raise TaguiFailure("selector exceeds 1000 characters")
        return value

    @staticmethod
    def _text(text: str) -> str:
        if not isinstance(text, str) or text == "":
            raise TaguiFailure("text is empty")
        if _UNSAFE_TEXT.search(text):
            raise TaguiFailure(
                "text cannot contain single quotes or newlines in browser_type. "
                "Use browser_step to send the type step yourself."
            )
        if len(text) > 2000:
            raise TaguiFailure("text exceeds 2000 characters")
        return text


def parse_mode(mode: str) -> BrowserMode:
    if mode == "headless":
        return "headless"
    if mode == "chrome":
        return "chrome"
    raise TaguiFailure("mode must be 'headless' or 'chrome'")


_SERVICE: TaguiService | None = None
_SERVICE_LOCK = threading.Lock()


def get_service() -> TaguiService:
    global _SERVICE
    with _SERVICE_LOCK:
        if _SERVICE is None:
            _SERVICE = TaguiService()
        return _SERVICE


def reset_service() -> None:
    """Stop the process-wide session. Used by tests and process shutdown."""
    global _SERVICE
    with _SERVICE_LOCK:
        if _SERVICE is not None:
            _SERVICE.close()
        _SERVICE = None
