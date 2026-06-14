<?php
// Entry parsing and formatting helpers (CA6, CP3)

function parseEntryLine(string $line): array {
    $parts = str_getcsv($line);
    if (count($parts) < 2) {
        return [];
    }

    if (str_starts_with($parts[0], '/')) {
        return parseFormattedEntryLine($parts, $line);
    }

    $entry = $parts[1];
    if (str_starts_with($entry, '|')) {
        $entry = ' ' . $entry;
    }

    $segments = explode(' | ', $entry, 3);
    if (count($segments) < 3) {
        return [];
    }

    [$topic, $node, $content] = $segments;

    return [
        'timestamp' => $parts[0],
        'topic' => $topic,
        'node' => $node,
        'content' => $content,
        'entry_type' => substr($content, -1),
        'delete' => trim($content) === '--',
        'raw' => $line,
    ];
}

function parseFormattedEntryLine(array $parts, string $line): array {
    $path = $parts[0];
    $attributes = [];
    $valueIndex = 1;

    while (isset($parts[$valueIndex]) && isEntryAttributeToken($parts[$valueIndex])) {
        [$key, $value] = splitEntryAttributeToken($parts[$valueIndex]);
        $attributes[$key] = $value;
        $valueIndex++;
    }

    $timestamp = $parts[$valueIndex] ?? '';
    $content = isset($parts[$valueIndex + 1]) ? str_replace('\\n', "\n", $parts[$valueIndex + 1]) : '';
    $vote = $parts[$valueIndex + 2] ?? ($attributes['vote'] ?? '');
    $lastSlash = strrpos($path, '/');

    if ($lastSlash === false || $lastSlash === 0) {
        $topic = '/';
        $node = ltrim($path, '/');
    } else {
        $topic = substr($path, 0, $lastSlash);
        $node = substr($path, $lastSlash + 1);
    }

    return [
        'timestamp' => $timestamp,
        'topic' => $topic,
        'node' => $node,
        'content' => $content,
        'entry_type' => $content === '' ? '' : substr($content, -1),
        'delete' => trim($content) === '--',
        'vote' => $vote,
        'attributes' => $attributes,
        'raw' => $line,
    ];
}

function isEntryAttributeToken(string $token): bool {
    $attribute = splitEntryAttributeToken($token);
    if ($attribute === null) {
        return false;
    }

    [$key] = $attribute;
    return preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $key) === 1;
}

function splitEntryAttributeToken(string $token): ?array {
    if (substr_count($token, ':') !== 1) {
        return null;
    }

    return explode(':', $token, 2);
}

function sortAndDeduplicateCsv(string $csvData): string {
    $csvData = str_replace("\r\n", "\n", $csvData);
    $lines = explode("\n", $csvData);
    $aggregated = [];
    $wrapped = '';

    foreach ($lines as $rawLine) {
        if ($rawLine === '' && $wrapped === '') {
            continue;
        }

        $line = $wrapped . $rawLine;
        if (substr_count($line, '"') % 2 !== 0) {
            $wrapped = $line . "\n";
            continue;
        }

        $wrapped = '';
        $parsed = parseEntryLine($line);
        if (empty($parsed)) {
            continue;
        }

        $key = $parsed['topic'] . ' | ' . $parsed['node'];
        $aggregated[$key] = $parsed['delete'] ? '' : $line;
    }

    $mostRecentEntry = findMostRecentEntry($aggregated);
    ksort($aggregated);

    $sortedCsv = '';
    foreach ($aggregated as $line) {
        if ($line !== '') {
            $sortedCsv .= $line . "\n";
        }
    }

    if ($mostRecentEntry !== '') {
        $sortedCsv .= $mostRecentEntry . "\n";
    }

    return trim($sortedCsv);
}

function buildMostRecentEntry(string $line): string {
    if ($line === '') {
        return '';
    }

    $mostRecentLine = preg_replace('/ \| /', '/', $line, 1);
    $entryStart = strpos($mostRecentLine, ',');
    if ($entryStart === false) {
        return '';
    }

    $entryStart++;
    if (isset($mostRecentLine[$entryStart]) && $mostRecentLine[$entryStart] === '"') {
        $entryStart++;
    }

    return substr_replace($mostRecentLine, '/_/menu/Most-Recent-Entry | ', $entryStart, 0);
}

function formatEntry(array $parsed): string {
    if (empty($parsed)) {
        return '';
    }

    return $parsed['topic'] . '/' . $parsed['node']
        . ',' . $parsed['timestamp']
        . ',' . str_replace("\n", '\n', $parsed['content']);
}

function findMostRecentEntry(array $aggregated): string {
    foreach (array_reverse($aggregated, true) as $key => $line) {
        if ($line !== '' && !str_starts_with($key, '/_')) {
            return buildMostRecentEntry($line);
        }
    }

    return '';
}

