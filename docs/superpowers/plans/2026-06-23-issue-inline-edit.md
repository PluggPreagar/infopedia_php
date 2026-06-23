# Issue Inline Edit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Allow editing an issue's full raw markdown inline in the detail view — textarea replaces rendered body on "Bearbeiten", saves to disk via `issue.php`, re-renders in place on success.

**Architecture:** `issue.php` gains a second path (edit) alongside creation, keyed on `$_POST['filename']`. `issues.php` `render_detail` adds an edit button, hidden textarea, and action bar; an inline `<script>` handles the toggle and fetch. No new files, no page reload.

**Tech Stack:** Plain PHP, vanilla JS, `renderMd()` from `md-renderer.js` (already loaded by `html_head`).

## Global Constraints

- CP1: Plain procedural PHP — no classes, no framework, no Composer.
- Tests run via `just unit` (PHP unit) and `just e2e` (subprocess e2e) — no server needed.
- German UI copy: "Bearbeiten", "Speichern", "Abbrechen".
- 64 KB report cap and empty-check apply to both create and edit paths in `issue.php`.
- `filename` present and non-empty → edit path; absent/empty → existing creation path unchanged.

---

## File Structure

| File | Change |
|---|---|
| `issue.php` | Add edit path: validate `filename`, overwrite file, return 200 |
| `issues.php` | Add edit button, textarea, action bar, and inline JS to `render_detail` |
| `test/e2e.php` | Add e2e tests for the edit path |

---

### Task 1: Backend edit path in `issue.php`

**Files:**
- Modify: `issue.php`
- Modify: `test/e2e.php` (new section after existing `issue.php` tests, ~line 206)

**Interfaces:**
- Consumes: `POST issue.php` with `report=<text>&filename=<state>/<id>` — e.g. `filename=new/2026-06-22_10-00-00_abc.md`
- Produces: `200 {"status":"ok"}` on success; `404` if file not found or path traversal; creation path unchanged at `201`

- [ ] **Step 1: Add failing e2e tests**

In `test/e2e.php`, insert a new section after the existing `issue.php` cleanup block (after line `foreach (glob('data/issues/*.txt') as $f) unlink($f);`):

```php
// ─── issue.php — edit ────────────────────────────────────────────────────────

section('issue.php — edit existing issue');

$testDir  = 'data/issues/new';
if (!is_dir($testDir)) mkdir($testDir, 0755, true);
$testFile = $testDir . '/test_edit_e2e.md';
file_put_contents($testFile, "# Original Title\nOriginal body.");

// Edit with valid filename → 200, content updated
$editBody = http_build_query([
    'report'   => "# Updated Title\nUpdated body.",
    'filename' => 'new/test_edit_e2e.md',
]);
$r = post('issue.php', '', $editBody);
ok($r['status'] === 200,                           'POST edit with filename → 200');
ok(($r['json']['status'] ?? '') === 'ok',          'body status = ok');
ok(
    file_get_contents($testFile) === "# Updated Title\nUpdated body.",
    'file content updated on disk'
);

// Non-existent filename → 404
$r = post('issue.php', '', http_build_query(['report' => 'x', 'filename' => 'new/nonexistent.md']));
ok($r['status'] === 404, 'non-existent filename → 404');

// Path traversal → 404
$r = post('issue.php', '', http_build_query(['report' => 'x', 'filename' => '../../../etc/passwd']));
ok($r['status'] === 404, 'path traversal → 404');

// Creation path still works (no filename)
$r = post('issue.php', '', http_build_query(['report' => 'Creation still works']));
ok($r['status'] === 201, 'creation path (no filename) still returns 201');

// cleanup
if (file_exists($testFile)) unlink($testFile);
foreach (glob('data/issues/new/*.md') as $f) {
    if (str_contains($f, uniqid('', false))) unlink($f);
}
// clean up the creation test file
foreach (glob('data/issues/new/????-??-??_*.md') as $f) {
    $content = file_get_contents($f);
    if (str_contains($content, 'Creation still works')) unlink($f);
}
```

- [ ] **Step 2: Run e2e to confirm tests are RED**

```bash
just e2e 2>&1 | grep -A1 "edit existing issue"
```

Expected: FAIL on "POST edit with filename → 200" (edit path not yet implemented).

- [ ] **Step 3: Implement the edit path in `issue.php`**

Replace the full contents of `issue.php` with:

```php
<?php
$type = 'issue';
require_once 'util.php';
require_once 'util_http.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_error('METHOD_NOT_ALLOWED', 'Only POST accepted', 405);
}

$report = $_POST['report'] ?? '';
if (trim($report) === '') {
    respond_error('INVALID_ENTRY', 'report must not be empty', 400);
}

if (strlen($report) > 65536) {
    respond_error('PAYLOAD_TOO_LARGE', 'report exceeds 64 KB limit', 413);
}

$issueDir = $config['issueDir'] ?? 'data/issues';
$filename = $_POST['filename'] ?? '';

if ($filename !== '') {
    $base   = realpath($issueDir);
    $target = realpath($issueDir . '/' . $filename);
    if ($base === false || $target === false
        || !str_starts_with($target, $base . '/')
        || !is_file($target)) {
        respond_error('NOT_FOUND', 'Issue not found', 404);
    }
    if (file_put_contents($target, $report) === false) {
        respond_error('WRITE_ERROR', 'Could not save report', 500);
    }
    log_return('issue updated (' . strlen($report) . ' bytes)');
    respond_json(['status' => 'ok'], 200);
} else {
    $issueDirNew = $issueDir . '/new';
    if (!is_dir($issueDirNew)) {
        @mkdir($issueDir, 0755, true);
        if (!is_dir($issueDir)) {
            respond_error('WRITE_ERROR', 'cannot create issue directory', 500);
        }
    }
    $newFile = $issueDirNew . '/' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.md';
    if (file_put_contents($newFile, $report) === false) {
        respond_error('WRITE_ERROR', 'Could not save report', 500);
    }
    log_return('issue saved (' . strlen($report) . ' bytes)');
    respond_json(['status' => 'ok'], 201);
}
```

Key change: the old `$filename` local was the new file path; renamed to `$newFile` to avoid shadowing `$_POST['filename']`.

- [ ] **Step 4: Run e2e to confirm GREEN**

```bash
just e2e 2>&1 | grep -E "PASS|FAIL" | grep -A0 "edit\|201\|404\|200"
```

Expected: all four new assertions PASS, existing `issue.php — save report` assertions unchanged.

Full suite:

```bash
just e2e 2>&1 | tail -5
```

Expected: summary line shows 0 failures.

- [ ] **Step 5: Commit**

```bash
git add issue.php test/e2e.php
git commit -m "feat: issue.php edit path — POST with filename overwrites existing file"
```

---

### Task 2: Frontend inline edit in `issues.php`

**Files:**
- Modify: `issues.php:148-193` (`render_detail` function)

**Interfaces:**
- Consumes: Task 1's `POST issue.php` with `filename=<state>/<id>&report=<text>` → `200`
- Consumes: `renderMd(bodyPart)` from `md-renderer.js` (already loaded in `html_head`)
- Produces: inline edit UI visible in browser at `issues.php?id=<id>`

- [ ] **Step 1: Replace `render_detail` in `issues.php`**

Replace the entire `render_detail` function (lines 148–193) with:

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

    $raw     = file_get_contents($issue['path']);
    $body    = ltrim(substr($raw, (strpos($raw, "\n") ?: 0) + 1));
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

    $titel = parse_titel($issue['path']);
    html_head('Issue: ' . $titel); ?>
<p><a href="issues.php">← Übersicht</a></p>
<h1>
  <?= htmlspecialchars($titel) ?>
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
<button id="edit-btn" style="margin-bottom:0.5rem">Bearbeiten</button>
<textarea id="edit-area" hidden rows="20"
  style="width:100%;box-sizing:border-box;font-family:monospace;font-size:0.85rem;margin-bottom:0.5rem;"></textarea>
<div id="edit-bar" hidden style="margin-bottom:0.5rem;display:flex;gap:0.5rem;align-items:center;">
  <button id="save-btn">Speichern</button>
  <button id="cancel-btn">Abbrechen</button>
  <span id="edit-err" style="color:#c00;font-size:0.85rem;"></span>
</div>
<div id="md-body" data-raw="<?= htmlspecialchars($body) ?>"></div>
<script>
function initEdit(filename, fullRaw) {
    const mdBody    = document.getElementById('md-body');
    const editBtn   = document.getElementById('edit-btn');
    const editArea  = document.getElementById('edit-area');
    const editBar   = document.getElementById('edit-bar');
    const saveBtn   = document.getElementById('save-btn');
    const cancelBtn = document.getElementById('cancel-btn');
    const editErr   = document.getElementById('edit-err');

    editBtn.addEventListener('click', () => {
        editArea.value      = fullRaw;
        mdBody.hidden       = true;
        editBtn.hidden      = true;
        editArea.hidden     = false;
        editBar.hidden      = false;
        editErr.textContent = '';
    });

    cancelBtn.addEventListener('click', () => {
        mdBody.hidden   = false;
        editBtn.hidden  = false;
        editArea.hidden = true;
        editBar.hidden  = true;
    });

    saveBtn.addEventListener('click', () => {
        saveBtn.disabled = true;
        fetch('issue.php', {
            method: 'POST',
            body: new URLSearchParams({ report: editArea.value, filename })
        })
        .then(r => r.ok ? r.json() : Promise.reject(r.status))
        .then(() => {
            const newRaw    = editArea.value;
            const nl        = newRaw.indexOf('\n');
            const titleLine = nl >= 0 ? newRaw.slice(0, nl) : newRaw;
            const bodyPart  = nl >= 0 ? newRaw.slice(nl + 1) : '';

            mdBody.dataset.raw = bodyPart;
            mdBody.innerHTML   = renderMd(bodyPart);

            const m = titleLine.match(/^#\s+(.+)/u);
            if (m) {
                const h1 = document.querySelector('h1');
                if (h1) {
                    for (const node of h1.childNodes) {
                        if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) {
                            node.textContent = m[1] + ' ';
                            break;
                        }
                    }
                }
            }

            fullRaw         = newRaw;
            mdBody.hidden   = false;
            editBtn.hidden  = false;
            editArea.hidden = true;
            editBar.hidden  = true;
        })
        .catch(() => {
            editErr.textContent = 'Speichern fehlgeschlagen.';
        })
        .finally(() => { saveBtn.disabled = false; });
    });
}
initEdit(<?= json_encode($current . '/' . $id) ?>, <?= json_encode($raw) ?>);
</script>
<?php html_foot();
}
```

- [ ] **Step 2: Run unit + e2e to confirm no regressions**

```bash
just unit 2>&1 | tail -3
just e2e  2>&1 | tail -5
```

Expected: both suites pass with 0 failures. (The `render_detail` changes are pure HTML/JS — no PHP logic changes, so no new unit tests needed; correctness is verified in the browser.)

- [ ] **Step 3: Commit**

```bash
git add issues.php
git commit -m "feat: inline edit UI in issue detail view"
```
