# CA17 — User-Eased Issue Reporting in `app2.html`

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement CA17 in `app2.html` — whenever an unexpected error toast is shown, a one-tap "Melden" button appears inside the toast. Tapping it opens an issue-report overlay pre-filled with action trail, state snapshot, sanitised error details, timestamp, and app version. The user reviews, edits, then submits via Copy / E-Mail / GitHub.

**Architecture:** All changes are confined to the single `app2.html` file. New HTML is appended after `#settings-overlay`; CSS is appended inside the existing `<style>` block; JS is appended inside the existing `<script>` block (with targeted edits to existing functions). No new files, no backend changes.

**Constitution refs:** CA17 (issue reporting), CA15 (user-friendly errors), CA16 (developer-detailed logs), CA1 (simple first), CP1 (procedural JS, no framework).

---

## Global Constraints

- CP1: Plain procedural JS — no classes, no framework, no build tools
- Single self-contained `app2.html` — all changes append or surgically modify that file only
- No backend changes
- Version constant: `const APP_VERSION = "0.1.0"` — add near top of `<script>` block (after the state vars, before the first function)
- `localStorage` key: `fayf_settings` (already used by `loadSettings` / `saveSettings`)
- Validation toasts (no `errorCtx`): "Zu kurz (mind. 3 Zeichen).", "Wähle zuerst ein Thema.", "Kein Tenant gesetzt — öffne Einstellungen." (this one is actually a validation gate, not a network error)
- Unexpected-error toasts (must receive `errorCtx`): all catch/non-ok cases in `sendVote`, `signEntry`, `loadInitialData`, `submitEntry`

---

## File Structure

| File | Action | Responsibility |
|---|---|---|
| `app2.html` | **Modify** | Add APP_VERSION; action trail; state/report builders; issue overlay HTML + CSS; updated `showToast`; updated settings panel + functions; wire all buttons |

No other files touched.

---

## Task 1: Action trail, state snapshot, and sanitise helper

**Files:** `app2.html`

**What it does:** Introduces the global `actionTrail` ring-buffer (max 10), the `pushAction` recorder, the `buildStateSnapshot` string builder, and `sanitiseForReport`. Instruments all user-action entry points. Adds `APP_VERSION` constant.

**Interfaces produced:**
- `actionTrail` — global array, max 10 entries, each `{ ts, action, detail }`
- `pushAction(action, detail)` — call at every user action
- `buildStateSnapshot()` — returns multiline string, tenant shown as `[hidden]`
- `sanitiseForReport(text)` — replaces `sid`/`tenantId` values with `[sid]`/`[tid]`

---

- [ ] **Step 1.1 — Add `APP_VERSION` constant after the state variables block**

  File: `app2.html`

  Locate the `let searchScope` line (line ~238). Add `APP_VERSION` immediately after the state block, before the first function definition:

  Find:
  ```javascript
  let activeTypes = new Set([".", "!", "!-", "?", "??"]); // visible types
  ```
  Replace with:
  ```javascript
  let activeTypes = new Set([".", "!", "!-", "?", "??"]); // visible types

  // ── Version ────────────────────────────────────────────────────────────────────
  const APP_VERSION = "0.1.0";
  ```

  **Verification:** Open browser console, type `APP_VERSION` — should return `"0.1.0"`.

---

- [ ] **Step 1.2 — Add action trail globals and `pushAction` after the version constant**

  File: `app2.html`

  Append immediately after the `APP_VERSION` line:

  ```javascript
  // ── Action trail (CA17) ────────────────────────────────────────────────────────
  const ACTION_TRAIL_MAX = 10;
  let actionTrail = [];

  function pushAction(action, detail) {
      actionTrail.push({ ts: new Date().toISOString(), action, detail: detail || "" });
      if (actionTrail.length > ACTION_TRAIL_MAX) actionTrail.shift();
  }
  ```

  **Verification:** In console: `pushAction("test", "x"); actionTrail` — should show one entry with `action: "test"`, `detail: "x"`, `ts` as ISO string.

---

- [ ] **Step 1.3 — Add `buildStateSnapshot` and `sanitiseForReport`**

  File: `app2.html`

  Append immediately after `pushAction`:

  ```javascript
  function buildStateSnapshot() {
      const searchVal = document.getElementById("search-input").value.trim();
      const cards = document.querySelectorAll("#card-list .card").length;
      return [
          "Thema:  " + selectedTopic,
          "Tenant: [hidden]",
          "Filter: " + (activeTypes.size ? [...activeTypes].join(", ") : "alle"),
          "Suche:  " + (searchVal ? '"' + searchVal + '" (' + searchScope + ')' : "keine"),
          "Karten: " + cards,
      ].join("\n");
  }

  function sanitiseForReport(text) {
      let s = String(text);
      if (sid)      s = s.split(sid).join("[sid]");
      if (tenantId) s = s.split(tenantId).join("[tid]");
      return s;
  }
  ```

  **Verification:** In console after loading a tenant: `buildStateSnapshot()` should show a multiline string with `Tenant: [hidden]`. `sanitiseForReport("test " + sid)` should return `"test [sid]"`.

---

- [ ] **Step 1.4 — Instrument `navigateTo`**

  File: `app2.html`

  Find the existing `navigateTo` function:
  ```javascript
  function navigateTo(topic) {
      runtimeCheck("navigateTo");
      selectedTopic = topic;
  ```
  Replace with:
  ```javascript
  function navigateTo(topic) {
      runtimeCheck("navigateTo");
      pushAction("navigate", topic);
      selectedTopic = topic;
  ```

---

- [ ] **Step 1.5 — Instrument `handleVote`**

  File: `app2.html`

  Find:
  ```javascript
  function handleVote(key, delta) {
      const [topic, nodeId] = splitKey(key);
      addVoteByGui(topic, nodeId, delta);
      sendVote(topic, nodeId, delta);
  ```
  Replace with:
  ```javascript
  function handleVote(key, delta) {
      const [topic, nodeId] = splitKey(key);
      pushAction("vote", key + " " + (delta > 0 ? "+" : "") + delta);
      addVoteByGui(topic, nodeId, delta);
      sendVote(topic, nodeId, delta);
  ```

---

- [ ] **Step 1.6 — Instrument `signEntry`**

  File: `app2.html`

  Find the success branch of `signEntry` (after `if (res.ok) {`):
  ```javascript
        if (res.ok) {
              if (!votesData[key]) votesData[key] = { votes: 0, signed: 0 };
              votesData[key].signed = (votesData[key].signed || 0) + 1;
              showToast("Bestätigt!", "success", 1500);
  ```
  Replace with:
  ```javascript
        if (res.ok) {
              pushAction("sign", key);
              if (!votesData[key]) votesData[key] = { votes: 0, signed: 0 };
              votesData[key].signed = (votesData[key].signed || 0) + 1;
              showToast("Bestätigt!", "success", 1500);
  ```

---

- [ ] **Step 1.7 — Instrument `submitEntry` success path**

  File: `app2.html`

  Find:
  ```javascript
          if (res.ok) {
              const json = await res.json();
              addEntry(selectedTopic, nodeId, message, 0, json.timestamp || "");
              updateView();
              showToast("Eintrag hinzugefügt!", "success");
  ```
  Replace with:
  ```javascript
          if (res.ok) {
              const json = await res.json();
              pushAction("addEntry", selectedTopic + "/" + nodeId);
              addEntry(selectedTopic, nodeId, message, 0, json.timestamp || "");
              updateView();
              showToast("Eintrag hinzugefügt!", "success");
  ```

---

- [ ] **Step 1.8 — Instrument search input event**

  File: `app2.html`

  Find:
  ```javascript
  document.getElementById("search-input").addEventListener("input", () => updateView());
  ```
  Replace with:
  ```javascript
  document.getElementById("search-input").addEventListener("input", () => {
      const val = document.getElementById("search-input").value.trim();
      if (val) pushAction("search", '"' + val + '"');
      updateView();
  });
  ```

---

- [ ] **Step 1.9 — Instrument scope chip click**

  File: `app2.html`

  Find:
  ```javascript
  document.getElementById("nav-search-bar").addEventListener("click", e => {
      const chip = e.target.closest(".scope-chip");
      if (!chip) return;
      searchScope = chip.dataset.scope;
      document.querySelectorAll(".scope-chip").forEach(c => c.classList.toggle("active", c === chip));
      updateView();
  });
  ```
  Replace with:
  ```javascript
  document.getElementById("nav-search-bar").addEventListener("click", e => {
      const chip = e.target.closest(".scope-chip");
      if (!chip) return;
      searchScope = chip.dataset.scope;
      pushAction("searchScope", searchScope);
      document.querySelectorAll(".scope-chip").forEach(c => c.classList.toggle("active", c === chip));
      updateView();
  });
  ```

---

- [ ] **Step 1.10 — Instrument type filter chip click**

  File: `app2.html`

  Find:
  ```javascript
  document.getElementById("chip-bar").addEventListener("click", e => {
      const chip = e.target.closest(".type-chip");
      if (!chip) return;
      const type = chip.dataset.type;
      if (activeTypes.has(type)) { activeTypes.delete(type); chip.classList.remove("active"); }
      else { activeTypes.add(type); chip.classList.add("active"); }
      updateView();
  });
  ```
  Replace with:
  ```javascript
  document.getElementById("chip-bar").addEventListener("click", e => {
      const chip = e.target.closest(".type-chip");
      if (!chip) return;
      const type = chip.dataset.type;
      if (activeTypes.has(type)) { activeTypes.delete(type); chip.classList.remove("active"); }
      else { activeTypes.add(type); chip.classList.add("active"); }
      pushAction("filterType", [...activeTypes].join(","));
      updateView();
  });
  ```

---

- [ ] **Step 1.11 — Instrument `applySettings` (tenant change)**

  File: `app2.html`

  Find in `applySettings`:
  ```javascript
      if (newTenant && newTenant !== tenantId) {
          tenantId = newTenant;
          saveSettings({ tenantId });
  ```
  Replace with:
  ```javascript
      if (newTenant && newTenant !== tenantId) {
          pushAction("changeTenant", "[tid]");
          tenantId = newTenant;
          saveSettings({ tenantId });
  ```

---

- [ ] **Step 1.12 — Manual verification of Task 1**

  1. Open `app2.html` in a browser with a valid `?tid=` param
  2. Navigate to a topic — check `actionTrail` in console: one entry with `action: "navigate"`
  3. Type in search — check: entry with `action: "search"`
  4. Toggle a type chip — check: entry with `action: "filterType"`
  5. Confirm `buildStateSnapshot()` shows `Tenant: [hidden]` and correct topic
  6. Confirm `sanitiseForReport("sid=" + sid)` → `"sid=[sid]"`
  7. Run `just e2e` — must pass (no backend changes)

---

## Task 2: Issue overlay HTML/CSS + report builder + open/close

**Files:** `app2.html`

**What it does:** Adds the `#issue-overlay` DOM node and its CSS, the `buildReportText` assembler, and `openIssueReport` / `closeIssueReport` functions. The overlay is inert at this stage — submission buttons are wired in Task 3.

**Interfaces produced:**
- `#issue-overlay` — hidden overlay with `#issue-user-msg` textarea, `#issue-details` textarea, `#issue-copy`, `#issue-mail`, `#issue-github`, `#issue-close` buttons
- `buildReportText(errorCtx)` — returns full report string (no user message)
- `openIssueReport(errorCtx)` — fills textareas, shows/hides mail+github buttons, opens overlay
- `closeIssueReport()` — removes `.open`

**Depends on:** Task 1 (uses `actionTrail`, `buildStateSnapshot`, `sanitiseForReport`, `APP_VERSION`)

---

- [ ] **Step 2.1 — Add CSS for issue overlay and `.toast-report-btn`**

  File: `app2.html`

  Locate the closing `</style>` tag. Insert before it:

  ```css
    /* ── Issue report overlay (CA17) ───────────────────── */
    #issue-overlay {
      display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 450;
      align-items: center; justify-content: center;
    }
    #issue-overlay.open { display: flex; }
    #issue-panel {
      background: #fff; border-radius: 8px; padding: 1.5rem;
      width: min(540px, 95vw); display: flex; flex-direction: column; gap: 0.75rem;
      max-height: 90vh; overflow-y: auto;
    }
    #issue-panel h3 { font-size: 1.05rem; margin: 0; }
    #issue-panel textarea { resize: vertical; }
    .toast-report-btn {
      display: inline-block;
      margin-left: 0.75rem;
      padding: 0.15rem 0.5rem;
      background: rgba(255,255,255,0.2);
      color: #fff;
      border: 1px solid rgba(255,255,255,0.5);
      border-radius: 3px;
      font-size: 0.8rem;
      cursor: pointer;
      vertical-align: middle;
    }
    .toast-report-btn:hover { background: rgba(255,255,255,0.35); }
  ```

---

- [ ] **Step 2.2 — Add `#issue-overlay` HTML after `#settings-overlay`**

  File: `app2.html`

  Find:
  ```html
  <div id="toast-container"></div>
  ```
  Replace with:
  ```html
  <div id="issue-overlay" class="overlay">
    <div id="issue-panel">
      <h3>Problem melden</h3>
      <p style="margin:0;font-size:0.85rem;color:#666">Was ist passiert? (optional)</p>
      <textarea id="issue-user-msg" rows="3" style="width:100%;box-sizing:border-box;font-family:inherit;font-size:0.9rem" placeholder="Beschreibe kurz, was du gemacht hast..."></textarea>
      <p style="margin:0;font-size:0.85rem;color:#666">Technische Details (automatisch — bitte prüfen und ggf. anpassen):</p>
      <textarea id="issue-details" rows="10" style="width:100%;box-sizing:border-box;font-family:monospace;font-size:0.78rem"></textarea>
      <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
        <button id="issue-copy">📋 Kopieren</button>
        <button id="issue-mail">✉️ E-Mail</button>
        <button id="issue-github">🐛 GitHub</button>
        <button id="issue-close" style="margin-left:auto">Schließen</button>
      </div>
    </div>
  </div>

  <div id="toast-container"></div>
  ```

---

- [ ] **Step 2.3 — Add `buildReportText`**

  File: `app2.html`

  Append inside `<script>`, after the `sanitiseForReport` function (end of the action-trail block):

  ```javascript
  // ── Issue report builder (CA17) ────────────────────────────────────────────────
  function buildReportText(errorCtx) {
      const lines = [];
      lines.push("=== Fehlerbericht ===");
      lines.push("Zeit:    " + new Date().toISOString());
      lines.push("Version: " + APP_VERSION);
      lines.push("");
      lines.push("--- Letzte Aktionen ---");
      if (actionTrail.length === 0) {
          lines.push("(keine)");
      } else {
          actionTrail.slice().reverse().forEach(a => {
              lines.push(a.ts.slice(11, 19) + "  " + a.action + (a.detail ? ": " + a.detail : ""));
          });
      }
      lines.push("");
      lines.push("--- Zustand ---");
      lines.push(buildStateSnapshot());
      if (errorCtx) {
          lines.push("");
          lines.push("--- Fehlerdetails ---");
          lines.push(sanitiseForReport(
              (errorCtx.label  ? "Fehler: "  + errorCtx.label + "\n" : "") +
              (errorCtx.status ? "Status: "  + errorCtx.status + "\n" : "") +
              (errorCtx.url    ? "URL:    "  + errorCtx.url + "\n" : "") +
              (errorCtx.err    ? "Detail: "  + String(errorCtx.err) : "")
          ));
      }
      return lines.join("\n");
  }
  ```

---

- [ ] **Step 2.4 — Add `openIssueReport` and `closeIssueReport`**

  File: `app2.html`

  Append immediately after `buildReportText`:

  ```javascript
  function openIssueReport(errorCtx) {
      document.getElementById("issue-details").value = buildReportText(errorCtx);
      document.getElementById("issue-user-msg").value = "";
      const s = loadSettings();
      document.getElementById("issue-mail").style.display   = s.issueMailto    ? "" : "none";
      document.getElementById("issue-github").style.display = s.issueGithubUrl ? "" : "none";
      document.getElementById("issue-overlay").classList.add("open");
  }

  function closeIssueReport() {
      document.getElementById("issue-overlay").classList.remove("open");
  }
  ```

---

- [ ] **Step 2.5 — Wire `#issue-close` button and backdrop click**

  File: `app2.html`

  Append after the `closeIssueReport` function (still inside `<script>`):

  ```javascript
  document.getElementById("issue-close").addEventListener("click", closeIssueReport);
  document.getElementById("issue-overlay").addEventListener("click", e => {
      if (e.target === document.getElementById("issue-overlay")) closeIssueReport();
  });
  ```

---

- [ ] **Step 2.6 — Manual verification of Task 2**

  1. Open `app2.html` in browser, open DevTools console
  2. Run `openIssueReport({ label: "test", err: new Error("boom") })` — overlay must appear
  3. `#issue-details` textarea must contain `=== Fehlerbericht ===`, action trail, state snapshot, and `Fehler: test`
  4. The `sid` / `tenantId` values must be replaced by `[sid]` / `[tid]` in the details
  5. Click backdrop or "Schließen" — overlay must close
  6. Run `just e2e` — must pass

---

## Task 3: Submission functions + "Melden" button + settings fields

**Files:** `app2.html`

**What it does:**
- Adds `buildFullReport`, the three submission functions (`submitReportClipboard`, `submitReportMail`, `submitReportGithub`), and wires their buttons
- Modifies `showToast` to accept an optional `errorCtx` parameter and append a "Melden" button for unexpected errors
- Updates all unexpected-error `showToast` calls to pass an `errorCtx` object
- Adds `issueGithubUrl` and `issueMailto` inputs to `#settings-overlay` HTML, and extends `openSettings` / `applySettings` to read/write them

**Depends on:** Task 2 (`openIssueReport`, `closeIssueReport`, `buildReportText`)

---

- [ ] **Step 3.1 — Add `buildFullReport` and the three submission functions**

  File: `app2.html`

  Append after the `closeIssueReport` / backdrop-click wiring (still inside `<script>`):

  ```javascript
  // ── Issue report submission (CA17) ────────────────────────────────────────────
  function buildFullReport() {
      const userMsg = document.getElementById("issue-user-msg").value.trim();
      const details = document.getElementById("issue-details").value;
      return (userMsg ? "Nutzerbeschreibung:\n" + userMsg + "\n\n" : "") + details;
  }

  function submitReportClipboard() {
      navigator.clipboard.writeText(buildFullReport())
          .then(() => { showToast("Bericht kopiert!", "success", 2000); closeIssueReport(); })
          .catch(() => showToast("Kopieren fehlgeschlagen.", "error"));
  }

  function submitReportMail() {
      const s = loadSettings();
      if (!s.issueMailto) return;
      const subject = encodeURIComponent("Fehlerbericht fayf.info " + APP_VERSION);
      const body    = encodeURIComponent(buildFullReport());
      window.open("mailto:" + s.issueMailto + "?subject=" + subject + "&body=" + body);
      closeIssueReport();
  }

  function submitReportGithub() {
      const s = loadSettings();
      if (!s.issueGithubUrl) return;
      window.open(s.issueGithubUrl + "?body=" + encodeURIComponent(buildFullReport()), "_blank");
      closeIssueReport();
  }
  ```

---

- [ ] **Step 3.2 — Wire submission buttons**

  File: `app2.html`

  Append after the submission functions:

  ```javascript
  document.getElementById("issue-copy").addEventListener("click", submitReportClipboard);
  document.getElementById("issue-mail").addEventListener("click", submitReportMail);
  document.getElementById("issue-github").addEventListener("click", submitReportGithub);
  ```

---

- [ ] **Step 3.3 — Extend `showToast` to accept and use `errorCtx`**

  File: `app2.html`

  Find the existing `showToast` function in its entirety:
  ```javascript
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
  ```
  Replace with:
  ```javascript
  function showToast(message, type = "info", duration = 3000, errorCtx = null) {
      const container = document.getElementById("toast-container");
      const el = document.createElement("div");
      el.style.cssText = `
          background:${type === "success" ? "#4CAF50" : type === "error" ? "#f44336" : "#333"};
          color:#fff;padding:0.75rem 1rem;margin-bottom:0.4rem;border-radius:6px;
          box-shadow:0 2px 6px rgba(0,0,0,0.25);font-size:0.9rem;display:flex;align-items:center;
      `;
      const span = document.createElement("span");
      span.textContent = message;
      el.appendChild(span);
      if (type === "error" && errorCtx) {
          const btn = document.createElement("button");
          btn.textContent = "Melden";
          btn.className = "toast-report-btn";
          btn.onclick = (e) => { e.stopPropagation(); openIssueReport(errorCtx); };
          el.appendChild(btn);
      }
      container.appendChild(el);
      setTimeout(() => el.remove(), duration);
  }
  ```

  Note: `el.textContent = message` is replaced by a `<span>` child so that appending the button does not overwrite the message text.

---

- [ ] **Step 3.4 — Update unexpected-error `showToast` calls to pass `errorCtx`**

  File: `app2.html`

  **`sendVote` catch:**

  Find:
  ```javascript
      } catch (err) {
          console.error("[app2] sendVote:", err);
          showToast("Bewertung konnte nicht gespeichert werden.", "error");
      }
  ```
  Replace with:
  ```javascript
      } catch (err) {
          console.error("[app2] sendVote:", err);
          showToast("Bewertung konnte nicht gespeichert werden.", "error", 3000, { label: "sendVote", err });
      }
  ```

  **`signEntry` catch:**

  Find:
  ```javascript
      } catch (err) {
          console.error("[app2] signEntry:", err);
          showToast("Bestätigung konnte nicht gespeichert werden.", "error");
      }
  ```
  Replace with:
  ```javascript
      } catch (err) {
          console.error("[app2] signEntry:", err);
          showToast("Bestätigung konnte nicht gespeichert werden.", "error", 3000, { label: "signEntry", err });
      }
  ```

  **`loadInitialData` catch:**

  Find:
  ```javascript
      } catch (err) {
          console.error("[app2] loadInitialData:", err);
          showToast("Daten konnten nicht geladen werden. Bitte Seite neu laden.", "error", 5000);
      }
  ```
  Replace with:
  ```javascript
      } catch (err) {
          console.error("[app2] loadInitialData:", err);
          showToast("Daten konnten nicht geladen werden. Bitte Seite neu laden.", "error", 5000, { label: "loadInitialData", err });
      }
  ```

  **`submitEntry` non-ok branch:**

  Find:
  ```javascript
          } else {
              showToast("Eintrag konnte nicht gespeichert werden.", "error");
          }
  ```
  Replace with:
  ```javascript
          } else {
              showToast("Eintrag konnte nicht gespeichert werden.", "error", 3000, { label: "submitEntry", status: res.status, url: res.url });
          }
  ```

  **`submitEntry` catch (network error — unexpected):**

  Find:
  ```javascript
      } catch (err) {
          console.error("[app2] submitEntry:", err);
          showToast("Verbindung unterbrochen. Bitte erneut versuchen.", "error");
      }
  ```
  Replace with:
  ```javascript
      } catch (err) {
          console.error("[app2] submitEntry:", err);
          showToast("Verbindung unterbrochen. Bitte erneut versuchen.", "error", 3000, { label: "submitEntry", err });
      }
  ```

  **Calls that must NOT receive `errorCtx` (validation — leave unchanged):**
  - `showToast("Zu kurz (mind. 3 Zeichen).", "error")` — no `errorCtx`
  - `showToast("Wähle zuerst ein Thema.", "info")` — no `errorCtx`
  - `showToast("Kein Tenant gesetzt — öffne Einstellungen.", "error")` — no `errorCtx` (validation gate)

---

- [ ] **Step 3.5 — Add `issueGithubUrl` and `issueMailto` inputs to `#settings-overlay` HTML**

  File: `app2.html`

  Find (inside `#settings-overlay`):
  ```html
      <label>Tenant ID
        <input id="settings-tenant" type="text" placeholder="demo">
      </label>
      <div style="display:flex;gap:0.5rem">
  ```
  Replace with:
  ```html
      <label>Tenant ID
        <input id="settings-tenant" type="text" placeholder="demo">
      </label>
      <label style="display:block;margin-top:0.75rem;font-size:0.85rem">GitHub Issues URL <span style="color:#999">(optional)</span></label>
      <input id="settings-github-url" type="url" placeholder="https://github.com/user/repo/issues/new" style="width:100%;box-sizing:border-box;margin-top:0.25rem;padding:0.4rem;border:1px solid #ccc;border-radius:4px">
      <label style="display:block;margin-top:0.5rem;font-size:0.85rem">Feedback E-Mail <span style="color:#999">(optional)</span></label>
      <input id="settings-issue-mail" type="email" placeholder="bugs@example.com" style="width:100%;box-sizing:border-box;margin-top:0.25rem;padding:0.4rem;border:1px solid #ccc;border-radius:4px">
      <div style="display:flex;gap:0.5rem">
  ```

---

- [ ] **Step 3.6 — Extend `openSettings` to populate the new fields**

  File: `app2.html`

  Find:
  ```javascript
  function openSettings() {
      document.getElementById("settings-tenant").value = tenantId;
      document.getElementById("settings-overlay").classList.add("open");
  }
  ```
  Replace with:
  ```javascript
  function openSettings() {
      const s = loadSettings();
      document.getElementById("settings-tenant").value     = tenantId;
      document.getElementById("settings-github-url").value = s.issueGithubUrl || "";
      document.getElementById("settings-issue-mail").value = s.issueMailto    || "";
      document.getElementById("settings-overlay").classList.add("open");
  }
  ```

---

- [ ] **Step 3.7 — Extend `applySettings` to save the new fields**

  File: `app2.html`

  Find (the end of `applySettings`, just before `closeSettings()`):
  ```javascript
        const url = new URL(window.location.href);
        url.searchParams.set("tid", tenantId);
        history.pushState({}, "", url);
      }
      closeSettings();
  }
  ```
  Replace with:
  ```javascript
        const url = new URL(window.location.href);
        url.searchParams.set("tid", tenantId);
        history.pushState({}, "", url);
      }
      const githubUrl = document.getElementById("settings-github-url").value.trim();
      const issueMail = document.getElementById("settings-issue-mail").value.trim();
      saveSettings({ issueGithubUrl: githubUrl, issueMailto: issueMail });
      closeSettings();
  }
  ```

---

- [ ] **Step 3.8 — Manual verification of Task 3 (full feature smoke test)**

  **Setup:**
  1. Open `app2.html?tid=demo` in browser (or any valid tenant)
  2. Open DevTools → Console

  **Smoke test A — "Melden" button appears on unexpected errors:**
  1. Simulate a network error: temporarily break the fetch URL by overriding it in console or disconnecting network
  2. Trigger `loadInitialData()` — toast should appear with message "Daten konnten nicht geladen werden…" AND a "Melden" button
  3. Tap "Melden" — `#issue-overlay` must open
  4. `#issue-details` must contain `Fehler: loadInitialData` and the error detail; `sid` / `tenantId` values replaced
  5. Close overlay — OK

  **Smoke test B — validation toasts have no "Melden" button:**
  1. Open add-entry sheet while on root topic `/`
  2. Toast "Wähle zuerst ein Thema." must NOT have a "Melden" button
  3. Navigate to a topic, open sheet, type "ab", tap submit
  4. Toast "Zu kurz (mind. 3 Zeichen)." must NOT have a "Melden" button

  **Smoke test C — submission paths:**
  1. Trigger the overlay via console: `openIssueReport({ label: "manualTest", err: "e2e check" })`
  2. Type something in the description textarea
  3. Click "📋 Kopieren" — toast "Bericht kopiert!" should appear; overlay closes; paste to verify content
  4. Open Settings → enter a GitHub URL (e.g. `https://github.com/example/repo/issues/new`) → Apply
  5. Re-trigger overlay; "🐛 GitHub" button should now be visible; click it → browser opens GitHub URL with prefilled `?body=`
  6. Open Settings → enter an email (e.g. `test@example.com`) → Apply
  7. Re-trigger overlay; "✉️ E-Mail" should appear; click it → opens `mailto:` link with subject + body

  **Regression check:**
  1. Run `just e2e` — must pass (no backend changes)
  2. Confirm all existing functional flows (load data, vote, sign, add entry, settings tenant change) still work

---

## Testing

This feature has no automated test harness in `app2.html`. All verification is manual per the steps above. `just e2e` is run at the end of each task exclusively to confirm the backend is unaffected.

### Summary of manual checks per task

| Task | Key checks |
|---|---|
| Task 1 | `actionTrail` populated on navigate/vote/search; `buildStateSnapshot` hides tenant; `sanitiseForReport` redacts sid/tenantId |
| Task 2 | Overlay opens/closes; `#issue-details` filled; `errorCtx` details present and sanitised; backdrop click closes |
| Task 3 | "Melden" button appears only on unexpected errors; all 3 submission paths work; settings fields persist across reload; validation toasts unchanged; `just e2e` passes |
