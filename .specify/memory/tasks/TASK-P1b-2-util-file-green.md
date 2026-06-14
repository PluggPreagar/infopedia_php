# TASK-P1b-2 · Create `util_file.php` (GREEN)

**Step:** S4 — Implement
**Phase:** 1b — extract file/cache helper
**~3 min** | Depends on: [TASK-P1b-1](TASK-P1b-1-util-file-red.md) must be RED first

## Constitution refs
- `CP1` — procedural PHP, no classes
- `CA9` — prepare for caching: seam with stable keys + deterministic outputs; no premature caching
- `CA14` — raw-first ingestion: `appendRaw` is append-only, never overwrites
- `CA7` — extracted from `read.php` + `infopedia.php` (eliminates duplication)
- `CC5` — self-documenting, short `// why` comments

## Step requirements
→ [`S4-implement.md`](../../../.ai/requirements/S4-implement.md)
- REQ-S4-1: minimal code to pass GREEN
- REQ-S4-2: plain procedural PHP
- REQ-S4-8: side-effect-free where possible

## Files
| Action | File | Source |
|---|---|---|
| CREATE | `util_file.php` | extracted from `read.php:130-146`, `infopedia.php:29-38` |

## Code — `util_file.php`

```php
<?php
// Cache and raw-file I/O helpers (CA9, CA14, CP1)

function isCacheValid(string $file, int $maxAge): bool {
    // maxAge=0 is the force_update path — always miss
    return $maxAge > 0
        && file_exists($file)
        && (time() - filemtime($file)) < $maxAge;
}

function readCache(string $file): string {
    return file_exists($file) ? file_get_contents($file) : '';
}

function writeCache(string $file, string $data): void {
    file_put_contents($file, $data);
}

/** Touch the outdated-signal file so read.php knows to refresh. */
function markCacheOutdated(string $file): void {
    if (file_exists($file)) {
        touch($file);
    }
}

/**
 * Append one raw line — raw-first, append-only (CA14).
 * Call this BEFORE any processing so replay is possible after failure.
 * Creates the directory if needed; uses LOCK_EX for concurrent safety.
 */
function appendRaw(string $file, string $line): void {
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($file, rtrim($line) . "\n", FILE_APPEND | LOCK_EX);
}
```

## Verification

```powershell
D:\_progs\xampp\php\php.exe util_file_test.php
# Expected: PASS: 6  FAIL: 0  exit 0  (GREEN ✓)
```

## Commit

```
feat(file): add util_file.php — cache I/O + raw-first appendRaw
Seam for future caching (CA9); appendRaw enables replay after failure (CA14).
Refs: CP1, CA9, CA14, CA7, CC5, REQ-S4-1, REQ-S4-2, REQ-S4-8
```
SemVer: MINOR (new helper) — `CV1`, `CV2`, `CV3`

## Next task
→ [TASK-P2-1](TASK-P2-1-bug05-log-warn.md)

