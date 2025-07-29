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

// Function to download Google Sheet and cache it
function downloadAndCacheGoogleSheet($url, $cacheFile) {
    $sheetData = file_get_contents($url);
    if ($sheetData !== false) {
        file_put_contents($cacheFile, $sheetData);
    }
}

// Function to check if cache is valid
function isCacheValid($cacheFile) {
    if (!file_exists($cacheFile)) {
        return false;
    }
    $lastModified = filemtime($cacheFile);
    return (time() - $lastModified) < 3600; // Cache valid for 1 hour
}

// Function to load filtered content from cache
function loadFilteredContent($cacheFile, $filter) {
    if (!isCacheValid($cacheFile)) {
        return [];
    }

    $lines = file($cacheFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $filteredData = [];
debug "Loading data from cache...\n";
    foreach ($lines as $line) {
        if (strpos($line, $filter) !== false) {
            $filteredData[] = $line;
        }
    }

    return $filteredData;
}

// Function to parse data into structured format
function parseData($lines) {
    $data = [];
    foreach ($lines as $line) {
        $parts = str_getcsv($line); // Handle quotes and commas
        if (count($parts) < 2) continue;

        $timestamp = $parts[0];
        $entry = $parts[1];
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

// Function to generate HTML output
function generateHtmlOutput($data) {
    echo "<!DOCTYPE html>";
    echo "<html>";
    echo "<head><title>Infopedia</title></head>";
    echo "<body>";
    echo "<h1>Infopedia</h1>";

    if (!empty($data)) {
        echo "<table border='1'>";
        echo "<tr><th>Timestamp</th><th>Topic</th><th>Node</th><th>Content</th><th>Entry Type</th></tr>";

        foreach ($data as $entry) {
            $parentTopic = substr($entry['topic'], 0, strrpos($entry['topic'], '/'));
            $entryLink = substr($entry['content'], 0, strrpos($entry['content'], ' | '));

            echo "<tr>";
            echo "<td>{$entry['timestamp']}</td>";
            echo "<td><a href='?topic={$parentTopic}'>{$entry['topic']}</a></td>";
            echo "<td>{$entry['node']}</td>";
            echo "<td><a href='?topic={$entryLink}'>{$entry['content']}</a></td>";
            echo "<td>{$entry['entry_type']}</td>";
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

// Main script execution
$googleSheetUrl = $config['googleSheetUrl'] ?? die("Google Sheet URL not set in configuration file.");
$cacheFile = $config['cacheFile'] ?? 'sheet.cache'; // Default to 'sheet.cache' if not set
$filter = $config['filter'] ?? 'example_filter'; // Default to 'example_filter' if not set
*/

$googleSheetUrl = $config['googleSheetUrl'] ?? '...';
$cacheFile = $config['cacheFile'] ?? 'sheet.cache'; // Default to 'sheet.cache' if not set
$filter = $config['filter'] ?? 'example_filter'; // Default to 'example_filter' if not set

if (!isCacheValid($cacheFile)) {
    downloadAndCacheGoogleSheet($googleSheetUrl, $cacheFile);
}

$filteredLines = loadFilteredContent($cacheFile, $filter);
$data = parseData($filteredLines);
generateHtmlOutput($data);

?>
