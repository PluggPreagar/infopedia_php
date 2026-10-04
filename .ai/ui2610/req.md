# UI2610: VoteUI mobile, product requirements

Scope `ui2610` = VoteUI mobile POC, started 2026-10. IDs: `REQ-UI2610-<n>`.
These are product requirements, separate from the process requirements `REQ-S<step>-<n>` in `../requirements.md`.
**MUST** is blocking. Decisions: `../adr/ui2610-adr-*.md`.

## Glossary (one name per thing)

| Term | Meaning |
| --- | --- |
| Item | A votable thing; ≤ 50 per Session |
| Session | One topic folder `/t` in one tenant |
| Voter | Participant, identified by `sid` |
| Triage | Step 1: is the Item relevant to judge? Values In / Open / Out. Not a score |
| Rating | Step 2: score 1–5 |
| Sign | The Voter's own Rating, shown publicly with their name |
| Signer | A Voter who gave a Sign |
| Name | A Signer's public name, attribute `by:` on the Sign row |
| Trust | A Voter → Signer relation; a filter for the viewer |
| Comparison | Step 3: pairwise pick, a wins / tie / b wins |
| Indicator | Per-Item icon: P × Impact, each with σ per side |
| Core | The Indicator's centre cell (kP, kI) |
| σ step | Spread per side, 0..3 |
| Fit | Own Indicator == group Indicator |
| Group value | Aggregate over all Voters |
| Own value | The current Voter's value |

## Flow

- **REQ-UI2610-1 (MUST):** a mobile page (≥ 360 px wide) with 3 steps: Triage → Rate → Fine-tune. The Voter may switch steps at any time. -- ADR-1
- **REQ-UI2610-2 (MUST):** Triage sets relevance only: In / Open / Out. Out never counts as a Rating. -- user 2026-10-04
- **REQ-UI2610-3 (MUST):** Rate lists Items that are In or Open; it sets a Rating of 1–5; `rate:<sid>:0` clears it. -- spec
- **REQ-UI2610-4 (MUST):** the Voter can Sign a Rating and withdraw it again. -- ADR-2
- **REQ-UI2610-5 (MUST):** Fine-tune offers Comparisons between the top-rated Items. -- spec
- **REQ-UI2610-6 (MUST):** Trust is a filter only. A Sign by a trusted Signer is shown with the name. A Sign by any other Signer is shown as not signed. Trust never changes a Rating or a Ranking. -- D2
- **REQ-UI2610-7 (MUST):** the Group Ranking is built from the Rating histograms plus the Bradley–Terry ranking from the `cmp` histograms; computed in the frontend. -- ADR-2

- **REQ-UI2610-8 (MUST):** one mode per step (D9): Triage = card stack (swipe right = In · left = Out · up = Open, plus buttons; undo toast for 4 s, the POST is delayed until then) · Rate = list with 1–5 chips + Sign · Fine-tune = two cards, tap the more important one, or Tie. -- user 2026-10-04
- **REQ-UI2610-9 (MUST):** an Overview tab shows the Group Ranking. Tapping an Item opens the Item sheet with all own controls (Triage, Rating, Sign, Indicator), so any earlier decision can be changed ("jump back"). The Overview also holds the own Name and the Trust toggles. -- user 2026-10-04
- **REQ-UI2610-19 (MUST):** Triage filter "new only" shows only Items without an own Triage (it hides Items the Voter marked Open). -- user 2026-10-04

UI labels (German): Triage = "Sichten" · Rate = "Bewerten" · Fine-tune = "Feinschliff" · In / Open / Out = "Relevant / Offen / Raus" · Sign = "signieren".

## Backend (set-kinds)

- **REQ-UI2610-10 (MUST):** `parseSetKinds()` (on top of `parseEntry`) reads `<kind>:<sid>:<value>` for the kinds `tri`, `rate`, `ind`, `cmp`, `trust`, each with its own value pattern. An invalid value is logged and ignored. -- ADR-2, CA4
- **REQ-UI2610-11 (MUST):** merge uses **set** (latest value per sid per kind) for the new kinds and for `signed`; `votes` keeps **sum**. -- ADR-2
- **REQ-UI2610-12 (MUST):** the projection returns the own value plus a histogram of the others per kind (`ind`: per component). -- ADR-2
- **REQ-UI2610-13 (MUST):** the projection lists the Signers' ids, `signers:<sid>,…`, on rows that carry set-kinds. -- ADR-2
- **REQ-UI2610-18 (MUST):** `by:<name>` on a row with `signed:<sid>:1` sets that Signer's Name (1–40 chars, no `|;=,`). The projection shows `names:<sid>=<name>;…` only for current Signers. -- ADR-2
- **REQ-UI2610-17 (MUST):** `format=json` returns `sets: {kind: {sid|others: value}}`. -- ADR-2
- **REQ-UI2610-14 (MUST):** `votes.php` POST accepts a row carrying at least one valid kind. -- ADR-2
- **REQ-UI2610-15 (MUST):** backward compatible: the output for rows with `votes:` and `signed:1` stays byte-identical. -- CD1, CA3
- **REQ-UI2610-16 (MUST):** tenant isolation is unchanged (`data/votes_<tid>.*`). -- CD2

## Indicator

- **REQ-UI2610-20 (MUST):** every Item card shows the group Indicator (box indicator; colours/frame from Schema F). -- ADR-3, ADR-4
- **REQ-UI2610-21 (MUST):** values snap to the 5×5 grid: kP, kI in 0..4; σ step per side 0..3. -- ADR-3
- **REQ-UI2610-22 (MUST):** the σ range is drawn as a filled box P[kP−σP−, kP+σP+] × I[kI−σI−, kI+σI+]; σ step = cells, clipped at the grid edge; tone = ring distance. -- ADR-4 (was: Schema F satellites, ADR-3)
- **REQ-UI2610-23 (MUST):** the editor is a 2-dimensional matrix, P on x and Impact on y. Tap sets the Core; dragging towards the lower left sets a symmetric σ; continuing towards the upper right sets σ+. -- ADR-3
- **REQ-UI2610-24 (MUST):** the editor shows the own value over a faint group layer. -- ADR-3
- **REQ-UI2610-25 (MUST):** Fit → one icon instance, highlighted. -- ADR-3
- **REQ-UI2610-26 (MUST):** every Voter can edit their own Indicator. -- user
- **REQ-UI2610-28 (MUST):** group Indicator = rounded mean Core + pooled σ per side (formula in ADR-3), computed in the frontend from the histograms. -- ADR-3
- **REQ-UI2610-27 (SHOULD):** later, one combined icon for own vs group. -- idea
