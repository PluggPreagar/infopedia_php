# UI2610-ADR-2: Set-kinds on the votes SumUp

- **Status:** accepted
- **Date:** 2026-10-04
- **Deciders:** user (Mart) + Claude
- **Relates to:** REQ-UI2610-10..16, `util_entry.php`, `util_sumup_votes.php`, `votes.php`

## Context

The store is append-only. Today `votes:<sid>:<n>` is summed per sid, and `signed:<sid>:<n>` only ever adds a Signer.
The projection returns own value + `votes:others:<sum>` + `signed_count`.
VoteUI needs **absolute** values per Voter: Triage, Rating, Sign, Indicator, Comparison, Trust.
Rule: the backend filters and counts as far as it can; the frontend does the final aggregation.

## Decision

New attribute kinds `<kind>:<sid>:<value>`, merged as **set** (latest value per sid wins).
`votes:` keeps its **sum** rule.

| Kind | Value | Path |
| --- | --- | --- |
| `tri` | `in` \| `open` \| `out` | `/t/<item>` |
| `rate` | `1`..`5`, `0` = cleared | `/t/<item>` |
| `signed` | `0` \| `1` (now set: `0` = withdraw) | `/t/<item>` |
| `by` | Signer name, 1–40 chars, no `\|` `;` `=` `,` — row attribute, bound to `signed:<sid>:1` in the same row | `/t/<item>` |
| `ind` | `kP,kI,sP-,sP+,sI-,sI+` (kP, kI 0..4 · σ 0..3) | `/t/<item>` |
| `cmp` | `-1` \| `0` \| `1` (b wins · tie · a wins) | `/t/~cmp/<a>~<b>` |
| `trust` | `0` \| `1` | `/t/~trust/<signer>` |

Projection per viewer (one row per path):

- Own value: `<kind>:<sid>:<v>`
- Others as a histogram: `<kind>:others:<v>=<n>;<v>=<n>`. For `ind`, one histogram per component: `ind:others:kP=a,b,c,d,e;kI=…;sP-=…`
- Signers: `signers:<sid>,<sid>`, only on rows that carry set-kinds (public: a Sign is public by definition)
- Names: `names:<sid>=<name>;…`, only for current Signers with a valid `by:` (latest wins; withdraw drops the name). Voters without a Sign stay anonymous
- JSON format (`format=json`): field `sets` = `{kind: {sid|others: value}}`; set-kinds are removed from `attrs` (attrs keyed by name would collapse own + others)
- Implementation: `parseSetKinds()` reads `parseEntry()`'s attrs, so `parseEntry` itself is unchanged; `votes_set_cols()`, `projectedSetKinds()`

Example:

```
POST  /t/item7 | tri:s42:in | rate:s42:5 | signed:s42:1 | by:Mart | ind:s42:2,3,1,1,0,2 | .
GET   /t/item7 | rate:s42:5 | rate:others:3=4;4=2;5=1 | signers:s17,s42 | names:s42=Mart | .
```

## Alternatives considered

- **Signer name as content column** (`… | signed:s42:1 | Mart`). Rejected: content = display text, merged as newest-wins; two meanings in one column (C2).
- **Name as own set-kind on `/t/~voter/<sid>`.** Rejected: the name belongs to the Sign; an extra path is not needed.

- **Histogram sub-paths on plain `votes:`** (one path per value, ±1 deltas). Rejected: about 25 paths per Item, and a lost request corrupts the histogram.
- **New endpoint `poll.php`** with its own CSV. Rejected: the most code, and a second notify file.

## Consequences

- `vote.html` is unaffected: it sends only `votes:` and `signed:1`, and their output stays byte-identical (golden tests T-C8/T-C9, test `ui2610_votes_test.php`).
- `signed:` semantics change from "add only" to "latest value wins". `signed:<sid>:1` stays compatible.
- Trust stays private: others see only the count of trusting Voters, never who.
- Frontend computes mean and σ from histograms, the Bradley–Terry ranking from `cmp`, and the Trust filter on `signers`.
