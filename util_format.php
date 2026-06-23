<?php
/*
 * util_format.php
 * CSV-to-output conversion helpers (JSON, txt.0.2, txt.0.3).
 * Plain procedural PHP 8.0+. No classes, no namespaces, no Composer.
 */

require_once __DIR__ . '/util_entry.php';

// ─── csv_quote ───────────────────────────────────────────────────────────────

/**
 * RFC 4180 quote a CSV field value if it contains commas, quotes, or newlines.
 */
function csv_quote(string $value): string {
    if (strpbrk($value, ',"' . "\n\r") !== false) {
        return '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}

// ─── csv_as_format ───────────────────────────────────────────────────────────

/**
 * Convert sorted CSV to the requested output format string.
 * Returns the formatted content; caller is responsible for Content-Type and output.
 */
function csv_as_format(string $csv, string $format): string {
    return match ($format) {
        'json'    => json_encode(
                         csv_to_json($csv),
                         JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
                     ),
        'txt.0.2' => csv_to_txt02($csv),
        'txt.0.3' => csv_to_txt03($csv),
        default   => $csv,
    };
}

// ─── respond_csv_as_format ───────────────────────────────────────────────────

/**
 * Set Content-Type, write body, and exit.
 * Requires util_http.php (set_content_type, respond_json) to be loaded first.
 */
function respond_csv_as_format(string $csv, string $format): never {
    if ($format === 'json') {
        respond_json(csv_to_json($csv));
    }
    set_content_type($format);
    echo csv_as_format($csv, $format);
    exit;
}

// ─── _parse_csv_rows ─────────────────────────────────────────────────────────

/**
 * Shared CSV row iterator: skip header, return [outer_ts, parsed] pairs.
 *
 * @internal
 * @return array  list of ['outer_ts'=>string, 'parsed'=>array]
 */
function _parse_csv_rows(string $csv): array {
    $lines = explode("\n", $csv);
    array_shift($lines); // discard header

    $rows = [];
    foreach ($lines as $line) {
        if (trim($line) === '') {
            continue;
        }

        $parts = str_getcsv($line);
        if (count($parts) < 2) {
            continue;
        }

        $outer_ts  = $parts[0];
        $entry_raw = $parts[1]; // str_getcsv strips surrounding quotes

        $parsed = parseEntry($entry_raw);
        if ($parsed['path'] === '') {
            continue;
        }

        $rows[] = ['outer_ts' => $outer_ts, 'parsed' => $parsed];
    }

    return $rows;
}

// ─── csv_to_json ─────────────────────────────────────────────────────────────

/**
 * Convert a sorted CSV string to an associative array keyed by /path/node.
 *
 * @param string $csv  Sorted CSV (header "Timestamp,entry" + data rows).
 * @return array       Keyed by /path/node.
 */
function csv_to_json(string $csv): array {
    $result = [];

    foreach (_parse_csv_rows($csv) as $row) {
        $parsed = $row['parsed'];

        // display_ts overrides outer timestamp when present.
        $ts = $parsed['display_ts'] ?? $row['outer_ts'];

        $entry = [
            'timestamp' => $ts,
            'message'   => $parsed['content'],
            'attrs'     => $parsed['attrs'],
        ];

        if (!empty($parsed['votes'])) {
            $entry['votes'] = $parsed['votes'];
        }

        $result[$parsed['path']] = $entry;
    }

    return $result;
}

// ─── csv_to_txt02 ────────────────────────────────────────────────────────────

/**
 * Convert a sorted CSV string to txt.0.2 format.
 *
 * Line format: /path/node | YYYY-MM-DD HH:MM:SS | message<type>
 * Attrs and votes are omitted.
 *
 * @param string $csv  Sorted CSV.
 * @return string      Newline-joined lines, no trailing newline. Empty string if no rows.
 */
function csv_to_txt02(string $csv): string {
    $lines = [];

    foreach (_parse_csv_rows($csv) as $row) {
        $parsed = $row['parsed'];
        $ts     = $parsed['display_ts'] ?? $row['outer_ts'];
        $lines[] = $parsed['path'] . ' | ' . $ts . ' | ' . $parsed['content'];
    }

    return implode("\n", $lines);
}

// ─── csv_to_txt03 ────────────────────────────────────────────────────────────

/**
 * Convert a sorted CSV string to txt.0.3 format.
 *
 * Line format: <indent>message<type>
 * Indent = 4 spaces × (path depth − 1), where depth = substr_count(path, '/').
 * Path is stripped; only message is shown.
 *
 * @param string $csv  Sorted CSV.
 * @return string      Newline-joined lines, no trailing newline. Empty string if no rows.
 */
function csv_to_txt03(string $csv): string {
    $lines = [];

    foreach (_parse_csv_rows($csv) as $row) {
        $parsed = $row['parsed'];
        // depth = slash count − 1 (spec): /a/node has 2 slashes → depth 1 → 0 indent levels
        $depth  = substr_count($parsed['path'], '/') - 1;
        $indent = str_repeat('    ', max(0, $depth - 1));
        $lines[] = $indent . $parsed['content'];
    }

    return implode("\n", $lines);
}
