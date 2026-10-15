# TagUI MCP server

A [Model Context Protocol](https://modelcontextprotocol.io) server that lets an AI assistant run [TagUI](https://github.com/tebelorg/TagUI) on the machine where the server is installed.

The server is a separate Python package under `src/tagui_mcp`. It does not modify TagUI's runner, parser, or browser integration. It calls the existing `tagui` script, which is the same path the command line, the crontab API queue, and [TagUI for Python](https://github.com/tebelorg/TagUI-Python) already use.

## Why Python

TagUI's runtime is a shell script, a PHP translator, and CasperJS. The project deliberately avoided adding Node.js as a requirement for Chrome automation. Python is already the language TagUI documents for external control: `py` steps run inside a flow, and TagUI for Python drives live mode from outside the process. The official MCP Python SDK (`mcp` 2.x, `MCPServer`) speaks stdio, which is what Claude Desktop and Cursor launch. A small Python process next to the `tagui` script is easier for upstream to review than folding a protocol server into `tagui_parse.php` or the shell runner.

## What you need

The MCP package does not vendor TagUI's engines. A packaged TagUI install (or a manual install of the same tools) has to be on the machine:

- The `tagui` script, normally `src/tagui` in this repository
- PHP CLI (`php`), used to translate a flow
- CasperJS and PhantomJS, used to execute the translated script
- Google Chrome on `PATH` as `google-chrome` for `headless` or `chrome` runs and for every live browser tool

`run_flow` / `run_steps` with no browser flag use TagUI's default PhantomJS browser. `browser_*` tools always start live mode with Chrome, because live-mode navigation only works there.

## Install

From this directory:

```bash
cd src/tagui_mcp
python3 -m pip install -e .
```

`pip install -e .` adds a `tagui-mcp` command. To run without installing, set `PYTHONPATH` to `src/tagui_mcp` and use `python3 -m tagui_mcp`.

The server finds TagUI in this order:

1. `TAGUI_BIN` — full path to the `tagui` script (or `tagui.cmd` on Windows)
2. `TAGUI_SRC` — directory that contains that script
3. `src/tagui` next to this package, when the package is used from a TagUI checkout
4. `tagui` on `PATH`

Check the resolved path with the `tagui_status` tool.

## Claude Desktop

Edit `claude_desktop_config.json` (macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`, Windows: `%APPDATA%\Claude\claude_desktop_config.json`):

```json
{
  "mcpServers": {
    "tagui": {
      "command": "tagui-mcp",
      "env": {
        "TAGUI_SRC": "/absolute/path/to/TagUI/src"
      }
    }
  }
}
```

If `tagui-mcp` is not on `PATH`:

```json
{
  "mcpServers": {
    "tagui": {
      "command": "python3",
      "args": ["-m", "tagui_mcp"],
      "env": {
        "PYTHONPATH": "/absolute/path/to/TagUI/src/tagui_mcp",
        "TAGUI_SRC": "/absolute/path/to/TagUI/src"
      }
    }
  }
}
```

Restart Claude Desktop after saving. The server uses stdio; there is no port to open.

## Cursor

Project config (`.cursor/mcp.json`) or the global MCP settings:

```json
{
  "mcpServers": {
    "tagui": {
      "command": "tagui-mcp",
      "env": {
        "TAGUI_SRC": "/absolute/path/to/TagUI/src"
      }
    }
  }
}
```

The `python3 -m tagui_mcp` form above works the same way in Cursor. Paths must be absolute.

## Tools

| Tool | What it does |
| --- | --- |
| `tagui_status` | Resolved `tagui` path, and whether a live browser session is open |
| `run_flow` | Run a flow file. Optional `options` (`quiet`, `headless`, `chrome`, `firefox`, `report`, `speed`, `debug`, `test`, `baseline`, `upload`), `inputs` (p1, p2, ...), `datatable` (a csv), `timeout_seconds` |
| `run_steps` | Write ad-hoc TagUI lines to a flow and run them. Returns `work_directory`, which is kept so snapshots and other output files remain |
| `browser_start` | Start live mode on headless Chrome (`mode`: `headless` or `chrome`). Returns `work_directory` for files created during the session |
| `browser_stop` | Send `done`, stop the TagUI process, and close TagUI's Chrome |
| `browser_go` | Open an `http` or `https` URL and poll until the page location changes |
| `browser_click` | `click <selector>` using TagUI's identifier rules (xpath, css, id, name, class, text, href) |
| `browser_type` | `type <selector> as <text>`. `[clear]` and `[enter]` work as in TagUI |
| `browser_read` | Visible text of the page (`target` = `page`) or of one element |
| `page_state` | URL, title, and visible text (text is truncated to 4000 characters) |
| `browser_step` | One raw live-mode line for steps the helpers do not cover (`hover`, `select`, `snap`, `dom`, `js`, `echo`, ...) |

Omitting `options` on `run_flow` / `run_steps` is quiet mode and TagUI's default PhantomJS browser. Example:

```json
{ "flow_file": "/absolute/path/to/TagUI/src/tagui_mcp/examples/hello.tagui" }
```

```json
{
  "steps": ["https://example.com", "echo title()", "snap page to page.png"],
  "options": ["headless", "quiet"]
}
```

A flow that is only `echo` lines does not need a browser flag. Chrome flags do need Chrome installed.

Every tool returns a JSON object. `ok` is false when TagUI prints `ERROR -`, the process exits non-zero, a dependency is missing, or the call times out. TagUI itself returns exit code 0 after a successful parse even when a later step fails, so `ok` follows the output, not only the exit code. `error_lines`, `stderr`, and `log_file` are included on flow runs.

## Try it

```bash
cd src/tagui_mcp
python3 -m pip install -r requirements-dev.txt
python3 -m pytest
```

The tests use `tests/fake_tagui.py` as `TAGUI_BIN`, then connect with the MCP Python client over stdio and call `run_flow`, `run_steps`, and the browser tools. That checks the protocol without PHP or Chrome.

With a real TagUI install:

```bash
tagui-mcp
```

Leave that process in a terminal only when you want to see it start; desktop clients launch it themselves. To exercise the real CLI, point `TAGUI_SRC` at `src` and call `run_flow` on `examples/hello.tagui`.

An MCP inspector session is the same stdio process:

```bash
npx @modelcontextprotocol/inspector python3 -m tagui_mcp
```

Run that from a shell where `PYTHONPATH` includes `src/tagui_mcp`, or use the installed `tagui-mcp` command as the inspector's command.

## Limitations

- **Same privileges as the user.** A flow can run shell commands (`run`) and Python (`py`). Live mode rejects `run`, but `run_flow` and `run_steps` do not. This is a local automation driver, not a sandbox. The `upload` option sends the run log to hastebin.com.
- **One TagUI Chrome at a time.** Live mode and `headless` / `chrome` flows use debugging port 9222 and `src/chrome/tagui_user_profile`. The server refuses `run_flow` / `run_steps` while a browser session is open. Do not start another TagUI Chrome run against that port at the same time. If a session is stuck, run `src/end_processes`.
- **Live mode is a single-step prompt.** `wait`, `check`, `frame`, `popup`, `run`, and multi-line `for` / `{ }` blocks are rejected by TagUI there. Put those in `run_steps` or a flow file. `browser_go` polls the URL because the live-mode navigation step returns before the load event.
- **`browser_type` and the click/read helpers reject quotes and newlines** so the generated step stays valid. Use `browser_step` when the value needs a single quote.
- **Files from the live session** are written under the `work_directory` returned by `browser_start` (the live flow's folder). `browser_stop` deletes that directory. Copy snapshots out before stopping. `run_steps` leaves its `work_directory` in place.
- **Argument budget.** The `tagui` script only forwards eight arguments after the flow file. Options, inputs, and a datatable share that budget. Inputs cannot contain whitespace; that is TagUI's own parameter splitting.
- **Engines are not bundled.** Missing `php`, CasperJS, PhantomJS, or Chrome shows up as a failed tool call (`stderr` often says `command not found`). Packaged TagUI builds already include those binaries. CasperJS 1.1's launcher asks for `python` on `PATH` (`python3` is not enough unless you symlink it). PhantomJS 2.1.1 on a system OpenSSL 3 fails unless the client env sets `OPENSSL_CONF` to `/dev/null` or another config that does not load the OpenSSL 3 `providers` module.
- **stdio only.** `MCPServer.run` can also serve Streamable HTTP, but this package does not enable it. Desktop clients use stdio.
- **Windows.** The executable name switches to `tagui.cmd`. Browser tools were exercised on Linux. Live mode reads CasperJS stdout a line at a time; that matches TagUI for Python, which depends on CasperJS flushing each `echo`.

## Layout

```
src/tagui_mcp/
  tagui_mcp/server.py     MCP tools (stdio)
  tagui_mcp/service.py    one-at-a-time flow runs and the live session
  tagui_mcp/runner.py     tagui CLI wrapper
  tagui_mcp/session.py    live-mode stdin/stdout protocol
  examples/hello.tagui
  tests/                  unit tests plus an MCP client test
```

## Upstream

The server is meant to be proposed to tebelorg/TagUI as an optional add-on. Nothing under `src/` besides this directory and a short pointer in the main README needs to change. Maintainers can drop the directory without affecting `tagui`, `tagui_parse.php`, or the existing PHP API queue (`tagui_service.php`), which remains a crontab-fed batch queue and is a poor fit for an interactive MCP session.
