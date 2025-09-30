<?php
    $type = "web";
    require 'util.php';
    require 'util_entry.php';
    require 'util_file.php';

$useReadPhp = isset($config['useReadPhp']) ? $config['useReadPhp'] : false; // Default to false if not set
$cacheTime = isset($_GET['force_update']) ? 0 : $config['cache_time'] ?? 3600; // Default to 1 hour if not set
$googleSheetUrl = $config['googleSheetUrl'] ?? '...';
$cacheFile = $config['cacheFile'] ?? 'sheet.cache'; // Default to 'sheet.cache' if not set
$topic = isset($_GET['topic']) ? $_GET['topic'] : ''; // Default to empty if not set



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
function generateHtmlOutput0v02($topic, $parents, $myself, $children, $hasGrandChilds) {
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

function run(){
    global $topic, $cacheFile;
    generateHtmlHead($topic);

    updateCacheIfNeeded();
    $cacheFile0v02 = "";
    $data = loadFiltered($cacheFile0v02, $topic);
    // [ "parents" => $parents,  "myself" => $myself, "children" => $children , "hasGrandChilds" => $hasGrandChilds ]
    generateHtmlOutput0v02($topic, $data["parents"], $data["myself"], $data["children"], $data["hasGrandChilds"]);

}

if (!isset($test)) {
    run();
}


?>
