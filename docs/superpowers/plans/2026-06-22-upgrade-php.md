# upgrade.php Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A single PHP endpoint that downloads the latest branch ZIP from GitHub and overwrites matching files in place on a Strato shared host.

**Architecture:** Self-contained `upgrade.php` — no requires, no dependencies. Token check → read `upgradeFiles` patterns from `infopedia.cfg` → `file_get_contents` ZIP → `ZipArchive` extract → `fnmatch` filter → write to `__DIR__` → HTML summary.

**Tech Stack:** PHP 8+, `ZipArchive` extension, `file_get_contents` with `allow_url_fopen = On`

## Global Constraints

- No `curl`, `exec`, or `shell_exec` — `file_get_contents` + `ZipArchive` only
- Self-contained: zero `require`/`include` — config parsed inline
- Writes to `__DIR__` (directory containing `upgrade.php`)
- Plain procedural PHP, no classes (CP1)
- `UPGRADE_TOKEN` and `UPGRADE_BRANCH` are hardcoded constants at the top of the file

---

### Task 1: Add `upgradeFiles` to `infopedia.cfg`

**Files:**
- Modify: `infopedia.cfg`

- [ ] **Step 1: Add `upgradeFiles` key under `[general]`**

Open `infopedia.cfg` and add after the existing `[general]` entries:

```ini
upgradeFiles = *.php *.html *.js *.md justfile
```

- [ ] **Step 2: Verify parse**

```bash
php -r "var_dump(parse_ini_file('infopedia.cfg', true)['general']['upgradeFiles']);"
```

Expected output:
```
string(36) "*.php *.html *.js *.md justfile"
```

- [ ] **Step 3: Commit**

```bash
git add infopedia.cfg
git commit -m "chore(cfg): add upgradeFiles pattern list for upgrade.php"
```

---

### Task 2: Write `upgrade.php`

**Files:**
- Create: `upgrade.php`

**Interfaces:**
- Consumes: `infopedia.cfg` → `upgradeFiles` (space-separated fnmatch patterns)
- Produces: HTTP response — HTML summary page or 403

- [ ] **Step 1: Create `upgrade.php` with the full implementation**

```php
<?php
/**
 * upgrade.php — pull latest from GitHub, overwrite matching files.
 * Usage: ?token=<UPGRADE_TOKEN>
 */

define('UPGRADE_TOKEN',  'change-me');   // <-- set your secret here
define('UPGRADE_BRANCH', 'refactor/202606');
define('REPO',           'PluggPreagar/infopedia_php');

// ── Auth ──────────────────────────────────────────────────────────────────────
if (($_GET['token'] ?? '') !== UPGRADE_TOKEN) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('Forbidden');
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function upgrade_fail(string $msg): never {
    echo '<body style="font-family:monospace;padding:1rem"><p style="color:red">Error: '
        . htmlspecialchars($msg) . '</p></body>';
    exit;
}

// ── Config ────────────────────────────────────────────────────────────────────
$patterns = ['*.php', '*.html', '*.js', '*.md', 'justfile'];
$cfgFile  = __DIR__ . '/infopedia.cfg';
if (file_exists($cfgFile)) {
    $ini = parse_ini_file($cfgFile, true);
    if (!empty($ini['general']['upgradeFiles'])) {
        $patterns = preg_split('/\s+/', trim($ini['general']['upgradeFiles']), -1, PREG_SPLIT_NO_EMPTY);
    }
}

// ── Download ──────────────────────────────────────────────────────────────────
$url   = 'https://codeload.github.com/' . REPO . '/zip/refs/heads/' . UPGRADE_BRANCH;
$bytes = file_get_contents($url);
if (!$bytes) upgrade_fail('Download failed: ' . $url);

$tmp = sys_get_temp_dir() . '/' . uniqid('upgrade_') . '.zip';
if (file_put_contents($tmp, $bytes) === false) upgrade_fail('Could not write temp file');

// ── Extract ───────────────────────────────────────────────────────────────────
$zip = new ZipArchive();
if ($zip->open($tmp) !== true) {
    unlink($tmp);
    upgrade_fail('ZipArchive::open failed');
}

// Detect top-level prefix, e.g. "infopedia_php-refactor-202606/"
$prefix = '';
if ($zip->count() > 0) {
    $first = $zip->getNameIndex(0);
    if (($pos = strpos($first, '/')) !== false) {
        $prefix = substr($first, 0, $pos + 1);
    }
}

$log = []; $written = 0; $skipped = 0; $errors = 0;

for ($i = 0; $i < $zip->count(); $i++) {
    $name = $zip->getNameIndex($i);
    $rel  = str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;

    // Skip directory entries
    if ($rel === '' || str_ends_with($rel, '/')) continue;

    // Positive pattern filter — check full relative path and basename
    $allow = false;
    foreach ($patterns as $p) {
        if (fnmatch($p, $rel) || fnmatch($p, basename($rel))) { $allow = true; break; }
    }
    if (!$allow) {
        $skipped++;
        $log[] = ['path' => $rel, 'st' => 'skipped'];
        continue;
    }

    // Write to __DIR__
    $dest = __DIR__ . '/' . $rel;
    $dir  = dirname($dest);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    if (file_put_contents($dest, $zip->getFromIndex($i)) === false) {
        $errors++;
        $log[] = ['path' => $rel, 'st' => 'error'];
    } else {
        $written++;
        $log[] = ['path' => $rel, 'st' => 'written'];
    }
}

$zip->close();
unlink($tmp);

// ── HTML output ───────────────────────────────────────────────────────────────
$zipUrl = 'https://github.com/' . REPO . '/archive/refs/heads/' . UPGRADE_BRANCH . '.zip';
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html><head><title>upgrade</title></head>
<body style="font-family:monospace;padding:1rem;max-width:900px">
<h2>upgrade — <?= htmlspecialchars(UPGRADE_BRANCH) ?></h2>
<p><a href="<?= htmlspecialchars($zipUrl) ?>">GitHub ZIP</a></p>
<table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse;width:100%">
<tr style="background:#eee"><th align="left">file</th><th>status</th></tr>
<?php foreach ($log as $row):
    $color = match($row['st']) { 'written' => '#c8e6c9', 'error' => '#ffcdd2', default => '' };
?>
<tr style="background:<?= $color ?>">
    <td><?= htmlspecialchars($row['path']) ?></td>
    <td align="center"><?= $row['st'] ?></td>
</tr>
<?php endforeach ?>
</table>
<p><strong><?= $written ?> written, <?= $skipped ?> skipped, <?= $errors ?> errors</strong></p>
</body></html>
```

- [ ] **Step 2: Set your token**

Edit `upgrade.php` line 8 — replace `'change-me'` with a real secret (16+ random chars).
Do **not** commit the real token; keep `'change-me'` as the placeholder in git.

- [ ] **Step 3: Smoke test — verify 403 without token**

```bash
php -S localhost:9999 -t . &
curl -s -o /dev/null -w "%{http_code}" http://localhost:9999/upgrade.php
# Expected: 403
curl -s -o /dev/null -w "%{http_code}" "http://localhost:9999/upgrade.php?token=wrong"
# Expected: 403
kill %1
```

- [ ] **Step 4: Smoke test — verify download + file list**

With a valid token, open in browser or curl:

```bash
php -S localhost:9999 -t . &
curl -s "http://localhost:9999/upgrade.php?token=change-me" | grep -E "written|skipped|errors"
# Expected: line like "12 written, 47 skipped, 0 errors"
kill %1
```

Verify:
- No `data/` files appear in the written list
- `.htaccess` and `infopedia.cfg` do not appear as `written`
- PHP, HTML, JS, MD files appear as `written`

- [ ] **Step 5: Commit**

```bash
git add upgrade.php
git commit -m "feat(upgrade): self-update endpoint — ZIP download + fnmatch filter"
```
