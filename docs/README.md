# InfoPedia Documentation Index

Welcome to the InfoPedia PHP documentation. This index helps you find the right document for your needs.

---

## 🎯 Quick Start

| I want to... | Read this |
|-------------|-----------|
| **Understand the API from a technical perspective** | [API Operations Guide](./api-operations.md) |
| **Learn about API endpoints and contracts** | [API Specification](../.ai/api_spec.md) |
| **Understand user-facing features** | [App2 Use Cases](./app2-use-cases.md) |
| **Onboard as a new developer** | [CLAUDE.md](../CLAUDE.md) + [AGENTS.md](../AGENTS.md) |

---

## 📚 Documentation Structure

### API & Backend

#### Core Specifications
- **[API Specification](../.ai/api_spec.md)** — Authoritative endpoint contracts, data formats, error envelopes
- **[API Operations Guide](./api-operations.md)** ⭐ — Technical deep-dive with sequence diagrams covering:
  - Client-side scenarios (init, catchup, re-sync, real-time notify)
  - Server-side scenarios (tenant provisioning, data ingestion, sumup operations)
  - Data flow architecture and consistency guarantees
  - Configuration reference and troubleshooting

#### Use Cases
- **[API Use Cases](./api-use-cases.md)** — Technical use cases (UC-C1 to UC-M3):
  - Client-side: initial load, incremental sync, re-sync, event handling
  - Server-side: tenant provisioning, entry/vote ingestion, notification broadcasting
  - Integration: external system integration, data migration
  - Disaster recovery and monitoring
- **[App2 Use Cases](./app2-use-cases.md)** — User-facing use cases (UC1 to UC15):
  - Browse, navigate, add, edit, vote, search
  - Mobile gestures and interactions

#### Communication & Real-Time
- **[Backend Communication](./backend-communication.md)** — Current notify channel implementation:
  - Long-poll mechanism (25s hold, 2s wake cycle)
  - Event types (entries, votes, message)
  - Frontend integration (`startNotifyPoll()`)
  - SumUp snapshots for votes and entries
- **[Backend Communication Concept](./backend-communication-concept.md)** — Future incremental notify design:
  - Rotating partition files (dual-write guarantee)
  - Cursor-based incremental reads with `(ts, msgid)`
  - Retention window and re-sync triggers

#### Frontend
- **[App2 Spec](./app2-spec.md)** — Frontend architecture and implementation details
- **[Debug Guide](./debug.md)** — Debugging techniques for app2.html

---

## 🏗️ Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│                     Client (app2.html)                      │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐     │
│  │ GET /entries │  │  GET /votes  │  │ GET /notify  │     │
│  │   (delta)    │  │ (aggregated) │  │ (long-poll)  │     │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘     │
└─────────┼──────────────────┼──────────────────┼─────────────┘
          │                  │                  │
          ▼                  ▼                  ▼
┌─────────────────────────────────────────────────────────────┐
│                    Server (PHP Routes)                      │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐     │
│  │ entries.php  │  │  votes.php   │  │  notify.php  │     │
│  │ ├─ throttle  │  │ ├─ throttle  │  │ ├─ poll loop │     │
│  │ ├─ sortCsv / │  │ ├─ sumup     │  │ ├─ mtime chk │     │
│  │ │  sumup     │  │ ├─ project   │  │ └─ JSONL read│     │
│  │ └─ cache     │  │ └─ format    │  └──────────────┘     │
│  └──────┬───────┘  └──────┬───────┘                        │
└─────────┼──────────────────┼──────────────────────────────┘
          │                  │
          ▼                  ▼
┌─────────────────────────────────────────────────────────────┐
│                    Data Layer (CSV + Snapshots)             │
│  ┌───────────────────┐  ┌───────────────────┐              │
│  │ entries_X.csv     │  │ votes_X.csv        │              │
│  │ (append-only)     │  │ (append-only)      │              │
│  └───────┬───────────┘  └───────┬────────────┘              │
│          │                      │                            │
│  ┌───────▼───────────┐  ┌───────▼────────────┐              │
│  │ entries_X.sumup   │  │ votes_X.sumup      │              │
│  │ (optional)        │  │ (required)         │              │
│  └───────────────────┘  └────────────────────┘              │
│  ┌─────────────────────────────────────────┐                │
│  │ notify_X.jsonl (event log)              │                │
│  └─────────────────────────────────────────┘                │
└─────────────────────────────────────────────────────────────┘
```

**Key Flows:**
1. **Write Path:** Client → POST → CSV append → sumup update → notify event → all clients
2. **Read Path:** Client → GET → (cache check) → sumup/sortCsv → format → respond
3. **Real-Time:** Client → long-poll → server watches mtime → event → client fetch delta

**Detailed diagrams:** See [API Operations Guide § 3](./api-operations.md#3-data-flow-architecture)

---

## 🔑 Key Concepts

### Canonical Entry Format
```
/path/node | [attr:value ...] | [YYYY-MM-DD HH:MM:SS] | content<type>
```
- Single `/path/node` field (sortable)
- Attributes: `votes:<sid>:<n>`, `author:x`, `priority:y`
- Type suffix: `.` fact, `!` important, `?` question, `>` reference, `-` note
- Server appends `.` if missing

**Details:** [API Spec § 2](../.ai/api_spec.md#2-canonical-entry-format)

---

### SumUp Snapshots
Incremental aggregation mechanism for CSV append-only logs.

- **Votes:** Always enabled (required for aggregation)
- **Entries:** Optional via config `[entry] sumup_enabled = true` (burn-in phase)

**Operations:**
- Init: Full CSV read on first access → O(full file)
- Running Delta: Tail merge on subsequent reads → O(tail)
- Increment: Single row merge on write → O(1)
- Re-create: Full rebuild on `?refresh=1` or corruption → O(full file)

**Details:** [API Operations § 2.7](./api-operations.md#27-sumup-operations) + [API Use Cases § UC-S7](./api-use-cases.md#uc-s7-sumup-snapshot-management)

---

### Notify Channel
Long-poll mechanism for real-time updates without continuous polling.

**Current Implementation:**
- Single JSONL file per tenant: `data/notify_<tid>.jsonl`
- Server holds connection ≤25s, wakes every 2s to check CSV mtime
- Events: `{"type":"entries"}`, `{"type":"votes"}`, `{"type":"message","text":"..."}`
- Timeout: `204 No Content` → client reconnects immediately

**Planned Enhancement:**
- Rotating partition files with dual-write guarantee
- Cursor-based incremental reads: `(ts, msgid)`
- Retention window enforcement (clients outside window get `re_sync_required`)

**Details:** [Backend Communication](./backend-communication.md) + [Backend Communication Concept](./backend-communication-concept.md)

---

### Throttling
File-based leaky bucket rate limiting.

- **Config:** `throttle_max`, `throttle_window`, `throttle_key` (sid or ip)
- **State:** `data/throttle_<key>.dat` → `<window_start>:<count>`
- **Applied to:** POST /entries, POST /votes, POST /dumps, GET ?refresh=1
- **Response:** `429 Too Many Requests` + `Retry-After: <seconds>` header

**Details:** [API Spec § 9](../.ai/api_spec.md#9-throttling) + [API Operations § Troubleshooting](./api-operations.md#54-throttle-blocking-legitimate-requests)

---

## 🧪 Testing & Development

### Commands (via justfile)
```bash
just unit           # Run unit tests (test/*_test.php)
just e2e            # Run end-to-end tests (no external server)
just ci             # Run both, fail non-zero on errors
just e2e-demo       # Manual add+vote+read demo
just serve          # Start local dev server (if configured)
just sumup-clean    # Delete all sumup snapshots (force rebuild)
just cache-clean    # Delete all cache files
```

**Details:** [AGENTS.md § Tests & Developer Workflow](../AGENTS.md#tests--developer-workflow)

---

### Test Files
- `test/util_entry_test.php` — Entry parsing, sorting, deduplication
- `test/util_format_test.php` — CSV to JSON/txt conversion
- `test/util_cache_test.php` — Cache validity and read/write
- `test/util_throttle_test.php` — Throttle enforcement
- `test/util_sumup_test.php` — Sumup snapshot operations (golden tests)
- `test/e2e.php` — End-to-end write + notify + read flow

---

### Development Workflow
1. Read `CLAUDE.md` (project onboarding)
2. Check `.ai/api_spec.md` and `justfile` for contracts and commands
3. Write failing test first (RED phase)
4. Implement feature (GREEN phase)
5. Run `just ci` to verify
6. Commit with reference to task ticket (if applicable)

**Details:** [CLAUDE.md](../CLAUDE.md) + [AGENTS.md](../AGENTS.md)

---

## 📋 Checklists

### Before Adding a New Feature
- [ ] Read relevant use cases in [API Use Cases](./api-use-cases.md) or [App2 Use Cases](./app2-use-cases.md)
- [ ] Check [API Spec](../.ai/api_spec.md) for endpoint contracts
- [ ] Write failing test in `test/` directory
- [ ] Implement in appropriate `util_*.php` or route file
- [ ] Run `just ci` to verify
- [ ] Update sequence diagrams in [API Operations](./api-operations.md) if data flow changes

### Before Deploying to Production
- [ ] All tests passing: `just ci`
- [ ] Config reviewed: `infopedia.cfg` (especially throttle settings)
- [ ] Sumup snapshots enabled for votes (always), disabled for entries (until burn-in complete)
- [ ] Monitoring in place: write latency, notify connection health, snapshot sizes
- [ ] Backup strategy verified: CSV files backed up regularly
- [ ] Disaster recovery tested: restore from CSV backup (see [UC-DR1](./api-use-cases.md#uc-dr1-restore-from-csv-backup))

---

## 🆘 Troubleshooting

| Symptom | Likely Cause | See |
|---------|--------------|-----|
| Client not receiving updates | Notify connection issue, mtime not advancing | [API Operations § 5.1](./api-operations.md#51-client-not-receiving-updates) |
| Wrong vote counts | Sumup snapshot out of sync | [API Operations § 5.2](./api-operations.md#52-sumup-snapshot-out-of-sync) |
| Stale cache after write | `.outdated` marker not triggered | [API Operations § 5.3](./api-operations.md#53-cache-stale-despite-writes) |
| 429 errors on normal usage | Throttle settings too aggressive | [API Operations § 5.4](./api-operations.md#54-throttle-blocking-legitimate-requests) |
| 503 errors | Filesystem permissions, CSV file locked | [API Spec § 7](../.ai/api_spec.md#7-error-envelope) |

**Full troubleshooting guide:** [API Operations § 5](./api-operations.md#5-troubleshooting)

---

## 🎓 Learning Path

### For New Backend Developers
1. **Day 1:** Read [CLAUDE.md](../CLAUDE.md) + [AGENTS.md](../AGENTS.md) for project constraints
2. **Day 2:** Study [API Specification](../.ai/api_spec.md) for endpoint contracts
3. **Day 3:** Read [API Operations Guide](./api-operations.md) sections 1 (client flows) and 2 (server flows)
4. **Day 4:** Run `just unit` and read test files in `test/` to understand utilities
5. **Day 5:** Implement a small feature (test-first) following [API Use Cases](./api-use-cases.md)

### For Frontend Developers
1. **Day 1:** Read [App2 Spec](./app2-spec.md) for frontend architecture
2. **Day 2:** Read [App2 Use Cases](./app2-use-cases.md) for user flows
3. **Day 3:** Study [Backend Communication](./backend-communication.md) for notify integration
4. **Day 4:** Read [API Spec § 8](../.ai/api_spec.md#8-endpoints) for endpoint contracts
5. **Day 5:** Read [API Operations § 1](./api-operations.md#1-client-side-scenarios) for client-side sequence diagrams

### For Operations / DevOps
1. **Day 1:** Read [API Operations § 2](./api-operations.md#2-server-side-scenarios) for server-side flows
2. **Day 2:** Study [API Operations § 3](./api-operations.md#3-data-flow-architecture) for data consistency guarantees
3. **Day 3:** Read [API Operations § 4](./api-operations.md#4-configuration-reference) for config options
4. **Day 4:** Study [API Use Cases § Disaster Recovery](./api-use-cases.md#disaster-recovery-use-cases)
5. **Day 5:** Set up monitoring for [UC-M1 to UC-M3](./api-use-cases.md#monitoring-use-cases)

---

## 📝 Document Status

| Document | Status | Last Updated |
|----------|--------|--------------|
| API Specification | ✅ Stable | 2026-06 (refactor/202606) |
| API Operations Guide | ✅ Complete | 2026-07-11 |
| API Use Cases | ✅ Complete | 2026-07-11 |
| App2 Use Cases | ✅ Stable | 2026-06 |
| Backend Communication | ✅ Stable | 2026-06 |
| Backend Communication Concept | 🚧 Draft (future) | 2026-06 |
| App2 Spec | ✅ Stable | 2026-06 |

**Legend:**
- ✅ Stable: Production-ready, actively maintained
- 🚧 Draft: Design document, not yet implemented
- 📝 In Progress: Actively being written
- 🗃️ Archive: Historical, superseded

---

## 🔗 External Resources

- **Project Repository:** `D:\_project\202506_InfoPedia\infopedia_php`
- **Constitution (CG5):** `.ai/constitution.md` (binding principles)
- **Plan:** `.ai/plan.md` (task list T01–T20)
- **Justfile:** `justfile` (all common commands)
- **Config Template:** `infopedia_template.cfg`

---

## 📞 Contact & Contribution

**Maintainer:** InfoPedia Backend Team  
**Last Index Update:** 2026-07-11

**Contributing:**
1. Follow [CLAUDE.md](../CLAUDE.md) constraints (CP1: procedural PHP, CP2: one file = one route)
2. Write tests first (RED → GREEN)
3. Update relevant documentation when adding features
4. Reference use cases in commit messages

---

**Note:** This documentation follows the constitution (`.ai/constitution.md`) as the highest authority (CG5).

