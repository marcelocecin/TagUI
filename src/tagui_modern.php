<?php

/* MODERN CHROME ENGINE FOR TAGUI ~ proof of concept
   Drives Chrome or Edge directly with the DevTools Protocol.
   No CasperJS and no PhantomJS. The casperjs engine stays the default.
   Invoked by `tagui flow modern` (headless Chrome unless `chrome` is also set). */

if (!function_exists('str_starts_with')) {
function str_starts_with($haystack, $needle) {
return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;}
function str_ends_with($haystack, $needle) {
if ($needle === '') return true; $len = strlen($needle);
return substr($haystack, -$len) === $needle;}
function str_contains($haystack, $needle) {
return $needle === '' || strpos($haystack, $needle) !== false;}}

require_once __DIR__ . '/ws/Base.php';
require_once __DIR__ . '/ws/Client.php';
require_once __DIR__ . '/ws/Exception.php';
require_once __DIR__ . '/ws/BadOpcodeException.php';
require_once __DIR__ . '/ws/BadUriException.php';
require_once __DIR__ . '/ws/ConnectionException.php';

/* -------------------------------------------------------------------------- */
/* Chrome / Edge                                                              */
/* -------------------------------------------------------------------------- */

class TaguiChrome {
public $port = 0;
public $profile = '';
public $headless = true;
public $timeout = 10.0;
private $process = null;
private $client = null;
private $next_id = 0;
private $log_path = '';

function __construct() {}

function start($headless) {
$this->headless = $headless;
$binary = $this->find_binary();
if ($binary === '') {
throw new RuntimeException('cannot find Chrome or Edge. Set TAGUI_CHROME_BIN to the browser executable');}
$this->port = $this->free_port();
$this->profile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR .
'tagui_modern_profile_' . getmypid() . '_' . bin2hex(random_bytes(4));
if (!mkdir($this->profile, 0700, true) && !is_dir($this->profile)) {
throw new RuntimeException('cannot create Chrome profile ' . $this->profile);}
$this->log_path = $this->profile . DIRECTORY_SEPARATOR . 'chrome.log';
$args = array(
$binary,
'--remote-debugging-port=' . $this->port,
'--remote-allow-origins=*',
'--user-data-dir=' . $this->profile,
'--window-size=1366,768',
'--force-device-scale-factor=1',
'--no-first-run',
'--no-default-browser-check',
'--disable-extensions',
'--disable-component-extensions-with-background-pages',
'--disable-default-apps',
'--disable-sync',
'--disable-background-networking',
'--disable-popup-blocking',
'--no-service-autorun',
'--metrics-recording-only',
'--hide-scrollbars',
'--allow-file-access-from-files');
if ($headless) {array_push($args, '--headless=new', '--disable-gpu');}
if (getenv('TAGUI_CHROME_SANDBOX') !== '1') {
array_push($args, '--no-sandbox', '--disable-dev-shm-usage');}
$args[] = 'about:blank';
$descriptor = array(
0 => array('file', '/dev/null', 'r'),
1 => array('file', $this->log_path, 'a'),
2 => array('file', $this->log_path, 'a'));
if (DIRECTORY_SEPARATOR === '\\') {$descriptor[0] = array('file', 'NUL', 'r');}
$this->process = proc_open($args, $descriptor, $pipes);
if (!is_resource($this->process)) {
throw new RuntimeException('cannot start ' . $binary);}
$ws = $this->wait_for_page_socket();
$this->client = new WebSocket\Client($ws, array('timeout' => 30, 'fragment_size' => 65536));
$this->command('Page.enable', new stdClass());
$this->command('Runtime.enable', new stdClass());
$this->command('Page.bringToFront', new stdClass());}

function stop() {
if ($this->client !== null) {
try {$this->client->close();} catch (Exception $e) {}
$this->client = null;}
if (is_resource($this->process)) {
$status = proc_get_status($this->process);
if (!empty($status['pid'])) {
if (function_exists('posix_kill')) {@posix_kill((int)$status['pid'], 15);}
else {proc_terminate($this->process);}
}
proc_close($this->process);
$this->process = null;}
if ($this->profile !== '' && $this->profile !== '/' && str_contains($this->profile, 'tagui_modern_profile_')) {
if (DIRECTORY_SEPARATOR !== '\\') {
@exec('pkill -f ' . escapeshellarg($this->profile) . ' >/dev/null 2>&1');}
$this->remove_tree($this->profile);}
$this->profile = '';}

function navigate($url) {
$this->command('Page.navigate', array('url' => $url));
$deadline = microtime(true) + max($this->timeout, 20);
$last = '';
while (microtime(true) < $deadline) {
$state = $this->evaluate('document.readyState');
$href = $this->evaluate('document.location.href');
$last = is_string($href) ? $href : '';
if ($state === 'complete' && $last !== '' && $last !== 'about:blank') {
usleep(150000);
return $last;}
usleep(100000);}
if ($last === '' || $last === 'about:blank') {
throw new RuntimeException('navigation timed out for ' . $url);}
return $last;}

function evaluate($expression) {
$result = $this->command('Runtime.evaluate', array(
'expression' => $expression,
'returnByValue' => true));
if (!empty($result['exceptionDetails'])) {
$detail = $result['exceptionDetails'];
$text = '';
if (isset($detail['exception']['description'])) $text = $detail['exception']['description'];
else if (isset($detail['text'])) $text = $detail['text'];
throw new RuntimeException('page script failed: ' . $text);}
if (!isset($result['result'])) return null;
if (array_key_exists('value', $result['result'])) return $result['result']['value'];
return null;}

function title() {
$value = $this->evaluate('document.title');
return is_string($value) ? $value : '';}

function url() {
$value = $this->evaluate('document.location.href');
if ($value === 'data:text/html,chromewebdata' || $value === null) return 'about:blank';
return is_string($value) ? $value : '';}

function body_text() {
$value = $this->evaluate('document.body ? (document.body.innerText || document.body.textContent || "") : ""');
return is_string($value) ? $value : '';}

function page_html() {
$value = $this->evaluate('document.documentElement ? document.documentElement.outerHTML : ""');
return is_string($value) ? $value : '';}

function locate($locator) {
$payload = json_encode($locator, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$script = <<<'JS'
(function (locator) {
function isXPath(value) {return value.charAt(0) === "/" || value.charAt(0) === "(";}
function nodes(xpath) {
try {return document.evaluate(xpath, document, null, XPathResult.ORDERED_NODE_SNAPSHOT_TYPE, null);}
catch (e) {return null;}}
function first(xpath) {var snap = nodes(xpath); if (!snap || snap.snapshotLength < 1) return null; return snap.snapshotItem(0);}
function countOf(xpath) {var snap = nodes(xpath); return snap ? snap.snapshotLength : 0;}
function cssCount(selector) {try {return document.querySelectorAll(selector).length;} catch (e) {return 0;}}
function cssFirst(selector) {try {return document.querySelector(selector);} catch (e) {return null;}}
function describe(el) {
if (!el || !el.getBoundingClientRect) return null;
try {el.scrollIntoView({block:"center", inline:"center"});} catch (e) {}
var rect = el.getBoundingClientRect();
var style = window.getComputedStyle(el);
var visible = !!style && style.display !== "none" && style.visibility !== "hidden" && style.opacity !== "0" && rect.width > 0 && rect.height > 0;
return {x: Math.round(rect.left + rect.width / 2), y: Math.round(rect.top + rect.height / 2),
left: Math.round(rect.left), top: Math.round(rect.top), width: Math.round(rect.width), height: Math.round(rect.height),
text: (el.value || el.innerText || el.textContent || ""), visible: visible};}
var quoted = locator.replace(/\\/g, "\\\\").replace(/"/g, '\\"');
var paths = ['//*[@id="'+quoted+'"]','//*[contains(@id,"'+quoted+'")]','//*[@name="'+quoted+'"]',
'//*[contains(@name,"'+quoted+'")]','//*[@class="'+quoted+'"]','//*[contains(@class,"'+quoted+'")]',
'//*[@title="'+quoted+'"]','//*[contains(@title,"'+quoted+'")]','//*[@aria-label="'+quoted+'"]',
'//*[contains(@aria-label,"'+quoted+'")]','//*[text()="'+quoted+'"]','//*[contains(text(),"'+quoted+'")]',
'//*[@href="'+quoted+'"]','//*[contains(@href,"'+quoted+'")]'];
var el = null; var total = 0;
if (isXPath(locator)) {el = first(locator); total = countOf(locator);}
else {
total = cssCount(locator); el = cssFirst(locator);
if (!el) {for (var i = 0; i < paths.length; i++) {el = first(paths[i]); if (el) {total = countOf(paths[i]); break;}}}
else if (total < 1) total = 1;}
if (!el) return {found:false, count:0};
var info = describe(el); if (!info) return {found:false, count:0};
info.found = true; info.count = total; return info;})
JS;
$info = $this->evaluate($script . '(' . $payload . ')');
if (!is_array($info) || empty($info['found'])) return null;
return $info;}

function find($locator, $live) {
$deadline = microtime(true) + ($live ? 0 : $this->timeout);
do {
$info = $this->locate($locator);
if ($info !== null) return $info;
if ($live) return null;
usleep(200000);
} while (microtime(true) < $deadline);
return null;}

function click_at($x, $y, $button, $clicks) {
$this->command('Input.dispatchMouseEvent', array(
'type' => 'mouseMoved', 'x' => $x, 'y' => $y, 'button' => 'none', 'clickCount' => 0));
$this->command('Input.dispatchMouseEvent', array(
'type' => 'mousePressed', 'x' => $x, 'y' => $y, 'button' => $button, 'clickCount' => $clicks));
$this->command('Input.dispatchMouseEvent', array(
'type' => 'mouseReleased', 'x' => $x, 'y' => $y, 'button' => $button, 'clickCount' => $clicks));}

function hover_at($x, $y) {
$this->command('Input.dispatchMouseEvent', array(
'type' => 'mouseMoved', 'x' => $x, 'y' => $y, 'button' => 'none', 'clickCount' => 0));}

function type_into($locator, $text) {
$clear = false;
if (str_starts_with(strtolower($text), '[clear]')) {$clear = true; $text = substr($text, 7);}
$payload = json_encode($locator, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$clear_js = $clear ? 'true' : 'false';
$script = <<<'JS'
(function (locator, clear) {
function isXPath(value) {return value.charAt(0) === "/" || value.charAt(0) === "(";}
function first(xpath) {try {var snap = document.evaluate(xpath, document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null); return snap.singleNodeValue;} catch (e) {return null;}}
var el = null;
if (isXPath(locator)) el = first(locator);
else {try {el = document.querySelector(locator);} catch (e) {el = null;}}
if (!el) {
var quoted = locator.replace(/\\/g, "\\\\").replace(/"/g, '\\"');
var paths = ['//*[@id="'+quoted+'"]','//*[contains(@id,"'+quoted+'")]','//*[@name="'+quoted+'"]','//*[contains(@name,"'+quoted+'")]','//*[@aria-label="'+quoted+'"]','//*[contains(text(),"'+quoted+'")]'];
for (var i = 0; i < paths.length; i++) {el = first(paths[i]); if (el) break;}}
if (!el) return false;
try {el.scrollIntoView({block:"center", inline:"center"});} catch (e) {}
el.focus();
if (clear && ("value" in el)) {el.value = ""; el.dispatchEvent(new Event("input", {bubbles:true})); el.dispatchEvent(new Event("change", {bubbles:true}));}
return true;})
JS;
$focused = $this->evaluate($script . '(' . $payload . ',' . $clear_js . ')');
if ($focused !== true) return false;
$parts = preg_split('/(\[(?:enter|tab|space|esc|ENTER|TAB|SPACE|ESC)\])/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
foreach ($parts as $part) {
if ($part === '') continue;
$lower = strtolower($part);
if ($lower === '[enter]') {$this->key_name('Enter'); continue;}
if ($lower === '[tab]') {$this->key_name('Tab'); continue;}
if ($lower === '[esc]') {$this->key_name('Escape'); continue;}
if ($lower === '[space]') {$part = ' ';}
$this->command('Input.insertText', array('text' => $part));}
return true;}

function screenshot($path, $clip) {
$params = array('format' => 'png', 'fromSurface' => true);
if ($clip !== null) $params['clip'] = $clip;
$result = $this->command('Page.captureScreenshot', $params);
if (empty($result['data'])) return false;
$bytes = base64_decode($result['data'], true);
if ($bytes === false) return false;
return file_put_contents($path, $bytes) !== false;}

private function key_name($key) {
$codes = array('Enter' => 13, 'Tab' => 9, 'Escape' => 27);
$code = isset($codes[$key]) ? $codes[$key] : 0;
$this->command('Input.dispatchKeyEvent', array(
'type' => 'keyDown', 'key' => $key, 'code' => $key, 'windowsVirtualKeyCode' => $code, 'nativeVirtualKeyCode' => $code));
$this->command('Input.dispatchKeyEvent', array(
'type' => 'keyUp', 'key' => $key, 'code' => $key, 'windowsVirtualKeyCode' => $code, 'nativeVirtualKeyCode' => $code));}

private function command($method, $params) {
if ($params instanceof stdClass) $encoded_params = new stdClass();
else $encoded_params = $params;
$this->next_id++;
$id = $this->next_id;
$body = json_encode(array('id' => $id, 'method' => $method, 'params' => $encoded_params));
$this->client->send($body);
$deadline = microtime(true) + 30;
while (microtime(true) < $deadline) {
try {$raw = $this->client->receive();}
catch (Exception $e) {continue;}
$message = json_decode($raw, true);
if (!is_array($message) || !isset($message['id']) || (int)$message['id'] !== $id) continue;
if (isset($message['error'])) {
throw new RuntimeException($method . ' failed: ' . json_encode($message['error']));}
return isset($message['result']) ? $message['result'] : array();}
throw new RuntimeException('timed out waiting for ' . $method);}

private function wait_for_page_socket() {
$deadline = microtime(true) + 20;
$previous_timeout = ini_get('default_socket_timeout');
ini_set('default_socket_timeout', '1');
while (microtime(true) < $deadline) {
$context = stream_context_create(array('http' => array('timeout' => 1)));
$raw = @file_get_contents('http://127.0.0.1:' . $this->port . '/json/list', false, $context);
if ($raw) {
$list = json_decode($raw, true);
$fallback = '';
if (is_array($list)) {
foreach ($list as $target) {
if (!isset($target['type']) || $target['type'] !== 'page' || empty($target['webSocketDebuggerUrl'])) continue;
if (isset($target['url']) && $target['url'] === 'about:blank') {
ini_set('default_socket_timeout', '30');
return $target['webSocketDebuggerUrl'];}
if ($fallback === '') $fallback = $target['webSocketDebuggerUrl'];}
if ($fallback !== '') {ini_set('default_socket_timeout', '30'); return $fallback;}}}
$status = is_resource($this->process) ? proc_get_status($this->process) : null;
if (is_array($status) && !$status['running']) break;
usleep(100000);}
ini_set('default_socket_timeout', (string)$previous_timeout);
$log = '';
if ($this->log_path !== '' && is_file($this->log_path)) $log = trim((string)file_get_contents($this->log_path));
if (strlen($log) > 500) $log = substr($log, -500);
throw new RuntimeException('Chrome did not open a DevTools socket on port ' . $this->port . ($log !== '' ? ' ' . $log : ''));}

private function find_binary() {
$from_env = getenv('TAGUI_CHROME_BIN');
if (is_string($from_env) && $from_env !== '' && is_file($from_env)) return $from_env;
$candidates = array(
'google-chrome-stable', '/opt/google/chrome/chrome', 'chromium', 'chromium-browser',
'microsoft-edge-stable', 'microsoft-edge', 'google-chrome',
'/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
'/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
'/Applications/Chromium.app/Contents/MacOS/Chromium');
if (getenv('PROGRAMFILES')) {
$pf = getenv('PROGRAMFILES'); $pf86 = getenv('PROGRAMFILES(X86)'); $local = getenv('LOCALAPPDATA');
$candidates[] = $pf . '\\Google\\Chrome\\Application\\chrome.exe';
$candidates[] = $pf . '\\Microsoft\\Edge\\Application\\msedge.exe';
if ($pf86) {
$candidates[] = $pf86 . '\\Google\\Chrome\\Application\\chrome.exe';
$candidates[] = $pf86 . '\\Microsoft\\Edge\\Application\\msedge.exe';}
if ($local) {
$candidates[] = $local . '\\Google\\Chrome\\Application\\chrome.exe';
$candidates[] = $local . '\\Microsoft\\Edge\\Application\\msedge.exe';}}
foreach ($candidates as $candidate) {
if ($candidate === '') continue;
if (str_contains($candidate, DIRECTORY_SEPARATOR) || str_contains($candidate, '/')) {
if (is_file($candidate)) return $candidate;
continue;}
$found = $this->which($candidate);
if ($found !== '') return $found;}
return '';}

private function which($name) {
$paths = explode(PATH_SEPARATOR, (string)getenv('PATH'));
foreach ($paths as $dir) {
$file = $dir . DIRECTORY_SEPARATOR . $name;
if (is_file($file) && is_executable($file)) return $file;}
return '';}

private function free_port() {
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) throw new RuntimeException('cannot reserve a debugging port');
$name = stream_socket_get_name($server, false);
fclose($server);
$port = (int)substr($name, strrpos($name, ':') + 1);
if ($port < 1) throw new RuntimeException('cannot reserve a debugging port');
return $port;}

private function remove_tree($dir) {
if (!is_dir($dir)) return;
$items = scandir($dir);
if ($items === false) return;
foreach ($items as $item) {
if ($item === '.' || $item === '..') continue;
$path = $dir . DIRECTORY_SEPARATOR . $item;
if (is_dir($path)) $this->remove_tree($path); else @unlink($path);}
@rmdir($dir);}}

/* -------------------------------------------------------------------------- */
/* Expressions                                                                */
/* -------------------------------------------------------------------------- */

class TaguiExprError extends RuntimeException {}

class TaguiExpr {
private $tokens = array();
private $index = 0;
private $vars;
private $calls;

function __construct($vars, $calls) {$this->vars = $vars; $this->calls = $calls;}

function evaluate($source) {
$this->tokens = $this->tokenize($source);
$this->index = 0;
if (count($this->tokens) === 0) return '';
$value = $this->parse_or();
if ($this->index < count($this->tokens)) {
throw new TaguiExprError('cannot understand expression ' . $source);}
return $value;}

private function tokenize($source) {
$tokens = array(); $i = 0; $n = strlen($source);
while ($i < $n) {
$c = $source[$i];
if (ctype_space($c)) {$i++; continue;}
if ($c === '"' || $c === "'") {
$quote = $c; $i++; $buf = '';
while ($i < $n && $source[$i] !== $quote) {
if ($source[$i] === '\\' && $i + 1 < $n) {$buf .= $source[$i + 1]; $i += 2; continue;}
$buf .= $source[$i]; $i++;}
if ($i >= $n) throw new TaguiExprError('unterminated string');
$i++; $tokens[] = array('str', $buf); continue;}
if (ctype_digit($c) || ($c === '.' && $i + 1 < $n && ctype_digit($source[$i + 1]))) {
$j = $i; while ($j < $n && (ctype_digit($source[$j]) || $source[$j] === '.')) $j++;
$tokens[] = array('num', substr($source, $i, $j - $i)); $i = $j; continue;}
if (ctype_alpha($c) || $c === '_') {
$j = $i; while ($j < $n && (ctype_alnum($source[$j]) || $source[$j] === '_')) $j++;
$tokens[] = array('id', substr($source, $i, $j - $i)); $i = $j; continue;}
$two = substr($source, $i, 2);
if (in_array($two, array('==', '!=', '>=', '<=', '&&', '||'), true)) {
$tokens[] = array('op', $two); $i += 2; continue;}
if (str_contains('+-*/<>().,', $c)) {
$kind = ($c === '(' || $c === ')' || $c === '.' || $c === ',') ? $c : 'op';
$tokens[] = array($kind, $c); $i++; continue;}
throw new TaguiExprError('unexpected character in expression');}
return $tokens;}

private function peek() {return $this->index < count($this->tokens) ? $this->tokens[$this->index] : null;}
private function pop() {$token = $this->peek(); if ($token === null) throw new TaguiExprError('unexpected end of expression'); $this->index++; return $token;}
private function is_op($op) {$token = $this->peek(); return $token !== null && $token[0] === 'op' && $token[1] === $op;}
private function eat_kind($kind) {
$token = $this->pop(); if ($token[0] !== $kind) throw new TaguiExprError('expected ' . $kind); return $token[1];}

private function parse_or() {
$value = $this->parse_and();
while ($this->is_op('||')) {$this->pop(); $right = $this->parse_and(); $value = $this->truthy($value) || $this->truthy($right);}
return $value;}
private function parse_and() {
$value = $this->parse_cmp();
while ($this->is_op('&&')) {$this->pop(); $right = $this->parse_cmp(); $value = $this->truthy($value) && $this->truthy($right);}
return $value;}
private function parse_cmp() {
$value = $this->parse_add();
while (true) {
$token = $this->peek();
if ($token === null || $token[0] !== 'op' || !in_array($token[1], array('==','!=','>=','<=','>','<'), true)) break;
$this->pop(); $right = $this->parse_add(); $value = $this->compare($token[1], $value, $right);}
return $value;}
private function parse_add() {
$value = $this->parse_mul();
while ($this->is_op('+') || $this->is_op('-')) {
$op = $this->pop()[1]; $right = $this->parse_mul();
if ($op === '+') $value = $this->plus($value, $right); else $value = $this->num($value) - $this->num($right);}
return $value;}
private function parse_mul() {
$value = $this->parse_unary();
while ($this->is_op('*') || $this->is_op('/')) {
$op = $this->pop()[1]; $right = $this->parse_unary();
$value = $op === '*' ? $this->num($value) * $this->num($right) : $this->num($value) / $this->num($right);}
return $value;}
private function parse_unary() {
if ($this->is_op('-')) {$this->pop(); return -$this->num($this->parse_unary());}
if ($this->is_op('+')) {$this->pop(); return $this->num($this->parse_unary());}
if ($this->is_op('!')) {$this->pop(); return !$this->truthy($this->parse_unary());}
return $this->parse_postfix();}
private function parse_postfix() {
$value = $this->parse_primary();
while (true) {
$token = $this->peek();
if ($token === null || $token[0] !== '.') break;
$this->pop(); $name = $this->eat_kind('id'); $this->eat_kind('('); $args = $this->parse_args();
$value = $this->method($value, $name, $args);}
return $value;}
private function parse_primary() {
$token = $this->pop();
if ($token[0] === 'str') return $token[1];
if ($token[0] === 'num') return str_contains($token[1], '.') ? (float)$token[1] : (int)$token[1];
if ($token[0] === '(') {$value = $this->parse_or(); $this->eat_kind(')'); return $value;}
if ($token[0] === 'id') {
$name = $token[1];
$next = $this->peek();
if ($next !== null && $next[0] === '(') {
$this->pop(); $args = $this->parse_args();
if (!isset($this->calls[$name])) throw new TaguiExprError('unknown function ' . $name);
return call_user_func($this->calls[$name], $args);}
if (is_array($this->vars) && array_key_exists($name, $this->vars)) return $this->vars[$name];
if ($name === 'true') return true; if ($name === 'false') return false; if ($name === 'null') return null;
throw new TaguiExprError('variable ' . $name . ' is not defined');}
throw new TaguiExprError('unexpected token');}
private function parse_args() {
$args = array();
$next = $this->peek();
if ($next !== null && $next[0] === ')') {$this->pop(); return $args;}
while (true) {
$args[] = $this->parse_or();
$next = $this->peek();
if ($next !== null && $next[0] === ',') {$this->pop(); continue;}
$this->eat_kind(')'); break;}
return $args;}
private function method($value, $name, $args) {
if ($name === 'indexOf') {
$needle = isset($args[0]) ? (string)$this->stringish($args[0]) : '';
$pos = strpos((string)$this->stringish($value), $needle);
return $pos === false ? -1 : $pos;}
if ($name === 'toString') return $this->stringish($value);
if ($name === 'trim') return trim((string)$this->stringish($value));
if ($name === 'toLowerCase') return strtolower((string)$this->stringish($value));
throw new TaguiExprError('unknown method ' . $name);}
private function plus($left, $right) {
if (is_string($left) || is_string($right)) return $this->stringish($left) . $this->stringish($right);
if (is_bool($left) || is_bool($right)) return $this->stringish($left) . $this->stringish($right);
return $this->num($left) + $this->num($right);}
private function compare($op, $left, $right) {
if (is_string($left) || is_string($right)) {$left = $this->stringish($left); $right = $this->stringish($right);}
if ($op === '==') return $left == $right; if ($op === '!=') return $left != $right;
if ($op === '>') return $left > $right; if ($op === '<') return $left < $right;
if ($op === '>=') return $left >= $right; return $left <= $right;}
function truthy($value) {
if ($value === false || $value === null || $value === '' || $value === 0 || $value === 0.0) return false;
return true;}
function stringish($value) {
if ($value === true) return 'true'; if ($value === false) return 'false'; if ($value === null) return 'null';
return (string)$value;}
private function num($value) {if (is_bool($value)) return $value ? 1 : 0; return $value + 0;}}

/* -------------------------------------------------------------------------- */
/* Flow interpreter                                                           */
/* -------------------------------------------------------------------------- */

class TaguiModern {
public $chrome;
public $quiet = false;
public $capture = false;
public $captured = array();
public $vars = array();
public $flow_path = '';
public $live = false;
public $stopped = false;
public $timeout = 10.0;
public $snap_count = 0;
public $started_at = 0;

function __construct($chrome, $quiet, $flow_path) {
$this->chrome = $chrome; $this->quiet = $quiet; $this->flow_path = $flow_path;
$this->vars['infinity'] = 1024;
for ($i = 1; $i <= 16; $i++) {
$env = getenv('TAGUI_PARAM_' . $i);
if ($env !== false && $env !== '') $this->vars['p' . $i] = $env;}}

function set_cli_params($args) {
$index = 1;
foreach ($args as $arg) {
if ($index > 16) break;
if ($arg !== '') $this->vars['p' . $index] = $arg;
$index++;}}

function run_lines($lines) {
$this->started_at = microtime(true);
if (!$this->quiet) $this->emit_raw("\nSTART - automation started - " . date('D M j Y H:i:s'));
$this->execute_range($lines, 0, count($lines) - 1, true);
if (!$this->quiet) {
$url = 'about:blank'; $title = '';
if ($this->chrome !== null) {try {$url = $this->chrome->url(); $title = $this->chrome->title();} catch (Exception $e) {}}
$this->emit_raw("\n" . $url . ' - ' . $title);
$seconds = number_format(microtime(true) - $this->started_at, 1, '.', '');
$this->emit_raw('FINISH - automation finished - ' . $seconds . 's');}}

function execute_range($lines, $start, $end, $active) {
$i = $start;
while ($i <= $end) {
if ($this->stopped && $active) return;
$trimmed = trim($lines[$i]);
if ($trimmed === '' || str_starts_with($trimmed, '//')) {$i++; continue;}
$i = $this->execute_statement($lines, $i, $end, $active);}}

function execute_statement($lines, $i, $end, $active) {
$line = $this->expand_backticks(trim($lines[$i]));
$lower = strtolower($line);
if ($lower === '{') {
$close = $this->find_block_end($lines, $i, $end);
if ($close < 0) {if ($active) $this->fail($i, 'number of step { does not tally with }'); return $end + 1;}
if ($active) $this->execute_range($lines, $i + 1, $close - 1, true);
return $close + 1;}
if ($line === '}') {if ($active) $this->fail($i, 'unexpected }'); return $i + 1;}
if (str_starts_with($lower, 'if ')) return $this->execute_if($lines, $i, $end, $active);
if (str_starts_with($lower, 'for ') || str_starts_with($lower, 'for(')) return $this->execute_for($lines, $i, $end, $active);
if ($active) $this->execute_step($line, $i);
return $i + 1;}

function execute_if($lines, $i, $end, $active) {
$matched = false;
while ($i <= $end) {
$line = $this->expand_backticks(trim($lines[$i]));
$lower = strtolower($line);
$is_else_if = str_starts_with($lower, 'else if ');
$is_else = ($lower === 'else');
$is_if = str_starts_with($lower, 'if ');
if (!$is_if && !$is_else_if && !$is_else) break;
$take = false;
if ($is_else) $take = $active && !$matched;
else {
$expr = $this->condition_expression($line);
$take = $active && !$matched && $this->truthy_expr($expr, $i);}
if ($take) $matched = true;
$i++;
$i = $this->execute_body($lines, $i, $end, $take);
if ($is_else) break;
$next = $this->next_code($lines, $i, $end);
if ($next === null) break;
$peek = strtolower(trim($lines[$next]));
if ($peek === 'else' || str_starts_with($peek, 'else if ')) {$i = $next; continue;}
break;}
return $i;}

function execute_body($lines, $i, $end, $active) {
$next = $this->next_code($lines, $i, $end);
if ($next === null) return $i;
return $this->execute_statement($lines, $next, $end, $active);}

function execute_for($lines, $i, $end, $active) {
$spec = $this->parse_for(trim($lines[$i]));
if ($spec === null) {if ($active) $this->fail($i, 'invalid for loop - ' . trim($lines[$i])); return $i + 1;}
$body = $this->next_code($lines, $i + 1, $end);
if ($body === null) return $end + 1;
if (!$active) return $this->execute_statement($lines, $body, $end, false);
$from = (int)$spec['from']; $to = (int)$spec['to'];
if (($to - $from) > 10000) {$this->fail($i, 'for loop range is too large'); return $this->execute_statement($lines, $body, $end, false);}
$after = $body;
if ($from > $to) return $this->execute_statement($lines, $body, $end, false);
for ($value = $from; $value <= $to; $value++) {
if ($this->stopped) break;
$this->vars[$spec['var']] = $value;
$after = $this->execute_statement($lines, $body, $end, true);}
return $after;}

function execute_step($line, $index) {
$lower = strtolower($line);
if ($lower === 'live' || str_starts_with($lower, 'live ')) {
if ($this->live) {$this->fail($index, 'you are already in live mode, type done to quit live mode'); return;}
$this->live_loop(); return;}
if (str_starts_with($lower, 'http://') || str_starts_with($lower, 'https://') || str_starts_with($lower, 'file://')) {
$this->trace($line); $this->go($line, $index); return;}
if (str_starts_with($lower, 'click ') || str_starts_with($lower, 'tap ') || $lower === 'click' || $lower === 'tap') {
$this->trace($line); $this->pointer($line, 'left', 1, $index); return;}
if (str_starts_with($lower, 'rclick ') || str_starts_with($lower, 'rtap ')) {
$this->trace($line); $this->pointer($line, 'right', 1, $index); return;}
if (str_starts_with($lower, 'dclick ') || str_starts_with($lower, 'dtap ')) {
$this->trace($line); $this->pointer($line, 'left', 2, $index); return;}
if (str_starts_with($lower, 'hover ') || str_starts_with($lower, 'move ')) {
$this->trace($line); $this->hover($line, $index); return;}
if (str_starts_with($lower, 'type ') || str_starts_with($lower, 'enter ')) {
$this->trace($line); $this->type_step($line, $index); return;}
if (str_starts_with($lower, 'read ') || str_starts_with($lower, 'fetch ')) {
$this->trace($line); $this->read_step($line, $index); return;}
if (str_starts_with($lower, 'show ') || str_starts_with($lower, 'print ')) {
$this->show_step($line, $index); return;}
if (str_starts_with($lower, 'echo ') || $lower === 'echo') {$this->echo_step($line, $index); return;}
if (str_starts_with($lower, 'snap ') || $lower === 'snap') {$this->trace($line); $this->snap_step($line, $index); return;}
if (str_starts_with($lower, 'wait ') || $lower === 'wait') {$this->trace($line); $this->wait_step($line, $index); return;}
if (str_starts_with($lower, 'dump ') || $lower === 'dump') {$this->dump_step($line, $index); return;}
if (str_starts_with($lower, 'timeout ')) {$this->timeout_step($line, $index); return;}
if (str_starts_with($lower, 'dom ')) {$this->dom_step($line, $index); return;}
if ($this->is_assignment($line)) {$this->assign_step($line, $index); return;}
$this->fail($index, 'cannot understand step ' . $line);}

function live_loop() {
$this->live = true;
fwrite(STDOUT, "LIVE MODE - type done to quit\n \x08");
fflush(STDOUT);
while (true) {
$raw = fgets(STDIN);
if ($raw === false) break;
$raw = rtrim($raw, "\r\n");
if (str_starts_with($raw, 'done')) break;
$line = $this->expand_backticks(trim($raw));
if ($line === '' || str_starts_with($line, '//')) continue;
try {$this->execute_step($line, -1);}
catch (Exception $e) {$this->emit('ERROR - ' . $e->getMessage());}}
$this->live = false;}

function go($url, $index) {
if (!$this->require_chrome($index)) return;
try {$this->chrome->navigate($url);}
catch (Exception $e) {$this->fail($index, $e->getMessage());}}

function pointer($line, $button, $clicks, $index) {
$target = trim(substr($line, strpos($line, ' ') + 1));
if ($target === '' || strcasecmp($target, 'click') === 0) {$this->fail($index, 'target missing for ' . $line); return;}
$info = $this->need($target, $index); if ($info === null) return;
try {$this->chrome->click_at((float)$info['x'], (float)$info['y'], $button, $clicks);}
catch (Exception $e) {$this->fail($index, $e->getMessage());}}

function hover($line, $index) {
$target = trim(substr($line, strpos($line, ' ') + 1));
$info = $this->need($target, $index); if ($info === null) return;
try {$this->chrome->hover_at((float)$info['x'], (float)$info['y']);}
catch (Exception $e) {$this->fail($index, $e->getMessage());}}

function type_step($line, $index) {
$params = trim(substr($line, strpos($line, ' ') + 1));
$split = stripos($params, ' as ');
if ($split === false) {$this->fail($index, 'target/text missing for ' . $line); return;}
$target = trim(substr($params, 0, $split));
$text = substr($params, $split + 4);
if ($target === '') {$this->fail($index, 'target/text missing for ' . $line); return;}
if ($this->need($target, $index) === null) return;
try {
if (!$this->chrome->type_into($target, $text)) $this->fail($index, 'cannot find ' . $target);}
catch (Exception $e) {$this->fail($index, $e->getMessage());}}

function read_step($line, $index) {
$params = trim(substr($line, strpos($line, ' ') + 1));
$split = stripos($params, ' to ');
if ($split === false) {$this->fail($index, 'target/variable missing for ' . $line); return;}
$target = trim(substr($params, 0, $split));
$name = trim(substr($params, $split + 4));
if ($target === '' || $name === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
$this->fail($index, 'target/variable missing for ' . $line); return;}
if (strcasecmp($target, 'page') === 0) {
if (!$this->require_chrome($index)) return;
$this->vars[$name] = $this->chrome->page_html(); return;}
$info = $this->need($target, $index); if ($info === null) return;
$this->vars[$name] = (string)$info['text'];}

function show_step($line, $index) {
$target = trim(substr($line, strpos($line, ' ') + 1));
if ($target === '') {$this->fail($index, 'target missing for ' . $line); return;}
if (strcasecmp($target, 'page') === 0) {
if (!$this->require_chrome($index)) return;
$this->emit($this->chrome->body_text()); return;}
$info = $this->need($target, $index); if ($info === null) return;
$this->emit(trim((string)$info['text']));}

function echo_step($line, $index) {
$params = trim(substr($line, 4));
if (str_starts_with(strtolower($line), 'echo ')) $params = trim(substr($line, 5));
if ($params === '') {$this->fail($index, 'text missing for ' . $line); return;}
if (str_contains($params, "'") && str_contains($params, '"')) {
$this->fail($index, 'inconsistent quotes in ' . $params); return;}
$expr = $this->add_concat($params);
try {$value = $this->expr($expr);}
catch (TaguiExprError $e) {$this->fail($index, $e->getMessage()); return;}
$this->emit($this->stringish($value));}

function snap_step($line, $index) {
$params = '';
if (str_contains($line, ' ')) $params = trim(substr($line, strpos($line, ' ') + 1));
$target = 'page'; $file = '';
$split = stripos($params, ' to ');
if ($params === '' || strcasecmp($params, 'page') === 0) {$target = 'page'; $file = $this->next_snap();}
else if ($split === false) {$target = $params; $file = $this->next_snap();}
else {
$target = trim(substr($params, 0, $split));
$file = trim(substr($params, $split + 4));
if ($file === '') $file = $this->next_snap(); else $file = $this->abs_file($file);}
if (!$this->require_chrome($index)) return;
$clip = null;
if (strcasecmp($target, 'page') !== 0) {
$info = $this->need($target, $index); if ($info === null) return;
if ((float)$info['width'] > 0 && (float)$info['height'] > 0) {
$clip = array('x' => (float)$info['left'], 'y' => (float)$info['top'],
'width' => (float)$info['width'], 'height' => (float)$info['height'], 'scale' => 1);}}
try {if (!$this->chrome->screenshot($file, $clip)) $this->fail($index, 'cannot save snapshot ' . $file);}
catch (Exception $e) {$this->fail($index, $e->getMessage());}}

function wait_step($line, $index) {
$params = trim(substr($line, 4));
if ($params === '' || strtolower($params) === 'wait') $params = '5';
if (!is_numeric($params)) {$this->fail($index, 'live mode wait needs a number of seconds'); return;}
$seconds = (float)$params;
if ($seconds < 0) $seconds = 0;
if ($seconds > 120) $seconds = 120;
usleep((int)($seconds * 1000000));}

function dump_step($line, $index) {
$params = trim(substr($line, 4));
$split = stripos($params, ' to ');
if ($params === '' || $split === false) {$this->fail($index, 'variable missing for ' . $line); return;}
$expr = trim(substr($params, 0, $split));
$file = $this->abs_file(trim(substr($params, $split + 4)));
if ($expr === '' || $file === '') {$this->fail($index, 'variable missing for ' . $line); return;}
try {$value = $this->expr($expr);}
catch (TaguiExprError $e) {$this->fail($index, $e->getMessage()); return;}
file_put_contents($file, $this->stringish($value));}

function timeout_step($line, $index) {
$params = trim(substr($line, 8));
if (!is_numeric($params)) {$this->fail($index, 'time in seconds missing for ' . $line); return;}
$this->timeout = (float)$params;
if ($this->chrome !== null) $this->chrome->timeout = $this->timeout;}

function dom_step($line, $index) {
$params = trim(substr($line, 4));
if ($params === '') {$this->fail($index, 'statement missing for ' . $line); return;}
if (!$this->require_chrome($index)) return;
try {$this->vars['dom_result'] = $this->chrome->evaluate($params);}
catch (Exception $e) {$this->fail($index, $e->getMessage());}}

function assign_step($line, $index) {
if (str_starts_with($line, 'var ')) $line = trim(substr($line, 4));
$eq = strpos($line, '=');
$name = trim(substr($line, 0, $eq));
$expr = trim(substr($line, $eq + 1));
if (str_ends_with($expr, ';')) $expr = trim(substr($expr, 0, -1));
if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {$this->fail($index, 'cannot understand step ' . $line); return;}
try {$this->vars[$name] = $this->expr($expr);}
catch (TaguiExprError $e) {$this->fail($index, $e->getMessage());}}

function calls() {
$engine = $this;
return array(
'title' => function ($args) use ($engine) {return $engine->chrome ? $engine->chrome->title() : '';},
'url' => function ($args) use ($engine) {return $engine->chrome ? $engine->chrome->url() : 'about:blank';},
'text' => function ($args) use ($engine) {return $engine->chrome ? $engine->chrome->body_text() : '';},
'exist' => function ($args) use ($engine) {return $engine->exists_arg($args);},
'present' => function ($args) use ($engine) {return $engine->exists_arg($args);},
'visible' => function ($args) use ($engine) {return $engine->visible_arg($args);},
'count' => function ($args) use ($engine) {return $engine->count_arg($args);});}

function exists_arg($args) {
if (!isset($args[0]) || $this->chrome === null) return false;
$info = $this->chrome->find((string)$args[0], true);
return $info !== null;}
function visible_arg($args) {
if (!isset($args[0]) || $this->chrome === null) return false;
$info = $this->chrome->find((string)$args[0], true);
return $info !== null && !empty($info['visible']);}
function count_arg($args) {
if (!isset($args[0]) || $this->chrome === null) return 0;
$info = $this->chrome->locate((string)$args[0]);
if ($info === null) return 0;
return (int)$info['count'];}

function expr($source) {
$parser = new TaguiExpr($this->vars, $this->calls());
return $parser->evaluate($source);}

function truthy_expr($source, $index) {
try {$parser = new TaguiExpr($this->vars, $this->calls()); return $parser->truthy($parser->evaluate($source));}
catch (TaguiExprError $e) {$this->fail($index, $e->getMessage()); return false;}}

function condition_expression($line) {
$trim = trim($line);
$lower = strtolower($trim);
if (str_starts_with($lower, 'else if ')) $body = trim(substr($trim, 8));
else if (str_starts_with($lower, 'if ')) $body = trim(substr($trim, 3));
else $body = $trim;
return $this->rewrite_condition($body);}

function rewrite_condition($logic) {
$logic = $this->rewrite_contains($logic);
$map = array(
' more than or equals to ' => ' >= ', ' greater than or equals to ' => ' >= ', ' higher than or equals to ' => ' >= ',
' less than or equals to ' => ' <= ', ' lesser than or equals to ' => ' <= ', ' lower than or equals to ' => ' <= ',
' more than or equal to ' => ' >= ', ' greater than or equal to ' => ' >= ', ' higher than or equal to ' => ' >= ',
' less than or equal to ' => ' <= ', ' lesser than or equal to ' => ' <= ', ' lower than or equal to ' => ' <= ',
' more than ' => ' > ', ' greater than ' => ' > ', ' higher than ' => ' > ',
' less than ' => ' < ', ' lesser than ' => ' < ', ' lower than ' => ' < ',
' not equals to ' => ' != ', ' not equal to ' => ' != ', ' equals to ' => ' == ', ' equal to ' => ' == ');
foreach ($map as $from => $to) $logic = str_replace($from, $to, $logic);
$logic = str_replace(' and ', ' && ', $logic);
$logic = str_replace(' or ', ' || ', $logic);
return $logic;}

function rewrite_contains($logic) {
$kinds = array(' not contains ' => '<0', ' not contain ' => '<0', ' contains ' => '>-1', ' contain ' => '>-1');
foreach ($kinds as $keyword => $op) {
while (($pos = strpos($logic, $keyword)) !== false) {
$after = substr($logic, $pos + strlen($keyword));
if (!preg_match('/^\s*([\'"])(.*?)\\1/', $after, $match)) break;
$quoted = $match[1] . $match[2] . $match[1];
$before = rtrim(substr($logic, 0, $pos));
if (!preg_match('/([A-Za-z_][A-Za-z0-9_]*\(\)|[A-Za-z_][A-Za-z0-9_]*)$/', $before, $var)) break;
$var_pos = strrpos($before, $var[1]);
$consumed = $pos + strlen($keyword) + strlen($match[0]);
$logic = substr($logic, 0, $var_pos) . '(' . $var[1] . '.indexOf(' . $quoted . ')' . $op . ')' . substr($logic, $consumed);}
}
return $logic;}

function parse_for($header) {
if (preg_match('/^for\s+([A-Za-z_][A-Za-z0-9_]*)\s+from\s+(-?\d+|infinity)\s+to\s+(-?\d+|infinity)\s*$/i', $header, $m)) {
$from = strtolower($m[2]) === 'infinity' ? 1024 : (int)$m[2];
$to = strtolower($m[3]) === 'infinity' ? 1024 : (int)$m[3];
return array('var' => $m[1], 'from' => $from, 'to' => $to);}
if (preg_match('/^for\s*\(\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(-?\d+)\s*;\s*\1\s*<=\s*(-?\d+)\s*;\s*\1\s*\+\+\s*\)\s*$/', $header, $m)) {
return array('var' => $m[1], 'from' => (int)$m[2], 'to' => (int)$m[3]);}
return null;}

function is_assignment($line) {
if (str_starts_with($line, 'var ')) $line = trim(substr($line, 4));
return (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_]*\s*=\s*.+$/', $line);}

function add_concat($source) {
$quote = null;
if (str_contains($source, "'")) $quote = "'";
else if (str_contains($source, '"')) $quote = '"';
$within = false; $out = '';
$length = strlen($source);
for ($i = 0; $i < $length; $i++) {
$ch = $source[$i];
if ($quote !== null && $ch === $quote) $within = !$within;
if (!$within && $ch === ' ') $out .= '+'; else $out .= $ch;}
return preg_replace('/\+{2,}/', '+', $out);}

function expand_backticks($line) {
$engine = $this;
$expanded = preg_replace_callback('/`([A-Za-z_][A-Za-z0-9_]*)`/', function ($match) use ($engine) {
$name = $match[1];
if (!array_key_exists($name, $engine->vars)) return $match[0];
return $engine->stringish($engine->vars[$name]);}, $line);
return $expanded === null ? $line : $expanded;}

function need($locator, $index) {
if (!$this->require_chrome($index)) return null;
$info = $this->chrome->find($locator, $this->live);
if ($info === null) {$this->fail($index, 'cannot find ' . $locator); return null;}
return $info;}

function require_chrome($index) {
if ($this->chrome !== null) return true;
$this->fail($index, 'browser is not open'); return false;}

function fail($index, $message) {
$prefix = 'ERROR - ';
if ($index >= 0 && !$this->live) $prefix .= '[LINE ' . ($index + 1) . '] ';
$this->emit($prefix . $message);
if (!$this->live) $this->stopped = true;}

function trace($line) {if (!$this->quiet && !$this->live) $this->emit($line);}

function emit($text) {
if ($this->capture) {$this->captured[] = (string)$text; return;}
echo $text, "\n"; fflush(STDOUT);}

function emit_raw($text) {
if ($this->capture) {$this->captured[] = (string)$text; return;}
echo $text, "\n"; fflush(STDOUT);}

function stringish($value) {
if ($value === true) return 'true'; if ($value === false) return 'false'; if ($value === null) return '';
if (is_float($value)) {if ((float)(int)$value === $value) return (string)(int)$value;}
return (string)$value;}

function next_snap() {$this->snap_count++; return $this->flow_path . DIRECTORY_SEPARATOR . 'snap' . $this->snap_count . '.png';}

function abs_file($filename) {
if ($filename === '') return '';
if ($filename[0] === '/' || $filename[0] === '\\') return $filename;
if (strlen($filename) > 2 && $filename[1] === ':') return $filename;
return $this->flow_path . DIRECTORY_SEPARATOR . $filename;}

function next_code($lines, $i, $end) {
while ($i <= $end) {
$trimmed = trim($lines[$i]);
if ($trimmed !== '' && !str_starts_with($trimmed, '//')) return $i;
$i++;}
return null;}

function find_block_end($lines, $open, $end) {
$depth = 0;
for ($j = $open; $j <= $end; $j++) {
$trimmed = trim($lines[$j]);
if ($trimmed === '{') $depth++;
else if ($trimmed === '}') {$depth--; if ($depth === 0) return $j;}}
return -1;}}

/* -------------------------------------------------------------------------- */
/* Entry point                                                                */
/* -------------------------------------------------------------------------- */

function tagui_modern_main($argv) {
ini_set('display_errors', 'stderr');
while (ob_get_level() > 0) {ob_end_flush();}
ob_implicit_flush(true);
if (!isset($argv[1]) || $argv[1] === '') {fwrite(STDERR, "ERROR - specify flow filename as first parameter\n"); return 1;}
$flow = $argv[1];
if (!is_file($flow)) {fwrite(STDERR, "ERROR - cannot find $flow\n"); return 1;}
$browser = getenv('tagui_web_browser');
if ($browser !== 'chrome' && $browser !== 'headless') $browser = 'headless';
$quiet = getenv('tagui_quiet_mode') === 'true';
$chrome = new TaguiChrome();
$engine = new TaguiModern($chrome, $quiet, dirname($flow));
if (isset($argv[2])) $engine->set_cli_params(array_slice($argv, 2));
$chrome_started = false;
pcntl_async_signals(true);
$stop = function () use ($chrome, &$chrome_started) {
if ($chrome_started) {$chrome->stop(); $chrome_started = false;}};
pcntl_signal(SIGTERM, function () use ($stop) {$stop(); exit(0);});
pcntl_signal(SIGINT, function () use ($stop) {$stop(); exit(130);});
register_shutdown_function($stop);
try {
$chrome->start($browser !== 'chrome');
$chrome->timeout = $engine->timeout;
$chrome_started = true;
$lines = file($flow, FILE_IGNORE_NEW_LINES);
if ($lines === false) $lines = array();
$engine->run_lines($lines);}
catch (Exception $e) {
echo 'ERROR - ', $e->getMessage(), "\n";
$stop();
return 1;}
$stop();
return 0;}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
exit(tagui_modern_main($argv));}
