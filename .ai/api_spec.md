# InfoPedia PHP — API Specification v2.0

> **Status:** Draft — refactor/202606  
> **Backward compatibility:** intentionally dropped  
> **Constraints still in force:** CP1 (procedural PHP, no framework), CA1 (simple first), CA6 (pure helper functions), CC3 (log not echo), CC4 (fail fast)

---

## 1. Design Principles

- **CSV is the core format.** Data flows as CSV at every layer: Google Sheets → disk cache → API I/O → tests. JSON and txt variants are read-side transforms only.
- **Flat file structure.** No subdirectories. Route files and `util_*.php` helpers sit at the project root.
- **One resource, one file.** `entries.php` handles both `GET` and `POST /entries`.
- **Thin route files.** Validate, dispatch, respond. All logic lives in `util_*.php`.
- **Uniform error envelope.** One JSON shape for every error.
- **Simple testing.** Every `util_*.php` has a `util_*_test.php` that feeds CSV strings and asserts outputs — no HTTP, no mocking.

---

## 2. Canonical Entry Format

The canonical unit is a **CSV row**:

```
<outer-timestamp>,<entry>
```

Where `<entry>` (the data column) is:

```
/path/node | [<attr>:<value> ...] | [<display-ts>] | <content><type>
```

- **`/path/node`** — full path, single sortable string. No separate topic/node columns.
- **`<attr>:<value>`** — optional named attributes, detected by `^[a-zA-Z_]+:[^ ]`.  
  Multiple allowed, any order, between path and content.
- **`<display-ts>`** — optional, 0 or 1 per entry. `YYYY-MM-DD HH:MM:SS` from client, for display only.  
  **The outer CSV timestamp is always set by the server and is the sync/sort truth.**
- **`<content><type>`** — always the **last** column. Type is the final character:

| Char | Meaning |
|------|---------|
| `.` | statement / fact |
| `!` | important |
| `?` | question |
| `>` | reference |
| `-` | note |

Server appends `.` if the last character is not a recognised type.

**Examples:**
```
2025-09-07 20:44:54,"/climate/solutions | Solar panels."
2025-09-07 20:44:54,"/climate/solutions | author:martin | Solar panels."
2025-09-07 20:44:54,"/climate/solutions | author:martin | 2024-01-01 09:00:00 | Solar panels."
2025-09-07 20:44:54,"/poll/q1 | votes:sid_abc:1 | Is this good?"
```

---

## 3. Vote Attribute

Votes use the attribute `votes:<sid>:<value>`:

```
/path/node | votes:<sid>:<n> | <content><type>
```

**Aggregation rules (server-side, before formatting):**
- Group rows by `/path/node`.
- Sum all `votes:*:<n>` values for the same path.
- Own session (`<sid>` == `$session_id`): emit `votes:<sid>:<total>` (visible).
- All other sessions: sum into `votes:others:<total>` (anonymised).
- Content taken from the most recent row for that path.

**Examples (raw storage):**
```csv
2025-09-07 20:44:54,"/poll/q1 | votes:sid_abc:1 | Fair question?"
2025-09-07 20:45:00,"/poll/q1 | votes:sid_def:2 | Fair question?"
```

**After aggregation (own session = `sid_abc`):**
```csv
2025-09-07 20:45:00,"/poll/q1 | votes:sid_abc:1 | votes:others:2 | Fair question?"
```

---

## 4. Sorting & Deduplication

- Sort rows by **column 1** (`/path/node`) — plain string sort, no key construction.
- Dedup: keep the **newest row per path** (latest outer timestamp wins).
- Delete marker: content ending in `--` removes that path from the output.

---

## 5. Routes

```
GET  /entries              → entries.php
POST /entries              → entries.php

GET  /votes                → votes.php
POST /votes                → votes.php

POST /dumps                → dumps.php

GET  /files/{filename}     → files.php
GET  /health               → health.php
GET  /                     → index.php  (SPA shell)
```

---

## 6. Common Query Parameters

| Name | Type | Constraint | Description |
|------|------|------------|-------------|
| `sid` | string | optional | Session ID. Auto-generated if empty. |
| `tid` | string | optional | Tenant ID — `[a-zA-Z0-9_-]{1,30}`. Empty = global Google Sheets. |

---

## 7. Error Envelope

```
Content-Type: application/json; charset=utf-8
```
```json
{ "error": { "code": "MACHINE_READABLE", "message": "Human-readable." } }
```

| Code | HTTP |
|------|------|
| `INVALID_TID` | 400 |
| `INVALID_FORMAT` | 400 |
| `INVALID_ENTRY` | 400 |
| `NOT_FOUND` | 404 |
| `THROTTLED` | 429 |
| `UPSTREAM_UNAVAILABLE` | 503 |
| `INTERNAL_ERROR` | 500 |

---

## 8. Endpoints

### GET /entries

| Param | Default | Values |
|-------|---------|--------|
| `format` | `json` | `json` · `csv` · `txt.0.2` · `txt.0.3` |
| `since` | — | `YYYY-MM-DD HH:MM:SS` — long-polls up to 50 s |
| `refresh` | — | flag — bypass disk cache (throttled) |

| Status | Condition |
|--------|-----------|
| `200 OK` | Entries returned |
| `204 No Content` | Long-poll timeout, no new entries |
| `400` | Invalid `tid` or `format` |
| `429` | `refresh` flag rate-limited |
| `503` | Upstream down, no cache |

**`format=csv`** — `text/csv; charset=utf-8`
```csv
Timestamp,entry
2025-09-07 20:44:54,"/climate/solutions | Solar panels."
```

**`format=json`** (default) — `application/json; charset=utf-8`
```json
{
  "/climate/solutions": {
    "timestamp": "2025-09-07 20:44:54",
    "message": "Solar panels.",
    "attrs": { "author": "martin" },
    "votes": { "sid_abc": 1, "others": 2 }
  }
}
```

**`format=txt.0.2`** — `text/plain; charset=utf-8`
```
/climate/solutions | 2025-09-07 20:44:54 | Solar panels.
```

**`format=txt.0.3`** — `text/plain; charset=utf-8`
```
    Solar panels.
```
*(indented by path depth, path omitted)*

---

### POST /entries

Body — form-encoded or `text/csv`, same column format:

```
/path/node | [attr:value ...] | [timestamp] | content.
```

| Status | Body |
|--------|------|
| `201 Created` | `{ "status": "ok", "timestamp": "YYYY-MM-DD HH:MM:SS" }` |
| `400` | Error envelope |
| `429 Too Many Requests` | Error envelope + `Retry-After: <seconds>` header |
| `503` | Error envelope |

---

### GET /votes

Same parameters as `GET /entries`. Vote aggregation (§3) applied before formatting.

---

### POST /votes

Body:
```
/path/node | votes:<sid>:<n> | content.
```

Same response shape as `POST /entries`. Throttle applies.

---

### POST /dumps

Body: `dump=<text>` (form-encoded) or raw text (`text/plain`).

| Status | Body |
|--------|------|
| `201 Created` | `{ "status": "ok" }` |
| `400` | Error envelope |
| `429` | Error envelope + `Retry-After` |

---

### GET /files/{filename}

`filename` must be in `allowedDownloadFiles[]` in `infopedia.cfg`.

| Status | Content-Type |
|--------|--------------|
| `200` | `.apk` → `application/vnd.android.package-archive` · `.pdf` → `application/pdf` · `.aab` → `application/x-authorware-bin` |
| `404` | Error envelope |

---

### GET /health

```json
{
  "status": "ok",
  "server_time": "YYYY-MM-DD HH:MM:SS",
  "cache": { "entry_age_seconds": 120, "vote_age_seconds": 60 }
}
```

`503` if no cache and upstream unreachable.

---

### GET /stats

Access log analysis — `statistic.php`, **unchanged from v1**.  
Returns HTML report. Not part of the JSON API; no route alias in `.htaccess` (direct `.php` access).

---

### GET /

SPA shell (`index.php`). `Content-Type: text/html; charset=utf-8`.

---

## 9. Throttling

Rate limiting is **config-driven and disabled by default** (`throttle_max = 0`).

**Config (`infopedia.cfg` `[general]` section):**
```ini
throttle_max    = 10   ; max requests per window per key (0 = disabled)
throttle_window = 60   ; window in seconds
throttle_key    = sid  ; 'sid' or 'ip'
```

**Applied to:**
- `POST /entries`, `POST /votes`, `POST /dumps` — write protection
- `GET /entries?refresh`, `GET /votes?refresh` — cache-bypass protection

**Mechanism — file-based leaky bucket:**
- State file: `data/throttle_<key>.dat` containing `<window_start>:<count>`
- On each throttled request: read file, check window, increment or reset count, write back
- If `count > throttle_max`: return `429` with `Retry-After: <seconds until window ends>`
- Files older than `throttle_window` seconds are expired and treated as fresh

**Response (429):**
```
HTTP/1.1 429 Too Many Requests
Retry-After: 42
Content-Type: application/json; charset=utf-8

{ "error": { "code": "THROTTLED", "message": "Too many requests. Retry after 42 seconds." } }
```

**`util_throttle.php` contracts:**
```php
// Returns true if request is allowed, false if throttled.
// $dir:    directory for state files (e.g. 'data/')
// $key:    throttle key (sid or ip, already sanitised)
// $max:    max requests per window (0 = always allow)
// $window: window in seconds
// $now:    unix timestamp — defaults to time(), injectable for testing
function checkThrottle(string $dir, string $key, int $max, int $window, int $now = 0): bool

// Seconds remaining in the current window (for Retry-After header).
function throttleRetryAfter(string $dir, string $key, int $window, int $now = 0): int
```

**State file format:** `<window_start_unix>:<count>` — plain text, one line, no JSON overhead.  
Example: `1735900800:7`

---

## 10. Flat File Structure

```
index.php               # SPA shell                              (was infopedia.php)
entries.php             # GET + POST /entries                    (was read.php + upload.php)
votes.php               # GET + POST /votes                      (was read.php + upload.php)
dumps.php               # POST /dumps                            (was upload.php)
files.php               # GET /files/{filename}                  (was download.php)
health.php              # GET /health                            (new)
statistic.php           # stats page                             (unchanged)

util.php                # bootstrap: config, logging, sid/tid/since, timezone
util_entry.php          # parseEntry(), sortCsvData(), aggregateVotes(), dedup
util_format.php         # csv_to_json(), csv_to_txt02(), csv_to_txt03()       (new)
util_http.php           # respond_json(), respond_error(), set_content_type()  (new)
util_cache.php          # isCacheValid(), readCache(), writeCache()             (was util_file.php)
util_throttle.php       # checkThrottle(), throttleRetryAfter()                 (new)

test/
  util_test.php         # harness: assert_eq, test_summary
  run_all.php           # runs all *_test.php in test/
  util_entry_test.php   # parseEntry, sortCsvData, aggregateVotes
  util_format_test.php  # csv_to_json, csv_to_txt02, csv_to_txt03
  util_cache_test.php   # isCacheValid, readCache, writeCache
  util_throttle_test.php# checkThrottle, throttleRetryAfter
```

Deleted: `read.php`, `upload.php`, `download.php`, `util_file.php`

---

## 10. util_* Function Contracts

### util_entry.php

```php
// Parse one entry column string into a structured array.
// Returns: ['path'=>..., 'content'=>..., 'type'=>..., 'timestamp'=>..., 'attrs'=>[...]]
function parseEntry(string $entry): array

// Sort, dedup, and normalise a raw CSV string. Returns clean CSV.
function sortCsvData(string $csv): string

// Aggregate vote attributes in a sorted CSV. Returns reconstructed CSV.
function aggregateVotes(string $csv, string $session_id): string
```

### util_format.php

```php
function csv_to_json(string $csv): array    // pass to json_encode()
function csv_to_txt02(string $csv): string  // newline-joined lines
function csv_to_txt03(string $csv): string  // indented, newline-joined lines
```

### util_http.php

```php
function respond_json(mixed $data, int $status = 200): never
function respond_error(string $code, string $message, int $status): never
function set_content_type(string $format): void
```

### util_cache.php

```php
function isCacheValid(string $file, int $maxAge, ?string $outdatedFile, int $delay): bool
function readCache(string $file): string
function writeCache(string $file, string $data): void
function touchOutdated(string $file): void
```

---

## 11. .htaccess

```apache
RewriteEngine On

RewriteRule ^/?$                    index.php [QSA,L]
RewriteRule ^/?entries/?$           entries.php [QSA,L]
RewriteRule ^/?votes/?$             votes.php [QSA,L]
RewriteRule ^/?dumps/?$             dumps.php [QSA,L]
RewriteRule ^/?files/(.+)$          files.php?file=$1 [QSA,L]
RewriteRule ^/?health/?$            health.php [QSA,L]

RewriteRule ^(favicon.*|apple-touch-icon\.png|android-chrome-.*|site\.webmanifest|robots\.txt|styles.*\.css)$ $1 [NC,L]

RewriteRule ^(.*)$                  index.php?missed=$1 [QSA,L]
```

---

## 12. HTTP Status Changes

| Situation | Old | New |
|-----------|-----|-----|
| Successful read | 200 | 200 |
| Entry / vote / dump created | 200 | 201 |
| Long-poll timeout | 200 empty | 204 |
| Malformed parameter | `die()` | 400 |
| File not whitelisted | `die()` | 404 |
| Rate limit exceeded | — | 429 + Retry-After |
| Upstream down, no cache | 404 | 503 |
| Unexpected error | unhandled | 500 |

---

## 13. Renamed / Removed

| v1 | v2 | Note |
|----|-----|------|
| `ts` param | `since` | clearer intent |
| `force_update` param | `refresh` | shorter |
| `format=json.0.3` | `format=json` | version in spec, not param |
| `topic \| node` (two cols) | `/path/node` (one col) | enables plain string sort |
| `node::Vote::sid` | `votes:<sid>:<n>` attribute | clean, generalisable |
