# Issue Inline Edit — Design Spec

## Goal

Allow editing an issue's full raw markdown content directly in the detail view, without a page reload. On save, the updated content is written to disk and re-rendered in place.

## Scope

- Editing is available on all issue states.
- Edit UI is inline: a textarea replaces the rendered body; no separate edit page.
- The full raw file content (including the `# Title` line) is editable.

---

## Backend — `issue.php`

### Routing

`issue.php` gains a second path alongside creation, distinguished by `$_POST['filename']`:

| `filename` field | Behaviour |
|---|---|
| present and non-empty | Edit path — overwrite existing file, return `200` |
| absent or empty | Creation path — unchanged, return `201` |

### Edit path

1. Sanitize: `$filename = $_POST['filename'] ?? ''`
2. Resolve: `$target = realpath($issueDir . '/' . $filename)`
3. Validate:
   - `$target !== false`
   - `str_starts_with($target, realpath($issueDir) . '/')` — blocks path traversal
   - `is_file($target)` — file must exist
   - Any failure → `404 NOT_FOUND`
4. Write: `file_put_contents($target, $report)` — failure → `500 WRITE_ERROR`
5. Return: `respond_json(['status' => 'ok'], 200)`

### `filename` format

Sent as `"$state/$id"` — e.g. `"inProgress/2026-06-22_10-00-00_abc.md"`. Both values are already available in `render_detail`.

### Existing validation unchanged

`$report` empty-check and 64 KB cap apply to both paths.

---

## Frontend — `issues.php`

### HTML additions in `render_detail`

Below the existing transition-button `.actions` div, add:

```html
<button id="edit-btn">Bearbeiten</button>

<textarea id="edit-area" hidden
  rows="20"
  style="width:100%;box-sizing:border-box;font-family:monospace;font-size:0.85rem;margin-top:0.5rem;">
</textarea>

<div id="edit-bar" hidden
  style="margin:0.5rem 0;display:flex;gap:0.5rem;align-items:center;">
  <button id="save-btn">Speichern</button>
  <button id="cancel-btn">Abbrechen</button>
  <span id="edit-err" style="color:#c00;font-size:0.85rem;"></span>
</div>
```

`#md-body` follows immediately after (unchanged).

At the bottom of `render_detail`, before `html_foot()`:

```html
<script>
function initEdit(filename, fullRaw) {
    const mdBody    = document.getElementById('md-body');
    const editBtn   = document.getElementById('edit-btn');
    const editArea  = document.getElementById('edit-area');
    const editBar   = document.getElementById('edit-bar');
    const saveBtn   = document.getElementById('save-btn');
    const cancelBtn = document.getElementById('cancel-btn');
    const editErr   = document.getElementById('edit-err');

    editBtn.addEventListener('click', () => {
        editArea.value = fullRaw;
        mdBody.hidden  = true;
        editBtn.hidden = true;
        editArea.hidden = false;
        editBar.hidden  = false;
        editErr.textContent = '';
    });

    cancelBtn.addEventListener('click', () => {
        mdBody.hidden   = false;
        editBtn.hidden  = false;
        editArea.hidden = true;
        editBar.hidden  = true;
    });

    saveBtn.addEventListener('click', () => {
        saveBtn.disabled = true;
        fetch('issue.php', {
            method: 'POST',
            body: new URLSearchParams({ report: editArea.value, filename })
        })
        .then(r => r.ok ? r.json() : Promise.reject(r.status))
        .then(() => {
            const newRaw = editArea.value;
            const nl     = newRaw.indexOf('\n');
            const titleLine = nl >= 0 ? newRaw.slice(0, nl) : newRaw;
            const bodyPart  = nl >= 0 ? newRaw.slice(nl + 1) : '';

            mdBody.dataset.raw = bodyPart;
            mdBody.innerHTML   = renderMd(bodyPart);

            const m = titleLine.match(/^#\s+(.+)/u);
            if (m) {
                const h1 = document.querySelector('h1');
                if (h1) {
                    for (const node of h1.childNodes) {
                        if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) {
                            node.textContent = m[1] + ' ';
                            break;
                        }
                    }
                }
            }

            fullRaw         = newRaw;
            mdBody.hidden   = false;
            editBtn.hidden  = false;
            editArea.hidden = true;
            editBar.hidden  = true;
        })
        .catch(() => {
            editErr.textContent = 'Speichern fehlgeschlagen.';
        })
        .finally(() => { saveBtn.disabled = false; });
    });
}
initEdit(<?= json_encode($current . '/' . $id) ?>, <?= json_encode($raw) ?>);
</script>
```

### Data flow

- `$raw` — full file content including `# Title` line, passed via `json_encode`
- `$current . '/' . $id` — relative path within `$issueDir`, sent as `filename` on save
- On success: body re-rendered via `renderMd(bodyPart)`; `<h1>` first text node updated if title changed; `fullRaw` synced for subsequent edits

---

## Testing — `test/e2e.php`

| Case | Input | Expected |
|---|---|---|
| Edit existing file | POST `report=updated&filename=<state>/<id>` | `200`, file content = `updated` |
| Creation unchanged | POST `report=x` (no filename) | `201`, new file created |
| Path traversal | POST `filename=../../../etc/passwd` | `404` |
| Non-existent file | POST `filename=new/nonexistent.md` | `404` |

Tests create and clean up temp files under `data/issues/` (use a dedicated `test_` prefixed filename to avoid collisions).
