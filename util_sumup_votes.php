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

    $sets = parseSetKinds($parsed);
    $is_vote_row = !empty($parsed['votes']) || !empty($parsed['signed']) || !empty($sets);
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

        // Signers: latest value per sid wins; 0 = withdrawn (UI2610-ADR-2)
        foreach ($parsed['signed'] as $sid => $val) {
            if ($ts < ($nodes[$path]['signed_ts'][$sid] ?? '')) {
                continue;
            }
            $nodes[$path]['signed_ts'][$sid] = $ts;
            if ($val > 0) {
                $nodes[$path]['signers'][$sid] = 1;
                // by:<name> in the same row = this Signer's public name (UI2610-ADR-2)
                $by = trim($parsed['attrs']['by'] ?? '');
                if (preg_match('/^[^|;=,]{1,40}$/u', $by)) {
                    $nodes[$path]['names'][$sid] = $by;
                }
            } else {
                unset($nodes[$path]['signers'][$sid], $nodes[$path]['names'][$sid]);
            }
        }

        // Set-kinds: latest value per sid wins (UI2610-ADR-2)
        foreach ($sets as $kind => $by_sid) {
            foreach ($by_sid as $sid => $val) {
                if ($ts < ($nodes[$path]['sets'][$kind][$sid]['ts'] ?? '')) {
                    continue;
                }
                $nodes[$path]['sets'][$kind][$sid] = ['v' => $val, 'ts' => $ts];
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
 * Columns for one set-kind: " | <kind>:<own-sid>:<v> | <kind>:others:<histogram>".
 * Histogram: "<v>=<n>;..." sorted by value; ind: one fixed-width bin list per component.
 * Cleared values (rate 0) are skipped. Pure — testable (CA6).
 *
 * @param array $by_sid sid => ['v' => value, 'ts' => ts]
 */
function votes_set_cols(string $kind, array $by_sid, string $session_id): string {
    $cleared = fn($v) => $kind === 'rate' && $v === '0';
    $cols = '';
    $own = $by_sid[$session_id]['v'] ?? null;
    if ($own !== null && !$cleared($own)) {
        $cols .= " | $kind:$session_id:$own";
    }

    $others = [];
    foreach ($by_sid as $sid => $rec) {
        if ($sid !== $session_id && !$cleared($rec['v'])) {
            $others[] = $rec['v'];
        }
    }
    if (empty($others)) {
        return $cols;
    }

    if ($kind === 'ind') {
        $names = ['kP', 'kI', 'sP-', 'sP+', 'sI-', 'sI+'];
        $parts = [];
        foreach ($names as $i => $name) {
            $bins = array_fill(0, $i < 2 ? 5 : 4, 0);
            foreach ($others as $v) {
                $bins[(int)explode(',', $v)[$i]]++;
            }
            $parts[] = $name . '=' . implode(',', $bins);
        }
        return $cols . " | ind:others:" . implode(';', $parts);
    }

    $hist = array_count_values($others);
    uksort($hist, 'strnatcmp');
    $bins = [];
    foreach ($hist as $v => $n) {
        $bins[] = "$v=$n";
    }
    return $cols . " | $kind:others:" . implode(';', $bins);
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

            // Set-kinds: own value + histogram of others; signers only on set rows (UI2610-ADR-2)
            $node_sets = $node['sets'] ?? [];
            foreach (array_keys(SET_KINDS) as $kind) {
                if (!empty($node_sets[$kind])) {
                    $vote_cols .= votes_set_cols($kind, $node_sets[$kind], $session_id);
                }
            }
            if (!empty($node_sets) && $signed_count > 0) {
                $vote_cols .= ' | signers:' . implode(',', array_keys($node['signers']));
                $names = [];
                foreach (array_keys($node['signers']) as $sid) {
                    if (isset($node['names'][$sid])) {
                        $names[] = $sid . '=' . $node['names'][$sid];
                    }
                }
                if (!empty($names)) {
                    $vote_cols .= ' | names:' . implode(';', $names);
                }
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

