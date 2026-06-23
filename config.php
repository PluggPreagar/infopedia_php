<?php
declare(strict_types=1);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$ini   = file_exists(__DIR__ . '/infopedia.cfg') ? parse_ini_file(__DIR__ . '/infopedia.cfg', true) : [];
$issue = $ini['issue'] ?? [];

echo json_encode([
    'issueGithubUrl' => $issue['github_url'] ?? '',
    'issueMailto'    => $issue['mailto']     ?? '',
]);
