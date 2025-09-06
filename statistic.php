<?php
header('Content-Type: text/html; charset=utf-8');

// Path to the log file
$logFile = 'infopedia.log';

echo "<!DOCTYPE html>";
echo "<html>";
echo "<head><title>Infopedia Statistics</title></head>";
echo "<link rel='stylesheet' type='text/css' href='styles_statistic.css'>";
echo "<body>";

// Check if the log file exists
if (!file_exists($logFile)) {
    die("Log file not found.");
}

// Read the log file
$logData = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

// Initialize statistics arrays
$typeStats = [];
$methodStats = [];
$uriStats = [];
$lastEntries = [];

// Process each log entry
foreach ($logData as $line) {

    $lastEntries[] = $line;
    if (count($lastEntries) > 20) {
        array_shift($lastEntries); // Keep only the last 20 entries
    }

    // Split the log line into parts
    $parts = explode(" ; ", $line);
    if (count($parts) < 4) {
        continue; // Skip invalid log lines
    }

    // Extract type, URI, and method
    $type = trim($parts[1]);
    $uri = trim($parts[2]);
    $method = trim($parts[3]);

    // Update type statistics
    if (!isset($typeStats[$type])) {
        $typeStats[$type] = 0;
    }
    $typeStats[$type]++;

    // Update method statistics
    if (!isset($methodStats[$method])) {
        $methodStats[$method] = 0;
    }
    $methodStats[$method]++;

    // Update URI statistics
    if (!isset($uriStats[$uri])) {
        $uriStats[$uri] = 0;
    }
    $uriStats[$uri]++;
}

// Display the statistics
echo "<h1>Log File Statistics</h1>";

// Display type statistics
echo "<h2>Requests by Type</h2>";
echo "<ul>";
foreach ($typeStats as $type => $count) {
    echo "<li>Type: <strong>$type</strong> - Requests: $count</li>";
}
echo "</ul>";

// Display method statistics
echo "<h2>Requests by HTTP Method</h2>";
echo "<ul>";
foreach ($methodStats as $method => $count) {
    echo "<li>Method: <strong>$method</strong> - Requests: $count</li>";
}
echo "</ul>";

// Display URI statistics
echo "<h2>Most Accessed URIs</h2>";
echo "<ul>";
foreach ($uriStats as $uri => $count) {
    echo "<li>URI: <strong>$uri</strong> - Requests: $count</li>";
}
echo "</ul>";





// Display the last 20 entries in detail
echo "<h1>Last 20 Log Entries</h1>";
echo "<table border='1'>";
echo "<tr><th>Timestamp</th><th>Type</th><th>URI</th><th>Method</th><th>Session</th><th>Details</th></tr>";
// reverse the lastEntries array to show the most recent first
$lastEntries = array_reverse($lastEntries);

foreach ($lastEntries as $line) {
    $parts = explode(" ; ", $line);
    if (count($parts) < 4) {
        continue; // Skip invalid log lines
    }

    $timestamp = htmlspecialchars(trim($parts[0]));
    $type = htmlspecialchars(trim($parts[1]));
    $uri = htmlspecialchars(trim($parts[2]));
    $method = htmlspecialchars(trim($parts[3]));
    $session_id = htmlspecialchars(trim($parts[4] ?? ''));
    $details = htmlspecialchars(implode(" ; ", array_slice($parts, 5)));

    echo "<tr>";
    echo "<td>$timestamp</td>";
    echo "<td>$type</td>";
    echo "<td>$uri</td>";
    echo "<td>$method</td>";
    echo "<td>$session_id</td>";
    echo "<td>$details</td>";
    echo "</tr>";
}

echo "</table>";




?>