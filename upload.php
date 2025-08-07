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

// forward data to google formular
$googlePostUrl = $config['googlePostUrl'] ?? 'https://docs.google.com/forms/d/YOUR_GOOGLE_FORM_ID/formResponse'; // Replace with your Google Form ID
$googlePostEntryId = $config['googlePostEntryId'] ?? 'entry.1234567890'; // Replace with your Google Form entry ID
// get data from POST request - unencoded
$data = isset($_POST['data']) ? $_POST['data'] : ''; // Get data from POST request
$cacheFile = $config['cacheFile']; // Path to the cache file
$dryRun = isset($config['dryRun']) && $config['dryRun'] == 'true'; // Check if dry run is requested
// $dryRun = true;

$url = "{$googlePostUrl}?{$googlePostEntryId}=" . urlencode($_GET['data'] ?? $_POST['data']); // Construct the Google Sheet export URL

if ($dryRun) {
    // output googleSheetUrl
    echo "type: " . $type . "<br>";
    echo "Google Sheet URL: " . $url . "<br>";
    exit;
}

// send data to url via POST request
$options = [
    'http' => [
        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
        'method'  => 'POST',
        'content' => http_build_query(['data' => $data]),
    ],
];
$context  = stream_context_create($options);
$response = @file_get_contents($url, false, $context);
if ($response === false) {
    $error = error_get_last();
    echo "Error sending POST request: " . $error['message'];
} else {
    echo "Response: " . $response;
}

?>