<?php
    $type = "web";
    require 'util.php';
    require 'util_entry.php';
    require 'util_file.php';

  /*
   *  entry_0v02: <parent-node>|<node>|<timestamp>|[flags|]<content><content-type-hint> (\n for multilines)
   *        they will be sorted by <parent-node>/<node>
   */


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
                    $parts = str_getcsv($line);
                    $entry = $parts[1] ?? '';
                    list($topic, $node, $content) = explode(" | ", $entry);
                    $parentContent[ $topic."/".$node ] = $content;
                    log_debug("matched parent filter: " . $parentFilter . " for " . $topic."/".$node . " with: " . $content);
                    $found = true;
                    break;
                }
            }
            if (!$found && $rowCount++ < 10) {
                log_debug("skipped line: " . $line);
            }
            // may bay is child of filtered entry - relevant to separate node from leaf (leaf has no link)

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
        // use delimiter as comma, but handle quotes "
        $parts = str_getcsv($line);
        if (count($parts) < 2) {
            continue;
        }

        $timestamp = $parts[0];
        $entry = $parts[1];
        // Assuming the entry is in the format "Topic | Node | Content"
        // when entry starts with '|' insert the trimmed space before the pipe
        if (strpos($entry, '|') === 0) {
            $entry = ' ' . $entry;
        }
        // if a filter is set, check if the entry starts with the filter
        // (we can't filter the file as it contains multiple columns and delimiters before the entry)
        if ($filter && strpos($entry, $filter) !== 0) {
            // Check if the entry matches any of the parent topic filters
            if (count($data) < 10) {
                log_debug("skipped data: " . $entry);
            }
            continue; // Skip entries that do not match the filter
        }
        list($topic, $node, $content) = explode(" | ", $entry);
        $entryType = substr($content, -1);

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

        echo "topic: " . $topic . " node: " . $node . " myTopic: " . $myTopic . "\n";

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



function updateCacheIfNeeded() {
    global $cacheFile, $cacheTime, $useReadPhp, $googleSheetUrl;
    if (!isCacheValid($cacheFile)) {
        if ($useReadPhp) {
            // Use read.php to fetch and cache the Google Sheet data
            log_debug("Using read.php to fetch and cache Google Sheet data...");
            //include 'read.php';
            // call read.php to fetch and cache the Google Sheet data
            $readPhpUrl = "https://" . $_SERVER['HTTP_HOST'] . str_replace('infopedia.php', 'read.php', $_SERVER['PHP_SELF']);
            $readUrl = $readPhpUrl . "?type=entry&force_update=" . (isset($_GET['force_update']) ? '1' : '0') . "&topic=" . urlencode($topic);
            log_debug("get-methode to receive read.php: " . $readUrl);
            // get my URL
            log_debug("my REQUEST_URI: " . $_SERVER["REQUEST_URI"]);
            log_debug("my PHP_SELF: " . $_SERVER["PHP_SELF"]);
            log_debug("my SERVER_NAME: " . $_SERVER["SERVER_NAME"]);


            log_debug("my URL: " . $_SERVER["SCRIPT_NAME"]);
            try {
                // side-effect: this will also cache the data
                $response = file_get_contents($readUrl);
            } catch (Exception $e) {
                log_debug("Exception when calling read.php: " . $e->getMessage());
            }
        } else {
            // Download and cache the Google Sheet data
            log_debug("Downloading and caching Google Sheet data...");
            downloadAndCacheGoogleSheet($googleSheetUrl, $cacheFile);
        }
    }
}

function setFilters(){
    global $topic, $filter, $preFilter, $parentContent;
    if ("/" === $topic) {
        $topic = ''; // If topic is just '/', set it to empty
    }
    $filter = $topic . ' | ' ; // Default to 'example_filter' if not set
// as file is comma separated, we need take in account first column and delimiter
    $preFilter = ',' . $filter; // ... and csv might have a trimmed data
    $parentContent = array();
}

function run(){
    global $topic, $cacheFile, $preFilter, $filter;
    setFilters();
    generateHtmlHead($topic);
    updateCacheIfNeeded();

    $filteredLines = loadFilteredContent($cacheFile, $preFilter, parentsToTopicFilter($topic));
    $data = parseData($filteredLines, $filter);
    generateHtmlOutput($data, $topic);

}


// Read configurations from the configuration file
$configFile = 'infopedia.cfg';
$type = "web";
if (file_exists($configFile)) {
    $configGeneral = parse_ini_file($configFile, true); // Enable section parsing
    if ($configGeneral === false) {
        die("Failed to parse configuration file.");
    }

    // Check if the 'general' and 'votes' sections exist
    $config = [];
    if (isset($configGeneral['general'])) {
        $config = $configGeneral['general'];
    }
    if (isset($configGeneral[$type])) {
        $config = array_merge($config, $configGeneral[$type]);
    }
} else {
    die("Configuration file not found.");
}
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
# use setFilter() to init depending on topic
$filter = "";
$preFilter = "";
$parentContent = [];




if (!isset($test)) {
    run();
}


?>
