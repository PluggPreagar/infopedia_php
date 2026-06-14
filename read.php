<?php

$type = "entry";
require 'util.php';
require_once 'util_entry.php';
require_once 'util_file.php';

$cacheTime = isset($_GET['force_update']) ? 0 : ($config['cache_time'] ?? 3600);
$googleSheetId = $config['googleSheetId'] ?? 'YOUR_GOOGLE_SHEET_ID_ENTRIES';
$googleSheetGridId = $config['googleSheetGridId'] ?? '0';
$googleSheetUrl = "https://docs.google.com/spreadsheets/d/{$googleSheetId}/export?format=csv&gid={$googleSheetGridId}";
$cacheFile = $config['cacheFile'];
$cacheOutdatedFile = $config['cacheOutdatedFile'] ?? null;
$cacheTimeDelay = $config['cache_time_delay'] ?? 10;

header('Content-Type: text/csv');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$cacheOutdated = $cacheOutdatedFile
    && file_exists($cacheOutdatedFile)
    && file_exists($cacheFile)
    && filemtime($cacheOutdatedFile) > filemtime($cacheFile) + $cacheTimeDelay;

if (isCacheValid($cacheFile, $cacheTime) && !$cacheOutdated) {
    $cachedResponse = readCache($cacheFile);
    echo $cachedResponse;
    log_return(strlen($cachedResponse) . " bytes from cache");
    exit;
}

$response = @file_get_contents($googleSheetUrl);
if ($response === false) {
    http_response_code(500);
    log_warn("Failed to fetch Google Sheet: " . $googleSheetUrl);
    exit;
}

$response = sortAndDeduplicateCsv($response);
writeCache($cacheFile, $response);

echo $response;
log_return(strlen($response) . " bytes fetched+cached");

?>
