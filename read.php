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
//    timestamp, topic | node | message indicator
// extract the second column
// split second column it by topic + node, message, indicator
function sortCsvData($csvData) {
    // handle dos line endings
    $csvData = str_replace("\r\n", "\n", $csvData); // Convert Windows line endings to Unix
    // Split the CSV data into rows
    // !! will not handle multiline as quote starts "within a column"
    $lines = explode("\n", $csvData);

    // aggregate data by topic and node
    $aggregatedData = [];
    $line_wrapped = "";
    $line_id = 0;
    foreach ($lines as $line_) {
        //echo "Processing line: $line<br>\n"; // Debugging output
        // aggregate (wrapped) lines until we have an even number of quotes
        $line = $line_wrapped . $line_;
        $line_id++;
        if (substr_count($line, '"') % 2 != 0
                    // allow \" as zoll - only check if necessary
                    // && substr_count( str_replace( "\"","", $line)  , '"') % 2 != 0
                ) {
            // odd number of quotes, line is wrapped
            $line_wrapped = $line . "\n";
        } else {
            // even number of quotes, line is complete
            $line_wrapped = "";
            // Split the line by semicolon, only 2 parts are expected
            $parts = str_getcsv($line);
            if (count($parts) >= 2) {
                // split second part by " | "
                $secondParts = explode(' | ', $parts[1]);
                if (count($secondParts) < 2) {
                    continue; // Skip lines that do not have at least 2 parts in the second column
                }
                $key = $secondParts[0] . ' | ' . $secondParts[1]; // Create a key from topic and node
                // if indicator is "--" then delete it
                // get last char of message as indicator
                // check if last char is  "-"
                if ( strlen($line) > 0 && ( substr($line, -2) === '--') || substr($line, -2) === '--"') {
                    // reset array entry with current key
                    // echo substr($line, -2)."--".$line."<br>\n"; // Debugging output
                    $aggregatedData[$key] = "";
                } else {
                    // echo substr($line, -2)."++".$line."<br>\n"; // Debugging output
                    $aggregatedData[$key] = $line; // Store the line in the aggregated data
                }
            } else  {
                log_warn("Skipping malformed line[". $line_id ."]: $line");
            }
        } // line wrapped ?
    }

    // sort aggregatedData by key
    ksort($aggregatedData);

    // reconstruct csv from aggregatedData
    $sortedCsv = $header . "\n";
    foreach ($aggregatedData as $key => $line) {
        if ($line === "") {
            continue; // Skip empty lines
        }
        $sortedCsv .= $line . "\n";
    }

    return trim($sortedCsv);
}


// Proxy script to fetch and serve Google Sheet content with caching
header('Content-Type: text/csv');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// if cacheOutdatedFile exists and is newer than cacheFile then delete cacheFile
$cacheOutdated= false;
$cacheOutdatedFile = $config['cacheOutdatedFile'] ?? null;
$cache_time_min = $config['cache_time_min'] ?? 10; // default 60 seconds
$cacheOutdated = $cacheOutdatedFile
    && file_exists($cacheOutdatedFile) && file_exists($cacheFile)
    && (filemtime($cacheOutdatedFile) > filemtime($cacheFile) + $cache_time_min) ;

// Check if the cache is valid
if (file_exists($cacheFile)
            && (time() - filemtime($cacheFile)) < $cacheTime
            && !$cacheOutdated
        ) {
    // serve the cached file
    readfile($cacheFile);
    // get file size from cache file
    log_return( filesize($cacheFile) . " bytes from cache" );
    exit;
}

// Fetch the content from the Google Sheet
$response = @file_get_contents($googleSheetUrl);

if ($response === false) {
    http_response_code(500);
    log_warn("Failed to fetch Google Sheet");
    exit;
}

$response = sortCsvData($response); // Sort and uniq the CSV data

// Save the content to the cache file
file_put_contents($cacheFile, $response);

// Output the content
echo $response;

log_return( strlen($response) . " bytes" );

?>
