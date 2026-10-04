# UI2610-ADR-3: Indicator model: 5×5 grid, asymmetric σ, group + own

- **Status:** accepted
- **Date:** 2026-10-04
- **Deciders:** user (Mart) + Claude
- **Relates to:** REQ-UI2610-20..27, `../icon-schema-f-weiss-3ton.html` (Waage, Schema F)

## Context

Each Item shows an indicator icon: P (Eintrittswahrscheinlichkeit) × Impact, each with a spread (σ).
Icon source is Schema F (2026-08-10):

- 5×5 grid. P steps 10/30/50/70/90 %, Impact steps 1/2/4/7/10.
- σ level 1–3 per axis. Variant F2 (tone = distance) is the default.
- Satellites are symmetric: `satellites(k, d)`.

Every Voter may edit the indicator.

## Decision

- **Snap to grid:** core cell `kP, kI` in 0..4; σ per side = step 0..3.
- **Asymmetric σ = extension:** `satellites(k, dMinus, dPlus)`. With `dMinus == dPlus` it draws exactly today's icon, so no existing icon is dropped.
- **Card shows the group value:** mean core, rounded to the nearest cell, plus σ per side, taken from the histograms.
- **Group σ = pooled** (decided 2026-10-04), per axis, all Voters incl. own:
  - μ = mean Core · SV− = Σ_{k<μ} c_k (μ−k)² / n · SV+ likewise for k > μ
  - σ−_group = √( mean(σ−²) + 2·SV− ) · σ+_group likewise → rounded, clamped to 0..3
  - Example: Cores at 1 and 3, both σ 0 → μ 2, σ− = σ+ = 1
  - Uses only the per-component histograms (no Core/σ pairing needed)
- **σ step ↔ Schema F:** step 1..3 = Schema F σ level (distance 1, 2, 2); step 0 = no satellite (extension). "entwertet" core tone only when all four sides are 3.
- **Editor edits the own value** over a faint layer showing the group value.
- **Fit:** own == group (same cell and same σ steps) → one icon, highlighted.
- **Editor gesture:** tap = core · drag towards the lower left = σ (symmetric) · keep dragging towards the upper right = σ+ (asymmetric). Within one drag σ only grows (the way back must not shrink σ−); a new tap resets.

## Alternatives considered

- **Continuous values.** Rejected: Fit would need a tolerance, and the editor would suggest more precision than the icon can show.
- **Per-Voter only, or last edit wins.** Rejected: the group view would be hidden, or edits would overwrite each other.

## Consequences

- Group σ grows with both own uncertainty and disagreement; with few Voters it widens quickly.
- Later idea: one combined icon for own vs group.
