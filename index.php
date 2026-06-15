<?php
    $type = "web";
    include_once 'util.php';

$useReadPhp = isset($config['useReadPhp']) ? $config['useReadPhp'] : false; // Default to false if not set
$cacheTime = isset($_GET['force_update']) ? 0 : $config['cache_time'] ?? 3600; // Default to 1 hour if not set
$googleSheetUrl = $config['googleSheetUrl'] ?? '...';
$cacheFile = $config['cacheFile'] ?? 'sheet.cache'; // Default to 'sheet.cache' if not set
$topic = isset($_GET['topic']) ? $_GET['topic'] : ''; // Default to empty if not set

$filePath = 'infopedia.html';
$response = @file_get_contents($filePath);
if ($response === false) {
    log_error("Failed to load infopedia.html");
    die("Error loading page.");
}

$currentTimestamp = date("Y-m-d H:i:s");
if (file_exists($filePath)) {
    $fileTimestamp = date("Y-m-d H:i:s", filemtime($filePath));
} else {
    $fileTimestamp = "";
}
$response = str_replace("<!-- timestamp -->", "created: $currentTimestamp / data updated: $fileTimestamp / ", $response);

echo $response;

?>
