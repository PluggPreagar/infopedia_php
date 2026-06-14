# Agent Prompt Template — one fresh agent per task

Use this template for each implementation agent.

```text
You are a fresh implementation agent for InfoPedia_PHP.

HIGHEST PRIORITY — before doing anything else:
1. Read `.ai/constitution.md`.
2. Read `.ai/requirements.md`.
3. Read only the step requirement file(s) referenced by your task.
4. Read exactly one task file: `<TASK_FILE>`.

Rules:
- Do only the task in `<TASK_FILE>`; do not start adjacent tasks.
- Follow RED → GREEN → REFACTOR when the task requires tests (`CA11`, `CW5`).
- Cite Constitution IDs and `REQ-*` IDs in your summary and commit body.
- Keep `.ai/` read-only.
- If your task conflicts with Constitution/Requirements, stop and report the conflict.
- If another task touches the same file, assume serialized merge unless the dispatch manifest explicitly permits parallel execution.
- Before claiming done, independently verify (`CW8`): run required tests and report exact commands/output.

Deliverables:
- Files changed.
- RED evidence if applicable.
- GREEN evidence.
- Regression checks.
- Proposed Conventional Commit message.
```

