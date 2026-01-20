<?php
    $type="download";
    include_once 'util.php';

    // get file to download from GET or POST param "file"
    $downloadFile = $_POST['file'] ?? $_GET['file'] ?? '';
    // check file is within allowed files
    $allowedFiles = $config['allowedDownloadFiles'] ?? [];
    $debug=true;
    if (!in_array($downloadFile, $allowedFiles)) {
        //log_debug("Allowed files: " . implode(", ", $allowedFiles));
        log_error("File not allowed: " . $downloadFile);
        die("Download of file restricted.");
    }

    // check file exists
    if (!file_exists($downloadFile)) {
        log_error("File not found: " . $downloadFile);
        die("File not found.");
    }

    // return file content as download - apk or pdf
    $fileInfo = pathinfo($downloadFile);
    $fileExt = strtolower($fileInfo['extension'] ?? '');
    $mimeTypes = [
        'apk' => 'application/vnd.android.package-archive',
        'pdf' => 'application/pdf',
        'zip' => 'application/zip',
        'txt' => 'text/plain',
        // add more mime types as needed
    ];
    $mimeType = $mimeTypes[$fileExt] ?? 'application/octet-stream';
    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mimeType);
    header('Content-Disposition: attachment; filename="' . basename($downloadFile) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($downloadFile));
    readfile($downloadFile);
    $data = "file=" . $downloadFile;

    log_return("downloaded: " . $data . ""  );

?>