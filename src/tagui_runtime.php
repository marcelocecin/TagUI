<?php

/* Opt-in OculiX / Java 17 helper. SikuliX stays the default jar.
   `php tagui_runtime.php --sikuli-launch` prints the java binary and the jar. */

if (!function_exists('str_contains')) {
function str_contains($haystack, $needle) {return $needle === '' || strpos((string)$haystack, (string)$needle) !== false;}}
if (!function_exists('str_starts_with')) {
function str_starts_with($haystack, $needle) {return strncmp((string)$haystack, (string)$needle, strlen((string)$needle)) === 0;}}

class TaguiSikuli {
private static $process = null;
private static $count = 0;
private static $last_result = '';

static function log_path() {return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tagui_oculix.log';}

static function startup_failure($log) {
return self::failure_message('OculiX exited before it was ready.', $log);}

static function step_crash($log) {
return self::failure_message('OculiX exited during the visual step.', $log);}

static function failure_message($lead, $log) {
$text = is_file($log) ? (string)file_get_contents($log) : '';
$message = $lead;
$errors = self::error_lines($text);
if ($errors !== '') $message .= ' ' . $errors;
$cause = self::cause_line($text);
if ($cause === '') $cause = self::relevant_line($text);
if ($cause !== '' && !str_contains($message, $cause)) $message .= ' OculiX said: ' . $cause;
$hint = self::environment_hint($text);
if ($hint !== '') $message .= ' ' . $hint;
return $message . ' See ' . $log;}

static function environment_hint($text) {
if (str_contains($text, 'UnsupportedClassVersionError')) {
return 'OculiX requires Java 17+; set PATH/JAVA_HOME or TAGUI_JAVA';}
$permission = self::permission_hint($text);
if ($permission !== '') return $permission;
if (str_contains($text, 'HeadlessException') || str_contains($text, 'running in headless environment')) {
return 'Java is a headless build. Install the full JRE (not the openjdk-*-jre-headless package) and set DISPLAY to an X11 session. Wayland is untested.';}
return '';}

static function permission_hint($text) {
if (str_contains($text, 'Mouse not useable') || str_contains($text, 'Mouse.init')) {
return 'macOS blocked mouse input. Grant Accessibility and Screen Recording to the app that launches TagUI (Terminal, iTerm, or your IDE), then run the flow again.';}
return '';}

static function cause_line($text) {
$lines = preg_split('/\r\n|\n|\r/', (string)$text);
if (!is_array($lines)) return '';
foreach ($lines as $line) {
$line = trim($line);
if ($line === '' || str_starts_with($line, '[DEBUG')) continue;
if (preg_match('/^\.\.\. \d+ more$/', $line) === 1) continue;
if (str_starts_with($line, 'Caused by:') || str_starts_with($line, 'Exception in thread') || preg_match('/\b(?:Exception|Error):/', $line) === 1) {
if (strlen($line) > 300) $line = substr($line, 0, 300);
return $line;}}
return '';}

static function error_lines($text) {
$lines = preg_split('/\r\n|\n|\r/', (string)$text);
if (!is_array($lines)) return '';
$found = array();
foreach ($lines as $line) {
$line = trim($line);
if ($line === '' || stripos($line, '[error]') === false) continue;
if (strlen($line) > 220) $line = substr($line, 0, 220);
$found[] = $line;
if (count($found) >= 4) break;}
return implode(' ', $found);}

static function relevant_line($text) {
$lines = preg_split('/\r\n|\n|\r/', trim((string)$text));
if (!is_array($lines)) return '';
for ($i = count($lines) - 1; $i >= 0; $i--) {
$line = trim($lines[$i]);
if ($line === '' || str_starts_with($line, '[DEBUG')) continue;
if (preg_match('/^\.\.\. \d+ more$/', $line) === 1) continue;
if (strlen($line) > 300) $line = substr($line, 0, 300);
return $line;}
return '';}

static function failure_detail($result) {
$note = trim(preg_replace('/^ERROR\s*/', '', (string)$result));
if ($note !== '' && str_contains($note, 'not on screen')) return 'Image ' . $note . '.';
return 'The target was not found on screen.';}

static function jar() {
// sikulix/sikulix.jar is the SikuliX default (SikuliX 2's sikulixapi jar).
// OculiX is opt-in: set TAGUI_SIKULI_JAR to oculixide.jar.
$env = getenv('TAGUI_SIKULI_JAR');
if (is_string($env) && trim($env) !== '') return trim($env);
return 'sikulix/sikulix.jar';}

static function java_major($binary) {
$output = shell_exec(escapeshellarg($binary) . ' -version 2>&1');
if (!is_string($output)) return 0;
if (preg_match('/version "1\.(\d+)/', $output, $match) === 1) return (int)$match[1];
if (preg_match('/version "(\d+)/', $output, $match) === 1) return (int)$match[1];
return 0;}

static function launch_pair() {
$jar = self::jar();
$java = self::java_binary();
if (stripos($jar, 'oculix') !== false && self::java_major($java) < 17) {
throw new RuntimeException('OculiX requires Java 17+; set PATH/JAVA_HOME or TAGUI_JAVA');}
return array($java, $jar);}

static function java_binary() {
$chosen = getenv('TAGUI_JAVA');
if (is_string($chosen) && trim($chosen) !== '') return trim($chosen);
$home = getenv('JAVA_HOME');
if (is_string($home) && trim($home) !== '') {
$name = DIRECTORY_SEPARATOR === '\\' ? 'java.exe' : 'java';
$candidate = rtrim($home, "/\\") . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $name;
if (is_file($candidate)) return $candidate;}
return 'java';}

static function run($line, $timeout) {
self::start($timeout);
self::$count++;
$src = dirname(__FILE__);
$in = $src . '/tagui.sikuli/tagui_sikuli.in';
$out = $src . '/tagui.sikuli/tagui_sikuli.out';
@unlink($src . '/tagui.sikuli/tagui_sikuli.txt');
file_put_contents($in, '[' . self::$count . '] ' . $line);
$marker = '[' . self::$count . '] ';
$deadline = microtime(true) + max(5, (float)$timeout);
while (microtime(true) < $deadline) {
$text = is_file($out) ? (string)file_get_contents($out) : '';
if (str_contains($text, $marker)) {
self::$last_result = trim(substr($text, strpos($text, $marker) + strlen($marker)));
return str_contains(self::$last_result, 'SUCCESS');}
if (!self::alive()) throw new RuntimeException(self::step_crash(self::log_path()));
usleep(200000);}
throw new RuntimeException('visual step timed out waiting for OculiX');}

static function last_result() {return self::$last_result;}

static function alive() {
if (!is_resource(self::$process)) return false;
$status = proc_get_status(self::$process);
if (is_array($status) && !empty($status['running'])) return true;
proc_close(self::$process);
self::$process = null;
self::$count = 0;
return false;}

static function start($timeout) {
if (self::alive()) return;
$pair = self::launch_pair();
$java = $pair[0];
$jar = $pair[1];
$src = dirname(__FILE__);
$log = self::log_path();
@unlink($src . '/tagui.sikuli/tagui_sikuli.out');
file_put_contents($log, '');
$descriptor = array(
0 => array('file', DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null', 'r'),
1 => array('file', $log, 'a'),
2 => array('file', $log, 'a'));
$process = proc_open(array($java, '-jar', $jar, '-r', $src . '/tagui.sikuli'), $descriptor, $pipes, $src);
if (!is_resource($process)) throw new RuntimeException('cannot start ' . $java . ' -jar ' . $jar . '. Set PATH, JAVA_HOME, or TAGUI_JAVA.');
self::$process = $process;
self::$count = 0;
$deadline = microtime(true) + max(90, (float)$timeout);
$out = $src . '/tagui.sikuli/tagui_sikuli.out';
while (microtime(true) < $deadline) {
if (is_file($out) && str_contains((string)file_get_contents($out), '[0] START')) return;
if (!self::alive()) throw new RuntimeException(self::startup_failure($log));
usleep(200000);}
$ready = self::failure_message('OculiX did not become ready.', $log);
if (!str_contains($ready, 'Java is a headless build') && !str_contains($ready, 'macOS blocked')) {
$ready = str_replace('OculiX did not become ready.', 'OculiX did not become ready. A display is required. On Linux install the full JRE (not the openjdk-*-jre-headless package) and set DISPLAY to an X11 session (Xvfb is enough; Wayland is untested).', $ready);}
throw new RuntimeException($ready);}

static function text() {
$path = dirname(__FILE__) . '/tagui.sikuli/tagui_sikuli.txt';
if (!is_file($path)) return '';
return trim((string)file_get_contents($path));}

static function stop() {
if (!is_resource(self::$process)) return;
$in = dirname(__FILE__) . '/tagui.sikuli/tagui_sikuli.in';
@file_put_contents($in, 'finish');
usleep(300000);
proc_terminate(self::$process);
proc_close(self::$process);
self::$process = null;
self::$count = 0;}}

if (PHP_SAPI === 'cli' && isset($argv[1]) && $argv[1] === '--sikuli-launch') {
try {
$pair = TaguiSikuli::launch_pair();
echo $pair[0], "\n", $pair[1], "\n";
exit(0);}
catch (Exception $e) {
echo $e->getMessage(), "\n";
exit(1);}}
