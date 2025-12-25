<?php
    $type = "web";
    include_once 'util.php';

$useReadPhp = isset($config['useReadPhp']) ? $config['useReadPhp'] : false; // Default to false if not set
$cacheTime = isset($_GET['force_update']) ? 0 : $config['cache_time'] ?? 3600; // Default to 1 hour if not set
$googleSheetUrl = $config['googleSheetUrl'] ?? '...';
$cacheFile = $config['cacheFile'] ?? 'sheet.cache'; // Default to 'sheet.cache' if not set
$topic = isset($_GET['topic']) ? $_GET['topic'] : ''; // Default to empty if not set

$response = @file_get_contents("infopedia.html");
if ($response === false) {
    log_error("Failed to load infopedia.html");
    die("Error loading page.");
}
echo $response;

?>
