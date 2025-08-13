<?php
// log current call to log file
// Log the current call to a log file
$logFile = 'calls.log'; // Define the log file
$logMessage = date('Y-m-d H:i:s') . " - Request URI: " . $_SERVER['REQUEST_URI'] . "\n";
$logMessage .= "Method: " . $_SERVER['REQUEST_METHOD'] . "\n";

// Log GET parameters if available
if (!empty($_GET)) {
    $logMessage .= "GET Parameters: " . json_encode($_GET) . "\n";
}

// Log POST data if available
if (!empty($_POST)) {
    $logMessage .= "POST Data: " . json_encode($_POST) . "\n";
}

// Append the log message to the file
file_put_contents($logFile, $logMessage, FILE_APPEND);




// start -----

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
$googlePostEntryId_ = str_replace('.', '_', $googlePostEntryId); // Replace '.' with '_' in the entry ID for compatibility
$data = isset($_POST[$googlePostEntryId_]) ? $_POST[$googlePostEntryId_] : ( isset($_GET[$googlePostEntryId_]) ? $_GET[$googlePostEntryId_] : '' ); // Get data from POST request
$cacheFile = $config['cacheFile']; // Path to the cache file
$dryRun = isset($config['dryRun']) && $config['dryRun'] == 'true'; // Check if dry run is requested
// $dryRun = true;
//$googlePostEntryId = 'entry.1234567890';
//$data="tett";

$url = "{$googlePostUrl}" ; // "?{$googlePostEntryId}=".urlencode($data); // Construct the Google Sheet export URL

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
        'content' => http_build_query([$googlePostEntryId => $data]),
    ],
];
$context  = stream_context_create($options);
//file_put_contents($logFile, print_r($options, true), FILE_APPEND);
$response = @file_get_contents($url, false, $context);
if ($response === false) {
    $error = error_get_last();
    echo "Error sending POST request: " . $error['message'];
} else {
    echo "Response: " . $response;
}

?>