<?php

require 'util.php';

// Cache settings
$cacheTime = isset($_GET['force_update']) ? 0 : $config['cache_time'] ?? 3600; // Default to 1 hour if not set
$googleSheetId = $config['googleSheetId'] ?? 'YOUR_GOOGLE_SHEET_ID_ENTRIES'; // Replace with your Google Sheet ID for entries
$googleSheetGridId = $config['googleSheetGridId'] ?? '0'; // Default to 0 if not set
$googleSheetUrl = "https://docs.google.com/spreadsheets/d/{$googleSheetId}/export?format=csv&gid={$googleSheetGridId}"; // Construct the Google Sheet export URL
$cacheFile = $config['cacheFile']; // Path to the cache file
$dryRun = isset($config['dryRun']) && $config['dryRun'] ; // Check if dry run is requested
#$dryRun = true;


// function to sort csv data
//    timestamp; topic | node | message indicator
// extract the second column
// split second column it by topic + node, message, indicator
function sortCsvData($csvData) {
    $data = preg_split('/\r\n|\r|\n/', $csvData);
    $header = array_shift($data); // Remove the header line

    // sort data - as string
    usort($data, function($a, $b) {
        return strcmp($a, $b);
    });

    // aggregate data by topic and node
    $aggregatedData = [];
    foreach ($data as $line) {
        //echo "Processing line: $line<br>\n"; // Debugging output
        // Split the line by semicolon, only 2 parts are expected
        $parts = str_getcsv($line);
        if (count($parts) < 2) {
            continue; // Skip lines that do not have at least 2 parts
        }
        // split second part by " | "
        $secondParts = explode(' | ', $parts[1]);
        if (count($secondParts) < 2) {
            continue; // Skip lines that do not have at least 2 parts in the second column
        }
        $key = $secondParts[0] . ' | ' . $secondParts[1]; // Create a key from topic and node
        // if indicator is "--" then delete it
        // get last char of message as indicator
        // check if last char is  "-"
        if ( strlen($line) > 0 && substr($line, -2) === '--') {
            // reset array entry with current key
            // echo substr($line, -2)."--".$line."<br>\n"; // Debugging output
            $aggregatedData[$key] = "";
        } else {
            // echo substr($line, -2)."++".$line."<br>\n"; // Debugging output
            $aggregatedData[$key] = $line; // Store the line in the aggregated data
        }
    }

    // sort aggregatedData by key
    ksort($aggregatedData);

    // reconstruct csv from aggregatedData
    $sortedCsv = $header . "\n";
    foreach ($aggregatedData as $key => $line) {
        $sortedCsv .= $line . "\n";
    }

    return trim($sortedCsv);
}




// Proxy script to fetch and serve Google Sheet content with caching
header('Content-Type: text/csv');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');


// Check if the cache is valid

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
    // serve the cached file
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

$response = sortCsvData($response); // Sort and uniq the CSV data

// Save the content to the cache file
file_put_contents($cacheFile, $response);

// Output the content
echo $response;
?>
