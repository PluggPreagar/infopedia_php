<?php

/*
### Explanation:
1. **Download Google Sheet**: Use `file_get_contents` to fetch the sheet data.
2. **Cache Management**: Save the sheet data in `sheet.cache` and check its validity based on the timestamp.
3. **Loading Function**: Read the cache file, filter lines based on a pattern, and parse the data into an array.
4. **Data Parsing**: Split the data into timestamp, entry, topic, node, and content, and assign `entry_type`.
5. **HTML Output**: Generate a table with links and a header.

### Code:
*/

function log_debug($message) {
    // Uncomment the next line to enable debugging output
    echo $message;
    echo "<br>";
}

function log_warn($message) {
    // Uncomment the next line to enable warning output
    echo "<strong>Warning: </strong>" . $message;
    echo "<br>";
}


// Function to download Google Sheet and cache it
function downloadAndCacheGoogleSheet($url, $cacheFile) {
    $sheetData = file_get_contents($url);
    if ($sheetData !== false) {
        file_put_contents($cacheFile, $sheetData);
        log_debug("Google Sheet data downloaded and cached successfully.\n");
    } else {
        log_debug("Failed to download Google Sheet data.\n");
    }
}

// Function to check if cache is valid
function isCacheValid($cacheFile) {
    global $cacheTime;
    if (!file_exists($cacheFile)) {
        log_debug("Cache file does not exist.\n");
        return false;
    }
    $lastModified = filemtime($cacheFile);
    log_debug("Cache file last modified at: " . date('Y-m-d H:i:s', $lastModified) . "\n");
    return (time() - $lastModified) < $cacheTime ; // Cache valid for 1 hour
}

// Function to load filtered content from cache
function loadFilteredContent($cacheFile, $filter) {
    $lines = file($cacheFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $filteredData = [];
    $rowCount = 0;
    log_debug( "Loading data from cache...\n");
    foreach ($lines as $line) {
        // Skip lines that do not contain the filter
        if (empty($line) || strpos($line, $filter) === false) {
            if ($rowCount++ < 10) {
                log_debug("skipped line: " . $line);
            }
            continue;
        } else {
            $filteredData[] = $line;
        }
    }
    log_debug("loaded " . count($filteredData) . " lines matching filter '$filter'.\n");
    return $filteredData;
}

// Function to parse data into structured format
function parseData($lines, $filter = '') {
    $data = [];
    foreach ($lines as $line) {
        $parts = str_getcsv($line); // Handle quotes and commas
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
            continue; // Skip entries that do not match the filter
        }
        list($topic, $node, $content) = explode(" | ", $entry);
        $entryType = substr($content, -1);

        $data[] = [
            'timestamp' => $timestamp,
            'topic' => $topic,
            'node' => $node,
            'content' => $content,
            'entry_type' => $entryType
        ];
    }
    return $data;
}

// allow to store debugging in hidden div
function generateHtmlHead($topic = '') {
    echo "<!DOCTYPE html>";
    echo "<html>";
    echo "<head><title>Infopedia {$topic}</title></head>";
    echo "<link rel='stylesheet' type='text/css' href='styles.css'>";
    echo "<body>";
    echo "<h1>Infopedia</h1>";
    echo "<div id='debug' style='display:block;font-size:smallest;'>";
    log_debug("Generating HTML head... for topic: " . htmlspecialchars($topic));
}

// Function to generate HTML output
function generateHtmlOutput($data, $topic = '') {
    log_debug("Generating HTML output... for topic: " . htmlspecialchars($topic));
    echo "</div>"; // Close debug div

    if (empty($topic) && isset($data[0]['topic'])) {
        $topic = $data[0]['topic'];
    }
    echo "<h2>";

    // split path of $entry['topic']
    // skipp leading slash
    $parts = explode('/', $topic);
    $path = '';
    foreach ($parts as $part) {
        $path .= $part;
        if (empty($part)) {
            echo "<a href='?topic=/'>/</a>&nbsp;";
        } else {
            echo "<a href='?topic=" . htmlspecialchars($path) . "'>" . htmlspecialchars( $part ) . "</a>&nbsp;/&nbsp";
        }
        $path .= '/' ; // Add a slash for the next part
    }
    echo "</h2>";
    // space
    if (!empty($data)) {
        echo "<table border='0'>";
        // data
        foreach ($data as $entry) {
            echo "<tr>";
            echo "<td><a href='?topic={$entry['topic']}/{$entry['node']}'>{$entry['content']}</a></td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No data available.</p>";
    }

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
function parentsToTopic($topic){
    // replace last "/" in string with " | "
    $parts = explode('/', $topic);
    // append empty string to the end of the array
    array_push($parts, '');
    $topics = [];
    $path = '';
    foreach ($parts as $part) {
        $topics[] = $path . " | " . $part . " | ";
        log_debug("topic: " . $path . " | " . $part . " | ");
        $path .= '/' . $part;
    }
    return $topics;
}

function preFilter($topics){
    $preFilters = [];
    foreach ($topics as $topic) {
        $preFilters[] = ',' . $topic; // Add comma before each topic
    }
    return $preFilters;
}

/*
    UPLOAD ...
 */





// Read configurations from the configuration file
$configFile = 'infopedia.cfg';
if (file_exists($configFile)) {
    $config = parse_ini_file($configFile);
    if ($config === false) {
        die("Failed to parse configuration file.");
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

generateHtmlHead($topic);

if (!isCacheValid($cacheFile)) {
    downloadAndCacheGoogleSheet($googleSheetUrl, $cacheFile);
}
$filteredLines = loadFilteredContent($cacheFile, $preFilter);
$data = parseData($filteredLines, $filter);
generateHtmlOutput($data, $topic);

?>
