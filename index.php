<?php
    $type = "web";
    include_once 'util.php';


$filePath = 'infopage.html';
$response = @file_get_contents($filePath);
if ($response === false) {
    log_error("Failed to load infopage.html");
    die("Error loading page.");
}

$currentTimestamp = date("Y-m-d H:i:s");
if (file_exists($filePath)) {
    $fileTimestamp = date("Y-m-d H:i:s", filemtime($filePath));
} else {
    $fileTimestamp = "";
}

$response = str_replace("<!-- timestamp -->", "created: $currentTimestamp / data updated: $fileTimestamp / ", $response);

echo $response;

?>
