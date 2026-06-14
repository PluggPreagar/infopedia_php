# S4 ÔÇö Implement

Gate for GREEN. Maps `CW5` (GREEN). See [`../constitution.md`](../constitution.md) for IDs.
Treat **MUST** as blocking. Requirement IDs: `REQ-S4-<n>`.

- **REQ-S4-1:** Write the **minimal** code to pass (GREEN); code written before its test
  is deleted and redone test-first. ÔÇö `CW5`, `CA11`
- **REQ-S4-2:** Plain procedural PHP only ÔÇö no classes/namespaces/Composer; reuse
  `util.php`/`util_file.php`/`util_entry.php`. ÔÇö `CP1`
- **REQ-S4-3:** New endpoints are one file = one route, set `$type`, then
  `include 'util.php'`; shared logic goes in `util_*.php`. ÔÇö `CP2`
- **REQ-S4-4:** Read tunables as `$config['key'] ?? default`; declare new settings in the
  matching `infopedia.cfg` section. ÔÇö `CC2`
- **REQ-S4-5:** Diagnostics via `log_*`; end requests with `log_return($msg)`; never
  `echo` diagnostics into the response. ÔÇö `CC3`, `CA5`
- **REQ-S4-6:** Validate input and fail fast with `die("ÔÇª")`; enforce
  `tid = [a-zA-Z0-9_-]{1,30}` and the `allowedDownloadFiles` whitelist. ÔÇö `CC4`
- **REQ-S4-7:** Self-documenting names; short `// why` comments only. ÔÇö `CC5`
- **REQ-S4-8:** Keep non-trivial logic in separate, side-effect-free functions. ÔÇö `CA6`
- **REQ-S4-9:** Parse/serialize robustly (doubled `""`, multi-line quoted, date variants)
  via `formatEntry()`. ÔÇö `CP3`, `CA4`
- **REQ-S4-10:** Keep the 80% fast path default; route the hard 20% to an opt-in branch
  without slowing it. ÔÇö `CA8`
- **REQ-S4-11:** For bugfixes, record symptom + root cause (commit body, plus `// why`
  if non-obvious). ÔÇö `CC1.2`
- **REQ-S4-12:** Fix the **root cause**, not the symptom; prefer condition-based checks
  over fixed `sleep()` in tests; add defense-in-depth for recurring bug classes. ÔÇö `CC1.6`
- **REQ-S4-13:** Persist incoming input **raw and append-only** (e.g. `data/<tid>.log`)
  **before** parsing/formatting; keep derived `.csv`/`.cache` rebuildable from it so a
  failed run can reprocess after a fix/reset. ÔÇö `CA14`, `CA4`



