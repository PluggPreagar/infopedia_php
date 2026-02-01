<?php

function formatEntry($entry) {
    $timestamp = null;
    $path = null;
    $node = null;
    $path_node = null;
    $message = null;
    $vote = null;
    // empty
    log_debug("formatEntry: input: '" . $entry . "'\n");
    if (empty($entry) || $entry === "/" || str_starts_with($entry, "Timestamp")) {
        return "";
    }
    if (!str_starts_with($entry, "/")) { // expect old format - contain delimiter "," and nested " | "
        // 2025-01-01 12:00:00 , /parent | node | message [ | vote]
        // /parent | node | message [ | vote]     --- w/o timestamp
        log_debug("formatEntry: old format detected");
        $parts = explode(",", $entry, 2);
        if (count($parts) == 2) {
            log_debug("formatEntry: parts[0]: '" . $parts[0] . "'  parts[1]: '" . $parts[1] . "'\n");
            $timestamp = trim($parts[0]);
            $data = trim($parts[1]);
            // $data might be quoted - remove quotes
            if (str_starts_with($data, '"') && str_ends_with($data, '"')) {
                $data = substr($data, 1, -1);
            }
            $data_parts = preg_split( "/\s*\|\s*/", $data, 4);
            if (count($data_parts) > 2) {
                log_debug("formatEntry: data_parts: " . print_r($data_parts, true) . "\n");
                $path = trim($data_parts[0]);
                $node = trim($data_parts[1]);
                $message = trim($data_parts[2]);
                $vote = (count($data_parts) == 4) ? trim($data_parts[3]) : null;
            } else {
                log_warn("formatEntry: old format entry could not be parsed: '" . $entry . "'\n");
            }
        } else {
            log_warn("formatEntry: old format entry could not be parsed: '" . $entry . "'\n");
        }
        $path_node = $path . "/" . $node;
    } else {
        // new format: /parent/node , timestamp , message [ , vote ]}  -- message might be quoted!
        log_debug("formatEntry: new format detected");
        $parts = explode(",", $entry, 3);
        if (count($parts) >= 3) {
            log_debug("formatEntry: parts[0]: '" . $parts[0] . "'  [1]: '" . $parts[1] . "'  [2]: '" . $parts[2] . "'\n");
            $path_node = trim($parts[0]);
            $timestamp = trim($parts[1]);
            $message = trim($parts[2]); // might be message and vote !!
            //- if ends with [.!?"],[+-]?1
            if (preg_match('/(.*),\s*([+-]?1)$/', $message, $matches)) {
                $message = trim($matches[1]);
                $vote = trim($matches[2]);
            }
        }
    }
    if (empty($path_node)) {
        log_warn("formatEntry: mandatory fields missing - cannot format entry: '" . $entry . "'\n");
        return "";
    }
    // add timestamp  YYYYY-MM-DD HH:MM:SS  if missing
    if (empty($timestamp)) {
        $timestamp = date("Y-m-d H:i:s");
    }
    // validate mandatory fields - starts with multiple "//+" -> "/"
    if (str_starts_with($path_node, "//")) {
        $path_node = "/" . ltrim($path_node, "/");
    }
    //
    if (!empty($message)) {
        $message = str_replace(["\n", "\r"], '\\n', $message); // replace newlines in message with \n
        // skipp if already quoted
        if (!str_starts_with($message, '"') /* message and vote ...  && str_ends_with($message, '"'))*/ ) {
            if (strpos($message, ',') !== false || strpos($message, '"') !== false) {
                $message_escaped = str_replace('"', '""', $message); // escape quotes by doubling them
                $message = '"' . $message_escaped . '"';
            }
        }
    }
    // rebuild entry in new format   /parent/node[:[attr]:sid] , timestamp , message [ , vote ]
    if (!empty($vote)) {
        // ensure path_node das ":vote:" part
        if (!str_contains($path_node, "::Vote::")) {
            $path_node .= "::Vote::unknown";
        }
        $message .= "," . $vote;
    }
    $entry = $path_node . "," . $timestamp . "," . $message;
    return $entry;
}





?>