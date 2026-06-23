<?php
$type = 'issue';
require_once 'util.php';
require_once 'util_http.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('METHOD_NOT_ALLOWED', 'Only POST accepted', 405);
}

$report = $_POST['report'] ?? '';
if (trim($report) === '') {
    respond_error('INVALID_ENTRY', 'report must not be empty', 400);
}

if (strlen($report) > 65536) {
    respond_error('PAYLOAD_TOO_LARGE', 'report exceeds 64 KB limit', 413);
}

$issueDir = $config['issueDir'] ?? 'data/issues';
$filename = $_POST['filename'] ?? '';

if ($filename !== '') {
    $base   = realpath($issueDir);
    $target = realpath($issueDir . '/' . $filename);
    if ($base === false || $target === false
        || !str_starts_with($target, $base . '/')
        || !is_file($target)) {
        respond_error('NOT_FOUND', 'Issue not found', 404);
    }
    if (file_put_contents($target, $report) === false) {
        respond_error('WRITE_ERROR', 'Could not save report', 500);
    }
    log_return('issue updated (' . strlen($report) . ' bytes)');
    respond_json(['status' => 'ok'], 200);
} else {
    $issueDirNew = $issueDir . '/new';
    if (!is_dir($issueDirNew)) {
        @mkdir($issueDirNew, 0755, true);
        if (!is_dir($issueDirNew)) {
            respond_error('WRITE_ERROR', 'cannot create issue directory', 500);
        }
    }
    $newFile = $issueDirNew . '/' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.md';
    if (file_put_contents($newFile, $report) === false) {
        respond_error('WRITE_ERROR', 'Could not save report', 500);
    }
    log_return('issue saved (' . strlen($report) . ' bytes)');
    respond_json(['status' => 'ok'], 201);
}
