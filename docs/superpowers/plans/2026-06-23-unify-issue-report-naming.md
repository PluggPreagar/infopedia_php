# Unify Issue Report Naming & GUI-Test-Command Format

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename "bug report" → "issue report" everywhere, and reformat the action trail in `buildReportText` as JS method calls (`actionName("detail")`) so the block can be lifted directly into a GUI test script.

**Architecture:** Three change layers — (1) `buildReportText()` in `app2.html` defines the canonical output format; (2) `app2_test.js` unit tests verify the strings; (3) docs carry the prose names. Changes are purely cosmetic/renaming; no data model, routes, or storage changes.

**Tech Stack:** Vanilla JS in `app2.html`, test assertions in `test/app2_test.js`, Markdown docs.

## Global Constraints

- No classes, no framework, no Composer (CP1).
- All tests runnable via `just unit` (no server needed).
- German UI labels stay German (`"Issue melden"`, not `"Report Issue"`).
- Existing regex-based test assertions in `testBuildReportTextSeeded` must continue to pass without changes (the new format satisfies all existing regexes).

---

### Task 1: Fix `buildReportText` — correct headers + gui-test-command format

**Background:** The current implementation outputs `## Letzte Aktionen`, `## Zustand`, `## Fehlerdetails` (Markdown `##` headings), but the unit tests assert `--- Letzte Aktionen ---`, `--- Zustand ---`, etc. (dashed section lines). These tests are currently RED. This task fixes the implementation AND renames to the new vocabulary in one commit.

**Files:**
- Modify: `app2.html:364-390`
- Modify: `test/app2_test.js:137,150-161`

**Interfaces:**
- `buildReportText(errorCtx)` → `string` — no signature change; output format changes.
- `actionTrail` entries stay `{ ts, action, detail }` — only how they are rendered changes.

- [ ] **Step 1: Write the failing tests first (update test expectations)**

In `test/app2_test.js` at the `testBuildReportTextBasic` function (lines 149–162), replace the existing assertions with updated names:

```javascript
// UC13 — issue report
function testBuildReportTextBasic() {
    suite('buildReportText');
    rs();
    const rep = buildReportText(null);
    assert('has header',              rep.includes('=== Issuebericht ==='),     true);
    assert('has GUI commands section', rep.includes('--- GUI-Test-Befehle ---'), true);
    assert('has zustand section',     rep.includes('--- Zustand ---'),           true);
    assert('no error section',        rep.includes('--- Fehlerdetails ---'),     false);
    const repCtx = buildReportText({ label: 'sendVote', status: 500 });
    assert('has Fehlerdetails',       repCtx.includes('--- Fehlerdetails ---'),  true);
    assert('has error label',         repCtx.includes('sendVote'),               true);
}
```

Also update the comment on line 137:
```javascript
// UC13 — issue report
```

- [ ] **Step 2: Run tests to confirm they are RED**

```bash
just unit 2>&1 | grep -A2 "buildReportText\|FAIL\|PASS" | head -30
```

Expected: `testBuildReportTextBasic` fails (assertions for old strings no longer match expectation; old implementation doesn't produce new strings yet).

- [ ] **Step 3: Update `buildReportText` in `app2.html`**

Replace the full function body (lines 364–390):

```javascript
function buildReportText(errorCtx) {
    const lines = [];
    lines.push("=== Issuebericht ===");
    lines.push("Zeit: " + new Date().toISOString() + " | Version: " + APP_VERSION);
    lines.push("");
    lines.push("--- GUI-Test-Befehle ---");
    if (actionTrail.length === 0) {
        lines.push("(keine)");
    } else {
        actionTrail.slice().reverse().forEach(a => {
            lines.push(a.detail ? a.action + '("' + a.detail + '")' : a.action + '()');
        });
    }
    lines.push("");
    lines.push("--- Zustand ---");
    lines.push("```");
    lines.push(buildStateSnapshot());
    lines.push("```");
    if (errorCtx) {
        lines.push("");
        lines.push("--- Fehlerdetails ---");
        if (errorCtx.label)  lines.push("**Fehler:** " + errorCtx.label);
        if (errorCtx.status) lines.push("**Status:** " + errorCtx.status);
        if (errorCtx.url)    lines.push("**URL:** `" + errorCtx.url + "`");
        if (errorCtx.err)    lines.push("**Detail:** " + String(errorCtx.err));
    }
    return sanitiseForReport(lines.join("\n"));
}
```

Key changes:
- Added `=== Issuebericht ===` header (was missing entirely).
- All section markers changed from `## X` to `--- X ---`.
- `"## Letzte Aktionen"` → `"--- GUI-Test-Befehle ---"`.
- Action lines changed from `"- HH:MM:SS  action: detail"` → `action("detail")`.
- `"*(keine)*"` → `"(keine)"` (asterisks were markdown noise, not needed).

- [ ] **Step 4: Run tests to confirm GREEN**

```bash
just unit 2>&1 | grep -E "PASS|FAIL|buildReportText" | head -20
```

Expected: all `buildReportText` suites pass.  
Cross-check: `testBuildReportTextSeeded` still passes — its regexes `/navigate.*climate/` and `/addEntry/` match the new format `navigate("/climate")` and `addEntry("/climate/sol")`. `/(keine)/` matches `(keine)`. `/Version: 0\.2\.0/` still present.

- [ ] **Step 5: Commit**

```bash
git add app2.html test/app2_test.js
git commit -m "feat: issue report header + gui-test-command action format in buildReportText"
```

---

### Task 2: Rename action `changeTenant` → `loadTenant`

**Background:** The example the user gave uses `loadTenant("tid_...")` — the current action name is `changeTenant`. Renaming makes the recorded trail read as a direct JS test script.

**Files:**
- Modify: `app2.html:1184`
- Modify: `test/app2_test.js` — no existing test data uses `changeTenant`, but `testIssueReportPanel` seeds `vote` — no change needed there. Verify via grep.

**Interfaces:**
- `pushAction("loadTenant", "[tid]")` — same call, new string literal.

- [ ] **Step 1: Verify no test data uses `changeTenant`**

```bash
grep -n "changeTenant" test/app2_test.js
```

Expected: no matches (safe to rename without touching tests).

- [ ] **Step 2: Rename in `app2.html`**

In `app2.html` at line 1184, change:
```javascript
pushAction("changeTenant", "[tid]")
```
to:
```javascript
pushAction("loadTenant", "[tid]")
```

- [ ] **Step 3: Run tests**

```bash
just unit 2>&1 | grep -E "PASS|FAIL" | tail -5
```

Expected: all still GREEN (no test asserts the `changeTenant` string).

- [ ] **Step 4: Commit**

```bash
git add app2.html
git commit -m "refactor: rename changeTenant action → loadTenant for gui-test-command readability"
```

---

### Task 3: Rename UI labels and mail/GitHub subjects

**Background:** The settings button says "Bug Melden"; mail and GitHub issue titles say "Fehlerbericht". These are the last user-visible strings carrying the old name.

**Files:**
- Modify: `app2.html:281,432,441`

**Interfaces:** None — pure UI copy changes.

- [ ] **Step 1: Update button text (line 281)**

Change:
```html
<button class="settings-btn secondary" id="settings-bug-report">Bug Melden</button>
```
to:
```html
<button class="settings-btn secondary" id="settings-bug-report">Issue melden</button>
```

- [ ] **Step 2: Update mail subject (line 432)**

Change:
```javascript
const subject = encodeURIComponent("Fehlerbericht fayf.info " + APP_VERSION);
```
to:
```javascript
const subject = encodeURIComponent("Issuebericht fayf.info " + APP_VERSION);
```

- [ ] **Step 3: Update GitHub issue title (line 441)**

Change:
```javascript
const title = encodeURIComponent("Fehlerbericht fayf.info " + APP_VERSION);
```
to:
```javascript
const title = encodeURIComponent("Issuebericht fayf.info " + APP_VERSION);
```

- [ ] **Step 4: Run tests (smoke check)**

```bash
just unit 2>&1 | grep -E "PASS|FAIL" | tail -5
```

Expected: all GREEN (no tests assert button text or mail subject strings).

- [ ] **Step 5: Commit**

```bash
git add app2.html
git commit -m "refactor: rename Bug Melden → Issue melden, Fehlerbericht → Issuebericht in UI labels"
```

---

### Task 4: Update docs

**Background:** `docs/app2-use-cases.md` and `docs/app2-spec.md` still say "Report a bug" and "Bug Melden". Keeping docs in sync prevents confusion for future contributors.

**Files:**
- Modify: `docs/app2-use-cases.md:117,126,127`
- Modify: `docs/app2-spec.md:56`

**Interfaces:** N/A — documentation only.

- [ ] **Step 1: Update `docs/app2-use-cases.md`**

Line 117 — change:
```markdown
### UC13: Report a bug
```
to:
```markdown
### UC13: Report an issue
```

Line 126 — change:
```markdown
#### UC13a: Report a bug on manual request
```
to:
```markdown
#### UC13a: Report an issue on manual request
```

Line 127 — change:
```markdown
**Trigger:** User taps "Bug Melden" in Settings
```
to:
```markdown
**Trigger:** User taps "Issue melden" in Settings
```

- [ ] **Step 2: Update `docs/app2-spec.md`**

Line 56 — change:
```markdown
| UC13a | Settings "Bug Melden" button → `closeSettings()` + `openIssueReport(null)` |
```
to:
```markdown
| UC13a | Settings "Issue melden" button → `closeSettings()` + `openIssueReport(null)` |
```

- [ ] **Step 3: Verify no remaining "Bug Melden" or "Fehlerbericht" in docs**

```bash
grep -rn "Bug Melden\|Fehlerbericht\|bug report\|Report a bug" docs/
```

Expected: no matches.

- [ ] **Step 4: Commit**

```bash
git add docs/app2-use-cases.md docs/app2-spec.md
git commit -m "docs: rename bug report → issue report in UC13 use cases and spec"
```
