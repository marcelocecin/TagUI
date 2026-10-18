<?php

/* Logic tests for the modern engine. These do not open a browser. */

require_once dirname(__DIR__) . '/tagui_modern.php';

$failures = 0;

function expect($label, $actual, $expected) {
global $failures;
if ($actual !== $expected) {
$failures++;
fwrite(STDERR, "FAIL $label\n  actual:   " . var_export($actual, true) . "\n  expected: " . var_export($expected, true) . "\n");
} else {
fwrite(STDOUT, "ok   $label\n");}}

$engine = new TaguiModern(null, true, '/tmp');
$engine->capture = true;

expect('contains', $engine->rewrite_condition('menu contains "fruits"'), '(menu.indexOf("fruits")>-1)');
expect('not contains and', $engine->rewrite_condition('menu contains "fruits" and menu not contains "meat"'),
'(menu.indexOf("fruits")>-1) && (menu.indexOf("meat")<0)');
expect('equals', $engine->rewrite_condition('cupcakes equals to 12'), 'cupcakes == 12');
expect('greater or equal', $engine->rewrite_condition('cupcakes greater than or equals to 12'), 'cupcakes >= 12');
expect('js form kept', $engine->condition_expression('if (menu.indexOf("fruits")>-1)'), '(menu.indexOf("fruits")>-1)');
expect('title contains', $engine->rewrite_condition('title() contains "Modern"'), '(title().indexOf("Modern")>-1)');

$for = $engine->parse_for('for n from 1 to 3');
expect('for from', $for, array('var' => 'n', 'from' => 1, 'to' => 3));
$js = $engine->parse_for('for (n=1; n<=3; n++)');
expect('for js', $js, array('var' => 'n', 'from' => 1, 'to' => 3));
expect('for bad', $engine->parse_for('for n to 3'), null);

$lines = array(
'menu = "salads fruits sandwiches"',
'cupcakes = 12',
'if menu contains "fruits"',
'echo "fruits-yes"',
'if menu contains "meat"',
'echo "meat-yes"',
'if cupcakes greater than or equals to 12',
'echo "dozen"',
'if cupcakes equals to 1',
'echo "nope"',
'else',
'echo "else-yes"',
'for n from 1 to 3',
'{',
'echo "n is " n',
'}',
'for (k=1; k<=2; k++)',
'echo "k" k');
$engine->execute_range($lines, 0, count($lines) - 1, true);
expect('captured', $engine->captured, array(
'fruits-yes', 'dozen', 'else-yes', 'n is 1', 'n is 2', 'n is 3', 'k1', 'k2'));
expect('menu var', $engine->vars['menu'], 'salads fruits sandwiches');
expect('stopped', $engine->stopped, false);

$bad = new TaguiModern(null, true, '/tmp');
$bad->capture = true;
$bad->execute_range(array('echo "unterminated'), 0, 0, true);
expect('bad echo stops', $bad->stopped, true);
expect('bad echo message', str_contains($bad->captured[0], 'ERROR -'), true);

expect('intent click', tagui_keyword_intent('click Go'), 'tap');
expect('intent choose', tagui_keyword_intent('choose color as Blue'), 'select');
expect('intent download', tagui_keyword_intent('download https://example.com to out.html'), 'down');
expect('intent file', tagui_keyword_intent('file:///tmp/page.html'), 'url');
expect('intent read before r', tagui_keyword_intent('read page to html'), 'read');
expect('code while', tagui_is_code('while n less than 3'), true);
expect('not code echo', tagui_is_code('echo "hi"'), false);
$generated = tagui_steps_javascript();
expect('js has click alias', str_contains($generated, 'click '), true);
expect('js has aria-label', str_contains($generated, 'aria-label'), true);
$header = file_get_contents(dirname(__DIR__) . '/tagui_header.js');
expect('header keeps tebelorg tx', str_contains($header, "if (casper.exists(xps666('//*[@id=\"'+locator+'\"]'))) return xps666('//*[@id=\"'+locator+'\"]');"), true);
expect('header is not the shared block', str_contains($header, 'generated from tagui_steps.php'), false);

$loop = new TaguiModern(null, true, '/tmp');
$loop->capture = true;
$loop->execute_range(array(
'n = 0',
'while n less than 3',
'{',
'n = n + 1',
'echo "w" n',
'if n equals to 2',
'break',
'echo "after"',
'}'), 0, 8, true);
expect('while break', $loop->captured, array('w1', 'after', 'w2'));
expect('while var', $loop->vars['n'], 2);

$csv = tempnam(sys_get_temp_dir(), 'tagui_csv_');
unlink($csv);
$csv .= '.csv';
file_put_contents($csv, "#,city\n1,Lisbon\n2,Kyoto\n");
$rows = tagui_datatable_rows('/tmp/no-such-flow', $csv);
expect('datatable count', count($rows), 2);
expect('datatable city', $rows[0]['city'], 'Lisbon');
expect('datatable iteration', $rows[1]['[iteration]'], '2');
$table = new TaguiModern(null, true, '/tmp');
$table->capture = true;
$table->repo = $rows[0];
$table->execute_range(array('echo "city `city` row `[iteration]`"'), 0, 0, true);
expect('datatable echo', $table->captured, array('city Lisbon row 1'));
unlink($csv);

$load_dir = sys_get_temp_dir() . '/tagui_load_' . getmypid();
mkdir($load_dir);
file_put_contents($load_dir . '/note.txt', "saved-note\n");
$files = new TaguiModern(null, true, $load_dir);
$files->capture = true;
$files->execute_range(array(
'load note.txt to note',
'echo note',
'write note to copy.txt',
'load copy.txt to again',
'echo again'), 0, 4, true);
expect('load write', $files->captured, array('saved-note', 'saved-note'));
expect('check step', true, true);
$files->captured = array();
$files->stopped = false;
$files->execute_range(array('check note equals to "saved-note" | "yes" | "no"'), 0, 0, true);
expect('check true', $files->captured, array('yes'));

class TaguiTitleStub {
function title() {return 'Example Domain';}
function url() {return 'https://example.com/';}}
$ticks = new TaguiModern(new TaguiTitleStub(), true, '/tmp');
$ticks->capture = true;
$ticks->vars['name'] = 'hello world';
$ticks->execute_range(array(
'echo `name`',
'echo `title()`',
'echo \'backtick-\' `title()`',
'page_title = `title()`',
'echo page_title',
'n = 2',
'if `n` equals to 2',
'echo "n-yes"'), 0, 7, true);
expect('backtick echo', $ticks->captured, array('hello world', 'Example Domain', 'backtick-Example Domain', 'Example Domain', 'n-yes'));
expect('backtick assign', $ticks->vars['page_title'], 'Example Domain');
$ticks->vars['name'] = 'Go button';
expect('click backtick', $ticks->expand_backticks('click `name`'), 'click Go button');
expect('type backtick', $ticks->expand_backticks('type `name` as `page_title`'), 'type Go button as Example Domain');

$ws_warnings = array();
set_error_handler(function ($severity, $message) use (&$ws_warnings) {
if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {$ws_warnings[] = $message; return true;}
return false;});
new WebSocket\Client('ws://127.0.0.1:9', array('timeout' => 1));
class TaguiWsProbe extends WebSocket\Base {
function __construct() {
$this->options = array('timeout' => 1, 'fragment_size' => 4096);
$this->socket = fopen('php://memory', 'r+');
fwrite($this->socket, chr(0x81) . chr(2) . 'hi');
rewind($this->socket);
$this->is_connected = true;}
function pull() {return $this->receive_fragment();}}
$probe = new TaguiWsProbe();
expect('ws frame', $probe->pull(), 'hi');
restore_error_handler();
expect('ws deprecations', $ws_warnings, array());
expect('ws options declared', property_exists('WebSocket\\Base', 'options'), true);

$js = new TaguiModern(null, true, '/tmp');
$js->capture = true;
$js->execute_range(array(
'profile_name = "Tan Kay Chen"',
'profile_name = profile_name.replace(/ /g,"_")',
'echo profile_name',
'js begin',
'profile_name = profile_name.replace(/_/g, "-")',
'js finish',
'echo profile_name'), 0, 6, true);
expect('js replace', $js->captured, array('Tan_Kay_Chen', 'Tan-Kay-Chen'));
expect('js stopped', $js->stopped, false);
TaguiHelpers::shutdown();

$py = new TaguiModern(null, true, '/tmp');
$py->capture = true;
$py->execute_range(array(
'py a = 40',
'py print(a + 2)',
'echo py_result',
'py begin',
'print(a * 6)',
'py finish',
'echo py_result'), 0, 6, true);
expect('py result', $py->captured, array('42', '240'));
expect('py json', $py->vars['py_json'], 240);
expect('py stopped', $py->stopped, false);
TaguiHelpers::shutdown();

$pybad = new TaguiModern(null, true, '/tmp');
$pybad->capture = true;
$pybad->execute_range(array('py print(no_such_name)', 'echo py_result'), 0, 1, true);
expect('py error continues', $pybad->stopped, false);
expect('py error text', str_contains($pybad->captured[0], 'ERROR -'), true);
TaguiHelpers::shutdown();

$r = new TaguiModern(null, true, '/tmp');
$r->capture = true;
putenv('TAGUI_RSCRIPT=');
$r->execute_range(array('r print(1)'), 0, 0, true);
expect('r missing', $r->stopped, true);
expect('r message', str_contains($r->captured[0], 'Rscript'), true);
TaguiHelpers::shutdown();

$zh = tagui_translate_lines(array('输入 search-box 为 github', '如果 cupcakes 多过或等于 12'), 'chinese');
expect('zh type', $zh[0], 'type search-box as github');
expect('zh if', $zh[1], 'if cupcakes more than or equals to 12');
$zh_engine = new TaguiModern(null, true, '/tmp');
$zh_engine->capture = true;
$spoken = tagui_translate_lines(array('回声 "nihao"'), 'chinese');
$zh_engine->execute_range($spoken, 0, 0, true);
expect('zh echo', $zh_engine->captured, array('nihao'));

$visual = new TaguiModern(null, true, '/tmp');
$visual->capture = true;
putenv('TAGUI_SIKULI_JAR=/tmp/tagui-missing-oculix.jar');
$visual->execute_range(array('vision print(1)'), 0, 0, true);
expect('sikuli missing', $visual->stopped, true);
expect('sikuli message', str_contains($visual->captured[0], 'OculiX'), true);
expect('sikuli no snap word', str_contains($visual->captured[0], 'cannot find '), false);
putenv('TAGUI_SIKULI_JAR');

$traced = new TaguiModern(null, false, '/tmp');
$traced->capture = true;
putenv('TAGUI_SIKULI_JAR=/tmp/tagui-missing-oculix.jar');
$traced->execute_range(array('read btn.png to seen'), 0, 0, true);
$traced_lines = array();
foreach ($traced->captured as $row) {if ($row === 'read btn.png to seen') $traced_lines[] = $row;}
expect('read traced once', count($traced_lines), 1);
putenv('TAGUI_SIKULI_JAR');

$chrome_root = sys_get_temp_dir() . '/tagui_chromium_' . getmypid();
mkdir($chrome_root . '/bin', 0700, true);
mkdir($chrome_root . '/lib/chromium', 0700, true);
$wrapper = $chrome_root . '/bin/chromium';
$elf = $chrome_root . '/lib/chromium/chromium';
file_put_contents($wrapper, "#!/bin/sh\nexec true\n");
file_put_contents($elf, "\x7fELF");
chmod($wrapper, 0755);
chmod($elf, 0755);
$chrome = $chrome_root . '/bin/google-chrome';
file_put_contents($chrome, "#!/bin/sh\nexec true\n");
chmod($chrome, 0755);
expect('chromium wrapper', tagui_unwrap_chromium($wrapper), realpath($elf));
expect('chromium elf', tagui_unwrap_chromium($elf), $elf);
expect('chrome left alone', tagui_unwrap_chromium($chrome), $chrome);
unlink($wrapper);
unlink($elf);
unlink($chrome);
rmdir($chrome_root . '/bin');
rmdir($chrome_root . '/lib/chromium');
rmdir($chrome_root . '/lib');
rmdir($chrome_root);

$real_dir = sys_get_temp_dir() . '/tagui_real_' . getmypid();
mkdir($real_dir);
file_put_contents($real_dir . '/page.html', 'x');
$link = sys_get_temp_dir() . '/tagui_link_' . getmypid();
symlink($real_dir, $link);
$resolved = tagui_file_url('file://' . $link . '/page.html');
expect('file symlink', str_contains($resolved, basename(realpath($real_dir))), true);
expect('file link gone', str_contains($resolved, basename($link)), false);
expect('https untouched', tagui_file_url('https://example.com/a'), 'https://example.com/a');
unlink($real_dir . '/page.html');
unlink($link);
rmdir($real_dir);

$blocked = tempnam(sys_get_temp_dir(), 'tagui_oculix_');
file_put_contents($blocked, "INFO: starting\nFATAL: Mouse.init: Mouse not useable (blocked)\n");
$blocked_message = TaguiSikuli::startup_failure($blocked);
expect('mouse hint access', str_contains($blocked_message, 'Accessibility'), true);
expect('mouse hint screen', str_contains($blocked_message, 'Screen Recording'), true);
unlink($blocked);
expect('miss score', TaguiSikuli::failure_detail('ERROR not on screen, best similarity 0.41'), 'Image not on screen, best similarity 0.41.');
expect('miss plain', TaguiSikuli::failure_detail('ERROR'), 'The target was not found on screen.');

$headless_log = tempnam(sys_get_temp_dir(), 'tagui_oculix_');
file_put_contents($headless_log, "Exception in thread \"main\" java.awt.HeadlessException: running in headless environment\n\tat java.desktop/java.awt.GraphicsEnvironment.checkHeadless(GraphicsEnvironment.java:166)\n\t... 5 more\n");
$headless_message = TaguiSikuli::startup_failure($headless_log);
expect('headless cause', str_contains($headless_message, 'HeadlessException'), true);
expect('headless not tail', str_contains($headless_message, '... 5 more'), false);
expect('headless jre', str_contains($headless_message, 'full JRE'), true);
expect('headless display', str_contains($headless_message, 'DISPLAY'), true);
unlink($headless_log);

$crash_log = tempnam(sys_get_temp_dir(), 'tagui_oculix_');
file_put_contents($crash_log, "[error] TextRecognizer failed: UnsatisfiedLinkError leptonica\nCaused by: java.lang.UnsatisfiedLinkError: leptonica\n\t... 5 more\n");
$crash_message = TaguiSikuli::step_crash($crash_log);
expect('crash error', str_contains($crash_message, '[error] TextRecognizer failed: UnsatisfiedLinkError leptonica'), true);
expect('crash cause', str_contains($crash_message, 'Caused by: java.lang.UnsatisfiedLinkError: leptonica'), true);
expect('crash not tail', str_contains($crash_message, '... 5 more'), false);
expect('crash during', str_contains($crash_message, 'exited during'), true);
unlink($crash_log);

$saved_tz = getenv('TZ');
putenv('TZ=Pacific/Auckland');
$stamp = tagui_local_stamp();
$zone_now = new DateTimeImmutable('now', new DateTimeZone('Pacific/Auckland'));
$parsed = DateTimeImmutable::createFromFormat('D M j Y H:i:s', $stamp, new DateTimeZone('Pacific/Auckland'));
expect('local stamp', $parsed instanceof DateTimeImmutable && abs($parsed->getTimestamp() - $zone_now->getTimestamp()) <= 2, true);
if ($saved_tz === false) putenv('TZ'); else putenv('TZ=' . $saved_tz);

$dir = sys_get_temp_dir() . '/tagui_flow_' . getmypid();
mkdir($dir);
mkdir($dir . '/sub');
file_put_contents($dir . '/child.tag', "echo n\nn = n + 1\ntagui sub/leaf.tag\n");
file_put_contents($dir . '/sub/leaf.tag', "echo \"leaf\"\n");
$inc = new TaguiModern(null, true, $dir);
$inc->capture = true;
$inc->execute_range(array('n = 4', 'tagui child.tag', 'echo n', 'tagui missing.tag', 'echo "nope"'), 0, 4, true);
expect('include echoes', array_slice($inc->captured, 0, 3), array('4', 'leaf', '5'));
expect('include missing', $inc->stopped, true);
expect('include message', str_contains($inc->captured[3], 'file not found'), true);
expect('include stopped', in_array('nope', $inc->captured, true), false);

$block = new TaguiModern(null, true, '/tmp');
$block->capture = true;
$block->execute_range(array(
'n = 0',
'if n equals to 0',
'    echo "a"',
'    if n equals to 0',
'        echo "b"',
'        echo "c"',
'    else',
'        echo "no"',
'    echo "d"',
'else',
'    echo "nope"',
'echo "e"',
'counti = 2',
'for i from 0 to counti',
'    echo i'), 0, 14, true);
expect('indent block', $block->captured, array('a', 'b', 'c', 'd', 'e', '0', '1', '2'));
expect('indent stopped', $block->stopped, false);
$varfor = $block->parse_for('for i from 0 to counti');
expect('for variable bound', $varfor, array('var' => 'i', 'from' => 0, 'to' => 'counti'));

$words = new TaguiModern(null, true, '/tmp');
$words->capture = true;
$words->execute_range(array(
'echo Retorno anexado no ClickUp',
'timeout 60  // keep the session',
'wait //*[@id="podio"]',
'echo "after-wait"'), 0, 3, true);
expect('echo literal', $words->captured[0], 'Retorno anexado no ClickUp');
expect('timeout comment', $words->timeout, 60.0);
expect('wait xpath', $words->stopped, false);
expect('after wait', $words->captured[1], 'after-wait');

$box = $dir . '/outbox';
$dl = new TaguiModern(null, true, $dir);
$dl->capture = true;
$dl->execute_range(array('download to outbox'), 0, 0, true);
expect('download dir', $dl->download_dir, $box);
expect('download folder', is_dir($box), true);
expect('download no url', $dl->stopped, false);

$repo = new TaguiModern(null, true, '/tmp');
$repo->capture = true;
$repo->repo = array('usuario_erp' => base64_encode('alice'), 'create account' => 'btn-green');
$repo->sync_repo_vars();
$repo->execute_range(array('js user = atob(usuario_erp)', 'echo user', 'echo create_account'), 0, 2, true);
expect('repo js', $repo->captured, array('alice', 'btn-green'));
expect('repo stopped', $repo->stopped, false);
expect('repo name', tagui_repo_identifier('create account'), 'create_account');
expect('repo iteration', tagui_repo_identifier('[iteration]'), 'iteration');

$saved_profile = getenv('TAGUI_CHROME_PROFILE');
$saved_isolated = getenv('TAGUI_CHROME_ISOLATED');
putenv('TAGUI_CHROME_PROFILE');
putenv('TAGUI_CHROME_ISOLATED');
$launcher = new TaguiChrome();
$official = $launcher->launch_plan('/usr/bin/google-chrome', 9333, false);
expect('official args', $official['args'], array(
'/usr/bin/google-chrome',
'--remote-debugging-port=9333',
'--user-data-dir=' . dirname(__DIR__) . '/chrome/tagui_user_profile',
'--window-size=1366,842',
'about:blank'));
expect('official persistent', $official['persistent'], true);
$official_line = implode("\n", $official['args']);
expect('official extensions', str_contains($official_line, '--disable-extensions'), false);
expect('official sandbox', str_contains($official_line, '--no-sandbox'), false);
putenv('TAGUI_CHROME_PROFILE=/data/buster-profile');
$custom = $launcher->launch_plan('/usr/bin/google-chrome', 9333, true);
expect('custom profile', $custom['args'][2], '--user-data-dir=/data/buster-profile');
expect('custom headless', in_array('--headless', $custom['args'], true) && in_array('--disable-gpu', $custom['args'], true), true);
expect('custom no sandbox', in_array('--no-sandbox', $custom['args'], true), false);
expect('custom url', $custom['args'][count($custom['args']) - 1], 'about:blank');
putenv('TAGUI_CHROME_PROFILE');
putenv('TAGUI_CHROME_ISOLATED=1');
$isolated = $launcher->launch_plan('/usr/bin/google-chrome', 9333, true);
expect('isolated profile', str_contains($isolated['profile'], 'tagui_modern_profile_'), true);
expect('isolated persistent', $isolated['persistent'], false);
expect('isolated sandbox', in_array('--no-sandbox', $isolated['args'], true), true);
expect('isolated extensions', in_array('--disable-extensions', $isolated['args'], true), true);
putenv('TAGUI_CHROME_ISOLATED');
if (is_string($saved_profile) && $saved_profile !== '') putenv('TAGUI_CHROME_PROFILE=' . $saved_profile);
else putenv('TAGUI_CHROME_PROFILE');
if (is_string($saved_isolated) && $saved_isolated !== '') putenv('TAGUI_CHROME_ISOLATED=' . $saved_isolated);
else putenv('TAGUI_CHROME_ISOLATED');

$typed = tagui_type_events('a[enter][tab][esc][backspace] ');
$typed_view = array();
foreach ($typed as $event) {
$key = $event['key'] === ' ' ? 'Space' : $event['key'];
$text = '';
if (isset($event['text'])) {
$shown = $event['text'];
if ($shown === ' ') $shown = 'Space';
else if ($shown === "\r") $shown = '\\r';
$text = '=' . $shown;}
$typed_view[] = $event['type'] . ' ' . $key . $text;}
expect('key events', $typed_view, array(
'keyDown a=a', 'keyUp a',
'keyDown Enter=\\r', 'keyUp Enter',
'keyDown Tab', 'keyUp Tab',
'keyDown Escape', 'keyUp Escape',
'keyDown Backspace', 'keyUp Backspace',
'keyDown Space=Space', 'keyUp Space'));
expect('enter code', $typed[2]['windowsVirtualKeyCode'], 13);
expect('enter text', $typed[2]['text'], "\r");
expect('enter unmodified', $typed[2]['unmodifiedText'], "\r");
expect('enter keyup text', isset($typed[3]['text']), false);
expect('backspace code', $typed[8]['windowsVirtualKeyCode'], 8);
$named_text = array();
foreach (array('enter', 'tab', 'esc', 'escape', 'backspace', 'bs', 'delete', 'del', 'space', 'up', 'down', 'left', 'right', 'home', 'end', 'pageup', 'pagedown', 'insert') as $name) {
$named = tagui_named_key($name);
$named_text[$name] = $named[3];}
expect('named key text', $named_text, array(
'enter' => "\r",
'tab' => null, 'esc' => null, 'escape' => null,
'backspace' => null, 'bs' => null, 'delete' => null, 'del' => null,
'space' => ' ',
'up' => null, 'down' => null, 'left' => null, 'right' => null,
'home' => null, 'end' => null, 'pageup' => null, 'pagedown' => null, 'insert' => null));
$cleared = tagui_type_events('x', true);
$clear_keys = array();
foreach ($cleared as $event) {
if ($event['type'] === 'keyDown') $clear_keys[] = $event['key'] . (isset($event['text']) ? '=' . $event['text'] : '');}
expect('clear keys', $clear_keys, array('Control', 'a', 'Backspace', 'x=x'));
$clear_mod = (defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Darwin') ? 4 : 2;
expect('clear modifier', $cleared[1]['modifiers'], $clear_mod);

class RecordingChrome extends TaguiChrome {
public $commands = array();
public $clicks = array();
function evaluate($expression) {
$this->commands[] = array('evaluate', $expression);
return array('x' => 8.0, 'y' => 9.0);}
function click_at($x, $y, $button, $clicks, $may_open = false) {$this->clicks[] = array($x, $y, $button, $clicks);}
function command($method, $params, $timeout = 30) {
$this->commands[] = array($method, $params);
return array();}}
$recorder = new RecordingChrome();
$recorder->frame_dx = 1;
$recorder->frame_dy = 2;
expect('type ok', $recorder->type_into('#password', '[clear]ab[enter]'), true);
expect('type click', $recorder->clicks, array(array(9.0, 11.0, 'left', 1)));
$focus_script = $recorder->commands[0][1];
expect('no synthetic input', str_contains($focus_script, 'dispatchEvent') || str_contains($focus_script, 'InputEvent') || str_contains($focus_script, 'el.value ='), false);
$methods = array();
$down_text = array();
foreach ($recorder->commands as $call) {
$methods[] = $call[0];
if ($call[0] === 'Input.dispatchKeyEvent' && $call[1]['type'] === 'keyDown' && isset($call[1]['text'])) $down_text[] = $call[1]['text'];}
expect('no insertText', in_array('Input.insertText', $methods, true), false);
expect('typed chars', $down_text, array('a', 'b', "\r"));

$secret = new TaguiModern(null, false, '/tmp');
$secret->capture = true;
$secret->execute_range(array('type #password as secret-value[enter]'), 0, 0, true);
expect('redacted trace', $secret->captured[0], 'type #password as [redacted]');
expect('secret hidden', str_contains(implode("\n", $secret->captured), 'secret-value'), false);
expect('type closed', $secret->stopped, true);

$skip = new TaguiModern(null, false, '/tmp');
$skip->capture = true;
$skip->vars['menu'] = 'salads';
$skip->execute_range(array(
'if menu contains "meat"',
'echo "no"',
'else if menu contains "fish"',
'echo "nope"',
'else',
'echo "yes"',
'if menu contains "salads"',
'echo "taken"'), 0, 7, true);
expect('skip false branch', $skip->captured, array(
'SKIP - if menu contains "meat"',
'SKIP - else if menu contains "fish"',
'yes',
'taken'));
expect('skip not failure', $skip->stopped, false);

$vis = new TaguiModern(null, true, '/flows/podio');
expect('visual abs', $vis->visual_command('click visao/login.png'), 'click /flows/podio/visao/login.png');
expect('type image keeps text', $vis->visual_command('type visao/box.png as report.png'), 'type /flows/podio/visao/box.png as report.png');
expect('visual coords', $vis->visual_command('click (12,34)'), 'click (12,34)');
expect('visual kept', $vis->visual_command('rclick /abs/mark.png'), 'rclick /abs/mark.png');
expect('chrome page snap', $vis->is_page_snap('page'), true);
expect('page.png not chrome', $vis->is_page_snap('page.png'), false);
expect('desktop page.png', $vis->is_desktop_snap('page.png'), true);
expect('desktop page.bmp', $vis->is_desktop_snap('page.bmp'), true);
expect('desktop nested', $vis->is_desktop_snap('visao/page.png'), true);
expect('page not desktop', $vis->is_desktop_snap('page'), false);

class CountingSikuli extends TaguiModern {
public $image_calls = 0;
public $image_command = '';
function image_present($command) {
$this->image_calls++;
$this->image_command = $command;
return $this->image_calls >= 3;}}
$poll = new CountingSikuli(null, true, '/flows/podio');
$poll->timeout = 5;
expect('exist polls', $poll->exists_arg(array('visao/login.png')), true);
expect('exist calls', $poll->image_calls, 3);
expect('exist abs', $poll->image_command, 'present /flows/podio/visao/login.png');
class MissingImage extends TaguiModern {
public $image_calls = 0;
function image_present($command) {$this->image_calls++; return false;}}
$miss = new MissingImage(null, true, '/flows/podio');
$miss->timeout = 0.35;
$miss_started = microtime(true);
expect('exist timeout', $miss->exists_arg(array('visao/nope.png')), false);
expect('exist waited', $miss->image_calls >= 2 && (microtime(true) - $miss_started) < 2, true);
class OnceImage extends TaguiModern {
public $image_calls = 0;
public $image_command = '';
function image_present($command) {
$this->image_calls++;
$this->image_command = $command;
return false;}}
$once = new OnceImage(null, true, '/flows/podio');
$once->timeout = 30;
$once_started = microtime(true);
expect('present once', $once->present_arg(array('visao/licenca2.png')), false);
expect('present calls', $once->image_calls, 1);
expect('present fast', (microtime(true) - $once_started) < 1, true);
expect('present command', $once->image_command, 'present /flows/podio/visao/licenca2.png');
$seen_once = new OnceImage(null, true, '/flows/podio');
$seen_once->timeout = 30;
expect('visible once', $seen_once->visible_arg(array('visao/licenca2.png')), false);
expect('visible calls', $seen_once->image_calls, 1);
expect('visible command', $seen_once->image_command, 'visible /flows/podio/visao/licenca2.png');
class CountingFind {
public $calls = 0;
public $lives = array();
function find($locator, $live) {$this->calls++; $this->lives[] = $live; return null;}}
$finder = new CountingFind();
$selector = new TaguiModern($finder, true, '/tmp');
$selector->timeout = 5;
$selector_started = microtime(true);
expect('selector present', $selector->present_arg(array('#login')), false);
expect('selector visible', $selector->visible_arg(array('#gone')), false);
expect('selector exist', $selector->exists_arg(array('#login')), false);
expect('selector calls', $finder->calls, 3);
expect('selector one shot', $finder->lives, array(true, true, true));
expect('selector fast', (microtime(true) - $selector_started) < 1, true);

putenv('TAGUI_SIKULI_JAR=/tmp/tagui-missing-oculix.jar');
$page_snap = new TaguiModern(null, true, '/tmp');
$page_snap->capture = true;
$page_snap->execute_range(array('snap page.png to shot.png'), 0, 0, true);
expect('page.png sikuli', $page_snap->stopped && str_contains($page_snap->captured[0], 'OculiX'), true);
expect('page.png not chrome', str_contains($page_snap->captured[0], 'browser is not open'), false);
$chrome_snap = new TaguiModern(null, true, '/tmp');
$chrome_snap->capture = true;
$chrome_snap->execute_range(array('snap page to shot.png'), 0, 0, true);
expect('snap page chrome', $chrome_snap->stopped && str_contains($chrome_snap->captured[0], 'browser is not open'), true);
expect('snap page not sikuli', str_contains($chrome_snap->captured[0], 'OculiX'), false);

class ReadyChrome {
function find($locator, $live) {return array('found' => true, 'visible' => true);}}
class MissingChrome {
function find($locator, $live) {return null;}}
$ready = new TaguiModern(new ReadyChrome(), true, '/tmp');
$ready->capture = true;
$ready->execute_range(array('expect #login', 'echo "in"'), 0, 1, true);
expect('expect found', $ready->captured, array('in'));
expect('expect found continues', $ready->stopped, false);
$missing = new TaguiModern(new MissingChrome(), true, '/tmp');
$missing->capture = true;
$missing->execute_range(array('expect #login', 'echo "no"'), 0, 1, true);
expect('expect missing', $missing->stopped && str_contains($missing->captured[0], 'required check failed'), true);
expect('expect stops', in_array('no', $missing->captured, true), false);
$wait_time = new TaguiModern(new MissingChrome(), true, '/tmp');
$wait_time->capture = true;
$wait_time->execute_range(array('wait //*[@id="podio"]', 'echo "after-wait"'), 0, 1, true);
expect('wait xpath still zero', $wait_time->stopped, false);
expect('wait xpath continues', $wait_time->captured, array('after-wait'));
$wait_for = new TaguiModern(new MissingChrome(), true, '/tmp');
$wait_for->capture = true;
$wait_for->timeout = 0.2;
$wait_for->execute_range(array('wait for #login', 'echo "late"'), 0, 1, true);
expect('wait for missing', $wait_for->stopped && str_contains($wait_for->captured[0], 'cannot find #login'), true);
expect('wait for stops', in_array('late', $wait_for->captured, true), false);
$wait_ok = new TaguiModern(new ReadyChrome(), true, '/tmp');
$wait_ok->capture = true;
$wait_ok->execute_range(array('wait element #login', 'echo "seen"'), 0, 1, true);
expect('wait element found', $wait_ok->captured, array('seen'));
putenv('TAGUI_MODERN_WAIT_ELEMENT=1');
$wait_env = new TaguiModern(new MissingChrome(), true, '/tmp');
$wait_env->capture = true;
$wait_env->timeout = 0.2;
$wait_env->execute_range(array('wait //*[@id="podio"]', 'echo "nope"'), 0, 1, true);
expect('wait env element', $wait_env->stopped && str_contains($wait_env->captured[0], 'cannot find //*[@id="podio"]'), true);
$wait_number = new TaguiModern(new MissingChrome(), true, '/tmp');
$wait_number->capture = true;
$wait_number->execute_range(array('wait 0', 'echo "timed"'), 0, 1, true);
expect('wait number stays time', $wait_number->stopped, false);
expect('wait number echo', $wait_number->captured, array('timed'));
putenv('TAGUI_MODERN_WAIT_ELEMENT');
putenv('TAGUI_MODERN_STRICT=1');
$strict = new TaguiModern(new MissingChrome(), true, '/tmp');
$strict->capture = true;
$strict->execute_range(array('if exist("#login")', 'echo "inside"', 'echo "after-strict"'), 0, 2, true);
expect('strict exist fails', $strict->stopped && str_contains(implode("\n", $strict->captured), 'required check failed'), true);
expect('strict stops flow', in_array('after-strict', $strict->captured, true), false);
$strict_not = new TaguiModern(new ReadyChrome(), true, '/tmp');
$strict_not->capture = true;
$strict_not->execute_range(array('if !exist("#login")', 'echo "inside"', 'echo "after-not"'), 0, 2, true);
expect('strict ignores negated exist', $strict_not->stopped, false);
expect('strict negated skip', in_array('after-not', $strict_not->captured, true), true);
expect('strict negated body', in_array('inside', $strict_not->captured, true), false);
putenv('TAGUI_MODERN_STRICT');
$loose = new TaguiModern(new MissingChrome(), true, '/tmp');
$loose->capture = true;
$loose->quiet = false;
$loose->execute_range(array('if exist("#login")', 'echo "inside"', 'echo "after-loose"'), 0, 2, true);
expect('loose exist continues', $loose->stopped, false);
expect('loose skip', $loose->captured[0], 'SKIP - if exist("#login")');
expect('loose after', in_array('after-loose', $loose->captured, true), true);
$summary = new TaguiModern(null, true, '/tmp');
$summary->capture = true;
$summary->vars['menu'] = 'salads';
$summary->run_lines(array('echo "ran"', 'if menu contains "meat"', 'echo "no"'));
expect('summary counts', array($summary->steps_ran, $summary->steps_skipped, $summary->blocks_skipped), array(2, 1, 1));
$summary_text = implode("\n", $summary->captured);
expect('summary line', str_contains($summary_text, 'SUMMARY - ran 2, skipped 1, blocks skipped 1'), true);
expect('quiet summary', str_contains($summary_text, 'SUMMARY - '), true);
expect('quiet hides start', str_contains($summary_text, 'START - '), false);
$routes = array(
'click (12,34)',
'keyboard a',
'rclick mark.png');
foreach ($routes as $route_line) {
$route = new TaguiModern(null, true, '/tmp');
$route->capture = true;
$route->execute_range(array($route_line), 0, 0, true);
expect('sikuli route ' . $route_line, $route->stopped && str_contains($route->captured[0], 'OculiX') && !str_contains($route->captured[0], 'cannot understand'), true);}
$seen = new TaguiModern(null, true, '/tmp');
$seen->capture = true;
$seen->execute_range(array('if exist("mark.png")', 'echo "seen"', 'echo "after-image"'), 0, 2, true);
expect('exist image', $seen->stopped && str_contains($seen->captured[0], 'OculiX'), true);
expect('exist not dom', str_contains($seen->captured[0], 'cannot find '), false);
expect('exist skips', in_array('after-image', $seen->captured, true), false);
$present = new TaguiModern(null, true, '/tmp');
$present->capture = true;
$present->execute_range(array('if present("mark.png")', 'echo "seen"'), 0, 1, true);
expect('present image', $present->stopped && str_contains($present->captured[0], 'OculiX'), true);
putenv('TAGUI_SIKULI_JAR');

$casper_echo = new TaguiModern(null, true, '/tmp');
$casper_echo->capture = true;
$casper_echo->execute_range(array("this.echo('hello');"), 0, 0, true);
expect('this.echo', in_array('hello', $casper_echo->captured, true) && !$casper_echo->stopped, true);
$casper_only = new TaguiModern(null, true, '/tmp');
$casper_only->capture = true;
$casper_only->execute_range(array("this.click('x');"), 0, 0, true);
$casper_text = implode("\n", $casper_only->captured);
expect('casper only', $casper_only->stopped && str_contains($casper_text, 'Casper-only'), true);
expect('casper not page', str_contains($casper_text, 'page script'), false);
$js_echo = new TaguiModern(null, true, '/tmp');
$js_echo->capture = true;
$js_echo->execute_range(array('js begin', "this.echo('YES');", 'js finish'), 0, 2, true);
expect('js this.echo', in_array('YES', $js_echo->captured, true) && !$js_echo->stopped, true);
$undefined = new TaguiModern(null, true, '/tmp');
$undefined->capture = true;
$undefined->execute_range(array('check eggs < 10 | "true" | "false"', 'if eggs < 10', 'echo "yes"', 'echo "after"'), 0, 3, true);
expect('undefined check', in_array('false', $undefined->captured, true), true);
expect('undefined if continues', !$undefined->stopped && in_array('after', $undefined->captured, true), true);
expect('undefined if skips', in_array('yes', $undefined->captured, true), false);
$plus = new TaguiModern(null, true, '/tmp');
$plus->vars['locator'] = 'email_button';
$plus->vars['variable'] = '5';
expect('plus concat', $plus->expand_backticks("click '+locator+'"), 'click email_button');
expect('plus xpath', $plus->expand_backticks("(//*[@id=\"test\"])['+variable+']"), '(//*[@id="test"])[5]');
$colors = new TaguiModern(null, true, '/tmp');
$colors->capture = true;
$colors->execute_range(array("var COLOR1='red'; var COLOR2='green'; var COLOR3='blue';"), 0, 0, true);
expect('multi var', !$colors->stopped && $colors->vars['COLOR1'] === 'red' && $colors->vars['COLOR2'] === 'green' && $colors->vars['COLOR3'] === 'blue', true);
$run_dir = sys_get_temp_dir() . '/tagui-run-' . getmypid();
@mkdir($run_dir, 0700, true);
$run = new TaguiModern(null, true, $run_dir);
$run->capture = true;
$run->execute_range(array('run begin', 'printf %s joined', 'run finish', 'echo "after-run"'), 0, 3, true);
expect('run joined', !$run->stopped && isset($run->vars['run_result']) && str_contains((string)$run->vars['run_result'], 'joined'), true);
expect('run continues', in_array('after-run', $run->captured, true), true);
$timed = new TaguiModern(null, true, '/tmp');
$timed->started_at = microtime(true) - 1.2;
$timed->execute_range(array('elapsed = timer()'), 0, 0, true);
expect('timer', !$timed->stopped && isset($timed->vars['elapsed']) && $timed->vars['elapsed'] >= 1, true);
$mouse_jar = getenv('TAGUI_SIKULI_JAR');
putenv('TAGUI_SIKULI_JAR');
$mouse = new TaguiModern(null, true, '/tmp');
$mouse->capture = true;
$mouse->execute_range(array('xy = mouse_xy()'), 0, 0, true);
$mouse_text = implode("\n", $mouse->captured);
expect('mouse routed', $mouse->stopped && str_contains($mouse_text, 'OculiX') && !str_contains($mouse_text, 'unknown function'), true);
if ($mouse_jar !== false && $mouse_jar !== '') putenv('TAGUI_SIKULI_JAR=' . $mouse_jar);
$files = sys_get_temp_dir() . '/tagui-files-' . getmypid();
@mkdir($files, 0700, true);
$save = new TaguiModern(null, true, $files);
$save->capture = true;
$save->vars['element'] = 'not-the-dom';
$save->execute_range(array('save element'), 0, 0, true);
expect('save asks dom', str_contains(implode("\n", $save->captured), 'browser is not open'), true);
expect('save ignores var', !is_file($files . '/text1.txt'), true);
$transfer = new TaguiModern(null, true, $files);
$transfer->vars['variable'] = 'X';
$transfer->execute_range(array("write 'abc'", 'dump "some text - " variable'), 0, 1, true);
expect('write default', !$transfer->stopped && is_file($files . '/text1.txt') && str_contains((string)file_get_contents($files . '/text1.txt'), 'abc'), true);
expect('dump default', is_file($files . '/text2.txt') && trim((string)file_get_contents($files . '/text2.txt')) === 'some text - X', true);

foreach (array('tagui_modern.php', 'tagui_steps.php', 'tagui_runtime.php') as $source_name) {
$source = file_get_contents(dirname(__DIR__) . '/' . $source_name);
expect($source_name . ' no curl', str_contains($source, 'curl_'), false);
expect($source_name . ' no DOMDocument', str_contains($source, 'DOMDocument'), false);}

if ($failures > 0) {fwrite(STDERR, "$failures failed\n"); exit(1);}
fwrite(STDOUT, "all logic checks passed\n");
exit(0);
