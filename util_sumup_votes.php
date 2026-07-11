<?php
/*
 * util_sumup_votes.php
 * Votes-specific SumUp callbacks: merge_line and projection.
 * Plain procedural PHP 8.0+. No classes, no framework, no Composer.
 */

require_once __DIR__ . '/util_entry.php';

// ─── Votes callbacks ──────────────────────────────────────────────────────────

/**
 * Merge one logical CSV line into votes SumUp nodes.
 * Node shapes:
 *   vote node:     ['by_sid'=>[sid=>int,...], 'signers'=>[sid=>1,...], 'ts'=>str, 'content'=>str]
 *   non-vote node: ['row'=>['ts'=>str, 'entry'=>str]]
 * Totals are NOT stored — derived at projection time (array_sum(by_sid)).
 *
 * @param array  $nodes Current nodes
 * @param string $line   CSV line (decoded entry column only — from sumup_read_tail)
 */
function votes_sumup_merge_line(array $nodes, string $line): array {
    // Parse CSV line
    $parts = str_getcsv($line, ',', '"', '\\');
    if (count($parts) < 2) {
        return $nodes;
    }

    $ts = $parts[0];
    $entry = $parts[1];

    $parsed = parseEntry($entry);
    $path = $parsed['path'];

    $is_vote_row = !empty($parsed['votes']) || !empty($parsed['signed']);
    $is_delete = ($parsed['type'] === '--');

    // Delete marker removes node
    if ($is_delete) {
        unset($nodes[$path]);
        return $nodes;
    }

    if ($is_vote_row) {
        // Initialize vote node if absent
        if (!isset($nodes[$path]) || !isset($nodes[$path]['by_sid'])) {
            $nodes[$path] = [
                'by_sid' => [],
                'signers' => [],
                'ts' => $ts,
                'content' => $parsed['content'],
            ];
        }

        // Add vote counts
        foreach ($parsed['votes'] as $sid => $count) {
            $nodes[$path]['by_sid'][$sid] = ($nodes[$path]['by_sid'][$sid] ?? 0) + $count;
        }

        // Merge signers (unique per sid with val > 0)
        foreach ($parsed['signed'] as $sid => $val) {
            if ($val > 0) {
                $nodes[$path]['signers'][$sid] = 1;
            }
        }

        // Update ts/content if this row is newer
        if ($ts > $nodes[$path]['ts']) {
            $nodes[$path]['ts'] = $ts;
            $nodes[$path]['content'] = $parsed['content'];
        }
    } else {
        // Non-vote row: passthrough, newest wins
        if (!isset($nodes[$path]) || !isset($nodes[$path]['row'])) {
            $nodes[$path] = [
                'row' => [
                    'ts' => $ts,
                    'entry' => $entry,
                ],
            ];
        } elseif (isset($nodes[$path]['row']) && $ts > $nodes[$path]['row']['ts']) {
            $nodes[$path]['row'] = [
                'ts' => $ts,
                'entry' => $entry,
            ];
        }
    }

    return $nodes;
}

/**
 * Render votes SumUp nodes as sorted CSV for one session's view.
 * Output: Timestamp,entry CSV (header + path-sorted rows).
 * Vote rows: votes:<own-sid>:<n> + votes:others:... + signed_count:...
 * Non-vote rows: passthrough (newest per path).
 *
 * @param array  $nodes      SumUp nodes (from votes_sumup_merge_line)
 * @param string $session_id Client's session ID
 */
function votes_sumup_project(array $nodes, string $session_id): string {
    $header = "Timestamp,entry\n";

    if (empty($nodes)) {
        return $header;
    }

    ksort($nodes);  // path-sorted

    $rows = [];
    foreach ($nodes as $path => $node) {
        if (isset($node['by_sid'])) {
            // Vote node: assemble vote attributes
            $own = $node['by_sid'][$session_id] ?? 0;
            $total = array_sum($node['by_sid']);
            $others = $total - $own;

            $vote_cols = '';
            if ($own !== 0) {
                $vote_cols .= ' | votes:' . $session_id . ':' . $own;
            }
            if ($others !== 0) {
                $vote_cols .= ' | votes:others:' . $others;
            }

            $signed_count = count($node['signers']);
            if ($signed_count > 0) {
                $vote_cols .= ' | signed_count:' . $signed_count;
            }

            $entry = $path . $vote_cols . ' | ' . $node['content'];

            // CSV quote if needed
            if (strpbrk($entry, ',"' . "\n") !== false) {
                $entry = '"' . str_replace('"', '""', $entry) . '"';
            }

            $rows[] = $node['ts'] . ',' . $entry;
        } elseif (isset($node['row'])) {
            // Non-vote row: passthrough
            $entry = $node['row']['entry'];

            if (strpbrk($entry, ',"' . "\n") !== false) {
                $entry = '"' . str_replace('"', '""', $entry) . '"';
            }

            $rows[] = $node['row']['ts'] . ',' . $entry;
        }
    }

    if (empty($rows)) {
        return $header;
    }

    return $header . implode("\n", $rows);
}

