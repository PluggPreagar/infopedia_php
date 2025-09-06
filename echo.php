<?php

// Set content type for debugging output
header('Content-Type: text/plain');

$configFile = 'infopedia.cfg';
$type = $_GET['type'] ?? "none";

include_once 'util.php';




// Output HTTP method and request URI
echo "HTTP Method: " . $_SERVER['REQUEST_METHOD'] . "\n";
echo "Request URI: " . $_SERVER['REQUEST_URI'] . "\n";

// Output GET parameters
if (!empty($_GET)) {
    echo "\nGET Parameters:\n";
    foreach ($_GET as $key => $value) {
        echo "  $key: $value\n";
    }
} else {
    echo "\nGET Parameters: None\n";
}

// Output POST parameters
if (!empty($_POST)) {
    echo "\nPOST Parameters:\n";
    foreach ($_POST as $key => $value) {
        echo "  $key: $value\n";
    }
} else {
    echo "\nPOST Parameters: None\n";
}

// Output raw input for other HTTP methods (e.g., PUT, DELETE)
$rawInput = file_get_contents('php://input');
if (!empty($rawInput)) {
    echo "\nRaw Input:\n";
    echo $rawInput . "\n";
} else {
    echo "\nRaw Input: None\n";
}

// Output headers
echo "\nHeaders:\n";
foreach (getallheaders() as $name => $value) {
    echo "  $name: $value\n";
}

?>
