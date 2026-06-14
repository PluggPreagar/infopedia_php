# Dispatch Manifest — refactor/php-functions

**Goal:** one fresh agent per task file.  
**Current tool limitation:** this workspace exposes only the `Plan` subagent. Direct GPT-5.5 implementation-agent spawning is not available here. This manifest is ready for an external/runner setup that can spawn GPT-5.5 agents.

## Required global context for every agent

Each agent MUST read, in order:
1. `.ai/constitution.md` (`CG5`)
2. `.ai/requirements.md`
3. the referenced `.ai/requirements/S<n>-*.md` file(s)
4. exactly one `.specify/memory/tasks/TASK-*.md`

`.ai/` is read-only.

## Safety rules

- `CW4`: use a fresh agent per task; independent tasks may run in parallel.
- `CW8`: no agent may claim success without running and reporting its verification commands.
- Tasks touching the same file are serialized unless explicitly marked safe.
- Bugfix tasks require RED evidence before GREEN (`CC1.1`–`CC1.5`).
- Merge/S6 happens only after P4 regression is complete.

## Dispatch waves

### Wave 0 — prerequisite, serial
1. `agents/AGENT-TASK-P0-1-util-test-harness.md`

### Wave 1 — helper RED/GREEN, serial pairs
2. `agents/AGENT-TASK-P1-1-util-entry-red.md`
3. `agents/AGENT-TASK-P1-2-util-entry-green.md`
4. `agents/AGENT-TASK-P1b-1-util-file-red.md`
5. `agents/AGENT-TASK-P1b-2-util-file-green.md`

### Wave 2 — bug fixes, conflict-aware
Can run in parallel only when file touch sets do not overlap:
- `agents/AGENT-TASK-P2-1-bug05-log-warn.md` touches `util.php`
- `agents/AGENT-TASK-P2-2-bug06-upload-type.md` touches `upload.php`
- `agents/AGENT-TASK-P2-3-bug07-infopedia-config.md` touches `infopedia.php`
- `agents/AGENT-TASK-P2-4-bug03-header-undef.md` touches `read.php`
- `agents/AGENT-TASK-P2-5-bug04-echo-leak.md` touches `infopedia.php` — serialize after P2-3
- `agents/AGENT-TASK-P2-6-bug01-02-str-leng.md` touches `upload.php`, `download.php` — serialize after P2-2 for `upload.php`

### Wave 3 — endpoint refactors
- `agents/AGENT-TASK-P3-1-refactor-read.md` after P2-4
- `agents/AGENT-TASK-P3-2-refactor-infopedia.md` after P2-3 + P2-5
- `agents/AGENT-TASK-P3-3-raw-first-upload.md` after P2-2 + P2-6 + P1b-2
- `agents/AGENT-TASK-P3-4-fix-statistic.md` after P2-2

### Wave 4 — final verification
- `agents/AGENT-TASK-P4-1-regression-suite.md`

## Final gate

After all agents finish, load `.ai/requirements/S6-merge.md` and perform S6 manually/with a dedicated merge agent.

