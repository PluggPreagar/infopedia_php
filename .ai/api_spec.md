# InfoPedia PHP â€” API Specification v2.0

> **Status:** Draft â€” refactor/202606  
> **Backward compatibility:** intentionally dropped  
> **Constraints still in force:** CP1 (procedural PHP, no framework), CA1 (simple first), CA6 (pure helper functions), CC3 (log not echo), CC4 (fail fast)

---

## Purpose

This document specifies the InfoPedia PHP API contract, including data formats, endpoints, error handling, and implementation constraints.

---

## ðŸ“š Module Navigation

### Data Formats & Protocols
**[API Spec â€” Data Formats](./api_spec_formats.md)**  
Canonical entry format, vote attributes, sorting, query parameters, and error envelope.

- Â§2 Canonical Entry Format
- Â§3 Vote Attribute
- Â§4 Sorting & Deduplication
- Â§6 Common Query Parameters
- Â§7 Error Envelope

---

### Endpoints & Routing
**[API Spec â€” Routes & Endpoints](./api_spec_routes.md)**  
Complete endpoint specifications with request/response contracts.

- Â§8 Endpoints (GET/POST /entries, /votes, /dumps, /files, /health, /)
- Â§11 .htaccess Routing

---

### Configuration & Utilities
**[API Spec â€” Configuration & Utils](./api_spec_utils.md)**  
Throttling, file structure, util contracts, and status code mappings.

- Â§9 Throttling
- Â§10 Flat File Structure
- Â§10 util_* Function Contracts
- Â§12 HTTP Status Changes
- Â§13 Renamed / Removed

---

## Design Principles

- **CSV is the core format.** Data flows as CSV at every layer: local disk â†’ cache â†’ API I/O â†’ tests. JSON and txt variants are read-side transforms only.
- **Flat file structure.** No subdirectories. Route files and `util_*.php` helpers sit at the project root.
- **One resource, one file.** `entries.php` handles both `GET` and `POST /entries`.
- **Thin route files.** Validate, dispatch, respond. All logic lives in `util_*.php`.
- **Uniform error envelope.** One JSON shape for every error.
- **Simple testing.** Every `util_*.php` has a `util_*_test.php` that feeds CSV strings and asserts outputs â€” no HTTP, no mocking.

---

## Routes Overview

```
GET  /entries              â†’ entries.php
POST /entries              â†’ entries.php

GET  /votes                â†’ votes.php
POST /votes                â†’ votes.php

POST /dumps                â†’ dumps.php

GET  /files/{filename}     â†’ files.php
GET  /health               â†’ health.php
GET  /                     â†’ index.php  (SPA shell)
```

---

## Related Documents

- [API Operations](../docs/api-operations.md) â€” How the API works in practice (flows, sequence diagrams)
- [API Use Cases](../docs/api-use-cases.md) â€” Technical use cases (client, server, integration, DR, monitoring)
- [Backend Communication](../docs/backend-communication.md) â€” Notify channel (current implementation)
- [Documentation Index](../docs/README.md) â€” All documentation organized by role

---

**Last Updated:** 2026-07-11  
**Status:** âœ… Modular structure (split from monolithic document)  
**Maintainer:** InfoPedia Backend Team

---

## Migration Notes

The original monolithic `api_spec.md` (444 lines) has been refactored into three focused modules:

1. **[Data Formats](./api_spec_formats.md)** (~150 lines)
   - Canonical entry format with examples
   - Vote aggregation rules
   - Sorting and deduplication
   - Query parameters and error envelope

2. **[Routes & Endpoints](./api_spec_routes.md)** (~180 lines)
   - Complete endpoint contracts
   - Request/response shapes
   - Status codes and error handling
   - Routing (.htaccess)

3. **[Configuration & Utils](./api_spec_utils.md)** (~130 lines)
   - Throttling configuration and mechanism
   - Flat file structure
   - Utility function contracts
   - HTTP status code changes
   - Renamed/removed parameters

**No content lost** â€” all specifications preserved. Module links provide complete API contract.

