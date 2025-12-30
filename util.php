<?php
    $startTime = microtime(true); // Start time measurement
    // config
    $debug= $config['debug'] ?? $debug ?? false; // Enable debug mode

    $configFile = 'infopedia.cfg';
    $type = $_GET['type'] ?? $type ?? "none";

    $session_id = ($_GET['sid'] ?? $_POST['sid'] ?? '') ; // session id from GET or POST (==systemid)
    // random session id if not provided
    if (empty($session_id)) {
        $session_id = bin2hex(random_bytes(4)); // Generate a random session ID
    }

    $tenant_id = ($_GET['tid'] ?? $_POST['tid'] ?? '') ; // tenant_id id from GET or POST (==tenantid)
    // check tenant id is alphanumeric only + not longer than 30 chars
    if (!empty($tenant_id) && !preg_match('/^[a-zA-Z0-9_-]+$/', $tenant_id)
            && $tenant_id !== 'default'
            && $tenant_id !== 'none'
            && $tenant_id !== 'all'
            && strlen($tenant_id) <= 30
            ) {
        die("Invalid tenant ID.");
    }

    $last_timestamp = ($_GET['ts'] ?? $_POST['ts'] ?? '') ; // last timestamp from GET or POST


    // Set the default timezone to Central European Time (CET)
    date_default_timezone_set('Europe/Berlin');

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

    $logFile = $config['logFile'] ?? 'infopedia.log'; // Path to the log file

    // logging functions

    function log_to_file($message) {
        global $logFile, $type, $session_id, $tenant_id;
        $logMessage = "[" . date('Y-m-d H:i:s') . "] ; ";
        if (isset($_SERVER) && isset($_SERVER['REQUEST_URI'])) {
            // skipp during test
            $logMessage .= " " . ( $type ?? "none" ) . " ; ";
            $logMessage .= " " . $_SERVER['REQUEST_URI'] . " ; ";
            $logMessage .= " " . $_SERVER['REQUEST_METHOD'] . " ; ";
            $logMessage .= " " . $session_id . (isset($tenant_id) ? "@".$tenant_id : "") . " ; ";
            $logMessage .= " " . $_SERVER['SCRIPT_NAME'] . " ; ";
        }
        // quote message to avoid log injection, replace newlines with spaces
        $message = str_replace(["\n", "\r"], ' ', $message);
        // shorten log message to 500 chars
        if (strlen($message) > 500) {
            $message = substr($message, 0, 500) . "...(truncated)";
        }
        $logMessage .= " " . $message . " ; ";
        $logMessage .= "\n";
        // Append the log message to the file
        file_put_contents($logFile, $logMessage, FILE_APPEND);
    }


    function log_debug($message) {
        // Uncomment the next line to enable debugging output
        if (!$GLOBALS['debug']) {
            return; // Skip debug output if debug mode is off
        }
        log_to_file( "DEBUG: " . $message );
    }

    function log_warn($message) {
        // Uncomment the next line to enable warning output
        /*
        if (!$GLOBALS['debug']) {
            return; // Skip debug output if debug mode is off
        }
        */
        log_to_file( "WARNING: " . $message );
    }

    function log_info($message) {
        log_to_file( "INFO: " . $message );
    }

    function log_error($message) {
        log_to_file( "ERROR: " . $message );
    }

    function log_return($message) {
        global $startTime;
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        log_to_file( "RETURN: " . $message . " in " . number_format($duration, 4) . " seconds" );
    }

    // always log import
    $log_message="";
    // Log GET parameters if available
    if (!empty($_GET)) {
        $log_message .= json_encode($_GET) ;
    }
    // Log POST data if available
    if (!empty($_POST)) {
        $log_message .= json_encode($_POST) ;
    }
    log_to_file( $log_message ?? "no message" );

?>
