# Design: app2 Dev Issue Tracker

**Date:** 2026-06-23  
**Scope:** Standalone PHP issue management page + Bug Melden dialog adjustment  
**Status:** Approved

---

## Overview

A local developer tool for managing issues submitted via app2's "Bug Melden" dialog.
Issues move through states stored as folders on disk. A single `issues.php` page
provides three views: overview, detail, and state transition.

No authentication, no framework, no Composer. Plain procedural PHP (CP1).

---

## States

```
new → ready → inProgress → inReview → closed
         ↘ blocked ↗              ↘ inProgress (reopen)
         canceled
```

Seven states: `new`, `ready`, `blocked`, `inProgress`, `inReview`, `canceled`, `closed`.

Backend allows any transition. UI shows only the usual buttons per current state (see Detail view).

---

## Data Model

### Folder structure

```
data/issues/new/
data/issues/ready/
data/issues/blocked/
data/issues/inProgress/
data/issues/inReview/
data/issues/canceled/
data/issues/closed/
```

### File naming

`YYYY-MM-DD_HH-MM-SS_<uniqid>.txt` — timestamp prefix for natural sort, uniqid for
uniqueness. The filename is the stable ID as the file moves between state folders.

### File format

```
Titel: <one-line title>
Zeit:  2026-06-23T14:30:12Z
Version: 0.3.1

--- Nutzerbeschreibung ---
<free text from user>

--- Letzte Aktionen ---
14:29:55  navigateTo: /thema/foo
...

--- Zustand ---
<buildStateSnapshot() output>

--- Fehlerdetails ---
Fehler: fetch failed
Status: 0
URL:    /entries.php
Detail: TypeError: failed to fetch

--- Verlauf ---
[2026-06-23 14:35:22] → ready
[2026-06-23 16:10:05] → inProgress
```

- `Titel:` is always line 1 — overview parsing reads only this line.
- `--- Fehlerdetails ---` section is omitted when no error context (manual report).
- `--- Verlauf ---` section is appended on first transition; subsequent transitions add one line each.

---

## Views

### Overview — `GET issues.php`

Lists all issues in `new/` and `ready/` folders, sorted newest first (filename sort descending).

```
[ new ]                            [ ready ]
2026-06-23 14:30  Login crash      2026-06-22 09:11  Scroll broken
2026-06-23 11:05  Dark mode bug
```

Each row is a link to the detail view. No pagination.

### Detail — `GET issues.php?id=<filename>`

- Shows full file content.
- Shows current state as a badge (resolved by globbing `data/issues/*/<id>`).
- Shows transition buttons for usual next states (current state excluded):

| Current state | Usual transition buttons |
|---|---|
| new | ready, blocked, canceled |
| ready | inProgress, blocked, canceled |
| blocked | ready, canceled |
| inProgress | inReview, blocked |
| inReview | closed, inProgress |
| canceled | *(none)* |
| closed | *(none)* |

Each button is a `<form method="POST" action="issues.php?id=<id>&set=<state>">`.

Back link to overview at top.

### Transpose — `POST issues.php?id=<filename>&set=<state>`

1. Glob `data/issues/*/<id>` to find current path.
2. Append `[<timestamp>] → <state>` to the file under `--- Verlauf ---`
   (create section if not present).
3. `rename()` file to `data/issues/<state>/<id>`.
4. `header('Location: issues.php')` — redirect to overview.

No view rendered. Backend accepts any valid state name, no transition guards.

---

## Bug Melden Dialog Changes (app2.html)

Add a required `Titel` single-line input above the existing `Nutzerbeschreibung` textarea:

```
[ Titel                                  ]  ← required, single-line
[ Beschreibung (optional)                ]  ← existing textarea
```

`buildFullReport()` prepends the title as the first line:

```js
return "Titel: " + titel.trim() + "\n" + existingReport;
```

Submission is blocked (button disabled or validation message) when Titel is empty.

`buildReportText()` drops the `=== Fehlerbericht ===` header line — `Titel:` on line 1
replaces it as the report identifier. `Zeit:` and `Version:` move up to lines 2–3.

`issue.php` unchanged — title is part of the existing `report` POST param.

---

## Implementation Scope

| File | Change |
|---|---|
| `issues.php` | New file — all three views in one PHP file |
| `app2.html` | Add Titel field to Bug Melden overlay; update `buildFullReport()` |
| `issue.php` | No change (title is part of the `report` param) |
| `data/issues/new/` | Create folder (or create on first use) |

Out of scope: authentication, search, filtering beyond state, pagination.
