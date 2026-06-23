# Design: Issue Content Format — Markdown

**Date:** 2026-06-23
**Branch:** `feature/issue-content-markdown`
**Scope:** Change bug report format from plain text to markdown; render in issues.php detail view via custom inline JS renderer.
**Status:** Approved

---

## Overview

Bug reports submitted via app2's Bug Melden dialog are currently stored as `.txt` files with `--- Section ---` plain-text headers. This change converts them to `.md` files using standard markdown syntax, and adds a purpose-built inline JS renderer to `issues.php` so the detail view renders them as HTML.

No third-party library. The renderer covers exactly the subset emitted by the report builder — nothing more.

---

## File Format

**Extension:** `.md` (was `.txt`)

**Title line:** `# <title>` on line 1 (was `Titel: <title>`)

```markdown
# Login schlägt fehl nach Token-Ablauf

Zeit: 2026-06-23T14:30:12Z | Version: 0.3.1

## Beschreibung
Was der Nutzer beschrieben hat...

## Letzte Aktionen
- 14:29:55  navigateTo: /thema/foo
- 14:29:44  vote: /root/abc

## Zustand
```
Thema:  /root
Tenant: [verborgen]
Filter: alle
Suche:  keine
Karten: 5
```

## Fehlerdetails
**Fehler:** fetch failed
**Status:** 0
**URL:** `/entries.php`
**Detail:** TypeError: failed to fetch

## Verlauf
- [2026-06-23 14:35:22] → ready
- [2026-06-23 16:10:05] → inProgress
```

Rules:
- `# Title` is always line 1 — `parse_titel()` strips leading `# `.
- `## Beschreibung` section omitted entirely if user left description empty.
- `## Fehlerdetails` section omitted if report was triggered manually (no error context).
- `## Verlauf` section appended on first state transition; subsequent transitions add one `- [ts] → state` line. `append_verlauf()` detects `\n## Verlauf` (not `\n--- Verlauf ---`).

---

## app2.html Changes

### `buildReportText(errorCtx)`

Outputs markdown body (everything except the title line):

```
Zeit: <iso> | Version: <ver>

## Letzte Aktionen
- HH:MM:SS  action: detail
- HH:MM:SS  action
(or: *(keine)* if empty)

## Zustand
```
Thema:  ...
Tenant: [verborgen]
Filter: ...
Suche:  ...
Karten: N
```

## Fehlerdetails          ← only if errorCtx
**Fehler:** ...
**Status:** ...
**URL:** `...`
**Detail:** ...
```

### `buildFullReport()`

Assembles the full `.md` file:

```
# <titel>

<output of buildReportText()>

## Beschreibung
<userMsg>                  ← entire section omitted if userMsg is empty
```

`Titel:` prefix removed — title is now a bare `# heading`.

### No other changes

`issue.php` (POST receiver) unchanged — title is still the first line of `report`.
The Titel input, validation, and send-button guard in `app2.html` are unchanged.

---

## issues.php Changes

### `parse_titel(string $path): string`

Updated regex to strip `# ` prefix:

```php
preg_match('/^#\s+(.+)/u', rtrim($line), $m)
```

### `render_overview()`

Glob pattern: `*.md` (was `*.txt`).

### `append_verlauf(string $path, string $state): void`

Detects `\n## Verlauf` (was `\n--- Verlauf ---`).
Appends `- [YYYY-MM-DD HH:MM:SS] → <state>` list item lines.
Creates `\n\n## Verlauf\n- [ts] → <state>` on first transition.

### `render_detail()`

Serves raw markdown into `<div id="md-body">` via `data-raw` attribute.
Removes the `<pre>` wrapper. The JS renderer populates `innerHTML` on load.

```php
<div id="md-body" data-raw="<?= htmlspecialchars(file_get_contents($issue['path'])) ?>"></div>
```

---

## JS Renderer

Inline `<script>` in `html_head()`. Runs once on `DOMContentLoaded`.

### Supported syntax

| Syntax | Output |
|---|---|
| `# text` | `<h1>` |
| `## text` | `<h2>` |
| `**text**` | `<strong>` |
| `` `code` `` | `<code>` |
| ` ```...``` ` fenced block | `<pre><code>` |
| `- item` | `<ul><li>` |
| `![alt](src)` | `<img alt src>` |
| `[text](url)` | `<a href>` |
| `\| col \| … \|` + separator row | `<table><tr><td>` |
| blank line | paragraph boundary |
| other lines | `<p>` |

### Inline transforms

Applied within all block elements (headings, list items, table cells, paragraphs):
- `**text**` → `<strong>`
- `` `code` `` → `<code>`
- `![alt](src)` → `<img>`
- `[text](url)` → `<a>`

### Rendering approach

1. Split input into lines.
2. Pass through a state machine: detect fenced code blocks (verbatim pass-through), tables (collect rows until non-table line), lists (collect `- ` lines), headings, blank lines (flush current paragraph), fallback paragraph.
3. Apply inline transforms to each rendered text segment.
4. Set `document.getElementById('md-body').innerHTML = result`.

### Security

All non-code text content is passed through a minimal escape (`&`, `<`, `>`) before inline transforms, so raw HTML in issue files cannot inject markup. `src`/`href` attributes are set as attributes (not via innerHTML) or sanitised to reject `javascript:` URLs.

---

## Implementation Scope

| File | Change |
|---|---|
| `issues.php` | `parse_titel()`, `render_overview()` glob, `append_verlauf()` header, `render_detail()` output, `html_head()` JS renderer |
| `app2.html` | `buildReportText()`, `buildFullReport()` |
| `issue.php` | No change |
| `data/issues/*/` | Existing `.txt` files unaffected (old format, not renamed) |

Out of scope: migrating existing `.txt` files, full CommonMark compliance, image upload.
