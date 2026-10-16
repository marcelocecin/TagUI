<?php

/* devicePixelRatio snaps and file:// symlink resolution in headless Chrome.
   TAGUI_DEVICE_SCALE=2 stands in for a Retina display. */

require_once dirname(__DIR__) . '/tagui_modern.php';

putenv('TAGUI_DEVICE_SCALE=2');
$work = sys_get_temp_dir() . '/tagui_screen_' . getmypid();
mkdir($work);
$html = $work . '/box.html';
file_put_contents($html, '<!DOCTYPE html><html><head><title>Scale</title></head><body>'
. '<div id="box" style="width:100px;height:40px;background:#c00">box</div></body></html>');
$link_dir = sys_get_temp_dir() . '/tagui_screen_link_' . getmypid();
symlink($work, $link_dir);
$snap = $work . '/box.png';
$page_snap = $work . '/page.png';
$chrome = new TaguiChrome();
$failures = 0;
function check($label, $ok, $detail) {
global $failures;
if ($ok) {fwrite(STDOUT, "ok   $label\n"); return;}
$failures++;
fwrite(STDERR, "FAIL $label\n$detail\n");}
try {
$chrome->start(true);
$chrome->timeout = 10;
$landed = $chrome->navigate(tagui_file_url('file://' . $link_dir . '/box.html'));
$real_base = basename((string)realpath($work));
check('landed', is_string($landed) && str_contains($landed, 'box.html') && !str_contains($landed, 'chrome-error://'), (string)$landed);
check('real path', str_contains((string)$landed, $real_base) || str_contains((string)$landed, rawurlencode($real_base)), (string)$landed);
check('link hidden', !str_contains((string)$landed, basename($link_dir)), (string)$landed);
$scale = $chrome->device_scale();
check('dpr', abs($scale - 2) < 0.01, (string)$scale);
$info = $chrome->find('#box', true);
check('box', is_array($info), json_encode($info));
if (is_array($info)) {
$clip = array('x' => (float)$info['left'], 'y' => (float)$info['top'], 'width' => (float)$info['width'], 'height' => (float)$info['height']);
check('saved', $chrome->screenshot($snap, $clip), $snap);
$size = tagui_png_size($snap);
$expect_w = (int)round((float)$info['width'] * $scale);
$expect_h = (int)round((float)$info['height'] * $scale);
check('snap width', is_array($size) && abs($size['width'] - $expect_w) <= 2, json_encode($size) . ' expected ' . $expect_w);
check('snap height', is_array($size) && abs($size['height'] - $expect_h) <= 2, json_encode($size) . ' expected ' . $expect_h);
check('page snap', $chrome->screenshot($page_snap, null), $page_snap);
$page_size = tagui_png_size($page_snap);
check('page scale', is_array($page_size) && $page_size['width'] >= 1366 * 1.5 && $page_size['width'] <= 1366 * 2.5, json_encode($page_size));}}
catch (Exception $e) {
check('run', false, $e->getMessage());}
$chrome->stop();
putenv('TAGUI_DEVICE_SCALE');
@unlink($html);
@unlink($snap);
@unlink($page_snap);
@unlink($link_dir);
@rmdir($work);
if ($failures > 0) exit(1);
fwrite(STDOUT, "screen checks passed\n");
