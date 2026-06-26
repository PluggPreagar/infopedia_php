<?php
header('Content-Type: text/html; charset=utf-8');

// ─── Config ───────────────────────────────────────────────────────────────────
$cfg     = is_file('infopedia.cfg') ? parse_ini_file('infopedia.cfg', true, INI_SCANNER_RAW) : [];
$logFile = $cfg['general']['logFile'] ?? 'infopedia.log';

// ─── Params ───────────────────────────────────────────────────────────────────
$exclude_e2e   = !empty($_GET['exclude_e2e']);
$lines_param   = max(10, min(500, (int)($_GET['lines'] ?? 50)));
$filter_type   = preg_replace('/[^a-z._]/',        '', $_GET['type']   ?? '');
$filter_tenant = preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['tenant'] ?? '');
$errors_only   = !empty($_GET['errors_only']);
$ar_options    = [0, 10, 30, 60];
$ar            = in_array((int)($_GET['ar'] ?? 0), $ar_options, true) ? (int)$_GET['ar'] : 0;

// ─── Parse log ───────────────────────────────────────────────────────────────
// Format: [YYYY-MM-DD HH:MM:SS] ; type ; uri ; method ; session@tenant ; script ; details
$parsed = [];
if (is_file($logFile)) {
    foreach (file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $raw) {
        $p = explode(' ; ', $raw);
        if (count($p) < 6) continue;

        $timestamp = trim(trim($p[0]), '[]');
        $type      = trim($p[1]);
        $uri       = trim($p[2]);
        $method    = trim($p[3]);
        $st        = trim($p[4]); // session@tenant
        $script    = trim($p[5]);
        $details   = trim(implode(' ; ', array_slice($p, 6)));

        if ($exclude_e2e && str_contains($script, 'e2e_request')) continue;

        $at      = strrpos($st, '@');
        $session = $at !== false ? substr($st, 0, $at) : $st;
        $tenant  = $at !== false ? substr($st, $at + 1) : '';

        $level = 'INFO';
        if     (str_starts_with($details, 'ERROR:'))   $level = 'ERROR';
        elseif (str_starts_with($details, 'WARNING:')) $level = 'WARNING';
        elseif (str_starts_with($details, 'RETURN:'))  $level = 'RETURN';

        $ms = null;
        if ($level === 'RETURN' && preg_match('/in ([\d.]+) seconds/', $details, $m)) {
            $ms = round((float)$m[1] * 1000, 2);
        }

        $parsed[] = compact('timestamp', 'type', 'uri', 'method', 'session', 'tenant', 'script', 'details', 'level', 'ms');
    }
}

// ─── Aggregate ────────────────────────────────────────────────────────────────
$returns  = array_values(array_filter($parsed, fn($r) => $r['level'] === 'RETURN'));
$errors   = array_values(array_filter($parsed, fn($r) => $r['level'] === 'ERROR'));
$warnings = array_values(array_filter($parsed, fn($r) => $r['level'] === 'WARNING'));

$total    = count($returns);
$err_cnt  = count($errors);
$warn_cnt = count($warnings);

$sessions_uniq = count(array_unique(array_column($returns, 'session')));
$tenants_uniq  = count(array_filter(array_unique(array_column($returns, 'tenant'))));

$times  = array_filter(array_column($returns, 'ms'), fn($v) => $v !== null);
$avg_ms = $times ? round(array_sum($times) / count($times), 2) : 0.0;
$max_ms = $times ? round(max($times), 2) : 0.0;

// Per-endpoint stats
$by_type = [];
foreach ($returns as $r) {
    $t = $r['type'];
    $by_type[$t] ??= ['get' => 0, 'post' => 0, 'times' => [], 'errors' => 0];
    $r['method'] === 'GET' ? $by_type[$t]['get']++ : $by_type[$t]['post']++;
    if ($r['ms'] !== null) $by_type[$t]['times'][] = $r['ms'];
}
foreach ($errors as $e) {
    if (isset($by_type[$e['type']])) $by_type[$e['type']]['errors']++;
}
uasort($by_type, fn($a, $b) => ($b['get'] + $b['post']) <=> ($a['get'] + $a['post']));

// Log viewer pool (most recent first)
$viewer_pool = $errors_only ? array_reverse($errors) : array_reverse($returns);
if ($filter_type)   $viewer_pool = array_values(array_filter($viewer_pool, fn($r) => $r['type']   === $filter_type));
if ($filter_tenant) $viewer_pool = array_values(array_filter($viewer_pool, fn($r) => $r['tenant'] === $filter_tenant));
$viewer_rows = array_slice($viewer_pool, 0, $lines_param);

// Available filter values
$all_types   = array_values(array_unique(array_column($returns, 'type')));
$all_tenants = array_values(array_filter(array_unique(array_column($returns, 'tenant'))));
sort($all_types);
sort($all_tenants);

$first_ts = $parsed ? $parsed[0]['timestamp']         : null;
$last_ts  = $parsed ? $parsed[count($parsed)-1]['timestamp'] : null;

$recent_errors   = array_slice(array_reverse($errors),   0, 10);
$recent_warnings = array_slice(array_reverse($warnings), 0,  5);

// ─── Chart data ──────────────────────────────────────────────────────────────

// A: requests by hour of day
$by_hour  = array_fill(0, 24, 0);
foreach ($returns as $r) {
    if (preg_match('/ (\d{2}):\d{2}:\d{2}/', $r['timestamp'], $m))
        $by_hour[(int)$m[1]]++;
}
$hour_max = max(1, max($by_hour));

// B: response-time histogram
$rt_labels  = ['<1ms', '1–10ms', '10–100ms', '100ms–1s', '>1s'];
$rt_buckets = array_fill_keys($rt_labels, 0);
foreach ($returns as $r) {
    if ($r['ms'] === null) continue;
    $ms = $r['ms'];
    if      ($ms < 1)    $rt_buckets['<1ms']++;
    elseif  ($ms < 10)   $rt_buckets['1–10ms']++;
    elseif  ($ms < 100)  $rt_buckets['10–100ms']++;
    elseif  ($ms < 1000) $rt_buckets['100ms–1s']++;
    else                 $rt_buckets['>1s']++;
}
$rt_max = max(1, max($rt_buckets));

// C: requests over time (SVG polyline)
$timeline  = [];
$tl_bucket = 3600;
$tl_label  = '1h buckets';
$tl_min_ts = null;
if ($returns) {
    $ts_vals = array_filter(array_map(fn($r) => strtotime($r['timestamp']), $returns));
    if ($ts_vals) {
        $tl_min_ts = min($ts_vals);
        $tl_range  = max($ts_vals) - $tl_min_ts;
        if      ($tl_range < 7200)   { $tl_bucket = 300;   $tl_label = '5-min buckets'; }
        elseif  ($tl_range < 86400)  { $tl_bucket = 900;   $tl_label = '15-min buckets'; }
        elseif  ($tl_range < 604800) { $tl_bucket = 3600;  $tl_label = '1h buckets'; }
        else                         { $tl_bucket = 86400; $tl_label = '1-day buckets'; }

        foreach ($returns as $r) {
            $t = strtotime($r['timestamp']);
            if ($t === false) continue;
            $key = (int)floor(($t - $tl_min_ts) / $tl_bucket);
            $timeline[$key] = ($timeline[$key] ?? 0) + 1;
        }
        $n_buckets = max(2, (int)ceil($tl_range / $tl_bucket) + 1);
        $tl_full   = [];
        for ($i = 0; $i < $n_buckets; $i++) $tl_full[$i] = $timeline[$i] ?? 0;
        $timeline  = $tl_full;
    }
}
$tl_max = $timeline ? max(1, max($timeline)) : 1;

// ─── Helpers ──────────────────────────────────────────────────────────────────
function qs(array $set = [], array $unset = []): string {
    $p = $_GET;
    foreach ($set   as $k => $v) $p[$k] = $v;
    foreach ($unset as $k)       unset($p[$k]);
    return ($q = http_build_query($p)) ? '?' . $q : '?';
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ms_fmt(?float $ms): string {
    if ($ms === null) return '—';
    return $ms >= 1000 ? number_format($ms / 1000, 2) . 's' : number_format($ms, 1) . 'ms';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Infopedia Statistics</title>
<link rel="icon" type="image/x-icon" href="favicon.ico">
<link rel="stylesheet" href="design-tokens.css">
<link rel="stylesheet" href="components.css">
<style>
body { font-family: var(--font-family); font-size: var(--text-sm);
       background: var(--color-surface-page); color: var(--color-neutral-900); }
.page { max-width: 1100px; margin: 0 auto; padding: var(--space-6) var(--space-4); }

table { width:100%; border-collapse:collapse; margin-top:var(--space-4);
        background:var(--color-surface-card); box-shadow:var(--shadow-1); font-size:var(--text-xs); }
table th, table td { border:1px solid var(--color-neutral-200); padding:var(--space-1) var(--space-2); text-align:left; }
table th { background:var(--color-interactive-50); color:var(--color-interactive-700);
           text-transform:uppercase; font-weight:var(--font-weight-bold); letter-spacing:.04em; }
table tr:nth-child(even) { background:var(--color-surface-page); }
table tr:hover { background:var(--color-interactive-50); }
table td:last-child { color:var(--color-neutral-600); }

.stat-strip { display:flex; gap:var(--space-3); flex-wrap:wrap; margin-bottom:var(--space-4); }
.stat-box { background:var(--color-surface-card); border:1px solid var(--color-interactive-100);
            border-radius:var(--radius-md); padding:var(--space-3) var(--space-4);
            flex:1; min-width:100px; text-align:center; }
.stat-box .val { font-size:1.9em; font-weight:var(--font-weight-bold);
                 color:var(--color-interactive-700); line-height:1.1; }
.stat-box .lbl { font-size:var(--text-xs); color:var(--color-neutral-600); margin-top:var(--space-1); }
.stat-box.err .val { color:var(--color-error); }

.controls { display:flex; gap:var(--space-2); align-items:center; flex-wrap:wrap; margin-bottom:var(--space-3); }
.meta { font-size:var(--text-xs); color:var(--color-neutral-400); margin-bottom:var(--space-4); }

.ctrl-btn { padding:4px 11px; border:1px solid var(--color-interactive-400); border-radius:var(--radius-sm);
            text-decoration:none; color:var(--color-interactive-700); font-size:var(--text-sm);
            background:var(--color-surface-card); white-space:nowrap; }
.ctrl-btn.on { background:var(--color-interactive-100); color:var(--color-interactive-700);
               border-color:var(--color-interactive-600); font-weight:var(--font-weight-bold); }
.ctrl-btn.danger { border-color:var(--color-error); color:var(--color-error); }
.ctrl-btn.danger.on { background:#fee2e2; color:#991b1b; border-color:var(--color-error);
                      font-weight:var(--font-weight-bold); }

.section { background:var(--color-surface-card); border:1px solid var(--color-interactive-100);
           border-radius:var(--radius-md); padding:var(--space-4); margin-bottom:var(--space-4); }
.section.err-section { border-color:var(--color-error); background:#fff8f8; }
.section h2 { margin:0 0 var(--space-3); font-size:var(--text-sm); text-transform:uppercase;
              letter-spacing:.04em; border-bottom:1px solid var(--color-interactive-100);
              padding-bottom:var(--space-2); color:var(--color-interactive-700); }
.err-section h2 { color:var(--color-error); border-color:#fca5a5; }

.pbar { display:inline-block; height:8px; background:var(--color-interactive-600);
        border-radius:2px; vertical-align:middle; margin-left:6px; min-width:2px; }
.err-txt { color:var(--color-error); }
.warn-txt { color:#92400e; }
.dim { color:var(--color-neutral-400); }
.mono { font-family:monospace; font-size:var(--text-xs); }
.clip { max-width:340px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
        display:inline-block; vertical-align:bottom; }

.filters { display:flex; gap:var(--space-1); flex-wrap:wrap; align-items:center; margin-bottom:var(--space-2); }
.filters label { font-size:var(--text-xs); color:var(--color-neutral-600); min-width:50px; }
.rx-input { font:var(--text-sm) monospace; padding:3px 8px;
            border:1px solid var(--color-interactive-400); border-radius:var(--radius-sm);
            width:260px; outline:none; }
.rx-input:focus { border-color:var(--color-interactive-600); }
.rx-input.invalid { border-color:var(--color-error); background:#fff8f8; }
.rx-count { font-size:var(--text-xs); color:var(--color-neutral-400); margin-left:4px; }

.bc-row { display:flex; align-items:center; gap:6px; margin:3px 0; font-size:var(--text-xs); }
.bc-lbl { flex-shrink:0; min-width:72px; text-align:right; color:var(--color-neutral-600); }
.bc-track { flex:1; height:9px; background:var(--color-interactive-50); border-radius:2px; }
.bc-bar { height:9px; background:var(--color-interactive-600); border-radius:2px; }
.bc-val { flex-shrink:0; min-width:24px; color:var(--color-neutral-400); }

.vchart { display:flex; align-items:flex-end; gap:1px; height:60px; }
.vc-col { flex:1; display:flex; flex-direction:column; justify-content:flex-end; align-items:center; }
.vc-bar { width:100%; background:var(--color-interactive-600); border-radius:2px 2px 0 0; }
</style>
</head>
<body>
<nav class="nav">
  <a class="nav-back" href="infopage.html">← Home</a>
  <span class="nav-title">Statistics</span>
</nav>
<div class="page">

<?php if (!is_file($logFile)): ?>
<p class="err-txt">Log file not found: <code><?= h($logFile) ?></code></p>
<?php else: ?>

<div class="controls">
  <a class="ctrl-btn <?= $exclude_e2e ? 'on' : '' ?>"
     href="<?= $exclude_e2e ? qs([], ['exclude_e2e']) : qs(['exclude_e2e' => '1']) ?>">
    <?= $exclude_e2e ? '&#10003; E2E excluded' : 'Exclude E2E' ?>
  </a>
  <a class="ctrl-btn" href="<?= qs() ?>">&#8635; Refresh</a>
  <span style="font-size:var(--text-xs);color:var(--color-neutral-400);margin-left:6px">Auto-refresh:</span>
  <?php foreach ($ar_options as $n): ?>
  <a class="ctrl-btn <?= $ar === $n ? 'on' : '' ?>"
     href="<?= $n ? qs(['ar' => $n]) : qs([], ['ar']) ?>">
    <?= $n ? $n . 's' : 'off' ?>
  </a>
  <?php endforeach ?>
  <?php if ($ar > 0): ?>
  <span id="ar-countdown" style="font-size:var(--text-xs);color:var(--color-neutral-400);min-width:34px;display:inline-block"></span>
  <?php endif ?>
</div>

<p class="meta">
  <?= h($logFile) ?> &nbsp;&middot;&nbsp; <?= count($parsed) ?> lines parsed
  <?php if ($first_ts): ?>
    &nbsp;&middot;&nbsp; <?= h($first_ts) ?> &rarr; <?= h($last_ts) ?>
  <?php endif ?>
</p>

<!-- Summary strip -->
<div class="stat-strip">
  <div class="stat-box">
    <div class="val"><?= $total ?></div>
    <div class="lbl">Requests</div>
  </div>
  <div class="stat-box">
    <div class="val"><?= $sessions_uniq ?></div>
    <div class="lbl">Sessions</div>
  </div>
  <div class="stat-box">
    <div class="val"><?= $tenants_uniq ?></div>
    <div class="lbl">Tenants</div>
  </div>
  <div class="stat-box <?= $err_cnt > 0 ? 'err' : '' ?>">
    <div class="val"><?= $err_cnt ?></div>
    <div class="lbl">Errors<?= $total > 0 ? ' (' . round($err_cnt / $total * 100, 1) . '%)' : '' ?></div>
  </div>
  <div class="stat-box">
    <div class="val"><?= ms_fmt($avg_ms) ?></div>
    <div class="lbl">Avg &middot; max <?= ms_fmt($max_ms) ?></div>
  </div>
</div>

<!-- Error dashboard -->
<?php if ($err_cnt > 0 || $warn_cnt > 0): ?>
<div class="section err-section">
  <h2>Errors &amp; Warnings</h2>

  <?php if ($recent_errors): ?>
  <table>
    <tr><th>Timestamp</th><th>Type</th><th>URI</th><th>Error</th></tr>
    <?php foreach ($recent_errors as $e): ?>
    <tr>
      <td class="mono"><?= h($e['timestamp']) ?></td>
      <td><?= h($e['type']) ?></td>
      <td class="mono"><span class="clip"><?= h($e['uri']) ?></span></td>
      <td class="err-txt"><?= h($e['details']) ?></td>
    </tr>
    <?php endforeach ?>
  </table>
  <?php endif ?>

  <?php if ($recent_warnings): ?>
  <p style="margin:var(--space-3) 0 var(--space-1)"><strong>Warnings</strong></p>
  <table>
    <tr><th>Timestamp</th><th>Type</th><th>URI</th><th>Warning</th></tr>
    <?php foreach ($recent_warnings as $w): ?>
    <tr>
      <td class="mono"><?= h($w['timestamp']) ?></td>
      <td><?= h($w['type']) ?></td>
      <td class="mono"><span class="clip"><?= h($w['uri']) ?></span></td>
      <td class="warn-txt"><?= h($w['details']) ?></td>
    </tr>
    <?php endforeach ?>
  </table>
  <?php endif ?>
</div>
<?php endif ?>

<!-- Endpoint breakdown -->
<div class="section">
  <h2>By Endpoint</h2>
  <?php if (empty($by_type)): ?>
  <p class="dim">No data.</p>
  <?php else: ?>
  <table>
    <tr><th>Type</th><th>GET</th><th>POST</th><th>Total</th><th>Avg</th><th>Max</th><th>Errors</th></tr>
    <?php foreach ($by_type as $type => $s):
        $t_total = $s['get'] + $s['post'];
        $t_avg   = $s['times'] ? round(array_sum($s['times']) / count($s['times']), 2) : null;
        $t_max   = $s['times'] ? round(max($s['times']), 2) : null;
        $bar_w   = $max_ms > 0 && $t_avg !== null ? (int)min(80, round($t_avg / $max_ms * 80)) : 0;
        $err_pct = $t_total > 0 ? round($s['errors'] / $t_total * 100, 1) : 0;
    ?>
    <tr>
      <td><strong><?= h($type) ?></strong></td>
      <td><?= $s['get']  ?: '<span class="dim">—</span>' ?></td>
      <td><?= $s['post'] ?: '<span class="dim">—</span>' ?></td>
      <td><?= $t_total ?></td>
      <td>
        <?= ms_fmt($t_avg) ?>
        <?php if ($bar_w > 0): ?><span class="pbar" style="width:<?= $bar_w ?>px"></span><?php endif ?>
      </td>
      <td><?= ms_fmt($t_max) ?></td>
      <td><?php if ($s['errors'] > 0): ?>
        <span class="err-txt"><?= $s['errors'] ?> (<?= $err_pct ?>%)</span>
      <?php else: ?>
        <span class="dim">—</span>
      <?php endif ?></td>
    </tr>
    <?php endforeach ?>
  </table>
  <?php endif ?>
</div>

<!-- Charts -->
<?php if ($total > 0): ?>
<div style="display:flex;gap:var(--space-4);flex-wrap:wrap;margin-bottom:var(--space-4)">

  <!-- A: by hour of day -->
  <div class="section" style="flex:1 1 240px">
    <h2>Requests by Hour</h2>
    <div class="vchart">
      <?php for ($h = 0; $h < 24; $h++):
          $bar_h = $by_hour[$h] > 0 ? max(1, (int)round($by_hour[$h] / $hour_max * 56)) : 0;
      ?>
      <div class="vc-col" title="<?= sprintf('%02dh', $h) ?>: <?= $by_hour[$h] ?> req">
        <?php if ($bar_h > 0): ?>
        <div class="vc-bar" style="height:<?= $bar_h ?>px"></div>
        <?php endif ?>
      </div>
      <?php endfor ?>
    </div>
    <div style="display:flex;gap:1px;font-size:0.6em;color:var(--color-neutral-400);margin-top:2px">
      <?php for ($h = 0; $h < 24; $h++): ?>
      <div style="flex:1;text-align:center"><?= ($h % 6 === 0) ? sprintf('%02d', $h) : '' ?></div>
      <?php endfor ?>
    </div>
  </div>

  <!-- B: response-time histogram -->
  <div class="section" style="flex:1 1 200px">
    <h2>Response Time Dist.</h2>
    <?php if (array_sum($rt_buckets) > 0): ?>
    <div style="margin-top:var(--space-3)">
      <?php foreach ($rt_buckets as $label => $count):
          $pct = $count > 0 ? max(2, (int)round($count / $rt_max * 100)) : 0;
      ?>
      <div class="bc-row">
        <span class="bc-lbl"><?= h($label) ?></span>
        <div class="bc-track"><div class="bc-bar" style="width:<?= $pct ?>%"></div></div>
        <span class="bc-val"><?= $count ?: '' ?></span>
      </div>
      <?php endforeach ?>
    </div>
    <?php else: ?>
    <p class="dim" style="margin-top:var(--space-3)">No timing data.</p>
    <?php endif ?>
  </div>

</div>

<!-- C: requests over time -->
<?php if (count($timeline) > 1): ?>
<div class="section" style="margin-bottom:var(--space-4)">
  <h2>Requests Over Time <span style="font-weight:400;font-size:0.8em;color:var(--color-neutral-400)">(<?= h($tl_label) ?>)</span></h2>
  <?php
      $n   = count($timeline);
      $svw = 580; $svh = 72; $pl = 28; $pt = 8; $pr = 10; $pb = 14;
      $pw  = $svw - $pl - $pr;
      $ph  = $svh - $pt - $pb;
      $pts = [];
      foreach ($timeline as $i => $v) {
          $x     = $pl + ($n > 1 ? round($i / ($n - 1) * $pw, 1) : $pw / 2);
          $y     = $pt + round((1 - $v / $tl_max) * $ph, 1);
          $pts[] = "$x,$y";
      }
      $pts_str = implode(' ', $pts);
      $bx0  = explode(',', $pts[0])[0];
      $bxN  = explode(',', end($pts))[0];
      $base = $pt + $ph;
      $area = "$bx0,$base $pts_str $bxN,$base";
      $fl   = $tl_min_ts ? date('d.m H:i', $tl_min_ts) : '';
      $ll   = $tl_min_ts ? date('d.m H:i', $tl_min_ts + ($n - 1) * $tl_bucket) : '';
  ?>
  <svg viewBox="0 0 <?= $svw ?> <?= $svh ?>" style="width:100%;height:<?= $svh ?>px;display:block"
       xmlns="http://www.w3.org/2000/svg">
    <line x1="<?= $pl ?>" y1="<?= $pt ?>" x2="<?= $svw-$pr ?>" y2="<?= $pt ?>"
          stroke="#E5E7EB" stroke-width="1"/>
    <line x1="<?= $pl ?>" y1="<?= $pt+$ph/2 ?>" x2="<?= $svw-$pr ?>" y2="<?= $pt+$ph/2 ?>"
          stroke="#E5E7EB" stroke-width="1"/>
    <polygon points="<?= $area ?>" fill="#E0E7FF" opacity="0.65"/>
    <polyline points="<?= $pts_str ?>" fill="none" stroke="#4F46E5" stroke-width="1.5" stroke-linejoin="round"/>
    <line x1="<?= $pl ?>" y1="<?= $pt ?>" x2="<?= $pl ?>" y2="<?= $base ?>"
          stroke="#C7D2FE" stroke-width="1"/>
    <line x1="<?= $pl ?>" y1="<?= $base ?>" x2="<?= $svw-$pr ?>" y2="<?= $base ?>"
          stroke="#C7D2FE" stroke-width="1"/>
    <text x="<?= $pl-2 ?>" y="<?= $pt+3 ?>" font-size="8" fill="#9CA3AF" text-anchor="end"><?= $tl_max ?></text>
    <?php if ($fl): ?>
    <text x="<?= $pl ?>" y="<?= $base+11 ?>" font-size="8" fill="#9CA3AF"><?= h($fl) ?></text>
    <text x="<?= $svw-$pr ?>" y="<?= $base+11 ?>" font-size="8" fill="#9CA3AF" text-anchor="end"><?= h($ll) ?></text>
    <?php endif ?>
  </svg>
</div>
<?php endif ?>

<?php endif ?>

<!-- Log viewer -->
<div class="section">
  <h2><?= $errors_only ? 'Error Log' : 'Recent Requests' ?></h2>

  <div class="filters">
    <label>Type</label>
    <a class="ctrl-btn <?= !$filter_type && !$errors_only ? 'on' : '' ?>"
       href="<?= qs([], ['type', 'errors_only']) ?>">all</a>
    <?php foreach ($all_types as $t): ?>
    <a class="ctrl-btn <?= $filter_type === $t && !$errors_only ? 'on' : '' ?>"
       href="<?= qs(['type' => $t], ['errors_only']) ?>"><?= h($t) ?></a>
    <?php endforeach ?>
    <a class="ctrl-btn danger <?= $errors_only ? 'on' : '' ?>"
       href="<?= $errors_only ? qs([], ['errors_only', 'type']) : qs(['errors_only' => '1'], ['type']) ?>">
      errors only</a>
  </div>

  <?php if ($all_tenants): ?>
  <div class="filters">
    <label>Tenant</label>
    <a class="ctrl-btn <?= $filter_tenant === '' ? 'on' : '' ?>"
       href="<?= qs([], ['tenant']) ?>">all</a>
    <?php foreach ($all_tenants as $t): ?>
    <a class="ctrl-btn <?= $filter_tenant === $t ? 'on' : '' ?>"
       href="<?= qs(['tenant' => $t]) ?>"><?= h($t) ?></a>
    <?php endforeach ?>
  </div>
  <?php endif ?>

  <div class="filters">
    <label>Show</label>
    <?php foreach ([10, 50, 100, 200] as $n): ?>
    <a class="ctrl-btn <?= $lines_param === $n ? 'on' : '' ?>"
       href="<?= qs(['lines' => $n]) ?>"><?= $n ?></a>
    <?php endforeach ?>
  </div>

  <div class="filters">
    <label>Filter</label>
    <input id="rx-filter" class="rx-input" type="text"
           placeholder="regex against uri + details…"
           value="<?= h($_GET['q'] ?? '') ?>">
    <span id="rx-count" class="rx-count"></span>
  </div>

  <?php if (empty($viewer_rows)): ?>
  <p class="dim">No entries match the current filter.</p>
  <?php else: ?>
  <p class="meta" style="margin:0 0 var(--space-2)">
    <?= count($viewer_rows) ?> of <?= count($viewer_pool) ?>
    <?= $errors_only ? 'errors' : 'requests' ?>
    <?= ($filter_type || $filter_tenant) ? '(filtered)' : '' ?>
  </p>

  <?php if ($errors_only): ?>
  <table id="viewer-table">
    <tr><th>Timestamp</th><th>Type</th><th>Method</th><th>Tenant</th><th>URI</th><th>Message</th></tr>
    <?php foreach ($viewer_rows as $r): ?>
    <tr>
      <td class="mono"><?= h($r['timestamp']) ?></td>
      <td><?= h($r['type']) ?></td>
      <td><?= h($r['method']) ?></td>
      <td class="dim"><?= h($r['tenant']) ?: '—' ?></td>
      <td class="mono"><span class="clip"><?= h($r['uri']) ?></span></td>
      <td class="err-txt"><?= h($r['details']) ?></td>
    </tr>
    <?php endforeach ?>
  </table>

  <?php else: ?>
  <table id="viewer-table">
    <tr><th>Timestamp</th><th>Type</th><th>Method</th><th>Tenant</th><th>URI</th><th>Time</th><th>Details</th></tr>
    <?php foreach ($viewer_rows as $r): ?>
    <tr>
      <td class="mono"><?= h($r['timestamp']) ?></td>
      <td><?= h($r['type']) ?></td>
      <td><?= h($r['method']) ?></td>
      <td class="dim"><?= h($r['tenant']) ?: '—' ?></td>
      <td class="mono"><span class="clip"><?= h($r['uri']) ?></span></td>
      <td class="mono"><?= ms_fmt($r['ms']) ?></td>
      <td class="dim"><span class="clip"><?= h($r['details']) ?></span></td>
    </tr>
    <?php endforeach ?>
  </table>
  <?php endif ?>
  <?php endif ?>
</div>

<?php endif ?>

</div>
<script>
(function () {
    var secs = <?= (int)$ar ?>;
    if (secs > 0) {
        var el = document.getElementById('ar-countdown');
        var left = secs;
        function tick() {
            if (el) el.textContent = '(' + left + 's)';
            if (left <= 0) { location.reload(); return; }
            left--;
            setTimeout(tick, 1000);
        }
        tick();
    }
}());

(function () {
    var inp = document.getElementById('rx-filter');
    var cnt = document.getElementById('rx-count');
    if (!inp) return;

    function applyFilter() {
        var tbl = document.getElementById('viewer-table');
        if (!tbl) { cnt.textContent = ''; return; }

        var val = inp.value.trim();
        var rx;
        try {
            rx = val ? new RegExp(val, 'i') : null;
            inp.classList.remove('invalid');
        } catch (e) {
            inp.classList.add('invalid');
            cnt.textContent = 'invalid regex';
            return;
        }

        var rows = Array.from(tbl.rows).slice(1); // skip header
        var shown = 0;
        rows.forEach(function (row) {
            var text = row.cells[4].textContent + ' ' + row.cells[row.cells.length - 1].textContent;
            var match = !rx || rx.test(text);
            row.style.display = match ? '' : 'none';
            if (match) shown++;
        });
        cnt.textContent = rx ? shown + ' of ' + rows.length + ' matching' : '';
    }

    inp.addEventListener('input', applyFilter);
    applyFilter(); // apply on load if value pre-filled
}());
</script>
</body>
</html>
