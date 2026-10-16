# Modern Chrome engine

TagUI and RPA-Python can run a useful subset of flows on current Chrome or Edge without CasperJS or PhantomJS. The new engine is opt-in. A run that does not pass `modern` still uses CasperJS, PhantomJS, and the existing Chrome file bridge.

This note is the architecture record, the comparison of replacement options, the migration plan, and the status of the proof of concept on `marcelocecin/TagUI` branch `rpa_python`.

## How TagUI runs a flow today

`src/tagui` (and `src/tagui.cmd` on Windows) is a launcher. It strips options, then:

1. `php -q tagui_parse.php` reads the flow and writes a CasperJS script (`flow.js`).
2. Optional helpers start for R, Python, Sikuli, or Chrome.
3. `casperjs flow.js` executes that script. CasperJS runs on PhantomJS by default. `firefox` uses SlimerJS, which is also unmaintained.

`package.json` depends on `casperjs`, `phantomjs-prebuilt`, and `slimerjs`. PhantomJS has had no release since 2018 and does not run on Apple Silicon.

### Parser

`src/tagui_parse.php` turns each step into JavaScript wrapped in `casper.then(...)`. Control flow (`if` / `else` / `for` / `{` `}`) becomes real JavaScript around those steps. The natural-language conditions in `src/samples/4_conditions` (`contains`, `equals to`, `more than`, `and` / `or`, `for n from X to Y`) are rewritten before that JavaScript is emitted.

Chrome and headless Chrome do not remove CasperJS. After the script is generated, a post-pass rewrites `casper.*` calls to `chrome.*`. Those helpers live in `src/tagui_header.js`. `src/tagui_footer.js` closes the run with `casper.then` plus `casper.run()`.

### Chrome is already DevTools, behind a file bridge

For `chrome` and `headless`, the launcher starts Chrome with `--remote-debugging-port=9222` and a fixed profile `chrome/tagui_user_profile`, then starts `src/tagui_chrome.php`.

`tagui_header.js` does not open a websocket itself. Each browser action writes a numbered JSON line to `tagui_chrome.in`. The PHP process polls that file (default 100000 microseconds), sends the command over the vendored Textalk websocket client in `src/ws/`, and writes the reply to `tagui_chrome.out`. The JavaScript side polls the out file until the matching id appears. Clicks are mouse events in the Puppeteer style (`Input.dispatchMouseEvent`), not DOM `.click()`.

So the dependency split is:

| Piece | Needs CasperJS / PhantomJS | Needs Chrome |
| --- | --- | --- |
| Step sequencing, `casper.then`, `casper.wait`, `casper.run` | yes | no |
| Live-mode read loop | yes, today | no |
| `url`, `click`, `type`, `read`, `snap` in headless/chrome mode | the sequencer only | yes, via CDP |
| Default browser when no browser flag is set | PhantomJS, and Casper's own page API | no |
| `firefox` | SlimerJS | no |
| Sikuli / visual steps | the sequencer | no, they use a separate Sikuli process |

The Chrome websocket code and the locator order already exist. What is stuck on CasperJS is the sequencer and the default headless browser.

### Locators

`tx()` / `check_tx()` in `tagui_header.js` resolve a target in this order: CSS selector, then an XPath search over id, name, class, title, aria-label, visible text, and href. XPath is chosen when the locator contains `/` or `(`. The modern engine repeats that order inside the page with `Runtime.evaluate`.

### Live mode, and why RPA-Python cares

RPA-Python does not call TagUI as a library. `tagui.py` in [tebelorg/RPA-Python](https://github.com/tebelorg/RPA-Python) starts:

```text
tagui rpa_python chrome|headless
```

with stdin and stdout pipes. The flow file contains only `live`. The parent waits until stdout contains:

```text
LIVE MODE - type done to quit
```

The banner is followed by a space and a backspace (`\n \b`), which paints a prompt on a terminal and must stay byte-for-byte so existing clients keep matching.

`send()` then, for each instruction:

1. writes `echo "[RPA][id] - <instruction>"`
2. writes the instruction itself
3. writes `echo "[RPA][id] - listening for inputs"`
4. reads until a line starts with `[RPA][` and ends with `] - listening for inputs`

Results come back through `dump EXPR to rpa_python.txt` (`url()`, `title()`, `exist('sel').toString()`, `read X to read_result`). `close()` echoes a finished marker and then `done`, and waits for the process to exit. `check`, `frame`, and `popup` are rejected in live mode because they open a block the single-line reader cannot see.

On this branch, live mode in `tagui_parse.php` is one `casper.then` per line. The copy published for RPA-Python still uses a blocking `while (true)` around `sys.stdin.readLine()`. That loop never returns to Casper, so `wait` and `run` queued inside it do not run until `done`. The local change is the one RPA-Python needs, and it is not in the Tump overlay yet.

`src/tagui_mcp` speaks the same banner. `LiveSession` writes a flow that contains `live`, starts `tagui`, reads until the banner, then sends a step plus an `echo TAGUI_MCP_END_*` marker. `dump` writes an expression to a file. `reap_tagui_chrome` kills the helper on port 9222, `tagui_user_profile`, and `tagui_chrome.php`.

## Tump TagUI-Python versus this branch

RPA-Python installs TagUI by downloading a base zip from tebelorg/Tump releases, then overwriting seven files from [Tump `TagUI-Python`](https://github.com/tebelorg/Tump/tree/master/TagUI-Python):

- `tagui`
- `tagui.cmd`
- `end_processes`
- `end_processes.cmd`
- `tagui_header.js`
- `tagui_parse.php`
- `tagui.sikuli/tagui.py`

Compared on 16 Oct 2026, those files against `rpa_python` at `35cfc42` (before this proof of concept), with CRLF normalized:

| File | SequenceMatcher | What differs |
| --- | --- | --- |
| `end_processes` | 1.000 | identical |
| `end_processes.cmd` | 1.000 | identical |
| `tagui.sikuli/tagui.py` | 1.000 | identical |
| `tagui.cmd` | 0.9986 | local adds a comment that Windows still forwards only arg2–arg9 and that the MCP server uses `TAGUI_PARAM_n` |
| `tagui` | 0.9655 | Tump joins `$2`–`$9` into one string and only scans those slots for a `.csv`. Local forwards `"${@:2}"` separately (spaces, and arguments past `$9`) and treats any remaining `.csv` as the datatable |
| `tagui_header.js` | 0.9738 | local adds `p1`–`p16` with CLI-over-`TAGUI_PARAM_n`, `sq()` so selectors survive quotes, numeric `wait` in live mode, clearer `check` / `frame` / `popup` errors, and `run` via `casper.waitForExec`. Tump's live `wait` says waiting is not relevant, and its live `run` says the step is not supported |
| `tagui_parse.php` | 0.9804 | local adds `csv_row_count()` so PHP 8 does not `count(false)` at EOF, snaps the page before a missing-element exit, and uses one `casper.then` per live line instead of Tump's blocking `while` |

Local `rpa_python` is ahead of the Tump overlay. Nothing in those seven files is a feature this branch lacks, except the runtime string patches RPA-Python applies itself after install (turbo mode, macOS PhantomJS and `python3` shebangs). This proof of concept then adds a `tagui_modern_profile_` kill loop to `end_processes` and `end_processes.cmd`, so those two files now differ from Tump for that reason only.

## Replacement options

The constraints that matter for an upstream-acceptable change:

- Keep the TagUI step language and the existing live stdin/stdout contract, so RPA-Python and the MCP server do not need a new protocol.
- Run on Windows, macOS (including Apple Silicon), and Linux.
- Keep the install small. TagUI's own Chrome bridge is PHP on purpose, to avoid requiring Node or Python for that path.
- Leave the CasperJS engine in place until coverage is close enough that maintainers can flip the default.

| Option | What it keeps | Cost |
| --- | --- | --- |
| Casper-compatible JavaScript shim on a current JS runtime | the parser and `tagui_header.js` almost unchanged | still a JS runtime, and a large fake of `casper.*`, `casper.page`, and Phantom's module system. Phantom-only flows stay phantom-shaped |
| Playwright (Python or Node) | a maintained browser driver, auto-waiting, traces | new runtime and a large browser download. The live contract can be preserved, but every step is reimplemented and the install story changes. RPA-Python would grow a second dependency stack |
| Node + `chrome-remote-interface` or Puppeteer | close to what `chrome.*` already does | Node becomes mandatory. TagUI comments rejected that for the Chrome bridge |
| Python CDP (the RPA-Python process drives Chrome itself) | no Casper inside the Python API | breaks `tagui flow.tag` for everyone who does not use RPA-Python, and splits one language across two engines |
| PHP CDP, opt-in flag (this proof of concept) | PHP, which is already required; the vendored websocket client; the live banner; RPA-Python's `send` / `dump` / `done` unchanged | a second implementation of the step table until it is shared with the parser. Firefox and Sikuli stay on the old engine |

## Recommendation

Drive Chrome and Edge directly from PHP with the DevTools Protocol, behind the `modern` flag. CasperJS stays the default.

Reasons:

- PHP and `src/ws/` are already the Chrome bridge. The new engine removes the file polling and the Casper sequencer, and talks to the page websocket itself.
- No Node, Playwright, or Python runtime is added for the engine. RPA-Python and the MCP server keep working because they only depend on the live banner, echo markers, `dump`, and `done`.
- Chrome and Edge ship native builds for Apple Silicon. The engine does not download PhantomJS.
- A flag lets maintainers try the engine on real flows without changing the default that existing users and the Tump zip expect.

Trade-off: locators and step semantics now exist in `tagui_header.js` and in `src/tagui_modern.php`. They will drift until both sides read one step table. The proof of concept accepts that drift and documents the gap below.

### Phased migration

1. **This change.** `tagui flow modern` runs the PHP engine. Omitted, the run is CasperJS as before. RPA-Python can pass `modern` once it knows the binary supports it; until then it keeps working against Casper live mode.
2. **Grow coverage and share the step table.** Move the intent list (step name, parameter shape, locator, error text) into one place the parser and the PHP engine both use. Add `select`, `upload`, `download`, `table`, `frame`, `popup`, `api`, and `run` as flows need them.
3. **Flip the default for Chrome.** `headless` and `chrome` start the modern engine. A `casper` flag selects the old sequencer. PhantomJS remains available only through that flag.
4. **Drop PhantomJS.** Remove `phantomjs-prebuilt`, `casperjs`, and `slimerjs` from `package.json` when the remaining visual and Firefox paths have a replacement. RPA-Python can then stop patching the PhantomJS binary and the macOS shebang. The Tump overlay shrinks to whatever live-mode delta is still required, and eventually to nothing if upstream live mode is the one-step-per-line version.

## Proof of concept

`src/tagui` and `src/tagui.cmd` accept `modern` in any option slot from 2 to 9, the same way they accept `headless` or `quiet`. When it is set they do not call the parser or CasperJS. They run:

```text
php -q tagui_modern.php <flow> <remaining args>
```

On Unix, `stdbuf -oL tee` line-buffers the log tee. GNU `tee` block-buffers when stdout is a pipe, which would hide the short live-mode banner from RPA-Python and the MCP server. Windows `tee` is still block-buffered; a live parent on Windows may need to wait or the launcher needs an equivalent there.

`phantomjs` is treated as `headless` under `modern`. `firefox` is rejected. `chrome` is a visible window. Any other browser value, including the default, is headless Chrome.

### Browser process

`TaguiChrome` picks a binary in this order: `TAGUI_CHROME_BIN`, then `google-chrome-stable`, `/opt/google/chrome/chrome`, Chromium, Edge, and `google-chrome` last. The last position is deliberate. On this VM `/usr/local/bin/google-chrome` is a wrapper that forces port 9222 and a shared profile, which fights a private debugging port.

The process gets a free port (`127.0.0.1:0`), a profile directory `tagui_modern_profile_<pid>_<hex>` under the temp dir, and `--remote-allow-origins=*` (Chrome 111 and later refuse the websocket without it). Headless uses `--headless=new`. Sandbox is off unless `TAGUI_CHROME_SANDBOX=1`, because containers and CI commonly cannot use the Chrome sandbox. `--no-sandbox` is the default here for that reason. Extra flags disable component extensions and default apps; a stock Chrome otherwise starts a background extension page and the first `/json/list` entry is not the about:blank tab.

Startup polls `/json/list` with a one-second HTTP timeout and prefers the `page` target whose URL is `about:blank`. The websocket timeout is 30 seconds. `Page.enable`, `Runtime.enable`, and `Page.bringToFront` run before the first step. Shutdown closes the socket, signals the process, `pkill`s anything still using that profile path, and deletes the profile. `end_processes` kills leftover `tagui_modern_profile_` processes. The MCP reaper does the same.

### Steps that work

| Step | Behavior |
| --- | --- |
| `http://`, `https://`, `file://` | `Page.navigate`, then poll `document.readyState === "complete"` and a non-blank URL. Minimum wait budget 20 seconds |
| `click` / `tap`, `rclick` / `rtap`, `dclick` / `dtap`, `hover` / `move` | resolve the locator, then `Input.dispatchMouseEvent` at the element center |
| `type` / `enter` | focus, optional `[clear]`, `Input.insertText`, and key events for `[enter]`, `[tab]`, `[esc]`, `[space]` |
| `read` / `fetch` | `read page to var` stores `outerHTML`. An element stores its text or input value |
| `show` / `print` | `show page` prints `innerText`. The classic engine prints HTML here |
| `echo` | same quote and concatenation rules as the classic echo step. Mixed `'` and `"` is an error |
| `snap` | `Page.captureScreenshot` of the page, or a clip of an element, to `snapN.png` or `snap ... to file` |
| `wait` | numeric seconds, default 5, capped at 120. An element wait is still a flow-file feature of the classic engine |
| `dump expr to file` | writes the value with no extra newline. Booleans are `true` and `false` |
| `timeout N` | element-find budget in seconds, default 10 |
| `dom <js>` | `Runtime.evaluate`, result stored in `dom_result` |
| `var = expr` and `var x = ...` | assignments. `exist()`, `present()`, `visible()`, `count()`, `title()`, `url()`, `text()` are calls. `.indexOf`, `.toString`, `.trim`, `.toLowerCase` are methods |
| `` `name` `` | replaced with the variable's value before the step runs |
| `if` / `else if` / `else` | natural-language conditions and a parenthesized JavaScript-shaped condition |
| `for n from A to B` and `for (n=1; n<=N; n++)` | integer range, `infinity` is 1024, ranges larger than 10000 are rejected |
| `{` `}` | blocks, including a block as an if/for body |
| `live` | prints the classic banner and reads stdin until a line whose first characters are `done` |

Flow mode polls for an element until `timeout`. Live mode looks once, matching `check_tx()` (no `waitFor`). A missing element in a flow stops the run, which is the classic `this.exit()` behavior. In live mode the error is printed and the next line is read. Errors in flow mode use `ERROR - [LINE n] ...` so the MCP server's line parser still matches. Quiet mode hides `START`, the step trace, and `FINISH`. `echo` and errors still print.

`p1`–`p16` are filled from the environment `TAGUI_PARAM_n` and then overwritten by non-empty CLI arguments, so the CLI wins.

The process exits 0 after a completed run even when a step set `stopped`. It exits 1 when Chrome fails to start or the flow file is missing. That matches the classic launcher, which reports a successful Casper exit after a step error.

### Steps that are not implemented

`select`, `upload`, `download`, `table`, `check`, `frame`, `popup`, `api`, Python / R / vision / JavaScript blocks, keyboard and mouse Sikuli steps, datatables, `ask`, `test` / xUnit, `while`, `break`, and `continue`. An unknown step is `ERROR - cannot understand step ...` and, in flow mode, stops the run.

`show page` returns visible text, not the HTML the classic engine echoes. Element `snap` clips to the element's box, but the verified screenshot is a full-page capture.

## Wiring that stayed compatible

RPA-Python is not modified. `src/test/test_modern_live.py` speaks its init, send, and close sequence to `tagui <flow> headless modern quiet`: banner, echo markers, `exist().toString()`, `type`, `click`, `read`, `dump`, `url()`, `done`.

The MCP server grew an optional engine selector and nothing else in the protocol:

- `browser_start(..., engine="")` accepts `casper` (default) or `modern`. An empty value follows `TAGUI_ENGINE`, then `casper`.
- `live_command` appends `modern` only for that engine. `modern` is an allowed runner option and is not a browser name, so `headless modern` is valid.
- The reaper also kills processes whose command line contains `tagui_modern_profile_`.

## What was verified on this VM

Linux, PHP 8.3.6 with `pcntl`, Chrome 154 (`google-chrome-stable`), `stdbuf` present. CasperJS and PhantomJS are not installed, so the old engine was not re-run here. Windows was not executed.

- `php -l src/tagui_modern.php` reports no syntax errors.
- `php src/test/test_modern_logic.php` checks condition rewrite, both `for` forms, and a captured flow of `if` / `else` / `for` echoes (`fruits-yes`, `dozen`, `else-yes`, `n is 1` through `n is 3`).
- A quiet headless flow against `src/test/fixtures/modern_page.html` then `https://example.com` echoed `Modern PoC`, typed `[clear]hello` into `name`, clicked `Go`, read `#out` back as `hello`, took the `title() contains "Modern"` branch, ran `for n from 1 to 2`, and echoed `Example Domain`. The page snap was a PNG, 1366 pixels wide. The run finished in about two seconds and exited 0. An earlier Chrome startup of about 60 seconds was the wrapper binary plus component-extension targets; the binary order and the extra disable flags removed that wait.
- `python3 src/test/test_modern_live.py` passed the RPA-style exchange and `LiveSession(engine="modern")` (`echo "from mcp"`, `title()` dump equals `Modern PoC`). No `tagui_modern_profile_` Chrome was left behind.
- `PYTHONPATH=src/tagui_mcp python3 -m pytest src/tagui_mcp/tests -q` passed 24 tests, including `test_live_command_selects_the_engine`. `pytest` and `mcp` are installed in the user environment for that run; they are not new project dependencies beyond what `src/tagui_mcp/pyproject.toml` already declares.

## Gaps to close before a default flip

- Step coverage listed above, especially `select`, frames, popups, file upload, and `run`.
- One shared step table so `tagui_header.js` and the PHP engine do not diverge.
- Windows live-mode output is not line-buffered.
- Element-level `snap` is implemented and not separately asserted.
- `show page` differs from the classic HTML echo.
- Visual automation and Firefox stay on CasperJS / SlimerJS.
- RPA-Python's installer still overlays the Tump files and still patches PhantomJS on macOS. Shipping `modern` as the default inside a Tump zip is a later step, after the flag has been used on real flows.
