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

$issueDir = $config['issueDir'] ?? 'data/issues';
if (!is_dir($issueDir)) mkdir($issueDir, 0755, true);

$filename = $issueDir . '/' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.txt';
if (file_put_contents($filename, $report) === false) {
    respond_error('WRITE_ERROR', 'Could not save report', 500);
}

log_return('issue saved (' . strlen($report) . ' bytes)');
respond_json(['status' => 'ok'], 201);
