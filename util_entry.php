<?php
/*
 *  Util_Cache
 *  - loads Data from Source
 *  - clones 1:1 into <data-type>.cache files
 *  - filters obsolete (updated, removed) entries, sorts into <data-type>_cleaned.cache
 *      - (!) the content will be transformed
 *          - datasource-style: <timestamp>,"?<parent-node> | <node> | <content><content-type-hint>"? (e.g. multiline)
 *          - fayf-style-0v02:  <parent-node>|<node>|<timestamp2>|flags|<content><content-type-hint> (single-line using \\n)
 *      - time by google
 *          - timestamp  07/09/2025 20:44:54
 *          - timestamp2 2025/09/07 20:44:54
 *
 *
 *  need globals:
 *      - $cacheTime
 */


    function transform_0v02($data_raw) {
        $data = [];
        $line = "";
        foreach ($data_raw as $k => $v) {
            $line = $line . $v;
            if (substr_count($line, '"') % 2 != 0
                    // allow \" as zoll - only check if necessary
                    // && substr_count( str_replace( "\"","", $line)  , '"') % 2 != 0
                    ) {
                // odd number of quotes, line is wrapped
                $line = $line . "\n";
            } else {
                if ($line) {
                    $data[] = data_entry_line_sortable( $line );
                }
                $line = "";
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
    function cleanData($dataSortKeyPrefixed) {
        // sort array of data
        asort($dataSortKeyPrefixed); // asc key but desc time !!
        $data = [];
        $key_last = "XXXXXXXXXX";
        foreach ($dataSortKeyPrefixed as $k => $v) {
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


    function data_entry_line_sortable($csv_value) {
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
                    // escape \r\n
                    $content = str_replace("\n", "\\n", str_replace("\r", "\\r", $content));
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

    function data_entry0v02($data_entry_line, $nodesWithChildren = []){
        // /topic|node|time[|flags]|content
        $parts = explode("|", $data_entry_line);
        $topic = $parts[0];
        $node = $parts[1];
        $timestamp = $parts[2];
        $flags =  count($parts) > 4 ? $parts[3] : 0;
        $content = $parts[count($parts)-1];
        $nodeKey = $topic."|".$node;
        $entry[] = [
            'timestamp' => $timestamp,
            'topic' => $topic,
            'node' => $node,
            'flags' => $flags,
            'content' => $content,
            'entry_type' => substr($content, -1),
            'child_count' => in_array($nodeKey, $nodesWithChildren)
        ];
        return $entry;
    }



    function topicFilter_($topic){
        // replace last "/" in string with " | "
        $parts = explode('/', $topic);
        // append empty string to the end of the array
        array_shift($parts);
        $topics = [];
        $path = '';
        // log_debug("Generating parentsToTopicFilter for: " . $topic);
        foreach ($parts as $part) {
            $topics[] = ',' . $path . " | " . $part . " | ";
            //log_debug("topic: " . $path . " | " . $part . " | ");
            $path .= '/' . $part;
        }
        return $topics;
    }

?>