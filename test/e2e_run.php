<?php
// Thin wrapper around e2e_request.php — outputs only the response body.
// Used by justfile for manual interactive commands.
// Usage: php test/e2e_run.php METHOD PATH [QUERY_STRING] [BODY]

[$_, $method, $path, $qs, $body] = array_pad($argv, 5, '');

$proc = proc_open(
    [PHP_BINARY, __DIR__ . '/e2e_request.php', $method, $path, $qs ?? '', $body ?? ''],
    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
    $pipes
);
fclose($pipes[0]);
$raw    = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);

// Parse status + body
$nl     = strpos($raw, "\n");
$status = $nl !== false ? substr($raw, 0, $nl) : 'STATUS:?';
$body_s = $nl !== false ? substr($raw, $nl + 1) : $raw;

// Debug header: request line + status, then full body
echo "> $method $path" . ($qs ? "?$qs" : '') . ($body ? " [$body]" : '') . "\n";
echo "< $status\n";
if ($stderr) fwrite(STDERR, $stderr);

$lines = explode("\n", rtrim($body_s));
foreach (array_slice($lines, 0, 5) as $line) echo "  $line\n";
if (count($lines) > 5) echo "  ... (" . (count($lines) - 5) . " more lines)\n";
