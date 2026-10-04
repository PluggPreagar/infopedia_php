# UI2610-ADR-8: Nested topics, cross-references, seeded arguments with default indicator

- **Status:** accepted
- **Date:** 2026-10-04
- **Deciders:** user (Mart) + Claude
- **Extends:** UI2610-ADR-7 (one topic level → any depth)
- **Relates to:** REQ-UI2610-34..36, `.ai/ui2610/seed/`, `tools/ui2610-seed.js`, `assets/ui2610-core.js`

## Context

The user wants German societal topics in 2–3 levels, with cross-references, plus up to 50 current arguments.
Each argument should have a source link and a starting indicator. A Voter who hasn't set an indicator should see that starting value.

## Decision

- **Topics at any depth:** a `kind:topic` entry can sit below another topic. Each topic holds Items and sub-topics.
- **Navigation:** the breadcrumb has one clickable link per level; deeper paths fold to "… › parent › current". The topic sheet shows: ⬆ parent · sub-topics (with Item count) · "Siehe auch" · "+ Unterthema". Sub-topic chips appear above every step view.
- **Cross-references:** the topic attribute `see:<slug-path>,…` (relative to the base) shows as ↔ buttons in the topic sheet.
- **Arguments:** the Item attribute `src:[Name](https://…)` shows as "Quelle: <link>" on the card (`rel="noopener noreferrer"`; only http(s)).
- **Default indicator:** the Item attribute `ind_default:kP,kI,sP-,sP+,sI-,sI+`. `effectiveInd(own, default)`: the Voter's own value wins, otherwise the default is used. It is labelled "Start", counts as the Voter's value in the group calculation, and pre-fills the editor. It is only written to the store once the Voter saves their own value.
- **Seed:** `.ai/ui2610/seed/topics-de.json` (56 topics, from the Ipsos Sorgenbarometer Aug 2026, R+V Ängste 2026 and the ZDF-Politbarometer) and `.ai/ui2610/seed/arguments-de.json` (50 arguments, 2025/26 sources, each opened and checked by the research agents). `tools/ui2610-seed.js` / `just ui2610-seed <url> <tid>` posts them in order (parents first). Item ids `s01..s50` are stable, so re-running only writes newer rows (latest wins).

## Alternatives considered

- **Flat topics + sub-topics as Items.** Rejected: it mixes levels; a sub-topic would itself be voted on.
- **Default indicator as a votes row from a "seed" Voter.** Rejected: it would distort the counts and show up as a fake Voter.

## Consequences

- The default counts once per Voter who hasn't set their own value. With n such Voters, the group value leans towards the default. That is intended as a starting value.
- The seed is append-only: removing it needs delete markers. So it goes into a separate tenant (`ui2610play`).
- Source URLs can go stale (e.g. the rolling BA page); they are a snapshot of 2026-10-04.
