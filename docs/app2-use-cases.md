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
