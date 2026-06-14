<?php

$type = "web";
require 'util.php';
require_once 'util_file.php';
require_once 'util_entry.php';


/*
### Explanation:
1. **Download Google Sheet**: Use `file_get_contents` to fetch the sheet data.
2. **Cache Management**: Save the sheet data in `sheet.cache` and check its validity based on the timestamp.
3. **Loading Function**: Read the cache file, filter lines based on a pattern, and parse the data into an array.
4. **Data Parsing**: Split the data into timestamp, entry, topic, node, and content, and assign `entry_type`.
5. **HTML Output**: Generate a table with links and a header.

### Code:
*/
$debug=false;

// Function to load filtered content from cache
function loadFilteredContent($cacheFile, $filter, $parentsToTopicFilters = []) {
    global $parentContent;

    $lines = file($cacheFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $filteredData = [];
    // check if child exists - will be needed later, to allow click on parent topics but not on leafs
    $childFilter = str_replace(' | ', '/', $filter); // e.g. 'clima | biz | ' -> 'clima/biz/'
    $rowCount = 0;
    log_debug( "Loading data from cache...\n");
    foreach ($lines as $line) {
        // replace ',"' with ',' to ignore quotes during filtering
        $line_ = str_replace(',"', ',', $line);
        // Skip lines that do not contain the filter
        if (empty($line) || strpos($line_, $filter) === false) {
            $found = false;
            foreach ($parentsToTopicFilters as $parentFilter) {
                //log_debug("Checking parent filter: " . $parentFilter . " for line: " . $line);
                if (strpos($line_, $parentFilter) != 0) {
                    $parsed = parseEntryLine($line);
                    if (empty($parsed)) {
                        continue;
                    }
                    $topic = $parsed['topic'];
                    $node = $parsed['node'];
                    $content = $parsed['content'];
                    $parentContent[ $topic."/".$node ] = $content;
                    log_debug("matched parent filter: " . $parentFilter . " for " . $topic."/".$node . " with: " . $content);
                    $found = true;
                    break;
                }
            }
            if (!$found && $rowCount++ < 10) {
                log_debug("skipped line: " . $line);
            }
            continue;
        } else {
            $filteredData[] = $line;
        }
    }
    /* var_dump($parentContent); */
    log_debug("\n");
    log_debug("loaded " . count($filteredData) . " lines matching filter '$filter'.\n");
    return $filteredData;
}

// Function to parse data into structured format
function parseData($lines, $filter = '', $parentsToTopicFilters = []) {
    $data = [];
    $topicIndex = [];
    foreach ($lines as $line) {
        $parsed = parseEntryLine($line);
        if (empty($parsed)) {
            continue;
        }

        $timestamp = $parsed['timestamp'];
        $entry = $parsed['topic'] . ' | ' . $parsed['node'] . ' | ' . $parsed['content'];
        // if a filter is set, check if the entry starts with the filter
        // (we can't filter the file as it contains multiple columns and delimiters before the entry)
        if ($filter && strpos($entry, $filter) !== 0) {
            // Check if the entry matches any of the parent topic filters
            if (count($data) < 10) {
                log_debug("skipped data: " . $entry);
            }
            continue; // Skip entries that do not match the filter
        }
        $topic = $parsed['topic'];
        $node = $parsed['node'];
        $content = $parsed['content'];
        $entryType = $parsed['entry_type'];

        /* does not work as childs are already skipped */

                // add current data index to topicIndex for sorting
                $myTopic = $topic . '/' . $node;
                $topicIndex[$myTopic] = count($data);
                // increment child count of parent topics
                // get parentIndex from $topicIndex
                if (isset($topicIndex[$topic])) {
                    $parentIndex = $topicIndex[$topic];
                    $data[$parentIndex]['child_count']++;
                }


        $data[] = [
            'timestamp' => $timestamp,
            'topic' => $topic,
            'node' => $node,
            'content' => $content,
            'entry_type' => $entryType,
            'child_count' => 0, // will be updated later
        ];
    }
    return $data;
}

// allow to store debugging in hidden div
function generateHtmlHead($topic = '') {
    echo "<!DOCTYPE html>";
    echo "<html>";
    echo "<head><title>fact-your-fear {$topic}</title></head>";
    echo "<link rel='stylesheet' type='text/css' href='styles.css'>";
    echo "<body>";
    echo "<h1><a href='.'>fact-your-fear<a/></h1>";
    echo "<div id='debug' style='display:none;font-size:smaller;'>";
    log_debug("Generating HTML head... for topic: " . htmlspecialchars($topic));
}

// Function to generate HTML output
function generateHtmlOutput($data, $topic = '') {
    global $parentContent;
    log_debug("Generating HTML output... for topic: " . htmlspecialchars($topic));
    echo "</div>"; // Close debug div

    if (empty($topic) && isset($data[0]['topic'])) {
        $topic = $data[0]['topic'];
    }
    echo "<h2>";

    // split path of $entry['topic']
    // skipp leading slash
    $parts = explode('/', $topic);
    $current_part = array_pop($parts);
    $path = '';
    foreach ($parts as $part) {
        $path .= $part;
        if (empty($part)) {
            echo "<a href='?topic=/'>/</a>&nbsp;";
        } else {
            echo "<a href='?topic=" . htmlspecialchars($path) . "'>" . htmlspecialchars(  $parentContent[$path]  ) . "</a>&nbsp;/&nbsp";
            echo "<br>";
        }
        $path .= '/' ; // Add a slash for the next part
    }

    echo " " . htmlspecialchars($parentContent[$path.$current_part] ?? "/") . " ";
    echo "</h2>";
    // space
    echo "<table border='0'>";
    if (!empty($data)) {
        // data
        foreach ($data as $entry) {
            echo "<tr>";
            // when $entry['content'] ends with ">" then set class "topic"
            $class = str_ends_with($entry['entry_type'], '>') ? 'class="topic"' : ( $entry['child_count'] > 0 ? 'class="parent"' : 'class="leaf"' );
            if  ($entry['child_count'] > -10) { // always true, TBD child-Detection
                echo "<td><a {$class} href='?topic={$entry['topic']}/{$entry['node']}'>{$entry['content']}</a></td>";
            } else {
                echo "<td><p {$class} idEntry='{$entry['topic']}/{$entry['node']}'>{$entry['content']}</p></td>";
            }
            echo "</tr>";
        }
    } else {
        echo "<tr><td>No data found</td></tr>";
    }
    echo "</table>";

    echo "</body>";
    echo "</html>";
}

/*
     | clima |
    /clima | biz |
    /clima/biz | tracker |
    /clima/biz/tracker | content |
    /clima/biz/tracker/content  << TOPIC
*/
function parentsToTopicFilter($topic){
    // replace last "/" in string with " | "
    $parts = explode('/', $topic);
    // append empty string to the end of the array
    array_shift($parts);
    $topics = [];
    $path = '';
    log_debug("Generating parentsToTopicFilter for: " . $topic);
    foreach ($parts as $part) {
        $topics[] = ',' . $path . " | " . $part . " | ";
        log_debug("topic: " . $path . " | " . $part . " | ");
        $path .= '/' . $part;
    }
    return $topics;
}




// Configuration is loaded by util.php after $type selects the [web] section.
/*
// Main script execution
$googleSheetUrl = $config['googleSheetUrl'] ?? die("Google Sheet URL not set in configuration file.");
$cacheFile = $config['cacheFile'] ?? 'sheet.cache'; // Default to 'sheet.cache' if not set
$filter = $config['filter'] ?? 'example_filter'; // Default to 'example_filter' if not set
*/

$useReadPhp = isset($config['useReadPhp']) ? $config['useReadPhp'] : false; // Default to false if not set
$cacheTime = isset($_GET['force_update']) ? 0 : $config['cache_time'] ?? 3600; // Default to 1 hour if not set
$googleSheetUrl = $config['googleSheetUrl'] ?? '...';
$cacheFile = $config['cacheFile'] ?? 'sheet.cache'; // Default to 'sheet.cache' if not set
$topic = isset($_GET['topic']) ? $_GET['topic'] : ''; // Default to empty if not set
if ("/" === $topic) {
    $topic = ''; // If topic is just '/', set it to empty
}
$filter = $topic . ' | ' ; // Default to 'example_filter' if not set
// as file is comma separated, we need take in account first column and delimiter
$preFilter = ',' . $filter; // ... and csv might have a trimmed data
$parentContent = array();

generateHtmlHead($topic);

if (!isCacheValid($cacheFile, $cacheTime)) {
    if ($useReadPhp) {
        // Use read.php to fetch and cache the Google Sheet data
        log_debug("Using read.php to fetch and cache Google Sheet data...");
        //include 'read.php';
        // call read.php to fetch and cache the Google Sheet data
        $readPhpUrl = "https://" . $_SERVER['HTTP_HOST'] . str_replace('infopedia.php', 'read.php', $_SERVER['PHP_SELF']);
        $readUrl = $readPhpUrl . "?type=entry&force_update=" . (isset($_GET['force_update']) ? '1' : '0') . "&topic=" . urlencode($topic);
        log_debug("get-methode to receive read.php: " . $readUrl);
        // get my URL
        log_debug("my REQUEST_URI: ".$_SERVER["REQUEST_URI"]);
        log_debug("my PHP_SELF: ".$_SERVER["PHP_SELF"]);
        log_debug("my SERVER_NAME: ".$_SERVER["SERVER_NAME"]);



        log_debug("my URL: ".$_SERVER["SCRIPT_NAME"]);
        try{
            // side-effect: this will also cache the data
            $response = file_get_contents($readUrl);
        } catch (Exception $e) {
            log_debug("Exception when calling read.php: " . $e->getMessage());
        }
    } else {
        log_debug("Downloading and caching Google Sheet data...");
        $sheetData = @file_get_contents($googleSheetUrl);
        if ($sheetData !== false) {
            writeCache($cacheFile, $sheetData);
            log_debug("Google Sheet data downloaded and cached successfully.");
        } else {
            log_warn("Failed to download Google Sheet data for infopedia.");
        }
    }
}

$filteredLines = loadFilteredContent($cacheFile, $preFilter, parentsToTopicFilter($topic));
$data = parseData($filteredLines, $filter);
generateHtmlOutput($data, $topic);

?>
