<?php

// test by calling:
//      http://fayf.info/entry
//      http://fayf.info/entry/get?sid=tst&tid=tenant1&force_update=1
//      http://fayf.info/entry/get?sid=tst&tid=tenant1&format=txt


require 'util.php';

// Cache settings
$cacheTime = isset($_GET['force_update']) ? 0 : $config['cache_time'] ?? 3600; // Default to 1 hour if not set
$googleSheetId = $config['googleSheetId'] ?? 'YOUR_GOOGLE_SHEET_ID_ENTRIES'; // Replace with your Google Sheet ID for entries
$googleSheetGridId = $config['googleSheetGridId'] ?? '0'; // Default to 0 if not set
$googleSheetUrl = "https://docs.google.com/spreadsheets/d/{$googleSheetId}/export?format=csv&gid={$googleSheetGridId}"; // Construct the Google Sheet export URL
$cacheFile = $config['cacheFile']; // Path to the cache file
$dryRun = isset($config['dryRun']) && $config['dryRun'] ; // Check if dry run is requested
$format = $_GET['format'] ?? ''; // default format is csv
#$dryRun = true;

// use local file for tenant specific data
if ($tenant_id !== '') {
    // modify cache file and google sheet url to include tenant id
    $cacheFile = str_replace('.cache', "_{$tenant_id}.cache", $cacheFile);
    $googleSheetUrl = "entries_{$tenant_id}.csv"; // local file for tenant specific data
}


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
    // first line is header
    $header = array_shift($lines);
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
            // Split the line by comma, only 2 parts are expected
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
                // ignore empty lines
                if (trim($line) !== '') {
                    log_warn("Skipping malformed line[". $line_id ."]: $line");
                }
            }
        } // line wrapped ?
    }

    // -- just to easy access most recent entry, as we reorder by key --
    //
    // keep last / most recent key of non hidden entry (not starting with "/_" )
    // search from back to front
    $mostRecentLine = "";
    foreach (array_reverse($aggregatedData, true) as $key => $line) {
        if (substr($key, 0, 2) !== '/_') {
            // construct DUMMY Most-Recent-Entry from latest Entry
            // prefix Topic with (non-reachable) "/_Most-Recent-Entry"
            $mostRecentLine = $line;
            // <timestamp>,<topic> | <node> | <message><indicator>
            // to absolute node
            // <timestamp>,/_Most-Recent-Entry | <topic><node> | <message><indicator>
            // -->
            //   1. replace first " | " with "/"
            $mostRecentLine = preg_replace('/ \| /', '/', $mostRecentLine, 1);
            $posEntryStart = strpos($mostRecentLine, ",") + 1;
            //
            // if mostRecentLine has quote after posEntryStart "," move it before "/_/menu/Most-Recent-Entry"
            //
            // 14/09/2025 07:17:33,"/1754063068 | 1757827051 | Früher auf Probe 2 - Verzichte auf Waschmaschine, Kühlschrank und Heizung."
            // 14/09/2025 07:17:33,"/_/menu/Most-Recent-Entry | /1754063068/1757827051 | Früher auf Probe 2 - Verzichte auf Waschmaschine, Kühlschrank und Heizung."
            if ($posEntryStart < 5 || $posEntryStart >= strlen($mostRecentLine)){
                // malformed / emtpy most recent line
                // try next one
            } else {
                if ($mostRecentLine[$posEntryStart] === '"') {
                    $posEntryStart++;
                }
                //   2. inject "/_Most-Recent-Entry"  after first ,-Delimiter
                $mostRecentLine = substr_replace( $mostRecentLine, "/_/menu/Most-Recent-Entry | ", $posEntryStart, 0);
                break;
            }
        }
    }

    // log $aggregatedData
    log_debug("Aggregated Data: " . print_r($aggregatedData, true) );

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
    // append most recent line at the end
    if ($mostRecentLine !== "") {
        $sortedCsv .= $mostRecentLine . "\n";
    }

    return trim($sortedCsv);
}

// return csv if format is not txt
if ($format === 'txt') {
    header('Content-Type: text/plain');
} else {

    // Proxy script to fetch and serve Google Sheet content with caching
    header('Content-Type: text/csv');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// if cacheOutdatedFile exists and is newer than cacheFile then delete cacheFile
$cacheOutdated= false;
$cacheOutdatedFile = $config['cacheOutdatedFile'] ?? null;
$cache_time_delay = $config['cache_time_delay'] ?? 10; // default 60 seconds
$cacheOutdated = $cacheOutdatedFile
    && file_exists($cacheOutdatedFile) && file_exists($cacheFile)
    && (filemtime($cacheOutdatedFile) > filemtime($cacheFile) + $cache_time_delay) ;

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

    //http_response_code(500);
    log_warn("Failed to fetch Sheet ({$googleSheetUrl})");
    http_response_code(404);
    log_return( "404 - no data found" );

} else {

    if ($response === '') {
        log_warn("Empty response from Sheet ({$googleSheetUrl})");
    } else {
        $response = sortCsvData($response); // Sort and uniq the CSV data

        if ($response === '') {
            log_warn("Empty response after sorting from Sheet ({$googleSheetUrl})");
        } else {
            // Save the content to the cache file
            file_put_contents($cacheFile, $response);
        }
    }

    // Output the content
    echo $response;

    log_return( strlen($response) . " bytes" );
}


?>
