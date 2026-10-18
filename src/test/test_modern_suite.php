<?php

/* Run src/test/positive_test on the modern engine.
   The flow file itself is not edited: classic parity still parses the original.
   External hosts are rewritten only in the copy this script executes, and that
   copy is served by src/test/fixtures/suite_router.php.

   Baseline at rpa_python 4e2810f, slice harness, steps after this.exit():
     292/400 = 73.0% raw
     327/400 = 81.8% after the harness supplied `variable` and tmp/
   The 73 lines still failing in that second pass were popup about (7),
   frame about|detail (14), receive (2), popup url_keyword (8), dom (2),
   r without Rscript (22), a vision block split into JavaScript (2),
   xpath [undefined] because slices dropped variable = 5 (8), and mouse_xy
   without a Sikuli jar (8).

   TAGUI_SUITE_PROBE=1 runs only the popup, frame, receive, and dom checks.

   read page stores the page HTML in variable. The later index clicks still
   name variable, and the fixture's fifth #test button is index 5, so the
   executed copy assigns variable = 5 on the line before each of those clicks.
   The original positive_test file is not edited.

   Image and coordinate steps run when an OculiX/Sikuli jar and Xvfb are
   available. The harness opens fixtures/visual.html on that display and
   crops each mark into the filename the suite already uses. */

$root = dirname(__DIR__, 2);
$tagui = $root . '/src/tagui';
$flow_source = $root . '/src/test/positive_test';
$fixtures = $root . '/src/test/fixtures';
require_once $root . '/src/tagui_modern.php';
require_once __DIR__ . '/visual_images.php';

$failures = 0;
function note($label, $ok, $detail) {
global $failures;
if ($ok) {fwrite(STDOUT, "ok   $label\n"); return;}
$failures++;
fwrite(STDERR, "FAIL $label\n$detail\n");}

function command_exists($name) {
$path = getenv('PATH');
if (!is_string($path) || $path === '') return false;
foreach (explode(PATH_SEPARATOR, $path) as $dir) {
$file = $dir . DIRECTORY_SEPARATOR . $name;
if ($file !== '' && is_file($file) && is_executable($file)) return true;}
return false;}

function suite_port() {
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) return 0;
$name = stream_socket_get_name($server, false);
fclose($server);
$port = (int)substr((string)$name, (int)strrpos((string)$name, ':') + 1);
return $port > 0 ? $port : 0;}

function suite_rm($dir) {
if (!is_dir($dir)) return;
$items = scandir($dir);
if ($items === false) return;
foreach ($items as $item) {
if ($item === '.' || $item === '..') continue;
$path = $dir . DIRECTORY_SEPARATOR . $item;
if (is_dir($path)) suite_rm($path); else @unlink($path);}
@rmdir($dir);}

function suite_clean_src($src) {
$items = scandir($src);
if ($items === false) return;
foreach ($items as $name) {
if ($name === '.' || $name === '..') continue;
if (!str_contains($name, '\\') && !str_starts_with($name, 'c:')) continue;
$path = $src . DIRECTORY_SEPARATOR . $name;
if (is_file($path)) @unlink($path);}}

function suite_sikuli_jar() {
$env = getenv('TAGUI_SIKULI_JAR');
if (is_string($env) && $env !== '' && is_file($env)) return $env;
$found = TaguiSikuli::jar();
if ($found !== '') return $found;
foreach (array('/tmp/oculixide-4.0.0-linux.jar') as $candidate) {
if (is_file($candidate)) return $candidate;}
return '';}

function visual_step_target($line) {
$trim = trim($line);
if (!preg_match('/^(tap|click|hover|move|type|enter|select|choose)\b/i', $trim, $match)) return '';
$rest = trim(substr($trim, strlen($match[0])));
$as = stripos($rest, ' as ');
if ($as !== false) $rest = trim(substr($rest, 0, $as));
return $rest;}

function needs_screen_target($line) {
$target = visual_step_target($line);
if ($target === '') return false;
return tagui_is_visual($target);}

function needs_sikuli($line) {
$low = strtolower($line);
if (preg_match('/^(keyboard|mouse|vision)(\s|$)/', $low)) return true;
return needs_screen_target($line);}

function is_external_step($line) {
$trim = trim($line);
if (preg_match('#^https?://#i', $trim)) return true;
if (str_contains($trim, 'dummytestsite.com') || str_contains($trim, 'yahoo.com') || str_contains($trim, 'google.com')) return true;
return false;}

function suite_r_section($section) {
$sec = strtolower(trim($section));
return $sec === 'test r' || str_starts_with($sec, 'test r ');}

function suite_index_click($line) {
$trim = trim($line);
return $trim === 'click (//*[@id="test"])[\'+variable+\']' || $trim === 'click (//*[@id="test"])[`variable`]';}

function engine_section($section) {
$sec = strtolower($section);
foreach (array('popup', 'frame', 'receive', 'dom', 'mouse_xy') as $word) {
if (str_contains($sec, $word)) return true;}
return false;}

function skip_reason($trim, $section, $tools) {
$low = strtolower($trim);
$sec = strtolower($section);
if (str_starts_with($sec, 'test {} while loop simple') && $low !== 'number = 1') {
return 'suite while never changes number, so the loop does not end';}
if ($low === 'live' || str_starts_with($low, 'live ')) return 'interactive live mode reads stdin until done';
if (!$tools['r'] && (suite_r_section($sec) || preg_match('/^r(\s|$)/', $low))) return 'Rscript is not installed';
if (!$tools['py'] && str_starts_with($sec, 'test py')) return 'Python is not installed';
if (needs_screen_target($trim) || preg_match('/^vision(\s|$)/', $low)) {
if (!$tools['sikuli']) return 'OculiX/Sikuli jar is not installed';
if (empty($tools['display'])) return 'no display for visual steps';}
if (!$tools['sikuli'] && (str_contains($sec, 'mouse_xy') || needs_sikuli($trim))) return 'OculiX/Sikuli jar is not installed';
return '';}

function rewrite_external($line, $base, $host) {
$trim = trim($line);
if (preg_match('#^https?://(?:www\.)?google\.com/?$#i', $trim)) return $base . '/org/page.html';
if (preg_match('#^https?://ca\.yahoo\.com/?$#i', $trim)) return $base . '/org/page.html';
if (preg_match('#^https?://www\.dummytestsite\.com/?$#i', $trim)) return $base . '/org/page.html';
if (str_contains($line, '://www.dummytestsite.com/')) {
$line = str_replace('http://www.dummytestsite.com', $base, $line);
return str_replace('https://www.dummytestsite.com', $base, $line);}
if (str_contains($line, 'www.dummytestsite.com')) return str_replace('www.dummytestsite.com', $host . '/org/page.html', $line);
return $line;}

function collect_steps($body, $tools) {
$section = 'before first header';
$steps = array();
$count = count($body);
for ($i = 0; $i < $count; $i++) {
$raw = $body[$i];
$trim = trim($raw);
if (preg_match('/^\/\/\s*test\b/i', $trim)) {$section = trim(substr($trim, 2)); continue;}
if ($trim === '' || str_starts_with($trim, '//')) {$steps[] = array('raw' => $raw, 'step' => false, 'skip' => '', 'section' => $section); continue;}
if (preg_match('/^(js|py|r|vision|dom)\s+begin$/i', $trim, $match)) {
$kind = strtolower($match[1]);
$block = array($raw);
$i++;
while ($i < $count) {
$block[] = $body[$i];
if (strtolower(trim($body[$i])) === $kind . ' finish') break;
$i++;}
$reason = skip_reason($trim, $section, $tools);
foreach ($block as $piece) {
$piece_trim = trim($piece);
$piece_skip = $reason;
if ($piece_skip === '' && $piece_trim !== '' && !str_starts_with($piece_trim, '//') && $piece_trim !== '{' && $piece_trim !== '}') {
$piece_skip = skip_reason($piece_trim, $section, $tools);}
$is_step = $piece_trim !== '' && !str_starts_with($piece_trim, '//') && $piece_trim !== '{' && $piece_trim !== '}';
$steps[] = array('raw' => $piece, 'step' => $is_step, 'skip' => $piece_skip, 'section' => $section);}
continue;}
$reason = skip_reason($trim, $section, $tools);
$is_step = $trim !== '{' && $trim !== '}';
$steps[] = array('raw' => $raw, 'step' => $is_step, 'skip' => $reason, 'section' => $section);}
return $steps;}

function step_category($step) {
if ($step['skip'] !== '') return 'b';
if (is_external_step($step['raw'])) return 'a';
if (engine_section($step['section'])) return 'c';
return 'core';}

function start_server($fixtures, $port, $log) {
$command = array(PHP_BINARY, '-S', '127.0.0.1:' . $port, 'suite_router.php');
$descriptor = array(0 => array('file', '/dev/null', 'r'), 1 => array('file', $log, 'a'), 2 => array('file', $log, 'a'));
$process = proc_open($command, $descriptor, $pipes, $fixtures);
return is_resource($process) ? $process : null;}

function wait_server($url) {
$deadline = microtime(true) + 5;
while (microtime(true) < $deadline) {
$body = @file_get_contents($url);
if (is_string($body) && str_contains($body, 'id="header"')) return true;
usleep(100000);}
return false;}

function run_flow($tagui, $flow, $work, $path_prefix) {
$saved = getenv('PATH');
if (!is_string($saved)) $saved = '';
putenv('PATH=' . $path_prefix . PATH_SEPARATOR . $saved);
putenv('TAGUI_CHROME_ISOLATED=1');
putenv('tagui_web_browser=headless');
$command = escapeshellarg($tagui) . ' ' . escapeshellarg($flow) . ' headless modern quiet';
$descriptor = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
$process = proc_open($command, $descriptor, $pipes, $work);
if (!is_resource($process)) {putenv('PATH=' . $saved); return array('code' => 1, 'text' => 'cannot start tagui');}
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$code = proc_close($process);
putenv('PATH=' . $saved);
$text = (string)$stdout . "\n" . (string)$stderr;
$log = $flow . '.log';
if (is_file($log)) $text .= "\n" . (string)file_get_contents($log);
return array('code' => $code, 'text' => $text);}

function write_png($path) {
$bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
file_put_contents($path, $bytes);}

$probe = getenv('TAGUI_SUITE_PROBE') === '1';
$sikuli_jar = suite_sikuli_jar();
if ($sikuli_jar !== '') putenv('TAGUI_SIKULI_JAR=' . $sikuli_jar);
$tools = array(
'r' => command_exists('Rscript'),
'py' => command_exists('python3') || command_exists('python'),
'sikuli' => suite_sikuli_jar() !== '',
'display' => false);
$port = suite_port();
if ($port < 1) {fwrite(STDERR, "FAIL cannot reserve a port\n"); exit(1);}
$base = 'http://127.0.0.1:' . $port;
$host = '127.0.0.1:' . $port;
$work = sys_get_temp_dir() . '/tagui_modern_suite_' . getmypid();
suite_rm($work);
mkdir($work . '/tmp', 0777, true);
mkdir($work . '/bin', 0777, true);
foreach (array('face_recognition', 'face_recognition1', 'face_recognition2') as $stub) {
file_put_contents($work . '/bin/' . $stub, "#!/bin/sh\nexit 0\n");
chmod($work . '/bin/' . $stub, 0755);}
write_png($work . '/abc.png');
write_png('/tmp/abc.png');
$server_log = $work . '/server.log';
$server = start_server($fixtures, $port, $server_log);
if ($server === null) {fwrite(STDERR, "FAIL cannot start fixture server\n"); exit(1);}
$screen = new stdClass();
$screen->chrome = null;
$screen->xvfb = null;
$screen->display = '';
register_shutdown_function(function () use ($server, $work, $root, $screen) {
if ($screen->chrome instanceof TaguiChrome) $screen->chrome->stop();
if (is_resource($screen->xvfb)) {proc_terminate($screen->xvfb); proc_close($screen->xvfb);}
if (is_resource($server)) {proc_terminate($server); proc_close($server);}
suite_clean_src($root . '/src');
@unlink('/tmp/abc.png');
@unlink('/tmp/test.png');
@unlink('/tmp/filename.txt');
suite_rm($work);});
if (!wait_server($base . '/org/page.html')) {
fwrite(STDERR, "FAIL fixture server did not answer\n" . (string)@file_get_contents($server_log) . "\n");
exit(1);}
note('fixture page', str_contains((string)file_get_contents($base . '/org/page.html'), 'About automation'), $base);
note('fixture report', (string)@file_get_contents($base . '/report/month.txt') === "month-report\n", (string)@file_get_contents($base . '/report/month.txt'));
if (!$probe && $tools['sikuli']) {
$reference = getenv('TAGUI_VISUAL_REFERENCES') === '1' ? $fixtures . '/images' : '';
$tools['display'] = visual_show($base, $work, $screen, $reference);
note('visual page', $tools['display'], $screen->display);}

if ($probe) {
$flow = $work . '/probe_flow';
file_put_contents($flow, implode("\n", array(
$base . '/org/page.html',
'wait for #header p',
'click open_about',
'click open_keyword',
'frame about | detail',
'click test',
'popup about',
'click test1',
'popup url_keyword',
'frame mainframe | subframe',
'echo "test popup with subframe"',
'frame mainframe',
'echo "test mainframe"',
'download ' . $base . '/report/month.txt to ' . $work . '/got.txt',
'receive report to ' . $work . '/recv.txt',
'dom document.querySelector(\'#header p\').focus()',
'if url() contains \'org\'',
'echo \'page url contains org\'',
'if title() contains \'About\'',
'echo \'page title contains About\'',
'if text() contains \'automation\'',
'echo \'page text contains automation\'',
'variable = 5',
'click (//*[@id="test"])[5]',
'')) . "\n");
$result = run_flow($tagui, $flow, $work, $work . '/bin');
note('probe exit', $result['code'] === 0, $result['text']);
note('probe popup', str_contains($result['text'], 'test popup with subframe'), $result['text']);
note('probe url', str_contains($result['text'], 'page url contains org'), $result['text']);
note('probe receive', is_file($work . '/recv.txt') && str_contains((string)file_get_contents($work . '/recv.txt'), 'month-report'), (string)@file_get_contents($work . '/recv.txt'));
$null_flow = $work . '/null_flow';
file_put_contents($null_flow, $base . "/org/page.html\ndom document.querySelector('#missing p').focus()\n");
$null = run_flow($tagui, $null_flow, $work, $work . '/bin');
note('null focus stops', $null['code'] === 1 && str_contains($null['text'], 'dom target is null'), $null['text']);
if ($failures > 0) exit(1);
fwrite(STDOUT, "probe passed\n");
exit(0);}

$lines = file($flow_source, FILE_IGNORE_NEW_LINES);
if ($lines === false) {fwrite(STDERR, "FAIL cannot read positive_test\n"); exit(1);}
$start = 0;
foreach ($lines as $index => $line) {
if (str_contains($line, 'this.exit()')) {$start = $index + 1; break;}}
$body = array_slice($lines, $start);
$steps = collect_steps($body, $tools);
$flow_lines = array(
$base . '/org/page.html',
'wait for #header p',
'click open_about',
'click open_keyword');
$flow_index = array();
foreach ($steps as $offset => $step) {
$trim = trim($step['raw']);
if ($step['skip'] !== '') {
$flow_lines[] = '// SKIP ' . $step['skip'] . ' | ' . $trim;
continue;}
$written = rewrite_external($step['raw'], $base, $host);
if (suite_index_click($written)) $flow_lines[] = 'variable = 5';
$flow_lines[] = $written;
if ($step['step']) $flow_index[count($flow_lines)] = $offset;}
$flow = $work . '/positive_test_modern';
file_put_contents($flow, implode("\n", $flow_lines) . "\n");
$result = run_flow($tagui, $flow, $work, $work . '/bin');
$error_line = 0;
if (preg_match('/ERROR - \[LINE (\d+)\]/', $result['text'], $match)) $error_line = (int)$match[1];
$counts = array('a' => array('pass' => 0, 'fail' => 0, 'skip' => 0), 'b' => array('pass' => 0, 'fail' => 0, 'skip' => 0), 'c' => array('pass' => 0, 'fail' => 0, 'skip' => 0), 'core' => array('pass' => 0, 'fail' => 0, 'skip' => 0));
$skip_reasons = array();
$failed_steps = array();
foreach ($steps as $offset => $step) {
if (!$step['step']) continue;
$category = step_category($step);
if ($step['skip'] !== '') {
$counts[$category]['skip']++;
$reason = $step['skip'];
if (!isset($skip_reasons[$reason])) $skip_reasons[$reason] = 0;
$skip_reasons[$reason]++;
continue;}
$ran_at = array_search($offset, $flow_index, true);
$status = 'pass';
if ($result['code'] !== 0) {
if ($error_line > 0 && $ran_at === $error_line) $status = 'fail';
else if ($error_line > 0 && $ran_at !== false && $ran_at > $error_line) $status = 'fail';
else if ($error_line === 0) $status = 'fail';}
if ($status === 'pass') $counts[$category]['pass']++;
else {
$counts[$category]['fail']++;
if (count($failed_steps) < 12) $failed_steps[] = trim($step['raw']);}}
$pass = 0; $fail = 0; $skip = 0;
foreach ($counts as $row) {$pass += $row['pass']; $fail += $row['fail']; $skip += $row['skip'];}
$total = $pass + $fail + $skip;
$attempted = $pass + $fail;
$rate = $attempted > 0 ? round(100 * $pass / $attempted, 1) : 0;
$rate_all = $total > 0 ? round(100 * $pass / $total, 1) : 0;
fwrite(STDOUT, "before 4e2810f slice harness: 292/400 = 73.0% raw; 327/400 = 81.8% with variable and tmp/\n");
fwrite(STDOUT, "after  attempted $attempted  pass $pass  fail $fail  skip $skip  rate $rate%  ($rate_all% including skips, $total official step lines)\n");
foreach (array('a' => 'external URL, now a local fixture', 'b' => 'environment', 'c' => 'popup frame receive dom mouse_xy', 'core' => 'other official steps') as $key => $label) {
$row = $counts[$key];
$row_attempted = $row['pass'] + $row['fail'];
$row_rate = $row_attempted > 0 ? round(100 * $row['pass'] / $row_attempted, 1) . '%' : 'n/a';
fwrite(STDOUT, "  $key $label: pass {$row['pass']} fail {$row['fail']} skip {$row['skip']} rate $row_rate\n");}
foreach ($skip_reasons as $reason => $count) fwrite(STDOUT, "  skip $count  $reason\n");
if ($error_line > 0) fwrite(STDOUT, "first error line $error_line\n");
foreach ($failed_steps as $failed) fwrite(STDOUT, "  fail step: $failed\n");
$received = is_file($work . '/filename.txt') ? (string)file_get_contents($work . '/filename.txt') : '';
note('suite exit', $result['code'] === 0, $result['text']);
note('url contains org', str_contains($result['text'], 'page url contains org'), $result['text']);
note('title contains About', str_contains($result['text'], 'page title contains About'), $result['text']);
note('text contains automation', str_contains($result['text'], 'page text contains automation'), $result['text']);
note('popup subframe', str_contains($result['text'], 'test popup with subframe'), $result['text']);
note('frame subframe', str_contains($result['text'], 'test subframe'), $result['text']);
note('receive report', str_contains($received, 'month-report'), $received);
if ($tools['r']) note('r result', str_contains($result['text'], 'TRUE1TRUE2'), $result['text']);
$null_flow = $work . '/null_flow';
file_put_contents($null_flow, $base . "/org/page.html\ndom document.querySelector('#missing p').focus()\n");
$null = run_flow($tagui, $null_flow, $work, $work . '/bin');
note('null focus message', $null['code'] === 1 && str_contains($null['text'], 'dom target is null'), $null['text']);
if ($failures > 0) exit(1);
fwrite(STDOUT, "official suite passed on the modern engine\n");
