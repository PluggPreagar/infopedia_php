# S6 -- Merge

Gate for finishing. Maps `CW9` (after `CW8` verification). See [`../constitution.md`](../constitution.md) for IDs.
Treat **MUST** as blocking. Requirement IDs: `REQ-S6-<n>`.

- **REQ-S6-0:** Verification-before-completion passed (S5 `REQ-S5-8`); do not merge
  unverified. -- `CW8`
- **REQ-S6-1:** No bugfix merges without a test that **failed before and passes
  after**. -- `CC1.5`
- **REQ-S6-2:** Commits follow Conventional Commits (`type(scope): summary`; `!` +
  `BREAKING CHANGE:` when breaking). -- `CV2`
- **REQ-S6-3:** Apply the SemVer bump matching the commit type; a bugfix commit
  references its reproduction test. -- `CV1`, `CV3`, `CC1.5`
- **REQ-S6-4:** If a normative doc changed, update `AGENTS.md` /
  `.github/copilot-instructions.md` and the constitution Sync Impact Report +
  `LAST_AMENDED_DATE`. -- `CG1`, `CG2`, `CG3`
- **REQ-S6-5:** Finish the branch: verify tests, choose merge/PR/keep/discard, and clean
  up the worktree. -- `CW9`
- **REQ-S6-6:** Change is reviewable against Core Principles, PHP-Specific Foundations,
  and Data & Compatibility Constraints. -- `CG4`


