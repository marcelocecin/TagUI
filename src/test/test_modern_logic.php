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

if ($failures > 0) {fwrite(STDERR, "$failures failed\n"); exit(1);}
fwrite(STDOUT, "all logic checks passed\n");
exit(0);
