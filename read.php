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
    $googleSheetUrl = str_replace('.cache', ".csv", $cacheFile); // local file for tenant specific data
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
if ($format === 'txt' || $format === 'txt.0.2') {
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
            && $format !== 'txt.0.2'
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


    if ($type == 'vote') {

        // aggregate votes
        // anonymous votes except own session_id
        $voteMarkerPrefix = "::Vote::";
        $othersMarker = $voteMarkerPrefix;
        // echo "Aggregating votes, own marker: $ownMarker<br>\n"; // Debugging output
        $lines = explode("\n", $response);
        // remove header
        array_shift($lines);
        $aggregatedVotes = [];
        $aggregatedVotesTimeStamp = [];
        $aggregateContent = [];
        foreach ($lines as $line) {
            // 2025-12-12 21:29:27,/_/check/1759255656 | 55199::Vote::sid_examples | 2  akfkfafkaf adfjawdfjadfgjyefgajegjwg | 1
            // ----- ts ---------- ============ key ==================( sid       )  -------- content -------------------     -- votes --
            // split on first "," , then next " | " two times
            [$timestamp, $rest] = explode(',', $line, 2);
            [$path, $nodeWsid, $content, $votes] = array_pad(explode(' | ', $rest, 4), 4, ''); // assume content has no " | "
            [$node, $wsid] = array_pad(explode($voteMarkerPrefix, $nodeWsid, 2), 2, '');
            if (is_numeric($votes) && '' !== trim($votes) && '' !== trim($path) &&  '' !== trim($wsid) ) {
                $key = $path . ' | ' . ($wsid == $session_id || $wsid == "signed" ? $nodeWsid : $node . $othersMarker) ;
                // echo "Processing vote line: $nodeWsid => key: $key<br>\n"; // Debugging output
                $aggregatedVotesTimeStamp[$key] = $timestamp;
                $aggregateContent[$key] = $content;
                // echo "Adding votes: $key   " . ($aggregatedVotes[$key] ?? "") . " += $votes<br>\n"; // Debugging output
                $aggregatedVotes[$key] = ($aggregatedVotes[$key] ?? 0) + $votes;
            } else {
                log_warn("Skipping malformed vote line: $line");
            }
        }
        // reconstruct response
        $response = "Timestamp,Topic | Node | Message | Votes\n";
        foreach ($aggregatedVotes as $key => $votes) {
            $response .= $aggregatedVotesTimeStamp[$key] . ',' . $key . ' | ' . $aggregateContent[$key] . ' | ' . $votes . "\n";
        }
    }

    if ($format === 'txt.0.2') {
        // convert csv to txt v0.2
        // target: <path>/<node> | <date> | <message>
        // from  : <date>,<path> | <node> | <message> | <votes>
        $lines = explode("\n", $response);
        $newLines = [];
        $txtResponse = "";
        $leftoverLine = "";
        foreach($lines as $line) {
            // check quotes for wrapped lines
            $quoteCount = substr_count($line, '"');
            if ($quoteCount % 2 != 0) {
                // odd number of quotes, line is wrapped
                $leftoverLine .= $line . "\\n"; // preserve newline in message
                continue;
            } else {
                // even number of quotes, line is complete
                $line = $leftoverLine . $line;
                $leftoverLine = "";
            }
            if (strpos($line, 'Timestamp,') !== 0) {
                [$timestamp, $rest] = explode(',', $line, 2);
                // handle <ts>,"/path | node | message | votes
                $rest = trim($rest, '"');
                [$path, $node, $message, $votes] = array_pad(explode(' | ', $rest, 4), 4, '');
                $message = trim($message, '"'); // remove quotes around message
                $fullPath = $path . "/" . $node; // fix multiple "/"
                $fullPath = preg_replace('#/+#','/',$fullPath);
                $newLine = [ $fullPath, $timestamp, $message, $votes];
                if (trim($node) !== '' && trim($message) !== '') {
                    $newLines[] = implode(' | ', $newLine);
                } else {
                    log_warn("Skipping malformed line for txt.0.2: $line");
                }
            }
        }
        // sort
        $newLines = array_unique($newLines);
        sort($newLines);
        $response = implode("\n", $newLines);
        log_warn("Converted csv to txt.0.2 format with " . count($newLines) . " lines");
    }

    // Output the content
    echo $response;

    log_return( strlen($response) . " bytes" );
}


?>
