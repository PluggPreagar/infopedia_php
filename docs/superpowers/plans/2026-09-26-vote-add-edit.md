# Vote Page — Add / Edit Entries Implementation Plan

> **For agentic workers:** execute task-by-task with human checkpoints (CW4), tests RED first (CW5). Design: [`../specs/2026-09-26-vote-page-design.md`](../specs/2026-09-26-vote-page-design.md) (amended in T8). Builds on [`2026-09-26-vote-page.md`](./2026-09-26-vote-page.md).

**Goal:** `vote.html` gets a `?type=fakt|gegenfrage` page mode (config default `[vote_page] type`), a FAB that opens a bottom sheet creating an entry of the page's type with an optional source (URL + name), and an edit sheet (pencil button + long-press) that re-sends every attribute so nothing is lost on the server's newest-row-wins read path.

**Architecture:** No backend change beyond one config key in `config.php`. All new logic lives in `vote.html` as pure helpers (CA6) plus a small sheet controller; the sheet reuses `assets/components.css` (`.overlay-backdrop`, `.bottom-sheet`, `.fab`, `.btn-icon`, `.input`, `.textarea`, `.form-label`); icons via external `<use href="assets/icons.svg#icon-…">`. `generateNodeId()` moves from `app2.html` to `assets/entry-core.js` (pure, CA7).

**Decisions (D9–D12 in the design doc):**
- **D9** Tabs are mode switches; `history.replaceState` (shareable URL, no back-stack, no popstate handler).
- **D10** One source per entry in the sheet (URL + optional name). `|` in the URL → `%7C`; `|` in text/name refused. Multi-link `src` edited verbatim (v1 limitation).
- **D11** Edit via pencil (primary) and long-press (secondary); no tap-hint toast. Edit keeps the type and re-emits all attrs.
- **D12** No delete: `matchType(text,"--")` yields `text.--`, which the server does not treat as a delete marker.

## Global Constraints

- Branch `feature/vote-add-edit` from `feature/vote-page`. Baseline: `just ci` unit 254 / e2e 95; `wrapper.php?test=vote.html` 67 pass; `wrapper.php?test=app2.html` 101 / 1 pre-existing fail.
- CG-DS1..5, CA15/CA16, CA18 (labels only from `TYPE_DEFS`). German copy as in app2.
- JS tests stay microtask-only after `await` (no `setTimeout`) so `init()`'s real fetch cannot interleave (R5).

## File Structure

| File | Change |
|---|---|
| `assets/entry-core.js` | add `generateNodeId()` (moved) |
| `app2.html` | remove `generateNodeId()` body |
| `infopedia.cfg`, `infopedia_template.cfg` | `[vote_page] type = fakt` |
| `config.php` | emit `votePageType` |
| `test/e2e.php` | 2 assertions in `config.php — vote page keys` |
| `vote.html` | mode switch, FAB, sheet, pencil, long-press |
| `test/vote_test.js` | ~90 new assertions |
| design doc, `CHANGELOG.md` | D7 reversed, D9–D12, VUC6–VUC8 |

## Tasks

| # | Task | Bites | Commit |
|---|---|---|---|
| T1 | Move `generateNodeId()` to `entry-core.js` | 1 | `refactor(app2): move generateNodeId to assets/entry-core.js` |
| T2 | `[vote_page] type` → `votePageType` (e2e RED → GREEN) | 2 | `feat(cfg): vote_page type surfaced by config.php as votePageType` |
| T3 | Pure-helper tests RED (`modeFromParam`, `paramFromMode`, `stripTypeSuffix`, `parseSourceMd`, `buildSourceMd`, `validateSheetInput`, `buildEntryLine`) | 2 | `test(vote): failing tests for page mode, source fields and entry line helpers` |
| T4 | Pure helpers GREEN in `vote.html` | 2 | `feat(vote): pure helpers for page mode, source fields and entry line` |
| T5 | DOM tests RED (sheet open add/edit, submit stubbed via `postEntryLine`, failure keeps sheet, edit round-trips `src`+attrs, `applyMode`) | 3 | `test(vote): failing DOM tests for add/edit sheet and page mode` |
| T6 | Markup + CSS: FAB (hidden until loaded), sheet (`.overlay-backdrop` + `.bottom-sheet`), pencil `.edit-btn`, load `gesture.js` | 2 | (with T7) |
| T7 | Controller: `applyMode`, `openSheet/closeSheet/submitSheet`, `postEntryLine`, `freshNodeId`, long-press delegation, Escape | 4 | `feat(vote): add and edit entries via bottom sheet; ?type= page mode` |
| T8 | Docs + changelog | 2 | `docs(vote): add/edit design amendments D9–D12, VUC6–VUC8, changelog` |
| T9 | S5: `just ci`, both browser suites, manual desktop + mobile, review | 2 | — |
| | **Total** | **20 ≈ 60–100 min** | |

## Key contracts (pure helpers)

| Function | Contract |
|---|---|
| `modeFromParam(v)` | `fakt`→`!`, `gegenfrage`→`??` (case-insensitive), else `""` |
| `paramFromMode(s)` | inverse; unknown → `""` |
| `stripTypeSuffix(m)` | strips exactly the suffix `getTypeFromMessage` finds — `Ist das so?!` → `Ist das so?` |
| `parseSourceMd(v)` | `[N](u)` → `{url:u,name:N}`; bare → `{url,name:""}`; several links / text → verbatim in `url` |
| `buildSourceMd(url,name)` | `[name](url)` or bare url; `\|`→`%7C`; `[`/`]` dropped from name; markdown passes verbatim |
| `validateSheetInput(t,u,n)` | `""` or plain message: `< 3` chars, `\|` in text/name, url not `https?://` (unless markdown) |
| `buildEntryLine(key,text,suffix,attrs,url,name)` | `key \| <other attrs> \| src:… \| matchType(text,suffix)` — re-emits every non-src attr |

## Verification

- RED/GREEN per task via `wrapper.php?test=vote.html` and `just e2e`.
- Manual (T7/T9): `?type=gegenfrage` opens on that tab; tab click rewrites URL; FAB appears after load; add with URL+name → card with link and `src:[…](…)` row in `data/entries_frueher.csv`; pencil → pre-filled sheet; save → new CSV row same path, `src:` intact; long-press opens sheet; Escape closes; mobile preset: sheet scrolls above keyboard.

## Risks

| # | Risk | L | I | Mitigation |
|---|---|---|---|---|
| R1 | src lost on edit (newest row wins, `util_entry.php` sortCsvData) | High w/o design | High | `buildEntryLine` always re-emits src; tests |
| R2 | other attrs lost on edit | Med | Med | re-emit every non-src attr; test |
| R3 | `\|` in URL/text/name splits columns | Low | High | `%7C` in URL; refuse in text/name |
| R4 | XSS via source fields | Low | High | href via `safeUrl`, label via `esc`, prefill via `.value` |
| R5 | init() race / late `loadData` overwrites | Med | Med | FAB hidden until loaded; tests microtask-only |
| R6 | nodeId collision | V.low | Med | `freshNodeId` re-rolls on hit |
| R7 | sheet on mobile keyboard | Med | Low-Med | `max-height: 90dvh; overflow-y: auto`; safe-area padding in `.bottom-sheet` |
| R8 | `--` delete trap | n/a | High if built | not built (D12) |
| R9 | app2 UC4/UC5 suites use stale `.bs-type-chip` selector | existing | Low | out of scope, noted in CHANGELOG |
| R10 | CA14: server logs only filename on POST | Low | Med | sheet keeps text on failure; console has the line; backend follow-up |
| R11 | tenant auto-create on typo tid | Low | Low | `TID_RE` guard; accepted (CD2) |
| R12 | 429 throttle on POST | Low | Low | mapped to plain message; sheet stays open; submit disabled in flight |
| R13 | `matchType` replaces a typed trailing type char (`Warum?` in Fakt mode → `Warum!`) | Med | Low | same as app2; documented |
| R14 | external `<use href="assets/icons.svg#…">` unsupported (very old Safari) | V.low | Low | aria-labels remain; fallback inline symbols |
