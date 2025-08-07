<?php
$configFile = 'infopedia.cfg';
$type = $_GET['type'];
if (file_exists($configFile)) {
    $configGeneral = parse_ini_file($configFile, true); // Enable section parsing
    if ($configGeneral === false) {
        die("Failed to parse configuration file.");
    }

    // Check if the 'general' and 'votes' sections exist
    $config = [];
    if (isset($configGeneral['general'])) {
        $config = $configGeneral['general'];
    }
    if (isset($configGeneral[$type])) {
        $config = array_merge($config, $configGeneral[$type]);
    }
} else {
    die("Configuration file not found.");
}

// Cache settings
$cacheTime = isset($_GET['force_update']) ? 0 : $config['cache_time'] ?? 3600; // Default to 1 hour if not set
$googleSheetId = $config['googleSheetId'] ?? 'YOUR_GOOGLE_SHEET_ID_ENTRIES'; // Replace with your Google Sheet ID for entries
$googleSheetGridId = $config['googleSheetGridId'] ?? '0'; // Default to 0 if not set
$googleSheetUrl = "https://docs.google.com/spreadsheets/d/{$googleSheetId}/export?format=csv&gid={$googleSheetGridId}"; // Construct the Google Sheet export URL
$cacheFile = $config['cacheFile']; // Path to the cache file
$dryRun = isset($config['dryRun']) && $config['dryRun'] ; // Check if dry run is requested
#$dryRun = true;
if ($dryRun) {
    // output googleSheetUrl
    echo "type: " . $type . "<br>";
    echo "Google Sheet URL: " . $googleSheetUrl . "<br>";
    exit;
}

// Proxy script to fetch and serve Google Sheet content with caching
header('Content-Type: text/csv');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Check if the cache is valid
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
    // Serve the cached file
    readfile($cacheFile);
    exit;
}

// Fetch the content from the Google Sheet
$response = @file_get_contents($googleSheetUrl);

if ($response === false) {
    http_response_code(500);
    echo "Failed to fetch Google Sheet data.";
    exit;
}

// Save the content to the cache file
file_put_contents($cacheFile, $response);

// Output the content
echo $response;
?>
