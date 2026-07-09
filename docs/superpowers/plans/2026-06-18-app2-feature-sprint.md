# app2 Feature Sprint — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the active poll crash bug, document app2 use cases, improve card UX (long-press edit, double-click drill, single-click notice), group tests into functions, add popstate support, add icons to type and scope chips with a display-mode toggle.

**Architecture:** All changes are in `app2.html` (JS + HTML) and `entries.php` (PHP bug fix) plus test updates in `test/app2_test.js`. No new files except `docs/app2-use-cases.md`. Follows the existing procedural JS pattern in app2.html.

**Tech Stack:** Vanilla JS, PHP 8, Font Awesome 6.0.0-beta3 (already loaded via CDN), no build tools.

## Global Constraints

- CP1: Plain procedural PHP — no classes, no framework, no Composer
- CP1-JS: Plain vanilla JS — no frameworks, no build step, no imports
- Font Awesome 6.0.0-beta3 already loaded from CDN: `fas` prefix, solid icons only
- Mobile-first: touch events must work alongside mouse events
- All JS in `app2.html` is one `<script>` block — keep it that way
- No new PHP files
- Run JS tests: open `http://localhost:8080/wrapper.php?test=app2.html` in browser
- Run PHP tests: `just unit` and `just e2e`
- Server start: `php -S localhost:8080 router.php &`

---

## File Structure

| File | Task(s) | Changes |
|------|---------|---------|
| `entries.php` | T0 | Cache path: add 204 when `$since`-filtered output is empty |
| `app2.html` | T0,T2,T5,T6,T7 | safeJson guard; card interactions; nav; icons; scope chips |
| `test/app2_test.js` | T3,T4,T5,T6,T7 | Refactor suites into functions; add new test suites |
| `docs/app2-use-cases.md` | T1 | New file: use case documentation |

---

### Task 0: Fix poll empty-body crash

**Root cause:** `entries.php` cache path (lines 138–142) calls `echo _get_respond(...)` without checking if the output is empty. When `?since=…` finds no new entries from cache, `_get_respond` returns `''` and the server sends HTTP 200 with an empty body. The non-cache path at lines 188–192 correctly sends 204, but the cache path does not. `safeJson` in `app2.html` then calls `JSON.parse("")` which throws `Unexpected end of JSON input`, crashing the poll loop.

**Files:**
- Modify: `entries.php:138-142`
- Modify: `app2.html` (the `safeJson` function, currently at lines 920–930)

**Interfaces:**
- Consumes: nothing from other tasks
- Produces: `entries.php` returns 204 on no-new-since-data from cache; `safeJson` returns `null` for empty body (callers already handle null: `addData(null)` is a no-op at line 552)

- [ ] **Step 1: Fix `entries.php` — cache path must check for empty output**

Find the cache-hit block (lines 138–142):
```php
if (!$refresh && isCacheValid($cache_file, $cache_max_age, $outdated_file, $cache_delay)) {
    $data = readCache($cache_file);
    log_return(strlen($data) . ' bytes from cache');
    echo _get_respond($data, $format, $since);
    exit;
}
```

Replace with:
```php
if (!$refresh && isCacheValid($cache_file, $cache_max_age, $outdated_file, $cache_delay)) {
    $data = readCache($cache_file);
    $out = _get_respond($data, $format, $since);
    if ($since !== '' && $out === '') {
        log_return('204 no new entries since ' . $since . ' (cache)');
        http_response_code(204);
        exit;
    }
    log_return(strlen($out) . ' bytes from cache');
    echo $out;
    exit;
}
```

- [ ] **Step 2: Fix `safeJson` in `app2.html` — guard against empty body**

Find `safeJson` (currently around line 920):
```javascript
async function safeJson(res, label) {
    const text = await res.text();
    try {
        return JSON.parse(text);
    } catch (err) {
        console.error("[app2] " + label + " JSON parse error:", err.message,
            "\n  status:", res.status, res.url,
            "\n  body:", text.slice(0, 500));
        throw new Error(label + ": ungültiges JSON (Status " + res.status + ") — Details in der Konsole");
    }
}
```

Replace with:
```javascript
async function safeJson(res, label) {
    const text = await res.text();
    if (!text.trim()) return null;
    try {
        return JSON.parse(text);
    } catch (err) {
        console.error("[app2] " + label + " JSON parse error:", err.message,
            "\n  status:", res.status, res.url,
            "\n  body:", text.slice(0, 500));
        throw new Error(label + ": ungültiges JSON (Status " + res.status + ") — Details in der Konsole");
    }
}
```

- [ ] **Step 3: Verify PHP fix — should return 204 for future `since` timestamps**

```bash
# Start server if not running
php -S localhost:8080 router.php &

# First, ensure there is some cached data for tenant 'demo'
curl -s "http://localhost:8080/entries?sid=www&tid=demo&format=json" > /dev/null

# Now query with a future 'since' — should get 204, not 200 with empty body
STATUS=$(curl -s -o /dev/null -w '%{http_code}' \
  "http://localhost:8080/entries?sid=www&tid=demo&format=json&since=2099-01-01+00%3A00%3A00")
echo "Status: $STATUS"
# Expected: Status: 204
```

- [ ] **Step 4: Verify JS fix — no console errors during polling**

Open `http://localhost:8080/app2.html?tid=demo` in browser. Open DevTools console. Watch for 60 seconds. The message `[app2] poll JSON parse error: Unexpected end of JSON input` must NOT appear.

- [ ] **Step 5: Commit**

```bash
git add entries.php app2.html
git commit -m "fix(entries): return 204 from cache path when since-filtered output is empty; guard safeJson against empty body"
```

---

### Task 1: Document app2 use cases

**Files:**
- Create: `docs/app2-use-cases.md`

**Interfaces:**
- Consumes: nothing
- Produces: `docs/app2-use-cases.md`

- [ ] **Step 1: Create `docs/app2-use-cases.md`**

```markdown
# app2 Use Cases

## Overview

app2.html is a mobile-first, single-page wiki client for the InfoPedia backend.
It enables collaborative, topic-hierarchical knowledge collection with typed entries
and a voting/signing system.

## Core Concepts

| Concept | Description |
|---------|-------------|
| **Topic** | A hierarchical path like `/climate/solutions`. Root is `/`. |
| **Entry** | A single contribution at a topic, with a type suffix and optional votes. |
| **SID** | Session identifier stored in localStorage. Ties votes to a session. |
| **TID** | Tenant ID (`?tid=demo`). Entries are isolated per tenant. |
| **Long-poll** | Client keeps a connection open to `/entries?since=…`; server holds up to 50 s. |

## Entry Types

| Suffix | Label      | Meaning |
|--------|------------|---------|
| `.`    | Meinung    | Subjective opinion |
| `!`    | Fakt       | Claimed fact |
| `!-`   | Fake       | Identified misinformation |
| `?`    | Unklar     | Unclear or needs clarification |
| `??`   | Gegenfrage | Counter-question |
| `>`    | Thema      | Sub-topic link (drills into a child topic) |

## Use Cases

### UC1: Browse root entries
**Trigger:** User opens `app2.html?tid=demo`
1. App sets `selectedTopic = "/"` (root)
2. `loadInitialData()` fetches all entries and votes in parallel
3. Cards render for root-level entries (direct children of `/`)
4. `startLongPoll()` begins the real-time update loop

### UC2: Navigate into a topic
**Trigger:** User clicks the drill-in arrow (chevron) on a topic card (type `>`)
1. `navigateTo("/climate")` is called
2. `selectedTopic = "/climate"`
3. `updateView()` re-renders cards — only entries at `/climate` appear
4. nav-back updates to show the parent's label ("← fayf.info" at root, otherwise parent name)
5. URL updates: `?topic=%2Fclimate`

### UC3: Navigate back
**Trigger:** User taps the nav-back arrow, or presses the browser back button
1. If tapping nav-back: `navigateTo(parentTopic)` is called
2. If browser back: `popstate` event fires → URL read → `navigateTo` called
3. View returns to parent topic

### UC4: Add a new entry (FAB)
**Trigger:** User taps the `+` FAB button
1. Bottom sheet slides up; textarea is empty, type chip "Meinung" active
2. User types text and optionally selects a different type chip
3. User taps "Senden"
4. `submitEntry()` POSTs to `/entries?sid=…&tid=…` with the full path and message
5. Entry appears immediately (optimistic UI); `latestTimestamp` updates

### UC5: Edit an existing entry (long-press)
**Trigger:** User holds a card ≥ 500 ms (long-press)
1. Bottom sheet opens pre-filled: textarea shows entry text (without suffix), type chip matches entry type
2. Heading reads "Eintrag bearbeiten"
3. User edits text and/or changes type
4. Taps "Senden"
5. Same nodeId is re-POSTed; server timestamps the update; client updates local state

### UC6: Add sub-entry via double-click / double-tap
**Trigger:** User double-clicks (≤ 350 ms between clicks) on a card
1. `navigateTo(card.fullKey)` — enters that card's topic context
2. Bottom sheet opens immediately for adding a sub-entry at the new topic

### UC7: Single-click hint
**Trigger:** Single click on a card body (not on the drill arrow, vote, or sign buttons)
1. Toast shows: "Lange drücken zum Bearbeiten · Doppelklick für Untereinträge"
2. No navigation; no sheet opens

### UC8: Drill into topic via arrow
**Trigger:** Click on the `›` drill arrow on a topic card
1. `navigateTo(card.fullKey)` — same as UC2

### UC9: Vote on an entry
**Trigger:** Tap ▲/▼ buttons, or swipe a card left/right (mobile, ≥ 50 px)
1. Vote is sent to `/votes?sid=…&tid=…`
2. Score updates immediately (optimistic UI)
3. Swipe right = +1, swipe left = −1

### UC10: Confirm (sign) an entry
**Trigger:** User taps "Bestätigen" on a card
1. `signEntry()` POSTs a `signed:sid:1` vote to `/votes`
2. Confirmation count shows on the card
3. ≥ 2 signatures marks entry as "Bewiesen ✓" (verified fact)

### UC11: Search entries
**Trigger:** User taps the search icon, enters text
1. Search bar slides open with scope chips: Global / Hier / Darunter
2. Entries filter in real time by text match
3. Scope chips control range:
   - **Global**: all topics
   - **Hier**: only `selectedTopic`
   - **Darunter** (default): `selectedTopic` and its subtopics

### UC12: Change tenant
**Trigger:** User opens Settings, changes the Tenant ID field, taps Apply
1. `applySettings()` clears all data and the poll
2. New tenant's data loads fresh; URL updates with `?tid=…`

### UC13: Report a bug
**Trigger:** User taps "Melden" on an error toast, or the issue report button
1. Issue panel opens, auto-filled with action trail and state snapshot
2. User adds a description
3. Can copy to clipboard, open GitHub issue, or send via email

### UC14: Real-time update (long poll)
**Trigger:** Another user adds or edits an entry on the same tenant
1. Server holds the current poll connection until the file changes (up to 50 s)
2. New data arrives → `addData()` merges entries → `updateView()` re-renders
3. If nothing new after 50 s: server sends 204 → client immediately re-polls

## State Variables

| Variable | Type | Description |
|----------|------|-------------|
| `data` | `{topic: {nodeId: entry}}` | All loaded entries by topic |
| `votesData` | `{"/topic/node": {votes, signed}}` | Vote/sign counts |
| `selectedTopic` | `string` | Currently viewed topic path |
| `latestTimestamp` | `string\|null` | Last known server timestamp for long-poll |
| `searchScope` | `"below"\|"here"\|"global"` | Scope filter for search |
| `activeTypes` | `Set<string>` | Which type suffixes to show |
| `actionTrail` | `Array` | Last N user actions (for bug reports) |
```

- [ ] **Step 2: Commit**

```bash
git add docs/app2-use-cases.md
git commit -m "docs(app2): add use cases document"
```

---

### Task 2: Card interaction UX — long-press edit, double-click drill+add, single-click notice

**Goal:** Replace the current "any click on card = navigate" with three gestures:
- **Single click** on card body → toast notice ("Lange drücken zum Bearbeiten · Doppelklick für Untereinträge")
- **Double-click / double-tap** (≤ 350 ms) on card → navigate into that card's topic + open bottom sheet
- **Long press** (≥ 500 ms) on card → open bottom sheet pre-filled with entry data for editing
- **Click on drill-arrow** (the `›`) → navigate (unchanged)
- Vote and sign buttons are unaffected

**Files:**
- Modify: `app2.html`

**Interfaces:**
- Consumes: `data`, `selectedTopic`, `splitKey`, `openBottomSheet`, `navigateTo`, `pushAction`, `showToast`, `closeBottomSheet`
- Produces: `openBottomSheet(editEntry?)` — `editEntry` is an entry object `{topic, nodeId, message, votes, timestamp}` or `null` for new-entry mode; `_bsEditEntry` global (null | entry)

- [ ] **Step 1: Add `_bsEditEntry` variable after `let bsSelectedType`**

Find `let bsSelectedType = ".";` and add on the next line:
```javascript
let _bsEditEntry = null;
```

- [ ] **Step 2: Replace `openBottomSheet()` with the edit-aware version**

Find the existing `openBottomSheet()` function (lines ~1000–1006) and replace the entire function:
```javascript
function openBottomSheet(editEntry = null) {
    _bsEditEntry = editEntry;
    if (!editEntry && REQUIRE_TOPIC_FOR_ENTRY && selectedTopic === "/") {
        showToast("Wähle zuerst ein Thema.", "info"); return;
    }
    const heading = document.querySelector("#bottom-sheet h3");
    if (heading) heading.textContent = editEntry ? "Eintrag bearbeiten" : "Neuer Eintrag";
    if (editEntry) {
        const type = getTypeFromMessage(editEntry.message);
        bsSelectedType = type || ".";
        const rawText = editEntry.message.replace(/[.!?@>\-]+$/, "").trim();
        document.getElementById("bs-textarea").value = rawText;
    } else {
        bsSelectedType = ".";
        document.getElementById("bs-textarea").value = "";
    }
    document.querySelectorAll(".bs-type-chip").forEach(c => {
        c.classList.toggle("active", c.dataset.type === bsSelectedType);
    });
    updateBsColor(bsSelectedType);
    document.getElementById("bottom-sheet-overlay").classList.add("open");
    document.getElementById("bs-textarea").focus();
}
```

- [ ] **Step 3: Update `closeBottomSheet()` to clear `_bsEditEntry`**

Replace `closeBottomSheet()`:
```javascript
function closeBottomSheet() {
    _bsEditEntry = null;
    document.getElementById("bottom-sheet-overlay").classList.remove("open");
}
```

- [ ] **Step 4: Replace `submitEntry()` to handle edit vs. new mode**

Replace the existing `submitEntry()` function:
```javascript
async function submitEntry() {
    const text = document.getElementById("bs-textarea").value.trim();
    if (text.length < 3) { showToast("Zu kurz (mind. 3 Zeichen).", "error"); return; }
    const message = matchType(text, bsSelectedType);
    const isEdit  = !!_bsEditEntry;
    const nodeId   = isEdit ? _bsEditEntry.nodeId  : generateNodeId();
    const topic    = isEdit ? _bsEditEntry.topic   : selectedTopic;
    const fullPath = topic === "/" ? "/" + nodeId : topic + "/" + nodeId;
    const body = new URLSearchParams({ entry: `${fullPath} | ${message}` });
    try {
        const res = await fetch("entries?" + new URLSearchParams({ sid, tid: tenantId }), { method: "POST", body });
        if (res.ok) {
            const json = await res.json();
            pushAction(isEdit ? "editEntry" : "addEntry", fullPath);
            addEntry(topic, nodeId, message, isEdit ? (_bsEditEntry.votes || 0) : 0, json.timestamp || "");
            updateView();
            showToast(isEdit ? "Eintrag aktualisiert!" : "Eintrag hinzugefügt!", "success");
            closeBottomSheet();
        } else {
            showToast("Eintrag konnte nicht gespeichert werden.", "error", 3000, { label: "submitEntry", status: res.status, url: res.url });
        }
    } catch (err) {
        console.error("[app2] submitEntry:", err);
        showToast("Verbindung unterbrochen. Bitte erneut versuchen.", "error", 3000, { label: "submitEntry", err });
    }
}
```

- [ ] **Step 5: Replace the existing card-list click handler with gesture handlers**

Find and REMOVE this existing block (lines ~875–882):
```javascript
document.getElementById("card-list").addEventListener("click", e => {
    const card = e.target.closest(".card");
    if (!card) return;
    // don't drill if vote/sign button clicked
    if (e.target.closest(".vote-btn") || e.target.closest(".sign-btn")) return;
    const key = card.dataset.fullKey;
    navigateTo(key);
});
```

Replace it with:
```javascript
// ── Card tap / long-press / double-tap ────────────────────────────────────────
let _lastTapKey  = null;
let _lastTapTime = 0;
let _lpTimer     = null;
let _lpConsumed  = false;

const _cardList = document.getElementById("card-list");

_cardList.addEventListener("pointerdown", e => {
    const card = e.target.closest(".card");
    if (!card || e.target.closest(".vote-btn") || e.target.closest(".sign-btn") || e.target.closest(".drill-arrow")) return;
    _lpConsumed = false;
    clearTimeout(_lpTimer);
    _lpTimer = setTimeout(() => {
        _lpTimer = null;
        _lpConsumed = true;
        const key = card.dataset.fullKey;
        if (!key) return;
        const [topic, nodeId] = splitKey(key);
        const entry = data[topic] && data[topic][nodeId];
        if (!entry) return;
        pushAction("longPress", key);
        openBottomSheet(entry);
    }, 500);
}, { passive: true });

_cardList.addEventListener("pointerup",   () => { clearTimeout(_lpTimer); _lpTimer = null; }, { passive: true });
_cardList.addEventListener("pointermove", () => { clearTimeout(_lpTimer); _lpTimer = null; }, { passive: true });

_cardList.addEventListener("click", e => {
    if (_lpConsumed) { _lpConsumed = false; return; }
    const card = e.target.closest(".card");
    if (!card) return;
    if (e.target.closest(".vote-btn") || e.target.closest(".sign-btn")) return;

    if (e.target.closest(".drill-arrow")) {
        navigateTo(card.dataset.fullKey);
        return;
    }

    const key = card.dataset.fullKey;
    const now = Date.now();
    if (_lastTapKey === key && now - _lastTapTime < 350) {
        _lastTapKey = null; _lastTapTime = 0;
        navigateTo(key);
        openBottomSheet();
    } else {
        _lastTapKey = key; _lastTapTime = now;
        showToast("Lange drücken zum Bearbeiten · Doppelklick für Untereinträge", "info", 2000);
    }
});
```

- [ ] **Step 6: Verify manually**

Open `http://localhost:8080/app2.html?tid=demo`:
1. Single-click on a card body → toast "Lange drücken zum Bearbeiten · Doppelklick für Untereinträge" appears
2. Double-click on a card → navigates into that topic, bottom sheet opens empty (new entry mode)
3. Long-press (hold ~0.6 s) on a card → bottom sheet opens pre-filled with entry text and type
4. Click on `›` drill arrow → navigates into that topic (no sheet)
5. Vote buttons (▲▼) still work normally

- [ ] **Step 7: Commit**

```bash
git add app2.html
git commit -m "feat(app2): long-press to edit entry; double-click to drill+add; single-click shows notice"
```

---

### Task 3: GUI tests for card interactions and bottom sheet

**Files:**
- Modify: `test/app2_test.js`

**Interfaces:**
- Consumes: `openBottomSheet(editEntry?)`, `closeBottomSheet`, `_bsEditEntry`, `data`, `addEntryWoCheck`, `navigateTo`, `updateView`, `showToast` from T2

- [ ] **Step 1: Add GUI test suites before the final `harnessFinish()` call**

Open `test/app2_test.js`. Find `harnessFinish();` at the very end. Add the following **before** it:

```javascript
// ── openBottomSheet — edit mode ───────────────────────────────────────────────
suite('openBottomSheet — edit mode');
rs();
addEntryWoCheck('/', 'n1', 'Solar!', 5, '');
const _editE = data['/']['n1'];
openBottomSheet(_editE);
assert('textarea pre-filled',       document.getElementById('bs-textarea').value,           'Solar');
assert('active chip matches type',  document.querySelector('.bs-type-chip.active').dataset.type, '!');
assert('heading says Bearbeiten',   document.querySelector('#bottom-sheet h3').textContent,  'Eintrag bearbeiten');
closeBottomSheet();
assert('heading reset after close', document.querySelector('#bottom-sheet h3').textContent,  'Neuer Eintrag');

// ── openBottomSheet — new mode ────────────────────────────────────────────────
suite('openBottomSheet — new mode');
rs();
openBottomSheet();
assert('textarea empty',           document.getElementById('bs-textarea').value,           '');
assert('heading says Neuer',       document.querySelector('#bottom-sheet h3').textContent,  'Neuer Eintrag');
assert('default chip is Meinung',  document.querySelector('.bs-type-chip.active').dataset.type, '.');
closeBottomSheet();

// ── openBottomSheet — edit strips suffix from textarea ────────────────────────
suite('openBottomSheet — suffix stripping');
rs();
addEntryWoCheck('/', 'n2', 'Some opinion.', 0, '');
openBottomSheet(data['/']['n2']);
assert('dot suffix stripped',      document.getElementById('bs-textarea').value, 'Some opinion');
closeBottomSheet();

suite('openBottomSheet — fake entry');
rs();
addEntryWoCheck('/', 'n3', 'Wrong claim!-', 0, '');
openBottomSheet(data['/']['n3']);
assert('text stripped',            document.getElementById('bs-textarea').value, 'Wrong claim');
assert('chip is fake',             document.querySelector('.bs-type-chip.active').dataset.type, '!-');
closeBottomSheet();
```

- [ ] **Step 2: Verify tests pass**

Open `http://localhost:8080/wrapper.php?test=app2.html`. The new suites (openBottomSheet — edit mode, new mode, suffix stripping, fake entry) must all show PASS. No regressions in existing suites.

- [ ] **Step 3: Commit**

```bash
git add test/app2_test.js
git commit -m "test(app2): add GUI tests for bottom sheet edit mode, new mode, suffix stripping"
```

---

### Task 4: Refactor app2_test — wrap suites in named functions

**Goal:** Wrap every suite block in a named function (e.g. `function testFullKey() { ... }`) and call it immediately. This makes suites independently readable, individually runnable, and easier to extend.

**Files:**
- Modify: `test/app2_test.js`

**Interfaces:**
- Consumes: nothing new
- Produces: same test behaviour, same suites, same pass/fail outcomes — just restructured

- [ ] **Step 1: Rewrite `test/app2_test.js` with function-wrapped suites**

The complete rewritten file follows. Every existing test is preserved; only the wrapping changes. The new suites from T3 are included.

```javascript
/**
 * Test cases for app2.html — loaded by wrapper.php?test=app2.html
 * Requires: test/harness.js (suite, assert, assertMatch, harnessFinish)
 * Each test group is wrapped in a named function and called immediately.
 */

function rs() {
    data            = {};
    votesData       = {};
    topicMap        = {};
    selectedTopic   = '/';
    latestTimestamp = null;
    searchScope     = 'below';
    activeTypes     = new Set(['.', '!', '!-', '?', '??']);
    actionTrail     = [];
    document.getElementById('search-input').value = '';
}

function testFullKey() {
    suite('fullKey');
    assert('root + nodeId',  fullKey('/', 'abc'),        '/abc');
    assert('nested topic',   fullKey('/climate', 'sol'), '/climate/sol');
    assert('deep nesting',   fullKey('/a/b', 'c'),       '/a/b/c');
}
testFullKey();

function testSplitKey() {
    suite('splitKey');
    assert('root entry',     splitKey('/abc'),           ['/', 'abc']);
    assert('one-level path', splitKey('/climate/sol'),   ['/climate', 'sol']);
    assert('deep path',      splitKey('/a/b/c'),         ['/a/b', 'c']);
}
testSplitKey();

function testGetTypeFromMessage() {
    suite('getTypeFromMessage');
    assert('opinion (.)',      getTypeFromMessage('Hello.'),  '.');
    assert('fact (!)',         getTypeFromMessage('Hello!'),  '!');
    assert('fake (!-)',        getTypeFromMessage('Hello!-'), '!-');
    assert('question (??)',    getTypeFromMessage('Hello??'), '??');
    assert('unclear (?)',      getTypeFromMessage('Hello?'),  '?');
    assert('topic (>)',        getTypeFromMessage('Topic>'),  '>');
    assert('delete (--)',      getTypeFromMessage('x--'),     '--');
    assert('no suffix',        getTypeFromMessage('Hello'),   '');
    assert('empty string',     getTypeFromMessage(''),        '');
}
testGetTypeFromMessage();

function testMatchType() {
    suite('matchType');
    assert('change . to !',    matchType('Hello.', '!'),    'Hello!');
    assert('no suffix → add',  matchType('Hello', '!'),     'Hello!');
    assert('same type noop',   matchType('Hello!', '!'),    'Hello!');
    assert('change !- to !',   matchType('Hello!-', '!'),   'Hello!');
    assert('change ?? to ?',   matchType('Hello??', '?'),   'Hello?');
    assert('-- always append', matchType('Hello.', '--'),   'Hello.--');
    assert('change > to .',    matchType('Topic>', '.'),    'Topic.');
}
testMatchType();

function testGenerateNodeId() {
    suite('generateNodeId');
    const id1 = generateNodeId(), id2 = generateNodeId();
    assertMatch('alphanum string', id1, /^[a-z0-9]+$/i);
    assert('two calls differ',     id1 === id2, false);
}
testGenerateNodeId();

function testFormatTimestamp() {
    suite('formatTimestamp');
    assert('empty → empty',         formatTimestamp(''),                    '');
    assert('invalid → passthrough', formatTimestamp('bogus'),               'bogus');
    assert('past year → year only', formatTimestamp('2020-01-15 10:00:00'), '2020');
}
testFormatTimestamp();

function testEscapeHtml() {
    suite('escapeHtml');
    assert('< escaped',  escapeHtml('<'),           '&lt;');
    assert('> escaped',  escapeHtml('>'),           '&gt;');
    assert('& escaped',  escapeHtml('&'),           '&amp;');
    assert('" escaped',  escapeHtml('"'),           '&quot;');
    assert('mixed',      escapeHtml('<b>"hi"</b>'), '&lt;b&gt;&quot;hi&quot;&lt;/b&gt;');
    assert('plain text', escapeHtml('hello'),       'hello');
}
testEscapeHtml();

function testGetTypeDef() {
    suite('getTypeDef');
    const factDef = getTypeDef('Hello!');
    assert('fakt label',        factDef.label,                 'Fakt');
    assert('fakt cssClass',     factDef.cssClass,               'fakt');
    assert('suffix stored',     factDef.suffix,                 '!');
    assert('fake cssClass',     getTypeDef('Hello!-').cssClass, 'fake');
    assert('thema label',       getTypeDef('Topic>').label,     'Thema');
    assert('unknown → default', getTypeDef('no-suffix').label,  'Eintrag');
}
testGetTypeDef();

function testGetSignedCount() {
    suite('getSignedCount');
    rs();
    assert('no data → 0',        getSignedCount('/', 'n1'), 0);
    votesData['/n1'] = { votes: 3, signed: 2 };
    assert('reads signed field', getSignedCount('/', 'n1'), 2);
}
testGetSignedCount();

function testSanitiseForReport() {
    suite('sanitiseForReport');
    const _sid0 = sid, _tid0 = tenantId;
    sid = 'mysid123'; tenantId = 'mytid456';
    assert('replaces sid',       sanitiseForReport('sent by mysid123'),      'sent by [sid]');
    assert('replaces tenantId',  sanitiseForReport('tenant mytid456 here'),  'tenant [tid] here');
    assert('replaces both',      sanitiseForReport('mysid123 and mytid456'), '[sid] and [tid]');
    assert('no match unchanged', sanitiseForReport('nothing here'),          'nothing here');
    sid = _sid0; tenantId = _tid0;
}
testSanitiseForReport();

function testBuildStateSnapshot() {
    suite('buildStateSnapshot');
    rs(); selectedTopic = '/climate'; searchScope = 'here';
    const snap = buildStateSnapshot();
    assert('contains topic',  snap.includes('/climate'), true);
    assert('contains Filter', snap.includes('Filter:'),  true);
    assert('contains Suche',  snap.includes('Suche:'),   true);
    assert('contains Karten', snap.includes('Karten:'),  true);
}
testBuildStateSnapshot();

function testBuildReportText() {
    suite('buildReportText');
    rs();
    const rep = buildReportText(null);
    assert('has header',           rep.includes('=== Fehlerbericht ==='),   true);
    assert('has aktionen section', rep.includes('--- Letzte Aktionen ---'), true);
    assert('has zustand section',  rep.includes('--- Zustand ---'),         true);
    assert('no error section',     rep.includes('--- Fehlerdetails ---'),   false);
    const repCtx = buildReportText({ label: 'sendVote', status: 500 });
    assert('has Fehlerdetails',    repCtx.includes('--- Fehlerdetails ---'), true);
    assert('has error label',      repCtx.includes('sendVote'),             true);
}
testBuildReportText();

function testBuildFullReport() {
    suite('buildFullReport');
    document.getElementById('issue-user-msg').value = 'Something went wrong';
    document.getElementById('issue-details').value  = 'Error: 500';
    const full = buildFullReport();
    assert('contains user msg',    full.includes('Something went wrong'), true);
    assert('contains details',     full.includes('Error: 500'),           true);
    assert('has prefix label',     full.includes('Nutzerbeschreibung'),   true);
    document.getElementById('issue-user-msg').value = '';
    const noMsg = buildFullReport();
    assert('no prefix when empty', noMsg.includes('Nutzerbeschreibung'),  false);
    document.getElementById('issue-user-msg').value = '';
    document.getElementById('issue-details').value  = '';
}
testBuildFullReport();

function testLoadSaveSettings() {
    suite('loadSettings / saveSettings');
    localStorage.removeItem('fayf_settings');
    assert('missing key → {}', loadSettings(), {});
    saveSettings({ tenantId: 'demo', sid: 'abc' });
    assert('saved and loaded',  loadSettings(), { tenantId: 'demo', sid: 'abc' });
    saveSettings({ sid: 'xyz' });
    assert('patch merges',      loadSettings().tenantId, 'demo');
    assert('patch updates',     loadSettings().sid,      'xyz');
    localStorage.removeItem('fayf_settings');
}
testLoadSaveSettings();

function testBuildEntriesVotesUrl() {
    suite('buildEntriesUrl / buildVotesUrl');
    const _sid1 = sid, _tid1 = tenantId;
    sid = 'tsid'; tenantId = 'ttid';
    const eUrl = buildEntriesUrl();
    assert('entries has sid',    eUrl.includes('sid=tsid'),   true);
    assert('entries has tid',    eUrl.includes('tid=ttid'),   true);
    assert('entries has format', eUrl.includes('format=json'), true);
    assert('entries prefix',     eUrl.startsWith('entries?'), true);
    const eUrlX = buildEntriesUrl({ since: '2024-01-01 00:00:00' });
    assert('extra params added', eUrlX.includes('since='), true);
    const vUrl = buildVotesUrl();
    assert('votes has sid',    vUrl.includes('sid=tsid'),  true);
    assert('votes prefix',     vUrl.startsWith('votes?'),  true);
    sid = _sid1; tenantId = _tid1;
}
testBuildEntriesVotesUrl();

function testDebounceKey() {
    suite('debounceKey');
    Object.keys(_debounceMap).forEach(k => delete _debounceMap[k]);
    assert('first call → true',        debounceKey('dk_test', 1000), true);
    assert('immediate repeat → false', debounceKey('dk_test', 1000), false);
    assert('different key → true',     debounceKey('dk_other', 1000), true);
    delete _debounceMap['dk_test'];
    assert('after clear → true again', debounceKey('dk_test', 1000), true);
}
testDebounceKey();

function testPushAction() {
    suite('pushAction');
    actionTrail = [];
    pushAction('navigate', '/climate');
    assert('appended to trail', actionTrail.length,        1);
    assert('action stored',     actionTrail[0].action,     'navigate');
    assert('detail stored',     actionTrail[0].detail,     '/climate');
    assertMatch('ts is ISO',    actionTrail[0].ts, /^\d{4}-\d{2}-\d{2}T/);
    for (let i = 0; i < 15; i++) pushAction('t', String(i));
    assert('capped at max (10)',  actionTrail.length,                           ACTION_TRAIL_MAX);
    assert('keeps newest',        actionTrail[actionTrail.length - 1].detail,  '14');
    actionTrail = [];
}
testPushAction();

function testNavigateTo() {
    suite('navigateTo');
    rs();
    navigateTo('/climate');
    assert('selectedTopic updated', selectedTopic, '/climate');
    navigateTo('/climate/solutions');
    assert('deep navigation',       selectedTopic, '/climate/solutions');
    navigateTo('/');
    assert('back to root',          selectedTopic, '/');
}
testNavigateTo();

function testAddEntryWoCheck() {
    suite('addEntryWoCheck');
    rs();
    addEntryWoCheck('/', 'n1', 'Hello.', 3, '2024-01-01 00:00:00');
    assert('entry stored',          data['/']['n1'].message,   'Hello.');
    assert('votes stored',          data['/']['n1'].votes,     3);
    assert('timestamp stored',      data['/']['n1'].timestamp, '2024-01-01 00:00:00');
    addEntryWoCheck('/', 'n1', 'Hello.--', 0, '');
    assert('delete marker removes', data['/']['n1'],           undefined);
    addEntryWoCheck('', 'n2', 'Empty topic.', 0, '');
    assert('empty topic → /',       data['/']['n2'].message,   'Empty topic.');
}
testAddEntryWoCheck();

function testCheckData() {
    suite('checkData — stub creation');
    rs();
    addEntry('/climate/solutions', 'n1', 'Solar panels.', 0, '');
    assert('stub /climate created', !!data['/']['climate'],              true);
    assert('stub ends with >',      data['/']['climate'].message.endsWith('>'), true);
    assert('deep entry stored',     !!data['/climate/solutions']['n1'], true);
}
testCheckData();

function testInitializeTopicMap() {
    suite('initializeTopicMap');
    rs();
    addEntryWoCheck('/', 'climate', 'Climate Change>', 0, '');
    initializeTopicMap();
    assert('topic name registered', topicMap['/climate'], 'Climate Change');
    addEntryWoCheck('/', 'other', 'No suffix.', 0, '');
    initializeTopicMap();
    assert('non-topic ignored',     topicMap['/other'],   undefined);
}
testInitializeTopicMap();

function testAddData() {
    suite('addData — flat format');
    rs();
    addData({
        '/climate/n1': { message: 'Solar!', votes: 5, timestamp: '2024-06-01 12:00:00' },
        '/climate/n2': { message: 'Wind.',  votes: 2, timestamp: '2024-06-02 09:00:00' },
    });
    assert('entry n1 stored',         data['/climate']['n1'].message, 'Solar!');
    assert('votes on n1',             data['/climate']['n1'].votes,   5);
    assert('latestTimestamp updated', latestTimestamp,                '2024-06-02 09:00:00');
    assert('stub /climate created',   !!data['/']['climate'],         true);

    suite('addData — votes as object');
    rs();
    addData({ '/poll/q1': { message: 'Fair?', votes: { sid_abc: 1, others: 2 }, timestamp: '' } });
    assert('votes object summed', data['/poll']['q1'].votes, 3);

    suite('addData — empty is no-op');
    rs();
    addEntryWoCheck('/', 'x', 'stays.', 0, '');
    addData({});
    assert('existing entry survives', !!data['/']['x'], true);

    suite('addData — null is no-op');
    rs();
    addEntryWoCheck('/', 'y', 'stays too.', 0, '');
    addData(null);
    assert('null does not crash',     !!data['/']['y'], true);
}
testAddData();

function testAddVotesData() {
    suite('addVotesData');
    rs();
    addEntryWoCheck('/', 'n1', 'Hello!', 0, '');
    addVotesData({ '/n1': { votes: 7, attrs: { signed_count: 3 } } });
    assert('votes in votesData',   votesData['/n1'].votes,  7);
    assert('signed stored',        votesData['/n1'].signed, 3);
    assert('votes synced to data', data['/']['n1'].votes,   7);
}
testAddVotesData();

function testAddVoteByGui() {
    suite('addVoteByGui');
    rs();
    addEntryWoCheck('/', 'n1', 'Hello!', 2, '');
    addVoteByGui('/', 'n1', 1);
    assert('entry votes +1', data['/']['n1'].votes,  3);
    assert('votesData +1',   votesData['/n1'].votes, 3);
    addVoteByGui('/', 'n1', -1);
    assert('downvote -1',    data['/']['n1'].votes,  2);
}
testAddVoteByGui();

function testSetVoteByOthers() {
    suite('setVoteByOthers');
    rs();
    addEntryWoCheck('/', 'n1', 'Hello!', 5, '');
    setVoteByOthers('/', 'n1', 10);
    assert('sets votes absolutely', data['/']['n1'].votes,  10);
    assert('synced to votesData',   votesData['/n1'].votes, 10);
}
testSetVoteByOthers();

function testGetFilteredEntries() {
    suite('getFilteredEntries — scope=below at root');
    rs();
    addEntryWoCheck('/', 'r1', 'Root entry.', 0, '');
    addEntryWoCheck('/climate', 's1', 'Sub entry.', 0, '');
    let ents = getFilteredEntries();
    assert('root entry shown',   ents.some(e => e.nodeId === 'r1'), true);
    assert('sub entry excluded', ents.some(e => e.nodeId === 's1'), false);

    suite('getFilteredEntries — scope=global');
    rs(); searchScope = 'global'; selectedTopic = '/climate';
    addEntryWoCheck('/', 'r1', 'Root.', 0, '');
    addEntryWoCheck('/other', 'o1', 'Other.', 0, '');
    ents = getFilteredEntries();
    assert('all topics visible', ents.length >= 2, true);

    suite('getFilteredEntries — type filter');
    rs(); activeTypes = new Set(['!']);
    addEntryWoCheck('/', 'fact',    'True!',    0, '');
    addEntryWoCheck('/', 'opinion', 'Opinion.', 0, '');
    addEntryWoCheck('/', 'topic',   'SubT>',    0, '');
    ents = getFilteredEntries();
    assert('fact shown',             ents.some(e => e.nodeId === 'fact'),    true);
    assert('opinion filtered out',   ents.some(e => e.nodeId === 'opinion'), false);
    assert('topic (>) always shown', ents.some(e => e.nodeId === 'topic'),   true);

    suite('getFilteredEntries — sorted by votes desc');
    rs();
    addEntryWoCheck('/', 'lo', 'Low.',  1, '');
    addEntryWoCheck('/', 'hi', 'High!', 9, '');
    addEntryWoCheck('/', 'mi', 'Mid?',  5, '');
    ents = getFilteredEntries();
    assert('highest votes first', ents.map(e => e.nodeId), ['hi', 'mi', 'lo']);
}
testGetFilteredEntries();

function testRequireTopicFlag() {
    suite('REQUIRE_TOPIC_FOR_ENTRY flag');
    function wouldBlock(flag, topic) { return flag && topic === '/'; }
    assert('flag off → root ok',     wouldBlock(false, '/'),  false);
    assert('flag off → topic ok',    wouldBlock(false, '/x'), false);
    assert('flag on → root blocked', wouldBlock(true,  '/'),  true);
    assert('flag on → topic ok',     wouldBlock(true,  '/x'), false);
}
testRequireTopicFlag();

// ── GUI: bottom sheet edit mode ───────────────────────────────────────────────
function testOpenBottomSheetEditMode() {
    suite('openBottomSheet — edit mode');
    rs();
    addEntryWoCheck('/', 'n1', 'Solar!', 5, '');
    openBottomSheet(data['/']['n1']);
    assert('textarea pre-filled',      document.getElementById('bs-textarea').value,                'Solar');
    assert('active chip matches type', document.querySelector('.bs-type-chip.active').dataset.type, '!');
    assert('heading says Bearbeiten',  document.querySelector('#bottom-sheet h3').textContent,       'Eintrag bearbeiten');
    closeBottomSheet();
    assert('heading reset after close', document.querySelector('#bottom-sheet h3').textContent,     'Neuer Eintrag');
}
testOpenBottomSheetEditMode();

function testOpenBottomSheetNewMode() {
    suite('openBottomSheet — new mode');
    rs();
    openBottomSheet();
    assert('textarea empty',          document.getElementById('bs-textarea').value,                '');
    assert('heading says Neuer',      document.querySelector('#bottom-sheet h3').textContent,       'Neuer Eintrag');
    assert('default chip is Meinung', document.querySelector('.bs-type-chip.active').dataset.type, '.');
    closeBottomSheet();
}
testOpenBottomSheetNewMode();

function testBottomSheetSuffixStripping() {
    suite('openBottomSheet — suffix stripping');
    rs();
    addEntryWoCheck('/', 'n2', 'Some opinion.', 0, '');
    openBottomSheet(data['/']['n2']);
    assert('dot suffix stripped', document.getElementById('bs-textarea').value, 'Some opinion');
    closeBottomSheet();

    suite('openBottomSheet — fake !- stripped');
    rs();
    addEntryWoCheck('/', 'n3', 'Wrong claim!-', 0, '');
    openBottomSheet(data['/']['n3']);
    assert('text stripped',  document.getElementById('bs-textarea').value,                'Wrong claim');
    assert('chip is fake',   document.querySelector('.bs-type-chip.active').dataset.type, '!-');
    closeBottomSheet();
}
testBottomSheetSuffixStripping();

// ── Done ─────────────────────────────────────────────────────────────────────
harnessFinish();
```

- [ ] **Step 2: Verify no regressions**

Open `http://localhost:8080/wrapper.php?test=app2.html`. All suites must pass. Count: same number of asserts as before, plus the new edit-mode and suffix-stripping suites.

- [ ] **Step 3: Commit**

```bash
git add test/app2_test.js
git commit -m "refactor(app2_test): wrap all suites in named functions; add null addData and suffix-stripping tests"
```

---

### Task 5: Nav-back shows current topic + browser back button support

**Goal:**
1. Add a `popstate` handler so the browser's native back button correctly updates `selectedTopic` and re-renders
2. Add a `#nav-topic` span in the nav bar that shows the current topic path (empty at root)

**Files:**
- Modify: `app2.html`
- Modify: `test/app2_test.js`

**Interfaces:**
- Consumes: `selectedTopic`, `updateView`, `navigateTo`
- Produces: `#nav-topic` DOM element; popstate listener

- [ ] **Step 1: Add `#nav-topic` span to HTML nav**

Find the `<nav>` element (around line 204):
```html
<nav>
  <a id="nav-back" href="infopage.html"><i class="fas fa-arrow-left"></i> fayf.info</a>
  <div id="nav-search-bar">
```

Insert `<span id="nav-topic"></span>` between nav-back and the search bar:
```html
<nav>
  <a id="nav-back" href="infopage.html"><i class="fas fa-arrow-left"></i> fayf.info</a>
  <span id="nav-topic"></span>
  <div id="nav-search-bar">
```

- [ ] **Step 2: Add CSS for `#nav-topic`**

In `<style>`, after the `#nav-back` rule (line ~22), add:
```css
#nav-topic {
    flex: 1;
    text-align: center;
    font-size: 0.8rem;
    color: #777;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    padding: 0 0.5rem;
    pointer-events: none;
}
```

- [ ] **Step 3: Update `updateView()` to populate `#nav-topic`**

In `updateView()` (currently lines 809–825), after the nav-back update block (after `navBack.onclick = ...`), add:
```javascript
const navTopic = document.getElementById("nav-topic");
if (navTopic) navTopic.textContent = selectedTopic === "/" ? "" : selectedTopic;
```

The full updated `updateView` function body becomes:
```javascript
function updateView() {
    runtimeCheck("updateView");
    const navBack = document.getElementById("nav-back");
    if (selectedTopic === "/") {
        navBack.innerHTML = `<i class="fas fa-arrow-left"></i> fayf.info`;
        navBack.href = "infopage.html";
        navBack.onclick = null;
    } else {
        const parts = selectedTopic.split("/").filter(Boolean);
        const parentTopic = parts.length > 1 ? "/" + parts.slice(0, -1).join("/") : "/";
        navBack.innerHTML = `<i class="fas fa-arrow-left"></i> ${escapeHtml(topicMap[parentTopic] || "zurück")}`;
        navBack.href = "#";
        navBack.onclick = e => { e.preventDefault(); navigateTo(parentTopic); };
    }
    const navTopic = document.getElementById("nav-topic");
    if (navTopic) navTopic.textContent = selectedTopic === "/" ? "" : selectedTopic;
    renderCards(getFilteredEntries());
    runtimeCheck("updateView-end");
}
```

- [ ] **Step 4: Add `popstate` handler**

After the `visibilitychange` event listener block (around line 987–990), add:
```javascript
window.addEventListener("popstate", () => {
    const params = new URLSearchParams(window.location.search);
    const topic = params.get("topic") || "/";
    if (topic !== selectedTopic) {
        selectedTopic = topic;
        updateView();
    }
});
```

- [ ] **Step 5: Add test for nav-topic element**

In `test/app2_test.js`, add a new function before `harnessFinish()`:

```javascript
function testNavTopic() {
    suite('nav-topic — shows current topic path');
    rs();
    navigateTo('/climate');
    const navTopic = document.getElementById('nav-topic');
    assert('shows /climate',   navTopic ? navTopic.textContent : 'missing', '/climate');
    navigateTo('/climate/solutions');
    assert('shows deep path',  navTopic ? navTopic.textContent : 'missing', '/climate/solutions');
    navigateTo('/');
    assert('empty at root',    navTopic ? navTopic.textContent : 'missing', '');
}
testNavTopic();
```

- [ ] **Step 6: Verify**

1. Open `http://localhost:8080/app2.html?tid=demo`
2. Navigate into a topic → topic path appears centered in nav (e.g. `/climate`)
3. Press the browser's native back button → app returns to previous topic, nav-topic updates
4. Open `wrapper.php?test=app2.html` → `nav-topic` suite passes

- [ ] **Step 7: Commit**

```bash
git add app2.html test/app2_test.js
git commit -m "feat(app2): show current topic in nav; add popstate handler for browser back button"
```

---

### Task 6: Icons for entry type chips and badges + display mode toggle

**Goal:** Add Font Awesome icons to type chips (chip-bar and bottom-sheet) and to card type badges. Add a display mode toggle in Settings: **Text** / **Icon + Text** / **Nur Icon**. Mode is persisted in localStorage.

**Icons** (Font Awesome 6 solid):
| Type | Icon class |
|------|-----------|
| Meinung (`.`) | `fa-comment` |
| Fakt (`!`) | `fa-circle-check` |
| Fake (`!-`) | `fa-circle-xmark` |
| Unklar (`?`) | `fa-circle-question` |
| Gegenfrage (`??`) | `fa-right-left` |
| Thema (`>`) | `fa-folder-open` |

**Files:**
- Modify: `app2.html`
- Modify: `test/app2_test.js`

**Interfaces:**
- Consumes: `TYPE_DEFS`, `getTypeDef`, `buildCard`, `loadSettings`, `saveSettings`, `openSettings`, `applySettings`
- Produces: `typeDisplayMode` global (string); `TYPE_DEFS` gains `iconClass` field; `getTypeDef` returns `iconClass`; `updateTypeDisplay()` function

- [ ] **Step 1: Add `iconClass` to `TYPE_DEFS` and `TYPE_DEF_DEFAULT`**

Find `const TYPE_DEFS = {` (line ~604) and replace:
```javascript
const TYPE_DEFS = {
    "!-": { label: "Fake",       cssClass: "fake",       color: "#f44336", iconClass: "fa-circle-xmark"    },
    "!":  { label: "Fakt",       cssClass: "fakt",       color: "#4CAF50", iconClass: "fa-circle-check"    },
    "??": { label: "Gegenfrage", cssClass: "gegenfrage", color: "#2196F3", iconClass: "fa-right-left"      },
    "?":  { label: "Unklar",     cssClass: "unklar",     color: "#FF9800", iconClass: "fa-circle-question" },
    ".":  { label: "Meinung",    cssClass: "meinung",    color: "#888",    iconClass: "fa-comment"         },
    "@":  { label: "Quelle",     cssClass: "meinung",    color: "#888",    iconClass: "fa-link"            },
    ">":  { label: "Thema",      cssClass: "fakt",       color: "#4CAF50", iconClass: "fa-folder-open"     },
};
const TYPE_DEF_DEFAULT = { label: "Eintrag", cssClass: "meinung", color: "#888", iconClass: "fa-circle-dot" };
```

- [ ] **Step 2: Add `typeDisplayMode` global variable**

After `const REQUIRE_TOPIC_FOR_ENTRY = false;` add:
```javascript
let typeDisplayMode = "text"; // "text" | "icon+text" | "icon"
```

- [ ] **Step 3: Update chip-bar HTML to include icons and `<span>` for text**

Find the chip-bar HTML and replace:
```html
<div id="chip-bar">
  <button class="type-chip active" data-type="."><i class="fas fa-comment"></i><span>Meinung</span></button>
  <button class="type-chip active" data-type="!"><i class="fas fa-circle-check"></i><span>Fakt</span></button>
  <button class="type-chip active" data-type="!-"><i class="fas fa-circle-xmark"></i><span>Fake</span></button>
  <button class="type-chip active" data-type="?"><i class="fas fa-circle-question"></i><span>Unklar</span></button>
  <button class="type-chip active" data-type="??"><i class="fas fa-right-left"></i><span>Frage</span></button>
</div>
```

Add CSS for chip icon spacing (in `<style>`):
```css
.type-chip i, .bs-type-chip i { margin-right: 0.2rem; }
.type-chip span, .bs-type-chip span { display: inline; }
```

- [ ] **Step 4: Update bottom-sheet type chips similarly**

Find the `#bs-type-chips` div and replace:
```html
<div id="bs-type-chips">
  <button class="bs-type-chip active" data-type="."><i class="fas fa-comment"></i><span>Meinung</span></button>
  <button class="bs-type-chip" data-type="!"><i class="fas fa-circle-check"></i><span>Fakt</span></button>
  <button class="bs-type-chip" data-type="!-"><i class="fas fa-circle-xmark"></i><span>Fake</span></button>
  <button class="bs-type-chip" data-type="?"><i class="fas fa-circle-question"></i><span>Unklar</span></button>
  <button class="bs-type-chip" data-type="??"><i class="fas fa-right-left"></i><span>Gegenfrage</span></button>
</div>
```

- [ ] **Step 5: Update `buildCard` to render type badge using `typeDisplayMode`**

Find the badge rendering inside `buildCard` (around line 656):
```javascript
<span class="type-badge ${typeDef.cssClass}">${typeDef.label}</span>
```

Replace with:
```javascript
<span class="type-badge ${typeDef.cssClass}">${(() => {
    const icon = typeDef.iconClass ? `<i class="fas ${typeDef.iconClass}"></i>` : '';
    if (typeDisplayMode === 'icon') return icon;
    if (typeDisplayMode === 'icon+text') return icon + (icon ? ' ' : '') + typeDef.label;
    return typeDef.label;
})()}</span>
```

- [ ] **Step 6: Add `updateTypeDisplay()` function**

After `updateBsColor()`, add:
```javascript
function updateTypeDisplay() {
    const spans = document.querySelectorAll(".type-chip span, .bs-type-chip span");
    spans.forEach(s => { s.style.display = typeDisplayMode === "icon" ? "none" : ""; });
    updateView();
}
```

- [ ] **Step 7: Add type display select to settings panel HTML**

In the settings panel HTML (find `#settings-panel`), add before the button row:
```html
<label style="font-size:0.9rem;display:flex;align-items:center;gap:0.5rem">
  Typen-Anzeige:
  <select id="settings-type-display" style="padding:0.3rem;border-radius:4px;border:1px solid #ccc;">
    <option value="text">Text</option>
    <option value="icon+text">Icon + Text</option>
    <option value="icon">Nur Icon</option>
  </select>
</label>
```

- [ ] **Step 8: Wire settings load/save for `typeDisplayMode`**

In `openSettings()`, add:
```javascript
document.getElementById("settings-type-display").value = typeDisplayMode;
```

In `applySettings()`, after the existing saves, add:
```javascript
const newDisplayMode = document.getElementById("settings-type-display").value;
if (newDisplayMode !== typeDisplayMode) {
    typeDisplayMode = newDisplayMode;
    saveSettings({ typeDisplayMode: newDisplayMode });
    updateTypeDisplay();
}
```

In `init()`, after loading settings `s`, add:
```javascript
typeDisplayMode = s.typeDisplayMode || "text";
updateTypeDisplay();
```

- [ ] **Step 9: Add test for `getTypeDef` iconClass**

In `test/app2_test.js`, update `testGetTypeDef()` to include iconClass assertions:
```javascript
function testGetTypeDef() {
    suite('getTypeDef');
    const factDef = getTypeDef('Hello!');
    assert('fakt label',        factDef.label,                     'Fakt');
    assert('fakt cssClass',     factDef.cssClass,                   'fakt');
    assert('suffix stored',     factDef.suffix,                     '!');
    assert('fakt iconClass',    factDef.iconClass,                  'fa-circle-check');
    assert('fake cssClass',     getTypeDef('Hello!-').cssClass,     'fake');
    assert('fake iconClass',    getTypeDef('Hello!-').iconClass,    'fa-circle-xmark');
    assert('meinung iconClass', getTypeDef('Hi.').iconClass,        'fa-comment');
    assert('unklar iconClass',  getTypeDef('Hi?').iconClass,        'fa-circle-question');
    assert('gegenfrage icon',   getTypeDef('Hi??').iconClass,       'fa-right-left');
    assert('thema iconClass',   getTypeDef('Topic>').iconClass,     'fa-folder-open');
    assert('thema label',       getTypeDef('Topic>').label,         'Thema');
    assert('unknown → default', getTypeDef('no-suffix').label,      'Eintrag');
}
```

- [ ] **Step 10: Verify**

1. Open `http://localhost:8080/app2.html?tid=demo`
2. Chip-bar shows icons alongside text labels (default: "text" mode, icons are present in DOM)
3. Open Settings → change to "Icon + Text" → Apply → cards show icon + label in type badge; chips show icon + text
4. Change to "Nur Icon" → chips show only icon, badges show only icon
5. Reload → mode is restored from localStorage
6. `wrapper.php?test=app2.html` → `getTypeDef` suite all PASS

- [ ] **Step 11: Commit**

```bash
git add app2.html test/app2_test.js
git commit -m "feat(app2): add FA icons to type chips and badges; add text/icon+text/icon display toggle"
```

---

### Task 7: Scope chips — German labels, reorder (Global→Hier→Darunter), icons

**Goal:**
- Reorder: Global → Hier → Darunter (broadest to narrowest, matches mental model)
- German labels: "Below" → "Darunter", "Here" → "Hier", "Global" stays "Global"
- Icons: `fa-globe` / `fa-crosshairs` / `fa-layer-group`
- `data-scope` values are unchanged (`"global"`, `"here"`, `"below"`) — only display changes

**Files:**
- Modify: `app2.html`
- Modify: `test/app2_test.js`

**Interfaces:**
- Consumes: `searchScope`, `updateTypeDisplay()` (handles span hide/show for icon mode)
- Produces: reordered scope chips in DOM; `updateTypeDisplay()` updated to cover `.scope-chip span`

- [ ] **Step 1: Replace scope chip HTML**

Find the existing scope chips inside `#nav-search-bar`:
```html
<button class="scope-chip active" data-scope="below">Below</button>
<button class="scope-chip" data-scope="here">Here</button>
<button class="scope-chip" data-scope="global">Global</button>
```

Replace with (new order: Global → Hier → Darunter; icons + spans):
```html
<button class="scope-chip" data-scope="global"><i class="fas fa-globe"></i><span>Global</span></button>
<button class="scope-chip" data-scope="here"><i class="fas fa-crosshairs"></i><span>Hier</span></button>
<button class="scope-chip active" data-scope="below"><i class="fas fa-layer-group"></i><span>Darunter</span></button>
```

Note: `data-scope="below"` keeps `active` class because `searchScope` initialises as `'below'`.

- [ ] **Step 2: Add scope chip icon spacing to CSS**

In `<style>`, after the scope chip styles, add:
```css
.scope-chip i { margin-right: 0.2rem; }
.scope-chip span { display: inline; }
```

- [ ] **Step 3: Update `updateTypeDisplay()` to include `.scope-chip span`**

Change the selector inside `updateTypeDisplay()` from T6:
```javascript
function updateTypeDisplay() {
    document.querySelectorAll(".type-chip span, .bs-type-chip span, .scope-chip span").forEach(s => {
        s.style.display = typeDisplayMode === "icon" ? "none" : "";
    });
    updateView();
}
```

- [ ] **Step 4: Add test for scope chip order and labels**

In `test/app2_test.js`, add before `harnessFinish()`:

```javascript
function testScopeChips() {
    suite('scope chips — reordered German labels');
    const chips = Array.from(document.querySelectorAll('.scope-chip'));
    const scopes = chips.map(c => c.dataset.scope);
    assert('global is first',    scopes[0], 'global');
    assert('here is second',     scopes[1], 'here');
    assert('below is third',     scopes[2], 'below');
    const labels = chips.map(c => {
        const span = c.querySelector('span');
        return span ? span.textContent.trim() : c.textContent.trim();
    });
    assert('global label',       labels[0], 'Global');
    assert('here label is Hier', labels[1], 'Hier');
    assert('below is Darunter',  labels[2], 'Darunter');
}
testScopeChips();
```

- [ ] **Step 5: Verify scope filtering still works**

1. Open `http://localhost:8080/app2.html?tid=demo` with some data
2. Chips show: `🌐 Global` · `⊕ Hier` · `▦ Darunter` (left to right)
3. "Darunter" is active by default
4. Click "Global" → all entries across all topics appear in search
5. Click "Hier" → only current topic's direct entries in search
6. `wrapper.php?test=app2.html` → `scope chips` suite PASS

- [ ] **Step 6: Commit**

```bash
git add app2.html test/app2_test.js
git commit -m "feat(app2): reorder scope chips Global→Hier→Darunter; add German labels and icons"
```
