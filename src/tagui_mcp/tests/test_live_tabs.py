from __future__ import annotations

from pathlib import Path

import pytest

from tagui_mcp import files
from tagui_mcp.runner import TaguiFailure
from tagui_mcp.service import TaguiService

FAKE = Path(__file__).parent / "fake_tagui.py"


def _modern(tmp_path: Path) -> TaguiService:
    service = TaguiService(tagui_bin=FAKE, workspace=tmp_path)
    assert service.browser_start("headless", startup_timeout=5, engine="modern")["ok"] is True
    return service


def test_tab_tools_need_a_session_and_the_modern_engine(tmp_path: Path) -> None:
    service = TaguiService(tagui_bin=FAKE, workspace=tmp_path)
    try:
        for result in (
            service.browser_tabs(),
            service.browser_switch_tab(0),
            service.browser_pdf("a.pdf"),
        ):
            assert result["ok"] is False
            assert "not running" in str(result["error"])
        assert service.browser_start("headless", startup_timeout=5, engine="casper")["ok"] is True
        for result in (
            service.browser_tabs(),
            service.browser_switch_tab(0),
            service.browser_pdf("a.pdf"),
        ):
            assert result["ok"] is False
            assert "casper engine" in str(result["error"])
        assert service.session.running
    finally:
        service.close()


def test_list_and_switch_keep_the_session(tmp_path: Path) -> None:
    service = _modern(tmp_path)
    try:
        listed = service.browser_tabs()
        assert listed["count"] == 2
        assert listed["tabs"][0]["active"] is True
        switched = service.browser_switch_tab(query="beta")
        assert switched["ok"] is True
        assert switched["tab"]["index"] == 1
        assert service.browser_tabs()["tabs"][1]["active"] is True
        assert service.browser_switch_tab(0)["ok"] is True
        assert service.session.running
        assert service.session.transcript == []
    finally:
        service.close()


def test_switch_rejects_bad_selection(tmp_path: Path) -> None:
    service = _modern(tmp_path)
    try:
        for args in ({}, {"index": 0, "query": "a"}, {"index": -1}, {"index": True}, {"query": "x" * 501}):
            assert service.browser_switch_tab(**args)["ok"] is False
        assert "no tab" in str(service.browser_switch_tab(7)["error"])
        assert service.session.running
    finally:
        service.close()


def test_pdf_is_written_inside_the_roots_only(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("TAGUI_MCP_FILE_ROOTS", str(tmp_path))
    service = _modern(tmp_path)
    try:
        saved = service.browser_pdf("out/../x.pdf")
        assert saved["ok"] is False
        (tmp_path / "out").mkdir()
        saved = service.browser_pdf("out/page.pdf")
        assert saved["ok"] is True
        assert saved["path"] == str(tmp_path.resolve() / "out" / "page.pdf")
        assert saved["size"] == len(b"%PDF-1.4\n%fake\n")
        assert service.browser_pdf("out/page.pdf")["ok"] is False
        assert service.browser_pdf("out/page.pdf", overwrite=True)["ok"] is True
        assert service.browser_pdf("out/page.txt")["ok"] is False
        assert "outside" in str(service.browser_pdf("/tmp/elsewhere.pdf")["error"])
        assert service.browser_pdf("missing/page.pdf")["ok"] is False
        auto = service.browser_pdf()
        assert auto["ok"] is True
        assert Path(str(auto["path"])).parent == tmp_path.resolve()
        assert not list(tmp_path.glob(".*.part"))
    finally:
        service.close()


def test_pdf_rejects_non_pdf_output_and_symlinks(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("TAGUI_MCP_FILE_ROOTS", str(tmp_path))
    service = _modern(tmp_path)
    try:
        (tmp_path / "link.pdf").symlink_to("/etc/hostname")
        assert "symbolic link" in str(service.browser_pdf("link.pdf", overwrite=True)["error"])
        assert service.browser_go("http://fixture/bad")["ok"] is True
        refused = service.browser_pdf("bad.pdf")
        assert refused["ok"] is False
        assert "valid PDF" in str(refused["error"])
        assert not (tmp_path / "bad.pdf").exists()
    finally:
        service.close()


def test_output_path_defaults_to_first_root(tmp_path: Path) -> None:
    target = files.output_path([tmp_path / "new"], "")
    assert target.parent == (tmp_path / "new").resolve()
    with pytest.raises(TaguiFailure):
        files.output_path([tmp_path], "a\x00.pdf")
