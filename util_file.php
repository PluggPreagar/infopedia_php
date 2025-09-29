<?php
/*
 *  Util_Cache
 *  - loads Data from Source
 */


    // Function to download Google Sheet and cache it
    function downloadAndCacheGoogleSheet($url, $cacheFile) {
        $sheetData = file_get_contents($url);
        if ($sheetData !== false) {
            file_put_contents($cacheFile, $sheetData);
            $cleanedData = cleanData(transform_0v02($sheetData));
            $cacheFileCleaned = str_replace( ".cache", "_0v02.cache", $cacheFile);
            file_put_contents($cacheFileCleaned, $cleanedData);
            log_debug("Google Sheet data downloaded and cached successfully.\n");
        } else {
            log_debug("Failed to download Google Sheet data.\n");
        }
    }

    function isCacheValid($cacheFile, $cacheTime) {
        if (!file_exists($cacheFile)) {
            log_debug("Cache file does not exist.\n");
            return false;
        }
        $lastModified = filemtime($cacheFile);
        log_debug("Cache file last modified at: " . date('Y-m-d H:i:s', $lastModified) . "\n");
        return (time() - $lastModified) < $cacheTime ; // Cache valid for 1 hour
    }

    function updateCacheIfNeeded($cacheFile, $googleSheetUrl) {
        if (!isCacheValid($cacheFile)) {
            // Download and cache the Google Sheet data
            log_debug("Downloading and caching Google Sheet data...");
            downloadAndCacheGoogleSheet($googleSheetUrl, $cacheFile);
        }
    }

    /*
     *  load data for Node as Folder
     *      - include parent - to show hierarchy
     *      - include childs - as folder-entries
     *      - include flags to indicate if childs are folder-node or leaf
     *  entry_0v02-Type ...
     *  |p1|parent 1
     *  p1|p2|parent 2
     *  p1/p2|f|folder-node      <-- id: p1/p2/f
     *  p1/p2/f|c1|child1
     *  p1/p2/f|c2|child2
     *  p1/p2/f/c2|gc2.1|grand-child2.1
     *  p1/p2/f|c3|child3
     */
    function loadFiltered($cacheFile, $folderNode) {
        // parent-id before folder-node-id before child-id before grand-child-id - as they are sorted
        // parent-id is prefix of folder-node-id
        // folder-node-id is prefix of child-id
        $lines = file($cacheFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        log_debug( "Loading data from cache...\n");
        foreach ($lines as $line) {
            $parts = preg_split("|",$line, 3);
            $id = $parts[0]."/".$parts[1];

        } // lines
    }

?>