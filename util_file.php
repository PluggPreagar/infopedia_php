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
        if (!str_starts_with($folderNode, "/")) {
            $folderNode = "/" . $folderNode;
        }
        $folderNode = str_replace("|", "/", $folderNode);

        // parent-id before folder-node-id before child-id before grand-child-id - as they are sorted
        // parent-id is prefix of folder-node-id  -> parent shorter
        // folder-node-id is prefix of child-id   -> children longer
        $lines = is_array($cacheFile) ? $cacheFile : file($cacheFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        log_debug( "Loading data from cache...\n");
        $parents = [];
        $myself = "";
        $children = [];
        $hasGrandChilds = [];
        $skippGrandChilds = "XXXXXX";
        foreach ($lines as $line) {
            if (str_contains($line, "|")) {
                $parts = preg_split("/\|/",$line, 3);
                $cur_parent = $parts[0];
                $cur_key = $parts[0]."/".$parts[1];
                if ( $folderNode == $cur_key ) {
                    // myself
                    $myself = $line;
                } else if ( str_starts_with($folderNode, $cur_key) ) {
                    // parent -> $id is part of $folderNode
                    $parents[] = $line;
                } else if ( $cur_parent == $folderNode ) {
                    // my children
                    $children[] = $line;
                } else if ( str_starts_with($cur_parent, $skippGrandChilds) ) {
                    // skipp non first grandchilds
                } else if ( str_starts_with($cur_parent, $folderNode) ) {
                    // grand children -- needed to know if further links should be shown ...
                    // replace last "/" with "|"
                    $pos = strrpos($cur_parent, $folderNode);
                    if ($pos !== false) {
                        $cur_parent = substr_replace($cur_parent, "|", $pos, 1);
                    }
                    $hasGrandChilds[] = $cur_parent;
                    $skippGrandChilds = $cur_parent;
                }
            }
        } // lines
        return [ "parents" => $parents,  "myself" => $myself, "children" => $children , "hasGrandChilds" => $hasGrandChilds ];
    }

?>