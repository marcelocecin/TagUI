"""Command line entry point: stdio by default, Streamable HTTP with a bearer token on request."""

from __future__ import annotations

import argparse
import sys

from tagui_mcp.http_transport import DEFAULT_HOST, DEFAULT_PATH, DEFAULT_PORT, TokenError


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="tagui-mcp", description="TagUI MCP server")
    parser.add_argument(
        "--transport",
        choices=("stdio", "http"),
        default="stdio",
        help="stdio (default) or Streamable HTTP. HTTP requires TAGUI_MCP_TOKEN or TAGUI_MCP_TOKEN_FILE.",
    )
    parser.add_argument("--host", default=DEFAULT_HOST, help=f"HTTP bind address (default {DEFAULT_HOST})")
    parser.add_argument("--port", type=int, default=DEFAULT_PORT, help=f"HTTP port (default {DEFAULT_PORT})")
    parser.add_argument("--path", default=DEFAULT_PATH, help=f"HTTP endpoint path (default {DEFAULT_PATH})")
    parser.add_argument(
        "--allow-remote",
        action="store_true",
        help="allow a non-loopback --host. The connection is plain HTTP, so terminate TLS in front of it.",
    )
    return parser


def main(argv: list[str] | None = None) -> None:
    args = _parser().parse_args(argv)
    if args.transport == "stdio":
        from tagui_mcp.server import main as serve_stdio

        serve_stdio()
        return
    from tagui_mcp.http_transport import serve_http

    try:
        serve_http(host=args.host, port=args.port, allow_remote=args.allow_remote, path=args.path)
    except TokenError as exc:
        print(f"tagui-mcp: {exc}", file=sys.stderr)
        raise SystemExit(2) from exc


if __name__ == "__main__":
    main()
