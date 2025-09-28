<?php
/*
 *  Util_Cache
 *  - loads Data from Source
 *  - clones 1:1 into <data-type>.cache files
 *  - filters obsolete (updated, removed) entries, sorts into <data-type>_cleaned.cache
 *      - (!) the content will be transformed
 *          - datasource-style: <timestamp>,"?<parent-node> | <node> | <data><data-type-hint>"? (e.g. multiline)
 *          - fayf-style-0v02:  <parent-node> | <node> | <timestamp> | <data><data-type-hint>  (single-line using \\n)
 *          - fayf-style-0v02e: | <parent-node>/<node> | <parent-node> | <node> | <timestamp> | <data><data-type-hint>
 */

    function log_test($message, $ok = false, $expected = null, $actual = null) {
        if ($ok) {
            print "OK: " . $message . "\n";
        } else {
            print "FAIL: " . $message . "\n";
            if ($expected !== null || $actual !== null) {
                print "      EXPECT:" . $expected . "\n";
                print "      ACTUAL:" . $actual . "\n";
            }
        }
    }

    function assert_equals($expected, $actual, $message = null) {
        log_test($message ?? "equals \"".$expected. "\"?" , $expected == $actual, $expected, $actual);
    }






?>