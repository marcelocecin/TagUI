<?php

/* Enter in a password field must fire a trusted form submit.
   The keyDown carries text and unmodifiedText "\r", the same text
   Puppeteer sends. Without it Chrome skips implicit submission. */

$root = dirname(__DIR__, 2);
$tagui = $root . '/src/tagui';
$work = sys_get_temp_dir() . '/tagui_modern_enter_' . getmypid();
mkdir($work);
$html = $work . '/login.html';
file_put_contents($html, <<<'HTML'
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>login</title></head>
<body>
<form id="login">
<label>Username <input id="username" name="username" type="text" autocomplete="username"></label>
<label>Password <input id="password" name="password" type="password" autocomplete="current-password"></label>
<button type="submit" id="signin">Sign in</button>
</form>
<script>
document.getElementById('login').addEventListener('submit', function (event) {
  event.preventDefault();
  var password = document.getElementById('password').value;
  document.title = (event.isTrusted ? 'trusted' : 'untrusted') + ':' + password;
});
</script>
</body>
</html>
HTML);
$flow = $work . '/login_flow';
file_put_contents($flow, implode("\n", array(
'file://' . $html,
'type #username as alice',
'type #password as x[enter]',
'echo `title()`',
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
check('trusted submit', preg_match('/^trusted:x$/m', $stdout) === 1, $stdout . $stderr);
@unlink($html);
@unlink($flow);
@rmdir($work);
if ($failures > 0) exit(1);
fwrite(STDOUT, "enter submit checks passed\n");
