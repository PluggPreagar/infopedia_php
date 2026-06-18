<?php
/**
 * Generic HTML wrapper.
 *
 * ?test=<file.html>   — serves file.html with test/harness.js + test/<base>_test.js injected
 * ?trace=<file.html>  — serves file.html with test/tracer.js injected; auto-discovers user-defined fns
 *
 * Example:
 *   http://localhost:8080/wrapper.php?test=app2.html
 *   http://localhost:8080/wrapper.php?trace=app2.html
 */

function send_error(int $code, string $msg): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

// ── Resolve target file ───────────────────────────────────────────────────────
$mode   = null;
$target = null;

if (isset($_GET['test']))  { $mode = 'test';  $target = $_GET['test']; }
elseif (isset($_GET['trace'])) { $mode = 'trace'; $target = $_GET['trace']; }
else {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
    <pre>
Usage:
  ?test=&lt;file.html&gt;    serve with test harness + test cases injected
  ?trace=&lt;file.html&gt;   serve with function-call tracer injected (console)

Example:
  wrapper.php?test=app2.html
  wrapper.php?trace=app2.html
    </pre>
    HTML;
    exit;
}

// Sanitise: basename only, must end in .html
$target = basename((string)$target);
if (!preg_match('/\.html$/', $target)) {
    send_error(400, "Target must be an .html file, got: $target");
}

$htmlPath = __DIR__ . '/' . $target;
if (!file_exists($htmlPath)) {
    send_error(404, "HTML file not found: $target");
}

$html = file_get_contents($htmlPath);
$base = basename($target, '.html');

// ── Build inject ──────────────────────────────────────────────────────────────
if ($mode === 'test') {
    $testFile = "test/{$base}_test.js";
    if (!file_exists(__DIR__ . '/' . $testFile)) {
        send_error(404, "Test file not found: $testFile\nCreate it to run tests for $target.");
    }
    $inject = '<script src="test/harness.js"></script>' . "\n"
            . '<script src="' . htmlspecialchars($testFile, ENT_QUOTES) . '"></script>';

} else { // trace
    // tracer.js auto-discovers user-defined window functions — no PHP extraction needed
    $inject = '<script src="test/tracer.js"></script>';
}

// ── Serve ─────────────────────────────────────────────────────────────────────
header('Content-Type: text/html; charset=utf-8');
echo str_replace('</body>', $inject . "\n</body>", $html);
