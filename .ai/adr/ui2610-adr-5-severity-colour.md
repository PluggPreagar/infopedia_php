# UI2610-ADR-5: Severity colour per cell, black core

- **Status:** accepted
- **Date:** 2026-10-04
- **Deciders:** user (Mart) + Claude
- **Supersedes:** UI2610-ADR-4, tone part only (teal tones, "entwertet")
- **Relates to:** REQ-UI2610-20, -29, `assets/ui2610-core.js` `cellStyle()`, `iconSvg()`

## Context

The box indicator (ADR-4) was drawn in teal only, so a likely and severe risk looked the same as a harmless one.
Variants found in the repos: the Schema F Pro/Contra palette, the green→red direction wash from `frueher_besser.html`, and `--color-error`.
The user chose severity colour per cell, with a black core.

## Decision

- **Cell colour = severity:** score = kP + kI (0..8) mapped to `SEVERITY` (9 steps): `#2f9e5b` green … `#f2b600` amber … `#b02525` red.
- **Ring = opacity:** ring 1 at 75 %, ring ≥ 2 at 50 %. So the shade still shows distance from the core.
- **Core = black square** (`#14171A`).
- **Single point** (all σ 0): the core shows its own severity colour, faded (50 %). Nothing else is drawn.
- "entwertet" is dropped: the core is always black.
- Unchanged: box geometry (ADR-4), the frame rail, the data model, the dashed group outline in the editor.

## Alternatives considered

- **Direction wash** (green→red background, teal box). Rejected by the user in favour of a direct colour per cell.
- **Magenta scale** (Schema F Contra) with a black core cell or dot. Rendered and compared; the user chose green→red.
- **Contra palette per Item type.** Rejected: it shows the type of Item, not how bad it is.

## Consequences

- High risk reads as red even at 12 px.
- ⚠️ Red/green is hard to tell apart for colour-blind users. The amber middle and the black core help, but this is not fully accessible.
- A single point is faint at ≤ 16 px. Accepted for now.
