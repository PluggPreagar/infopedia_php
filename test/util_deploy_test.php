<?php
require_once __DIR__ . '/util_test.php';
require_once __DIR__ . '/../util_deploy.php';

// ─── deploy_commit_time: GitHub commit JSON → UTC timestamp (zip mtime has no timezone) ──
$json = '{"sha":"e98764d","commit":{"committer":{"name":"x","date":"2026-10-04T18:18:59Z"}}}';
assert_eq(gmmktime(18, 18, 59, 10, 4, 2026), deploy_commit_time($json), 'deploy_commit_time: committer date Z');
assert_eq(gmmktime(16, 18, 59, 10, 4, 2026), deploy_commit_time('{"commit":{"committer":{"date":"2026-10-04T18:18:59+02:00"}}}'), 'deploy_commit_time: offset honoured');
assert_eq(0, deploy_commit_time(''), 'deploy_commit_time: empty → 0');
assert_eq(0, deploy_commit_time('{"message":"API rate limit exceeded"}'), 'deploy_commit_time: API error → 0');
assert_eq(0, deploy_commit_time('not json'), 'deploy_commit_time: garbage → 0');

// ─── deploy_zip_ut: UT extra field (0x5455) of the first local header → UTC ──
// Real GitHub zip of 8878aea (commit 2026-10-07T05:56:06Z): DOS fields say 2026-10-06 22:56:06 (Pacific), UT = exact UTC
$lh = fn(string $name, string $extra) => "PK\x03\x04" . str_repeat("\0", 22) . pack('vv', strlen($name), strlen($extra)) . $name . $extra;
$ut = pack('vv', 0x5455, 5) . "\x01" . pack('V', gmmktime(5, 56, 6, 10, 7, 2026));
assert_eq(gmmktime(5, 56, 6, 10, 7, 2026), deploy_zip_ut($lh('infopedia_php-dev/', $ut)), 'deploy_zip_ut: GitHub UT field');
assert_eq(gmmktime(5, 56, 6, 10, 7, 2026), deploy_zip_ut($lh('x/', pack('vv', 0x7875, 3) . "abc" . $ut)), 'deploy_zip_ut: UT after another field');
assert_eq(0, deploy_zip_ut($lh('x/', '')), 'deploy_zip_ut: no extra → 0');
assert_eq(0, deploy_zip_ut($lh('x/', pack('vv', 0x5455, 5) . "\x00abcd")), 'deploy_zip_ut: mtime flag not set → 0');
assert_eq(0, deploy_zip_ut('not a zip'), 'deploy_zip_ut: garbage → 0');
$real = @file_get_contents('/tmp/claude-0/t.zip');   // optional: local git archive
if ($real) assert_eq(true, deploy_zip_ut($real) > gmmktime(0, 0, 0, 1, 1, 2026), 'deploy_zip_ut: real git-archive zip');
