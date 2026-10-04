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
