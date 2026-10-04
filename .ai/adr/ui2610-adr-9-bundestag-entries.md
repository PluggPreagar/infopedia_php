# UI2610-ADR-9: Entries from Bundestag protocols (21st term), 20 per leaf topic

- **Status:** accepted
- **Date:** 2026-10-04
- **Deciders:** user (Mart) + Claude
- **Extends:** UI2610-ADR-8 (seed)
- **Relates to:** REQ-UI2610-39, `tools/ui2610-bt21-extract.py`, `.ai/ui2610/seed/arguments-bt21.json`, `tools/ui2610-seed.js`

## Context

The user wants 20–50 entries per topic, where an entry is a claim (Behauptung) or a decision option (Entscheidungsoption) as formulated in parliament.
The local Open Discourse data already holds the current term:
`open-discourse/python/data/02_cached/electoral_term_19_20/stage_03/electoral_term_21/speech_content/speech_content.pkl`
(41 MB · 31,322 contributions · sessions 21001–21090 · 25.03.2025–10.07.2026).
The 1.8 GB all-terms file is not needed, and it would not fit the 4 GB VM.

## Decision

- **20 entries per leaf topic** (40 leaves → 800 entries); the user chose this over 20–50 per main topic and over 50 per leaf.
- **Pipeline:**
  1. `tools/ui2610-bt21-extract.py`: keywords per leaf (`KEYWORDS`) → paragraph windows of ≤ 1400 characters → score = distinct keyword hits → top 40 per leaf, with diversity caps (≤ 8 per faction/office, 1 per speech). Presidium and speeches < 600 characters are skipped.
  2. 9 agents, one per main topic, each with the same instructions: 20 entries per leaf, about half claims and half options ("Option: …"). Each entry is grounded in one passage and carries a verbatim `quote`, speaker, role, session and date, plus a starting indicator. Neutral wording, factions balanced (≤ 40 %).
  3. Independent machine check: 800/800 entries; every quote is an exact substring of a passage with that `speech_id`; speaker and session consistent; paths valid. Mix: CDU/CSU 153 · AfD 137 · government 131 · SPD 130 · Grüne 130 · Linke 117; 429 claims / 371 options.
- **Source:** `src:[Plenarprotokoll 21/<n>, <date> · <speaker> (<faction/office>)](https://dserver.bundestag.de/btp/21/21<nnn>.pdf)`, plus `quote:<verbatim>`, shown in the Item sheet.
- Item ids `b001…b800`, kept apart from the web set `s01…s50`, both stable.

## Alternatives considered

- **20–50 per main topic.** Rejected by the user: leaves would end up uneven.
- **Fetch protocols online** (DIP API / bundestag.de). Not needed: the local data covers the current term; the cloud workspace's proxy blocks direct fetches.
- **Store the whole speech text.** Rejected: too large, and the protocol link plus a short quote is enough to check an entry.

## Consequences

- The entries are paraphrases. The verbatim quote and the protocol link let anyone check them.
- Thin leaves were partly filled from related files: Fachkräfteeinwanderung (11 passages), PISA (13), Generationen (21, only one AfD passage). `bildung/digitalisierung` contains administrative/AI digitalisation beyond schools.
- One option appears in two leaves (Bundeswehr and Schuldenbremse).
- Starting indicators are judgements by the agents, labelled "Start" until a Voter sets their own value.
