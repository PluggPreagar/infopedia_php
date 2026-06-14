# S5 -- Successful Test + Regression

Gate for GREEN/REFACTOR + review + verification. Maps `CW5`, `CW6`, `CW7`, `CW8`.
See [`../constitution.md`](../constitution.md) for IDs.
Treat **MUST** as blocking. Requirement IDs: `REQ-S5-<n>`.

- **REQ-S5-1:** The target test now passes (GREEN). -- `CW5`
- **REQ-S5-2:** The **full suite** passes (`*_test.php` via `php.exe ...` /
  `start_php_test.bat`). -- `CT1`
- **REQ-S5-3:** The reproduction test is kept as a permanent regression guard. -- `CC1.4`
- **REQ-S5-4:** Verify backward compatibility: old + 0v02 formats still parse; each
  `?format=` output keeps its established shape; tenant files unaffected. -- `CD1`, `CD3`,
  `CD2`, `CP3`
- **REQ-S5-5:** REFACTOR while staying green; no dead/pre-test code remains. -- `CW5`
- **REQ-S5-6:** Request code review against the plan; critical issues block. -- `CW6`
- **REQ-S5-7:** Triage every review comment; fix it or push back with rationale; re-review
  until critical issues are clear. -- `CW7`
- **REQ-S5-8:** Verification before completion: independently exercise the actual change
  and re-run the suite; never claim done unverified. -- `CW8`
- **REQ-S5-9:** For bugfixes, the fix addresses the **root cause** (not a symptom patch);
  add defense-in-depth where the bug class can recur. -- `CC1.6`
