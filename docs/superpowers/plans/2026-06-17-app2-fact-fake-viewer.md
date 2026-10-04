# app2.html — Hierarchical Fact/Fake Viewer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `app2.html` — a card-based hierarchical viewer for facts, fakes, opinions and counter-questions, backed by the existing PHP/CSV API.

**Architecture:** Single self-contained HTML file (no build step, no framework). JS data layer copied verbatim from `app.html`; new card renderer, navigation, long-poll, search, signing, and add-entry sheet written fresh. All data goes through the existing `entries.php` and `votes.php` endpoints unchanged.

**Tech Stack:** Vanilla HTML/CSS/JS, PHP backend (entries.php, votes.php), CSV storage, Font Awesome 6 CDN.

## Global Constraints

- CP1: Plain procedural JS — no classes, no framework, no bundler
- CP2: Single self-contained HTML file (`app2.html`) — all CSS and JS inline
- No backend PHP changes
- Font Awesome 6 via CDN: `https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css`
- QRCode.js via CDN: `https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js`
- `localStorage` key for settings: `fayf_settings` (same as app.html)
- API base URLs: `entry/get`, `entry/add`, `vote/get`, `vote/add` (relative)
- Entry format: `/topic/nodeId | message<suffix>` — suffix chars: `.` `!` `!-` `?` `??` `@` `>`
- Vote POST format: `entry=/path | nodeId | message | votes:sid:N`
- Sign POST format: `entry=/path | nodeId | message | signed:sid:1`
- `sid` param: read from localStorage settings or default `"www"`
- `tid` param: read from URL `?tid=` or `?tenantId=`, fallback to localStorage

---

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `app2.html` | **Create** | Entire new app — HTML skeleton, all CSS, all JS |
| `justfile` | **Modify** | Add app2 link to `serve` recipe output |

No other files touched.

---

### Task 1: HTML skeleton + CSS foundation

**Files:**
- Create: `app2.html`

**Interfaces:**
- Produces: DOM structure that all later tasks wire up — `#card-list`, `#chip-bar`, `#fab`, `#bottom-sheet`, `#search-bar`, `#nav-back`, `#toast-container`, `#settings-overlay`

- [ ] **Step 1: Create app2.html with full HTML skeleton**

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>fayf — Fact Farming</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <style>
    /* ── Reset + base ───────────────────────────────── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; font-family: Arial, sans-serif; color: #333; background: #f5f5f5; }
    body { display: flex; flex-direction: column; }
    a { text-decoration: none; color: inherit; }

    /* ── Nav ────────────────────────────────────────── */
    nav {
      display: flex; align-items: center; gap: 0.5rem;
      background: #333; color: #fff;
      padding: 0.5rem 1rem; position: sticky; top: 0; z-index: 100;
    }
    nav a { color: #fff; }
    #nav-back { margin-right: auto; font-size: 0.9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 50vw; }
    #nav-search-icon { cursor: pointer; padding: 0.3rem; }
    #nav-search-bar { display: none; flex: 1; }
    #nav-search-bar.open { display: flex; align-items: center; gap: 0.4rem; }
    #search-input { flex: 1; padding: 0.3rem 0.6rem; border-radius: 4px; border: none; font-size: 0.9rem; }
    .scope-chip {
      padding: 0.2rem 0.6rem; border-radius: 12px; font-size: 0.75rem; cursor: pointer;
      background: #555; color: #fff; border: none;
    }
    .scope-chip.active { background: #4CAF50; }
    #nav-settings-icon { cursor: pointer; padding: 0.3rem; }

    /* ── Chip filter bar ────────────────────────────── */
    #chip-bar {
      display: flex; flex-wrap: wrap; gap: 0.4rem;
      padding: 0.5rem 1rem; background: #fff;
      border-bottom: 1px solid #e0e0e0; position: sticky; top: 3rem; z-index: 90;
    }
    .type-chip {
      padding: 0.25rem 0.75rem; border-radius: 14px; font-size: 0.8rem;
      border: 2px solid transparent; cursor: pointer; background: #eee; color: #555;
    }
    .type-chip.active { color: #fff; border-color: transparent; }
    .type-chip[data-type="."].active   { background: #888; }
    .type-chip[data-type="!"].active   { background: #4CAF50; }
    .type-chip[data-type="!-"].active  { background: #f44336; }
    .type-chip[data-type="?"].active   { background: #FF9800; }
    .type-chip[data-type="??"].active  { background: #2196F3; }

    /* ── Card list ──────────────────────────────────── */
    #card-list { flex: 1; padding: 0.75rem 1rem; display: flex; flex-direction: column; gap: 0.6rem; max-width: 800px; width: 100%; margin: 0 auto; }

    /* ── Card ───────────────────────────────────────── */
    .card {
      background: #fff; border-radius: 8px;
      border: 1px solid #e0e0e0; padding: 0.75rem 1rem;
      cursor: pointer; transition: box-shadow 0.15s;
    }
    .card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.12); }
    .card-header { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem; }
    .type-badge {
      padding: 0.15rem 0.55rem; border-radius: 4px; font-size: 0.72rem;
      font-weight: bold; color: #fff; white-space: nowrap; flex-shrink: 0;
    }
    .type-badge.meinung   { background: #888; }
    .type-badge.fakt      { background: #4CAF50; }
    .type-badge.bewiesen  { background: #2e7d32; }
    .type-badge.fake      { background: #f44336; }
    .type-badge.widerlegung { background: #b71c1c; }
    .type-badge.unklar    { background: #FF9800; }
    .type-badge.gegenfrage { background: #2196F3; }
    .card-text { font-size: 0.95rem; line-height: 1.4; flex: 1; }
    .card-footer { display: flex; align-items: center; gap: 0.75rem; margin-top: 0.6rem; }
    .vote-score { font-weight: bold; font-size: 0.9rem; min-width: 2rem; text-align: center; }
    .vote-score.pos { color: #4CAF50; }
    .vote-score.neg { color: #f44336; }
    .vote-score.zero { color: #aaa; }
    .vote-btn {
      background: none; border: 1px solid #ccc; border-radius: 4px;
      padding: 0.15rem 0.5rem; cursor: pointer; font-size: 0.8rem; color: #666;
    }
    .vote-btn:hover { border-color: #4CAF50; color: #4CAF50; }
    .sign-count { font-size: 0.8rem; color: #888; margin-left: auto; }
    .sign-count.verified { color: #2e7d32; font-weight: bold; }
    .sign-btn {
      background: none; border: 1px solid #ccc; border-radius: 4px;
      padding: 0.15rem 0.5rem; cursor: pointer; font-size: 0.75rem; color: #666;
    }
    .sign-btn:hover { border-color: #2e7d32; color: #2e7d32; }
    .drill-arrow { color: #ccc; font-size: 0.8rem; margin-left: 0.25rem; }
    .ts-label { font-size: 0.75rem; color: #bbb; }

    /* ── FAB ────────────────────────────────────────── */
    #fab {
      position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 200;
      width: 3.2rem; height: 3.2rem; border-radius: 50%;
      background: #4CAF50; color: #fff; border: none;
      font-size: 1.5rem; cursor: pointer; box-shadow: 0 3px 8px rgba(0,0,0,0.3);
      display: flex; align-items: center; justify-content: center;
    }
    #fab:hover { background: #45a049; }

    /* ── Bottom sheet (add entry) ───────────────────── */
    #bottom-sheet-overlay {
      display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 300;
    }
    #bottom-sheet-overlay.open { display: flex; align-items: flex-end; }
    #bottom-sheet {
      background: #fff; width: 100%; border-radius: 12px 12px 0 0;
      padding: 1.25rem 1rem 2rem; display: flex; flex-direction: column; gap: 0.75rem;
    }
    #bottom-sheet h3 { font-size: 1rem; color: #333; }
    #bs-type-chips { display: flex; flex-wrap: wrap; gap: 0.4rem; }
    .bs-type-chip {
      padding: 0.3rem 0.8rem; border-radius: 14px; font-size: 0.82rem;
      border: 2px solid #ccc; cursor: pointer; background: #fff; color: #555;
    }
    .bs-type-chip.active { color: #fff; border-color: transparent; }
    .bs-type-chip[data-type="."].active  { background: #888; }
    .bs-type-chip[data-type="!"].active  { background: #4CAF50; }
    .bs-type-chip[data-type="!-"].active { background: #f44336; }
    .bs-type-chip[data-type="?"].active  { background: #FF9800; }
    .bs-type-chip[data-type="??"].active { background: #2196F3; }
    #bs-textarea {
      width: 100%; min-height: 5rem; padding: 0.6rem;
      border: 1px solid #ccc; border-radius: 6px; font-size: 1rem;
      resize: vertical; font-family: inherit;
    }
    #bs-submit {
      padding: 0.7rem; background: #4CAF50; color: #fff;
      border: none; border-radius: 6px; font-size: 1rem; cursor: pointer;
    }
    #bs-submit:hover { background: #45a049; }
    #bs-cancel {
      padding: 0.5rem; background: none; border: none;
      color: #888; font-size: 0.9rem; cursor: pointer; text-align: center;
    }

    /* ── Toast ──────────────────────────────────────── */
    #toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 500; }

    /* ── Settings overlay (reuse from app.html pattern) */
    #settings-overlay {
      display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 400;
      align-items: center; justify-content: center;
    }
    #settings-overlay.open { display: flex; }
    #settings-panel {
      background: #fff; border-radius: 8px; padding: 1.5rem;
      width: min(400px, 90vw); display: flex; flex-direction: column; gap: 1rem;
    }
    #settings-panel h2 { font-size: 1.1rem; }
    #settings-tenant { width: 100%; padding: 0.4rem; border: 1px solid #ccc; border-radius: 4px; }
    .settings-btn {
      padding: 0.5rem 1rem; border-radius: 4px; border: none; cursor: pointer; font-size: 0.9rem;
    }
    .settings-btn.primary { background: #4CAF50; color: #fff; }
    .settings-btn.secondary { background: #eee; color: #333; }

    /* ── Swipe feedback ─────────────────────────────── */
    .card.swiping-right { border-left: 4px solid #4CAF50; }
    .card.swiping-left  { border-left: 4px solid #f44336; }

    /* ── Responsive ─────────────────────────────────── */
    @media (max-width: 600px) {
      nav { padding: 0.4rem 0.6rem; }
      #card-list { padding: 0.5rem; }
    }
  </style>
</head>
<body>

<nav>
  <a id="nav-back" href="infopage.html"><i class="fas fa-arrow-left"></i> fayf.info</a>
  <div id="nav-search-bar">
    <input id="search-input" type="text" placeholder="Suchen…">
    <button class="scope-chip active" data-scope="below">Below</button>
    <button class="scope-chip" data-scope="here">Here</button>
    <button class="scope-chip" data-scope="global">Global</button>
  </div>
  <i class="fas fa-search" id="nav-search-icon"></i>
  <i class="fas fa-cog" id="nav-settings-icon"></i>
</nav>

<div id="chip-bar">
  <button class="type-chip active" data-type=".">Meinung</button>
  <button class="type-chip active" data-type="!">Fakt</button>
  <button class="type-chip active" data-type="!-">Fake</button>
  <button class="type-chip active" data-type="?">Unklar</button>
  <button class="type-chip active" data-type="??">Frage</button>
</div>

<div id="card-list"></div>

<button id="fab"><i class="fas fa-plus"></i></button>

<div id="bottom-sheet-overlay">
  <div id="bottom-sheet">
    <h3>Neuer Eintrag</h3>
    <div id="bs-type-chips">
      <button class="bs-type-chip active" data-type=".">Meinung</button>
      <button class="bs-type-chip" data-type="!">Fakt</button>
      <button class="bs-type-chip" data-type="!-">Fake</button>
      <button class="bs-type-chip" data-type="?">Unklar</button>
      <button class="bs-type-chip" data-type="??">Gegenfrage</button>
    </div>
    <textarea id="bs-textarea" placeholder="Dein Beitrag…"></textarea>
    <button id="bs-submit">Hinzufügen</button>
    <button id="bs-cancel">Abbrechen</button>
  </div>
</div>

<div id="settings-overlay">
  <div id="settings-panel">
    <h2>Einstellungen</h2>
    <label>Tenant ID
      <input id="settings-tenant" type="text" placeholder="demo">
    </label>
    <div style="display:flex;gap:0.5rem">
      <button class="settings-btn primary" id="settings-apply">Übernehmen</button>
      <button class="settings-btn secondary" id="settings-close">Schließen</button>
    </div>
  </div>
</div>

<div id="toast-container"></div>

<script>
// ── All JS goes here (Tasks 2-10) ──
</script>
</body>
</html>
```

- [ ] **Step 2: Open in browser and verify structure**

```bash
cd /home/martin/play/infopedia_php && php -S localhost:8080 -t . &
# open http://localhost:8080/app2.html
# expect: dark nav bar, chip row, empty card list, green FAB
kill %1
```

- [ ] **Step 3: Commit**

```bash
git add app2.html
git commit -m "feat(app2) html skeleton and css foundation"
```

---

### Task 2: Core JS data layer

**Files:**
- Modify: `app2.html` (inside `<script>`)

**Interfaces:**
- Consumes: DOM from Task 1
- Produces:
  - `let data = {}` — `{ "/topic": { "nodeId": { topic, nodeId, message, votes, timestamp } } }`
  - `let votesData = {}` — `{ "/topic/nodeId": { votes: N, signed: N } }`
  - `addEntryWoCheck(topic, nodeId, message, votes, timestamp)`
  - `addEntry(topic, nodeId, message, votes, timestamp)`
  - `addVoteByGui(topic, nodeId, deltaVotes)`
  - `setVoteByOthers(topic, nodeId, votes)`
  - `addData(dataLoaded)` — merges JSON response into `data`
  - `addVotesData(dataLoaded)` — merges JSON response into `votesData`
  - `fullKey(topic, nodeId)` → `"/topic/nodeId"`
  - `splitKey(key)` → `["/topic", "nodeId"]`
  - `getTypeFromMessage(message)` → suffix string e.g. `"!"`, `"!-"`, `"??"`
  - `matchType(message, type)` → message with correct suffix
  - `generateNodeId()` → unique string
  - `formatTimestamp(ts)` → display string
  - `showToast(message, type, duration)`
  - `runtimeCheck(msg)`

- [ ] **Step 1: Add state variables and copied utility functions inside `<script>`**

Replace `// ── All JS goes here (Tasks 2-10) ──` with:

```javascript
// ── State ────────────────────────────────────────────────────────────────────
let tenantId = "";
let sid = "www";
let data = {};          // { "/topic": { "nodeId": { topic, nodeId, message, votes, timestamp } } }
let votesData = {};     // { "/topic/nodeId": { votes: N, signed: N } }
let topicMap = {};      // { "/topic": "DisplayName" }
let selectedTopic = "/";
let latestTimestamp = null;
let searchScope = "below";   // "here" | "below" | "global"
let activeTypes = new Set([".", "!", "!-", "?", "??"]); // visible types

// ── Debug ─────────────────────────────────────────────────────────────────────
function runtimeCheck(msg = "") {
    // hook point — intentionally empty; call sites kept for future instrumentation
}

// ── Key helpers ───────────────────────────────────────────────────────────────
function fullKey(topic, nodeId) {
    return topic + ("/" === topic ? "" : "/") + nodeId;
}
function splitKey(key) {
    const lastSlash = key.lastIndexOf("/");
    if (lastSlash <= 0) return ["/", key.replace(/^\//, "")];
    return [key.substring(0, lastSlash), key.substring(lastSlash + 1)];
}

// ── Entry helpers ─────────────────────────────────────────────────────────────
function generateNodeId() {
    return Date.now().toString(36).substring(2) + Math.random().toString(36).substring(2, 8);
}
function getTypeFromMessage(message) {
    for (const t of [">", "!-", "??", "!", "?", ".", "@", "--"]) {
        if (message && message.endsWith(t)) return t;
    }
    return "";
}
function matchType(message, type) {
    const current = getTypeFromMessage(message);
    if (current === type) return message;
    if (!current) return message + type;
    if (type === "--") return message + type;
    return message.slice(0, -current.length).trim() + type;
}
function formatTimestamp(ts) {
    if (!ts) return "";
    const d = new Date(ts.replace(" ", "T"));
    if (isNaN(d)) return ts;
    const now = new Date();
    if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString("de-DE", { hour: "2-digit", minute: "2-digit" });
    if (d.getFullYear() === now.getFullYear()) return d.toLocaleDateString("de-DE", { day: "numeric", month: "short" });
    return d.getFullYear().toString();
}

// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(message, type = "info", duration = 3000) {
    const container = document.getElementById("toast-container");
    const el = document.createElement("div");
    el.textContent = message;
    el.style.cssText = `
        background:${type === "success" ? "#4CAF50" : type === "error" ? "#f44336" : "#333"};
        color:#fff;padding:0.75rem 1rem;margin-bottom:0.4rem;border-radius:6px;
        box-shadow:0 2px 6px rgba(0,0,0,0.25);font-size:0.9rem;
    `;
    container.appendChild(el);
    setTimeout(() => el.remove(), duration);
}

// ── Data write ────────────────────────────────────────────────────────────────
function addEntryWoCheck(topic, nodeId, message, votes, timestamp) {
    runtimeCheck("addEntryWoCheck");
    if (!topic || topic === "") topic = "/";
    if (!data[topic]) data[topic] = {};
    if (message && message.endsWith("--")) {
        delete data[topic][nodeId];
    } else {
        data[topic][nodeId] = { topic, nodeId, timestamp: timestamp || "", message, votes: votes || 0 };
    }
    runtimeCheck("addEntryWoCheck-end");
}
function addEntry(topic, nodeId, message, votes, timestamp) {
    runtimeCheck("addEntry");
    addEntryWoCheck(topic, nodeId, message, votes, timestamp);
    checkData(topic, nodeId, message);
    runtimeCheck("addEntry-end");
}
function addVoteByGui(topic, nodeId, deltaVotes) {
    runtimeCheck("addVoteByGui");
    if (data[topic] && data[topic][nodeId]) {
        data[topic][nodeId].votes = (data[topic][nodeId].votes || 0) + deltaVotes;
    }
    const key = fullKey(topic, nodeId);
    if (!votesData[key]) votesData[key] = { votes: 0, signed: 0 };
    votesData[key].votes = (votesData[key].votes || 0) + deltaVotes;
    runtimeCheck("addVoteByGui-end");
}
function setVoteByOthers(topic, nodeId, votes) {
    runtimeCheck("setVoteByOthers");
    const key = fullKey(topic, nodeId);
    if (!votesData[key]) votesData[key] = { votes: 0, signed: 0 };
    votesData[key].votes = votes;
    if (data[topic] && data[topic][nodeId]) data[topic][nodeId].votes = votes;
    runtimeCheck("setVoteByOthers-end");
}

// ── checkData: ensure topic map consistency ───────────────────────────────────
function checkData(topic, nodeId, message) {
    runtimeCheck("checkData");
    // ensure parent topics exist
    if (topic && topic !== "/") {
        const parts = topic.split("/").filter(Boolean);
        let path = "";
        for (const part of parts) {
            const parentPath = path || "/";
            if (!data[parentPath]) data[parentPath] = {};
            path += "/" + part;
            const found = Object.values(data[parentPath] || {}).some(e => fullKey(e.topic, e.nodeId) === path || e.message.replace(/[.!?@>-]+$/, "") === part);
            if (!found) {
                // create stub topic entry
                const stubId = part;
                data[parentPath][stubId] = { topic: parentPath, nodeId: stubId, message: part + ">", votes: 0, timestamp: "" };
            }
        }
    }
    runtimeCheck("checkData-end");
}

// ── Topic map ─────────────────────────────────────────────────────────────────
function initializeTopicMap() {
    runtimeCheck("initializeTopicMap");
    topicMap = {};
    Object.entries(data).forEach(([topic, nodes]) => {
        Object.values(nodes).forEach(entry => {
            if (entry.message && entry.message.endsWith(">")) {
                const childTopic = fullKey(entry.topic, entry.nodeId);
                topicMap[childTopic] = entry.message.replace(/>$/, "").trim();
            }
        });
    });
    runtimeCheck("initializeTopicMap-end");
}

// ── Merge loaded data ─────────────────────────────────────────────────────────
function addData(dataLoaded) {
    runtimeCheck("addData");
    if (!dataLoaded || Object.keys(dataLoaded).length === 0) return;
    Object.entries(dataLoaded).forEach(([topic, nodes]) => {
        Object.entries(nodes).forEach(([nodeId, entry]) => {
            addEntryWoCheck(topic, nodeId, entry.message, entry.votes, entry.timestamp);
            if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(entry.timestamp)) {
                if (!latestTimestamp || entry.timestamp > latestTimestamp) latestTimestamp = entry.timestamp;
            }
        });
    });
    checkData();
    initializeTopicMap();
    runtimeCheck("addData-end");
}
function addVotesData(dataLoaded) {
    runtimeCheck("addVotesData");
    if (!dataLoaded) return;
    Object.entries(dataLoaded).forEach(([key, entry]) => {
        const v = parseInt(entry.votes || 0, 10);
        const s = parseInt(entry.signed || 0, 10);
        if (!votesData[key]) votesData[key] = { votes: 0, signed: 0 };
        votesData[key].votes = v;
        votesData[key].signed = s;
        const [topic, nodeId] = splitKey(key);
        if (data[topic] && data[topic][nodeId]) data[topic][nodeId].votes = v;
    });
    runtimeCheck("addVotesData-end");
}

// ── Settings ──────────────────────────────────────────────────────────────────
function loadSettings() {
    try { return JSON.parse(localStorage.getItem("fayf_settings") || "{}"); } catch { return {}; }
}
function saveSettings(patch) {
    const s = loadSettings();
    localStorage.setItem("fayf_settings", JSON.stringify({ ...s, ...patch }));
}
```

- [ ] **Step 2: Verify no JS errors on page load**

```bash
cd /home/martin/play/infopedia_php && php -S localhost:8080 -t . &
# open http://localhost:8080/app2.html
# open browser DevTools console — expect: no errors
kill %1
```

- [ ] **Step 3: Commit**

```bash
git add app2.html
git commit -m "feat(app2) core JS data layer"
```

---

### Task 3: Type system + card rendering

**Files:**
- Modify: `app2.html`

**Interfaces:**
- Consumes: `data`, `votesData`, `selectedTopic`, `activeTypes`, `fullKey()`, `getTypeFromMessage()`, `formatTimestamp()`
- Produces:
  - `TYPE_DEFS` — map from suffix to `{label, cssClass, color}`
  - `getTypeDef(message)` → `{label, cssClass, color, suffix}`
  - `getSignedCount(topic, nodeId)` → integer
  - `buildCard(entry)` → `HTMLDivElement`
  - `renderCards(entries)` — renders with IntersectionObserver batch of 80

- [ ] **Step 1: Add type definitions and card builder after the settings functions**

```javascript
// ── Type system ───────────────────────────────────────────────────────────────
const TYPE_DEFS = {
    "!-": { label: "Fake",       cssClass: "fake",       color: "#f44336" },
    "!":  { label: "Fakt",       cssClass: "fakt",       color: "#4CAF50" },
    "??": { label: "Gegenfrage", cssClass: "gegenfrage", color: "#2196F3" },
    "?":  { label: "Unklar",     cssClass: "unklar",     color: "#FF9800" },
    ".":  { label: "Meinung",    cssClass: "meinung",    color: "#888"    },
    "@":  { label: "Quelle",     cssClass: "meinung",    color: "#888"    },
    ">":  { label: "Thema",      cssClass: "fakt",       color: "#4CAF50" },
};
const TYPE_DEF_DEFAULT = { label: "Eintrag", cssClass: "meinung", color: "#888" };

function getTypeDef(message) {
    const suffix = getTypeFromMessage(message);
    const base = TYPE_DEFS[suffix] || TYPE_DEF_DEFAULT;
    // check verification
    if (suffix === "!" || suffix === "!-") {
        // caller passes entry so we can check signed count via closure
    }
    return { ...base, suffix };
}

function getSignedCount(topic, nodeId) {
    const key = fullKey(topic, nodeId);
    return (votesData[key] && votesData[key].signed) || 0;
}

function buildCard(entry) {
    const { topic, nodeId, message, votes, timestamp } = entry;
    const suffix = getTypeFromMessage(message);
    let typeDef = TYPE_DEFS[suffix] || TYPE_DEF_DEFAULT;
    const signed = getSignedCount(topic, nodeId);
    const verified = signed >= 2 && (suffix === "!" || suffix === "!-");
    if (verified) {
        typeDef = suffix === "!"
            ? { label: "Bewiesen ✓", cssClass: "bewiesen", color: "#2e7d32" }
            : { label: "Widerlegung ✓", cssClass: "widerlegung", color: "#b71c1c" };
    }

    const cleanMsg = message.replace(/[.!?@>\-]+$/, "").trim() || message;
    const voteCount = votes || 0;
    const scoreClass = voteCount > 0 ? "pos" : voteCount < 0 ? "neg" : "zero";
    const scoreStr = voteCount > 0 ? "+" + voteCount : String(voteCount);
    const key = fullKey(topic, nodeId);
    const hasDrillIn = suffix === ">" || (data[key] && Object.keys(data[key]).length > 0);

    const card = document.createElement("div");
    card.className = "card";
    card.dataset.topic = topic;
    card.dataset.nodeId = nodeId;
    card.dataset.fullKey = key;
    card.innerHTML = `
        <div class="card-header">
            <span class="type-badge ${typeDef.cssClass}">${typeDef.label}</span>
            <span class="card-text">${cleanMsg}</span>
            ${hasDrillIn ? '<i class="fas fa-chevron-right drill-arrow"></i>' : ""}
        </div>
        <div class="card-footer">
            <button class="vote-btn upvote-btn" data-key="${key}">▲</button>
            <span class="vote-score ${scoreClass}">${scoreStr}</span>
            <button class="vote-btn downvote-btn" data-key="${key}">▼</button>
            <span class="sign-count ${signed >= 2 ? "verified" : ""}">${signed > 0 ? "✓ " + signed : ""}</span>
            <button class="sign-btn" data-key="${key}">Bestätigen</button>
            <span class="ts-label">${formatTimestamp(timestamp)}</span>
        </div>
    `;
    return card;
}

// ── Render cards with IntersectionObserver batching ───────────────────────────
function renderCards(entries) {
    runtimeCheck("renderCards");
    const list = document.getElementById("card-list");
    list.innerHTML = "";
    if (!entries || entries.length === 0) {
        list.innerHTML = `<p style="color:#999;text-align:center;padding:2rem">Keine Einträge. Füge den ersten hinzu!</p>`;
        return;
    }
    const BATCH = 80;
    let rendered = 0;
    let observer;

    function renderBatch() {
        const sentinel = document.getElementById("card-sentinel");
        if (sentinel) sentinel.remove();
        const end = Math.min(rendered + BATCH, entries.length);
        for (let i = rendered; i < end; i++) {
            list.appendChild(buildCard(entries[i]));
        }
        rendered = end;
        if (rendered < entries.length) {
            const s = document.createElement("div");
            s.id = "card-sentinel";
            s.style.height = "1px";
            list.appendChild(s);
            observer.observe(s);
        }
        addSwipeHandlersToAllCards();
    }

    observer = new IntersectionObserver(entries => {
        if (entries[0].isIntersecting) { observer.disconnect(); renderBatch(); }
    }, { rootMargin: "200px" });

    renderBatch();
    runtimeCheck("renderCards-end");
}
```

- [ ] **Step 2: Add `getFilteredEntries()` and `updateView()`**

```javascript
// ── Filtering ─────────────────────────────────────────────────────────────────
function getFilteredEntries() {
    runtimeCheck("getFilteredEntries");
    const searchVal = document.getElementById("search-input").value.trim().toLowerCase();
    const result = [];
    Object.entries(data).forEach(([topic, nodes]) => {
        // scope filter
        if (searchScope === "here" && topic !== selectedTopic) return;
        if (searchScope === "below" && !topic.startsWith(selectedTopic === "/" ? "" : selectedTopic)) return;
        // for non-search: only show direct children of selectedTopic
        if (!searchVal && topic !== selectedTopic) return;

        Object.values(nodes).forEach(entry => {
            const suffix = getTypeFromMessage(entry.message);
            if (!activeTypes.has(suffix) && suffix !== ">") return;
            if (searchVal && !entry.message.toLowerCase().includes(searchVal)) return;
            result.push(entry);
        });
    });
    result.sort((a, b) => (b.votes || 0) - (a.votes || 0) || a.message.localeCompare(b.message));
    runtimeCheck("getFilteredEntries-end");
    return result;
}

function updateView() {
    runtimeCheck("updateView");
    // update nav back link
    const navBack = document.getElementById("nav-back");
    if (selectedTopic === "/") {
        navBack.innerHTML = `<i class="fas fa-arrow-left"></i> fayf.info`;
        navBack.href = "infopage.html";
    } else {
        const label = topicMap[selectedTopic] || selectedTopic;
        const parts = selectedTopic.split("/").filter(Boolean);
        const parentTopic = parts.length > 1 ? "/" + parts.slice(0, -1).join("/") : "/";
        navBack.innerHTML = `<i class="fas fa-arrow-left"></i> ${topicMap[parentTopic] || "zurück"}`;
        navBack.href = "#";
        navBack.onclick = e => { e.preventDefault(); navigateTo(parentTopic); };
    }
    renderCards(getFilteredEntries());
    runtimeCheck("updateView-end");
}

function navigateTo(topic) {
    runtimeCheck("navigateTo");
    selectedTopic = topic;
    const url = new URL(window.location.href);
    url.searchParams.set("topic", topic);
    history.pushState({}, "", url);
    updateView();
    runtimeCheck("navigateTo-end");
}
```

- [ ] **Step 3: Wire chip filter and placeholder `addSwipeHandlersToAllCards`**

```javascript
// placeholder — swipe added in Task 7
function addSwipeHandlersToAllCards() {}

// Chip filter toggle
document.getElementById("chip-bar").addEventListener("click", e => {
    const chip = e.target.closest(".type-chip");
    if (!chip) return;
    const type = chip.dataset.type;
    if (activeTypes.has(type)) { activeTypes.delete(type); chip.classList.remove("active"); }
    else { activeTypes.add(type); chip.classList.add("active"); }
    updateView();
});

// Card click → drill in or topic link
document.getElementById("card-list").addEventListener("click", e => {
    const card = e.target.closest(".card");
    if (!card) return;
    // don't drill if vote/sign button clicked
    if (e.target.closest(".vote-btn") || e.target.closest(".sign-btn")) return;
    const key = card.dataset.fullKey;
    navigateTo(key);
});
```

- [ ] **Step 4: Init tenantId + initial render**

```javascript
// ── Bootstrap ─────────────────────────────────────────────────────────────────
(function init() {
    const params = new URLSearchParams(window.location.search);
    tenantId = params.get("tid") || params.get("tenantId") || "";
    if (!tenantId) {
        const s = loadSettings();
        tenantId = s.tenantId || "";
    }
    sid = loadSettings().sid || "www";
    const topicParam = params.get("topic");
    if (topicParam) selectedTopic = topicParam;
    document.getElementById("settings-tenant").value = tenantId;
    updateView();
})();
```

- [ ] **Step 5: Verify cards render with mock data**

Open browser console on `http://localhost:8080/app2.html` and run:
```javascript
addEntry("/", "test1", "CO2 steigt weiter!");
addEntry("/", "test2", "Das stimmt nicht!-");
addEntry("/", "test3", "Ist das belegt??");
updateView();
// expect: 3 cards with correct type badges
```

- [ ] **Step 6: Commit**

```bash
git add app2.html
git commit -m "feat(app2) type system, card builder, filtered rendering"
```

---

### Task 4: Data loading from API

**Files:**
- Modify: `app2.html`

**Interfaces:**
- Consumes: `tenantId`, `latestTimestamp`, `addData()`, `addVotesData()`, `updateView()`
- Produces:
  - `loadInitialData()` — fetches entries + votes for tenant
  - `startLongPoll()` — self-correcting recursive long-poll loop

- [ ] **Step 1: Add loadInitialData and startLongPoll**

```javascript
// ── Data loading ──────────────────────────────────────────────────────────────
let pollActive = false;
let pollController = null;

function buildEntriesUrl(extra = {}) {
    const p = new URLSearchParams({ sid, tid: tenantId, topicId: "/", format: "json.0.3", ...extra });
    return "entry/get?" + p.toString();
}
function buildVotesUrl(extra = {}) {
    const p = new URLSearchParams({ sid, tid: tenantId, topicId: "/", format: "json.0.3", ...extra });
    return "vote/get?" + p.toString();
}

async function loadInitialData() {
    if (!tenantId) { showToast("Kein Tenant gesetzt — öffne Einstellungen.", "error"); return; }
    runtimeCheck("loadInitialData");
    try {
        const [eRes, vRes] = await Promise.all([
            fetch(buildEntriesUrl()),
            fetch(buildVotesUrl()),
        ]);
        if (eRes.ok) addData(await eRes.json());
        if (vRes.ok) addVotesData(await vRes.json());
        updateView();
        showToast("Daten geladen.", "success", 1500);
    } catch (err) {
        showToast("Ladefehler: " + err.message, "error");
    }
    runtimeCheck("loadInitialData-end");
}

async function startLongPoll() {
    pollActive = true;
    async function poll() {
        if (!pollActive || document.hidden) {
            setTimeout(poll, 2000);
            return;
        }
        runtimeCheck("poll");
        try {
            pollController = new AbortController();
            const res = await fetch(buildEntriesUrl(latestTimestamp ? { since: latestTimestamp } : {}), { signal: pollController.signal });
            if (res.status === 200) {
                addData(await res.json());
                updateView();
            }
            // 204 = nothing new → backend already held 50 s, re-poll immediately
        } catch (err) {
            if (err.name !== "AbortError") {
                await new Promise(r => setTimeout(r, 5000)); // retry after 5 s on network error
            }
        }
        poll();
    }
    poll();
}

function stopLongPoll() {
    pollActive = false;
    if (pollController) { pollController.abort(); pollController = null; }
}

// Pause poll when tab hidden
document.addEventListener("visibilitychange", () => {
    if (document.hidden) stopLongPoll();
    else if (tenantId) startLongPoll();
});
```

- [ ] **Step 2: Wire init to load data**

Replace the existing `updateView()` call in `init()` with:

```javascript
    updateView();
    if (tenantId) {
        loadInitialData().then(() => startLongPoll());
    }
```

- [ ] **Step 3: Test with real server**

```bash
cd /home/martin/play/infopedia_php && php -S localhost:8080 -t . &
# open http://localhost:8080/app2.html?tid=demo
# expect: cards appear after load, toast "Daten geladen."
kill %1
```

- [ ] **Step 4: Commit**

```bash
git add app2.html
git commit -m "feat(app2) data loading and long-poll live updates"
```

---

### Task 5: Voting (buttons + swipe)

**Files:**
- Modify: `app2.html`

**Interfaces:**
- Consumes: `addVoteByGui()`, `sendVote()`, `buildCard()`, cards in `#card-list`
- Produces:
  - `sendVote(topic, nodeId, delta)` — POST to vote/add
  - `addSwipeHandlersToAllCards()` — swipe left/right on cards

- [ ] **Step 1: Add sendVote**

```javascript
// ── Voting ────────────────────────────────────────────────────────────────────
async function sendVote(topic, nodeId, delta) {
    runtimeCheck("sendVote");
    if (!tenantId) return;
    const key = fullKey(topic, nodeId);
    const entry = data[topic] && data[topic][nodeId];
    if (!entry) return;
    const body = new URLSearchParams({
        sid, tid: tenantId,
        entry: `${key} | ${nodeId} | ${entry.message} | votes:${sid}:${delta}`
    });
    try {
        await fetch("vote/add?" + new URLSearchParams({ sid, tid: tenantId }), { method: "POST", body });
    } catch (err) {
        showToast("Vote fehlgeschlagen", "error");
    }
    runtimeCheck("sendVote-end");
}

function handleVote(key, delta) {
    const [topic, nodeId] = splitKey(key);
    addVoteByGui(topic, nodeId, delta);
    sendVote(topic, nodeId, delta);
    // update score in existing card without full re-render
    const card = document.querySelector(`.card[data-full-key="${key}"]`);
    if (card) {
        const votes = (data[topic] && data[topic][nodeId] && data[topic][nodeId].votes) || 0;
        const score = card.querySelector(".vote-score");
        score.textContent = votes > 0 ? "+" + votes : String(votes);
        score.className = "vote-score " + (votes > 0 ? "pos" : votes < 0 ? "neg" : "zero");
    }
}
```

Note: the card uses `data-full-key` — update `buildCard` to use `card.dataset.fullKey` (already set as `data-full-key` via the hyphenated dataset property). The selector above uses the hyphenated attr directly.

- [ ] **Step 2: Wire vote buttons via event delegation**

```javascript
// vote button delegation
document.getElementById("card-list").addEventListener("click", e => {
    const upBtn = e.target.closest(".upvote-btn");
    const dnBtn = e.target.closest(".downvote-btn");
    if (upBtn) { e.stopPropagation(); handleVote(upBtn.dataset.key, 1); }
    if (dnBtn) { e.stopPropagation(); handleVote(dnBtn.dataset.key, -1); }
});
```

- [ ] **Step 3: Add swipe handler (copied from app.html pattern)**

```javascript
// ── Swipe voting ──────────────────────────────────────────────────────────────
const _debounceMap = {};
function debounceKey(key, ms) {
    const now = Date.now();
    if (_debounceMap[key] && now - _debounceMap[key] < ms) return false;
    _debounceMap[key] = now;
    return true;
}

function addSwipeHandlerToCard(card) {
    if (card.__swipeBound) return;
    card.__swipeBound = true;
    let startX = 0, startY = 0, startTime = 0;

    card.addEventListener("touchstart", e => {
        startX = e.touches[0].clientX;
        startY = e.touches[0].clientY;
        startTime = Date.now();
    }, { passive: true });

    card.addEventListener("touchend", e => {
        const dx = e.changedTouches[0].clientX - startX;
        const dy = e.changedTouches[0].clientY - startY;
        const dt = Date.now() - startTime;
        if (Math.abs(dx) < 50 || Math.abs(dy) > Math.abs(dx) / 1.5 || dt > 700) return;
        const key = card.dataset.fullKey;
        if (!key || !debounceKey("swipe_" + key, 1000)) return;
        const delta = dx > 0 ? 1 : -1;
        card.classList.add(delta > 0 ? "swiping-right" : "swiping-left");
        setTimeout(() => card.classList.remove("swiping-right", "swiping-left"), 400);
        handleVote(key, delta);
    }, { passive: true });
}

function addSwipeHandlersToAllCards() {
    document.querySelectorAll(".card").forEach(addSwipeHandlerToCard);
}
```

- [ ] **Step 4: Test in browser**

```bash
# open http://localhost:8080/app2.html?tid=demo
# click ▲ on a card → score increments
# swipe right on mobile or touch emulator → score +1
```

- [ ] **Step 5: Commit**

```bash
git add app2.html
git commit -m "feat(app2) voting via buttons and swipe"
```

---

### Task 6: Add entry (FAB + bottom sheet)

**Files:**
- Modify: `app2.html`

**Interfaces:**
- Consumes: `selectedTopic`, `tenantId`, `sid`, `generateNodeId()`, `matchType()`, `addEntry()`, `updateView()`
- Produces: `openBottomSheet()`, `closeBottomSheet()`, `submitEntry()`

- [ ] **Step 1: Add sheet logic**

```javascript
// ── Add entry ─────────────────────────────────────────────────────────────────
let bsSelectedType = ".";

function openBottomSheet() {
    if (selectedTopic === "/") { showToast("Wähle zuerst ein Thema.", "info"); return; }
    document.getElementById("bottom-sheet-overlay").classList.add("open");
    document.getElementById("bs-textarea").value = "";
    document.getElementById("bs-textarea").focus();
}
function closeBottomSheet() {
    document.getElementById("bottom-sheet-overlay").classList.remove("open");
}

async function submitEntry() {
    const text = document.getElementById("bs-textarea").value.trim();
    if (text.length < 3) { showToast("Zu kurz (mind. 3 Zeichen).", "error"); return; }
    const message = matchType(text, bsSelectedType);
    const nodeId = generateNodeId();
    const body = new URLSearchParams({ sid, tid: tenantId, entry: `${selectedTopic} | ${nodeId} | ${message}` });
    try {
        const res = await fetch("entry/add?" + new URLSearchParams({ sid, tid: tenantId }), { method: "POST", body });
        if (res.ok) {
            const json = await res.json();
            addEntry(selectedTopic, nodeId, message, 0, json.timestamp || "");
            updateView();
            showToast("Eintrag hinzugefügt!", "success");
            closeBottomSheet();
        } else {
            showToast("Fehler " + res.status, "error");
        }
    } catch (err) {
        showToast("Netzwerkfehler", "error");
    }
}

// wire FAB and sheet buttons
document.getElementById("fab").addEventListener("click", openBottomSheet);
document.getElementById("bs-cancel").addEventListener("click", closeBottomSheet);
document.getElementById("bs-submit").addEventListener("click", submitEntry);
document.getElementById("bottom-sheet-overlay").addEventListener("click", e => {
    if (e.target === document.getElementById("bottom-sheet-overlay")) closeBottomSheet();
});

// type chips in sheet
document.getElementById("bs-type-chips").addEventListener("click", e => {
    const chip = e.target.closest(".bs-type-chip");
    if (!chip) return;
    bsSelectedType = chip.dataset.type;
    document.querySelectorAll(".bs-type-chip").forEach(c => c.classList.toggle("active", c === chip));
});
```

- [ ] **Step 2: Test add entry**

```bash
# open http://localhost:8080/app2.html?tid=demo&topic=/demo
# click green FAB
# type "Solar panels are efficient!" → pick "Fakt" → submit
# expect: new card appears, toast "Eintrag hinzugefügt!"
```

- [ ] **Step 3: Commit**

```bash
git add app2.html
git commit -m "feat(app2) add entry via FAB bottom sheet"
```

---

### Task 7: Search (3 scope modes)

**Files:**
- Modify: `app2.html`

**Interfaces:**
- Consumes: `searchScope`, `getFilteredEntries()`, `updateView()`
- Produces: search input expand/collapse, scope chip toggle

- [ ] **Step 1: Wire search UI**

```javascript
// ── Search ────────────────────────────────────────────────────────────────────
document.getElementById("nav-search-icon").addEventListener("click", () => {
    const bar = document.getElementById("nav-search-bar");
    bar.classList.toggle("open");
    if (bar.classList.contains("open")) document.getElementById("search-input").focus();
    else { document.getElementById("search-input").value = ""; updateView(); }
});

document.getElementById("search-input").addEventListener("input", () => updateView());

document.getElementById("nav-search-bar").addEventListener("click", e => {
    const chip = e.target.closest(".scope-chip");
    if (!chip) return;
    searchScope = chip.dataset.scope;
    document.querySelectorAll(".scope-chip").forEach(c => c.classList.toggle("active", c === chip));
    updateView();
});
```

- [ ] **Step 2: Verify search works for all scopes**

```bash
# open http://localhost:8080/app2.html?tid=demo
# click search icon → type "solar"
# scope "Global" → shows all matching entries across all topics
# scope "Below" → shows entries at or under current topic
# scope "Here" → shows only direct children of current topic
```

- [ ] **Step 3: Commit**

```bash
git add app2.html
git commit -m "feat(app2) search with Here/Below/Global scope"
```

---

### Task 8: Signing + verification badge

**Files:**
- Modify: `app2.html`

**Interfaces:**
- Consumes: `votesData`, `getSignedCount()`, `fullKey()`, `buildCard()`
- Produces: `signEntry(topic, nodeId)` — POST `signed:sid:1` to votes API

- [ ] **Step 1: Add signEntry**

```javascript
// ── Signing ───────────────────────────────────────────────────────────────────
async function signEntry(topic, nodeId) {
    runtimeCheck("signEntry");
    if (!tenantId) return;
    const key = fullKey(topic, nodeId);
    const entry = data[topic] && data[topic][nodeId];
    if (!entry) return;
    const body = new URLSearchParams({
        sid, tid: tenantId,
        entry: `${key} | ${nodeId} | ${entry.message} | signed:${sid}:1`
    });
    try {
        const res = await fetch("vote/add?" + new URLSearchParams({ sid, tid: tenantId }), { method: "POST", body });
        if (res.ok) {
            if (!votesData[key]) votesData[key] = { votes: 0, signed: 0 };
            votesData[key].signed = (votesData[key].signed || 0) + 1;
            showToast("Bestätigt!", "success", 1500);
            updateView();
        }
    } catch (err) {
        showToast("Fehler beim Bestätigen", "error");
    }
    runtimeCheck("signEntry-end");
}
```

- [ ] **Step 2: Wire sign button via delegation**

```javascript
document.getElementById("card-list").addEventListener("click", e => {
    const btn = e.target.closest(".sign-btn");
    if (!btn) return;
    e.stopPropagation();
    const [topic, nodeId] = splitKey(btn.dataset.key);
    signEntry(topic, nodeId);
});
```

- [ ] **Step 3: Test signing**

```bash
# open http://localhost:8080/app2.html?tid=demo
# click "Bestätigen" on a Fakt card
# expect: sign count increments; after 2 signs → badge changes to "Bewiesen ✓"
```

- [ ] **Step 4: Commit**

```bash
git add app2.html
git commit -m "feat(app2) signing and verification badge"
```

---

### Task 9: Settings panel + tenant switching

**Files:**
- Modify: `app2.html`

**Interfaces:**
- Consumes: `tenantId`, `loadSettings()`, `saveSettings()`, `loadInitialData()`, `startLongPoll()`, `stopLongPoll()`
- Produces: settings open/close/apply, tenant change restarts data load

- [ ] **Step 1: Add settings wiring**

```javascript
// ── Settings ──────────────────────────────────────────────────────────────────
function openSettings() {
    document.getElementById("settings-tenant").value = tenantId;
    document.getElementById("settings-overlay").classList.add("open");
}
function closeSettings() {
    document.getElementById("settings-overlay").classList.remove("open");
}
function applySettings() {
    const newTenant = document.getElementById("settings-tenant").value.trim().replace(/[^a-zA-Z0-9_-]/g, "").substring(0, 30);
    if (newTenant && newTenant !== tenantId) {
        tenantId = newTenant;
        saveSettings({ tenantId });
        stopLongPoll();
        data = {}; votesData = {}; topicMap = {}; latestTimestamp = null; selectedTopic = "/";
        updateView();
        loadInitialData().then(() => startLongPoll());
        // update URL
        const url = new URL(window.location.href);
        url.searchParams.set("tid", tenantId);
        history.pushState({}, "", url);
    }
    closeSettings();
}

document.getElementById("nav-settings-icon").addEventListener("click", openSettings);
document.getElementById("settings-close").addEventListener("click", closeSettings);
document.getElementById("settings-apply").addEventListener("click", applySettings);
document.getElementById("settings-overlay").addEventListener("click", e => {
    if (e.target === document.getElementById("settings-overlay")) closeSettings();
});
```

- [ ] **Step 2: Test tenant switch**

```bash
# open http://localhost:8080/app2.html?tid=demo
# click ⚙ → change tenant → click Übernehmen
# expect: data reloads for new tenant, URL updates
```

- [ ] **Step 3: Commit**

```bash
git add app2.html
git commit -m "feat(app2) settings panel and tenant switching"
```

---

### Task 10: justfile link + final smoke test

**Files:**
- Modify: `justfile`

- [ ] **Step 1: Add app2 link to serve output**

In `justfile`, find the serve recipe and add the app2 line:

```diff
 serve:
     @echo "  App:       {{base}}/infopedia.html"
+    @echo "  App2:      {{base}}/app2.html"
     @echo "  Statistic: {{base}}/statistic.php"
```

- [ ] **Step 2: Run e2e to confirm backend unaffected**

```bash
just e2e
# expect: all tests pass
```

- [ ] **Step 3: Smoke test app2 end-to-end**

```bash
just serve &
# open http://localhost:8080/app2.html?tid=demo
# 1. cards load from demo tenant
# 2. filter chip toggle hides/shows type
# 3. search "solar" → results appear
# 4. tap card → drills into sub-topic, nav back link updates
# 5. FAB → add "Test entry." → appears in list
# 6. swipe right → score +1
# 7. sign a Fakt → sign count increments
# 8. ⚙ settings → change tenant → data reloads
kill %1
```

- [ ] **Step 4: Final commit**

```bash
git add app2.html justfile
git commit -m "feat(app2) wire justfile link, complete smoke test"
```
