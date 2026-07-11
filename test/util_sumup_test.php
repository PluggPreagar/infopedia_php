<?php
require_once __DIR__ . '/util_test.php';
require_once __DIR__ . '/../util_sumup.php';

// ─── T05: util_sumup.php — load/save/read_tail primitives ──────────────────

// T-B1: save + load round-trip
$tmpfile = tempnam(sys_get_temp_dir(), 'sumup_');
$src = 'test.csv';
$data_in = ['path1' => ['ts' => '2026-07-11 10:00:00']];

sumup_save($tmpfile, $src, 100, $data_in);
$loaded = sumup_load($tmpfile, $src);

assert_eq(true, $loaded !== null, 'T-B1: sumup load returns non-null after save');
assert_eq('test.csv', $loaded['src'], 'T-B1: src matches');
assert_eq(100, $loaded['offset'], 'T-B1: offset matches');
assert_eq($data_in, $loaded['nodes'], 'T-B1: nodes match');

// T-B2: load with wrong src → null
$loaded_wrong = sumup_load($tmpfile, 'different.csv');
assert_eq(null, $loaded_wrong, 'T-B2: wrong src returns null');

// T-B3: load when offset > filesize → null (stale)
// Create a real src file, then save a sumup with offset > filesize
$src_file = tempnam(sys_get_temp_dir(), 'src_');
file_put_contents($src_file, "Timestamp,entry\n2026-07-11 10:00:00,/test | data\n");
$src_file_size = filesize($src_file);  // ~60 bytes

$tmpfile2 = tempnam(sys_get_temp_dir(), 'sumup_');
sumup_save($tmpfile2, $src_file, $src_file_size + 100, []); // offset > filesize
$loaded_stale = sumup_load($tmpfile2, $src_file);
assert_eq(null, $loaded_stale, 'T-B3: stale offset (offset > filesize) returns null');

// T-B4: read_tail from 0 → all lines, new_offset == filesize
$tail = sumup_read_tail($src_file, 0);
assert_eq(2, count($tail['lines']), 'T-B4: read_tail from 0 gets both lines');
assert_eq('Timestamp,entry', $tail['lines'][0], 'T-B4: header line present');
assert_eq('2026-07-11 10:00:00,/test | data', $tail['lines'][1], 'T-B4: data line present');
assert_eq($src_file_size, $tail['new_offset'], 'T-B4: new_offset == filesize');

// T-B5: read_tail from previous offset after 1 append
$offset_mid = filesize($src_file);
file_put_contents($src_file, "2026-07-11 11:00:00,/test2 | more\n", FILE_APPEND);
$tail2 = sumup_read_tail($src_file, $offset_mid);
assert_eq(1, count($tail2['lines']), 'T-B5: read_tail from mid yields only new line');
assert_eq('2026-07-11 11:00:00,/test2 | more', $tail2['lines'][0], 'T-B5: new line correct');

// T-B6: partial last line (no trailing \n) → excluded
$src_file3 = tempnam(sys_get_temp_dir(), 'src_');
file_put_contents($src_file3, "Timestamp,entry\n2026-07-11 10:00:00,/test | data\n2026-07-11 11:00:00,/incomplete");
$tail3 = sumup_read_tail($src_file3, 0);
assert_eq(2, count($tail3['lines']), 'T-B6: partial line excluded (only 2 complete lines)');
$expected_offset = filesize($src_file3) - strlen("2026-07-11 11:00:00,/incomplete");
assert_eq($expected_offset, $tail3['new_offset'], 'T-B6: offset before incomplete line');

// T-B7: multiline quoted row in tail → joined to one logical line
$src_file4 = tempnam(sys_get_temp_dir(), 'src_');
file_put_contents($src_file4, "Timestamp,entry\n2026-07-11 10:00:00,\"/test | line1\nline2\"\n");
$tail4 = sumup_read_tail($src_file4, 0);
assert_eq(2, count($tail4['lines']), 'T-B7: multiline entry joined (2 logical lines: header + multiline)');
assert_eq('2026-07-11 10:00:00,"/test | line1\\nline2"', $tail4['lines'][1], 'T-B7: multiline row joined with \\n');
assert_eq(filesize($src_file4), $tail4['new_offset'], 'T-B7: offset at EOF');

// T-B8: newer-wins — save(offset=100) then save(offset=50) → load returns offset 100
$tmpfile8 = tempnam(sys_get_temp_dir(), 'sumup_');
sumup_save($tmpfile8, 'src.csv', 100, ['a' => 1]);
sumup_save($tmpfile8, 'src.csv', 50, ['b' => 2]);  // older offset — should not overwrite
$loaded8 = sumup_load($tmpfile8, 'src.csv');
assert_eq(100, $loaded8['offset'], 'T-B8: newer-wins keeps offset 100');
assert_eq(['a' => 1], $loaded8['nodes'], 'T-B8: nodes unchanged (newer kept)');

// T-B8b: corrupt sumup file → load returns null
$tmpfile_corrupt = tempnam(sys_get_temp_dir(), 'corrupt_');
file_put_contents($tmpfile_corrupt, "{ broken json");
$loaded_corrupt = sumup_load($tmpfile_corrupt, 'any.csv');
assert_eq(null, $loaded_corrupt, 'T-B8b: corrupt JSON returns null');

// T-B13 (T13): identity-mode joiner ($joiner = null) — plain lines, no CSV quote
// joining/filtering. Used by util_data.php's stats log (not CSV-quoted).
$src_b13 = tempnam(sys_get_temp_dir(), 'src_');
file_put_contents($src_b13, "line one\nline \"two\"\nline three\n");
$tail_b13 = sumup_read_tail($src_b13, 0, null);
assert_eq(3, count($tail_b13['lines']), 'T-B13: identity mode returns 3 plain lines (no joining)');
assert_eq('line one', $tail_b13['lines'][0], 'T-B13: line 1 verbatim');
assert_eq('line "two"', $tail_b13['lines'][1], 'T-B13: line 2 verbatim (unbalanced quote kept)');
assert_eq(filesize($src_b13), $tail_b13['new_offset'], 'T-B13: new_offset at EOF');
@unlink($src_b13);

// Cleanup
@unlink($tmpfile);
@unlink($tmpfile2);
@unlink($src_file);
@unlink($src_file3);
@unlink($src_file4);
@unlink($tmpfile8);
@unlink($tmpfile_corrupt);

// ─── T06: sumup_update() orchestration + idempotency ──────────────────────

// Simple merge callback: count lines per first field
function simple_merge(array $nodes, string $line): array {
    $parts = explode(',', $line, 2);
    if (count($parts) < 2) return $nodes;
    $key = $parts[0];
    $nodes[$key] = ($nodes[$key] ?? 0) + 1;
    return $nodes;
}
function simple_init(): array { return []; }

// T-B9: fresh build (no sumup file) creates it
$src_b9 = tempnam(sys_get_temp_dir(), 'src_');
$sumup_b9 = tempnam(sys_get_temp_dir(), 'sumup_');
@unlink($sumup_b9);  // Remove so it's fresh

file_put_contents($src_b9, "Timestamp,entry\n");
file_put_contents($src_b9, "a,1\nb,1\na,1\n", FILE_APPEND);

$nodes_b9 = sumup_update($sumup_b9, $src_b9, 'simple_merge', 'simple_init');
assert_eq(true, file_exists($sumup_b9), 'T-B9: sumup file created');
assert_eq(2, $nodes_b9['a'], 'T-B9: a counted twice');
assert_eq(1, $nodes_b9['b'], 'T-B9: b counted once');

// T-B10: incremental == full rebuild (core invariant)
$src_b10 = tempnam(sys_get_temp_dir(), 'src_');
$sumup_b10 = tempnam(sys_get_temp_dir(), 'sumup_');
@unlink($sumup_b10);

// Build from partial
file_put_contents($src_b10, "Timestamp,entry\na,1\nb,1\n");
$nodes_b10_partial = sumup_update($sumup_b10, $src_b10, 'simple_merge', 'simple_init');

// Append more
file_put_contents($src_b10, "a,1\nc,1\n", FILE_APPEND);
$nodes_b10_incr = sumup_update($sumup_b10, $src_b10, 'simple_merge', 'simple_init');

// Full rebuild (delete sumup first)
$sumup_b10_fresh = tempnam(sys_get_temp_dir(), 'sumup_fresh_');
$nodes_b10_full = sumup_update($sumup_b10_fresh, $src_b10, 'simple_merge', 'simple_init');

assert_eq($nodes_b10_full, $nodes_b10_incr, 'T-B10: incremental == full rebuild');

// T-B11: idempotency — update twice with no appends → no save
$src_b11 = tempnam(sys_get_temp_dir(), 'src_');
$sumup_b11 = tempnam(sys_get_temp_dir(), 'sumup_');
@unlink($sumup_b11);

file_put_contents($src_b11, "Timestamp,entry\na,1\n");
$nodes_b11_1 = sumup_update($sumup_b11, $src_b11, 'simple_merge', 'simple_init');
$mtime_1 = filemtime($sumup_b11);
sleep(1);  // Ensure time difference if file were written

$nodes_b11_2 = sumup_update($sumup_b11, $src_b11, 'simple_merge', 'simple_init');
$mtime_2 = filemtime($sumup_b11);

assert_eq($nodes_b11_1, $nodes_b11_2, 'T-B11: nodes identical on second update');
assert_eq($mtime_1, $mtime_2, 'T-B11: file not rewritten (mtime unchanged)');

// T-B12: stale source (truncated) → transparent rebuild
$src_b12 = tempnam(sys_get_temp_dir(), 'src_');
$sumup_b12 = tempnam(sys_get_temp_dir(), 'sumup_');
@unlink($sumup_b12);

file_put_contents($src_b12, "Timestamp,entry\na,1\nb,1\nc,1\n");
$nodes_b12_full = sumup_update($sumup_b12, $src_b12, 'simple_merge', 'simple_init');
assert_eq(3, count($nodes_b12_full), 'T-B12: initial build has 3 keys');

// Truncate src to shorter content
file_put_contents($src_b12, "Timestamp,entry\nx,1\n");
$nodes_b12_stale = sumup_update($sumup_b12, $src_b12, 'simple_merge', 'simple_init');
assert_eq(1, count($nodes_b12_stale), 'T-B12: stale rebuild only has 1 key (x)');
assert_eq(1, $nodes_b12_stale['x'], 'T-B12: x=1 correct');

// Cleanup
@unlink($src_b9);
@unlink($sumup_b9);
@unlink($src_b10);
@unlink($sumup_b10);
@unlink($sumup_b10_fresh);
@unlink($src_b11);
@unlink($sumup_b11);
@unlink($src_b12);
@unlink($sumup_b12);

// ─── T07/T08: Votes merge callback + projection (golden test) ────────────────

// Helper: build nodes from fixture CSV
function _sumup_nodes_from_csv(string $csv): array {
    $nodes = [];
    $lines = explode("\n", $csv);
    array_shift($lines);  // skip header
    $lines = csv_join_wrapped_lines($lines);
    foreach ($lines as $line) {
        if (trim($line) !== '') {
            $nodes = votes_sumup_merge_line($nodes, $line);
        }
    }
    return $nodes;
}

// Golden fixture: mixed vote / non-vote / multi-sid
$golden_fixture = "Timestamp,entry\n"
    . "2025-09-07 20:44:54,/climate/solutions | Solar panels.\n"
    . "2025-09-07 20:44:54,/poll/q1 | votes:sid_a:1 | Fair question?\n"
    . "2025-09-07 20:45:00,/poll/q1 | votes:sid_b:2 | Fair question?\n"
    . "2025-09-07 20:45:10,/poll/q2 | signed:sid_a:1 | Petition.\n"
    . "2025-09-07 20:46:00,/poll/q1 | votes:sid_a:1 | Fair question?";

// T-C8: golden — projection byte-compatible with legacy (sid_a)
$legacy = aggregateVotes(sortCsvData($golden_fixture, false), 'sid_a');
$sumup  = votes_sumup_project(_sumup_nodes_from_csv($golden_fixture), 'sid_a');
assert_eq($legacy, $sumup, 'T-C8: projection byte-compatible (sid_a)');

// T-C9: different viewer (sid_b)
assert_eq(aggregateVotes(sortCsvData($golden_fixture, false), 'sid_b'),
          votes_sumup_project(_sumup_nodes_from_csv($golden_fixture), 'sid_b'),
          'T-C9: projection byte-compatible (sid_b)');

// T-C10: own vote nets 0 → no votes: attr
$net_zero_fixture = "Timestamp,entry\n"
    . "2025-09-07 20:44:54,/poll/q1 | votes:alice:1 | Q?\n"
    . "2025-09-07 20:45:00,/poll/q1 | votes:alice:-1 | Q?";
$projection_zero = votes_sumup_project(_sumup_nodes_from_csv($net_zero_fixture), 'alice');
assert_eq(false, strpos($projection_zero, 'votes:alice'), 'T-C10: net-zero vote has no votes:alice attr');

// T-C7b: order-insensitivity for counts
$shuffled_fixture = "Timestamp,entry\n"
    . "2025-09-07 20:45:00,/poll/q1 | votes:sid_b:2 | Fair question?\n"
    . "2025-09-07 20:44:54,/poll/q1 | votes:sid_a:1 | Fair question?";
$proj_shuffled = votes_sumup_project(_sumup_nodes_from_csv($shuffled_fixture), 'viewer');
// Should still have both sid_a:1 and sid_b:2 aggregated
assert_eq(true, strpos($proj_shuffled, 'votes:others:3') !== false, 'T-C7b: order-insensitive count (3 = 1+2)');

// ─── T12: Entries incremental — merge/project golden tests ───────────────────

// Helper: build entries nodes directly from a CSV fixture string (no file I/O).
function _entries_nodes_from_csv(string $csv): array {
    $nodes = [];
    $lines = explode("\n", $csv);
    array_shift($lines); // header
    $lines = csv_join_wrapped_lines($lines);
    foreach ($lines as $line) {
        if (trim($line) !== '') {
            $nodes = entries_sumup_merge_line($nodes, $line);
        }
    }
    return $nodes;
}

// T-D1: projection(fixture) == sortCsvData(fixture) — incl. delete marker + multiline row
$entries_fixture = "Timestamp,entry\n"
    . "2025-09-07 20:44:54,/climate/solutions | Solar panels.\n"
    . "2025-09-07 20:45:00,/climate/other | Wind power.\n"
    . "2025-09-07 20:46:00,/climate/gone | --\n"
    . "2025-09-07 20:47:00,\"/climate/multi | line one\nline two.\"";
assert_eq(
    sortCsvData($entries_fixture),
    entries_sumup_project(_entries_nodes_from_csv($entries_fixture)),
    'T-D1: entries projection byte-identical to sortCsvData (incl. delete + multiline)'
);

// T-D2: incremental via sumup_update == full rebuild via sortCsvData
$src_d2   = tempnam(sys_get_temp_dir(), 'entries_src_');
$sumup_d2 = tempnam(sys_get_temp_dir(), 'entries_sumup_');
@unlink($sumup_d2);

file_put_contents($src_d2, "Timestamp,entry\n"
    . "2025-09-07 20:44:54,/a/one | First.\n"
    . "2025-09-07 20:45:00,/a/two | Second.\n");
$nodes_d2_partial = sumup_update($sumup_d2, $src_d2, 'entries_sumup_merge_line', fn() => []);

// Append: a newer row for existing path + one new path
file_put_contents($src_d2,
    "2025-09-07 20:46:00,/a/one | First updated.\n"
    . "2025-09-07 20:47:00,/a/three | Third.\n", FILE_APPEND);
$nodes_d2_incr = sumup_update($sumup_d2, $src_d2, 'entries_sumup_merge_line', fn() => []);

$full_csv_d2 = file_get_contents($src_d2);
assert_eq(
    sortCsvData($full_csv_d2),
    entries_sumup_project($nodes_d2_incr),
    'T-D2: incremental sumup_update == sortCsvData(full history)'
);
@unlink($src_d2);
@unlink($sumup_d2);

// T-D3: delete marker appended in tail → path disappears from projection
$src_d3   = tempnam(sys_get_temp_dir(), 'entries_src_');
$sumup_d3 = tempnam(sys_get_temp_dir(), 'entries_sumup_');
@unlink($sumup_d3);

file_put_contents($src_d3, "Timestamp,entry\n2025-09-07 20:44:54,/b/one | Alive.\n");
sumup_update($sumup_d3, $src_d3, 'entries_sumup_merge_line', fn() => []);

file_put_contents($src_d3, "2025-09-07 20:45:00,/b/one | --\n", FILE_APPEND);
$nodes_d3 = sumup_update($sumup_d3, $src_d3, 'entries_sumup_merge_line', fn() => []);
$proj_d3  = entries_sumup_project($nodes_d3);
assert_eq(false, strpos($proj_d3, '/b/one') !== false, 'T-D3: deleted path absent from projection');
@unlink($src_d3);
@unlink($sumup_d3);

// T-D4: re-creation — delete marker, then a newer normal row → path visible again
$src_d4   = tempnam(sys_get_temp_dir(), 'entries_src_');
$sumup_d4 = tempnam(sys_get_temp_dir(), 'entries_sumup_');
@unlink($sumup_d4);

file_put_contents($src_d4, "Timestamp,entry\n"
    . "2025-09-07 20:44:54,/c/one | Alive.\n"
    . "2025-09-07 20:45:00,/c/one | --\n");
sumup_update($sumup_d4, $src_d4, 'entries_sumup_merge_line', fn() => []);

file_put_contents($src_d4, "2025-09-07 20:46:00,/c/one | Reborn.\n", FILE_APPEND);
$nodes_d4 = sumup_update($sumup_d4, $src_d4, 'entries_sumup_merge_line', fn() => []);
$proj_d4  = entries_sumup_project($nodes_d4);
assert_eq(true, strpos($proj_d4, '/c/one | Reborn.') !== false, 'T-D4: path re-created by newer non-delete row');
@unlink($src_d4);
@unlink($sumup_d4);




