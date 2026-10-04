# Debugging & Tracing Entries

Layered toolkit for diagnosing issues with entries (reads/writes through
`entries.php`), from lightest to heaviest.

## 1. Live log viewer — `statistic.html`

Filterable dashboard over `data/infopedia.log`.

```
just serve
# then open http://localhost:8080/statistic.php   (redirects to statistic.html)
```

- Filter bar: type (`entries`/`votes`/`health`), method (GET/POST), tenant
  regex, URI regex
- Sub-level filter: `ERROR` / `WARNING` / `RETURN`
- Query flags: `?exclude_e2e=1` (hide e2e-test noise), `?errors_only=1`
  (jump straight to errors)

Fastest way to see what a specific entry POST/GET actually did.

## 2. Raw log file — `data/infopedia.log`

Every request logs via `log_to_file()` (`util.php`). `entries.php` calls
`log_info` / `log_warn` / `log_error` / `log_return` at each decision point
(cache hit, stale-cache fallback, write failure, unknown tenant, etc.).

```
tail -f data/infopedia.log
```

Debug-level logging (`log_debug`) is off by default. Enable it by adding
`debug=true` under `[general]` (or the `[entry]` section) in
`infopedia.cfg`.

## 3. Manual inspection via `just`

```
just entries [tid]      # GET /entries as JSON
just collect [tid]      # GET /entries as txt.0.2 (human-readable, one line/entry)
just refresh [tid]      # force cache rebuild + dump
just post "/path | content" [tid]   # POST an entry directly
just health
```

## 4. E2E harness with full trace

```
just e2e-debug           # full request/response trace, no server needed (PHP subprocess)
just e2e-add-entry "/demo/hello | test." demo
just e2e-read demo       # dumps both entries + votes for that tenant
```

`test/e2e_run.php` fires one-off GET/POST requests at any endpoint without
a running server.

## 5. Client-side diagnostics — `dumps.php` → `data/dumps.log`

POST `dump=<text>` to the dumps endpoint appends a timestamped line to
`data/dumps.log`. Useful when the frontend needs to report its own state
alongside a failing entry.

## 6. Bug-report entries get rerouted

Any entry POSTed with path `/_/bug | bug_...` is intercepted in
`entries.php` (`/_/bug` prefix check) and rewritten into a special tenant
`fayfBug__1754128928`. If a user-reported bug seems to have vanished,
check that tenant's cache/CSV: `data/entries_fayfBug__1754128928.*`.

## 7. Structured issue tracker — `issues.php`

Renders issues from `data/issues/<state>/<id>`
(states: `new`, `ready`, `blocked`, `inProgress`, `inReview`, `canceled`,
`closed`) with a history log (`## Verlauf`) appended on state transitions.

## 8. Notify/long-poll channel

`data/notify[_<tid>]_a.jsonl` / `_b.jsonl` record every incremental push
event (`append_incr` in `util.php`). Check these if entries show up in
`/entries` but live long-poll clients never got notified.

## Quick triage order

1. Reproduce, then run `just e2e-debug` (or hit the endpoint via `just post`)
2. Check `statistic.html?errors_only=1` for the log line
3. If still unclear, grep `data/infopedia.log` directly and cross-reference
   `data/notify_*.jsonl`
