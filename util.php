<?php

// config

$configFile = 'infopedia.cfg';
$type = $_GET['type'] ?? $type ?? "none";
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

// log call

$logFile = $config['logFile'] ?? 'upload.log'; // Path to the log file
$logMessage = "[" . date('Y-m-d H:i:s') . "] ; ";
$logMessage .= " " . ( $type ?? "none" ) . " ; ";
$logMessage .= " " . $_SERVER['REQUEST_URI'] . " ; ";
$logMessage .= " " . $_SERVER['REQUEST_METHOD'] . " ; ";
// Log GET parameters if available
if (!empty($_GET)) {
    $logMessage .= json_encode($_GET) . " ; ";
}
// Log POST data if available
if (!empty($_POST)) {
    $logMessage .= json_encode($_POST) . " ; ";
}
$logMessage .= "\n";
// Append the log message to the file
file_put_contents($logFile, $logMessage, FILE_APPEND);


// logging functions


function log_debug($message) {
    // Uncomment the next line to enable debugging output
    if (!$GLOBALS['debug']) {
        return; // Skip debug output if debug mode is off
    }
    echo $message;
    echo "<br>";
}

function log_warn($message) {
    // Uncomment the next line to enable warning output
    if (!$GLOBALS['debug']) {
        return; // Skip debug output if debug mode is off
    }
    echo "<strong>Warning: </strong>" . $message;
    echo "<br>";
}


?>
