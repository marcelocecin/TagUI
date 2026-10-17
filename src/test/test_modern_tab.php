<?php

/* A target=_blank click opens a second page.
   Default matches official Chrome: DOM steps stay on the first page.
   TAGUI_MODERN_FOLLOW_TABS=1 adopts the page that click opened. */

$root = dirname(__DIR__, 2);
$tagui = $root . '/src/tagui';
$work = sys_get_temp_dir() . '/tagui_modern_tab_' . getmypid();
mkdir($work);
file_put_contents($work . '/first.html', <<<'HTML'
<!DOCTYPE html>
<html><head><title>First</title></head>
<body><a id="go" href="second.html" target="_blank">Open</a></body></html>
HTML);
file_put_contents($work . '/second.html', <<<'HTML'
<!DOCTYPE html>
<html><head><title>Second</title></head>
<body><h1 id="marker">second-tab</h1></body></html>
HTML);
$stay = $work . '/stay_flow';
$follow = $work . '/follow_flow';
file_put_contents($stay, implode("\n", array(
'file://' . $work . '/first.html',
'click #go',
'wait 0.5',
'echo `title()`',
'read #go to link',
'echo link',
'')) . "\n");
file_put_contents($follow, implode("\n", array(
'file://' . $work . '/first.html',
'click #go',
'wait 0.5',
'echo `title()`',
'read #marker to seen',
'echo seen',
'')) . "\n");

$failures = 0;
function check($label, $ok, $detail) {
global $failures;
if ($ok) {fwrite(STDOUT, "ok   $label\n"); return;}
$failures++;
fwrite(STDERR, "FAIL $label\n$detail\n");}

function run_tab($tagui, $flow, $work) {
$command = escapeshellarg($tagui) . ' ' . escapeshellarg($flow) . ' headless modern quiet speed';
$descriptor = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
$process = proc_open($command, $descriptor, $pipes, $work);
if (!is_resource($process)) return array(127, '', 'cannot start tagui');
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
return array(proc_close($process), (string)$stdout, (string)$stderr);}

putenv('TAGUI_CHROME_ISOLATED=1');
putenv('TAGUI_MODERN_FOLLOW_TABS');
list($stay_code, $stay_out, $stay_err) = run_tab($tagui, $stay, $work);
check('default exit', $stay_code === 0, "exit $stay_code\n$stay_err\n$stay_out");
check('default title', preg_match('/^First$/m', $stay_out) === 1, $stay_out . $stay_err);
check('default link', preg_match('/^Open$/m', $stay_out) === 1, $stay_out . $stay_err);
check('default stays', !preg_match('/^Second$/m', $stay_out), $stay_out);

putenv('TAGUI_MODERN_FOLLOW_TABS=1');
list($follow_code, $follow_out, $follow_err) = run_tab($tagui, $follow, $work);
check('follow exit', $follow_code === 0, "exit $follow_code\n$follow_err\n$follow_out");
check('follow title', preg_match('/^Second$/m', $follow_out) === 1, $follow_out . $follow_err);
check('follow marker', preg_match('/^second-tab$/m', $follow_out) === 1, $follow_out . $follow_err);
check('follow left first', !preg_match('/^First$/m', $follow_out), $follow_out);
putenv('TAGUI_MODERN_FOLLOW_TABS');

foreach (array('first.html', 'second.html', 'stay_flow', 'follow_flow') as $name) @unlink($work . '/' . $name);
@rmdir($work);
if ($failures > 0) exit(1);
fwrite(STDOUT, "tab checks passed\n");
