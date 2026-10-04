# UI2610-ADR-1: Standalone POC page in infopedia_php

- **Status:** accepted
- **Date:** 2026-10-04
- **Deciders:** user (Mart) + Claude
- **Relates to:** REQ-UI2610-1..3, `vote.html`, `fayf_ui/CONSTITUTION.md`

## Context

VoteUI mobile needs a backend with tenants, append-only storage and live sync.
`infopedia_php` already has all three (`tid`, `votes.php`, `notify.php`).
Its API sends no CORS headers, so the page must be served from the same origin.
The alternative home was `fayf_ui` (JSON screens, L9 controllers). It has no mobile screen yet,
and below 560 px its side rails squeeze the content.

## Decision

- POC = one standalone page, `vote-mobile.html`, next to `vote.html` in `infopedia_php`.
- Plain ES JavaScript, no framework. Pattern taken from `vote.html`.
- Own tenant on https://fayf.info/dev/ (branch `dev`, `deploy.php`).
- `fayf_ui` rules still apply as style: C1–C3 (KISS, one name per thing), C7 (test first).

## Alternatives considered

- **`fayf_ui` framework + infopedia_php consumer page.** Rejected for the POC: mobile layout and vendoring cost come too early.
- **`fayf_ui` on its own `server.py`.** Rejected: no backend; CORS blocks fayf.info.

## Consequences

- Fast path: tenant, deploy and notify are reused.
- The editor is not a reusable `fayf_ui` part yet. A port is possible after the POC.
- A third UI code path exists next to `vote.html` and `app2.html`.
