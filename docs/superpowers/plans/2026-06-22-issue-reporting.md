# Issue Reporting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Senden" button to the app2 bug-report panel that POSTs the report to a new `issue.php` backend endpoint which saves each report as a timestamped file in `data/issues/`.

**Architecture:** `issue.php` is a thin POST-only endpoint (same pattern as `dumps.php`) that appends nothing — it writes one file per report to `data/issues/`. `app2.html` gains a fourth submission option alongside clipboard/email/github. JS unit tests cover report text generation; an e2e PHP test covers the backend endpoint.

**Tech Stack:** PHP 8+, procedural, no Composer; JS ES2020 (fetch + URLSearchParams); `wrapper.php?test=app2.html` for JS tests; `just e2e` for PHP e2e tests.

## Global Constraints

- CP1: Plain procedural PHP — no classes, no framework, no Composer
- CP2: One file = one route (`issue.php`)
- Self-contained: `issue.php` uses `require_once 'util.php'` and `require_once 'util_http.php'` (same as all other endpoints)
- Report storage: `data/issues/<YYYY-MM-DD_HH-MM-SS>_<uniqid>.txt` — one file per report
- POST body param: `report` (plain text, the full report)
- Response: JSON `{"status":"ok"}` with HTTP 201 on success; error JSON on failure (same as `dumps.php`)
- Config key: `issueDir = data/issues` under `[issue]` section in `infopedia.cfg` and `infopedia_template.cfg`
- JS: `submitReportSend()` uses `fetch('issue.php', {method:'POST', body: new URLSearchParams({report})})` — no credentials, no auth
- JS tests run in browser via `wrapper.php?test=app2.html`; use `suite()`, `assert()`, `assertMatch()` from `test/harness.js`
- `rs()` resets state: `data={}, votesData={}, topicMap={}, selectedTopic='/', latestTimestamp=null, searchScope='below', activeTypes=new Set([...]), actionTrail=[]`

---

### Task 1: `issue.php` backend + e2e test

**Files:**
- Create: `issue.php`
- Modify: `test/e2e.php` (add issue section at end)
- Modify: `infopedia.cfg` (add `[issue]` section)
- Modify: `infopedia_template.cfg` (add `[issue]` section)

**Interfaces:**
- Consumes: POST `?sid=<sid>&tid=<tid>` with body `report=<text>`
- Produces: `{"status":"ok"}` HTTP 201, or error JSON

- [ ] **Step 1: Add `[issue]` section to both cfg files**

In `infopedia.cfg`, append at the end:
```ini
[issue]
issueDir = data/issues
```

In `infopedia_template.cfg`, append at the end:
```ini
[issue]
issueDir = data/issues
```

- [ ] **Step 2: Write the e2e test first (RED)**

Append to `test/e2e.php` before the final summary block (before `echo "\n$pass passed, $fail failed\n";`):

```php
// ─── issue.php ────────────────────────────────────────────────────────────────

section('issue.php — save report');

// POST with report text → 201
$r = post('issue.php', "sid=$sid&tid=$tid", 'report=Test+Fehlerbericht+%3A%29');
ok($r['status'] === 201, 'POST report → 201');
ok(($r['json']['status'] ?? '') === 'ok', 'body status = ok');

// Verify file was written to data/issues/
$files = glob('data/issues/*.txt');
ok(count($files) > 0, 'issue file created in data/issues/');
if (count($files) > 0) {
    $content = file_get_contents($files[0]);
    ok(str_contains($content, 'Test Fehlerbericht'), 'report text in file');
}

// POST with empty report → 400
$r = post('issue.php', "sid=$sid&tid=$tid", 'report=');
ok($r['status'] === 400, 'empty report → 400');

// GET → 405
$r = get('issue.php', "sid=$sid&tid=$tid");
ok($r['status'] === 405, 'GET → 405');

// cleanup
foreach (glob('data/issues/*.txt') as $f) unlink($f);
```

- [ ] **Step 3: Run the e2e test to confirm it fails (RED)**

```bash
just e2e 2>&1 | grep -A 20 'issue.php'
```

Expected: `FAIL  POST report → 201` (endpoint doesn't exist yet)

- [ ] **Step 4: Create `issue.php`**

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

$issueDir = $config['issueDir'] ?? 'data/issues';
if (!is_dir($issueDir)) mkdir($issueDir, 0755, true);

$filename = $issueDir . '/' . date('Y-m-d_H-i-s') . '_' . uniqid() . '.txt';
if (file_put_contents($filename, $report) === false) {
    respond_error('WRITE_ERROR', 'Could not save report', 500);
}

log_return('issue saved (' . strlen($report) . ' bytes)');
respond_json(['status' => 'ok'], 201);
```

- [ ] **Step 5: Run e2e tests to confirm they pass (GREEN)**

```bash
just e2e 2>&1 | grep -A 20 'issue.php'
```

Expected output:
```
── issue.php — save report
  PASS  POST report → 201
  PASS  body status = ok
  PASS  issue file created in data/issues/
  PASS  report text in file
  PASS  empty report → 400
  PASS  GET → 405
```

- [ ] **Step 6: Run full CI to make sure nothing else broke**

```bash
just ci
```

Expected: all tests pass, exit 0.

- [ ] **Step 7: Commit**

```bash
git add issue.php test/e2e.php infopedia.cfg infopedia_template.cfg
git commit -m "feat(issue): POST endpoint saves bug reports to data/issues/"
```

---

### Task 2: `app2.html` "Senden" button + JS unit test for report builder

**Files:**
- Modify: `app2.html` (add `submitReportSend()`, wire button, add `#issue-send` button to HTML)
- Modify: `test/app2_test.js` (add `testBuildReportText` and `testIssueReportPanel`)

**Interfaces:**
- Consumes: `issue.php` from Task 1 (POST endpoint at `issue.php`)
- Produces: `submitReportSend()` — callable from the issue panel

- [ ] **Step 1: Write JS unit tests first (RED)**

In `test/app2_test.js`, append after `testBottomSheetCloseButton`:

```js
// UC13 — buildReportText: seeds action trail + error context
function testBuildReportText() {
    suite('buildReportText — empty trail');
    rs();
    const r = buildReportText(null);
    assertMatch('has version',       r, /Version: 0\.2\.0/);
    assertMatch('has keine actions', r, /\(keine\)/);

    suite('buildReportText — seeded trail + error context');
    rs();
    actionTrail = [
        { ts: '2026-06-22T10:00:00.000Z', action: 'navigate', detail: '/climate' },
        { ts: '2026-06-22T10:00:01.000Z', action: 'addEntry', detail: '/climate/sol' },
    ];
    const ctx = { label: 'submitEntry', status: 500, url: '/entries', err: 'Network error' };
    const r2 = buildReportText(ctx);
    assertMatch('contains navigate action', r2, /navigate.*climate/);
    assertMatch('contains addEntry action', r2, /addEntry/);
    assertMatch('contains error label',    r2, /submitEntry/);
    assertMatch('contains status 500',     r2, /500/);
    assertMatch('contains URL',            r2, /\/entries/);
    assertMatch('SID is not exposed',      r2, /\[sid\]/);
}
testBuildReportText();

// UC13 — issue panel opens, shows generated text, has Senden button
function testIssueReportPanel() {
    suite('openIssueReport — panel opens and populates');
    rs();
    actionTrail = [{ ts: '2026-06-22T10:00:00.000Z', action: 'vote', detail: '/abc +1' }];
    openIssueReport(null);
    assert('overlay is open',    document.getElementById('issue-overlay').classList.contains('open'), true);
    assertMatch('details pre-filled', document.getElementById('issue-details').value, /vote/);
    assert('user-msg empty',     document.getElementById('issue-user-msg').value, '');
    assert('send button exists', !!document.getElementById('issue-send'), true);
    closeIssueReport();
    assert('overlay closed',     document.getElementById('issue-overlay').classList.contains('open'), false);
}
testIssueReportPanel();
```

- [ ] **Step 2: Run in browser to confirm tests fail (RED)**

Open `wrapper.php?test=app2.html` in browser.

Expected: `FAIL  send button exists` (button not yet added to HTML)

Note: `testBuildReportText` suites should already PASS (they test existing functions). Only the `send button exists` assertion should FAIL.

- [ ] **Step 3: Add `#issue-send` button to the issue panel HTML**

In `app2.html`, find:
```html
      <button id="issue-copy">📋 Kopieren</button>
      <button id="issue-mail">✉️ E-Mail</button>
      <button id="issue-github">🐛 GitHub</button>
      <button id="issue-close" style="margin-left:auto">Schließen</button>
```

Replace with:
```html
      <button id="issue-send">📤 Senden</button>
      <button id="issue-copy">📋 Kopieren</button>
      <button id="issue-mail">✉️ E-Mail</button>
      <button id="issue-github">🐛 GitHub</button>
      <button id="issue-close" style="margin-left:auto">Schließen</button>
```

- [ ] **Step 4: Add `submitReportSend()` and wire the button**

In `app2.html`, after `function submitReportGithub() { ... }` (around line 440), add:

```js
function submitReportSend() {
    const report = buildFullReport();
    closeIssueReport();
    fetch('issue.php', { method: 'POST', body: new URLSearchParams({ report }) })
        .then(r => r.ok
            ? showToast("Bericht gesendet!", "success", 2000)
            : showToast("Senden fehlgeschlagen.", "error"))
        .catch(() => showToast("Senden fehlgeschlagen.", "error"));
}
```

After the existing `document.getElementById("issue-copy").addEventListener(...)` line, add:

```js
document.getElementById("issue-send").addEventListener("click", submitReportSend);
```

- [ ] **Step 5: Run tests in browser to confirm all pass (GREEN)**

Open `wrapper.php?test=app2.html`.

Expected: all `testBuildReportText` and `testIssueReportPanel` suites PASS.

- [ ] **Step 6: Manual smoke test**

Start PHP server: `php -S localhost:8080` from the project root.

Open `http://localhost:8080/app2.html?tid=demo`.

1. Perform a few actions (navigate, vote)
2. Open Settings → tap "Bug Melden"
3. Tap "📤 Senden"
4. Verify toast: "Bericht gesendet!"
5. Check `data/issues/` — one `.txt` file should exist with the report content

- [ ] **Step 7: Commit**

```bash
git add app2.html test/app2_test.js
git commit -m "feat(app2): add Senden button to issue panel + JS tests for report builder (UC13)"
```
