# Vote Page (`vote.html`) — Design Spec

**Date:** 2026-09-26
**Branch target:** `feature/vote-page`
**Affects:** `vote.html` (new), `test/vote_test.js` (new), `assets/entry-core.js` (new), `app2.html`, `config.php`, `infopedia.cfg`
**Backend changes:** none
**Status:** awaiting validation (CW1)

---

## 1. Goal

A standalone page where visitors read the arguments collected under one topic folder and
vote on them — and, in a second list, read and vote on the counter-questions
(*Gegenfragen*) for the same folder. Sources are shown as links so a reader can check a
claim before voting.

Vote + confirm only. Adding and editing entries stays in `app2.html`.

---

## 2. Decisions from brainstorming (CW1)

| # | Decision | Rationale | IDs |
|---|----------|-----------|-----|
| D1 | Standalone `vote.html`, no new PHP route | Reuses `GET/POST /entries` + `/votes` unchanged; zero risk to `app2.html` | CA1, CA7, CP2 |
| D2 | Arguments are entries whose message ends in `!` (**Fakt**) | Chosen over `.` — the list is evidence-backed claims | CD3 |
| D3 | Gegenfragen are entries whose message ends in `??` | Existing type, existing blue badge | CA18 |
| D4 | Use the **defined** design-system layout, badges and labels (`Fakt`, `Gegenfrage`) | No new vocabulary, no rename of `.`/`!` anywhere | CA18, CG-DS1 |
| D5 | Source stored as `src:` attribute whose value is **markdown link(s)** `[Name](url)`; a bare URL is tolerated | Name + URL in one field; several sources per entry; `[ ]( )` are unambiguous where `:` is not; parses today | CC2, CD1, CA4 |
| D6 | Markdown links inside the message are additionally rendered | `renderMd()` already exists and sanitises | CA7 |
| D7 | Vote + confirm only; no add, no edit | Smallest surface for v1 | CA1, CA8 |
| D8 | Tenant and topic come from config, overridable per URL | No hardcoding | CC2 |

---

## 3. Data contract

Nothing new on the wire. An argument with a source is an ordinary entry:

```
/frueher/zuckerkonsum | src:[DGE Ernährungsbericht 2021](https://www.dge.de/bericht-2021) | Der Zuckerkonsum ist seit 1970 um 40% gestiegen!
```

Several sources — same attribute, space-separated, nothing new to parse:

```
/frueher/zuckerkonsum | src:[DGE 2021](https://www.dge.de/b21) [Destatis](https://www.destatis.de/z) | Der Zuckerkonsum ist seit 1970 um 40% gestiegen!
```

A bare URL is accepted and labelled by its host (`src:https://www.dge.de/b21` → "dge.de").

Rejected shapes, and why: `src:<name>:<url>` — the URL itself contains `:` so the split is
ambiguous; `src_<name>:<url>` — the attr key regex is `[a-zA-Z_]+`, so a name with digits,
spaces or umlauts fails to parse and the column is dropped; a second `src:` column — attrs
is a map, the first value is silently overwritten.

A Gegenfrage:

```
/frueher/zuckerkonsum | War die Messmethode 1970 vergleichbar??
```

A vote and a confirmation, exactly as `app2.html` sends them today:

```
POST /votes   entry=/frueher/zuckerkonsum | votes:<sid>:1  | <message>
POST /votes   entry=/frueher/zuckerkonsum | signed:<sid>:1 | <message>
```

### Why `src:` needs no backend change

`parseEntry()` matches middle columns with `^([a-zA-Z_]+):(.+)$` — the key is the part
before the **first** colon, the value is everything after (`util_entry.php:64`).
`src:[Name](https://…)` therefore yields `attrs['src'] = '[Name](https://…)'` today —
`[`, `]`, `(`, `)` and the `:` inside the URL are all plain value characters. `csv_to_json()`
already emits `attrs` verbatim (`util_format.php:112`), so the markdown reaches the client
for free, and `renderMd()` turns it into sanitised `<a>` tags — one per link.

**The one constraint:** a `src` value must not contain `|`, the column separator. URLs
normally do not. A `|` in a URL must be percent-encoded as `%7C` — documented, not enforced
in v1.

### Why type detection happens client-side

The backend reduces the type to the **last single character**, so a message ending in `??`
is stored with `type = '?'` (`util_entry.php:47`). `!-` (Fake) likewise ends in `-`.
Distinguishing `??` from `?` and `!` from `!-` is therefore only possible on the message
suffix — which is exactly what `getTypeFromMessage()` does (`app2.html:398`). `vote.html`
reuses that function rather than the backend `type` field.

---

## 4. What is reused, what is new

| Concern | Source | New? |
|---------|--------|------|
| Colors, spacing, type tokens | `assets/design-tokens.css` | reuse |
| Card, badge, chip, button styles | `assets/components.css` | reuse |
| Markdown + safe-URL rendering | `assets/md-renderer.js` | reuse |
| `fullKey` `splitKey` `getTypeFromMessage` `matchType` `escapeHtml` `TYPE_DEFS` `TYPE_DEF_DEFAULT` `getTypeDef` | **extract** to `assets/entry-core.js` | new file, moved code |
| `.card-text` `.sign-count` `.sign-btn` `.ts-label` (card internals, currently inline in `app2.html`) | **move** to `assets/components.css` | moved CSS |
| Entry + vote fetch, vote/sign POST | shape copied from `app2.html`, ~25 lines | new |
| Filter / sort / source-link logic | pure functions in `vote.html` | new |
| Test harness | `wrapper.php?test=vote.html` → `test/vote_test.js` | reuse |

### The `assets/entry-core.js` extraction

CA7 forbids copy-paste sharing. The pure key/type helpers **and the type vocabulary**
(`TYPE_DEFS` — the closed set CA18 says must be defined in one place) are needed by both
pages, so they move out of `app2.html` into `assets/entry-core.js`, which both pages load
before their own script. The card-internal CSS rules that only exist in app2's inline
`<style>` move to `assets/components.css` for the same reason. `app2.html` keeps behaving
identically; `test/app2_test.js` already covers the moved functions
(`test/app2_test.js:107`) and acts as the regression guard. Two things noted while moving,
neither changed silently: `.sign-btn` has no 44 px minimum (CG-DS5) — it gets one as part of
becoming a shared component; and `badge-bewiesen` has no CSS anywhere, so "Bewiesen ✓" on
the vote page uses `badge-fakt` (same green family) rather than an unstyled badge.

Baseline of `test/app2_test.js` before the extraction: 101 pass, 1 pre-existing fail
(`has prefix label`, issue-report builder — unrelated, flagged as its own task). The
regression check after the extraction is: same 101 pass, same single fail, nothing new.

*Alternative if you want `app2.html` untouched:* duplicate the ~20 lines into `vote.html`
and accept the CA7 deviation. Say so and I will switch.

---

## 5. Page structure

```
┌─ header ──────────────────────────────────────────────┐
│  Früher                       [ Argumente | Gegenfragen ]│   ← two tabs
├───────────────────────────────────────────────────────┤
│  ┌─ card ──────────────────────────────────────┐      │
│  │ [Fakt]                              ▲ 12 ▼  │      │
│  │ Der Zuckerkonsum ist seit 1970 …            │      │
│  │ 🔗 Quelle: example.org                      │      │
│  │ [ Bestätigen ]                   Bewiesen ✓ │      │
│  └─────────────────────────────────────────────┘      │
│  … sorted by score, descending                        │
└───────────────────────────────────────────────────────┘
```

- **Tabs** — "Argumente" and "Gegenfragen". These are section names, not type labels; the
  badges on the cards stay `Fakt` and `Gegenfrage` (D4).
- **Badges** — `.badge-fakt` (green) and `.badge-gegenfrage` (blue `#2196F3`, the documented
  CG-DS1 exception).
- **Source line** — rendered only when `attrs.src` is present: `normaliseSourceMd()` then
  `renderMd()`. Link text is the markdown name; a bare URL shows its host. Several links
  render inline. Icon `fa-link`, reusing the existing `@ = Quelle` vocabulary from `TYPE_DEFS`.
- **Sort** — vote score descending, ties broken by timestamp descending.
- **Touch targets** — ▲ ▼ and *Bestätigen* get `min-height: 44px` (CG-DS5).
- **Focus** — `:focus-visible` + `var(--focus-ring)`, never `outline: none` (CG-DS4).
- **Spacing / type** — `--space-*` and `--text-*` tokens only (CG-DS2, CG-DS3).

---

## 6. Use cases

### VUC1: Read the argument list
- **AVC1.1** — Opening `vote.html` shows all `!`-entries under the configured topic folder.
- **AVC1.2** — Each card shows its text, its `Fakt` badge and its current score.
- **AVC1.3** — An entry with a `src:` attribute shows a source link; one without shows none.
- **AVC1.4** — Cards are ordered by score, highest first.
- **AVC1.5** — Entries outside the configured folder never appear.

### VUC2: Read the Gegenfrage list
- **AVC2.1** — The "Gegenfragen" tab shows all `??`-entries under the same folder.
- **AVC2.2** — `?` (Unklar) entries do **not** appear in that list.

### VUC3: Vote
- **AVC3.1** — Tapping ▲ raises the visible score immediately, before the server replies.
- **AVC3.2** — Tapping ▼ lowers it immediately.
- **AVC3.3** — Repeated taps within 1 s on the same entry count once.
- **AVC3.4** — A failed vote shows a plain-language message and logs the detail to console (CA15, CA16).

### VUC4: Confirm
- **AVC4.1** — Tapping *Bestätigen* raises the confirmation count immediately.
- **AVC4.2** — An entry with ≥ 2 confirmations is marked "Bewiesen ✓".

### VUC5: Choose folder and tenant
- **AVC5.1** — `?tid=` and `?topic=` override the configured defaults.
- **AVC5.2** — Without them the configured defaults are used.
- **AVC5.3** — An invalid `tid` shows a plain-language error, not a status code (CA15).

---

## 7. Config keys (CC2, REQ-S2-4)

New `infopedia.cfg` section:

```ini
[vote_page]
tid   = frueher
topic = /frueher
```

`config.php` — which already serves frontend config as JSON — gains two keys:

```php
$vote = $ini['vote_page'] ?? [];
'votePageTid'   => $vote['tid']   ?? '',
'votePageTopic' => $vote['topic'] ?? '/',
```

Same two keys go into `infopedia_template.cfg` with empty values.

> **Assumption to confirm:** the dedicated tenant is also named `frueher`. You said
> "dedicated tenant" and "folder frueher" separately, so the tenant name may differ —
> it is one config line either way.

---

## 8. Testable algorithms (CA6, REQ-S2-8)

Pure, side-effect-free, all in `vote.html`, all directly unit-testable:

| Function | Contract |
|----------|----------|
| `isUnderTopic(key, topic)` | `'/frueher/x', '/frueher'` → `true`; `'/frueherX/y'` → `false` |
| `voteListFor(entries, topic, suffix)` | filters by folder + exact type suffix (via `getTypeFromMessage`), returns `[{key, message, timestamp, attrs}]` |
| `sumVotes(votes)` | sums a `{sid: n}` object; a bare number passes through; anything else → `0` |
| `sortByScore(list, votesData)` | score desc, timestamp desc as tiebreak, stable; does not mutate |
| `normaliseSourceMd(value)` | markdown passes through unchanged; a bare URL becomes `[host](url)`; `'https://www.example.org/a'` → `'[example.org](https://www.example.org/a)'`; empty/missing → `''` |
| `scoreOf(votesData, key)` | sums the vote object, missing key → `0` |

Rendering the normalised value goes through `renderMd()` unchanged, so the
`javascript:` / `data:` scheme guard in `safeUrl()` (`assets/md-renderer.js`) applies to
attribute-supplied URLs exactly as to message text — untrusted input never becomes an
unsafe `href`. The test in §10 asserts that guard end-to-end.

---

## 9. Compatibility (REQ-S2-5)

| Area | Impact |
|------|--------|
| `data/<tid>.csv` / `.cache` / `.log` | none — no format change (CD1) |
| `?format=` outputs | none — no route touched (CD3) |
| Tenant isolation | none — `vote.html` is one more `?tid=` consumer (CD2) |
| `infopedia.html` | not touched (CD4) |
| `app2.html` | four functions move to `assets/entry-core.js`; no user-facing change, so no `docs/app2-use-cases.md` change needed (CD5) |
| `parseEntry()` / `formatEntry()` | not modified (CP3) |

---

## 10. Test plan (RED first, REQ-S3-1)

1. **`test/vote_test.js`** — new, run via `wrapper.php?test=vote.html`, using
   `suite()` / `assert()` from `test/harness.js`. Covers every function in §8 plus the
   `??` vs `?` and `!` vs `!-` suffix boundaries, two links in one `src` value, and
   `src:javascript:alert(1)` rendering to `href="#"`. Written and watched failing first.
2. **`test/util_entry_test.php`** — extend with a `src:` round-trip case asserting
   `parseEntry('/frueher/x | src:[A](https://a.example.org/p?q=1) [B](https://b.example.org) | Text!')`
   yields `attrs['src'] === '[A](https://a.example.org/p?q=1) [B](https://b.example.org)'`
   and `type === '!'`. This locks in the
   behaviour §3 depends on, so a future attr-parser change cannot silently break the page
   (REQ-S3-3, defense-in-depth per CC1.6).
3. **`test/app2_test.js`** — unchanged, and must stay green after the extraction (REQ-S5-3).
4. **`just ci`** — full suite green before merge (REQ-S5-2).

---

## 11. SemVer and commits (REQ-S2-6)

New page, backward compatible → **MINOR**.

```
feat(vote): standalone voting page for arguments and Gegenfragen
refactor(app2): extract entry helpers to assets/entry-core.js
test(vote): unit tests for filter, sort and source-link helpers
test(entry): lock src: attribute round-trip
feat(cfg): vote_page tid and topic defaults
```

---

## 12. Out of scope for v1

- Adding or editing arguments from this page (D7)
- Live updates via the notify channel — v2; v1 loads on open and updates optimistically
- Swipe-to-vote (`assets/card-swipe.js`) — v2
- Voting on source quality
- Issue reporting (CA17) — v2; v1 errors use plain-language toasts (CA15) with full console detail (CA16)

---

## 13. Open items

1. Tenant name — confirm `frueher` or give the real one (§7).
2. `assets/entry-core.js` extraction vs. duplicating 20 lines (§4).
3. Baseline is not clean: 136 files show as modified, all with equal insert/delete counts —
   CRLF/LF churn, not content. REQ-S1-2 wants a clean baseline before branching.
