<?php
$type = "upload";
include_once 'util.php';
require_once 'util_file.php';

// forward data to google formular
$googlePostUrl = $config['googlePostUrl'] ?? 'https://docs.google.com/forms/d/YOUR_GOOGLE_FORM_ID/formResponse'; // Replace with your Google Form ID
$googlePostEntryId = $config['googlePostEntryId'] ?? 'entry.1234567890'; // Replace with your Google Form entry ID
// get data from POST request - unencoded
$googlePostEntryId_ = str_replace('.', '_', $googlePostEntryId); // Replace '.' with '_' in the entry ID for compatibility
// get POST or GET param with name of "entry", $googlePostEntryId, $googlePostEntryId_
$data = $_POST['entry'] ?? $_GET['entry']
    ?? $_POST[$googlePostEntryId] ?? $_GET[$googlePostEntryId]
    ?? $_POST[$googlePostEntryId_] ?? $_GET[$googlePostEntryId_]
    ?? '';
$rawLog = $config['rawLog'] ?? 'data/upload_raw.log';
appendRaw($rawLog, date('Y-m-d H:i:s') . ',' . $data);
$cacheFile = $config['cacheFile']; // Path to the cache file
$cacheOutdatedFile = $config['cacheOutdatedFile']; // Path to the cache file
$dryRun = isset($config['dryRun']) && $config['dryRun'] == 'true'; // Check if dry run is requested
// $dryRun = true;
//$googlePostEntryId = 'entry.1234567890';
//$data="tett";

$url = "{$googlePostUrl}" ; // "?{$googlePostEntryId}=".urlencode($data); // Construct the Google Sheet export URL


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

// touch cache file to signal update
if (file_exists($cacheOutdatedFile)) {
    touch($cacheOutdatedFile);
}
log_return(strlen($data) . " bytes queued (" . $data . ")");

?>