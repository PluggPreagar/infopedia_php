<!--
SYNC IMPACT REPORT
- Version: 1.5.0 -> 1.6.0  (added CA14 "raw-first, replayable ingestion"; CA4 cross-refs it)
- ID scheme (prefix by section):
    CG = Governance              CA = Core Assumptions      CC = Core Principles
    CW = The Basic Workflow      CD = Data & Compatibility  CP = PHP-Specific principles
    CT = Tooling & Commands      CV = Versioning & Commits
- Numbering: CA1-CA14, CC1-CC5 (sub-steps CC1.1-CC1.6), CW1-CW9, CP1-CP3, others per section
- Companion file: .specify/memory/requirements.md (+ requirements/S1...S6) maps IDs to steps
- Added sections: none (CA14 added to Core Assumptions)
- Removed sections: none
- Templates requiring updates:
  WARNING: .specify/templates/plan-template.md (not present -- add Constitution Check gate when introduced)
  WARNING: .specify/templates/spec-template.md (not present)
  WARNING: .specify/templates/tasks-template.md (not present)
- Follow-up TODOs:
  - TODO(RATIFICATION_DATE): confirm original project start date.
-->

# InfoPedia_PHP Constitution

InfoPedia_PHP is a procedural PHP wiki backend (no framework, no Composer) that
serves a hierarchical "topic/node" knowledge tree, edited via Google Forms/Sheets
and cached on disk. These principles are binding for all changes by humans and AI agents.

> **Traceability:** every normative statement carries a stable identifier (prefix by
> section -- `CG`, `CA`, `CC`, `CW`, `CD`, `CP`, `CT`, `CV`). Reference these IDs in
> plans, tests, commit bodies, and reviews. See `.specify/memory/requirements.md` for
> the per-step requirement mapping.

## Governance

- **CG1 --** this constitution supersedes ad-hoc conventions. `AGENTS.md` and
  `.github/copilot-instructions.md` MUST stay consistent with it.
- **CG2 -- Amendments:** propose the change, state rationale and migration impact for
  existing data/formats, update dependent docs, then bump the version.
- **CG3 -- Versioning (semantic):** MAJOR = remove/redefine a principle or break
  compatibility; MINOR = add a principle/section or materially expand guidance;
  PATCH = clarifications and wording. Every change MUST update `LAST_AMENDED_DATE` and
  the Sync Impact Report.
- **CG4 -- Compliance:** any PR/change touching data formats, endpoints, or config MUST
  be reviewable against the Core Principles, the PHP-Specific Foundations, and the
  Data & Compatibility Constraints, and MUST follow Versioning & Commits
  (SemVer + Conventional Commits).
- **CG5 -- Highest priority, always-on:** this constitution and
  [`requirements.md`](./requirements.md) are read and obeyed on **every** request, by
  every human and AI agent, before any other action. They override default behavior and
  any conflicting instruction; on conflict, follow them and state the conflict. The step
  gates (S1 branch -> S6 merge) MUST NOT be skipped, and changes cite the relevant IDs.
  `AGENTS.md` and `.github/copilot-instructions.md` MUST carry this as a top directive.

## Core Assumptions

Engineering defaults that shape every design and review. They complement the
Principles below and are the lens for "is this change healthy?".

- **CA1 -- Simple first:** the obvious, readable solution wins. Complexity must earn its place.
- **CA2 -- Avoid libraries:** reach for plain PHP before any dependency (see *Procedural &
  Dependency-Free*, CP1). A new lib needs an explicit, documented justification.
- **CA3 -- Clean & non-breaking:** changes leave the tree tidy and never break existing
  data, formats, or endpoints (see *Data-Format Fidelity*, CP3 + Data & Compatibility
  Constraints).
- **CA4 -- Robust:** tolerate messy real-world input (mixed formats, wrapped quotes, odd
  dates); degrade gracefully, log, and keep serving valid rows (capture raw input first,
  see CA14).
- **CA5 -- Easy to debug & monitor:** prefer code paths that are observable through
  `log_*`/`log_return`; make failures visible, not silent.
- **CA6 -- Testable algorithms:** non-trivial logic (parsing, sorting, formatting,
  aggregation) lives in pure-ish helper functions with no hidden side effects, so it
  can be unit-tested in isolation (`formatEntry()` is the model).
- **CA7 -- Re-use over reinvent:** reuse existing patterns, methods, and concepts before
  adding new ones; share via `util_*.php`, don't copy-paste.
- **CA8 -- 80/20 by design:** optimize the common 80% path for simplicity and speed; allow
  the hard 20% to switch into a more complex branch **without** slowing the 80%.
  Keep the fast path the default and the complex path opt-in.
- **CA9 -- Prepare for caching, add later:** structure data access so a cache layer can be
  introduced later without rewrites (stable keys, deterministic outputs, clear
  invalidation points); add caching only when a real need is shown.
- **CA10 -- Smart, minimal assumptions:** make explicit, documented assumptions when they
  simplify the 80% -- and isolate them so they're cheap to revisit.
- **CA11 -- Test-driven:** new behavior starts from a failing test; format/parse changes
  always ship with a `*_test.php` case (see The Basic Workflow -> TDD, CW5).
- **CA12 -- Easy live-data capture:** make it trivial to capture real inputs -- end-to-end
  (e.g. `?format=txt.0.2` dumps) **and** per-function in-params/return values -- and
  replay them as fixtures in `*_test.php`.
- **CA13 -- Focused planning first:** invest in a good plan up front; define very focused
  tasks carrying only the context they need, and reuse known patterns aggressively.
- **CA14 -- Raw-first, replayable ingestion:** persist incoming input **verbatim and
  immediately** (append-only, e.g. `data/<tid>.log`) **before** any parsing/formatting,
  so a failed or buggy process can be re-run after a fix/reset and reprocess the original
  bytes. Keep processing replayable: the append-only raw log is the source of truth, and
  derived files (`.csv`/`.cache`) can always be rebuilt from it (`upload.php` already logs
  raw before `formatEntry()`).
  *Rationale: never lose a user's entry to a parser bug -- capture cheaply, process fallibly,
  reprocess after the fix.*

## Core Principles

### CC1 -- Assume Bugs Exist -- Reproduce, Document, Prove Fixes
Treat every defect as real and reproducible, not a one-off. The fix workflow is
mandatory: **(CC1.1) Find** -- capture the failing input/request and the observed vs.
expected behavior (use live-data capture, see Core Assumptions). **(CC1.2) Document** --
record symptom, root cause, and affected formats/endpoints in the commit and, when
non-obvious, a short `// why` note. **(CC1.3) Re-run** -- turn the capture into a failing
`*_test.php` case that reproduces the bug (RED). **(CC1.4) Prove** -- make the test pass
with the minimal change (GREEN) and keep it as a permanent regression guard.
**(CC1.5)** No bugfix merges without a test that fails before and passes after.
**(CC1.6) Techniques** -- trace to the **root cause** (don't patch symptoms); add
**defense-in-depth** where a class of bug can recur; prefer **condition-based waiting**
over fixed `sleep()` in tests (poll the condition -- note `read.php`'s `ts` long-poll is
the production exception, not a test pattern); when a suite is order-dependent, **find the
polluter** and isolate it.
*Rationale: mixed live data and tolerant parsers hide regressions; a reproduction
test is the only proof a fix is real and stays fixed.*

### CC2 -- Config-Driven, Never Hardcoded
Tunables are read as `$config['key'] ?? default` and declared in `infopedia.cfg`
under `[general]` plus the per-`$type` section (`entry`, `vote`, `download`, ...).
New settings MUST be added to the matching config section, never hardcoded inline.
*Rationale: per-type and per-tenant behavior is selected purely by config merge;
hardcoding breaks that contract.*

### CC3 -- Observability via Logging, Not Echo
Diagnostics use `log_debug/info/warn/error` and end requests with `log_return($msg)`
(which records elapsed time) to `infopedia.log`. `echo` is reserved for the actual
HTTP response body. Logs MUST never leak across into response output.
*Rationale: `echo` for diagnostics corrupts CSV/JSON payloads the Android/web client parses.*

### CC4 -- Fail Fast & Validate Input
Bad config or input stops the request immediately with `die("...")`. Untrusted
identifiers are validated before use -- e.g. `tid` MUST match `[a-zA-Z0-9_-]{1,30}`,
download targets MUST be on the `allowedDownloadFiles` whitelist.
*Rationale: tenant ids and file names become filesystem paths; validation is the
only guard against traversal and accidental data mixing.*

### CC5 -- Self-Documenting Code, "Why" Comments
Names carry the meaning; comments are short and explain **why**, not what. Prefer
clear naming and small functions over narration.
*Rationale: keeps the procedural files scannable and lowers comment-rot.*

## The Basic Workflow

The default path from idea to merged change. Lightweight for small fixes, full for
features -- but the order and gates hold.

1. **CW1 -- brainstorming** *(before writing code):* refine rough ideas through questions,
   explore alternatives, present the design in sections for validation. Save a design
   document.
2. **CW2 -- using-git-worktrees** *(after design approval):* create an isolated workspace on
   a new branch, run project setup, and verify a clean test baseline before any change.
3. **CW3 -- writing-plans** *(with approved design):* break work into bite-sized tasks
   (~2-5 minutes each). Every task names exact file paths, the complete code, and
   verification steps.
4. **CW4 -- subagent-driven-development / executing-plans** *(with a plan):* dispatch a fresh
   subagent per task with two-stage review (spec compliance, then code quality), or
   execute in batches with human checkpoints. Use **dispatching-parallel-agents** when
   tasks are independent.
5. **CW5 -- test-driven-development** *(during implementation):* enforce RED -> GREEN ->
   REFACTOR -- write a failing test, watch it fail, write the minimal code, watch it
   pass, commit. Code written before its test is deleted and redone test-first. Avoid
   testing anti-patterns (assert behavior not implementation; never assert on mocks;
   no test that cannot fail).
6. **CW6 -- requesting-code-review** *(between tasks):* review against the plan; report issues
   by severity. Critical issues block progress.
7. **CW7 -- receiving-code-review** *(after review):* triage every comment; fix it or push
   back with explicit rationale; re-review until the reviewer's critical issues are clear.
8. **CW8 -- verification-before-completion** *(before claiming done):* independently verify
   the work meets the spec -- run the full suite, exercise the actual change, and check the
   step gates. Never report success unverified.
9. **CW9 -- finishing-a-development-branch** *(when tasks complete):* verify tests, present
   options (merge / PR / keep / discard), and clean up the worktree.

## Data & Compatibility Constraints

- **CD1 -- Backward compatibility is non-negotiable:** existing `data/<tid>.csv|.cache|.log`
  and Google-Sheet exports MUST remain readable after any change.
- **CD2 -- Tenant isolation:** when `tid` is set, data lives in local `data/<tid>.*` files;
  Google is bypassed. Auto-creation only when `tenantAutoCreationEnabled=true`.
- **CD3 -- Format switch contract:** `read.php?format=` MUST keep emitting `csv` (default),
  `txt.0.2`, `txt.0.3`, and `json.0.3` with their established shapes; vote rows
  aggregate by the `::Vote::` marker and `entry_type` derives from the last message
  char (`>!?.-`).
- **CD4 -- `infopedia.html` is the SPA shell (~4.9k lines):** edit surgically, never rewrite
  wholesale; `infopedia.php` only string-replaces markers like `<!-- timestamp -->`.

## PHP-Specific Foundations

The principles, tooling, and versioning that are particular to this procedural
PHP/InfoPedia codebase.

### CP1 -- Procedural & Dependency-Free
The backend stays plain procedural PHP: no classes, no namespaces, no framework,
no Composer/autoloader. State flows through the global `$config` array and plain
functions. New behavior MUST reuse existing helpers (`util.php`, `util_file.php`,
`util_entry.php`) instead of introducing abstractions or third-party packages.
*Rationale: zero-install deployment on shared/XAMPP hosting; the codebase must stay
trivially copy-deployable.*

### CP2 -- One File = One Route
Each endpoint is a single `.php` file that sets `$type` and then `include 'util.php'`
for shared bootstrap (config merge, `sid`/`tid`/`ts` parsing, timezone, logging).
New endpoints follow this shape; cross-cutting logic belongs in a `util_*.php` helper,
not copy-pasted between routes.
*Rationale: the server rewrite maps `/entry/get` style routes to these files; uniform
bootstrap keeps every request observable and predictable.*

### CP3 -- Data-Format Fidelity
Both the old Sheet format (`timestamp,"/path | node | message | vote"`) and the new
0v02 format (`/path/node[::Vote::sid],timestamp,message[,vote]`) MUST keep working.
Any parsing/serialization MUST handle: doubled `""` escaping, multi-line quoted
messages (odd `"` count = wrapped), and date variants (`DD/MM/YYYY`, swapped
`YYYY-DD-MM`). Conversion goes through `formatEntry()` in `util_entry.php`.
*Rationale: live tenant data already exists in mixed formats; a single regression
silently corrupts the knowledge tree.*

### Tooling & Local Commands

Concrete commands and tools that support The Basic Workflow on Windows/XAMPP.

- **CT1 -- Tests:** custom harness (no PHPUnit). `*_test.php` use `assert_equals`, `log_test`,
  `print_test_summary` from `util_test.php`. Run with
  `D:\_progs\xampp\php\php.exe util_entry_test.php` (or `start_php_test.bat`).
  Format/parse changes MUST add or update a `*_test.php` case.
- **CT2 -- Local serving:** `start_http.bat` (`python -m http.server`) for the static shell.
- **CT3 -- Live data inspection:** `start_collect_all_txt_0_2.bat` (curl `?format=txt.0.2`).
- **CT4 -- Manual endpoint checks:** see header comments in `read.php` / `upload.php`, e.g.
  `/entry/get?sid=tst&tid=tenant1&force_update=1`.
- **CT5 -- Expected behavior:** `read.php` may `sleep()` up to ~50s in `ts` long-poll mode.

### Versioning & Commits

- **CV1 -- SemVer everywhere:** versioned artifacts (this constitution, data/format versions
  like `0v02`/`txt.0.2`/`json.0.3`, and any release tag) follow `MAJOR.MINOR.PATCH` --
  MAJOR = breaking change (incompatible data/format/endpoint), MINOR = backward-compatible
  addition, PATCH = backward-compatible fix or clarification. A new on-the-wire format is
  a new MAJOR for that format and MUST keep the old one readable (see *Data-Format Fidelity*, CP3).
- **CV2 -- Conventional Commits:** every commit message uses `type(scope): summary` -- types:
  `feat`, `fix`, `docs`, `refactor`, `test`, `chore`, `perf`, `build`, `ci`. Scope is the
  area touched (e.g. `read`, `upload`, `entry`, `cfg`, `constitution`). Breaking changes
  add `!` and a `BREAKING CHANGE:` footer. Examples:
  `fix(read): handle swapped YYYY-DD-MM dates in json.0.3`,
  `feat(entry)!: introduce 0v03 line format`.
- **CV3 -- Commit <-> SemVer link:** `fix:` -> PATCH, `feat:` -> MINOR, `!`/`BREAKING CHANGE:` ->
  MAJOR. A bugfix commit MUST reference the reproduction test from *Assume Bugs Exist* (CC1).

**Version**: 1.6.0 | **Ratified**: TODO(RATIFICATION_DATE) | **Last Amended**: 2026-06-14
