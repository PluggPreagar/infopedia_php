<?php
/**
 * upgrade.php — pull latest from GitHub, overwrite matching files.
 * Usage: ?token=<upgradeToken from infopedia.cfg>
 */

// ── Config ────────────────────────────────────────────────────────────────────
$ini      = file_exists(__DIR__ . '/infopedia.cfg') ? parse_ini_file(__DIR__ . '/infopedia.cfg', true) : [];
$u        = $ini['upgrade'] ?? [];
$token    = $u['token']  ?? '';
$branch   = $u['branch'] ?? 'dev';
$repo     = $u['repo']   ?? 'PluggPreagar/infopedia_php';
$patterns = !empty($u['files'])
    ? preg_split('/\s+/', trim($u['files']), -1, PREG_SPLIT_NO_EMPTY)
    : ['*.php', '*.html', '*.js', '*.md', 'justfile'];
$excludes = !empty($u['exclude'])
    ? preg_split('/\s+/', trim($u['exclude']), -1, PREG_SPLIT_NO_EMPTY)
    : [];

// ── Auth ──────────────────────────────────────────────────────────────────────
if ($token === '' || ($_GET['token'] ?? '') !== $token) {
    http_response_code(403);
    header('Content-Type: text/plain');
    exit('Forbidden');
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function upgrade_fail(string $msg): never {
    echo '<body style="font-family:monospace;padding:1rem"><p style="color:red">Error: '
        . htmlspecialchars($msg) . '</p></body>';
    exit;
}

function matches(string $rel, array $patterns): bool {
    foreach ($patterns as $p) {
        if (fnmatch($p, $rel) || fnmatch($p, basename($rel))) return true;
    }
    return false;
}

// ── Download ──────────────────────────────────────────────────────────────────
$url   = 'https://codeload.github.com/' . $repo . '/zip/refs/heads/' . $branch;
$bytes = file_get_contents($url);
if (!$bytes) upgrade_fail('Download failed: ' . $url);

$tmp = sys_get_temp_dir() . '/' . uniqid('upgrade_') . '.zip';
if (file_put_contents($tmp, $bytes) === false) upgrade_fail('Could not write temp file');

// ── Extract ───────────────────────────────────────────────────────────────────
$zip = new ZipArchive();
if ($zip->open($tmp) !== true) {
    unlink($tmp);
    upgrade_fail('ZipArchive::open failed');
}

// Detect top-level prefix, e.g. "infopedia_php-dev/" and read commit metadata
$prefix      = '';
$commitSha   = $zip->comment ?: '(unknown)';
$commitMtime = $zip->count() > 0 ? ($zip->statIndex(0)['mtime'] ?? 0) : 0;
if ($zip->count() > 0) {
    $first = $zip->getNameIndex(0);
    if (($pos = strpos($first, '/')) !== false) {
        $prefix = substr($first, 0, $pos + 1);
    }
}

$log = []; $written = 0; $skipped = 0; $errors = 0;

for ($i = 0; $i < $zip->count(); $i++) {
    $name = $zip->getNameIndex($i);
    $rel  = str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;

    // Skip directory entries
    if ($rel === '' || str_ends_with($rel, '/')) continue;

    if (!matches($rel, $patterns) || matches($rel, $excludes)) {
        $skipped++;
        $log[] = ['path' => $rel, 'st' => 'skipped'];
        continue;
    }

    // Write to __DIR__
    $dest = __DIR__ . '/' . $rel;
    // Guard against path traversal in ZIP entry names.
    $abs = realpath(__DIR__) . '/';
    $normalised = $abs . ltrim($rel, '/');
    if (strpos(str_replace('\\', '/', $normalised), str_replace('\\', '/', $abs)) !== 0
        || strpos($rel, '..') !== false) {
        $errors++; $log[] = ['path' => $rel, 'st' => 'skip'];
        continue;
    }
    $dir  = dirname($dest);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    if (file_put_contents($dest, $zip->getFromIndex($i)) === false) {
        $errors++;
        $log[] = ['path' => $rel, 'st' => 'error'];
    } else {
        $written++;
        $log[] = ['path' => $rel, 'st' => 'written'];
    }
}

$zip->close();
unlink($tmp);

file_put_contents(__DIR__ . '/version.json', json_encode([
    'sha'    => $commitSha,
    'time'   => $commitMtime ? gmdate('Y-m-d H:i:s', $commitMtime) . ' UTC' : null,
    'branch' => $branch,
]));

// ── HTML output ───────────────────────────────────────────────────────────────
$zipUrl = 'https://github.com/' . $repo . '/archive/refs/heads/' . $branch . '.zip';
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html><head><title>upgrade</title></head>
<body style="font-family:monospace;padding:1rem;max-width:900px">
<h2>upgrade — <?= htmlspecialchars($branch) ?></h2>
<p>
  commit: <code><?= htmlspecialchars($commitSha) ?></code><br>
  time: <?= $commitMtime ? gmdate('Y-m-d H:i:s', $commitMtime) . ' UTC' : '(unknown)' ?><br>
  <a href="<?= htmlspecialchars($zipUrl) ?>">GitHub ZIP</a>
</p>
<table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse;width:100%">
<tr style="background:#eee"><th align="left">file</th><th>status</th></tr>
<?php foreach ($log as $row):
    $color = match($row['st']) { 'written' => '#c8e6c9', 'error' => '#ffcdd2', default => '' };
?>
<tr style="background:<?= $color ?>">
    <td><?= htmlspecialchars($row['path']) ?></td>
    <td align="center"><?= $row['st'] ?></td>
</tr>
<?php endforeach ?>
</table>
<p><strong><?= $written ?> written, <?= $skipped ?> skipped, <?= $errors ?> errors</strong></p>
</body></html>
