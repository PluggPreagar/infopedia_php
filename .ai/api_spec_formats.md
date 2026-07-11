# InfoPedia PHP — API Specification: Data Formats

> 📚 **Navigation:** [API Spec Index](./.ai/api_spec.md) → **Data Formats**
>
> **Related:** [Routes & Endpoints](./api_spec_routes.md) · [Configuration & Utils](./api_spec_utils.md)

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

## 6. Common Query Parameters

| Name | Type | Constraint | Description |
|------|------|------------|-------------|
| `sid` | string | optional | Session ID. Auto-generated if empty. |
| `tid` | string | optional | Tenant ID — `[a-zA-Z0-9_-]{1,30}`. Empty = global default dataset. |

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

**Last Updated:** 2026-07-11  
**Related:** [API Operations](../docs/api-operations.md) · [Routes & Endpoints](./api_spec_routes.md)

