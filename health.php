<?php
require_once 'util_http.php';

// util.php is type-specific (merges [general]+[$type]). Health needs both [entry]
// and [vote] sections, so we parse config directly here.
$configFile = 'infopedia.cfg';
if (!file_exists($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Configuration file not found.']]);
    exit;
}

$ini = parse_ini_file($configFile, true);
if ($ini === false) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'Failed to parse configuration file.']]);
    exit;
}

date_default_timezone_set('Europe/Berlin');

$entryConfig   = $ini['entry']   ?? [];
$voteConfig    = $ini['vote']    ?? [];
$generalConfig = $ini['general'] ?? [];

$entryCacheFile = $entryConfig['cacheFile']   ?? ($generalConfig['cacheFile'] ?? 'data/entries.cache');
$voteCacheFile  = $voteConfig['cacheFile']    ?? 'data/votes.cache';

$entryAge = file_exists($entryCacheFile) ? (time() - filemtime($entryCacheFile)) : null;
$voteAge  = file_exists($voteCacheFile)  ? (time() - filemtime($voteCacheFile))  : null;


$response = [
    'status'      => 'ok',
    'server_time' => date('Y-m-d H:i:s'),
    'cache'       => [
        'entry_age_seconds' => $entryAge,
        'vote_age_seconds'  => $voteAge,
    ],
];
respond_json($response, 200);
