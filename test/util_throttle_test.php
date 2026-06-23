<?php
require_once __DIR__ . '/util_test.php';
require_once __DIR__ . '/../util_throttle.php';

// ─── checkThrottle ────────────────────────────────────────────────────────────
// Input:  $dir (temp dir), $key (string), $max (int), $window (int), $now (int)
// Output: true = allowed, false = throttled
//
// State file: $dir/throttle_$key.dat  containing  "<window_start>:<count>"
// $now is injectable — no real time() calls in tests

$dir = sys_get_temp_dir() . '/infopedia_throttle_test_' . getmypid();
mkdir($dir);

function ct(string $dir, string $key, int $max, int $window, int $now, bool $expect, string $msg): void {
    assert_eq($expect, checkThrottle($dir, $key, $max, $window, $now), "checkThrottle: $msg");
}

// disabled (max=0) → always allow
ct($dir, 'sid_a', 0, 60, 1000, true, 'max=0 always allows');

// first request in empty state → allowed
ct($dir, 'sid_b', 3, 60, 1000, true, 'first request allowed');

// second request within window → allowed
ct($dir, 'sid_b', 3, 60, 1001, true, 'second request allowed');

// third request within window → allowed (at limit)
ct($dir, 'sid_b', 3, 60, 1002, true, 'third request allowed (at limit)');

// fourth request within window → throttled (over limit)
ct($dir, 'sid_b', 3, 60, 1003, false, 'fourth request throttled');

// still throttled on same second
ct($dir, 'sid_b', 3, 60, 1003, false, 'still throttled same second');

// window expired → resets, first request allowed again
ct($dir, 'sid_b', 3, 60, 1065, true, 'allowed after window expires');

// different keys are independent
ct($dir, 'sid_c', 3, 60, 1000, true,  'different key first request');
ct($dir, 'sid_c', 3, 60, 1001, true,  'different key second request');
ct($dir, 'sid_c', 3, 60, 1002, true,  'different key third request');
ct($dir, 'sid_c', 3, 60, 1003, false, 'different key throttled');
ct($dir, 'sid_b', 3, 60, 1066, true,  'sid_b unaffected by sid_c');

// max=1 → allows first, blocks second
ct($dir, 'sid_d', 1, 60, 2000, true,  'max=1 first allowed');
ct($dir, 'sid_d', 1, 60, 2001, false, 'max=1 second blocked');

// ─── throttleRetryAfter ───────────────────────────────────────────────────────
// Input:  $dir, $key, $window, $now
// Output: seconds remaining in current throttle window (>= 0)

function tra(string $dir, string $key, int $window, int $now, int $expect, string $msg): void {
    assert_eq($expect, throttleRetryAfter($dir, $key, $window, $now), "throttleRetryAfter: $msg");
}

// sid_d throttled at t=2000 with window=60 → at t=2010 retry after 50s
tra($dir, 'sid_d', 60, 2010, 50, 'retry-after mid-window');

// at t=2059 → 1s remaining
tra($dir, 'sid_d', 60, 2059, 1, 'retry-after near end of window');

// at t=2060 → window expired → 0
tra($dir, 'sid_d', 60, 2060, 0, 'retry-after at window boundary');

// cleanup
array_map('unlink', glob("$dir/throttle_*.dat"));
rmdir($dir);
