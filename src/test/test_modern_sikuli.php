<?php

/* Headless smoke for the OculiX file bridge. Skips when the jar is not present.
   A missing PNG must come back as "not on screen". OCR text is not asserted:
   the Xvfb screen is blank. */

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
else {fwrite(STDERR, "FAIL keyboard\n$log\n"); $failures++;}
$missing = sys_get_temp_dir() . '/tagui_missing_' . getmypid() . '.png';
write_unique_png($missing);
TaguiSikuli::run('vision wait_timeout = 1; setAutoWaitTimeout(1)', 30);
$miss = TaguiSikuli::run('click ' . $missing, 20);
$note = TaguiSikuli::last_result();
@unlink($missing);
if (!$miss && str_contains($note, 'not on screen')) fwrite(STDOUT, "ok   image miss ($note)\n");
else {fwrite(STDERR, "FAIL image miss\n$note\n"); $failures++;}
TaguiSikuli::run('vision wait_timeout = 8; setAutoWaitTimeout(8)', 30);
$hang = sys_get_temp_dir() . '/tagui_hang_' . getmypid() . '.png';
$needle = sys_get_temp_dir() . '/tagui_kill_needle_' . getmypid();
write_unique_png($hang);
file_put_contents($needle, 'oculixide');
$kill_script = 'sleep 1; needle=$(cat ' . $needle . '); for p in /proc/[0-9]*; do cmd=$(tr "\000" " " < "$p/cmdline" 2>/dev/null || true); case "$cmd" in *"$needle"*) kill -9 "${p#/proc/}";; esac; done';
$killer = proc_open(array('bash', '-c', $kill_script), array(
0 => array('file', '/dev/null', 'r'),
1 => array('file', '/dev/null', 'w'),
2 => array('file', '/dev/null', 'w')), $kill_pipes);
$began = microtime(true);
$died = false;
$death = '';
try {TaguiSikuli::run('click ' . $hang, 30);}
catch (Exception $death_error) {$died = true; $death = $death_error->getMessage();}
$elapsed = microtime(true) - $began;
@unlink($hang);
@unlink($needle);
if (is_resource($killer)) proc_close($killer);
if ($died && $elapsed < 8 && str_contains($death, 'exited during') && !str_contains($death, 'timed out')) fwrite(STDOUT, 'ok   java death (' . number_format($elapsed, 1) . "s)\n");
else {fwrite(STDERR, "FAIL java death elapsed=$elapsed\n$death\n"); $failures++;}}
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

function png_chunk($type, $data) {
return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));}

function write_unique_png($path) {
$width = 80; $height = 40; $raw = '';
for ($y = 0; $y < $height; $y++) {
$raw .= "\x00";
for ($x = 0; $x < $width; $x++) {
$raw .= chr(($x * 17 + $y * 29) & 255);
$raw .= chr(($x * 53 + 90) & 255);
$raw .= chr(($y * 41 + 200) & 255);}}
$ihdr = pack('NNC5', $width, $height, 8, 2, 0, 0, 0);
file_put_contents($path, "\x89PNG\r\n\x1a\n" . png_chunk('IHDR', $ihdr) . png_chunk('IDAT', gzcompress($raw)) . png_chunk('IEND', ''));}
