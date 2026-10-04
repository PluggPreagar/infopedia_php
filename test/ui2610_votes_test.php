<?php
// UI2610: set-kinds on the votes SumUp. See .ai/adr/ui2610-adr-2-set-kinds.md, .ai/ui2610/req.md
require_once __DIR__ . '/util_test.php';
require_once __DIR__ . '/../util_entry.php';
require_once __DIR__ . '/../util_sumup.php';

// ─── parseSetKinds (REQ-UI2610-10) ───────────────────────────────────────────

function _psk(string $entry): array {
    return parseSetKinds(parseEntry($entry));
}

assert_eq([], _psk('/t/a | Item.'), 'UI2610 psk: no kinds → empty');
assert_eq(['rate' => ['s42' => '5']], _psk('/t/a | rate:s42:5 | Item.'), 'UI2610 psk: rate');
assert_eq(['tri' => ['s42' => 'open']], _psk('/t/a | tri:s42:open | Item.'), 'UI2610 psk: tri');
assert_eq(['ind' => ['s42' => '2,3,1,1,0,2']], _psk('/t/a | ind:s42:2,3,1,1,0,2 | Item.'), 'UI2610 psk: ind');
assert_eq(['cmp' => ['s42' => '-1']], _psk('/t/~cmp/a~b | cmp:s42:-1 | Pair.'), 'UI2610 psk: cmp');
assert_eq(['trust' => ['s42' => '1']], _psk('/t/~trust/s17 | trust:s42:1 | Trust.'), 'UI2610 psk: trust');
assert_eq(['rate' => ['s42' => '0']], _psk('/t/a | rate:s42:0 | Item.'), 'UI2610 psk: rate 0 = clear');
assert_eq(
    ['tri' => ['s42' => 'in'], 'rate' => ['s42' => '4']],
    _psk('/t/a | tri:s42:in | rate:s42:4 | Item.'),
    'UI2610 psk: several kinds in one row'
);
// invalid values ignored (CA4)
assert_eq([], _psk('/t/a | rate:s42:6 | Item.'), 'UI2610 psk: rate 6 invalid');
assert_eq([], _psk('/t/a | tri:s42:maybe | Item.'), 'UI2610 psk: tri unknown invalid');
assert_eq([], _psk('/t/a | ind:s42:5,0,0,0,0,0 | Item.'), 'UI2610 psk: ind kP 5 invalid');
assert_eq([], _psk('/t/a | ind:s42:0,0,4,0,0,0 | Item.'), 'UI2610 psk: ind σ 4 invalid');
assert_eq([], _psk('/t/a | ind:s42:1,2,3 | Item.'), 'UI2610 psk: ind too short');
assert_eq([], _psk('/t/a | cmp:s42:2 | Pair.'), 'UI2610 psk: cmp 2 invalid');
assert_eq([], _psk('/t/a | rate:others:3 | Item.'), 'UI2610 psk: sid "others" reserved');
assert_eq([], _psk('/t/a | rate:s 42:3 | Item.'), 'UI2610 psk: bad sid');
assert_eq([], _psk('/t/a | author:martin | Item.'), 'UI2610 psk: plain attr is not a kind');

// ─── merge + projection ──────────────────────────────────────────────────────

function _ui_nodes(array $rows): array {
    $nodes = [];
    foreach ($rows as $r) {
        $nodes = votes_sumup_merge_line($nodes, $r);
    }
    return $nodes;
}
function _ui_row(string $proj, string $path): string {
    foreach (explode("\n", $proj) as $line) {
        if (strpos($line, ',' . $path . ' |') !== false || strpos($line, ',"' . $path . ' |') !== false) return $line;
    }
    return '';
}
function _has(string $hay, string $needle): bool {
    return strpos($hay, $needle) !== false;
}

// REQ-UI2610-11: set = latest value per sid
$n = _ui_nodes([
    '2026-10-04 10:00:00,/t/a | rate:s1:2 | Item.',
    '2026-10-04 10:01:00,/t/a | rate:s1:5 | Item.',
    '2026-10-04 10:02:00,/t/a | rate:s2:3 | Item.',
    '2026-10-04 10:03:00,/t/a | rate:s3:3 | Item.',
]);
$row = _ui_row(votes_sumup_project($n, 's1'), '/t/a');
assert_eq(true, _has($row, ' | rate:s1:5 | '), 'UI2610 merge: own latest value (not sum)');
assert_eq(true, _has($row, ' | rate:others:3=2 | '), 'UI2610 proj: histogram of others');
assert_eq(false, _has($row, 'rate:s2:'), 'UI2610 proj: other sids hidden');

// out-of-order rows: latest timestamp wins
$n = _ui_nodes([
    '2026-10-04 10:05:00,/t/a | rate:s1:4 | Item.',
    '2026-10-04 10:01:00,/t/a | rate:s1:1 | Item.',
]);
assert_eq(true, _has(votes_sumup_project($n, 's1'), 'rate:s1:4'), 'UI2610 merge: older row does not overwrite newer');

// histogram excludes viewer, sorted, several bins
$n = _ui_nodes([
    '2026-10-04 10:00:00,/t/a | rate:s1:5 | Item.',
    '2026-10-04 10:00:01,/t/a | rate:s2:1 | Item.',
    '2026-10-04 10:00:02,/t/a | rate:s3:5 | Item.',
    '2026-10-04 10:00:03,/t/a | rate:s4:3 | Item.',
]);
assert_eq(true, _has(votes_sumup_project($n, 's1'), ' | rate:others:1=1;3=1;5=1 | '), 'UI2610 proj: sorted bins');
assert_eq(true, _has(votes_sumup_project($n, 'viewer'), ' | rate:others:1=1;3=1;5=2 | '), 'UI2610 proj: non-voter sees all');

// rate 0 = cleared: no own value, not in histogram
$n = _ui_nodes([
    '2026-10-04 10:00:00,/t/a | rate:s1:4 | Item.',
    '2026-10-04 10:01:00,/t/a | rate:s1:0 | Item.',
    '2026-10-04 10:02:00,/t/a | rate:s2:2 | Item.',
]);
$row = _ui_row(votes_sumup_project($n, 's1'), '/t/a');
assert_eq(false, _has($row, 'rate:s1:'), 'UI2610 proj: cleared rate has no own value');
assert_eq(true, _has(votes_sumup_project($n, 's2'), 'rate:s2:2 | '), 'UI2610 proj: cleared rate (s1) missing from others');
assert_eq(false, _has(votes_sumup_project($n, 's2'), 'rate:others'), 'UI2610 proj: no others → no histogram');

// tri + cmp + trust bins
$n = _ui_nodes([
    '2026-10-04 10:00:00,/t/a | tri:s1:in | Item.',
    '2026-10-04 10:00:01,/t/a | tri:s2:out | Item.',
    '2026-10-04 10:00:02,/t/a | tri:s3:in | Item.',
    '2026-10-04 10:00:03,/t/~cmp/a~b | cmp:s2:1 | Pair.',
    '2026-10-04 10:00:04,/t/~cmp/a~b | cmp:s3:-1 | Pair.',
    '2026-10-04 10:00:05,/t/~cmp/a~b | cmp:s4:1 | Pair.',
    '2026-10-04 10:00:06,/t/~trust/s9 | trust:s2:1 | Trust.',
    '2026-10-04 10:00:07,/t/~trust/s9 | trust:s3:1 | Trust.',
]);
$p = votes_sumup_project($n, 's1');
assert_eq(true, _has($p, '/t/a | tri:s1:in | tri:others:in=1;out=1 | '), 'UI2610 proj: tri');
assert_eq(true, _has($p, '/t/~cmp/a~b | cmp:others:-1=1;1=2 | '), 'UI2610 proj: cmp');
assert_eq(true, _has($p, '/t/~trust/s9 | trust:others:1=2 | '), 'UI2610 proj: trust = count only');

// ind: histogram per component (kP,kI: 5 bins · σ: 4 bins)
$n = _ui_nodes([
    '2026-10-04 10:00:00,"/t/a | ind:s1:2,3,1,1,0,2 | Item."',
    '2026-10-04 10:00:01,"/t/a | ind:s2:2,3,1,1,0,0 | Item."',
    '2026-10-04 10:00:02,"/t/a | ind:s3:4,0,3,0,2,2 | Item."',
]);
$row = _ui_row(votes_sumup_project($n, 's1'), '/t/a');
assert_eq(true, _has($row, ' | ind:s1:2,3,1,1,0,2 | '), 'UI2610 proj: own ind');
assert_eq(
    true,
    _has($row, ' | ind:others:kP=0,0,1,0,1;kI=1,0,0,1,0;sP-=0,1,0,1;sP+=1,1,0,0;sI-=1,0,1,0;sI+=1,0,1,0 | '),
    'UI2610 proj: ind histogram per component'
);

// REQ-UI2610-13 + REQ-4: signers listed on set rows; withdraw = signed:0
$n = _ui_nodes([
    '2026-10-04 10:00:00,/t/a | rate:s1:5 | signed:s1:1 | Item.',
    '2026-10-04 10:00:01,/t/a | rate:s2:1 | signed:s2:1 | Item.',
    '2026-10-04 10:00:02,/t/a | rate:s3:1 | signed:s3:1 | Item.',
    '2026-10-04 10:00:03,/t/a | signed:s3:0 | Item.',
]);
$row = _ui_row(votes_sumup_project($n, 'viewer'), '/t/a');
assert_eq(true, _has($row, ' | signers:s1,s2 | '), 'UI2610 proj: signers listed, withdrawn removed');
assert_eq(true, _has($row, ' | signed_count:2 | '), 'UI2610 proj: signed_count follows withdraw');

// REQ-UI2610-15: legacy rows unchanged (no signers: on plain votes/signed rows)
$n = _ui_nodes([
    '2025-09-07 20:44:54,/poll/q1 | votes:sid_a:1 | Fair question?',
    '2025-09-07 20:45:10,/poll/q2 | signed:sid_a:1 | Petition.',
]);
$p = votes_sumup_project($n, 'sid_b');
assert_eq(false, _has($p, 'signers:'), 'UI2610 compat: no signers: on legacy rows');
assert_eq(true, _has($p, '/poll/q1 | votes:others:1 | Fair question?'), 'UI2610 compat: legacy vote row unchanged');
assert_eq(true, _has($p, '/poll/q2 | signed_count:1 | Petition.'), 'UI2610 compat: legacy signed row unchanged');

// mixed: votes + set kinds on one path keep both
$n = _ui_nodes([
    '2026-10-04 10:00:00,/t/a | votes:s1:1 | rate:s1:4 | Item.',
    '2026-10-04 10:00:01,/t/a | votes:s2:1 | rate:s2:2 | Item.',
]);
$row = _ui_row(votes_sumup_project($n, 's1'), '/t/a');
assert_eq(true, _has($row, '/t/a | votes:s1:1 | votes:others:1 | rate:s1:4 | rate:others:2=1 | Item.'), 'UI2610 proj: column order');

// old SumUp nodes on disk (no 'sets' key) still project
$legacy_node = ['/poll/q1' => ['by_sid' => ['a' => 1], 'signers' => [], 'ts' => '2025-09-07 20:44:54', 'content' => 'Q?']];
assert_eq(true, _has(votes_sumup_project($legacy_node, 'b'), '/poll/q1 | votes:others:1 | Q?'), 'UI2610 compat: node without sets key');

// ─── JSON format: sets field (attrs keyed by name would collapse own + others) ──
require_once __DIR__ . '/../util_format.php';

assert_eq(
    ['rate' => ['s1' => '4', 'others' => '3=2;5=1'], 'ind' => ['others' => 'kP=0,1,0,0,0;kI=1,0,0,0,0;sP-=1,0,0,0;sP+=1,0,0,0;sI-=1,0,0,0;sI+=1,0,0,0']],
    projectedSetKinds('/t/a | rate:s1:4 | rate:others:3=2;5=1 | ind:others:kP=0,1,0,0,0;kI=1,0,0,0,0;sP-=1,0,0,0;sP+=1,0,0,0;sI-=1,0,0,0;sI+=1,0,0,0 | Item.'),
    'UI2610 json: projectedSetKinds own + others'
);
assert_eq([], projectedSetKinds('/poll/q1 | votes:others:1 | author:x | Q?'), 'UI2610 json: no set kinds');

$j = csv_to_json("Timestamp,entry\n2026-10-04 10:00:00,/t/a | rate:s1:4 | rate:others:3=2 | signers:s1 | Item.");
assert_eq(['rate' => ['s1' => '4', 'others' => '3=2']], $j['/t/a']['sets'] ?? null, 'UI2610 json: sets field');
assert_eq(['signers' => 's1'], $j['/t/a']['attrs'], 'UI2610 json: set kinds removed from attrs, signers kept');
$j = csv_to_json("Timestamp,entry\n2026-10-04 10:00:00,/poll/q1 | votes:others:1 | Q?");
assert_eq(false, isset($j['/poll/q1']['sets']), 'UI2610 json: legacy row has no sets field');

// ─── by: Signer name (REQ-UI2610-18) ─────────────────────────────────────────
$n = _ui_nodes([
    '2026-10-04 10:00:00,/t/a | rate:s1:5 | signed:s1:1 | by:Mart | Item.',
    '2026-10-04 10:00:01,/t/a | rate:s2:1 | signed:s2:1 | Item.',
    '2026-10-04 10:00:02,/t/a | rate:s3:4 | by:Anna | Item.',
    '2026-10-04 10:00:03,/t/a | rate:s4:1 | signed:s4:1 | by:Bob | Item.',
    '2026-10-04 10:00:04,/t/a | signed:s4:0 | Item.',
]);
$row = _ui_row(votes_sumup_project($n, 'viewer'), '/t/a');
assert_eq(true, _has($row, ' | signers:s1,s2 | names:s1=Mart | '), 'UI2610 by: names only for Signers with a name');
assert_eq(false, _has($row, 'Anna'), 'UI2610 by: name without Sign stays hidden');
assert_eq(false, _has($row, 'Bob'), 'UI2610 by: withdrawn Sign drops the name');

// latest name wins
$n = _ui_nodes([
    '2026-10-04 10:00:00,/t/a | rate:s1:5 | signed:s1:1 | by:Mart | Item.',
    '2026-10-04 10:00:01,/t/a | signed:s1:1 | by:Martin H | Item.',
]);
assert_eq(true, _has(votes_sumup_project($n, 'x'), ' | names:s1=Martin H | '), 'UI2610 by: latest name wins');

// invalid names ignored (CA4): too long, reserved chars
$n = _ui_nodes([
    '2026-10-04 10:00:00,/t/a | rate:s1:5 | signed:s1:1 | by:' . str_repeat('x', 41) . ' | Item.',
    '2026-10-04 10:00:01,/t/a | rate:s2:5 | signed:s2:1 | by:a=b | Item.',
    '2026-10-04 10:00:02,/t/a | rate:s3:5 | signed:s3:1 | by:a;b | Item.',
]);
$row = _ui_row(votes_sumup_project($n, 'x'), '/t/a');
assert_eq(false, _has($row, 'names:'), 'UI2610 by: invalid names dropped');
assert_eq(true, _has($row, 'signers:s1,s2,s3'), 'UI2610 by: Sign still counts without valid name');

// legacy rows: no names
$n = _ui_nodes(['2025-09-07 20:45:10,/poll/q2 | signed:sid_a:1 | by:Mart | Petition.']);
assert_eq(false, _has(votes_sumup_project($n, 'x'), 'names:'), 'UI2610 by: no names on legacy rows');
