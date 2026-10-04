# UI2610-ADR-4: Box indicator: the σ range is drawn as a filled rectangle

- **Status:** accepted; tone part superseded by UI2610-ADR-5
- **Date:** 2026-10-04
- **Deciders:** user (Mart) + Claude
- **Supersedes:** UI2610-ADR-3, icon part only (satellites, σ step ↔ Schema F distance)
- **Relates to:** REQ-UI2610-22, `assets/ui2610-core.js` `stateGrid()`

## Context

Schema F draws σ as satellites on two orthogonal axes through the Core, with gaps in between.
But every combination of P and Impact inside the range is valid, so the cross shape hides
most of the range. The user's latest indicator version draws the range as one filled rectangle.

## Decision

- Fill every cell in P[kP−σP−, kP+σP+] × I[kI−σI−, kI+σI+].
- **σ step = cells** (0..3), clipped at the grid edge. This replaces Schema F's mapping step 1/2/3 → distance 1/2/2.
- **Tone = distance** (the F2 idea, kept): Core dark · ring 1 medium · ring ≥ 2 light. Ring = max(|ΔP|, |ΔI|).
- "entwertet": Core drawn light only when all four sides are 3 (kept).
- Kept from Schema F: 6×6 tile, frame rail, Pro/Contra colours, crisp 1-cell pixels.
- **Editor, low noise:** the own value is drawn exactly like the icon: one solid box of full cells, no nested squares. Dragging only widens it. The group shows as one dashed outline of its range, hidden on Fit.
- The data model is unchanged: `kP,kI,sP-,sP+,sI-,sI+`, pooled group σ (ADR-3).

| σ | Schema F (old) | Box (new) |
| --- | --- | --- |
| all 1 | `+` cross, 5 cells | 3×3 block, 9 cells |
| P −0 +2, I −1 +0 | 2 arms | 3×2 block |

## Alternatives considered

- **Keep satellites, add diagonal corner dots.** Rejected: still gaps, harder to read at 16 px.
- **Keep Schema F step→distance (1, 2, 2).** Rejected: step 3 would look the same as step 2 except for the Core tone.

## Consequences

- Symmetric σ no longer reproduces Schema F pixel-for-pixel. The golden test against Schema F is removed and replaced by box tests.
- The editor and the cards render the box automatically, because both use `stateGrid()`.
- Edge clipping hides part of an asymmetric range at the border. Accepted.
