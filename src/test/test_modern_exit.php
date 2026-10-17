<?php

/* Modern engine exit status and local START timestamp.
   A failed flow step exits 1. A finished flow exits 0.
   The Casper launcher is unchanged and still exits 0 after a step error. */

$work = sys_get_temp_dir() . '/tagui_exit_' . getmypid();
mkdir($work);
$ok_flow = $work . '/ok_flow';
$bad_flow = $work . '/bad_flow';
file_put_contents($ok_flow, "echo \"ready\"\n");
file_put_contents($bad_flow, "echo 'a' \"b\"\n");
$engine = dirname(__DIR__) . '/tagui_modern.php';
$failures = 0;

function check($label, $ok, $detail) {
global $failures;
if ($ok) {fwrite(STDOUT, "ok   $label\n"); return;}
$failures++;
fwrite(STDERR, "FAIL $label\n$detail\n");}

function run_engine($engine, $flow) {
$env = getenv();
if (!is_array($env)) $env = array();
$env['TZ'] = 'Pacific/Auckland';
$env['TAGUI_CHROME_ISOLATED'] = '1';
$spec = array(
0 => array('file', '/dev/null', 'r'),
1 => array('pipe', 'w'),
2 => array('pipe', 'w'));
$process = proc_open(array(PHP_BINARY, '-q', $engine, $flow), $spec, $pipes, null, $env);
if (!is_resource($process)) return array(127, '', 'cannot start');
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($process);
return array($code, (string)$stdout, (string)$stderr);}

list($ok_code, $ok_out, $ok_err) = run_engine($engine, $ok_flow);
check('success exit', $ok_code === 0, "code $ok_code\n$ok_out\n$ok_err");
check('success echo', str_contains($ok_out, 'ready'), $ok_out);
$stamp = '';
if (preg_match('/START - automation started - (.+)/', $ok_out, $match) === 1) $stamp = trim($match[1]);
$parsed = DateTimeImmutable::createFromFormat('D M j Y H:i:s', $stamp, new DateTimeZone('Pacific/Auckland'));
$delta = $parsed instanceof DateTimeImmutable ? abs($parsed->getTimestamp() - time()) : 99999;
check('local start', $delta < 60, $stamp . ' delta ' . $delta);

list($bad_code, $bad_out, $bad_err) = run_engine($engine, $bad_flow);
check('failure exit', $bad_code === 1, "code $bad_code\n$bad_out\n$bad_err");
check('failure text', str_contains($bad_out, 'ERROR -'), $bad_out);

@unlink($ok_flow);
@unlink($bad_flow);
@unlink($ok_flow . '.log');
@unlink($bad_flow . '.log');
@rmdir($work);
if ($failures > 0) exit(1);
fwrite(STDOUT, "exit checks passed\n");
