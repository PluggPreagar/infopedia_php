<?php
$type = 'files';
require_once 'util.php';
require_once 'util_http.php';

$filename = $_GET['file'] ?? '';

$allowed = $config['allowedDownloadFiles'] ?? [];
if (!in_array($filename, $allowed, true)) {
    log_error("File not allowed: $filename");
    respond_error('NOT_FOUND', "File not available: $filename", 404);
}

if (!file_exists($filename)) {
    log_error("File not found on disk: $filename");
    respond_error('NOT_FOUND', "File not available: $filename", 404);
}

$mime = match (pathinfo($filename, PATHINFO_EXTENSION)) {
    'apk'  => 'application/vnd.android.package-archive',
    'pdf'  => 'application/pdf',
    'aab'  => 'application/x-authorware-bin',
    default => 'application/octet-stream',
};

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
header('Content-Length: ' . filesize($filename));
readfile($filename);
log_return("file=$filename");
exit;
