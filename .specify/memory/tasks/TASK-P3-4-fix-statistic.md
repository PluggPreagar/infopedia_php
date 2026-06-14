# TASK-P3-4 · Fix `statistic.php` — use `util.php` config + add `log_return`
**Step:** S4 | **Phase:** 3 | **~2 min**
Depends on: TASK-P2-2 ($type pattern established)
## Constitution refs
- `CC2` — config-driven: `logFile` from `$config`, never hardcoded
- `CC3` — observability: end request with `log_return`
- `CP2` — `$type` before include
## Step requirements
→ `.ai/requirements/S4-implement.md` REQ-S4-4, REQ-S4-5
## Files
| Action | File |
|---|---|
| EDIT | `statistic.php` |
## Fix
Top of file (before `header(...)`):
```php
<?php
$type = "stat"; // CP2
include_once 'util.php';
$logFile = $config['logFile'] ?? 'infopedia.log'; // CC2: config-driven, not hardcoded
```
Remove existing `$logFile = 'infopedia.log';` line.
Bottom of file (after last echo):
```php
log_return("statistic rendered"); // CC3
```
## Commit
```
fix(statistic): use util.php config for logFile, add log_return (CC2, CC3)
Refs: CC2, CC3, CP2, REQ-S4-4, REQ-S4-5
```
SemVer: PATCH — CV1, CV2, CV3
## Next task
→ TASK-P4-1-regression-suite.md
