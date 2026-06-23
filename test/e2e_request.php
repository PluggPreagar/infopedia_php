<?php
// Subprocess entry point — called by e2e.php for each simulated request.
// Usage: php test/e2e_request.php METHOD PATH QUERY_STRING POST_BODY
//
// Simulates the PHP SAPI environment, includes the matching route file,
// and wraps its output with a "STATUS:xxx\n" prefix line so the caller
// can read the HTTP status code without a real HTTP server.

chdir(__DIR__ . '/..');   // project root — all relative paths in route files resolve correctly

[$_, $method, $path, $qs, $body] = array_pad($argv, 5, '');

// Populate superglobals the route files read
$_SERVER['REQUEST_METHOD'] = strtoupper($method);
$_SERVER['REQUEST_URI']    = $path . ($qs ? "?$qs" : '');
$_SERVER['QUERY_STRING']   = $qs;
parse_str($qs,   $_GET);
parse_str($body, $_POST);

// header() is a no-op in CLI — that's fine, we only care about the status code.
// Capture status via http_response_code(); default 200 matches PHP's own default.

ob_start(function (string $buf): string {
    return 'STATUS:' . (http_response_code() ?: 200) . "\n" . $buf;
});

$file = e2e_route($path);
if ($file === null) {
    http_response_code(404);
    echo json_encode(['error' => ['code' => 'NOT_FOUND', 'message' => "No route for $path"]]);
    exit;
}

require $file;   // route file calls http_response_code(), echo, exit — all captured above

// ── Route table ──────────────────────────────────────────────────────────────

function e2e_route(string $path): ?string {
    if (str_starts_with($path, '/files/')) {
        $_GET['file'] = substr($path, strlen('/files/'));
        return 'files.php';
    }
    return [
        '/'           => 'index.php',
        '/entries'    => 'entries.php',
        '/votes'      => 'votes.php',
        '/dumps'      => 'dumps.php',
        '/health'     => 'health.php',
        '/stats'      => 'statistic.php',
        '/issue'      => 'issue.php',
        'issue.php'   => 'issue.php',
        'entries.php' => 'entries.php',
    ][$path] ?? null;
}
