<?php
/*
 * util_sumup.php
 * Aggregator: requires all SumUp modules for backward compatibility.
 * Modules:
 *  - util_sumup_core.php:    generic primitives (sumup_load, sumup_save, sumup_read_tail, sumup_update)
 *  - util_sumup_votes.php:   votes callbacks (votes_sumup_merge_line, votes_sumup_project)
 *  - util_sumup_entries.php: entries callbacks (entries_sumup_merge_line, entries_sumup_project)
 * Plain procedural PHP 8.0+. No classes, no framework, no Composer.
 */

require_once __DIR__ . '/util_sumup_core.php';
require_once __DIR__ . '/util_sumup_votes.php';
require_once __DIR__ . '/util_sumup_entries.php';


