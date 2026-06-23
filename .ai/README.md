# .ai — Authoritative, Read-Only Project Memory

> ⛔ **DO NOT EDIT FILES IN THIS DIRECTORY.**
> Edit only `.specify/memory/constitution.md` (source of truth), then copy here.
> These files are loaded by every AI agent at the start of every conversation.

## Files (load order)

1. [`constitution.md`](./constitution.md) — **highest priority**, all principles + governance (CG5)
2. [`requirements.md`](./requirements.md) — index + traceability matrix
3. [`requirements/S1-branch.md`](./requirements/S1-branch.md) — load only when working on S1
4. [`requirements/S2-plan.md`](./requirements/S2-plan.md) — load only when working on S2
5. [`requirements/S3-failing-test.md`](./requirements/S3-failing-test.md) — load only when working on S3
6. [`requirements/S4-implement.md`](./requirements/S4-implement.md) — load only when working on S4
7. [`requirements/S5-green-regression.md`](./requirements/S5-green-regression.md) — load only when working on S5
8. [`requirements/S6-merge.md`](./requirements/S6-merge.md) — load only when working on S6

## How to update

```
1. Edit .specify/memory/constitution.md  (bump version, update Sync Impact Report)
2. Copy to .ai/:
   Copy-Item .specify\memory\constitution.md .ai\constitution.md
   Copy-Item .specify\memory\requirements.md .ai\requirements.md
   Copy-Item .specify\memory\requirements .ai\requirements -Recurse -Force
3. Commit: docs(constitution): <summary>
```

