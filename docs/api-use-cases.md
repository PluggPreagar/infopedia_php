# InfoPedia API â€” Technical Use Cases

> **Audience:** Backend developers, API integrators, operations  
> **Status:** Living document â€” modular structure (updated 2026-07-11)  
> **Related:** [`api-operations.md`](./api-operations.md) Â· [`app2-use-cases.md`](./app2-use-cases.md) Â· [`api_spec.md`](../.ai/api_spec.md)

---

## Purpose

This document defines **technical use cases** for the InfoPedia API from a systems integration and operations perspective. For user-facing use cases, see [`app2-use-cases.md`](./app2-use-cases.md).

Each use case includes:
- **Trigger:** What initiates the scenario
- **Preconditions:** System state before scenario
- **Flow:** Step-by-step technical process
- **Postconditions:** System state after scenario
- **Error Cases:** What can go wrong and how to handle it

---

## ðŸ“š Module Navigation

### Client-Side Use Cases
**[API Use Cases â€” Client-Side](./api-use-cases-client.md)**  
Client initialization, synchronization, and real-time event handling.

- **UC-C1:** Initial App Load
- **UC-C2:** Incremental Sync (Within Retention)
- **UC-C3:** Full Re-Sync (Outside Retention)
- **UC-C4:** Real-Time Event Handling
- **UC-C5:** Notify Timeout (204 No Content)
- **UC-C6:** Background/Foreground Transitions

---

### Server-Side Use Cases
**[API Use Cases â€” Server-Side](./api-use-cases-server.md)**  
Tenant provisioning, data ingestion, notifications, and aggregation.

- **UC-S1:** Tenant Provisioning
- **UC-S2:** Entry Ingestion
- **UC-S3:** Vote Recording
- **UC-S4:** Notification Broadcasting
- **UC-S5:** Notification Partition Rotation (planned)
- **UC-S6:** Full Data Export
- **UC-S7:** SumUp Snapshot Management (init, delta, increment, rebuild)

---

### Integration, DR, Monitoring & Testing
**[API Use Cases â€” Integration, DR, Monitoring & Testing](./api-use-cases-integration.md)**  
External integrations, disaster recovery, monitoring, and test scenarios.

**Integration:**
- **UC-I1:** External System Writes Entry
- **UC-I2:** External System Reads Data
- **UC-I3:** Tenant Migration

**Disaster Recovery:**
- **UC-DR1:** Restore from CSV Backup
- **UC-DR2:** Recover from Snapshot Corruption
- **UC-DR3:** Clean All Caches and Snapshots

**Monitoring:**
- **UC-M1:** Monitor Notify Connection Health
- **UC-M2:** Monitor Write Latency
- **UC-M3:** Monitor Sumup Snapshot Size

**Testing:**
- **UC-T1:** End-to-End Test (Write + Notify + Read)
- **UC-T2:** Throttle Enforcement Test

---

## Quick Reference by Scenario

| Scenario | Use Cases |
|----------|-----------|
| User opens app first time | [UC-C1](./api-use-cases-client.md#uc-c1-initial-app-load) |
| User returns (2 min later) | [UC-C2](./api-use-cases-client.md#uc-c2-incremental-sync-within-retention) |
| User returns (48 hrs later) | [UC-C3](./api-use-cases-client.md#uc-c3-full-re-sync-outside-retention) |
| Real-time event arrives | [UC-C4](./api-use-cases-client.md#uc-c4-real-time-event-handling) |
| Create new tenant | [UC-S1](./api-use-cases-server.md#uc-s1-tenant-provisioning) |
| Add entry | [UC-S2](./api-use-cases-server.md#uc-s2-entry-ingestion) |
| Add vote | [UC-S3](./api-use-cases-server.md#uc-s3-vote-recording) |
| Send notification | [UC-S4](./api-use-cases-server.md#uc-s4-notification-broadcasting) |
| External system integrates | [UC-I1](./api-use-cases-integration.md#uc-i1-external-system-writes-entry), [UC-I2](./api-use-cases-integration.md#uc-i2-external-system-reads-data) |
| Data loss occurs | [UC-DR1](./api-use-cases-integration.md#uc-dr1-restore-from-csv-backup) |
| Snapshot corrupts | [UC-DR2](./api-use-cases-integration.md#uc-dr2-recover-from-snapshot-corruption) |

---

## Related Documents

- [API Specification](../.ai/api_spec.md) â€” Endpoint contracts and data formats
- [API Operations](./api-operations.md) â€” Operational flows with sequence diagrams
  - [Client-Side Operations](./api-operations-client.md)
  - [Server-Side Operations](./api-operations-server.md)
  - [Architecture](./api-operations-architecture.md)
  - [Troubleshooting](./api-operations-troubleshooting.md)
- [Backend Communication](./backend-communication.md) â€” Notify channel (current implementation)
- [Backend Communication Concept](./backend-communication-concept.md) â€” Incremental notify (future)
- [App2 Use Cases](./app2-use-cases.md) â€” User-facing use cases
- [Documentation Index](./README.md) â€” All documentation by role

---

**Last Updated:** 2026-07-11  
**Status:** âœ… Modular structure (split from monolithic document)  
**Maintainer:** InfoPedia Backend Team

---

## Migration Notes

The original monolithic `api-use-cases.md` (1055 lines) has been refactored into three focused modules:

1. **[Client-Side Use Cases](./api-use-cases-client.md)** (UC-C1 to UC-C6)
   - Initial load, incremental sync, re-sync, real-time handling, timeouts, background/foreground

2. **[Server-Side Use Cases](./api-use-cases-server.md)** (UC-S1 to UC-S7)
   - Tenant provisioning, entry/vote ingestion, notifications, partition rotation, full data export, sumup management

3. **[Integration, DR, Monitoring & Testing](./api-use-cases-integration.md)**
   - UC-I1 to UC-I3 (Integration)
   - UC-DR1 to UC-DR3 (Disaster Recovery)
   - UC-M1 to UC-M3 (Monitoring)
   - UC-T1 to UC-T2 (Testing)

**No content lost** â€” all use cases, error handling, and examples preserved. Module links provide full context.
