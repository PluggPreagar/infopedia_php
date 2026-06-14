# TASK-P2-5 · BUG-04 — Diagnostic `echo` leaking into response in `parseData()`

**Step:** S3 (RED) + S4 (GREEN)
**Phase:** 2 — bug fixes
**~2 min**

## Bug
`infopedia.php:125` — `echo "topic: " . $topic . " node: " . $node . "…\n";` inside
`parseData()`. This leaks raw text into the HTML response body, corrupting the page output.

## Constitution refs
- `CC1.1–CC1.5` — reproduce, document, prove
- `CC3` — observability via logging, NOT echo: diagnostics use `log_debug()`, never `echo`
- `CC5` — self-documenting code: remove dead debug output

## Step requirements
→ [`S3-failing-test.md`](../../../.ai/requirements/S3-failing-test.md) REQ-S3-1, REQ-S3-2
→ [`S4-implement.md`](../../../.ai/requirements/S4-implement.md) REQ-S4-1, REQ-S4-5

## Files
| Action | File |
|---|---|
| CREATE | `util_infopedia_echo_test.php` (regression test) |
| EDIT | `infopedia.php` — remove echo line 125 |

## S3 — Failing test (RED)

```php
<?php
// util_infopedia_echo_test.php — regression for BUG-04 (CC1.3)
require_once 'util_test.php';

$src = file_get_contents('infopedia.php');
// check no bare diagnostic echo remains in parseData function
// (echo inside strings or HTML template echo are fine — we look for the debug one)
$inParseData = false;
$found       = false;
foreach (explode("\n", $src) as $line) {
    if (preg_match('/function\s+parseData/', $line))  { $inParseData = true; }
    if ($inParseData && preg_match('/^}\s*$/', $line)) { $inParseData = false; }
    if ($inParseData && preg_match('/echo\s+"topic:/', $line)) { $found = true; }
}

assert_equals($found, false, 'infopedia.php parseData: no diagnostic echo present');

print_test_summary();
```

**Run before fix:**
```powershell
D:\_progs\xampp\php\php.exe util_infopedia_echo_test.php
# Expected: FAIL — RED confirmed ✓
```

## S4 — Fix (GREEN)

Remove **only** line 125 in `infopedia.php`:

```php
// DELETE this line from parseData():
echo "topic: " . $topic . " node: " . $node . " myTopic: " . $myTopic . "\n";
```

If the information is genuinely needed during debugging, replace with:
```php
log_debug("parseData: topic=$topic node=$node myTopic=$myTopic"); // CC3: log not echo
```
(But prefer removing it entirely — the data is in the parsed array which is returned.)

**Run after fix:**
```powershell
D:\_progs\xampp\php\php.exe util_infopedia_echo_test.php
# Expected: PASS: 1  FAIL: 0  exit 0  (GREEN ✓)
```

## Commit

```
fix(infopedia): remove diagnostic echo from parseData (CC3 violation)

BUG-04: echo "topic:..." inside parseData() leaked raw text into HTML response,
corrupting the page. Diagnostics belong in log_debug(), not echo (CC3).

Reproduction test: util_infopedia_echo_test.php (RED before, GREEN after).
Refs: CC1.1–CC1.5, CC3, CC5, REQ-S3-2, REQ-S4-5
```
SemVer: PATCH — `CV1`, `CV2`, `CV3`

## Next task
→ [TASK-P2-6](TASK-P2-6-bug01-02-str-leng.md)

