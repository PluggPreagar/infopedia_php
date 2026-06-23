# app2 Technical Specification

Business use cases and acceptance criteria live in [`docs/app2-use-cases.md`](./app2-use-cases.md).

## Core Concepts

| Concept | Description |
|---------|-------------|
| **Topic** | A hierarchical path like `/climate/solutions`. Root is `/`. |
| **Entry** | A single contribution at a topic with a type suffix and optional votes. |
| **SID** | Session identifier stored in `localStorage`. Ties votes and settings to a session. |
| **TID** | Tenant ID (`?tid=demo`). Entries are isolated per tenant; data lives in `data/<tid>.*`. |
| **Long-poll** | Client keeps a connection open to `/entries?since=…`; server holds up to 50 s, sends 204 on timeout. |

## Entry Type Suffixes

| Suffix | Label | CSS class | Icon (FA6) |
|--------|-------|-----------|------------|
| `.` | Meinung | meinung | fa-comment |
| `!` | Fakt | fakt | fa-circle-check |
| `!-` | Fake | fake | fa-circle-xmark |
| `?` | Unklar | unklar | fa-circle-question |
| `??` | Gegenfrage | gegenfrage | fa-right-left |
| `>` | Thema | thema | fa-folder-open |

## State Variables

| Variable | Type | Description |
|----------|------|-------------|
| `data` | `{topic: {nodeId: entry}}` | All loaded entries keyed by topic then nodeId |
| `votesData` | `{"/topic/node": {votes, signed}}` | Vote and sign counts |
| `selectedTopic` | `string` | Currently viewed topic path |
| `latestTimestamp` | `string\|null` | Last known server timestamp for long-poll `?since=` |
| `searchScope` | `"below"\|"here"\|"global"` | Active scope filter |
| `activeTypes` | `Set<string>` | Which type suffixes are currently shown |
| `typeDisplayMode` | `"text"\|"icon+text"\|"icon"` | How type labels render in chips and badges |
| `actionTrail` | `Array` | Last N user actions, capped at `ACTION_TRAIL_MAX` (used by UC13) |

## Implementation Notes per UC

| UC | Key functions / endpoints |
|----|--------------------------|
| UC1 | `loadInitialData()` — parallel fetch of `buildEntriesUrl()` + `buildVotesUrl()`; `startLongPoll()` |
| UC2 | `navigateTo(path)` → sets `selectedTopic`, updates `#nav-topic`, pushes `history.pushState` |
| UC3 | `navigateTo(parentTopic)` on back-tap; `popstate` event reads `?topic=` param |
| UC4 | `openBottomSheet()` (no arg = new mode); `submitEntry()` POSTs to `/entries?sid=…&tid=…`; stub topic via `checkData()` |
| UC5 | `openBottomSheet(entry)` (with arg = edit mode); `gesture:longpress` (≥ 500 ms); suffix stripped from textarea |
| UC6 | ~~`gesture:doubletap` → `navigateTo` + `openBottomSheet()`~~ **DEPRECATED 0.2.0** — handler removed; use UC2 + UC4 |
| UC7 | `gesture:tap` → toast only |
| UC8 | drill-arrow click → `navigateTo(card.fullKey)` |
| UC9 | `addVoteByGui()` (optimistic); `gesture:swipe` (≥ 50 px); `debounceKey(key, 1000)`; server sync via `addVotesData()` |
| UC10 | `signEntry()` POSTs `signed:<sid>:1` to `/votes`; `getSignedCount()` |
| UC11 | `getFilteredEntries()` — scope + type filter + text search; `>` type always shown regardless of `activeTypes` |
| UC12 | `applySettings()` — clears data and poll, reloads; `loadSettings()`/`saveSettings()` via `localStorage` |
| UC13 | `pushAction()`, `buildStateSnapshot()`, `sanitiseForReport()`, `buildReportText()`, `buildFullReport()` |
| UC13a | Settings "Issue melden" button → `closeSettings()` + `openIssueReport(null)` |
| UC14 | Long-poll: server 204 → immediate re-poll; server 200 → `addData()` + `updateView()` |
| UC15 | `typeDisplayMode` → `updateTypeDisplay()`; persisted in `localStorage` via `saveSettings()` |

## Test Coverage

| UC | Unit tests | Gap (needs browser) |
|----|------------|---------------------|
| UC1 | `testAddData`, `testBuildEntriesVotesUrl` | fetch/poll lifecycle |
| UC2 | `testNavigateTo`, `testInitializeTopicMap` | — |
| UC3 | `testNavigateTo`, `testNavTopic` | popstate wiring |
| UC4 | `testOpenBottomSheetNewMode`, `testAddEntryWoCheck`, `testCheckData`, `testRequireTopicFlag` | `submitEntry` incl. AC4.4 guard (needs fetch mock) |
| UC4a | — (AC4a.7 is backend-only; frontend flow identical to UC4) | tenant auto-creation (needs live server) |
| UC5 | `testOpenBottomSheetEditMode`, `testBottomSheetSuffixStripping` | `gesture:longpress` |
| UC6 | — | removed (deprecated 0.2.0) |
| UC7 | — | `gesture:tap` |
| UC8 | — | drill-arrow click |
| UC9 | `testAddVoteByGui`, `testSetVoteByOthers`, `testAddVotesData`, `testDebounceKey` | `gesture:swipe` |
| UC10 | `testGetSignedCount`, `testAddVotesData`, `testBuildCardVerified` | sign button click needs browser |
| UC11 | `testGetFilteredEntries`, `testScopeChips` | — |
| UC12 | `testLoadSaveSettings` | `applySettings` flow |
| UC13 | `testSanitiseForReport`, `testBuildStateSnapshot`, `testBuildReportText`, `testBuildFullReport`, `testPushAction` | — |
| UC13a | (shared with UC13 — no new unit tests needed) | settings button click (needs browser) |
| UC14 | `testAddData` | poll lifecycle |
| UC15 | `testGetTypeDef` (iconClass) | `updateTypeDisplay` DOM |
