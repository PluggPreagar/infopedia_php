# Requirements per Development Step ÔÇö Index

Derived from [`constitution.md`](./constitution.md) (v1.6.0). Requirements are split into
**one file per step** so each task loads only its own context. Each requirement traces
back to constitution IDs (`CG`, `CA`, `CC`, `CW`, `CD`, `CP`, `CT`, `CV`).

| Step | File | Maps |
| --- | --- | --- |
| S1 Branch | [`requirements/S1-branch.md`](./requirements/S1-branch.md) | `CW2` |
| S2 Plan | [`requirements/S2-plan.md`](./requirements/S2-plan.md) | `CW3` |
| S3 Failing Test | [`requirements/S3-failing-test.md`](./requirements/S3-failing-test.md) | `CW5` (RED) |
| S4 Implement | [`requirements/S4-implement.md`](./requirements/S4-implement.md) | `CW5` (GREEN) |
| S5 Green + Regression | [`requirements/S5-green-regression.md`](./requirements/S5-green-regression.md) | `CW5`, `CW6`, `CW7`, `CW8` |
| S6 Merge | [`requirements/S6-merge.md`](./requirements/S6-merge.md) | `CW9` |

Requirement IDs follow `REQ-S<step>-<n>`. Treat **MUST** as blocking.

---

## Traceability matrix (ID ÔåÆ steps)

| Constitution ID | Covered in |
| --- | --- |
| CG1, CG2, CG3 | S6 |
| CG4 | S6 |
| CA1, CA2, CA8 | S2 |
| CA4 | S4 |
| CA5 | S4 |
| CA6 | S2, S4 |
| CA7 | S2 |
| CA9 | S2 |
| CA11 | S3, S4 |
| CA12 | S3 |
| CA13 | S2 |
| CA14 | S4 |
| CC1.1, CC1.3 | S3 |
| CC1.2 | S4 |
| CC1.4 | S5 |
| CC1.5 | S6 |
| CC1.6 | S4, S5 |
| CC2 | S2, S4 |
| CC3 | S4 |
| CC4 | S4 |
| CC5 | S4 |
| CW1 | S1 |
| CW2 | S1 |
| CW3 | S2 |
| CW5 | S3, S4, S5 |
| CW6 | S5 |
| CW7 | S5 |
| CW8 | S5, S6 |
| CW9 | S6 |
| CD1, CD2, CD3 | S2, S5 |
| CP1, CP2 | S4 |
| CP3 | S2, S3, S4, S5 |
| CT1 | S1, S3, S5 |
| CV1, CV3 | S2, S6 |
| CV2 | S1, S6 |

> Not step-gated (situational references): **CA3, CA10, CD4, CT2, CT3, CT4, CT5, CW4** ÔÇö
> apply whenever relevant (e.g. `CW4` when dispatching subagents / parallel agents,
> `CD4` when touching `infopedia.html`).




