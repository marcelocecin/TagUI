<?php

/* Headless smoke for the OculiX file bridge. Skips when the jar is not present.
   Image click and OCR are not asserted here: this checks that tagui.sikuli
   reaches [0] START and runs a vision step that only prints. */

require_once dirname(__DIR__) . '/tagui_modern.php';

$jar = getenv('TAGUI_SIKULI_JAR');
if (!is_string($jar) || $jar === '' || !is_file($jar)) {
$candidates = array(
'/tmp/oculixide-4.0.0-linux.jar',
dirname(__DIR__) . '/sikulix/oculixide.jar');
$jar = '';
foreach ($candidates as $candidate) {if (is_file($candidate)) {$jar = $candidate; break;}}}
if ($jar === '' || tagui_which('java') === '') {
fwrite(STDOUT, "ok   skip (no OculiX jar or java)\n");
exit(0);}
putenv('TAGUI_SIKULI_JAR=' . $jar);

$display = getenv('DISPLAY');
$xvfb = null;
if (!is_string($display) || $display === '') {
if (tagui_which('Xvfb') === '') {fwrite(STDOUT, "ok   skip (no DISPLAY and no Xvfb)\n"); exit(0);}
$display = ':99';
putenv('DISPLAY=' . $display);
$xvfb = proc_open(array('Xvfb', $display, '-screen', '0', '1366x768x24'), array(
0 => array('file', '/dev/null', 'r'),
1 => array('file', '/dev/null', 'w'),
2 => array('file', '/dev/null', 'w')), $pipes);
usleep(400000);}

$failures = 0;
try {
$ok = TaguiSikuli::run('vision print("[tagui-smoke]")', 90);
$ocr = TaguiSikuli::run('vision output_sikuli_text(Screen().text())', 60);
$keys = TaguiSikuli::run('keyboard a', 30);
$log = (string)@file_get_contents(sys_get_temp_dir() . '/tagui_oculix.log');
if ($ok) fwrite(STDOUT, "ok   vision print\n");
else {fwrite(STDERR, "FAIL vision print\n$log\n"); $failures++;}
if (str_contains($log, '[tagui-smoke]') || str_contains($log, 'tagui-smoke')) fwrite(STDOUT, "ok   log contains print\n");
else {fwrite(STDERR, "FAIL log missing print\n$log\n"); $failures++;}
if ($ocr) fwrite(STDOUT, "ok   screen ocr\n");
else {fwrite(STDERR, "FAIL screen ocr\n$log\n"); $failures++;}
if ($keys) fwrite(STDOUT, "ok   keyboard\n");
else {fwrite(STDERR, "FAIL keyboard\n$log\n"); $failures++;}}
catch (Exception $e) {
fwrite(STDERR, 'FAIL ' . $e->getMessage() . "\n");
$log = (string)@file_get_contents(sys_get_temp_dir() . '/tagui_oculix.log');
fwrite(STDERR, $log . "\n");
$failures++;}
TaguiSikuli::stop();
if (is_resource($xvfb)) {proc_terminate($xvfb); proc_close($xvfb);}
if ($failures > 0) exit(1);
fwrite(STDOUT, "sikuli smoke passed\n");
exit(0);
