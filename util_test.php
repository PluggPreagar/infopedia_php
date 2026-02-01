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

    $test_counter = 0;
    $test_passed = 0;
    $test_failed = 0;


    function log_debug($message) {
        // indent debug messages for better readability
        $message = str_replace("\n", "\n          ", $message);
        print "   DEBUG: " . $message . "\n";
    }

    function log_warn($message) {
        $message = str_replace("\n", "\n          ", $message);
        print "   WARN : " . $message . "\n";
    }


    function log_test($message, $ok = false, $expected = null, $actual = null) {
        global $test_counter, $test_passed, $test_failed;
        if ($ok) {
            $test_counter++;
            $test_passed++;
            print "OK: " . $message . "\n";
        } else {
            $test_counter++;
            $test_failed++;
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


    function print_test_summary() {
        global $test_counter, $test_passed, $test_failed;
        print "\nTEST SUMMARY: \n";
        print "   Total tests: " . $test_counter . "\n";
        print "   Passed     : " . $test_passed . "\n";
        print "   Failed     : " . $test_failed . "\n";
    }




?>