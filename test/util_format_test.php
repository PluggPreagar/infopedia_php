<?php
require_once __DIR__ . '/util_test.php';
require_once __DIR__ . '/../util_entry.php';
require_once __DIR__ . '/../util_format.php';

// Input to all functions: sorted+deduped CSV produced by sortCsvData()
// Header row: "Timestamp,entry"

$H = "Timestamp,entry\n";

// ─── csv_to_json ──────────────────────────────────────────────────────────────
// Output: array keyed by /path/node, ready for json_encode

function cj(string $csv, array $expect, string $msg): void {
    assert_eq($expect, csv_to_json($csv), "csv_to_json: $msg");
}

// empty
cj($H, [], 'empty csv');

// basic entry — outer timestamp used
cj($H . "2025-09-07 20:44:54,/climate/solutions | Solar panels.",
   ['/climate/solutions' => ['timestamp'=>'2025-09-07 20:44:54', 'message'=>'Solar panels.', 'attrs'=>[]]],
   'basic entry');

// display_ts overrides outer timestamp in output
cj($H . "2025-09-07 20:44:54,/climate/solutions | 2024-01-01 09:00:00 | Solar panels.",
   ['/climate/solutions' => ['timestamp'=>'2024-01-01 09:00:00', 'message'=>'Solar panels.', 'attrs'=>[]]],
   'display_ts overrides outer timestamp');

// attrs included
cj($H . "2025-09-07 20:44:54,/climate/solutions | author:martin | Solar panels.",
   ['/climate/solutions' => ['timestamp'=>'2025-09-07 20:44:54', 'message'=>'Solar panels.', 'attrs'=>['author'=>'martin']]],
   'entry with attr');

// vote entry — votes key present, attrs empty
cj($H . "2025-09-07 20:44:54,/poll/q1 | votes:sid_own:1 | votes:others:5 | Fair question?",
   ['/poll/q1' => ['timestamp'=>'2025-09-07 20:44:54', 'message'=>'Fair question?', 'attrs'=>[], 'votes'=>['sid_own'=>1,'others'=>5]]],
   'vote entry includes votes key');

// no votes key when no votes
cj($H . "2025-09-07 20:44:54,/a/b | Content.",
   ['/a/b' => ['timestamp'=>'2025-09-07 20:44:54', 'message'=>'Content.', 'attrs'=>[]]],
   'no votes key when no votes');

// multiple entries — all present, keyed by path
cj($H . "2025-09-07 20:44:54,/a/first | First.\n2025-09-07 20:44:54,/z/last | Last.",
   [
     '/a/first' => ['timestamp'=>'2025-09-07 20:44:54', 'message'=>'First.', 'attrs'=>[]],
     '/z/last'  => ['timestamp'=>'2025-09-07 20:44:54', 'message'=>'Last.',  'attrs'=>[]],
   ],
   'multiple entries');


// ─── csv_to_txt02 ─────────────────────────────────────────────────────────────
// Output: string of newline-joined lines
// Line format: /path/node | YYYY-MM-DD HH:MM:SS | message<type>

function ct2(string $csv, string $expect, string $msg): void {
    assert_eq($expect, csv_to_txt02($csv), "csv_to_txt02: $msg");
}

// empty
ct2($H, '', 'empty csv');

// basic
ct2($H . "2025-09-07 20:44:54,/climate/solutions | Solar panels.",
    "/climate/solutions | 2025-09-07 20:44:54 | Solar panels.",
    'basic entry');

// display_ts used in output
ct2($H . "2025-09-07 20:44:54,/climate/solutions | 2024-01-01 09:00:00 | Solar panels.",
    "/climate/solutions | 2024-01-01 09:00:00 | Solar panels.",
    'display_ts used as timestamp');

// attrs omitted from txt.0.2 output
ct2($H . "2025-09-07 20:44:54,/climate/solutions | author:martin | Solar panels.",
    "/climate/solutions | 2025-09-07 20:44:54 | Solar panels.",
    'attrs omitted');

// multiple entries — newline-joined
ct2($H . "2025-09-07 20:44:54,/a/first | First.\n2025-09-07 20:44:54,/z/last | Last.",
    "/a/first | 2025-09-07 20:44:54 | First.\n/z/last | 2025-09-07 20:44:54 | Last.",
    'multiple entries newline-joined');

// vote entry — votes omitted, content shown
ct2($H . "2025-09-07 20:44:54,/poll/q1 | votes:sid_own:1 | votes:others:5 | Fair question?",
    "/poll/q1 | 2025-09-07 20:44:54 | Fair question?",
    'vote entry shows content only');


// ─── csv_to_txt03 ─────────────────────────────────────────────────────────────
// Output: string of newline-joined lines
// Line format: <indent>message<type>
// Indent: 4 spaces per path depth level minus 1
// (depth = number of "/" in path − 1, so /a = depth 1 = 0 indent levels)

function ct3(string $csv, string $expect, string $msg): void {
    assert_eq($expect, csv_to_txt03($csv), "csv_to_txt03: $msg");
}

// empty
ct3($H, '', 'empty csv');

// depth 1 — /a/node — no indent
ct3($H . "2025-09-07 20:44:54,/a/node | Top level.",
    "Top level.",
    'depth 1 no indent');

// depth 2 — /a/b/node — 4 spaces
ct3($H . "2025-09-07 20:44:54,/a/b/node | Nested.",
    "    Nested.",
    'depth 2 one indent level');

// depth 3 — /a/b/c/node — 8 spaces
ct3($H . "2025-09-07 20:44:54,/a/b/c/node | Deep.",
    "        Deep.",
    'depth 3 two indent levels');

// mixed depths
ct3($H . "2025-09-07 20:44:54,/a/node | Top.\n2025-09-07 20:44:54,/a/b/node | Nested.",
    "Top.\n    Nested.",
    'mixed depths');

// path stripped — only message shown
ct3($H . "2025-09-07 20:44:54,/climate/solutions | Solar panels.",
    "Solar panels.",
    'path stripped from output');
