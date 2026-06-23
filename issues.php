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

function html_head(string $title): void { ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($title) ?></title>
<style>
body { font-family: system-ui, sans-serif; max-width: 900px; margin: 2rem auto; padding: 0 1rem; color: #111; }
a { color: #0066cc; text-decoration: none; } a:hover { text-decoration: underline; }
h1 { font-size: 1.3rem; margin: 0 0 1.5rem; }
h2 { font-size: 1rem; margin: 1.5rem 0 0.5rem; }
table { width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; }
th, td { padding: 0.4rem 0.6rem; text-align: left; border-bottom: 1px solid #eee; }
th { font-weight: 600; background: #f5f5f5; }
pre { background: #f8f8f8; padding: 1rem; overflow-x: auto; font-size: 0.82rem; white-space: pre-wrap; word-break: break-word; }
#md-body code { background:#f0f0f0; padding:0.1em 0.3em; border-radius:2px; font-size:0.88em; font-family:monospace; }
#md-body img { max-width:100%; height:auto; }
#md-body ul { margin:0.5rem 0; padding-left:1.5rem; }
#md-body p { margin:0.5rem 0; line-height:1.5; }
#md-body h2 { border-bottom:1px solid #eee; padding-bottom:0.2rem; }
.badge { display:inline-block; padding:0.15rem 0.5rem; border-radius:3px; font-size:0.8rem; font-weight:600; margin: 0rem 0rem 0rem 1rem }
.badge-new        { background:#dbeafe; color:#1d4ed8; }
.badge-ready      { background:#d1fae5; color:#065f46; }
.badge-blocked    { background:#fee2e2; color:#991b1b; }
.badge-inProgress { background:#fef9c3; color:#854d0e; }
.badge-inReview   { background:#ede9fe; color:#5b21b6; }
.badge-canceled   { background:#f3f4f6; color:#6b7280; }
.badge-closed     { background:#e5e7eb; color:#374151; }
form { display:inline; }
button { cursor:pointer; padding:0.3rem 0.7rem; border:1px solid #ccc; border-radius:4px; background:#fff; font-size:0.85rem; margin:0.2rem 0; }
button:hover { background:#f0f0f0; }
.actions { margin:1rem 0; display:flex; gap:0.5rem; flex-wrap:wrap; align-items:center; }
[hidden] { display:none !important; }
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
<?php }

function html_foot(): void { ?>
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
<h1>Issues</h1>
<?php foreach (['new' => 'Neu', 'ready' => 'Bereit'] as $state => $label): ?>
<h2><?= $label ?></h2>
<?php if (empty($cols[$state])): ?>
  <p style="color:#888;font-size:0.9rem">Keine Issues.</p>
<?php else: ?>
<table>
  <tr><th>Datum</th><th>Titel</th></tr>
  <?php foreach ($cols[$state] as $row): ?>
  <tr>
    <td style="white-space:nowrap;color:#666;font-size:0.85rem"><?= htmlspecialchars($row['ts']) ?></td>
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
<p><a href="issues.php">← Übersicht</a></p>
<h1>
  <?= htmlspecialchars($titel) ?>
  <span class="badge badge-<?= htmlspecialchars($current) ?>"><?= htmlspecialchars($current) ?></span>
</h1>
<?php if (!empty($buttons)): ?>
<div class="actions">
  <span style="font-size:0.85rem;color:#666">Übergang:</span>
  <?php foreach ($buttons as $next): ?>
  <form method="POST" action="issues.php?id=<?= urlencode($id) ?>&amp;set=<?= urlencode($next) ?>">
    <button type="submit"><?= htmlspecialchars($next) ?></button>
  </form>
  <?php endforeach ?>
</div>
<?php endif ?>
<textarea id="edit-area" hidden rows="20"
  style="width:100%;box-sizing:border-box;font-family:monospace;font-size:0.85rem;margin-bottom:0.5rem;"></textarea>
<div id="edit-bar" hidden style="margin-bottom:0.5rem;display:flex;gap:0.5rem;align-items:center;">
  <button id="save-btn">Speichern</button>
  <button id="cancel-btn">Abbrechen</button>
  <span id="edit-err" style="color:#c00;font-size:0.85rem;"></span>
</div>
<div id="md-body" data-raw="<?= htmlspecialchars($body) ?>"></div>
<button id="edit-btn" style="margin:1rem 0 0 0">Bearbeiten</button>
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
