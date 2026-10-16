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
expect('header uses shared intent', str_contains(file_get_contents(dirname(__DIR__) . '/tagui_header.js'), 'tagui_keyword_intent'), true);
expect('header uses shared locator', str_contains(file_get_contents(dirname(__DIR__) . '/tagui_header.js'), 'tagui_locator_attributes'), true);

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

if ($failures > 0) {fwrite(STDERR, "$failures failed\n"); exit(1);}
fwrite(STDOUT, "all logic checks passed\n");
exit(0);
