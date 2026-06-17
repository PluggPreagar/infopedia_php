<?php
require_once __DIR__ . '/util_test.php';
require_once __DIR__ . '/../util_entry.php';

// ─── parseEntry ─────────────────────────────────────────────────────────────
// Input:  entry column string  (the pipe-delimited part after the outer timestamp)
// Output: array with keys: path, content, type, display_ts, attrs, votes

function pe(string $input, array $expect, string $msg): void {
    assert_eq($expect, parseEntry($input), "parseEntry: $msg");
}

// minimal
pe('/climate/solutions | Solar panels.',
    ['path'=>'/climate/solutions','content'=>'Solar panels.','type'=>'.','display_ts'=>null,'attrs'=>[],'votes'=>[]],
    'minimal entry');

// entry types
pe('/a/b | Important!',
    ['path'=>'/a/b','content'=>'Important!','type'=>'!','display_ts'=>null,'attrs'=>[],'votes'=>[]],
    'type !');
pe('/a/b | A question?',
    ['path'=>'/a/b','content'=>'A question?','type'=>'?','display_ts'=>null,'attrs'=>[],'votes'=>[]],
    'type ?');
pe('/a/b | A reference>',
    ['path'=>'/a/b','content'=>'A reference>','type'=>'>','display_ts'=>null,'attrs'=>[],'votes'=>[]],
    'type >');
pe('/a/b | A note-',
    ['path'=>'/a/b','content'=>'A note-','type'=>'-','display_ts'=>null,'attrs'=>[],'votes'=>[]],
    'type -');

// no type suffix → server appends '.'
pe('/climate/solutions | Solar panels',
    ['path'=>'/climate/solutions','content'=>'Solar panels.','type'=>'.','display_ts'=>null,'attrs'=>[],'votes'=>[]],
    'missing type suffix gets dot appended');

// delete marker
pe('/climate/solutions | --',
    ['path'=>'/climate/solutions','content'=>'--','type'=>'--','display_ts'=>null,'attrs'=>[],'votes'=>[]],
    'delete marker');

// single attribute
pe('/climate/solutions | author:martin | Solar panels.',
    ['path'=>'/climate/solutions','content'=>'Solar panels.','type'=>'.','display_ts'=>null,'attrs'=>['author'=>'martin'],'votes'=>[]],
    'single attr');

// multiple attributes
pe('/climate/solutions | author:martin | priority:high | Solar panels.',
    ['path'=>'/climate/solutions','content'=>'Solar panels.','type'=>'.','display_ts'=>null,'attrs'=>['author'=>'martin','priority'=>'high'],'votes'=>[]],
    'multiple attrs');

// display timestamp
pe('/climate/solutions | 2025-09-07 20:44:54 | Solar panels.',
    ['path'=>'/climate/solutions','content'=>'Solar panels.','type'=>'.','display_ts'=>'2025-09-07 20:44:54','attrs'=>[],'votes'=>[]],
    'display timestamp');

// attr + display timestamp
pe('/climate/solutions | author:martin | 2025-09-07 20:44:54 | Solar panels.',
    ['path'=>'/climate/solutions','content'=>'Solar panels.','type'=>'.','display_ts'=>'2025-09-07 20:44:54','attrs'=>['author'=>'martin'],'votes'=>[]],
    'attr + display timestamp');

// display timestamp + attr (any order)
pe('/climate/solutions | 2025-09-07 20:44:54 | author:martin | Solar panels.',
    ['path'=>'/climate/solutions','content'=>'Solar panels.','type'=>'.','display_ts'=>'2025-09-07 20:44:54','attrs'=>['author'=>'martin'],'votes'=>[]],
    'display timestamp before attr');

// single vote
pe('/poll/q1 | votes:sid_abc:1 | Fair question?',
    ['path'=>'/poll/q1','content'=>'Fair question?','type'=>'?','display_ts'=>null,'attrs'=>[],'votes'=>['sid_abc'=>1]],
    'single vote');

// multiple votes
pe('/poll/q1 | votes:sid_abc:1 | votes:sid_def:2 | Fair question?',
    ['path'=>'/poll/q1','content'=>'Fair question?','type'=>'?','display_ts'=>null,'attrs'=>[],'votes'=>['sid_abc'=>1,'sid_def'=>2]],
    'multiple votes');

// votes + attr
pe('/poll/q1 | votes:sid_abc:1 | author:martin | Fair question?',
    ['path'=>'/poll/q1','content'=>'Fair question?','type'=>'?','display_ts'=>null,'attrs'=>['author'=>'martin'],'votes'=>['sid_abc'=>1]],
    'vote + attr');

// votes:others (aggregated key)
pe('/poll/q1 | votes:sid_own:1 | votes:others:5 | Fair question?',
    ['path'=>'/poll/q1','content'=>'Fair question?','type'=>'?','display_ts'=>null,'attrs'=>[],'votes'=>['sid_own'=>1,'others'=>5]],
    'aggregated votes:others key');

// colon in content is fine — content is always last, never parsed for attrs
pe('/a/b | See https://example.com for details.',
    ['path'=>'/a/b','content'=>'See https://example.com for details.','type'=>'.','display_ts'=>null,'attrs'=>[],'votes'=>[]],
    'colon in content is safe because content is always last');

// attr value may contain colons — key is only the part before the first colon
pe('/a/b | url:https://example.com | Content.',
    ['path'=>'/a/b','content'=>'Content.','type'=>'.','display_ts'=>null,'attrs'=>['url'=>'https://example.com'],'votes'=>[]],
    'attr value may contain colons');


// ─── sortCsvData ─────────────────────────────────────────────────────────────
// Input:  raw CSV string (may have legacy date format)
// Output: clean CSV, sorted asc by path, deduped (newest timestamp wins), deletes removed
//         outer timestamp normalised to YYYY-MM-DD HH:MM:SS
//         header row: "Timestamp,entry"

$H = "Timestamp,entry\n"; // canonical header

function sd(string $input, string $expect, string $msg): void {
    assert_eq($expect, sortCsvData($input), "sortCsvData: $msg");
}

// single row passthrough
sd($H . "2025-09-07 20:44:54,/climate/solutions | Solar panels.",
   $H . "2025-09-07 20:44:54,/climate/solutions | Solar panels.",
   'single row passthrough');

// sort ascending by path
sd($H . "2025-09-07 20:44:54,/z/last | Last.\n2025-09-07 20:44:54,/a/first | First.",
   $H . "2025-09-07 20:44:54,/a/first | First.\n2025-09-07 20:44:54,/z/last | Last.",
   'sort by path asc');

// dedup — keep row with latest outer timestamp
sd($H . "2025-09-07 20:44:54,/climate/solutions | Old.\n2025-09-08 10:00:00,/climate/solutions | New.",
   $H . "2025-09-08 10:00:00,/climate/solutions | New.",
   'dedup keeps newest');

// dedup — order of input rows does not matter
sd($H . "2025-09-08 10:00:00,/climate/solutions | New.\n2025-09-07 20:44:54,/climate/solutions | Old.",
   $H . "2025-09-08 10:00:00,/climate/solutions | New.",
   'dedup keeps newest regardless of input order');

// delete marker — newest row is delete → entry removed
sd($H . "2025-09-07 20:44:54,/climate/solutions | Solar panels.\n2025-09-08 10:00:00,/climate/solutions | --",
   $H,
   'delete marker removes entry');

// delete marker — followed by newer non-delete → entry restored
sd($H . "2025-09-07 20:44:54,/climate/solutions | --\n2025-09-08 10:00:00,/climate/solutions | Restored.",
   $H . "2025-09-08 10:00:00,/climate/solutions | Restored.",
   'newer row after delete restores entry');

// normalise DD/MM/YYYY to YYYY-MM-DD
sd($H . "07/09/2025 20:44:54,/climate/solutions | Solar panels.",
   $H . "2025-09-07 20:44:54,/climate/solutions | Solar panels.",
   'normalise DD/MM/YYYY timestamp');

// multiline quoted entry — joined with literal \n
sd($H . "2025-09-07 20:44:54,\"/climate/solutions | Line one\nline two.\"",
   $H . "2025-09-07 20:44:54,\"/climate/solutions | Line one\\nline two.\"",
   'multiline quoted entry joined');

// empty input → header only
sd($H, $H, 'empty input returns header only');


// ─── aggregateVotes ──────────────────────────────────────────────────────────
// Input:  sorted CSV string, session_id string
// Output: sorted CSV with vote rows aggregated per path
//         own sid → preserved as votes:<sid>:<total>
//         other sids → summed into votes:others:<total>
//         content taken from the row with the latest outer timestamp

$H2 = "Timestamp,entry\n";

function av(string $input, string $sid, string $expect, string $msg): void {
    assert_eq($expect, aggregateVotes($input, $sid), "aggregateVotes: $msg");
}

// non-vote rows pass through unchanged
av($H2 . "2025-09-07 20:44:54,/climate/solutions | Solar panels.",
   'sid_own',
   $H2 . "2025-09-07 20:44:54,/climate/solutions | Solar panels.",
   'non-vote rows unchanged');

// own vote — sid preserved
av($H2 . "2025-09-07 20:44:54,/poll/q1 | votes:sid_own:1 | Fair question?",
   'sid_own',
   $H2 . "2025-09-07 20:44:54,/poll/q1 | votes:sid_own:1 | Fair question?",
   'own vote preserved');

// other session vote — anonymised
av($H2 . "2025-09-07 20:44:54,/poll/q1 | votes:sid_other:2 | Fair question?",
   'sid_own',
   $H2 . "2025-09-07 20:44:54,/poll/q1 | votes:others:2 | Fair question?",
   'other vote anonymised');

// own + other votes — own preserved, others summed
av($H2 . "2025-09-07 20:44:54,/poll/q1 | votes:sid_own:1 | Fair question?\n"
       . "2025-09-07 20:45:00,/poll/q1 | votes:sid_other:2 | Fair question?",
   'sid_own',
   $H2 . "2025-09-07 20:45:00,/poll/q1 | votes:sid_own:1 | votes:others:2 | Fair question?",
   'own + other votes');

// multiple other sessions — all summed into others
av($H2 . "2025-09-07 20:44:54,/poll/q1 | votes:sid_a:1 | Q?\n"
       . "2025-09-07 20:45:00,/poll/q1 | votes:sid_b:3 | Q?\n"
       . "2025-09-07 20:45:30,/poll/q1 | votes:sid_c:2 | Q?",
   'sid_own',
   $H2 . "2025-09-07 20:45:30,/poll/q1 | votes:others:6 | Q?",
   'multiple others summed');

// two different paths — each aggregated independently
av($H2 . "2025-09-07 20:44:54,/poll/q1 | votes:sid_own:1 | Q1?\n"
       . "2025-09-07 20:44:54,/poll/q2 | votes:sid_other:2 | Q2?",
   'sid_own',
   $H2 . "2025-09-07 20:44:54,/poll/q1 | votes:sid_own:1 | Q1?\n"
       . "2025-09-07 20:44:54,/poll/q2 | votes:others:2 | Q2?",
   'two paths aggregated independently');

// mixed entries and votes — votes aggregated, entries untouched
av($H2 . "2025-09-07 20:44:54,/climate/solutions | Solar panels.\n"
       . "2025-09-07 20:44:54,/poll/q1 | votes:sid_own:1 | Q?",
   'sid_own',
   $H2 . "2025-09-07 20:44:54,/climate/solutions | Solar panels.\n"
       . "2025-09-07 20:44:54,/poll/q1 | votes:sid_own:1 | Q?",
   'mixed entries and votes');
