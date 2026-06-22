# app2 Use Cases

## Overview

app2.html is a mobile-first single-page wiki client for InfoPedia.
It enables collaborative, topic-hierarchical knowledge collection through typed entries,
voting, and a confirmation system.

Technical implementation details live in [`docs/app2-spec.md`](./app2-spec.md).

## Entry Types

| Label | Icon | Meaning |
|-------|------|---------|
| Meinung | fa-comment | Subjective opinion |
| Fakt | fa-circle-check | Claimed fact |
| Fake | fa-circle-xmark | Identified misinformation |
| Unklar | fa-circle-question | Unclear or needs clarification |
| Gegenfrage | fa-right-left | Counter-question |
| Thema | fa-folder-open | Sub-topic (navigates into a child topic) |

## Use Cases

### UC1: Browse root entries
**Trigger:** User opens the app

- **AC1.1** — Entries at the root level appear without any user action.
- **AC1.2** — New entries added by other users appear automatically, without a page reload.

### UC2: Navigate into a topic
**Trigger:** User taps the drill arrow on a topic card

- **AC2.1** — The view switches to show only entries belonging to that topic.
- **AC2.2** — The nav bar shows the current topic path.
- **AC2.3** — A back button appears, labeled with the parent topic name (or the site name at root).

### UC3: Navigate back
**Trigger:** User taps the back button or the browser back button

- **AC3.1** — The view returns to the parent topic.
- **AC3.2** — Both the in-app back button and the browser back button work.
- **AC3.3** — At root, the topic path indicator is empty.

### UC4: Add a new entry
**Trigger:** User taps the "+" button

- **AC4.1** — The "+" button is always visible while browsing.
- **AC4.2** — Tapping it opens a sheet with an empty text field and "Meinung" pre-selected.
- **AC4.3** — The user can select a different entry type before submitting.
- **AC4.4** — Submitting without text is blocked.
- **AC4.5** — After submitting, the new entry appears immediately without a page reload.
- **AC4.6** — Adding an entry inside a topic that does not yet exist creates a placeholder topic card at the parent level.

### UC5: Edit an existing entry
**Trigger:** User holds a card for ≥ 500 ms (long-press)

- **AC5.1** — An edit sheet opens pre-filled with the entry's current text and type.
- **AC5.2** — The sheet heading indicates edit mode ("Eintrag bearbeiten").
- **AC5.3** — After submitting, the card updates immediately without a page reload.

### UC6: Add a sub-entry via double-tap
**Trigger:** User double-taps a card

DEPRECATED: use UC2 (navigate into a topic) + UC4 (add entry) instead for a clearer, more consistent UX.

- **AC6.1** — The app navigates into that card's topic.
- **AC6.2** — The add-entry sheet opens immediately at the new topic.

### UC7: Single-tap hint
**Trigger:** User taps a card body (not a button)

- **AC7.1** — A hint toast appears explaining long-press (edit) and double-tap (sub-entry).
- **AC7.2** — No navigation occurs and no sheet opens.

### UC8: Drill into a topic via the arrow
**Trigger:** User taps the "›" arrow on a topic card

- **AC8.1** — The app navigates into that topic (same outcome as UC2).

### UC9: Vote on an entry
**Trigger:** User taps ▲/▼ or swipes a card left/right

- **AC9.1** — Tapping ▲ increases the vote count immediately.
- **AC9.2** — Tapping ▼ decreases the vote count immediately.
- **AC9.3** — Swiping right counts as an upvote; swiping left as a downvote.
- **AC9.4** — Rapid repeated gestures on the same entry are counted only once (1 s debounce).

### UC10: Confirm (sign) an entry
**Trigger:** User taps "Bestätigen" on a card

- **AC10.1** — The confirmation count on the card increases immediately.
- **AC10.2** — An entry with ≥ 2 confirmations is marked "Bewiesen ✓".

### UC11: Search entries
**Trigger:** User taps the search icon and enters text

- **AC11.1** — The search bar opens and entries filter in real time as the user types.
- **AC11.2** — Three scope options are available in order: **Global** · **Hier** · **Darunter**.
- **AC11.3** — "Darunter" is the default scope, covering the current topic and its subtopics.
- **AC11.4** — "Hier" limits results to the current topic only.
- **AC11.5** — "Global" searches across all topics regardless of the current position.

### UC12: Change tenant
**Trigger:** User opens Settings and changes the Tenant ID

- **AC12.1** — Applying a new tenant ID reloads the app with that tenant's data.
- **AC12.2** — The URL reflects the active tenant.

### UC13: Report a bug
**Trigger:** User taps "Melden" on an error toast

- **AC13.1** — An issue panel opens, pre-filled with the last user actions and current app state.
- **AC13.2** — The user can review, edit, and remove any field before submitting.
- **AC13.3** — Sensitive session identifiers are redacted automatically.
- **AC13.4** — Submission options: copy to clipboard, open GitHub issue, send via email.
- **AC13.5** — Nothing is sent without explicit user confirmation.

### UC14: Real-time updates
**Trigger:** Another user adds or edits an entry on the same tenant

- **AC14.1** — New or updated entries appear in the current view without any user action.
- **AC14.2** — The app reconnects automatically after an idle period.

### UC15: Toggle type display mode
**Trigger:** User opens Settings and changes "Typen-Anzeige"

- **AC15.1** — Three modes are available: **Text** (default) · **Icon + Text** · **Nur Icon**.
- **AC15.2** — The selected mode applies to type badges on cards and to all type chips.
- **AC15.3** — The choice persists across page reloads.
