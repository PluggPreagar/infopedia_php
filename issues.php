<?php
declare(strict_types=1);

$base   = __DIR__ . '/data/issues';
$states = ['new', 'ready', 'blocked', 'inProgress', 'inReview', 'canceled', 'closed'];

$id  = preg_replace('/[^A-Za-z0-9._-]/', '', $_GET['id'] ?? '');
$set = $_GET['set'] ?? '';
if (!in_array($set, $states, true)) { $set = ''; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id !== '' && $set !== '') {
    handle_transpose($base, $states, $id, $set);
} elseif ($id !== '') {
    render_detail($base, $states, $id);
} else {
    render_overview($base);
}
exit;

// ── Helpers ───────────────────────────────────────────────────────────────────

function find_issue(string $base, array $states, string $id): ?array {
    foreach ($states as $state) {
        $path = "$base/$state/$id";
        if (is_file($path)) {
            return ['state' => $state, 'path' => $path];
        }
    }
    return null;
}

function parse_titel(string $path): string {
    $fh = fopen($path, 'r');
    if ($fh === false) {
        return '(kein Titel)';
    }
    $line = fgets($fh);
    fclose($fh);
    if ($line !== false && preg_match('/^#\s+(.+)/u', rtrim($line), $m)) {
        return $m[1];
    }
    return '(kein Titel)';
}

function filename_to_display(string $id): string {
    if (preg_match('/^(\d{4}-\d{2}-\d{2})_(\d{2})-(\d{2})-\d{2}_/', $id, $m)) {
        return $m[1] . ' ' . $m[2] . ':' . $m[3];
    }
    return $id;
}

function append_verlauf(string $path, string $state): void {
    $content = file_get_contents($path);
    $entry   = '- [' . date('Y-m-d H:i:s') . '] → ' . $state;
    if (str_contains($content, "\n## Verlauf")) {
        file_put_contents($path, rtrim($content) . "\n" . $entry);
    } else {
        file_put_contents($path, rtrim($content) . "\n\n## Verlauf\n" . $entry);
    }
}

function html_head(string $title): void {
    $is_overview = ($title === 'Issues'); ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?></title>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="stylesheet" href="design-tokens.css">
<link rel="stylesheet" href="components.css">
<style>
.page { max-width: 900px; margin: 0 auto; padding: var(--space-6) var(--space-4); }

a { color: var(--color-interactive-600); }
a:hover { text-decoration: underline; color: var(--color-interactive-700); }

h1 { font-size: var(--text-xl); margin: 0 0 var(--space-6); font-weight: var(--font-weight-bold); color: var(--color-neutral-900); }
h2 { font-size: var(--text-base); font-weight: var(--font-weight-bold); color: var(--color-neutral-900); margin: var(--space-6) 0 var(--space-3); }

table { width: 100%; border-collapse: collapse; margin-bottom: var(--space-6); }
th, td { padding: var(--space-2) var(--space-3); text-align: left; border-bottom: 1px solid var(--color-neutral-200); font-size: var(--text-sm); }
th { font-weight: var(--font-weight-bold); background: var(--color-surface-page); }

pre { background: var(--color-surface-page); padding: var(--space-4); overflow-x: auto;
      font-size: var(--text-xs); white-space: pre-wrap; word-break: break-word;
      border-radius: var(--radius-md); border: 1px solid var(--color-neutral-200); }

#md-body code { background: var(--color-neutral-200); padding: 0.1em 0.3em;
                border-radius: var(--radius-sm); font-size: 0.88em; font-family: monospace; }
#md-body img  { max-width: 100%; height: auto; }
#md-body ul   { margin: var(--space-2) 0; padding-left: var(--space-6); }
#md-body p    { margin: var(--space-2) 0; line-height: 1.5; }
#md-body h2   { border-bottom: 1px solid var(--color-neutral-200); padding-bottom: var(--space-1); }

/* State badges — light-bg Kanban style, distinct from content-type badges */
.state-badge {
  display: inline-block; padding: var(--space-1) var(--space-2);
  border-radius: var(--radius-sm); font-size: var(--text-xs); font-weight: var(--font-weight-bold);
  margin-left: var(--space-3); vertical-align: middle;
}
.state-badge-new        { background: #dbeafe; color: #1d4ed8; }
.state-badge-ready      { background: var(--color-semantic-50); color: var(--color-semantic-700); }
.state-badge-blocked    { background: #fee2e2; color: var(--color-error); }
.state-badge-inProgress { background: #fef9c3; color: #854d0e; }
.state-badge-inReview   { background: var(--color-interactive-100); color: var(--color-interactive-700); }
.state-badge-canceled   { background: var(--color-neutral-200); color: var(--color-neutral-600); }
.state-badge-closed     { background: var(--color-neutral-200); color: var(--color-neutral-900); }

.actions { margin: var(--space-4) 0; display: flex; gap: var(--space-2); flex-wrap: wrap; align-items: center; }
.actions-label { font-size: var(--text-sm); color: var(--color-neutral-600); }
[hidden] { display: none !important; }

#edit-area {
  width: 100%; box-sizing: border-box; font-family: monospace;
  font-size: var(--text-sm); margin-bottom: var(--space-2);
  border: 1px solid var(--color-neutral-200); border-radius: var(--radius-md);
  padding: var(--space-3); resize: vertical;
}
#edit-bar { margin-bottom: var(--space-2); display: flex; gap: var(--space-2); align-items: center; }
#edit-err { color: var(--color-error); font-size: var(--text-sm); }
</style>
<script src="md-renderer.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('md-body');
    if (el) el.innerHTML = renderMd(el.dataset.raw);
});
</script>
</head>
<body>
<nav class="nav">
  <?php if (!$is_overview): ?>
  <a class="nav-back" href="issues.php">← Issues</a>
  <?php else: ?>
  <span class="nav-back">Issues</span>
  <?php endif ?>
  <span class="nav-title"><?= htmlspecialchars($title) ?></span>
</nav>
<div class="page">
<?php }

function html_foot(): void { ?>
</div>
</body></html>
<?php }

// ── Views ───────────────────────────────────────────────────────────────────

function render_overview(string $base): void {
    $cols = ['new' => [], 'ready' => []];
    foreach (array_keys($cols) as $state) {
        $dir   = "$base/$state";
        $files = is_dir($dir) ? (glob("$dir/*.md") ?: []) : [];
        rsort($files);
        foreach ($files as $f) {
            $id           = basename($f);
            $cols[$state][] = [
                'id'    => $id,
                'titel' => parse_titel($f),
                'ts'    => filename_to_display($id),
            ];
        }
    }
    html_head('Issues'); ?>
<?php foreach (['new' => 'Neu', 'ready' => 'Bereit'] as $state => $label): ?>
<h2><?= $label ?></h2>
<?php if (empty($cols[$state])): ?>
  <p style="color:var(--color-neutral-400);font-size:var(--text-sm)">Keine Issues.</p>
<?php else: ?>
<table>
  <tr><th>Datum</th><th>Titel</th></tr>
  <?php foreach ($cols[$state] as $row): ?>
  <tr>
    <td style="white-space:nowrap;color:var(--color-neutral-600)"><?= htmlspecialchars($row['ts']) ?></td>
    <td><a href="issues.php?id=<?= urlencode($row['id']) ?>"><?= htmlspecialchars($row['titel']) ?></a></td>
  </tr>
  <?php endforeach ?>
</table>
<?php endif ?>
<?php endforeach;
    html_foot();
}

function render_detail(string $base, array $states, string $id): void {
    $issue = find_issue($base, $states, $id);
    if (!$issue) {
        http_response_code(404);
        html_head('404');
        echo '<h1>Issue nicht gefunden</h1><p><a href="issues.php">← Übersicht</a></p>';
        html_foot();
        return;
    }

    $raw = file_get_contents($issue['path']);
    if ($raw === false) {
        http_response_code(500);
        html_head('Fehler');
        echo '<p>Issue-Datei konnte nicht gelesen werden.</p><p><a href="issues.php">← Übersicht</a></p>';
        html_foot();
        return;
    }
    $body    = ltrim(substr($raw, (strpos($raw, "\n") ?: 0) + 1));
    $current = $issue['state'];

    $transitions = [
        'new'        => ['ready', 'blocked', 'canceled'],
        'ready'      => ['inProgress', 'blocked', 'canceled'],
        'blocked'    => ['ready', 'canceled'],
        'inProgress' => ['inReview', 'blocked'],
        'inReview'   => ['closed', 'inProgress'],
        'canceled'   => [],
        'closed'     => [],
    ];
    $buttons = $transitions[$current] ?? [];

    $titel = parse_titel($issue['path']);
    html_head('Issue: ' . $titel); ?>
<h1>
  <?= htmlspecialchars($titel) ?>
  <span class="state-badge state-badge-<?= htmlspecialchars($current) ?>"><?= htmlspecialchars($current) ?></span>
</h1>
<?php if (!empty($buttons)): ?>
<div class="actions">
  <span class="actions-label">Übergang:</span>
  <?php foreach ($buttons as $next): ?>
  <form method="POST" action="issues.php?id=<?= urlencode($id) ?>&amp;set=<?= urlencode($next) ?>">
    <button type="submit" class="btn btn-secondary"><?= htmlspecialchars($next) ?></button>
  </form>
  <?php endforeach ?>
</div>
<?php endif ?>
<textarea id="edit-area" hidden rows="20"></textarea>
<div id="edit-bar" hidden>
  <button id="save-btn" class="btn btn-primary">Speichern</button>
  <button id="cancel-btn" class="btn btn-ghost">Abbrechen</button>
  <span id="edit-err"></span>
</div>
<div id="md-body" data-raw="<?= htmlspecialchars($body) ?>"></div>
<button id="edit-btn" class="btn btn-secondary" style="margin-top:var(--space-4)">Bearbeiten</button>
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
        editArea.value      = fullRaw;
        mdBody.hidden       = true;
        editBtn.hidden      = true;
        editArea.hidden     = false;
        editBar.hidden      = false;
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
        const snapshot = editArea.value;
        editArea.disabled = true;
        fetch('issue.php', {
            method: 'POST',
            body: new URLSearchParams({ report: snapshot, filename })
        })
        .then(r => r.ok ? r.json() : Promise.reject(r.status))
        .then(() => {
            const nl        = snapshot.indexOf('\n');
            const titleLine = nl >= 0 ? snapshot.slice(0, nl) : snapshot;
            const bodyPart  = nl >= 0 ? snapshot.slice(nl + 1) : '';

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
            } else {
                const h1 = document.querySelector('h1');
                if (h1) {
                    for (const node of h1.childNodes) {
                        if (node.nodeType === Node.TEXT_NODE && node.textContent.trim()) {
                            node.textContent = '(kein Titel) ';
                            break;
                        }
                    }
                }
            }

            fullRaw         = snapshot;
            mdBody.hidden   = false;
            editBtn.hidden  = false;
            editArea.hidden = true;
            editBar.hidden  = true;
        })
        .catch(status => {
            editErr.textContent = status === 404
                ? 'Issue wurde verschoben – bitte Seite neu laden.'
                : 'Speichern fehlgeschlagen.';
        })
        .finally(() => {
            saveBtn.disabled  = false;
            editArea.disabled = false;
        });
    });
}
initEdit(<?= json_encode($current . '/' . $id) ?>, <?= json_encode($raw, JSON_INVALID_UTF8_SUBSTITUTE) ?>);
</script>
<?php html_foot();
}

function handle_transpose(string $base, array $states, string $id, string $set): void {
    $issue = find_issue($base, $states, $id);
    if (!$issue) {
        http_response_code(404);
        echo 'Issue nicht gefunden';
        return;
    }
    $newDir = "$base/$set";
    if (!is_dir($newDir)) {
        mkdir($newDir, 0755, true);
    }
    append_verlauf($issue['path'], $set);
    rename($issue['path'], "$newDir/$id");
    header('Location: issues.php');
    exit;
}
