# TASK-P2-3 · BUG-07 — `infopedia.php` double config load + missing `$type`

**Step:** S3 (RED) + S4 (GREEN)
**Phase:** 2 — bug fixes
**~3 min**

## Bug
`infopedia.php` calls `require 'util.php'` at line 3 **without** `$type` set → bootstrap
runs with type `"none"`. Then at line 231 it re-parses `infopedia.cfg` manually with
`$type = "web"` — duplicating and partially overriding what `util.php` already did.
Result: the `[web]` config section is not available to functions defined earlier in the file.

## Constitution refs
- `CC1.1–CC1.5` — reproduce, document, prove
- `CC2` — config-driven: `$type` selects config section before bootstrap
- `CP2` — one file = one route: `$type` MUST be set before `require 'util.php'`
- `CA7` — re-use over reinvent: config parsing belongs entirely in `util.php`

## Step requirements
→ [`S3-failing-test.md`](../../../.ai/requirements/S3-failing-test.md) REQ-S3-1, REQ-S3-2
→ [`S4-implement.md`](../../../.ai/requirements/S4-implement.md) REQ-S4-1, REQ-S4-4

## Files
| Action | File |
|---|---|
| EDIT | `infopedia.php` — add `$type = "web"` before `require`, remove duplicate config block (lines 231–249) |

## S3 — Failing test (RED)

Source-level proof that `$type` precedes `require` and no duplicate config parse exists:

```php
<?php
// util_infopedia_config_test.php — regression for BUG-07 (CC1.3)
require_once 'util_test.php';

$src   = file_get_contents('infopedia.php');
$lines = explode("\n", $src);

$typeSetLine    = null;
$requireLine    = null;
$duplicateCount = 0;

foreach ($lines as $i => $line) {
    if ($typeSetLine === null && preg_match('/\$type\s*=\s*["\']web["\']/', $line)) {
        $typeSetLine = $i;
    }
    if ($requireLine === null && preg_match("/require.*'util\.php'/", $line)) {
        $requireLine = $i;
    }
    if (preg_match('/parse_ini_file/', $line)) {
        $duplicateCount++;
    }
}

assert_equals($typeSetLine !== null, true,  'infopedia.php: $type="web" exists');
assert_equals($requireLine !== null, true,  'infopedia.php: require util.php exists');
assert_equals($typeSetLine < $requireLine, true, 'infopedia.php: $type before require');
assert_equals($duplicateCount, 0, 'infopedia.php: no duplicate parse_ini_file call');

print_test_summary();
```

**Run before fix:**
```powershell
D:\_progs\xampp\php\php.exe util_infopedia_config_test.php
# Expected: FAIL — RED confirmed ✓
```

## S4 — Fix (GREEN)

**`infopedia.php` — top of file:**

```php
<?php
$type = "web"; // CP2: set before util.php so [web] config section is merged correctly
require 'util.php';
// ...rest of file...
```

**Remove duplicate config block** (lines ~231–249, the `if (file_exists($configFile))` block
that re-calls `parse_ini_file`). The variables it set (`$cacheTime`, `$googleSheetUrl`,
`$cacheFile`, `$useReadPhp`) are now read from `$config` directly:

```php
// REPLACE the duplicate block with:
$useReadPhp     = $config['useReadPhp']   ?? false;
$cacheTime      = isset($_GET['force_update']) ? 0 : ($config['cache_time'] ?? 3600);
$googleSheetUrl = $config['googleSheetUrl']   ?? '';
$cacheFile      = $config['cacheFile']        ?? 'data/entries.cache';
```

**Run after fix:**
```powershell
D:\_progs\xampp\php\php.exe util_infopedia_config_test.php
# Expected: PASS: 4  FAIL: 0  exit 0  (GREEN ✓)
```

## Commit

```
fix(infopedia): set $type="web" before require, remove duplicate config load

BUG-07: require 'util.php' ran without $type → [web] section never merged.
Subsequent manual parse_ini_file at line 231 was a workaround that caused
inconsistent state for functions bootstrapped earlier. Config belongs in util.php.

Reproduction test: util_infopedia_config_test.php (RED before, GREEN after).
Refs: CC1.1–CC1.5, CC2, CP2, CA7, REQ-S3-2, REQ-S4-4
```
SemVer: PATCH — `CV1`, `CV2`, `CV3`

## Next task
→ [TASK-P2-4](TASK-P2-4-bug03-header-undef.md)

