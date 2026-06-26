# Plan — app2 Frontend Changes

> Branch: `refactor/202606`  
> Spec: `docs/app2-use-cases.md` (UC4a, UC6, UC13a)  
> Tests: `test/app2_test.js` — run with `just unit`  
> Workflow: RED → GREEN per task (CW5); cite UC + AC IDs in commits

## Status

| ID | Task | Status |
|----|------|--------|
| FA1 | Fix FAB listener (bug: Event passed as editEntry) | DONE |
| FA2 | UC6: remove doubletap handler + fix UC7 toast | DONE |
| FA3 | UC13a: "Bug Melden" button in Settings | DONE |
| FA4 | UC4a: enable tenantAutoCreationEnabled in config | DONE (was already true) |
| FA5 | Docs: update app2-spec.md for UC6/UC13a | DONE |

---

## FA1 · Fix FAB listener — DONE

**File:** `app2.html:1103`

**Root cause:** `addEventListener("click", openBottomSheet)` passed the click Event
as `editEntry`; `editEntry.message` was `undefined`, causing `.replace()` to throw.

**Fix applied:**
```js
// before
document.getElementById("fab").addEventListener("click", openBottomSheet);
// after
document.getElementById("fab").addEventListener("click", () => openBottomSheet());
```

**Verify:** Tap FAB on any tenant → bottom sheet opens with empty textarea.

---

## FA2 · UC6: Remove doubletap handler + fix UC7 toast

**File:** `app2.html`

UC6 deprecated in 0.2.0. Users navigate via UC2 (drill arrow) then add via UC4 (FAB).

### Changes

**1. Remove gesture:doubletap handler** (~lines 900–905):
```js
// DELETE this block entirely:
list.addEventListener("gesture:doubletap", e => {
    const card = e.target.closest(".card");
    if (!card) return;
    navigateTo(card.dataset.fullKey);
    openBottomSheet();
});
```

**2. Update UC7 hint toast** (line ~897) — remove doubletap reference:
```js
// before
showToast("Lange drücken zum Bearbeiten · Doppelklick für Untereinträge", "info", 2000);
// after
showToast("Lange drücken zum Bearbeiten", "info", 2000);
```

### Verify

- Long-press a card → edit sheet opens (UC5 unaffected).
- Single tap a card body → toast shows "Lange drücken zum Bearbeiten" only.
- Double-tap a card → nothing happens (no navigation, no sheet).

---

## FA3 · UC13a: "Bug Melden" button in Settings

**Files:** `app2.html`

Trigger: user taps "Bug Melden" in the Settings panel → `openIssueReport(null)`.
`openIssueReport(null)` already works; `buildReportText(null)` omits the error section.

### Changes

**1. Add button to settings panel HTML** (after the Schließen button, ~line 279):
```html
<button class="settings-btn secondary" id="settings-bug-report">Bug Melden</button>
```

**2. Wire button** (after `closeSettings` listener, ~line 1181):
```js
document.getElementById("settings-bug-report").addEventListener("click", () => {
    closeSettings();
    openIssueReport(null);
});
```

### Verify

- Open Settings → "Bug Melden" button is visible.
- Tap it → settings closes, issue report opens with no error-details section.
- `issue-details` textarea contains state snapshot + action trail but no "Fehlerdetails" block.

---

## FA4 · UC4a: Enable tenant auto-creation

**Files:** `infopedia.cfg` (or create `data/infopedia.cfg` if it's the active config)

Backend already handles this at `entries.php:83`:
```php
if (!file_exists($source_file) && ($tenant_id === '' || ($config['tenantAutoCreationEnabled'] ?? false))) {
```

Condition: `tenantAutoCreationEnabled` must be `true` in config. Default is `false` (safe).

### Change

Add or set in the `[general]` section of `infopedia.cfg`:
```ini
tenantAutoCreationEnabled = true
```

### Verify

```bash
# POST to a tenant that does not exist yet
curl -X POST "http://localhost/entries?sid=test&tid=newtenant" \
     -d "entry=/test/node | Hello from new tenant."
# → 201 Created
curl "http://localhost/entries?tid=newtenant&format=json"
# → entry present, data/newtenant.csv created
```

---

## FA5 · Docs: update app2-spec.md

**File:** `docs/app2-spec.md`

**1. Mark UC6 as deprecated** in Implementation Notes:
```
| UC6 | ~~`gesture:doubletap` → `navigateTo` + `openBottomSheet()`~~ **DEPRECATED 0.2.0** — use UC2+UC4 |
```

**2. Add UC13a row** in Implementation Notes:
```
| UC13a | Settings "Bug Melden" button → `closeSettings()` + `openIssueReport(null)` |
```

**3. Update Test Coverage table** — UC6 row:
```
| UC6 | — | removed; doubletap gesture handler deleted |
```

**4. Add UC13a row** in Test Coverage:
```
| UC13a | (shared with UC13 — no new unit tests needed) | settings button click (needs browser) |
```

### Verify

Read the file — all four edits present, no UC references broken.
