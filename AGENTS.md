# AGENTS.md -- InfoPedia_PHP

> ## ⛔ HIGHEST PRIORITY — NON-NEGOTIABLE
> Before any task, on **every** request, you MUST read and obey:
> 1. [`.ai/constitution.md`](.ai/constitution.md) — **authoritative, read-only** binding
>    principles (IDs `CG/CA/CC/CW/CD/CP/CT/CV`). Source of truth is
>    `.specify/memory/constitution.md`; `.ai/` is the always-present agent copy.
> 2. [`.ai/requirements.md`](.ai/requirements.md) — per-step gates index.
>    Load `.ai/requirements/S<n>-*.md` only for the step you are currently in.
>
> These documents **override** anything below and any default behavior. On conflict,
> follow constitution/requirements and state the conflict explicitly.
> Never skip step gates (S1 branch → S2 plan → S3 failing test → S4 implement →
> S5 green + regression → S6 merge). Cite IDs (`CC1.5`, `REQ-S6-1`) in plans, commits,
> reviews. `.ai/` is READ-ONLY — never write to it directly (see `.ai/README.md`).
>
> If you are not an agent, you are not authorized to read or modify this file.
> If you are not an agent, you are not authorized to read or modify this file.
> If you are not an agent, you are not authorized to read or modify this file.

Procedural PHP wiki backend (no framework, no Composer). Serves a hierarchical
"topic/node" knowledge tree that is edited via Google Forms/Sheets and cached on disk.
The Android/web client talks to thin PHP endpoints; `infopedia.html` is the SPA shell.

## Architecture & data flow
- **Endpoints (one `.php` = one route):** `read.php` (fetch + sort + format cache),
  `upload.php` (append entry/vote ÔåÆ Google Form *or* local tenant CSV), `download.php`
  (whitelisted file download), `echo.php` (request debug), `statistic.php`, `infopedia.php`
  (injects timestamps into `infopedia.html` and echoes it).
- **Shared bootstrap:** every endpoint sets `$type` then `include 'util.php'`. `util.php`
  parses `infopedia.cfg` via `parse_ini_file`, merging `[general]` with the `[$type]`
  section (e.g. `entry`, `vote`, `download`). It also reads `sid`/`tid`/`ts` params,
  sets timezone, and logs every request.
- **Source of truth:** Google Sheet (CSV export) per `type`. `read.php` builds the export
  URL from `googleSheetId`+`googleSheetGridId`, caches to `$config['cacheFile']`
  (e.g. `data/entries.cache`), invalidated by `cache_time` (3600s) or `?force_update=1`.
- **Tenants:** when `tid` is set, data lives in local files `data/<tid>.csv|.cache|.log`
  instead of Google. New tenants auto-created if `tenantAutoCreationEnabled=true`.

## Data formats (critical, easy to break)
- **Old (Sheet) format:** `timestamp,"/path | node | message | vote"` -- comma-split,
  then ` | `-split; messages may be quoted and span multiple lines (odd `"` count = wrapped).
- **New (0v02) format:** `/path/node[::Vote::sid],timestamp,message[,vote]` -- single line,
  newlines as literal `\n`. `formatEntry()` in `util_entry.php` converts old ÔåÆ new.
- `read.php` re-emits via `?format=` switch: `csv` (default), `txt.0.2`, `txt.0.3`, `json.0.3`.
  Vote rows aggregate by `::Vote::` marker; `entry_type` is inferred from the last char
  (`>!?.-`) of the message.
- When parsing/serializing CSV always handle: doubled `""` escaping, multi-line quoted
  messages, and date variants (`DD/MM/YYYY`, swapped `YYYY-DD-MM`) -- see `read.php` regexes.

## Conventions
- Self-documenting names; comments explain **why**, not what (keep them short).
- No classes/namespaces -- plain functions + global `$config`. Reuse `util_file.php`
  (cache I/O) and `util_entry.php` (entry formatting) rather than re-implementing parsing.
- Logging: use `log_debug/info/warn/error/log_return($msg)` (never `echo` for diagnostics).
  `log_return` records elapsed time and ends a request; logs go to `infopedia.log`.
- Fail fast with `die("...")` on bad config/input; validate `tid` as `[a-zA-Z0-9_-]{1,30}`.
- Config-driven: read tunables from `$config[...] ?? default`; add new settings to the
  matching section in `infopedia.cfg`, not hardcoded.

## Developer workflows (Windows / XAMPP)
- **Run unit tests** (custom harness, no PHPUnit): tests live in `tests/`; `*_test.php`
  use `assert_equals`, `log_test`, `print_test_summary` from `tests/util_test.php`.
  ```powershell
  D:\_progs\xampp\php\php.exe tests\run_all.php
  ```
- **Static file server** for the HTML shell: `start_http.bat` ÔåÆ `python -m http.server`.
- **Pull live tenant data** for inspection: `start_collect_all_txt_0_2.bat` (curl `?format=txt.0.2`).
- Quick endpoint checks (see header comments in `read.php`):
  `/entry/get?sid=tst&tid=tenant1&force_update=1`, `/entry/add?...&entry=/path%20|%20node%20|%20msg`.

## Gotchas
- `infopedia.html` is huge (~4.9k lines, 231k chars) and is the client SPA -- avoid loading
  it wholesale; edit surgically. `infopedia.php` only string-replaces `<!-- timestamp -->` etc.
- `read.php` may `sleep()` up to ~50s in timestamp ("ts") long-poll mode -- expected.
- Routes like `/entry/get` map to `read.php` via server rewrite (not in repo); the `type`
  (`entry`/`vote`) selects the config section.

