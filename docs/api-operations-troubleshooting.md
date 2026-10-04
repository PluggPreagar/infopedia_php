# InfoPedia API — Operations Guide & Troubleshooting

> 📚 **Navigation:** [Docs Index](./README.md) → [API Operations](./api-operations.md) → **Troubleshooting & Future**
>
> **Related Modules:** [Client-Side](./api-operations-client.md) · [Server-Side](./api-operations-server.md) · [Architecture](./api-operations-architecture.md)

This module covers troubleshooting common operational issues and future enhancements.

---

## 5. Troubleshooting

### 5.1 Client Not Receiving Updates

**Symptoms:** Client shows stale data, notify events not firing, `204 No Content` for hours.

**Root Causes:**
1. Notify.php not responding
2. CSV mtime not advancing (writes failing silently)
3. Network connectivity issues
4. Client not reconnecting after 204

**Diagnostic Checks:**

1. **Verify notify.php responding:**
   ```bash
   curl "http://localhost/notify?tid=X&since=2020-01-01%2000:00:00" -v
   ```
   Expected: Holds for ~25 seconds, then 204 or returns JSON

2. **Check CSV mtime:**
   ```bash
   ls -la data/entries_X.csv
   ```
   Expected: mtime should advance on POST requests

3. **Check notify log:**
   ```bash
   tail -f data/notify_X.jsonl
   ```
   Expected: New events appearing after POSTs

4. **Check browser console:**
   - Verify poll loop running (`GET /notify` requests)
   - Check for JavaScript errors in app2.html
   - Verify poll reconnects after each 200/204

**Fixes:**

- **Accelerate testing:** Set `poll_timeout = 2` in config (temporarily)
- **Clear cache:** `rm data/entries_X.cache*`
- **Rebuild snapshots:** `rm data/*.sumup.json` (next read rebuilds)
- **Verify write handler:** Check POST /entries logs for append_notify() calls
- **Check file permissions:** `ls -la data/` (ensure writable by web server)

---

### 5.2 Sumup Snapshot Out of Sync

**Symptoms:** Vote counts wrong, entries missing or duplicated, `votes:others` incorrect.

**Root Causes:**
1. Snapshot offset > CSV size (CSV was truncated or replaced)
2. Concurrent write during snapshot save (race condition)
3. Corrupted JSON in snapshot file
4. Offset tracking incorrect after failed write

**Diagnostic Checks:**

1. **Compare snapshot offset to CSV size:**
   ```bash
   stat -c%s data/votes_X.csv          # CSV size
   jq .offset data/votes_X.sumup.json  # Snapshot offset
   ```
   If offset > size: rebuild needed

2. **Verify snapshot `src` field:**
   ```bash
   jq .src data/votes_X.sumup.json
   ```
   Should match `votes_X.csv`

3. **Validate JSON:**
   ```bash
   jq . data/votes_X.sumup.json > /dev/null
   ```
   If parse error: snapshot corrupted

4. **Compare aggregates manually:**
   ```bash
   # Count votes in CSV
   grep "votes:" data/votes_X.csv | wc -l
   # Count votes in snapshot
   jq '.nodes | length' data/votes_X.sumup.json
   ```

**Fixes:**

- **Force rebuild (throttled):** `GET /votes?tid=X&refresh=1`
- **Delete snapshot:** `rm data/votes_X.sumup.json` (rebuilds on next GET)
- **Admin rebuild all:** `just sumup-clean` (deletes all snapshots)
- **Check for concurrent writes:** Review server logs for flock() errors

---

### 5.3 Cache Stale Despite Writes

**Symptoms:** GET /entries returns old data even after POST, `.outdated` marker persists.

**Root Causes:**
1. `touchOutdated()` not called in POST handler
2. `cache_delay` grace period too long (buffering multiple writes)
3. Cache file permissions wrong
4. `.outdated` marker file stuck

**Diagnostic Checks:**

1. **Verify `.outdated` file exists:**
   ```bash
   ls -la data/entries_X.cache.outdated
   ```

2. **Check cache age:**
   ```bash
   stat -c%Y data/entries_X.cache
   echo $(date +%s)  # Current time
   ```
   Difference > `cache_max_age`: cache expired

3. **Verify `touchOutdated()` called:**
   - Search entries.php for `touchOutdated()`
   - Check logs for "Invalidating cache" message

4. **Check file permissions:**
   ```bash
   ls -la data/entries_X.cache*
   ```
   Should be writable by web server user

**Fixes:**

- **Increase `cache_delay`:** Grace period for multiple rapid writes (default: 5s)
- **Force refresh:** `GET /entries?tid=X&refresh=1` (throttled)
- **Manual cleanup:** `touch data/entries_X.cache.outdated`
- **Clear cache:** `rm data/entries_X.cache*`
- **Restart web server:** Clear any stale file handles

---

### 5.4 Throttle Blocking Legitimate Requests

**Symptoms:** `429 Too Many Requests` on normal usage pattern.

**Root Causes:**
1. `throttle_max` too low for use pattern
2. `throttle_window` too small
3. Multiple clients sharing same sid (collision)
4. `throttle_key = ip` (all LAN users throttled together)
5. Stale throttle state file

**Diagnostic Checks:**

1. **Check throttle state:**
   ```bash
   cat data/throttle_<sid>.dat
   # Format: <window_start_unix>:<count>
   ```

2. **Verify config:**
   ```bash
   grep throttle infopedia.cfg
   ```

3. **Check sid uniqueness:**
   - Each browser tab should have unique sid
   - Verify localStorage: `console.log(window.sid)`
   - Check `throttle_key` setting (sid vs ip)

4. **Check request rate:**
   - Count requests in time window
   - Compare to `throttle_max` and `throttle_window`

**Fixes:**

- **Increase limits (temporary):** Adjust `throttle_max` or `throttle_window` in config
- **Disable throttle:** Set `throttle_max = 0` (not recommended for production)
- **Clear state:** `rm data/throttle_*.dat` (resets all throttle counters)
- **Check sid generation:** Ensure each client has unique sid (GUID)
- **Switch to ip-based:** Set `throttle_key = ip` if many users behind NAT
- **Wait for window expiry:** Request fails with `Retry-After` header (honor it)

---

## 6. Related Documents

- [API Specification](../.ai/api_spec.md) — Endpoint contracts and data formats
- [Backend Communication Concept](./backend-communication-concept.md) — Incremental notify design (future)
- [Backend Communication](./backend-communication.md) — Current notify implementation
- [API Use Cases](./api-use-cases.md) — Detailed technical use cases
- [App2 Use Cases](./app2-use-cases.md) — User-facing use cases
- [App2 Spec](./app2-spec.md) — Frontend implementation details

---

## 7. Future Enhancements

### 7.1 Incremental Notify Payload Delivery

**Status:** Designed (see [backend-communication-concept.md](./backend-communication-concept.md)), not implemented

**Changes:**
- Notify response includes `entries` and `votes` arrays (actual data, not just event type)
- Client uses `(ts, msgid)` cursor for precise positioning
- Rotating notify files with dual-write guarantee (§2.5)
- Retention window enforcement (clients outside window get `re_sync_required`)

**Benefits:**
- Eliminates separate GET /entries after notify event (one round-trip instead of two)
- Enables incremental vote aggregation on client side (future)
- Reduces server load (no full CSV reads on every poll)
- Better compliance with mobile data limits

**Timeline:** After notify infrastructure stabilizes (Q3 2026+)

---

### 7.2 Client-Side Vote Aggregation

**Status:** Design gap (see [backend-communication-concept.md](./backend-communication-concept.md) § Votes)

**Current Hybrid Model:**
- Server: Incremental (one vote row per write)
- Client: Full aggregation (re-fetch entire `/votes` per event)

**Proposed Change:**
- Notify delivers raw vote rows: `{"path":"/poll/q1","votes":"sid_a:1","ts":"..."}`
- Client maintains local vote aggregation store (in-memory)
- No more full `/votes` re-fetch on every vote event

**Benefits:**
- Truly incremental votes (server-to-client)
- Lower bandwidth on high-vote tenants
- Enables real-time vote animations (smooth count transitions)
- Reduced cache churn

**Timeline:** Q4 2026 (depends on 7.1)

---

### 7.3 Entry Sumup Default-On

**Status:** Implemented but disabled by default (burn-in phase)

**Current:** `[entry] sumup_enabled = false` (uses full CSV read + sortCsvData())

**Proposed:** Flip to `true` after production burn-in

**Burn-in Criteria:**
- 30 days production use
- Zero snapshot corruption incidents
- Performance metrics verified (O(tail) improvement confirmed)

**Backward Compatibility:**
- Automatic: old tenants rebuild snapshot on first read
- No client changes required
- No downtime needed

**Benefits:**
- O(tail) entry reads for all tenants (same as votes)
- Scales to millions of entries per tenant
- Unified code path with votes (less maintenance)
- Predictable read latency

**Timeline:** Q3-Q4 2026 (data-driven decision)

---

## Monitoring & Observability

### Recommended Alerts

- **Write latency > 200ms:** Check sumup snapshot size and CSV disk I/O
- **Notify.php response time > 30s:** Check poll loop, possible hang
- **Throttle 429 rate > 5%:** Review throttle config vs actual usage
- **Sumup snapshot size > 5MB:** Review tenant data volume
- **Cache hit rate < 70%:** Consider increasing `cache_max_age`
- **File lock contention:** Monitor flock() failures in logs

### Health Check

```bash
# GET /health returns:
{
  "status": "ok",
  "server_time": "2026-07-11 12:00:00",
  "cache": {
    "entry_age_seconds": 120,
    "vote_age_seconds": 60
  }
}
```

Status **ok** if:
- All required files accessible
- Cache recent (< 5× max_age)
- Sumup snapshots not corrupted
- Notify.php responding

---

**Last Updated:** 2026-07-11  
**Maintainer:** InfoPedia Backend Team

