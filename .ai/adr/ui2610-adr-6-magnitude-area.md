# UI2610-ADR-6: Magnitude area: grey rectangle from 0,0 to the core

- **Status:** accepted
- **Date:** 2026-10-04
- **Deciders:** user (Mart) + Claude
- **Extends:** UI2610-ADR-5 (no supersede)
- **Relates to:** REQ-UI2610-30, `assets/ui2610-core.js` `magnitudeRect()`, `iconSvg()`, `vote-mobile.html` `drawEditor()`

## Context

The user asked for a vector from 0,0 (unlikely, marginal) to the core, with the area "below" it greyed.
5 variants were rendered: none · vector · grey rectangle · rectangle + vector · triangle + vector.

## Decision

- **V2:** a grey rectangle (`#E4E6E1`) from cell 0,0 to the core cell, i.e. P[0, kP] × I[0, kI], drawn **below** the box.
- No vector line.
- Icons and the editor draw it the same way.

## Alternatives considered

- **Vector line.** Rejected: it crosses the box, and slanted lines look blurry at small sizes.
- **Rectangle + vector.** Rejected: the line adds little beyond the area (low-noise rule).
- **Triangle under the vector.** Rejected: slanted edges look blurry at small sizes, and its area says less than the rectangle's.

## Consequences

- The grey area grows with P × I, so magnitude reads even at 12 px.
- A single point (faded core) becomes recognisable at small sizes.
- When the range covers the area, the grey is hidden. Accepted.
