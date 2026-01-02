<?php
 include_once 'util.php';

log_info("upload config: " . print_r($config, true) );


// https://fayf.info/entry/add?sid=tst&tid=tenant1&entry=This%20is%20a%20wrong-formatted%20entry
// https://fayf.info/entry/add?sid=tst&tid=tenant1&entry=/_/check%20|%20tstKey%20|%20tstValue

// forward data to google formular
$googlePostUrl = $config['googlePostUrl'] ?? 'https://docs.google.com/forms/d/YOUR_GOOGLE_FORM_ID/formResponse'; // Replace with your Google Form ID
$googlePostEntryId = $config['googlePostEntryId'] ?? 'entry.1234567890'; // Replace with your Google Form entry ID
// get data from POST request - unencoded
$googlePostEntryId_ = str_replace('.', '_', $googlePostEntryId); // Replace '.' with '_' in the entry ID for compatibility
// get POST or GET param with name of "entry", $googlePostEntryId, $googlePostEntryId_
$data = $_POST['entry'] ?? $_GET['entry']
    ?? $_POST[$googlePostEntryId] ?? $_GET[$googlePostEntryId]
    ?? $_POST[$googlePostEntryId_] ?? $_GET[$googlePostEntryId_]
    ?? $_POST['dump'] ?? $_GET['dump']
    ?? $_POST['log'] ?? $_GET['log']
    ?? '';
$dataIsLog = ( $_POST['log'] ?? $_GET['log'] ?? '' ) != '' ; // suppress info log if data is log - to much details ...
$cacheOutdatedFile = $config['cacheOutdatedFile']; // Path to the cache file
$cacheFile = $config['cacheFile']; // Path to the cache file
// $dryRun = true;
//$googlePostEntryId = 'entry.1234567890';
//$data="tett";
$url = "{$googlePostUrl}" ; // "?{$googlePostEntryId}=".urlencode($data); // Construct the Google Sheet export URL
// dump config

// use local file for tenant specific data
if ($tenant_id == '') {

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
        log_error("Error sending POST request: " . $error['message']);
    } else {
        echo "Response: " . $response;
        $responseCode = $http_response_header[0] ?? 'No response code';
        if (!$dataIsLog) {
            log_info("uploaded : " . $responseCode . " " . $url  );
        }

    }

} else {
    // use local file for tenant specific data

    // modify cache file and google sheet url to include tenant id
    $cacheOutdatedFile = str_replace('.cache', "_{$tenant_id}.cache", $cacheOutdatedFile);
    $googleSheetUrl = str_replace('.cache', "_{$tenant_id}.csv", $cacheFile); // local file for tenant specific data

    // check if cache file exists
    if (file_exists($googleSheetUrl)
            || ($config['tenantAutoCreationEnabled'] ?? false)) {
        // behave like google sheet - prefix timestamp, delimiter "," and quote data (if needed)
        $timestamp = date('Y-m-d H:i:s');
        // check if data contains comma or quote or newline
        if (strpos($data, ',') !== false || strpos($data, '"') !== false || strpos($data, "\n") !== false || strpos($data, "\r") !== false) {
            // escape quotes by doubling them
            $data_escaped = str_replace('"', '""', $data);
            $data = '"' . $data_escaped . '"';
        }
        $data = $timestamp . ',' . $data;
        // append line break if not present
        if (substr($data, -1) !== "\n") {
            $data .= "\n";
        }
        if (!file_exists($googleSheetUrl)) {
            log_warn("creating tenant: " . $googleSheetUrl  );
            @file_put_contents($googleSheetUrl, "Timestamp,data\n");
        }
        // append data to local file
        @file_put_contents($googleSheetUrl, $data, FILE_APPEND);
        $response = $data;

        if (!$dataIsLog) {
            log_info("saved to tenant: " . $googleSheetUrl  );
        }
    } else {
        log_warn("SKIPP unknown tenant: " . $googleSheetUrl  );
    }
}


// touch cache file to signal update
if (file_exists($cacheOutdatedFile)) {
    touch($cacheOutdatedFile);
}
if (!$dataIsLog) {
    log_return( strlen($response ?? "") . " bytes saved ( " . $data . ")"  );
}

?>