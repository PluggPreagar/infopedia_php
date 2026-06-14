# S2 ÔÇö Plan

Gate for planning. Maps `CW3`. See [`../constitution.md`](../constitution.md) for IDs.
Treat **MUST** as blocking. Requirement IDs: `REQ-S2-<n>`.

- **REQ-S2-1:** Break work into bite-sized tasks (~2ÔÇô5 min); each names exact file
  paths, the complete code, and verification steps. ÔÇö `CW3`
- **REQ-S2-2:** Carry only necessary context; reuse existing patterns/helpers
  (`util_*.php`). ÔÇö `CA13`, `CA7`
- **REQ-S2-3:** Choose the simplest design; no new library without documented
  justification; keep the 80% path simple. ÔÇö `CA1`, `CA2`, `CA8`
- **REQ-S2-4:** List config keys to add/extend in `infopedia.cfg` (no hardcoding). ÔÇö `CC2`
- **REQ-S2-5:** State compatibility impact for data, formats, endpoints, and tenants. ÔÇö
  `CD1`, `CD2`, `CD3`, `CP3`
- **REQ-S2-6:** Decide SemVer impact up front (`fix`/`feat`/breaking). ÔÇö `CV1`, `CV3`
- **REQ-S2-7:** If data access is involved, design cache seams (stable keys,
  deterministic output) without adding caching yet. ÔÇö `CA9`
- **REQ-S2-8:** Identify the algorithm(s) to isolate into testable functions. ÔÇö `CA6`

