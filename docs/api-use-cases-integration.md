# InfoPedia API — Integration, DR, Monitoring & Testing Use Cases

> 📚 **Navigation:** [Docs Index](./README.md) → [API Use Cases](./api-use-cases.md) → **Integration, DR, Monitoring & Testing**
>
> **Related Modules:** [Client-Side](./api-use-cases-client.md) · [Server-Side](./api-use-cases-server.md)

Advanced use cases for integration, disaster recovery, monitoring, and testing scenarios.

---

## Integration Use Cases

### UC-I1: External System Writes Entry

**Trigger:** External system (not app2.html) sends `POST /entries` via API.

**Preconditions:**
- External system has valid tenant ID
- API authentication (if implemented, future)

**Flow:**
1. External system sends:
   ```bash
   curl -X POST "https://infopedia.example.com/entries?tid=prod" \
     -H "Content-Type: text/plain; charset=utf-8" \
     -d "/events/deploy | author:ci_bot | 2026-07-11 12:00:00 | Deployed v2.1.0 successfully."
   ```
2. Server processes as UC-S2 (Entry Ingestion)
3. Server responds `201 Created`
4. All connected app2.html clients receive `{"type":"entries"}` event
5. Clients fetch delta, see new entry

**Postconditions:**
- Entry visible to all users
- No distinction between human and bot entries (unless marked via `author` attr)

**Use Cases:**
- CI/CD deployment notifications
- Monitoring system alerts
- Scheduled task reports

**Related:** [api-operations.md § 2.2](./api-operations-server.md#22-add-entry)

---

### UC-I2: External System Reads Data

**Trigger:** External system needs to analyze or export InfoPedia data.

**Flow:**

#### CSV Export
```bash
curl "https://infopedia.example.com/entries?tid=prod&format=csv" > entries_prod.csv
```

Server responds with:
```csv
Timestamp,entry
2026-07-11 12:00:00,"/events/deploy | author:ci_bot | Deployed v2.1.0."
```

#### JSON Export
```bash
curl "https://infopedia.example.com/entries?tid=prod&format=json" | jq .
```

Server responds with:
```json
{
  "/events/deploy": {
    "timestamp": "2026-07-11 12:00:00",
    "message": "Deployed v2.1.0.",
    "attrs": {"author": "ci_bot"},
    "votes": {}
  }
}
```

**Use Cases:**
- Data backup scripts
- Analytics pipelines
- Report generation
- External search indexing

---

### UC-I3: Tenant Migration

**Trigger:** Need to copy data from one tenant to another, or merge tenants.

**Flow:**
1. Export source tenant:
   ```bash
   curl "https://infopedia.example.com/entries?tid=source&format=csv" > source_entries.csv
   curl "https://infopedia.example.com/votes?tid=source&format=csv" > source_votes.csv
   ```
2. Transform CSV if needed (e.g., path prefix changes)
3. Import to target tenant (line by line):
   ```bash
   while IFS=',' read -r ts entry; do
     curl -X POST "https://infopedia.example.com/entries?tid=target" \
       -H "Content-Type: text/plain" \
       -d "$entry"
   done < source_entries.csv
   ```
4. Or: direct CSV file copy (if tenants on same server):
   ```bash
   cat data/entries_source.csv >> data/entries_target.csv
   cat data/votes_source.csv >> data/votes_target.csv
   rm data/entries_target.sumup.json  # Force rebuild
   rm data/votes_target.sumup.json    # Force rebuild
   ```

**Postconditions:**
- Target tenant has all source data
- Sumup snapshots rebuilt on next read
- Connected clients see full dataset after re-sync

**Caveats:**
- Direct CSV copy bypasses throttling (admin only)
- Duplicate paths will be deduped (latest timestamp wins)
- Session IDs in votes preserved (may cause sid collision)

---

## Disaster Recovery Use Cases

### UC-DR1: Restore from CSV Backup

**Trigger:** Data loss (accidental deletion, filesystem corruption).

**Preconditions:**
- Backup CSV files available
- Server filesystem writable

**Flow:**
1. Stop web server (to prevent concurrent writes)
2. Copy backup CSV to `data/` directory:
   ```bash
   cp backup/entries_prod.csv data/entries_prod.csv
   cp backup/votes_prod.csv data/votes_prod.csv
   ```
3. Delete sumup snapshots (will rebuild):
   ```bash
   rm data/entries_prod.sumup.json
   rm data/votes_prod.sumup.json
   ```
4. Delete caches:
   ```bash
   rm data/entries_prod.cache
   rm data/entries_prod.cache.outdated
   ```
5. Start web server
6. First read will rebuild sumup and cache
7. Connected clients will re-sync (via notify mtime change or manual refresh)

**Postconditions:**
- Data restored to backup point
- Sumup and cache rebuilt automatically
- Users see restored state

**Data Loss:**
- All writes between backup and restore time

---

### UC-DR2: Recover from Snapshot Corruption

**Trigger:** Vote counts or entry display incorrect, suspect snapshot corruption.

**Flow:**
1. Admin triggers rebuild:
   ```bash
   curl "https://infopedia.example.com/votes?tid=prod&refresh=1"
   ```
   (Throttled, may need to wait if limit exceeded)
2. OR: manual delete + auto-rebuild on next read:
   ```bash
   rm data/votes_prod.sumup.json
   ```
3. Next GET request rebuilds from CSV
4. Verify correctness via test queries

**Postconditions:**
- Snapshot rebuilt from authoritative CSV
- Correct aggregation restored
- Users see correct data

---

### UC-DR3: Clean All Caches and Snapshots

**Trigger:** Suspected global corruption, or major version upgrade.

**Flow:**
1. Run admin command:
   ```bash
   just sumup-clean  # Deletes all *.sumup.json
   just cache-clean  # Deletes all *.cache* files
   ```
2. Or manual:
   ```bash
   cd data/
   rm *.sumup.json
   rm *.cache*
   ```
3. Next read request per tenant rebuilds from CSV
4. Monitor logs for rebuild messages:
   ```bash
   tail -f data/infopedia.log | grep "sumup_init"
   ```

**Postconditions:**
- All cached/snapshot state cleared
- Authoritative CSV files untouched
- System recovers automatically on next use

---

## Monitoring Use Cases

### UC-M1: Monitor Notify Connection Health

**Trigger:** Ops dashboard, scheduled health check.

**Flow:**
1. Test notify endpoint:
   ```bash
   time curl "https://infopedia.example.com/notify?tid=test" -m 30
   ```
2. Expected: hold for ~25s, then 204 or 200
3. If immediate response: notify.php not holding (config error)
4. If timeout: notify.php hanging (deadlock, filesystem issue)

**Alerts:**
- Response time < 20s → config error
- Response time > 30s → hang, restart needed
- HTTP 500 → check logs

---

### UC-M2: Monitor Write Latency

**Trigger:** Performance testing, production monitoring.

**Flow:**
1. Write entry with timing:
   ```bash
   time curl -X POST "https://infopedia.example.com/entries?tid=test" \
     -d "/perf/test | Test entry."
   ```
2. Expected: < 50ms for empty tenant, < 200ms for large tenant
3. If > 500ms: investigate:
   - Sumup snapshot size (should be < 1MB per tenant)
   - CSV file size (should append in O(1))
   - Filesystem performance (NFS latency, disk I/O)

**Metrics:**
- p50, p95, p99 write latency
- Throttle 429 rate (should be < 1% of requests)

---

### UC-M3: Monitor Sumup Snapshot Size

**Trigger:** Daily cron job, capacity planning.

**Flow:**
1. Check snapshot sizes:
   ```bash
   du -h data/*.sumup.json | sort -h
   ```
2. Expected: < 1MB per tenant (thousands of paths)
3. If > 10MB: investigate:
   - Excessive vote attributes (thousands of unique sids)
   - Very large content fields (should be trimmed)
   - Memory consumption on next rebuild

**Alerts:**
- Snapshot > 5MB → review tenant data
- Snapshot > 10MB → manual cleanup recommended

---

## Testing Use Cases

### UC-T1: End-to-End Test (Write + Notify + Read)

**Flow:**
1. Write entry:
   ```bash
   curl -X POST "http://localhost/entries?tid=e2e" \
     -d "/test/e2e | E2E test entry."
   ```
2. Long-poll notify (separate terminal):
   ```bash
   curl "http://localhost/notify?tid=e2e&since=2020-01-01%2000:00:00"
   ```
   Expected: immediate response `[{"type":"entries","ts":"..."}]`
3. Read delta:
   ```bash
   curl "http://localhost/entries?tid=e2e&since=2020-01-01%2000:00:00"
   ```
   Expected: new entry in response

**Assertions:**
- Notify latency < 2s
- Entry returned in delta
- Entry timestamp matches notify event timestamp

**Related:** `test/e2e.php`, `just e2e`

---

### UC-T2: Throttle Enforcement Test

**Flow:**
1. Configure aggressive throttle:
   ```ini
   [general]
   throttle_max = 3
   throttle_window = 10
   throttle_key = sid
   ```
2. Send 4 requests in rapid succession:
   ```bash
   for i in {1..4}; do
     curl -X POST "http://localhost/entries?tid=test&sid=test_sid" \
       -d "/test/$i | Request $i."
     echo "Request $i done"
   done
   ```
3. Expected:
   - Requests 1-3: `201 Created`
   - Request 4: `429 Too Many Requests` + `Retry-After: <seconds>`
4. Wait for window expiry, retry:
   ```bash
   sleep 11
   curl -X POST "http://localhost/entries?tid=test&sid=test_sid" \
     -d "/test/5 | Request 5."
   ```
   Expected: `201 Created` (window reset)

**Assertions:**
- 429 on 4th request
- `Retry-After` header present
- Error envelope: `{"error":{"code":"THROTTLED",...}}`
- New window allows requests

**Related:** `test/util_throttle_test.php`, `just unit`

---

**Last Updated:** 2026-07-11  
**Related:** [API Operations](./api-operations.md) · [Backend Communication](./backend-communication.md)

