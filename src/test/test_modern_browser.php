<?php

/* Browser coverage for the modern engine: select, upload, table, show page,
   frame, popup, download, receive, run, and api. */

$root = dirname(__DIR__, 2);
$tagui = $root . '/src/tagui';
$page = $root . '/src/test/fixtures/steps_page.html';
$work = sys_get_temp_dir() . '/tagui_modern_browser_' . getmypid();
mkdir($work);
$upload = $work . '/upload.txt';
file_put_contents($upload, "uploaded");
$table = $work . '/grid.csv';
$download = $work . '/download.html';
$received = $work . '/received.html';
$flow = $work . '/steps_flow';
$page_url = 'file://' . $page;
file_put_contents($flow, implode("\n", array(
$page_url,
'select color as Blue',
'dom return document.querySelector("#color").value',
'echo dom_result',
'upload file as ' . $upload,
'dom return document.querySelector("#file").files.length',
'echo dom_result',
'table grid to ' . $table,
'show page',
'wait 0.5',
'frame inner',
'read #inside to framed',
'echo framed',
'click Open',
'popup steps_popup.html',
'read #poptext to popped',
'echo popped',
'https://example.com',
'receive example.com to ' . $received,
'download https://example.com to ' . $download,
'run echo modern-run',
'echo run_result',
'api https://example.com',
'if api_result contains "Example"',
'echo "api-ok"',
'')) . "\n");

$command = escapeshellarg($tagui) . ' ' . escapeshellarg($flow) . ' headless modern quiet speed';
$descriptor = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
$process = proc_open($command, $descriptor, $pipes, $work);
if (!is_resource($process)) {fwrite(STDERR, "FAIL cannot start tagui\n"); exit(1);}
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$code = proc_close($process);
$failures = 0;
function check($label, $ok, $detail) {
global $failures;
if ($ok) {fwrite(STDOUT, "ok   $label\n"); return;}
$failures++;
fwrite(STDERR, "FAIL $label\n$detail\n");}

check('exit', $code === 0, "exit $code\n$stderr\n$stdout");
check('select', str_contains($stdout, "\nblue\n") || str_starts_with($stdout, "blue\n"), $stdout);
check('upload', str_contains($stdout, "\n1\n"), $stdout);
check('table', is_file($table) && str_contains((string)file_get_contents($table), 'A') && str_contains((string)file_get_contents($table), '1'), (string)@file_get_contents($table));
check('show page html', str_contains($stdout, 'show page -') && str_contains($stdout, '<title>Modern Steps</title>'), substr($stdout, 0, 500));
check('frame', str_contains($stdout, 'inside-frame'), $stdout);
check('popup', str_contains($stdout, 'popup-body'), $stdout);
check('run', str_contains($stdout, 'modern-run'), $stdout);
check('api', str_contains($stdout, 'api-ok'), $stdout);
check('download', is_file($download) && str_contains((string)file_get_contents($download), 'Example Domain'), (string)@file_get_contents($download));
check('receive', is_file($received) && str_contains((string)file_get_contents($received), 'Example Domain'), (string)@file_get_contents($received));
if ($failures > 0) exit(1);
fwrite(STDOUT, "all browser checks passed\n");
