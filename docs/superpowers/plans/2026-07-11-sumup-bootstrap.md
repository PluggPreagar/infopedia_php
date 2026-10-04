# SumUp Bootstrap — Overview & Index

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans. Each story/task lives in its own file under
> `sumup-bootstrap/` and is implementable independently (context included per file).
> **Status (2026-07-11): T01–T13 IMPLEMENTED.** `just unit` (253 passed) and `just e2e`
> green (pre-existing, unrelated `issue.php` edit-feature failures excluded). Entries
> SumUp (T12) is config-gated and defaults **off**; votes SumUp (T09/T10) is always on.

**Goal:** Speed up client bootstrap (initial load / forced full refresh) of `/entries` and
`/votes` by replacing "read full CSV → sort → dedup → aggregate on every request" with an
**offset-based incremental aggregate ("SumUp")**, following the proven
`stats_aggregate.cache` pattern already implemented in `util_data.php`.

**Architecture (one sentence):** The full-data CSVs (`data/entries_<tid>.csv`,
`data/votes_<tid>.csv`) are append-only logs; a byte **offset** into them is the delta
cursor — a SumUp is a JSON snapshot `{src, offset, nodes}` that is lazily advanced by
parsing only appended tail bytes on read, saved under `flock` with newer-wins re-check.
**No API change. No background worker. No notify hooks. No extra delta files.**

**Tech Stack:** PHP 8.0+ procedural (CP1), one file = one route (CP2),
test-first RED→GREEN, `just lint` / `just unit` / `just e2e`.

## Global Constraints (apply to every task)

- CP1: no classes, no framework, no Composer
- CP2: no new route files — only `util_*.php` helpers + edits inside existing routes
- **No API change**: `GET /entries` / `GET /votes` responses stay byte-compatible
- **Atomic writes**: `flock` + newer-wins re-check (pattern: `save_stats_cache()` in
  `util_data.php`); production runtime is Unix — `rename()` is atomic there
- **Incremental unless no SumUp exists**: missing/stale SumUp (offset > filesize) →
  automatic full rebuild; forced full refresh = delete SumUp file or `?refresh=1`
- Duplicate tail consumption impossible by design: offset advances only under exclusive lock
- Server is the vote truth: clients have no local knowledge (different devices, fakeable) —
  per-SID aggregates live server-side; response projects own-SID vs. others

## Stories

| Story | File | Priority |
|---|---|---|
| A — Correct multi-session vote aggregation | [story-A-vote-aggregation.md](sumup-bootstrap/story-A-vote-aggregation.md) | 🔴 blocker, do first |
| B — Generic offset-based SumUp helper | [story-B-sumup-helper.md](sumup-bootstrap/story-B-sumup-helper.md) | high |
| C — Votes SumUp (fast bootstrap, per-SID precise) | [story-C-votes-sumup.md](sumup-bootstrap/story-C-votes-sumup.md) | high — main win |
| D — Entries incremental rebuild | [story-D-entries-incremental.md](sumup-bootstrap/story-D-entries-incremental.md) | phase 2, optional |
| E — Stats cache on shared helper | [story-E-stats-refactor.md](sumup-bootstrap/story-E-stats-refactor.md) | optional, lowest |

## Task Tickets

| Task | File | Story | Size | Depends on |
|---|---|---|---|---|
| T01 — RED pipeline test: vote-row loss | [T01-red-pipeline-test.md](sumup-bootstrap/T01-red-pipeline-test.md) | A | S | — |
| T02 — Fix: keep all vote rows in read pipeline | [T02-fix-vote-row-loss.md](sumup-bootstrap/T02-fix-vote-row-loss.md) | A | M | T01 |
| T03 — E2E: two sessions vote on same path | [T03-e2e-two-session-votes.md](sumup-bootstrap/T03-e2e-two-session-votes.md) | A | S | T02 |
| T04 — Extract `csv_join_wrapped_lines()` | [T04-extract-line-joiner.md](sumup-bootstrap/T04-extract-line-joiner.md) | B | S | — |
| T05 — `util_sumup.php` load/save/tail primitives | [T05-sumup-primitives.md](sumup-bootstrap/T05-sumup-primitives.md) | B | M | T04 |
| T06 — `sumup_update()` orchestration + idempotency | [T06-sumup-update.md](sumup-bootstrap/T06-sumup-update.md) | B | S | T05 |
| T07 — Votes merge callback (per-path per-SID) | [T07-votes-merge-callback.md](sumup-bootstrap/T07-votes-merge-callback.md) | C | M | T05 |
| T08 — Votes projection (byte-compatible) | [T08-votes-projection.md](sumup-bootstrap/T08-votes-projection.md) | C | M | T07 |
| T09 — Wire `votes.php` GET to SumUp | [T09-wire-votes-route.md](sumup-bootstrap/T09-wire-votes-route.md) | C | M | T06, T08 |
| T10 — Forced full refresh + stale handling | [T10-forced-refresh-stale.md](sumup-bootstrap/T10-forced-refresh-stale.md) | C | S | T09 |
| T11 — Config docs, justfile task, CHANGELOG | [T11-docs-justfile-changelog.md](sumup-bootstrap/T11-docs-justfile-changelog.md) | C | S | T09, T10 |
| T12 — Entries incremental cache rebuild | [T12-entries-incremental.md](sumup-bootstrap/T12-entries-incremental.md) | D | M | T05, T06 |
| T13 — Stats cache refactor onto helper | [T13-stats-refactor.md](sumup-bootstrap/T13-stats-refactor.md) | E | M | T05 |

## Dependency graph & suggested order

```
T01 → T02 → T03            (Story A — correctness gate, do first)
T04 → T05 → T06            (Story B — helper)
T05 → T07 → T08 ─┐
T06 ─────────────┴→ T09 → T10 → T11    (Story C — main win)
T05,T06 → T12              (Story D — later)
T05 → T13                  (Story E — whenever)
```

## Definition of Done (epic)

- `just ci` green.
- `GET /votes` and `GET /entries` outputs byte-identical to pre-epic behavior
  (golden tests in T08 / T12).
- Bootstrap read cost scales with **tail size**, not history size.
- Forced full refresh available via `?refresh=1` and `just sumup-clean`.

## Background: how this concept maps to existing code

The repo already implements the pattern twice — reuse, don't reinvent:

| Existing | Pattern | Where |
|---|---|---|
| `entries.cache` | lazy snapshot + invalidation signal (`touchOutdated`) | `entries.php` L126–172, `util_cache.php` |
| `stats_aggregate.cache` | **offset-based incremental aggregate** `{offset, agg}`, flock + newer-wins | `util_data.php`: `data_stats_respond()`, `load_stats_cache()`, `save_stats_cache()` |
| notify A/B JSONL | event push channel with rotation | `notify.php`, `util.php::append_incr()` |

Key insight: `votes.php` today has **no caching at all** (declared `$cacheFile` is dead
code) — every GET re-reads and re-aggregates the entire history. That is the main cost
this epic removes.

