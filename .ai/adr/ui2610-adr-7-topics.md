# UI2610-ADR-7: Topics: one folder per topic, marked by `kind:topic`

- **Status:** accepted
- **Date:** 2026-10-04
- **Deciders:** user (Mart) + Claude
- **Relates to:** REQ-UI2610-31, `assets/ui2610-core.js` `topicsOf()`, `slugify()`, `vote-mobile.html`

## Context

One tenant should hold several separate votes, e.g. "Klima" and "Rente". Their Items, Comparisons and Trust must not mix.

## Decision

- A topic is an entry directly below the base folder (`[vote_mobile] topic`), marked with the attribute `kind:topic`:
  `/<base>/<slug> | kind:topic | <Title>.` The slug comes from the title: lower case, ä→ae…, `[a-z0-9-]`, max 40 characters.
- The topic's Items live below it: `/<base>/<slug>/<id>`. Comparisons go under `/<slug>/~cmp/…`, Trust under `/<slug>/~trust/…`, so each topic is separate.
- The page has a topic selector plus "+ Thema". The current topic comes from `?topic=`, then the last used one (localStorage), then the first topic.
- Items directly under the base (before topics existed) show as "(ohne Thema)".

## Alternatives considered

- **One tenant per topic.** Rejected: config and data files multiply, and a Voter's name would have to be set per tenant.
- **A topic attribute on each Item.** Rejected: Comparisons and Trust would need their own topic field, and the folder already gives the scope for free.

## Consequences

- Trust is per topic (spec: "Trust … per Session").
- A topic cannot be renamed yet; a delete marker removes it.
