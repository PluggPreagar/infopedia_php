# Changelog

## [0.3.0] — 2026-06-23

### Added
- **`issues.php`**: standalone developer issue tracker — folder-per-state storage (`data/issues/{new,ready,blocked,inProgress,inReview,canceled,closed}/`), overview (new + ready), detail view with state badge, state-transition buttons (POST), Verlauf log appended on each transition
- **`md-renderer.js`**: pure JS `renderMd(text)` function — no DOM dependency, no libraries; supports `#`/`##` headings, `**bold**`, `` `inline code` ``, fenced code blocks, `- lists`, `![alt](src)` images, `[text](url)` links, GFM tables; blocks `javascript:` and `data:` URLs
- **Markdown bug reports**: `app2.html` Bug Melden dialog now emits `.md` files — `# Title` on line 1, `## Section` headings, fenced code block for state snapshot, `**bold:**` labels for error details; `issues.php` renders them via `md-renderer.js`
- **Titel field** in Bug Melden dialog — required, send button disabled until filled
- **`issue.php`** POST endpoint — saves bug reports to `data/issues/new/` as timestamped files
- **`deploy.php`** (was `upgrade.php`) — self-update endpoint, pulls ZIP from GitHub and overwrites matching files; persists commit SHA/time to `version.json`; supports exclude pattern list in `[upgrade]` cfg section
- **`.gitignore`** — excludes `.ai/` directory

### Fixed
- `entries.php`: long-poll now returns `204` on cache-hit with no new entries since `?since=`; `poll_timeout` configurable via `infopedia.cfg`
- `entries.php`: path traversal guard, cfg leak guard, mkdir race fix
- `app2.html`: 64 KB payload cap on bug reports; fixed duplicate test function name

### Refactored
- `app2.html`: removed configurable report URLs from settings; fixed preset targets (GitHub Issues + bugs@fayf.info)

## [0.2.0] — 2026-06-19

### Added
- **Gesture detector** (`gesture.js`): reusable pointer-event layer; fires `gesture:tap`, `gesture:doubletap`, `gesture:longpress`, `gesture:swipe` as bubbling CustomEvents on any container
- **`initCardList()`**: single function wiring all card-list interactions via delegated gesture events; works for dynamically added/removed cards without rebinding
- **Long-press to edit** (UC5): hold a card ≥ 500 ms to open the bottom sheet pre-filled with existing text and type
- **Double-tap to add sub-entry** (UC6): double-tap a card to navigate into its topic and open a new-entry sheet
- **Single-tap hint** (UC7): single tap shows a toast explaining the gestures
- **Swipe to vote** (UC9): swipe right = +1, swipe left = −1 (now via pointer events, works on desktop too)
- **`#nav-topic` span** (UC3): nav bar shows the current topic path; clears at root
- **`popstate` handler** (UC3): browser back/forward syncs `selectedTopic` from URL `?topic=` param
- **Type display mode toggle** (UC15): Settings selector switches chips and badges between Text / Icon + Text / Nur Icon; persisted in localStorage
- **Font Awesome icons** on type chips, bottom-sheet chips, and card badges
- **Scope chips reordered** Global → Hier → Darunter with German labels and FA icons (UC11)
- **Use-case document** (`docs/app2-use-cases.md`): UC1–UC15 with coverage table

### Fixed
- `entries.php`: cache-hit path now returns `204 No Content` when `?since=` filtering produces no new entries (was returning `200` with empty body, crashing the JS long-poll parser)
- `entries.php`: `_filter_since()` grace-period guard now compares only the timestamp prefix (`substr($line, 0, 19)`) — was comparing the full CSV row, making the guard a no-op
- `entries.php`: both fallback paths (missing source file, stale cache) now return `204` on empty `?since=` output
- `app2.html` `safeJson()`: guards against empty response body before calling `JSON.parse`
- `app2.html` `buildCard()`: `data-key` attributes on vote/sign buttons now passed through `escapeHtml()` (XSS fix)

### Refactored
- Card gesture handling unified: removed per-card `touchstart`/`touchend` binding (`addSwipeHandlerToCard`, `__swipeBound`) and five scattered pointer-event globals; replaced with `initGestureDetector` + `initCardList`
- `test/app2_test.js`: all suites wrapped in named functions for independent readability

### Tests
- Added GUI test suites: bottom sheet edit mode, new mode, suffix stripping, scope chip order/labels, nav-topic path display
- Added UC cross-reference comments to every test function

## [0.1.0] — 2026-06-17

Initial app2 release.
