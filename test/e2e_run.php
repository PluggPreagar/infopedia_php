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

// Strip "STATUS:xxx\n" prefix — print body only
$nl = strpos($raw, "\n");
echo $nl !== false ? substr($raw, $nl + 1) : $raw;
if ($stderr) fwrite(STDERR, $stderr);
