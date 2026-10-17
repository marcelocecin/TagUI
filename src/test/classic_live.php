<?php

/* RPA-Python live mode is the blocking stdin loop in the generated Casper
   script. This checks that loop. When casperjs and phantomjs are both on
   PATH and phantomjs answers --version, it also pipes a few live lines. */

$repo = dirname(__DIR__, 2);
$dir = sys_get_temp_dir() . '/tagui-live-' . getmypid();
mkdir($dir, 0700, true);
foreach (array('tagui.sikuli', 'tagui_r', 'tagui_py') as $extra) mkdir($dir . '/' . $extra, 0700, true);
foreach (array('tagui_parse.php', 'tagui_header.js', 'tagui_footer.js', 'tagui_config.txt') as $name) {
copy($repo . '/src/' . $name, $dir . '/' . $name);}
$flow = $dir . '/live_probe';
file_put_contents($flow, "live\n");
$spec = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
$env = child_env(array(
'custom_csv_file' => $dir . '/missing.csv',
'tagui_web_browser' => 'phantomjs',
'quiet_mode' => 'false'));
$proc = proc_open(array('php', '-q', 'tagui_parse.php', $flow), $spec, $pipes, $dir, $env);
fclose($pipes[0]);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$code = proc_close($proc);
$js_path = $flow . '.js';
if ($code !== 0 || !is_file($js_path)) {
fwrite(STDERR, "live parse failed ($code)\n$out\n$err\n");
exit(1);}
$js = file_get_contents($js_path);
$needles = array(
'while (true)',
'sys.stdin.readLine()',
"LIVE MODE - type done to quit\\n \\b",
"live_input.indexOf('done') == 0");
foreach ($needles as $needle) {
if (!str_contains($js, $needle)) {
fwrite(STDERR, "live script missing: $needle\n");
exit(1);}}
if (str_contains($js, 'tagui_live_step')) {
fwrite(STDERR, "live script still uses tagui_live_step\n");
exit(1);}

$casper = bin_on_path('casperjs');
$phantom = bin_on_path('phantomjs');
if ($casper === '' || $phantom === '' || !phantom_runs($phantom)) {
fwrite(STDOUT, "classic live ok (parse only, casperjs/phantomjs not runnable)\n");
cleanup($dir);
exit(0);}

$shim = $dir . '/bin';
mkdir($shim, 0700, true);
if (bin_on_path('python') === '' && bin_on_path('python3') !== '') {
symlink(bin_on_path('python3'), $shim . '/python');}
$session = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
$run_env = $env;
$run_env['PHANTOMJS_EXECUTABLE'] = $phantom;
$run_env['OPENSSL_CONF'] = '/dev/null';
$path = isset($run_env['PATH']) ? $run_env['PATH'] : '';
$run_env['PATH'] = $shim . PATH_SEPARATOR . $path;
$run = proc_open(array($casper, $js_path), $session, $run_pipes, $dir, $run_env);
if (!is_resource($run)) {
fwrite(STDERR, "cannot start casperjs\n");
exit(1);}
$input = "echo hello\necho world\ndone\n";
fwrite($run_pipes[0], $input);
fclose($run_pipes[0]);
stream_set_blocking($run_pipes[1], false);
stream_set_blocking($run_pipes[2], false);
$stdout = ''; $stderr = '';
$deadline = microtime(true) + 25;
while (microtime(true) < $deadline) {
$stdout .= (string)stream_get_contents($run_pipes[1]);
$stderr .= (string)stream_get_contents($run_pipes[2]);
$status = proc_get_status($run);
if (!$status['running']) break;
usleep(100000);}
$status = proc_get_status($run);
if (!empty($status['running'])) {
proc_terminate($run);
fwrite(STDERR, "live session timed out\n$stdout\n$stderr\n");
exit(1);}
$stdout .= (string)stream_get_contents($run_pipes[1]);
$stderr .= (string)stream_get_contents($run_pipes[2]);
fclose($run_pipes[1]); fclose($run_pipes[2]);
proc_close($run);
$combined = $stdout . "\n" . $stderr;
if (!str_contains($combined, 'LIVE MODE - type done to quit') || !str_contains($combined, 'hello') || !str_contains($combined, 'world')) {
fwrite(STDERR, "live session did not echo the piped steps\n$combined\n");
exit(1);}
fwrite(STDOUT, "classic live ok (casperjs session)\n");
cleanup($dir);
exit(0);

function child_env($overrides) {
$base = array();
foreach ($_SERVER as $key => $value) {
if (is_string($key) && is_string($value)) $base[$key] = $value;}
foreach (array('PATH', 'HOME', 'LANG', 'LC_ALL', 'TMPDIR') as $key) {
$value = getenv($key);
if ($value !== false) $base[$key] = $value;}
return array_merge($base, $overrides);}

function bin_on_path($name) {
$path = getenv('PATH');
if (!is_string($path) || $path === '') return '';
foreach (explode(PATH_SEPARATOR, $path) as $dir) {
$candidate = $dir . DIRECTORY_SEPARATOR . $name;
if (is_file($candidate) && is_executable($candidate)) return $candidate;}
return '';}

function phantom_runs($phantom) {
$spec = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
$env = child_env(array('OPENSSL_CONF' => '/dev/null'));
$proc = proc_open(array($phantom, '--version'), $spec, $pipes, null, $env);
if (!is_resource($proc)) return false;
stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
return proc_close($proc) === 0;}

function cleanup($dir) {
if (!is_dir($dir)) return;
$items = new RecursiveIteratorIterator(
new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
RecursiveIteratorIterator::CHILD_FIRST);
foreach ($items as $item) {
if ($item->isDir()) rmdir($item->getPathname());
else unlink($item->getPathname());}
rmdir($dir);}
