<?php

/* Show src/test/fixtures/visual.html on an X display and crop its marks.
   The crops are the images the official suite names (button.png, 1.png, …).
   They are taken from the same window OculiX searches, at device scale 1. */

function visual_png_pixels($bytes) {
$png = "\x89PNG\r\n\x1a\n";
if (!is_string($bytes) || !str_starts_with($bytes, $png)) return null;
$pos = 8;
$width = 0;
$height = 0;
$depth = 0;
$color = 0;
$interlace = 0;
$zlib = '';
$len = strlen($bytes);
while ($pos + 8 <= $len) {
$size = unpack('N', substr($bytes, $pos, 4))[1];
$type = substr($bytes, $pos + 4, 4);
$chunk = substr($bytes, $pos + 8, $size);
if ($type === 'IHDR') {
$width = unpack('N', substr($chunk, 0, 4))[1];
$height = unpack('N', substr($chunk, 4, 4))[1];
$depth = ord($chunk[8]);
$color = ord($chunk[9]);
$interlace = ord($chunk[12]);}
else if ($type === 'IDAT') $zlib .= $chunk;
else if ($type === 'IEND') break;
$pos += 12 + $size;}
if ($width < 1 || $height < 1 || $depth !== 8 || $interlace !== 0) return null;
if ($color !== 2 && $color !== 6) return null;
$channels = $color === 6 ? 4 : 3;
$raw = zlib_decode($zlib);
if (!is_string($raw)) return null;
$stride = $width * $channels;
$out = array();
$prev = str_repeat("\x00", $stride);
$cursor = 0;
for ($y = 0; $y < $height; $y++) {
if ($cursor >= strlen($raw)) return null;
$filter = ord($raw[$cursor]);
$cursor++;
$row = substr($raw, $cursor, $stride);
$cursor += $stride;
$recon = '';
for ($i = 0; $i < $stride; $i++) {
$x = ord($row[$i]);
$a = $i >= $channels ? ord($recon[$i - $channels]) : 0;
$b = ord($prev[$i]);
$c = $i >= $channels ? ord($prev[$i - $channels]) : 0;
if ($filter === 0) $v = $x;
else if ($filter === 1) $v = ($x + $a) & 255;
else if ($filter === 2) $v = ($x + $b) & 255;
else if ($filter === 3) $v = ($x + intdiv($a + $b, 2)) & 255;
else if ($filter === 4) {
$p = $a + $b - $c;
$pa = abs($p - $a);
$pb = abs($p - $b);
$pc = abs($p - $c);
$pr = ($pa <= $pb && $pa <= $pc) ? $a : (($pb <= $pc) ? $b : $c);
$v = ($x + $pr) & 255;}
else return null;
$recon .= chr($v);}
$prev = $recon;
$rgb = '';
for ($x = 0; $x < $width; $x++) {
$i = $x * $channels;
$rgb .= $recon[$i] . $recon[$i + 1] . $recon[$i + 2];}
$out[] = $rgb;}
return array('width' => $width, 'height' => $height, 'rows' => $out);}

function visual_bmp_bytes($image) {
$width = $image['width'];
$height = $image['height'];
$row_stride = intdiv($width * 3 + 3, 4) * 4;
$pixel = $row_stride * $height;
$header = 54;
$out = 'BM' . pack('V', $header + $pixel) . pack('V', 0) . pack('V', $header);
$out .= pack('V', 40) . pack('V', $width) . pack('V', $height);
$out .= pack('v', 1) . pack('v', 24) . pack('V', 0) . pack('V', $pixel);
$out .= pack('V', 2835) . pack('V', 2835) . pack('V', 0) . pack('V', 0);
for ($y = $height - 1; $y >= 0; $y--) {
$row = $image['rows'][$y];
$bgr = '';
for ($x = 0; $x < $width; $x++) $bgr .= $row[$x * 3 + 2] . $row[$x * 3 + 1] . $row[$x * 3];
$out .= $bgr . str_repeat("\x00", $row_stride - $width * 3);}
return $out;}

function visual_xvfb_bin() {
foreach (array('/usr/bin/Xvfb', '/usr/bin/xvfb') as $path) {
if (is_executable($path)) return $path;}
return '';}

function visual_start_display($screen) {
$bin = visual_xvfb_bin();
if ($bin === '') return '';
for ($n = 90; $n <= 109; $n++) {
if (is_file('/tmp/.X' . $n . '-lock')) continue;
$display = ':' . $n;
$log = sys_get_temp_dir() . '/tagui_suite_xvfb_' . $n . '.log';
@unlink($log);
$proc = proc_open(array($bin, $display, '-screen', '0', '1366x768x24', '-nolisten', 'tcp'), array(
0 => array('file', '/dev/null', 'r'),
1 => array('file', $log, 'a'),
2 => array('file', $log, 'a')), $pipes);
if (!is_resource($proc)) continue;
$deadline = microtime(true) + 3;
while (microtime(true) < $deadline) {
if (is_file('/tmp/.X' . $n . '-lock')) {
$screen->xvfb = $proc;
$screen->display = $display;
return $display;}
usleep(50000);}
proc_terminate($proc);
proc_close($proc);}
return '';}

function visual_marks() {
return array(
'button' => array('button.png', 'button.bmp'),
'textbox' => array('textbox.png', 'textbox.bmp'),
'option_list' => array('option_list.png', 'option_list.bmp'),
'option_item' => array('option_item.png', 'option_item.bmp'),
'sometext' => array('sometext.png'),
'one' => array('1.png'),
'testmark' => array('test.png'));}

function visual_write_mark($png_bytes, $names, $dirs) {
$image = visual_png_pixels($png_bytes);
if ($image === null) return false;
$bmp = visual_bmp_bytes($image);
foreach ($names as $name) {
$bmp_name = str_ends_with(strtolower($name), '.bmp');
foreach ($dirs as $dir) {
if ($dir === '') continue;
if ($bmp_name) {
if (file_put_contents($dir . '/' . $name, $bmp) === false) return false;
// OculiX resolves button.bmp by opening button.bmp.png.
if (file_put_contents($dir . '/' . $name . '.png', $png_bytes) === false) return false;}
else if (file_put_contents($dir . '/' . $name, $png_bytes) === false) return false;}}
return true;}

function visual_show($base, $work, $screen, $reference_dir = '') {
$display = visual_start_display($screen);
if ($display === '') return false;
putenv('DISPLAY=' . $display);
putenv('TAGUI_CHROME_ISOLATED=1');
putenv('TAGUI_DEVICE_SCALE=1');
putenv('TAGUI_CHROME_POSITION=0,0');
$chrome = new TaguiChrome();
$chrome->timeout = 20;
$screen->chrome = $chrome;
try {$chrome->start(false);}
catch (Exception $e) {
fwrite(STDERR, "visual chrome did not start: " . $e->getMessage() . "\n");
return false;}
try {$chrome->navigate($base . '/visual.html');}
catch (Exception $e) {
fwrite(STDERR, "visual page did not open: " . $e->getMessage() . "\n");
return false;}
$dirs = array($work);
if ($reference_dir !== '') {
if (!is_dir($reference_dir) && !mkdir($reference_dir, 0777, true) && !is_dir($reference_dir)) return false;
$dirs[] = $reference_dir;}
foreach (visual_marks() as $id => $names) {
$info = $chrome->locate('#' . $id);
if (!is_array($info) || empty($info['width']) || empty($info['height'])) {
fwrite(STDERR, "visual mark #$id was not on the page\n");
return false;}
$clip = array(
'x' => (float)$info['left'],
'y' => (float)$info['top'],
'width' => (float)$info['width'],
'height' => (float)$info['height']);
$png_path = $work . '/.visual_' . $id . '.png';
if (!$chrome->screenshot($png_path, $clip)) {
fwrite(STDERR, "visual mark #$id was not captured\n");
return false;}
$png = (string)file_get_contents($png_path);
@unlink($png_path);
if (!visual_write_mark($png, $names, $dirs)) {
fwrite(STDERR, "visual mark #$id could not be saved\n");
return false;}}
if (!is_file($work . '/test.png') || !copy($work . '/test.png', '/tmp/test.png')) return false;
putenv('TAGUI_CHROME_POSITION');
return true;}
