# AGENTS.md — InfoPedia PHP (agent guide)

This file gives concise, actionable instructions for AI coding agents to get productive in this repository.

## Quick checklist for agents
- [ ] Read `CLAUDE.md` first (project onboarding / constraints).
- [ ] Read `.ai/api_spec.md` and `justfile` for endpoint contracts and commands.
- [ ] Follow CP1/CP2: plain procedural PHP, one file = one route.
- [ ] Use test-first flow: add tests in `test/*.php`, run `just unit`.

## Big picture
- Architecture: small procedural PHP app serving a single-page frontend. Routes are single files: `entries.php`, `votes.php`, `dumps.php`, `files.php`, `health.php`, `index.php`.
- Utilities are grouped by capability: `util_entry.php`, `util_format.php`, `util_http.php`, `util_cache.php`, `util_throttle.php`, `util.php`.
- Data is stored as CSV files under `data/`. Canonical entry format (inner content):

  /path/node | [attr:value ...] | [YYYY-MM-DD HH:MM:SS] | content<type>

  - attrs: `votes:<sid>:<n>`, `author:...`, `priority:...` etc.
  - type suffix chars: `. ! ? > -` (server appends `.` if absent)

## Key patterns & examples
- One-file route pattern: each route loads `util.php`, does throttle/validation, mutates CSV and responds. See `entries.php` and `votes.php`.
- Pure utility functions: prefer stateless helpers in `util_*.php` that operate on CSV strings/arrays (e.g. `parseEntry()` in `util_entry.php`, `csv_as_format()` in `util_format.php`).
- CSV round-trip: read CSV -> `sortCsvData()`/`aggregateVotes()` -> format via `csv_as_format()` -> send via helpers in `util_http.php`.
- Throttling & caching: call `require_throttle()` before state-changing work; use `util_cache.php` helpers to invalidate caches after writes.

## Tests & developer workflow
- Test-first: tests live in `test/` (e.g. `test/util_entry_test.php`). Use the project's small test harness (`test/*` helpers) and `assert_eq()`.
- Commands (see `justfile`):
  - `just unit` — run unit tests
  - `just e2e` — run end-to-end tests (no external server)
  - `just ci` — run both, fail non-zero on test failures
  - `just e2e-demo` — manual add+vote+read demo
  - `just serve` — start local dev server (if configured)

## Config & runtime
- Configuration via `infopedia.cfg` (read by `util.php` using `parse_ini_file()`); per-route overrides exist (section per type).
- Query overrides: `tid`, `sid`, `since`, `refresh` used throughout handlers.
- Log control: `infopedia.cfg` toggles debug/logging; utilities use `log_*()` functions in `util.php`.

## Integration points & constraints
- No Composer, no frameworks (CP1). Keep changes procedural.
- Files are single-route boundaries (CP2) — add new endpoints as new PHP files.
- Tests run without a webserver where possible (E2E uses PHP subprocesses in tests).

## Troubleshooting notes (discoverable patterns)
- If tests fail: run single file with `just test-file test/<name>`, inspect assertions in `test/*` helpers.
- Stale cache: run `just clean-cache` or trigger `?refresh=1` on the request.
- Rate limit blocks: check `util_throttle.php` and `infopedia.cfg` thresholds.

## Subagents (CW4 — subagent-driven-development)
- `.claude/agents/php-task-implementer.md` — model `haiku`, implements ONE task
  ticket (RED -> GREEN). Dispatch one per task from `docs/superpowers/plans/**`.
- `.claude/agents/php-task-reviewer.md` — model `sonnet`, two-stage review
  (spec compliance, then code quality) after the implementer finishes.
- Orchestrator (Sonnet) does planning/dispatch/review-triage; implementer
  subagents run on the cheaper/faster model to keep per-task cost low.

## Where to look first (files to open)
- Onboarding: `CLAUDE.md`, `.ai/api_spec.md`, `.ai/plan.md`
- Routes: `entries.php`, `votes.php`, `dumps.php`, `files.php`
- Core utils: `util.php`, `util_entry.php`, `util_format.php`, `util_http.php`, `util_cache.php`, `util_throttle.php`
- Tests: `test/util_entry_test.php`, `test/util_format_test.php`, `test/util_cache_test.php`

---
Generated from repository analysis (routes, utils, tests, justfile, CLAUDE.md). Keep this file minimal — reference code directly for implementation details.

