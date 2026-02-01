<?php
 include_once 'util.php';
 include_once 'util_entry.php'

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

// if data starts with "/_/bug | bug_" then set tenant_id to "bug"
if (strpos($data, '/_/bug | bug_') === 0) {
    // append tenant_id to end of first line of data, only first line
    // remove "/_/" prefix - shift to visible part
    $data = preg_replace('/^\/_(.+?)(\r?\n|$)/', '$1 ' . $tenant_id . ' $2', $data, 1);
    $tenant_id = 'fayfBug__1754128928';
}

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


/*
    new version of tenant data file format:
    - one line per entry - newline replaced by \n
    - path/node[:<attr>:<sid>],timestamp,(message|[message],attr-value)
        - value quoted with '"' when containing ',' (existing quotes are doubled)
        - votes   : path/node:vote:<sid>,timestamp,[message],(+1|-1)
        - deletion: path/node:delete:<sid>,timestamp,<message>,(reason)
                    path/node,timestamp,<message>--                        -- deprecated format

    advantages:
    - easier to parse (only 1 delimiter, no multi-line entries)
    - easier to sort by topic/path (most used sorting)
    - allow to sort by attributes

    use case:
    - add new entry
    - add new vote
    - delete entry
    - change vote
    - read entries only (obsolete)
    - read votes only (obsolete)
    - init/reset tenant - read all data (entries and votes)
    - incremental update, regular - read delta entries + votes, less than 5 minutes old
    - incremental update, long - read all entries + votes since last known timestamp
    - maintenance
        - fix entry tree
        - reset votes
        - backup, proof archiving

    files:
    - data/<tenant1>.log                -- log all entries and votes for tenant, for debugging, archiving (raw input)
    - data/<tenant1>.csv                -- append only (entries + vote) (formatted, not sorted)
    - data/<tenant1>.clean.csv          -- cache file (entries + vote) (sorted and cleaned)
    - data/<tenant1>.clean.csv.bak      -- temporary cache file during re-generation
    - data/<tenant1>_entries.csv        -- append only for entries - eases reset to entries only    (optional)
    - data/<tenant1>_votes.csv          -- append only for votes - eases reset to entries + selected votes (optional)
    - for delta updates:                -- allow to fastly get new entries since last update (<=5 min Slots)
        - data/<tenant1>.delta0.cache   -- delta file, partitioned by 5 Minutes (entries + votes)
        - data/<tenant1>.delta1.cache   -- delta file, partitioned by 5 Minutes (entries + votes)
    - for delta update - variant seek
        - data/<tenant1>.delta.pos      -- offset position in <data/<tenant1>.csv with ts > 5 minutes, new as possible


    processing:
    - add new entry / deletion / vote
        - log to <tenant1>.log
        - fix format, sanitize input ...
        - append to <tenant1>.csv
        - if entry    -> append to <tenant1>_entries.csv
        - if vote     -> append to <tenant1>_votes.csv
        - if deletion -> append to <tenant1>_entries.csv + <tenant1>_votes.csv
        - append to delta?.cache as well (!)
    - ... read <tenant1>.cache
        - if <tenant1>.cache exists and up-to-date (newer than <tenant1>.csv)
            - return <tenant1>.cache
        - else
            - if <tenant1>.cache.bak exists
                if <tenant1>.cache.bak not older than 1 sek
                    return <tenant1>.cache.bak  (to avoid concurrent regeneration)
            - else atomic move <tenant1>.cache to <tenant1>.cache.bak
            - read <tenant1>.csv
            - fix format
            - sort by path/topic
            - clean (remove deletions, duplicates, aggregate anonymous votes)
            - write <tenant1>.cache.tmp
            - atomic rename <tenant1>.cache.tmp -> <tenant1>.cache
    - read all data -> read <tenant1>.cache
    - read all entries/votes -> read <tenant1>.cache and FILTER by type
    - read delta entries/votes less than 5 minutes old
        - return entries from delta?.cache files (max 10 minutes old)
    - read delta entries older than 5 minutes
        - read <tenant1>.cache and FILTER by timestamp


    delta file management:
        - write
            - append to delta file that is not older then 6 minutes
            - IDX = current time in minutes % 10 / 5 - file will cleared on switch of 5 minute slot
            - if delta file with IDX does not exist
                - create new delta file with IDX (allow concurrent creation)
            - else if delta file is older than 6 minutes - reset delta file IDX
                - atomic rename to delta?.bak & create new delta file
                - append content from bak-file (if modified since rename) & remove bak-file
        - read
            - determine current IDX
            - read both delta files (IDX and (IDX+1)%2) to get all entries since last 5 minute slot
                - if last modification time of delta file is older than 5 minutes - skip file


*/

$dataDir = $config['cacheDir'] ?? 'data/';
$dataLog = $dataDir . $tenant_id . '.log';
$dataCsv = $dataDir . $tenant_id . '.csv';
$dataCleanedCsv = $dataDir . $tenant_id . '.clean.csv';
$dataCsvOldEntries = 'data/entries_' . $tenant_id . '.csv'; // data/entries_tenant1.csv
$dataCsvOldVotes = 'data/votes_' . $tenant_id . '.csv';     // data/votes_tenant1.csv
//
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}
if (file_exists($dataLog)) {
    file_put_contents($dataLog, $data . "\n", FILE_APPEND); // log all data
    $dataNew = formatEntry($data);
    if ($dataNew !== "") {
        file_put_contents($dataCsv, $dataNew . "\n", FILE_APPEND); // append formatted data
    }
}




?>