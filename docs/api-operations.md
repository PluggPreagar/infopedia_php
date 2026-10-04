# InfoPedia API â€” Technical Operations Guide

> **Audience:** Backend developers, operations, integrators  
> **Status:** Living document â€” modular structure (updated 2026-07-11)  
> **Related:** [`api_spec.md`](../.ai/api_spec.md) Â· [`api-use-cases.md`](./api-use-cases.md) Â· [Documentation Index](./README.md)

---

## Purpose

This guide explains **how the InfoPedia API works in practice**. It is organized into focused modules covering client-side flows, server-side operations, data architecture, and troubleshooting.

For **endpoint contracts** and **data formats**, see [`api_spec.md`](../.ai/api_spec.md).  
For **detailed technical use cases**, see [`api-use-cases.md`](./api-use-cases.md).

---

## ðŸ“š Module Navigation

### Client-Side Operations
**[API Operations â€” Client-Side](./api-operations-client.md)**  
How clients interact with the API from initial load through real-time synchronization.

- Â§1.1 Initial Client Sync (Cold Start)
- Â§1.2 Catchup During Retention Time
- Â§1.3 Catchup After Retention Time (Long Offline)
- Â§1.4 Receiving Real-Time Notifications

---

### Server-Side Operations
**[API Operations â€” Server-Side](./api-operations-server.md)**  
Server-side flows for tenant provisioning, data ingestion, notifications, and aggregation.

- Â§2.1 New Tenant Initialization
- Â§2.2 Add Entry
- Â§2.3 Add Vote
- Â§2.4 Add Notification
- Â§2.5 Switch Notification Partition (planned)
- Â§2.6 Full Data from Entries/Votes Log
- Â§2.7 SumUp Operations (init, delta, increment, rebuild)

---

### Architecture & Configuration
**[API Operations â€” Architecture](./api-operations-architecture.md)**  
Data flow paths, consistency guarantees, and configuration reference.

- Â§3 Data Flow Architecture (write/read paths)
- Â§3.3 Consistency Guarantees
- Â§4 Configuration Reference (`infopedia.cfg`)

---

### Troubleshooting & Future
**[API Operations â€” Troubleshooting](./api-operations-troubleshooting.md)**  
Common operational issues, diagnostic checks, fixes, and future enhancements.

- Â§5 Troubleshooting (notify, sumup, cache, throttle)
- Â§7 Future Enhancements (incremental notify, client-side aggregation, sumup burn-in)

---

## Architecture Overview

```
â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”
â”‚                     Client (app2.html)                      â”‚
â”‚  â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”  â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”  â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”     â”‚
â”‚  â”‚ GET /entries â”‚  â”‚  GET /votes  â”‚  â”‚ GET /notify  â”‚     â”‚
â”‚  â”‚   (delta)    â”‚  â”‚ (aggregated) â”‚  â”‚ (long-poll)  â”‚     â”‚
â”‚  â””â”€â”€â”€â”€â”€â”€â”¬â”€â”€â”€â”€â”€â”€â”€â”˜  â””â”€â”€â”€â”€â”€â”€â”¬â”€â”€â”€â”€â”€â”€â”€â”˜  â””â”€â”€â”€â”€â”€â”€â”¬â”€â”€â”€â”€â”€â”€â”€â”˜     â”‚
â””â”€â”€â”€â”€â”€â”€â”€â”€â”€â”¼â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”¼â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”¼â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”˜
          â”‚                  â”‚                  â”‚
          â–¼                  â–¼                  â–¼
â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”
â”‚                    Server (PHP Routes)                      â”‚
â”‚  â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”  â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”  â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”     â”‚
â”‚  â”‚ entries.php  â”‚  â”‚  votes.php   â”‚  â”‚  notify.php  â”‚     â”‚
â”‚  â”‚ â”œâ”€ throttle  â”‚  â”‚ â”œâ”€ throttle  â”‚  â”‚ â”œâ”€ poll loop â”‚     â”‚
â”‚  â”‚ â”œâ”€ sortCsv / â”‚  â”‚ â”œâ”€ sumup     â”‚  â”‚ â”œâ”€ mtime chk â”‚     â”‚
â”‚  â”‚ â”‚  sumup     â”‚  â”‚ â”œâ”€ project   â”‚  â”‚ â””â”€ JSONL readâ”‚     â”‚
â”‚  â”‚ â””â”€ cache     â”‚  â”‚ â””â”€ format    â”‚  â””â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”˜     â”‚
â”‚  â””â”€â”€â”€â”€â”€â”€â”¬â”€â”€â”€â”€â”€â”€â”€â”˜  â””â”€â”€â”€â”€â”€â”€â”¬â”€â”€â”€â”€â”€â”€â”€â”˜                        â”‚
â””â”€â”€â”€â”€â”€â”€â”€â”€â”€â”¼â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”¼â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”˜
          â”‚                  â”‚
          â–¼                  â–¼
â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”
â”‚                    Data Layer (CSV + Snapshots)             â”‚
â”‚  â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”  â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”              â”‚
â”‚  â”‚ entries_X.csv     â”‚  â”‚ votes_X.csv        â”‚              â”‚
â”‚  â”‚ (append-only)     â”‚  â”‚ (append-only)      â”‚              â”‚
â”‚  â””â”€â”€â”€â”€â”€â”€â”€â”¬â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”˜  â””â”€â”€â”€â”€â”€â”€â”€â”¬â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”˜              â”‚
â”‚          â”‚                      â”‚                            â”‚
â”‚  â”Œâ”€â”€â”€â”€â”€â”€â”€â–¼â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”  â”Œâ”€â”€â”€â”€â”€â”€â”€â–¼â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”              â”‚
â”‚  â”‚ entries_X.sumup   â”‚  â”‚ votes_X.sumup      â”‚              â”‚
â”‚  â”‚ (optional)        â”‚  â”‚ (required)         â”‚              â”‚
â”‚  â””â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”˜  â””â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”˜              â”‚
â”‚  â”Œâ”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”                â”‚
â”‚  â”‚ notify_X.jsonl (event log)              â”‚                â”‚
â”‚  â””â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”˜                â”‚
â””â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”˜
```

---

## Quick Reference

### Key Flows

| Scenario | Client Module | Server Module | Architecture |
|----------|---------------|---------------|--------------|
| User opens app | [1.1](./api-operations-client.md#11-initial-client-sync-cold-start) | [2.1â€“2.6](./api-operations-server.md#26-full-data-from-entriesvotes-log) | [3.2](./api-operations-architecture.md#32-read-path-overview) |
| User returns (2 min offline) | [1.2](./api-operations-client.md#12-catchup-during-retention-time) | [2.6](./api-operations-server.md#26-full-data-from-entriesvotes-log) | [3.2](./api-operations-architecture.md#32-read-path-overview) |
| User returns (48 hrs offline) | [1.3](./api-operations-client.md#13-catchup-after-retention-time-long-offline) | [2.6](./api-operations-server.md#26-full-data-from-entriesvotes-log) | [3.2](./api-operations-architecture.md#32-read-path-overview) |
| Real-time event | [1.4](./api-operations-client.md#14-receiving-real-time-notifications) | [2.2â€“2.4](./api-operations-server.md#24-add-notification) | [3.1](./api-operations-architecture.md#31-write-path-overview) |
| Add entry | [1.4](./api-operations-client.md#14-receiving-real-time-notifications) | [2.2](./api-operations-server.md#22-add-entry) | [3.1](./api-operations-architecture.md#31-write-path-overview) |
| Add vote | [1.4](./api-operations-client.md#14-receiving-real-time-notifications) | [2.3, 2.7](./api-operations-server.md#23-add-vote) | [3.1](./api-operations-architecture.md#31-write-path-overview) |

---

## Configuration Quick Start

```bash
# View current config
cat infopedia.cfg

# Key settings
[general]
throttle_max    = 10        # 0 = disabled
poll_timeout    = 25        # seconds

[entry]
sumup_enabled   = false     # burn-in phase

# See full reference: [Architecture Â§ 4](./api-operations-architecture.md#4-configuration-reference)
```

---

## Common Troubleshooting

| Issue | See | Fix |
|-------|-----|-----|
| Client stale | [Â§5.1](./api-operations-troubleshooting.md#51-client-not-receiving-updates) | Verify notify.php, check CSV mtime |
| Wrong vote counts | [Â§5.2](./api-operations-troubleshooting.md#52-sumup-snapshot-out-of-sync) | `rm data/*.sumup.json` (rebuild) |
| Cache not updating | [Â§5.3](./api-operations-troubleshooting.md#53-cache-stale-despite-writes) | `GET ?refresh=1` or clear cache |
| 429 errors | [Â§5.4](./api-operations-troubleshooting.md#54-throttle-blocking-legitimate-requests) | Increase `throttle_max` or disable |

See [Troubleshooting Module](./api-operations-troubleshooting.md) for full diagnostic procedures.

---

## Module Dependency Graph

```
api-operations.md (index)
  â”œâ”€ api-operations-client.md â†’ api-use-cases-client.md
  â”œâ”€ api-operations-server.md â†’ api-use-cases-server.md
  â”œâ”€ api-operations-architecture.md â†’ api_spec.md
  â””â”€ api-operations-troubleshooting.md â†’ all modules
```

---

## Related Documents

- [API Specification](../.ai/api_spec.md) â€” Endpoint contracts, error codes
- **API Use Cases** (modular):
  - [Client-Side](./api-use-cases-client.md) â€” UC-C1 to UC-C6
  - [Server-Side](./api-use-cases-server.md) â€” UC-S1 to UC-S7
  - [Integration & DR](./api-use-cases-integration.md) â€” UC-I1 to UC-DR3
  - [Monitoring & Testing](./api-use-cases-monitoring.md) â€” UC-M1 to UC-T2
- [Backend Communication](./backend-communication.md) â€” Notify channel (current implementation)
- [Backend Communication Concept](./backend-communication-concept.md) â€” Incremental notify (future design)
- [Documentation Index](./README.md) â€” All documentation organized by role

---

**Last Updated:** 2026-07-11  
**Status:** âœ… Modular structure (split from monolithic document)  
**Maintainer:** InfoPedia Backend Team

---

## Migration Notes

The original monolithic `api-operations.md` (1251 lines) has been refactored into four focused modules:

1. **[Client-Side Operations](./api-operations-client.md)** (270 lines)
   - Covers client initialization, incremental sync, reconnection, and real-time events
   - Sequence diagrams with client-server flows

2. **[Server-Side Operations](./api-operations-server.md)** (570 lines)
   - Covers tenant provisioning, entry/vote ingestion, notifications, and sumup operations
   - Detailed aggregation logic and validation

3. **[Architecture & Configuration](./api-operations-architecture.md)** (180 lines)
   - Write/read path diagrams
   - Consistency guarantees
   - Full config reference for `infopedia.cfg`

4. **[Troubleshooting & Future](./api-operations-troubleshooting.md)** (150 lines)
   - Common issues with diagnostic checks and fixes
   - Future enhancements (incremental notify, client-side vote aggregation)

**No content lost** â€” all scenarios, diagrams, and implementation details are preserved in the new modules. Cross-module links provide full context.---
