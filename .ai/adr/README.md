# ADRs: architecture decision records

A log of **significant, hard-to-reverse** decisions. Template adapted from
`fayf_smartskills/.ai/adr/README.md`.

## Lifecycle

- **Status** goes `proposed` → `accepted` → `superseded by <id>` | `deprecated`.
- An accepted ADR is never edited to change its decision. Write a new ADR that supersedes it.
- Typo and clarity fixes only.

## Files and IDs

- One file per decision: `<scope>-adr-<n>-<kebab-title>.md`.
- Scope prefix = feature scope, e.g. `ui2610` (VoteUI mobile, started 2026-10).
- Referenced as `<SCOPE>-ADR-<n>`, e.g. `UI2610-ADR-2`.

## Template

```
# <SCOPE>-ADR-<n>: <title>

- **Status:** proposed | accepted | superseded by <id>
- **Date:** YYYY-MM-DD
- **Deciders:** <who>
- **Relates to:** <REQ ids, files>

## Context
## Decision
## Alternatives considered
## Consequences
```

## Registry

| ID | Title | Status |
| --- | --- | --- |
| UI2610-ADR-1 | Standalone POC page in infopedia_php | accepted |
| UI2610-ADR-2 | Set-kinds on the votes SumUp | accepted |
| UI2610-ADR-3 | Indicator model: 5×5 grid, asymmetric σ, group + own | accepted |
