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
    $timestamp = $parts[1] ?? '';
    $content = isset($parts[2]) ? str_replace('\\n', "\n", $parts[2]) : '';
    $vote = $parts[3] ?? '';
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
        'raw' => $line,
    ];
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

