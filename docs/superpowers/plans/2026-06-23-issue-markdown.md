# Issue Markdown Format Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace plain-text bug report format with markdown, add a reusable pure-JS renderer to `issues.php`'s detail view.

**Architecture:** Three independent changes: (1) `md-renderer.js` — pure `renderMd(text)` function, tested via Node.js; (2) `issues.php` — updated parsers, glob pattern, Verlauf format, and detail view wiring; (3) `app2.html` — `buildReportText` and `buildFullReport` emit markdown. Existing `.txt` files are left untouched.

**Tech Stack:** Vanilla JS (ES6), PHP 8.3, no third-party libraries.

## Global Constraints

- CP1: No classes, no framework, no Composer — plain procedural PHP and vanilla JS only.
- CA1: Simple first — readable over clever.
- CA2: No third-party libraries — renderer is hand-written.
- `md-renderer.js` exposes exactly one function: `renderMd(text: string): string` — pure, no DOM access.
- `# Title` is always line 1 of a `.md` issue file — `parse_titel()` strips the `# ` prefix.
- `append_verlauf()` must detect `\n## Verlauf` (not `\n--- Verlauf ---`) and append `- [ts] → state` list items.
- All HTML output in `renderMd()` must escape `&`, `<`, `>` in plain text; reject `javascript:` URLs.
- `just unit` must pass (83 tests) after every task.
- Existing `.txt` issue files are not migrated or deleted.

---

### Task 1: md-renderer.js — pure markdown renderer

**Files:**
- Create: `md-renderer.js`

**Interfaces:**
- Produces: `renderMd(text: string): string` — converts a markdown string to an HTML string. No DOM access, no side effects. Testable with Node.js.

**Supported syntax (exact subset to implement):**

| Input | Output |
|---|---|
| `# text` | `<h1>` |
| `## text` | `<h2>` |
| `**text**` | `<strong>` |
| `` `code` `` | `<code>` |
| ` ```...``` ` fenced block | `<pre><code>` |
| `- item` | `<ul><li>` |
| `![alt](src)` | `<img alt src>` |
| `[text](url)` | `<a href>` |
| `\| col \| … \|` + separator row | `<table><tr><th/td>` |
| blank line | paragraph boundary (skip) |
| other non-empty lines | `<p>` |

Inline transforms (`**`, `` ` ``, `![]()`, `[]()`) apply inside all block elements.

- [ ] **Step 1: Create md-renderer.js**

```js
function renderMd(text) {
    const lines = text.split('\n');
    let html = '';
    let i = 0;

    function esc(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function safeUrl(url) {
        return /^javascript:/i.test(url.trim()) ? '#' : url;
    }

    function inline(raw) {
        const pat = /!\[([^\]]*)\]\(([^)]*)\)|\[([^\]]*)\]\(([^)]*)\)|\*\*([^*]+)\*\*|`([^`]+)`/g;
        let result = '', last = 0, m;
        while ((m = pat.exec(raw)) !== null) {
            result += esc(raw.slice(last, m.index));
            last = m.index + m[0].length;
            if      (m[0][0] === '!') result += `<img alt="${esc(m[1])}" src="${safeUrl(m[2])}">`;
            else if (m[0][0] === '[') result += `<a href="${safeUrl(m[4])}">${esc(m[3])}</a>`;
            else if (m[0][0] === '*') result += `<strong>${esc(m[5])}</strong>`;
            else                      result += `<code>${esc(m[6])}</code>`;
        }
        return result + esc(raw.slice(last));
    }

    function isSep(row) {
        return row.split('|').slice(1, -1).every(c => /^[\s\-:]+$/.test(c));
    }

    while (i < lines.length) {
        const line = lines[i];

        // Fenced code block
        if (line.startsWith('```')) {
            const code = [];
            i++;
            while (i < lines.length && !lines[i].startsWith('```')) {
                code.push(esc(lines[i++]));
            }
            html += `<pre><code>${code.join('\n')}</code></pre>\n`;
            i++;
            continue;
        }

        // Headings
        if (line.startsWith('# '))  { html += `<h1>${inline(line.slice(2))}</h1>\n`;  i++; continue; }
        if (line.startsWith('## ')) { html += `<h2>${inline(line.slice(3))}</h2>\n`; i++; continue; }

        // Table: collect consecutive | lines
        if (line.startsWith('|')) {
            const rows = [];
            while (i < lines.length && lines[i].startsWith('|')) rows.push(lines[i++]);
            const sepIdx = rows.findIndex(isSep);
            html += '<table>\n';
            rows.forEach((row, idx) => {
                if (idx === sepIdx) return;
                const cells = row.split('|').slice(1, -1);
                const tag   = (sepIdx < 0 || idx < sepIdx) ? 'th' : 'td';
                html += '<tr>' + cells.map(c => `<${tag}>${inline(c.trim())}</${tag}>`).join('') + '</tr>\n';
            });
            html += '</table>\n';
            continue;
        }

        // List: collect consecutive - lines
        if (line.startsWith('- ')) {
            html += '<ul>\n';
            while (i < lines.length && lines[i].startsWith('- ')) {
                html += `<li>${inline(lines[i++].slice(2))}</li>\n`;
            }
            html += '</ul>\n';
            continue;
        }

        // Blank line
        if (line.trim() === '') { i++; continue; }

        // Paragraph
        html += `<p>${inline(line)}</p>\n`;
        i++;
    }

    return html;
}
```

- [ ] **Step 2: Verify headings, lists, and inline transforms via Node.js**

```bash
node -e "
$(cat md-renderer.js)
const out = renderMd('# Hello\n\n## World\n\n- one\n- **two**\n\nparagraph with \`code\`');
console.assert(out.includes('<h1>Hello</h1>'),        'h1');
console.assert(out.includes('<h2>World</h2>'),        'h2');
console.assert(out.includes('<li>one</li>'),           'li');
console.assert(out.includes('<strong>two</strong>'),  'strong');
console.assert(out.includes('<code>code</code>'),     'code');
console.assert(out.includes('<p>paragraph'),          'p');
console.log('OK: headings, lists, inline');
"
```

Expected: `OK: headings, lists, inline`

- [ ] **Step 3: Verify fenced code block, table, image, link, and XSS escaping**

```bash
node -e "
$(cat md-renderer.js)

const fence = renderMd('\`\`\`\nThema: /root\nKarten: 5\n\`\`\`');
console.assert(fence.includes('<pre><code>'), 'pre');
console.assert(fence.includes('Thema: /root'), 'fence content');

const tbl = renderMd('| A | B |\n|---|---|\n| x | y |');
console.assert(tbl.includes('<table>'), 'table');
console.assert(tbl.includes('<th>A</th>'), 'th');
console.assert(tbl.includes('<td>x</td>'), 'td');

const img = renderMd('![alt text](http://example.com/img.png)');
console.assert(img.includes('<img alt=\"alt text\"'), 'img');
console.assert(img.includes('src=\"http://example.com/img.png\"'), 'src');

const lnk = renderMd('[click me](https://example.com)');
console.assert(lnk.includes('<a href=\"https://example.com\">click me</a>'), 'a');

const xss = renderMd('<script>alert(1)</script>');
console.assert(!xss.includes('<script>'), 'xss');
console.assert(xss.includes('&lt;script&gt;'), 'escaped');

const jsu = renderMd('[bad](javascript:alert(1))');
console.assert(jsu.includes('href=\"#\"'), 'js: blocked');

console.log('OK: fence, table, img, link, xss');
"
```

Expected: `OK: fence, table, img, link, xss`

- [ ] **Step 4: Commit**

```bash
git add md-renderer.js
git commit -m "feat(issues): add md-renderer.js — pure renderMd() function"
```

---

### Task 2: issues.php — update for .md format + wire renderer

**Files:**
- Modify: `issues.php`

**Interfaces:**
- Consumes: `md-renderer.js` (Task 1) — `renderMd(text)` loaded via `<script src>`
- Changes: `parse_titel()`, `render_overview()` glob, `append_verlauf()`, `render_detail()`, `html_head()`

- [ ] **Step 1: Update `parse_titel()` regex**

Find:
```php
    if ($line !== false && preg_match('/^Titel:\s*(.+)/u', rtrim($line), $m)) {
```

Replace with:
```php
    if ($line !== false && preg_match('/^#\s+(.+)/u', rtrim($line), $m)) {
```

- [ ] **Step 2: Update `render_overview()` glob pattern**

Find:
```php
        $files = is_dir($dir) ? (glob("$dir/*.txt") ?: []) : [];
```

Replace with:
```php
        $files = is_dir($dir) ? (glob("$dir/*.md") ?: []) : [];
```

- [ ] **Step 3: Update `append_verlauf()` for markdown Verlauf section**

Replace the entire `append_verlauf` function:
```php
function append_verlauf(string $path, string $state): void {
    $content = file_get_contents($path);
    $entry   = '- [' . date('Y-m-d H:i:s') . '] → ' . $state;
    if (str_contains($content, "\n## Verlauf")) {
        file_put_contents($path, rtrim($content) . "\n" . $entry);
    } else {
        file_put_contents($path, rtrim($content) . "\n\n## Verlauf\n" . $entry);
    }
}
```

- [ ] **Step 4: Update `render_detail()` — replace `<pre>` with `<div id="md-body">`**

Find:
```php
    $content = htmlspecialchars(file_get_contents($issue['path']));
```

Replace with:
```php
    $raw  = file_get_contents($issue['path']);
    // Skip line 1 (# Title) — already shown in the PHP <h1> above
    $body = ltrim(substr($raw, (strpos($raw, "\n") ?: 0) + 1));
```

Find:
```php
<pre><?= $content ?></pre>
```

Replace with:
```php
<div id="md-body" data-raw="<?= htmlspecialchars($body) ?>"></div>
```

- [ ] **Step 5: Update `html_head()` — add CSS for md-body + load renderer**

Inside the `<style>` block in `html_head()`, add after the existing `pre { ... }` rule:
```css
#md-body code { background:#f0f0f0; padding:0.1em 0.3em; border-radius:2px; font-size:0.88em; font-family:monospace; }
#md-body img { max-width:100%; height:auto; }
#md-body ul { margin:0.5rem 0; padding-left:1.5rem; }
#md-body p { margin:0.5rem 0; line-height:1.5; }
#md-body h2 { border-bottom:1px solid #eee; padding-bottom:0.2rem; }
```

After the closing `</style>` tag (still inside `html_head()`), add:
```html
<script src="md-renderer.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('md-body');
    if (el) el.innerHTML = renderMd(el.dataset.raw);
});
</script>
```

- [ ] **Step 6: Verify syntax**

```bash
php -l issues.php
```

Expected: `No syntax errors detected in issues.php`

- [ ] **Step 7: Create a fixture .md file and verify with server**

```bash
cat > data/issues/new/2026-06-23_12-00-00_mdtest.md << 'EOF'
# Markdown Render Test

Zeit: 2026-06-23T12:00:00Z | Version: 0.3.1

## Letzte Aktionen
- 11:59:50  navigateTo: /root
- 11:59:40  vote: /root/abc

## Zustand
```
Thema:  /root
Tenant: [verborgen]
Filter: alle
Suche:  keine
Karten: 3
```

## Fehlerdetails
**Fehler:** fetch failed
**Status:** 0
**URL:** `/entries.php`
**Detail:** TypeError: failed to fetch
EOF
```

Start server: `just serve`

Then check:
```bash
# Overview lists the fixture
curl -s http://localhost:8080/issues.php | grep -o 'Markdown Render Test'
# Expected: Markdown Render Test

# Detail page contains md-body div (renderer wired up)
curl -s "http://localhost:8080/issues.php?id=2026-06-23_12-00-00_mdtest.md" | grep -o 'id="md-body"'
# Expected: id="md-body"

# parse_titel reads # heading correctly
curl -s "http://localhost:8080/issues.php?id=2026-06-23_12-00-00_mdtest.md" | grep -o 'Markdown Render Test'
# Expected: Markdown Render Test (in the <h1> PHP title)
```

Open `http://localhost:8080/issues.php?id=2026-06-23_12-00-00_mdtest.md` in browser and verify:
- Title shows "Markdown Render Test" with state badge
- Body renders `## Letzte Aktionen` as an `<h2>`, list items as `<ul><li>`, fenced block as `<pre><code>`
- `**Fehler:**` renders as bold
- `` `/entries.php` `` renders as inline code

- [ ] **Step 8: Verify Verlauf append writes markdown list items**

```bash
# POST a transpose (server must be running)
curl -s -X POST \
  "http://localhost:8080/issues.php?id=2026-06-23_12-00-00_mdtest.md&set=ready" \
  -o /dev/null -w "%{http_code}"
# Expected: 302

grep "## Verlauf" data/issues/ready/2026-06-23_12-00-00_mdtest.md
# Expected: ## Verlauf

grep "^\- \[" data/issues/ready/2026-06-23_12-00-00_mdtest.md
# Expected: - [2026-06-23 ...] → ready
```

- [ ] **Step 9: Clean up fixture**

```bash
rm -f data/issues/ready/2026-06-23_12-00-00_mdtest.md \
      data/issues/new/2026-06-23_12-00-00_mdtest.md
```

- [ ] **Step 10: Run unit tests**

```bash
just unit
```

Expected: `OK — 83 passed, 0 failed`

- [ ] **Step 11: Commit**

```bash
git add issues.php
git commit -m "feat(issues): update issues.php for .md format + wire md-renderer"
```

---

### Task 3: app2.html — emit markdown bug reports

**Files:**
- Modify: `app2.html`

**Interfaces:**
- Changes: `buildReportText(errorCtx)` and `buildFullReport()` only
- Produces: files whose line 1 is `# <titel>` and whose sections use `##` headings

- [ ] **Step 1: Replace `buildReportText()`**

Find the entire existing function:
```js
function buildReportText(errorCtx) {
    const lines = [];
    lines.push("Zeit:    " + new Date().toISOString());
    lines.push("Version: " + APP_VERSION);
    lines.push("");
    lines.push("--- Letzte Aktionen ---");
    if (actionTrail.length === 0) {
        lines.push("(keine)");
    } else {
        actionTrail.slice().reverse().forEach(a => {
            lines.push(a.ts.slice(11, 19) + "  " + a.action + (a.detail ? ": " + a.detail : ""));
        });
    }
    lines.push("");
    lines.push("--- Zustand ---");
    lines.push(buildStateSnapshot());
    if (errorCtx) {
        lines.push("");
        lines.push("--- Fehlerdetails ---");
        lines.push(
            (errorCtx.label  ? "Fehler: "  + errorCtx.label + "\n" : "") +
            (errorCtx.status ? "Status: "  + errorCtx.status + "\n" : "") +
            (errorCtx.url    ? "URL:    "  + errorCtx.url + "\n" : "") +
            (errorCtx.err    ? "Detail: "  + String(errorCtx.err) : "")
        );
    }
    return sanitiseForReport(lines.join("\n"));
}
```

Replace with:
```js
function buildReportText(errorCtx) {
    const lines = [];
    lines.push("Zeit: " + new Date().toISOString() + " | Version: " + APP_VERSION);
    lines.push("");
    lines.push("## Letzte Aktionen");
    if (actionTrail.length === 0) {
        lines.push("*(keine)*");
    } else {
        actionTrail.slice().reverse().forEach(a => {
            lines.push("- " + a.ts.slice(11, 19) + "  " + a.action + (a.detail ? ": " + a.detail : ""));
        });
    }
    lines.push("");
    lines.push("## Zustand");
    lines.push("```");
    lines.push(buildStateSnapshot());
    lines.push("```");
    if (errorCtx) {
        lines.push("");
        lines.push("## Fehlerdetails");
        if (errorCtx.label)  lines.push("**Fehler:** " + errorCtx.label);
        if (errorCtx.status) lines.push("**Status:** " + errorCtx.status);
        if (errorCtx.url)    lines.push("**URL:** `" + errorCtx.url + "`");
        if (errorCtx.err)    lines.push("**Detail:** " + String(errorCtx.err));
    }
    return sanitiseForReport(lines.join("\n"));
}
```

- [ ] **Step 2: Replace `buildFullReport()`**

Find:
```js
function buildFullReport() {
    const titel   = document.getElementById("issue-titel").value.trim();
    const userMsg = document.getElementById("issue-user-msg").value.trim();
    const details = document.getElementById("issue-details").value;
    return "Titel: " + titel + "\n"
        + details
        + (userMsg ? "\n\n--- Nutzerbeschreibung ---\n" + userMsg : "");
}
```

Replace with:
```js
function buildFullReport() {
    const titel   = document.getElementById("issue-titel").value.trim();
    const userMsg = document.getElementById("issue-user-msg").value.trim();
    const details = document.getElementById("issue-details").value;
    const desc    = userMsg ? "\n\n## Beschreibung\n" + userMsg : "";
    return "# " + titel + "\n\n" + details + desc;
}
```

- [ ] **Step 3: Run unit tests**

```bash
just unit
```

Expected: `OK — 83 passed, 0 failed`

- [ ] **Step 4: Verify in browser**

Open `http://localhost:8080/app2.html`. Open Settings → Bug Melden:
1. Type a title, e.g. "Test Markdown Report"
2. Type a description, e.g. "Reproduktion: ..."
3. Click 📋 Kopieren
4. Paste into a text editor — verify line 1 is `# Test Markdown Report`
5. Verify `## Letzte Aktionen`, `## Zustand` sections present with `- ` list items and fenced code block
6. Click 📤 Senden — verify a new `.md` file appears in `data/issues/new/`
7. Check `data/issues/new/<new-file>.md` line 1 is `# Test Markdown Report`
8. Open `http://localhost:8080/issues.php` — verify the new issue appears in the Neu list
9. Click the issue — verify it renders as HTML (headings, list, code block)

- [ ] **Step 5: Commit**

```bash
git add app2.html
git commit -m "feat(issues): emit markdown bug reports from Bug Melden dialog"
```

---

## Cleanup

```bash
just unit
```

Expected: `OK — 83 passed, 0 failed`

Remove any leftover fixture files:
```bash
rm -f data/issues/new/2026-06-23_12-00-00_mdtest.md \
      data/issues/ready/2026-06-23_12-00-00_mdtest.md
```
