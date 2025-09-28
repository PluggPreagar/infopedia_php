<?php
/*
 *  Util_Cache
 *  - loads Data from Source
 *  - clones 1:1 into <data-type>.cache files
 *  - filters obsolete (updated, removed) entries, sorts into <data-type>_cleaned.cache
 *      - (!) the content will be transformed
 *          - datasource-style: <timestamp>,"?<parent-node> | <node> | <content><content-type-hint>"? (e.g. multiline)
 *          - fayf-style-0v02:  <parent-node> | <node> | <timestamp2> | <content><content-type-hint> | flags (single-line using \\n)
 *          - fayf-style-0v02e: | <parent-node>/<node> | <parent-node> | <node> | <timestamp2> | <content><content-type-hint> | flags
 *      - time by google
 *          - timestamp  07/09/2025 20:44:54
 *          - timestamp2 2025/09/07 20:44:54
 *
 *
 *  need globals:
 *      - $cacheTime
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
     *  TRANSFORM - Entry-Versions !!
     */


    function transform_0v02($data_raw) {
        $data = [];
        foreach ($data_raw as $k => $v) {
            $value = data_entry($v);
            if ($value) {
                $data[] = $value;
            }
        }
        return $data;
    }

    /*
     * clean-up data
     *  - replace obsolete (by timestamp)
     *  - remove deleted (by "--" suffix)
     *  ! ticky - use "inverted"-Time-Stamp to sort time (implicit) desc (by sorting key asc)
     */
    function cleanData($data) {
        // sort array of data
        asort($data);
        $key_last = "XXXXXXXXXX";
        foreach ($data as $k => $v) {
            if (str_starts_with($v, $key_last)) {
                // obsolete key
                unset($data[$k]);
            } else { // remove time-rev
                // get substring left of "##"
                $key_l = strpos($v, "##");
                $key_last = substr($v, 0, $key_l + 1); // use "#" as end-of-key
                if ( str_ends_with($v, "--") ) {
                    // check for "--" suffix -> deleted
                    unset($data[$k]);
                } else {
                    $key_timeRev = strpos($v, "##", $key_l+2)+2;
                    $value = substr($v, $key_timeRev);
                    $data[$k] = $value;
                }
            }
        }
        return $data;
    }


    function data_entry($csv_value) {
        $value = null;
        $parts1 = str_getcsv($csv_value); // split top level, handle quotes
        if (count($parts1) >= 2) {
            $time = $parts1[0];
            // reconstruct time ... 07/09/2025 20:44:54  -> 2025-09-07 20:44:54
            $timeParts = preg_split("/[ \/]/", $time);
            if (4 == count($timeParts) || 2 == count($timeParts)) {
                $timeOrdered = 2 == count($timeParts) ? $timeParts[0]." ".$timeParts[1] : $timeParts[2] . "-" . $timeParts[1] . "-" . $timeParts[0] . " " . $timeParts[3];
                $timeRev = strtr($timeOrdered, "0123456789", "9876543210"); // reverse sort by time
                // replace first 2 occurrences of " | " with "|"
                // $parts2 = str_getcsv($parts1[1]," | ");  // separator - must be single byte ..
                $parts2 = preg_split("/ ?\| ?/", $parts1[1], 3);
                if (count($parts2) >= 3) {
                    $parent = $parts2[0];
                    $node = $parts2[1];
                    $content = $parts2[2];
                    //$value0v02 = [ $parent, $node, $time, $content, str_ends_with($v,"\"") ];
                    //array_push($data, $value0v02);
                    // $k =  $parent. "|". $node."|".$time
                    //
                    // assume sorting and split is faster than prep (key + data), sorting key, de-ref // or multiple sortings
                    //
                    $key = $parent."/".$node."##".$timeRev."##"; // !! time-prefix-asc < char("/")
                    $value = $key . $parent . "|" . $node . "|" . $timeOrdered . "|" . $content;
                } // content-parts
            } // time-parts
        } // rows
        return $value;
    }



?>