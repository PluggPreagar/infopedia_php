<?php
/*
 * util_http.php
 * HTTP response helpers: content-type negotiation and structured JSON responses.
 * Include after util.php (needs log_* functions and globals).
 */

    // Maps a format string to the appropriate Content-Type header.
    // Called early in request handling, before any output.
    function set_content_type(string $format): void {
        $contentType = match ($format) {
            'json'              => 'application/json; charset=utf-8',
            'csv'               => 'text/csv; charset=utf-8',
            'txt.0.2', 'txt.0.3' => 'text/plain; charset=utf-8',
            default             => 'text/plain; charset=utf-8',
        };
        header("Content-Type: $contentType");
    }

    // Sends a JSON response with the given HTTP status and exits.
    // Uses JSON_PRETTY_PRINT for readability in logs and curl output.
    function respond_json(mixed $data, int $status = 200): never {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    // Sends a structured error JSON response and exits.
    // $code is a machine-readable slug (e.g. 'invalid_input'); $message is human-readable.
    function respond_error(string $code, string $message, int $status): never {
        respond_json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    // Validate the ?format= query parameter. Exits with 400 if not one of the four known values.
    function validate_format(string $format): void {
        static $valid = ['json', 'csv', 'txt.0.2', 'txt.0.3'];
        if (!in_array($format, $valid, true)) {
            respond_error('INVALID_FORMAT', 'format must be one of: ' . implode(', ', $valid) . '.', 400);
        }
    }
