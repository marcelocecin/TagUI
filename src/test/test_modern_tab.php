<?php

/* A target=_blank click opens a second page. The next DOM read must see that
   page, not the tab the flow started on. Official Chrome stays on the first
   page socket unless the flow says popup; this engine follows openerId. */

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
$flow = $work . '/tab_flow';
file_put_contents($flow, implode("\n", array(
'file://' . $work . '/first.html',
'click #go',
'wait 0.5',
'echo `title()`',
'read #marker to seen',
'echo seen',
'')) . "\n");

putenv('TAGUI_CHROME_ISOLATED=1');
$command = escapeshellarg($tagui) . ' ' . escapeshellarg($flow) . ' headless modern quiet speed';
$descriptor = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
$process = proc_open($command, $descriptor, $pipes, $work);
$failures = 0;
function check($label, $ok, $detail) {
global $failures;
if ($ok) {fwrite(STDOUT, "ok   $label\n"); return;}
$failures++;
fwrite(STDERR, "FAIL $label\n$detail\n");}
if (!is_resource($process)) {check('start', false, 'cannot start tagui'); exit(1);}
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$code = proc_close($process);
check('exit', $code === 0, "exit $code\n$stderr\n$stdout");
check('second title', preg_match('/^Second$/m', $stdout) === 1, $stdout . $stderr);
check('second marker', preg_match('/^second-tab$/m', $stdout) === 1, $stdout . $stderr);
check('left first', !preg_match('/^First$/m', $stdout), $stdout);
foreach (array('first.html', 'second.html', 'tab_flow') as $name) @unlink($work . '/' . $name);
@rmdir($work);
if ($failures > 0) exit(1);
fwrite(STDOUT, "tab checks passed\n");
