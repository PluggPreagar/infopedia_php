# TASK-P4-1 · Full Regression Suite + Format Contract Verification

**Step:** S5 — Green + Regression
**Phase:** 4 — verify everything
**~5 min** | Depends on: all Phase 0–3 tasks complete

## Constitution refs
- `CC1.4` — keep reproduction tests as permanent regression guards
- `CD1` — backward compatibility: existing tenant files unmodified
- `CD3` — format switch contract: `csv`, `txt.0.2`, `txt.0.3`, `json.0.3` keep their shapes
- `CA3` — clean & non-breaking

## Step requirements
→ [`S5-green-regression.md`](../../../.ai/requirements/S5-green-regression.md)
- REQ-S5-1: target tests GREEN
- REQ-S5-2: full suite passes
- REQ-S5-3: regression tests kept
- REQ-S5-4: backward compatibility verified
- REQ-S5-5: REFACTOR while staying green

## Checklist

### Unit test suite (all must PASS, exit 0)

```powershell
D:\_progs\xampp\php\php.exe util_test.php
D:\_progs\xampp\php\php.exe util_entry_test.php
D:\_progs\xampp\php\php.exe util_file_test.php
D:\_progs\xampp\php\php.exe util_warn_test.php
D:\_progs\xampp\php\php.exe util_upload_type_test.php
D:\_progs\xampp\php\php.exe util_infopedia_config_test.php
D:\_progs\xampp\php\php.exe util_infopedia_echo_test.php
D:\_progs\xampp\php\php.exe util_str_leng_test.php
D:\_progs\xampp\php\php.exe util_upload_raw_test.php
```

### Format contract spot-checks (CD3)

```powershell
# start server first: start_http.bat
curl "http://localhost/entry/get?sid=tst&tid=tst&force_update=1"
# → valid CSV, no null prefix, no PHP notices/warnings in body

curl "http://localhost/entry/get?sid=tst&tid=tst&format=txt.0.2"
# → txt.0.2 shape (one entry per line, /path/node format)

curl "http://localhost/entry/get?sid=tst&tid=tst&format=json.0.3"
# → valid JSON array, same field names as before
```

### Tenant file integrity (CD1, CD2)

```powershell
# Check size/mtime of tenant files — must be unchanged
Get-Item "data\entries_tst.csv", "data\entries_tst.cache" | Select-Object Name, Length, LastWriteTime
```

### No dead/pre-test code remains (CW5 REFACTOR gate)

- [ ] `sortCsvData()` removed from `read.php` ✓
- [ ] Duplicate `downloadAndCacheGoogleSheet()` + local `isCacheValid()` removed from `infopedia.php` ✓
- [ ] No `echo "topic:..."` in `parseData()` ✓
- [ ] No `str_leng()` anywhere ✓
- [ ] No `parse_ini_file` outside `util.php` ✓

## Commit

```
test(regression): verify full suite GREEN after refactor/php-functions

All unit tests pass. Format contract (csv/txt.0.2/json.0.3) verified.
Tenant file integrity confirmed. No dead code remains.
Refs: CC1.4, CD1, CD3, CA3, REQ-S5-1–REQ-S5-5
```

## Next step
→ S6 Merge gate: `.ai/requirements/S6-merge.md`

