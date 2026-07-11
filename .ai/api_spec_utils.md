# InfoPedia PHP — API Specification: Configuration & Utils

> 📚 **Navigation:** [API Spec Index](./.ai/api_spec.md) → **Configuration & Utils**
>
> **Related:** [Data Formats](./api_spec_formats.md) · [Routes & Endpoints](./api_spec_routes.md)

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

---

**Last Updated:** 2026-07-11  
**Related:** [API Operations](../docs/api-operations.md) · [Data Formats](./api_spec_formats.md)

