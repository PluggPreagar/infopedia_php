# InfoPedia PHP — API Specification: Routes & Endpoints

> 📚 **Navigation:** [API Spec Index](./.ai/api_spec.md) → **Routes & Endpoints**
>
> **Related:** [Data Formats](./api_spec_formats.md) · [Configuration & Utils](./api_spec_utils.md)

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

Same parameters as `GET /entries`. Vote aggregation (Data Formats § 3) applied before formatting.

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

**Last Updated:** 2026-07-11  
**Related:** [API Operations](../docs/api-operations.md) · [Data Formats](./api_spec_formats.md)

