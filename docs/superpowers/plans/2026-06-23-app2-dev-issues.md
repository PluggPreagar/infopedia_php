# App2 Dev Issue Tracker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a standalone PHP issue management page (`issues.php`) with folder-based state storage plus a Titel field in app2's Bug Melden dialog.

**Architecture:** Single `issues.php` dispatches to one of three views (overview, detail, transpose) based on `$_GET` and request method. Issues are `.txt` files stored in `data/issues/<state>/`; state transitions move the file between folders and append a log line.

**Tech Stack:** Plain procedural PHP 8.3, no framework, no Composer. HTML output with inline CSS. `just serve` for local testing.

## Global Constraints

- CP1: No classes, no framework, no Composer — plain procedural PHP only.
- CA1: Simple first — readable over clever.
- CA7: Reuse patterns from existing PHP files where applicable.
- PHP 8.3 features (e.g. `str_contains`) are available.
- `data/issues/` already exists and is empty.
- `issue.php` (the POST receiver) is NOT modified.

---

### Task 1: Create state folders + issues.php routing scaffold

**Files:**
- Create: `data/issues/new/.gitkeep`, `data/issues/ready/.gitkeep`, `data/issues/blocked/.gitkeep`, `data/issues/inProgress/.gitkeep`, `data/issues/inReview/.gitkeep`, `data/issues/canceled/.gitkeep`, `data/issues/closed/.gitkeep`
- Create: `issues.php`

**Interfaces:**
- Produces: `find_issue(string $base, array $states, string $id): ?array` — returns `['state'=>string, 'path'=>string]` or `null`
- Produces: `parse_titel(string $path): string` — reads line 1, returns title text
- Produces: `filename_to_display(string $id): string` — `2026-06-23_14-30-12_abc.txt` → `"2026-06-23 14:30"`
- Produces: `append_verlauf(string $path, string $state): void` — appends transition log line to file
- Produces: `html_head(string $title): void` / `html_foot(): void` — shared page wrapper

- [ ] **Step 1: Create state subdirectories**

```bash
mkdir -p data/issues/new data/issues/ready data/issues/blocked \
         data/issues/inProgress data/issues/inReview \
         data/issues/canceled data/issues/closed
touch data/issues/new/.gitkeep data/issues/ready/.gitkeep \
      data/issues/blocked/.gitkeep data/issues/inProgress/.gitkeep \
      data/issues/inReview/.gitkeep data/issues/canceled/.gitkeep \
      data/issues/closed/.gitkeep
```

- [ ] **Step 2: Create issues.php with routing, helpers, and stub views**

```php
<?php
declare(strict_types=1);

$base   = __DIR__ . '/data/issues';
$states = ['new', 'ready', 'blocked', 'inProgress', 'inReview', 'canceled', 'closed'];

$id  = preg_replace('/[^A-Za-z0-9._-]/', '', $_GET['id'] ?? '');
$set = $_GET['set'] ?? '';
if (!in_array($set, $states, true)) { $set = ''; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id !== '' && $set !== '') {
    handle_transpose($base, $states, $id, $set);
} elseif ($id !== '') {
    render_detail($base, $states, $id);
} else {
    render_overview($base);
}
exit;

// ── Helpers ───────────────────────────────────────────────────────────────────

function find_issue(string $base, array $states, string $id): ?array {
    foreach ($states as $state) {
        $path = "$base/$state/$id";
        if (is_file($path)) {
            return ['state' => $state, 'path' => $path];
        }
    }
    return null;
}

function parse_titel(string $path): string {
    $fh   = fopen($path, 'r');
    $line = fgets($fh);
    fclose($fh);
    if ($line !== false && preg_match('/^Titel:\s*(.+)/u', rtrim($line), $m)) {
        return $m[1];
    }
    return '(kein Titel)';
}

function filename_to_display(string $id): string {
    if (preg_match('/^(\d{4}-\d{2}-\d{2})_(\d{2})-(\d{2})-\d{2}_/', $id, $m)) {
        return $m[1] . ' ' . $m[2] . ':' . $m[3];
    }
    return $id;
}

function append_verlauf(string $path, string $state): void {
    $content = file_get_contents($path);
    $entry   = '[' . date('Y-m-d H:i:s') . '] → ' . $state;
    if (str_contains($content, "\n--- Verlauf ---")) {
        file_put_contents($path, rtrim($content) . "\n" . $entry);
    } else {
        file_put_contents($path, rtrim($content) . "\n\n--- Verlauf ---\n" . $entry);
    }
}

function html_head(string $title): void { ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($title) ?></title>
<style>
body { font-family: system-ui, sans-serif; max-width: 900px; margin: 2rem auto; padding: 0 1rem; color: #111; }
a { color: #0066cc; text-decoration: none; } a:hover { text-decoration: underline; }
h1 { font-size: 1.3rem; margin: 0 0 1.5rem; }
h2 { font-size: 1rem; margin: 1.5rem 0 0.5rem; }
table { width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; }
th, td { padding: 0.4rem 0.6rem; text-align: left; border-bottom: 1px solid #eee; }
th { font-weight: 600; background: #f5f5f5; }
pre { background: #f8f8f8; padding: 1rem; overflow-x: auto; font-size: 0.82rem; white-space: pre-wrap; word-break: break-word; }
.badge { display:inline-block; padding:0.15rem 0.5rem; border-radius:3px; font-size:0.8rem; font-weight:600; }
.badge-new        { background:#dbeafe; color:#1d4ed8; }
.badge-ready      { background:#d1fae5; color:#065f46; }
.badge-blocked    { background:#fee2e2; color:#991b1b; }
.badge-inProgress { background:#fef9c3; color:#854d0e; }
.badge-inReview   { background:#ede9fe; color:#5b21b6; }
.badge-canceled   { background:#f3f4f6; color:#6b7280; }
.badge-closed     { background:#e5e7eb; color:#374151; }
form { display:inline; }
button { cursor:pointer; padding:0.3rem 0.7rem; border:1px solid #ccc; border-radius:4px; background:#fff; font-size:0.85rem; margin:0.2rem 0; }
button:hover { background:#f0f0f0; }
.actions { margin:1rem 0; display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center; }
</style>
</head>
<body>
<?php }

function html_foot(): void { ?>
</body></html>
<?php }

// ── Views (stubs — implemented in subsequent tasks) ───────────────────────────

function render_overview(string $base): void {
    html_head('Issues');
    echo '<h1>Issues</h1><p><em>TODO: overview</em></p>';
    html_foot();
}

function render_detail(string $base, array $states, string $id): void {
    html_head('Issue');
    echo '<h1>Issue Detail</h1><p><em>TODO: detail</em></p>';
    html_foot();
}

function handle_transpose(string $base, array $states, string $id, string $set): void {
    echo 'TODO: transpose';
}
```

- [ ] **Step 3: Verify syntax**

```bash
php -l issues.php
```

Expected: `No syntax errors detected in issues.php`

- [ ] **Step 4: Verify routing stubs**

In one terminal: `just serve`

In another:
```bash
curl -s http://localhost:8080/issues.php | grep -o 'TODO: overview'
# Expected: TODO: overview

curl -s "http://localhost:8080/issues.php?id=foo.txt" | grep -o 'TODO: detail'
# Expected: TODO: detail
```

- [ ] **Step 5: Commit**

```bash
git add issues.php data/issues/
git commit -m "feat(issues): scaffold issues.php routing + helper functions"
```

---

### Task 2: Overview view

**Files:**
- Modify: `issues.php` — replace `render_overview()` stub

**Interfaces:**
- Consumes: `parse_titel(string $path): string`, `filename_to_display(string $id): string`, `html_head()`, `html_foot()`
- Produces: HTML page listing `new/` and `ready/` issues in two tables, sorted newest first

- [ ] **Step 1: Create a fixture issue file for testing**

```bash
cat > data/issues/new/2026-06-23_10-00-00_testfixture.txt << 'EOF'
Titel: Login schlägt fehl nach Token-Ablauf
Zeit:  2026-06-23T10:00:00Z
Version: 0.3.1

--- Letzte Aktionen ---
09:59:44  navigateTo: /root

--- Zustand ---
tenant: demo, path: /root
EOF

cat > data/issues/ready/2026-06-22_09-11-00_testfixture2.txt << 'EOF'
Titel: Scroll-Bug in langer Liste
Zeit:  2026-06-22T09:11:00Z
Version: 0.3.0

--- Letzte Aktionen ---
09:10:30  navigateTo: /thema/test

--- Zustand ---
tenant: demo, path: /thema/test
EOF
```

- [ ] **Step 2: Replace render_overview() stub**

Replace the entire `render_overview` function:

```php
function render_overview(string $base): void {
    $cols = ['new' => [], 'ready' => []];
    foreach (array_keys($cols) as $state) {
        $dir   = "$base/$state";
        $files = is_dir($dir) ? (glob("$dir/*.txt") ?: []) : [];
        rsort($files);
        foreach ($files as $f) {
            $id           = basename($f);
            $cols[$state][] = [
                'id'    => $id,
                'titel' => parse_titel($f),
                'ts'    => filename_to_display($id),
            ];
        }
    }
    html_head('Issues'); ?>
<h1>Issues</h1>
<?php foreach (['new' => 'Neu', 'ready' => 'Bereit'] as $state => $label): ?>
<h2><?= $label ?></h2>
<?php if (empty($cols[$state])): ?>
  <p style="color:#888;font-size:0.9rem">Keine Issues.</p>
<?php else: ?>
<table>
  <tr><th>Datum</th><th>Titel</th></tr>
  <?php foreach ($cols[$state] as $row): ?>
  <tr>
    <td style="white-space:nowrap;color:#666;font-size:0.85rem"><?= htmlspecialchars($row['ts']) ?></td>
    <td><a href="issues.php?id=<?= urlencode($row['id']) ?>"><?= htmlspecialchars($row['titel']) ?></a></td>
  </tr>
  <?php endforeach ?>
</table>
<?php endif ?>
<?php endforeach;
    html_foot();
}
```

- [ ] **Step 3: Verify syntax**

```bash
php -l issues.php
```

Expected: `No syntax errors detected in issues.php`

- [ ] **Step 4: Verify overview in browser**

With server running:
```bash
curl -s http://localhost:8080/issues.php | grep -o 'Login schlägt fehl'
# Expected: Login schlägt fehl

curl -s http://localhost:8080/issues.php | grep -o 'Scroll-Bug'
# Expected: Scroll-Bug
```

- [ ] **Step 5: Commit**

```bash
git add issues.php
git commit -m "feat(issues): implement overview view (new + ready)"
```

---

### Task 3: Detail view

**Files:**
- Modify: `issues.php` — replace `render_detail()` stub

**Interfaces:**
- Consumes: `find_issue()`, `html_head()`, `html_foot()`
- Produces: HTML page with full file content, state badge, transition buttons (POST forms)

Usual transitions shown per state:
| State | Buttons shown |
|---|---|
| new | ready, blocked, canceled |
| ready | inProgress, blocked, canceled |
| blocked | ready, canceled |
| inProgress | inReview, blocked |
| inReview | closed, inProgress |
| canceled | *(none)* |
| closed | *(none)* |

- [ ] **Step 1: Replace render_detail() stub**

```php
function render_detail(string $base, array $states, string $id): void {
    $issue = find_issue($base, $states, $id);
    if (!$issue) {
        http_response_code(404);
        html_head('404');
        echo '<h1>Issue nicht gefunden</h1><p><a href="issues.php">← Übersicht</a></p>';
        html_foot();
        return;
    }

    $content = htmlspecialchars(file_get_contents($issue['path']));
    $current = $issue['state'];

    $transitions = [
        'new'        => ['ready', 'blocked', 'canceled'],
        'ready'      => ['inProgress', 'blocked', 'canceled'],
        'blocked'    => ['ready', 'canceled'],
        'inProgress' => ['inReview', 'blocked'],
        'inReview'   => ['closed', 'inProgress'],
        'canceled'   => [],
        'closed'     => [],
    ];
    $buttons = $transitions[$current] ?? [];

    html_head('Issue: ' . parse_titel($issue['path'])); ?>
<p><a href="issues.php">← Übersicht</a></p>
<h1>
  <?= htmlspecialchars(parse_titel($issue['path'])) ?>
  <span class="badge badge-<?= htmlspecialchars($current) ?>"><?= htmlspecialchars($current) ?></span>
</h1>
<?php if (!empty($buttons)): ?>
<div class="actions">
  <span style="font-size:0.85rem;color:#666">Übergang:</span>
  <?php foreach ($buttons as $next): ?>
  <form method="POST" action="issues.php?id=<?= urlencode($id) ?>&amp;set=<?= urlencode($next) ?>">
    <button type="submit"><?= htmlspecialchars($next) ?></button>
  </form>
  <?php endforeach ?>
</div>
<?php endif ?>
<pre><?= $content ?></pre>
<?php html_foot();
}
```

- [ ] **Step 2: Verify syntax**

```bash
php -l issues.php
```

Expected: `No syntax errors detected in issues.php`

- [ ] **Step 3: Verify detail view**

With server running:
```bash
curl -s "http://localhost:8080/issues.php?id=2026-06-23_10-00-00_testfixture.txt" \
  | grep -o 'Login schlägt fehl'
# Expected: Login schlägt fehl

curl -s "http://localhost:8080/issues.php?id=2026-06-23_10-00-00_testfixture.txt" \
  | grep -o 'badge-new'
# Expected: badge-new

curl -s "http://localhost:8080/issues.php?id=2026-06-23_10-00-00_testfixture.txt" \
  | grep -o 'set=ready'
# Expected: set=ready (transition button)

curl -s "http://localhost:8080/issues.php?id=doesnotexist.txt" -o /dev/null -w "%{http_code}"
# Expected: 404
```

- [ ] **Step 4: Commit**

```bash
git add issues.php
git commit -m "feat(issues): implement detail view with state badge and transition buttons"
```

---

### Task 4: Transpose handler

**Files:**
- Modify: `issues.php` — replace `handle_transpose()` stub

**Interfaces:**
- Consumes: `find_issue()`, `append_verlauf()`
- Produces: Moves file to `data/issues/<set>/`, appends `--- Verlauf ---` log line, redirects to `issues.php`

- [ ] **Step 1: Replace handle_transpose() stub**

```php
function handle_transpose(string $base, array $states, string $id, string $set): void {
    $issue = find_issue($base, $states, $id);
    if (!$issue) {
        http_response_code(404);
        echo 'Issue nicht gefunden';
        return;
    }
    $newDir = "$base/$set";
    if (!is_dir($newDir)) {
        mkdir($newDir, 0755, true);
    }
    append_verlauf($issue['path'], $set);
    rename($issue['path'], "$newDir/$id");
    header('Location: issues.php');
}
```

- [ ] **Step 2: Verify syntax**

```bash
php -l issues.php
```

Expected: `No syntax errors detected in issues.php`

- [ ] **Step 3: Verify transpose moves the file and logs the transition**

With server running:
```bash
# Confirm fixture is in new/
ls data/issues/new/ | grep testfixture

# POST to move it to ready
curl -s -X POST \
  "http://localhost:8080/issues.php?id=2026-06-23_10-00-00_testfixture.txt&set=ready" \
  -o /dev/null -w "%{http_code}"
# Expected: 302 (redirect)

# File should now be in ready/
ls data/issues/ready/ | grep testfixture
# Expected: 2026-06-23_10-00-00_testfixture.txt

# Verlauf should be appended
grep "Verlauf" data/issues/ready/2026-06-23_10-00-00_testfixture.txt
# Expected: --- Verlauf ---
grep "→ ready" data/issues/ready/2026-06-23_10-00-00_testfixture.txt
# Expected: [2026-06-23 ...] → ready
```

- [ ] **Step 4: Verify second transition appends (not duplicates Verlauf header)**

```bash
curl -s -X POST \
  "http://localhost:8080/issues.php?id=2026-06-23_10-00-00_testfixture.txt&set=inProgress" \
  -o /dev/null -w "%{http_code}"

grep -c "--- Verlauf ---" data/issues/inProgress/2026-06-23_10-00-00_testfixture.txt
# Expected: 1  (header appears exactly once)

grep -c "→" data/issues/inProgress/2026-06-23_10-00-00_testfixture.txt
# Expected: 2  (ready + inProgress)
```

- [ ] **Step 5: Clean up fixtures**

```bash
rm -f data/issues/inProgress/2026-06-23_10-00-00_testfixture.txt
rm -f data/issues/ready/2026-06-22_09-11-00_testfixture2.txt
```

- [ ] **Step 6: Commit**

```bash
git add issues.php
git commit -m "feat(issues): implement transpose handler with Verlauf log"
```

---

### Task 5: app2.html — Titel field + report format update

**Files:**
- Modify: `app2.html`

**Interfaces:**
- Produces: Bug Melden dialog has a required Titel field; saved file starts with `Titel: <value>` on line 1
- The `--- Fehherbericht ===` header is removed; `Zeit:` and `Version:` become lines 2–3

Changes are three separate edits: (1) HTML, (2) `buildReportText()`, (3) `buildFullReport()` + `openIssueReport()` + send-button guard.

- [ ] **Step 1: Add Titel input to the Bug Melden overlay HTML**

In `app2.html`, find the `<div id="issue-panel">` block (around line 290). Add a Titel label + input immediately after `<h3>Problem melden</h3>`:

Current:
```html
    <h3>Problem melden</h3>
    <p style="margin:0;font-size:0.85rem;color:#666">Was ist passiert? (optional)</p>
    <textarea id="issue-user-msg" ...
```

Replace with:
```html
    <h3>Problem melden</h3>
    <p style="margin:0;font-size:0.85rem;color:#666">Titel <span style="color:#c00">*</span></p>
    <input type="text" id="issue-titel" style="width:100%;box-sizing:border-box;font-family:inherit;font-size:0.9rem;padding:0.35rem 0.5rem;border:1px solid #ccc;border-radius:4px" placeholder="Kurzer Titel des Problems">
    <p style="margin:0;font-size:0.85rem;color:#666">Was ist passiert? (optional)</p>
    <textarea id="issue-user-msg" ...
```

- [ ] **Step 2: Update buildReportText() — remove `=== Fehlerbericht ===` header**

Current first two lines inside `buildReportText`:
```js
    lines.push("=== Fehlerbericht ===");
    lines.push("Zeit:    " + new Date().toISOString());
```

Replace with (remove the `=== Fehlerbericht ===` line):
```js
    lines.push("Zeit:    " + new Date().toISOString());
```

- [ ] **Step 3: Update buildFullReport() to prepend Titel and restructure Nutzerbeschreibung**

Current:
```js
function buildFullReport() {
    const userMsg = document.getElementById("issue-user-msg").value.trim();
    const details = document.getElementById("issue-details").value;
    return (userMsg ? "Nutzerbeschreibung:\n" + userMsg + "\n\n" : "") + details;
}
```

Replace with:
```js
function buildFullReport() {
    const titel   = document.getElementById("issue-titel").value.trim();
    const userMsg = document.getElementById("issue-user-msg").value.trim();
    const details = document.getElementById("issue-details").value;
    return "Titel: " + titel + "\n"
        + details
        + (userMsg ? "\n\n--- Nutzerbeschreibung ---\n" + userMsg : "");
}
```

- [ ] **Step 4: Update openIssueReport() to clear Titel and disable send button**

Current:
```js
function openIssueReport(errorCtx) {
    if (document.getElementById("issue-overlay").classList.contains("open")) return;
    document.getElementById("issue-details").value = buildReportText(errorCtx);
    document.getElementById("issue-user-msg").value = "";
    document.getElementById("issue-overlay").classList.add("open");
}
```

Replace with:
```js
function openIssueReport(errorCtx) {
    if (document.getElementById("issue-overlay").classList.contains("open")) return;
    document.getElementById("issue-details").value = buildReportText(errorCtx);
    document.getElementById("issue-user-msg").value = "";
    document.getElementById("issue-titel").value = "";
    document.getElementById("issue-send").disabled = true;
    document.getElementById("issue-overlay").classList.add("open");
}
```

- [ ] **Step 5: Add Titel input listener to re-enable send button**

After the existing `document.getElementById("issue-close").addEventListener(...)` block, add:

```js
document.getElementById("issue-titel").addEventListener("input", () => {
    document.getElementById("issue-send").disabled =
        document.getElementById("issue-titel").value.trim() === "";
});
```

- [ ] **Step 6: Verify in browser**

Open `http://localhost:8080/app2.html`:
1. Tap "Bug Melden" in Settings → dialog opens with Titel field at top, send button disabled.
2. Leave Titel empty → send button stays disabled.
3. Type a title → send button becomes enabled.
4. Submit → `just serve` logs a POST to `issue.php`.
5. Check `data/issues/new/` → new `.txt` file present, first line is `Titel: <your title>`.
6. Verify second line starts with `Zeit:` (no `=== Fehlerbericht ===` header).

- [ ] **Step 7: Run unit tests to confirm no JS regressions**

```bash
just unit
```

Expected: all tests pass.

- [ ] **Step 8: Commit**

```bash
git add app2.html
git commit -m "feat(issues): add Titel field to Bug Melden dialog; update report format"
```

---

## Cleanup

After all tasks pass:

```bash
just ci
```

Expected: unit + e2e pass.

Remove fixture files if still present:
```bash
rm -f data/issues/*/2026-06-23_10-00-00_testfixture.txt \
       data/issues/*/2026-06-22_09-11-00_testfixture2.txt
```
