# S3 — Failing Test

Gate for RED. Maps `CW5` (RED), `CA11`. See [`../constitution.md`](../constitution.md) for IDs.
Treat **MUST** as blocking. Requirement IDs: `REQ-S3-<n>`.

- **REQ-S3-1:** Write the test first and **watch it fail** (RED) before writing code. —
  `CW5`, `CA11`
- **REQ-S3-2:** For a bugfix, reproduce the defect from captured live input as a failing
  `*_test.php` case. — `CC1.1`, `CC1.3`
- **REQ-S3-3:** Any format/parse change adds or extends a `*_test.php` case. — `CT1`, `CP3`
- **REQ-S3-4:** Capture fixtures both end-to-end (e.g. `?format=txt.0.2`) and per-function
  (in-params/return values). — `CA12`
- **REQ-S3-5:** Use the project harness (`assert_equals`, `log_test`,
  `print_test_summary` from `util_test.php`). — `CT1`

