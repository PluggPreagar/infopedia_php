# InfoPedia PHP — Agent Onboarding

## First: read the constitution
`.ai/constitution.md` is the highest authority (CG5). Read it before acting.

## Current state
Branch `refactor/202606` — complete PHP refactor in progress.  
Spec and test files (RED phase) are done. **No new implementation files exist yet.**

## What this project is
Procedural PHP wiki backend. No framework, no Composer. Serves a hierarchical topic/node knowledge tree from Google Sheets + disk cache. Single-page HTML app frontend (`infopedia.html`).

## Key documents
| File | Purpose |
|------|---------|
| `.ai/constitution.md` | Binding principles — read first |
| `.ai/api_spec.md` | Complete API contract for the refactor |
| `.ai/plan.md` | Task list T01–T20, current phase |
| `test/` | All test files (RED — functions not yet implemented) |
| `justfile` | All common commands (`just --list`) |

## Canonical entry format
```
/path/node | [attr:value ...] | [YYYY-MM-DD HH:MM:SS] | content<type>
```
- Single `/path/node` field (not topic + node separately)
- `votes:<sid>:<n>` for votes; `author:x`, `priority:y` for other attrs
- Content always last; type char `.!?>-`; server appends `.` if absent
- Outer CSV timestamp set by server (sync truth); inner display-ts optional

## Running tests
```bash
just unit          # unit tests — no server needed
just e2e           # E2E tests — no server needed (PHP subprocesses)
just ci            # both, exits non-zero on failure
just e2e-demo      # manual add entry + vote + read sequence
```

## File structure (post-refactor target)
```
entries.php  votes.php  dumps.php  files.php  health.php  index.php
util.php  util_entry.php  util_format.php  util_http.php  util_cache.php  util_throttle.php
test/  statistic.php (unchanged)
```

## Key constraints
- CP1: Plain procedural PHP — no classes, no framework, no Composer
- CP2: One file = one route
- CA1: Simple first
- Backward compat: intentionally dropped for this refactor
- Tests: write failing test first (RED), then implement (GREEN)
