<?php
    $startTime = microtime(true); // Start time measurement
    // config
    $debug= $config['debug'] ?? $debug ?? false; // Enable debug mode

    $configFile = 'infopedia.cfg';
    // Strip chars outside [a-zA-Z0-9_-] and truncate; used for SID, TID, throttle keys.
    function sanitize_id(string $val, int $max = 32): string {
        return substr(preg_replace('/[^a-zA-Z0-9_-]/', '', $val), 0, $max);
    }

    $session_id = sanitize_id($_GET['sid'] ?? $_POST['sid'] ?? '', 32);
    if (empty($session_id)) {
        $session_id = bin2hex(random_bytes(4));
    }

    $tenant_id = ($_GET['tid'] ?? $_POST['tid'] ?? '');
    if ($tenant_id !== ''
        && !in_array($tenant_id, ['default', 'none', 'all'], true)
        && sanitize_id($tenant_id, 30) !== $tenant_id
    ) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => ['code' => 'INVALID_TID', 'message' => 'Tenant ID must be 1–30 alphanumeric/-/_ characters.']]);
        exit;
    }

    // Set the default timezone to Central European Time (CET) early, before parsing timestamps
    date_default_timezone_set('Europe/Berlin');

    $since = ($_GET['since'] ?? $_GET['ts'] ?? $_POST['since'] ?? $_POST['ts'] ?? '') ; // since timestamp from GET or POST ('ts' kept as fallback alias)
    // convert timestamp to int  YYYYY/MM/DD HH:MM:SS or YYYY-MM-DD HH:MM:SS or YYYYMMDDHHMMSS or DD-MM-YYYY HH:MM:SS
    // 1767351121 == 2025-02-01 12:12:01
    $since_int = 0;
    if (!empty($since)) {
        $since_original = $since;
        if (is_numeric($since)) {
            $since_int = (int)$since;
            $since = date("Y-m-d H:i:s", $since_int);
        } else {
            // try to parse various formats YYYY/MM/DD HH:MM:SS or YYYY-MM-DD HH:MM:SS or YYYYMMDDHHMMSS
            $tmp = $since;
            // handle YYYY-MM-DD HH:MM:SS or YYYY/MM/DD HH:MM:SS
            $tmp = preg_replace('/(\d{4})[-\/](\d{2})[-\/](\d{2})[ T](\d{2}):(\d{2}):(\d{2})/', '$1-$2-$3 $4:$5:$6', $tmp);
            // handle DD-MM-YYYY HH:MM:SS
            $tmp = preg_replace('/(\d{2})[-\/](\d{2})[-\/](\d{4})[ T](\d{2}):(\d{2}):(\d{2})/', '$3-$2-$1 $4:$5:$6', $tmp);
            // handle YYYYMMDDHHMMSS
            $tmp = preg_replace('/(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', '$1-$2-$3 $4:$5:$6', $tmp);
            $since_int = strtotime($tmp);
            $since = $tmp;
        }
        if ($since === false) {
            log_error("Invalid timestamp format (" . $since_original . ")");
            die("Invalid timestamp format.");
        }
    }

    $refresh = isset($_GET['refresh']) || isset($_GET['force_update']) || isset($_POST['refresh']);

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
        // quote message to avoid log injection: strip newlines, escape the column delimiter
        $message = str_replace(["\n", "\r", " ; "], [' ', ' ', ' : '], $message);
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

    // log import — skipped for data channel when log_requests is off (default false)
    if ($type !== 'data' || !empty($config['log_requests'])) {
        $log_message = '';
        if (!empty($_GET))  { $log_message .= json_encode($_GET);  }
        if (!empty($_POST)) { $log_message .= json_encode($_POST); }
        log_to_file($log_message ?: 'no message');
    }

// ─── Notify channel ──────────────────────────────────────────────────────────

function append_incr(string $tid, array $event): void {
    $suffix = $tid !== '' ? '_' . $tid : '';
    $file_a = 'data/notify' . $suffix . '_a.jsonl';
    $file_b = 'data/notify' . $suffix . '_b.jsonl';

    $ts = time();

    // Lock _a exclusively, count same-ts lines to assign msgid, then write.
    $fp = fopen($file_a, 'a+');
    if ($fp === false) return;
    flock($fp, LOCK_EX);
    fseek($fp, 0);
    $existing = stream_get_contents($fp);
    $count    = 0;
    foreach (explode("\n", $existing) as $line) {
        if ($line === '') continue;
        $decoded = json_decode($line, true);
        if (is_array($decoded) && ($decoded['ts'] ?? 0) === $ts) {
            $count++;
        }
    }
    $event['ts']    = $ts;
    $event['msgid'] = $count + 1;
    $json = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    fwrite($fp, $json);
    flock($fp, LOCK_UN);
    fclose($fp);

    // Mirror to _b (fixed lock order: always _a before _b).
    file_put_contents($file_b, $json, FILE_APPEND | LOCK_EX);
}

function append_notify(string $tid, array $event): void {
    append_incr($tid, $event);
}

?>
