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
// if cacheOutdatedFile exists and is newer than cacheFile then delete cacheFile
$cacheOutdated= false;
$cacheOutdatedFile = $config['cacheOutdatedFile'] ?? null;
$cache_time_delay = $config['cache_time_delay'] ?? 10; // default 60 seconds


// use local file for tenant specific data
if ($tenant_id !== '') {
    // modify cache file and google sheet url to include tenant id
    $cacheFile = str_replace('.cache', "_{$tenant_id}.cache", $cacheFile);
    $googleSheetUrl = str_replace('.cache', ".csv", $cacheFile); // local file for tenant specific data
    // cahe time reduced for tenant specific data
    $cache_time_delay = 5; // 5 seconds cache for tenant specific data
}

$cacheOutdated = $cacheOutdatedFile
    && file_exists($cacheOutdatedFile) && file_exists($cacheFile)
    && (filemtime($cacheOutdatedFile) > filemtime($cacheFile) + $cache_time_delay) ;


// return csv if format is not txt
if ($format === 'txt' || $format === 'txt.0.2') {
    header('Content-Type: text/plain');
} else if ($format === 'json.0.3') {
    header('Content-Type: application/json');
} else {

    // Proxy script to fetch and serve Google Sheet content with caching
    header('Content-Type: text/csv');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Check if the cache is valid
if (file_exists($cacheFile)
            && (time() - filemtime($cacheFile)) < $cacheTime
            && !$cacheOutdated
            && $format !== 'txt.0.2' && $format !== 'json.0.3'
            && $last_timestamp === '' // no timestamp filtering
        ) {
    // serve the cached file
    readfile($cacheFile);
    // get file size from cache file
    log_return( filesize($cacheFile) . " bytes from cache" );
    exit;
}

// if ts-mode, check last modified time of org file - wait 1 minute for the file to change
// add 2 seconds to let file write finish
if ($last_timestamp !== '' && file_exists($googleSheetUrl)
        && $format !== 'txt.0.2' && $format !== 'txt' && $format !== 'json.0.3') {
    // last_timestamp == time of last read
    // a) already changed - if file modification time is new then last_timestamp
    // b) no changed yet - wait until max 50 seconds for file to change
    $timeStopWaiting = time() + 50; // wait max cacheTime + 50 seconds
    $fileModTime = filemtime($googleSheetUrl);
    // KLUDGE - must use VersionID - not good to compare file-modification-time with timestamp from data
    //  --> V1 ) add artificial data set with current timestamp
    //  --> V2 ) ensure data send is always in past ... all timestamps send are older than file modification time
    //             --> wait 1 second after data added
    //             --> send only data with timestamp < file modification time on initial-trigger
    //                      (may skipp the recent recent ones .. very unlikely, will be caught on next read)
    // wait max 50 seconds for file to change
    while ( time() < $timeStopWaiting && $last_timestamp_int < $fileModTime ) {
        sleep(2); // wait 2 seconds
        clearstatcache(); // clear file stat cache
        $fileModTime = filemtime($googleSheetUrl);
    }
    if ( (time() - $fileModTime) >= ($cacheTime + 50 - 1) ) {
        log_info("Timeout waiting for file to change for timestamp mode.");
        //http_response_code(404);
        //log_return( "404 - no new data found" ); // client will periodically retry anyway
        // just no data
        http_response_code(200);
        exit;
    }
    sleep(1); // wait 1 second to let file write finish
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




// timestamp from 1 second ago - ensure data integrity
$timestamp_max_allowed = date('Y-m-d H:i:s', time() - 1);
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

    // check for vote aggregation


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

    // KLUDGE - cut off by timestamp - $last_timestamp =
    if ($last_timestamp) {
        $lines = explode("\n", $response);
        $last_timestamp_a = $last_timestamp . 'a'; // append "a" to make sure we get all entries after the timestamp
        $newLines = []; // lines start with timestamp - so we can compare directly the whole line with $last_timestamp
        foreach($lines as $line) {
            // skipp line contain /_/menu/Most-Recent-Entry
            if (strpos($line, '/_/menu/Most-Recent-Entry') !== false) {
                continue;
            }
            // fix timestamp if DD/MM/YYYY format - convert to YYYY-MM-DD
            if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4}) /', $line, $matches)) {
                $line = $matches[3] . '-' . $matches[2] . '-' . $matches[1] . substr($line, 10);
            }
            // fix timestamp if YYYY-DD-MM format - convert to YYYY-MM-DD
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2}) /', $line, $matches)) {
                if (intval($matches[2]) > 12) {
                    $line = $matches[1] . '-' . $matches[3] . '-' . $matches[2] . substr($line, 10);
                }
            }
            if (strcmp($line, $last_timestamp_a) > 0 && strpos($line, 'Timestamp,') !== 0) {
                // skipp lines with exact current timestamp
                // - as we can't garantee that other entries are added right now, with very same timestamp
                //   that would lead to missing entries on next read using last_timestamp
                if (strcmp($line, $timestamp_max_allowed) > 0) {
                    log_warn("Skipping line with current or future timestamp: $line");
                } else {
                    $newLines[] = $line;
                }
            }
        }
        log_warn("Cutting off entries after timestamp: $last_timestamp , remaining lines: " . count($newLines) );
        $response = implode("\n", $newLines);
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
                [$timestamp, $rest] = array_pad(explode(',', $line, 2), 2, '');
                if ($timestamp === '' || $rest === '') {
                    log_warn("Skipping malformed line for txt.0.2: $line");
                    continue;
                }
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

    if ($format == "json.0.3"){
        // convert csv to json v0.3
        // target: { "<path>": { "nodeId": { "timestamp": "<date>", "message": "<message>" } } }
        // from  : <date>,<path> | <node> | <message> | <votes>
        $lines = explode("\n", $response);
        $lines_tmp = [];
        $leftoverLine = "";
        // repair
        // - multiline
        // - invalid timestamp
        // - different date formats
        foreach($lines as $line) {
            // check quotes for wrapped lines
            $quoteCount = substr_count($line, '"');
            if ($quoteCount % 2 != 0) {
                // odd number of quotes, line is wrapped
                $leftoverLine .= $line . "\\n"; // preserve newline in message
            } else {
                // even number of quotes, line is complete
                $line = $leftoverLine . $line;
                $leftoverLine = "";
                if (strpos($line, 'Timestamp,') !== 0) {
                    // ensure 1 lines by replacing inner newlines with \n
                    $line = str_replace("\n", "\\n", $line);
                    $line = str_replace("\r", "", $line);
                    // extract timestamp and fix format if needed
                    [$timestamp, $rest] = array_pad(explode(',', $line, 2), 2, '');
                    // skipp malformed lines, or timestamp uses - only digits, spaces, :, -, /
                    if ($timestamp === '' || $rest === '' || !preg_match('/^[0-9\s:\-\/]+$/', $timestamp)) {
                        log_warn("Skipping malformed line for json.0.3: $line");
                        continue;
                    }
                    // fix timestamp if DD/MM/YYYY HH:MM:SS format - convert to YYYY-MM-DD HH:MM:SS
                    if (preg_match('/^(\d{2})[-\/](\d{2})[-\/](\d{4}) (\d{2}):(\d{2}):(\d{2})$/', $timestamp, $matches)) {
                        $timestamp = $matches[3] . '-' . $matches[2] . '-' . $matches[1] . ' ' . $matches[4] . ':' . $matches[5] . ':' . $matches[6];
                        $line = $timestamp . ',' . $rest;
                    }
                    if (preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $timestamp, $matches)) {
                        if (intval($matches[2]) > 12) { // check if MM and DD are swapped
                            $timestamp = $matches[1] . '-' . $matches[3] . '-' . $matches[2] . ' ' . $matches[4] . ':' . $matches[5] . ':' . $matches[6];
                            $line = $timestamp . ',' . $rest;
                        }
                    }
                    $lines_tmp[] = $line;
                }
            }
        }
        $jsonArray = [];
        foreach($lines_tmp as $line) {
            [$timestamp, $rest] = array_pad(explode(',', $line, 2), 2, '');
            if ($timestamp === '' || $rest === '') {
                log_warn("Skipping malformed line for json.0.3: $line");
                continue;
            }
            // handle <ts>,"/path | node | message | votes
            $rest = trim($rest, '"');
            [$path, $node, $message, $votes] = array_pad(explode(' | ', $rest, 4), 4, '');
            $message = trim($message, '"'); // remove quotes around message
            $path = preg_replace('#/+#','/',$path); // fix multiple "/"
            // empty path -> "/"
            if (trim($path) === '') {
                $path = '/';
            }
            if (trim($node) !== '' && trim($message) !== '') {
                if (!isset($jsonArray[$path])) {
                    $jsonArray[$path] = [];
                }
                $jsonArray[$path][$node] = [
                    "timestamp" => $timestamp,
                    "message" => str_replace("\\n", "\n", $message) // restore newlines
                ];
                // add votes if present
                if (trim($votes) !== '') {
                    $jsonArray[$path][$node]["votes"] = intval($votes);
                }
            } else {
                log_warn("Skipping malformed line for json.0.3: $line");
            }
        }

        // output json
        $response = json_encode($jsonArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        log_warn("Converted csv to json.0.3 format with " . count($jsonArray) . " entries");
    }

    // Output the content
    echo $response;

    log_return( strlen($response) . " bytes" );
}


?>
