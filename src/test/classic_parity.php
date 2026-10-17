<?php

/* Compare CasperJS generated for src/test/positive_test with the pinned
   tebelorg rpa_python parser (src/test/parity/tebelorg, commit in PIN).
   flow_path is rewritten to /full_path before the diff. */

$repo = dirname(__DIR__, 2);
$flow = $repo . '/src/test/positive_test';
$pin_dir = $repo . '/src/test/parity/tebelorg';
$pin = trim((string)file_get_contents($pin_dir . '/PIN'));
if ($pin === '') {
fwrite(STDERR, "missing pin\n");
exit(1);}

$fork = stage('fork', array(
'tagui_parse.php' => $repo . '/src/tagui_parse.php',
'tagui_header.js' => $repo . '/src/tagui_header.js',
'tagui_footer.js' => $repo . '/src/tagui_footer.js',
'tagui_config.txt' => $repo . '/src/tagui_config.txt'));
$oracle = stage('tebelorg', array(
'tagui_parse.php' => $pin_dir . '/tagui_parse.php',
'tagui_header.js' => $pin_dir . '/tagui_header.js',
'tagui_footer.js' => $pin_dir . '/tagui_footer.js',
'tagui_config.txt' => $pin_dir . '/tagui_config.txt'));

$left = parse_flow($fork, $flow);
$right = parse_flow($oracle, $flow);
$left_n = normalize_js($left);
$right_n = normalize_js($right);
if ($left_n !== $right_n) {
$diff = tempnam(sys_get_temp_dir(), 'tagui-parity-');
file_put_contents($diff . '.fork.js', $left_n);
file_put_contents($diff . '.tebelorg.js', $right_n);
fwrite(STDERR, "classic parity failed against tebelorg $pin\n");
fwrite(STDERR, "normalized scripts: {$diff}.fork.js {$diff}.tebelorg.js\n");
exit(1);}
fwrite(STDOUT, "classic parity ok (tebelorg $pin)\n");
exit(0);

function stage($name, $files) {
$dir = sys_get_temp_dir() . '/tagui-parity-' . $name . '-' . getmypid();
if (is_dir($dir)) cleanup($dir);
mkdir($dir, 0700, true);
foreach (array('tagui.sikuli', 'tagui_r', 'tagui_py') as $extra) mkdir($dir . '/' . $extra, 0700, true);
foreach ($files as $base => $source) {
if (!is_file($source)) {fwrite(STDERR, "missing $source\n"); exit(1);}
copy($source, $dir . '/' . $base);}
return $dir;}

function parse_flow($dir, $flow) {
$copy = $dir . '/positive_test';
copy($flow, $copy);
$spec = array(
0 => array('pipe', 'r'),
1 => array('pipe', 'w'),
2 => array('pipe', 'w'));
$env = child_env(array(
'custom_csv_file' => $dir . '/missing.csv',
'tagui_web_browser' => 'phantomjs',
'quiet_mode' => 'false'));
$proc = proc_open(array('php', '-q', 'tagui_parse.php', $copy), $spec, $pipes, $dir, $env);
if (!is_resource($proc)) {fwrite(STDERR, "cannot start parser in $dir\n"); exit(1);}
fclose($pipes[0]);
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$code = proc_close($proc);
$js = $copy . '.js';
if ($code !== 0 || !is_file($js)) {
fwrite(STDERR, "parser failed in $dir (exit $code)\n$out\n$err\n");
exit(1);}
if (str_contains($out . $err, 'ERROR -')) {
fwrite(STDERR, "parser reported an error in $dir\n$out\n$err\n");
exit(1);}
$text = file_get_contents($js);
cleanup($dir);
return $text;}

function child_env($overrides) {
$base = array();
foreach ($_SERVER as $key => $value) {
if (is_string($key) && is_string($value)) $base[$key] = $value;}
foreach (array('PATH', 'HOME', 'LANG', 'LC_ALL', 'TMPDIR') as $key) {
$value = getenv($key);
if ($value !== false) $base[$key] = $value;}
return array_merge($base, $overrides);}

function normalize_js($js) {
if (!preg_match("/var flow_path = '([^']*)'/", $js, $match)) {
fwrite(STDERR, "generated script has no flow_path\n");
exit(1);}
return str_replace($match[1], '/full_path', $js);}

function cleanup($dir) {
if (!is_dir($dir)) return;
$items = new RecursiveIteratorIterator(
new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
RecursiveIteratorIterator::CHILD_FIRST);
foreach ($items as $item) {
if ($item->isDir()) rmdir($item->getPathname());
else unlink($item->getPathname());}
rmdir($dir);}
