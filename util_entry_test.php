<?php
    require_once "util_entry.php";
    require_once "util_test.php";

    // start_php_test.php

    function frmt( $expected, $entry, $msg ) {
        assert_equals( $expected, formatEntry( $entry ), "formatEntry(\"".$msg."\")" );
    }


    function formatEntry_test() {
        frmt( "" ,"", "empty");
        frmt( "" ,"/", "empty - slash only");
        frmt( "" ,"Timestamp", "empty - csv header only");

        frmt( "/parent/node,2025-01-01 12:00:00,data" , "2025-01-01 12:00:00, /parent | node | data", "old line");
        frmt( "/p/n,2025-01-01 12:00:00,d" , "2025-01-01 12:00:00, /p | n | d", "old line short");
        frmt( "/p/n::Vote::sid_example,2025-01-01 12:00:00,d,vote"
            , "2025-01-01 12:00:00, /p | n::Vote::sid_example | d | vote"
            , "old line with vote");
        frmt( "/p/n::Vote::unknown,2025-01-01 12:00:00,d,vote"
            , "2025-01-01 12:00:00, /p | n | d | vote"
            , "old line with vote - missing attribute");

        frmt( "/p/n,2025-01-01 12:00:00,d\\nd2" , "2025-01-01 12:00:00, /p | n | d\nd2", "old line with newline in data");
        frmt( "/p/n,2025-01-01 12:00:00,d" , "2025-01-01 12:00:00,\"/p | n | d\"", "old line quoted");
        frmt( "/p/n,2025-01-01 12:00:00,\"d,d\"" , "2025-01-01 12:00:00,/p | n | d,d", "old line containing comma");

        frmt( "/_/check/1759255656/55199::Vote::sid_example,2025-12-13 09:20:46,2  akfkfafkaf adjwg,1"
            , "2025-12-13 09:20:46,/_/check/1759255656 | 55199::Vote::sid_example | 2  akfkfafkaf adjwg | 1"
            , "realistic old line" );

        frmt( "/p/n,2025-01-01 12:00:00,d" , "//p/n,2025-01-01 12:00:00,d" , "new line with double slash");
        frmt( "/p/n::Vote::unknown,2025-01-01 12:00:00,d,1" , "/p/n,2025-01-01 12:00:00,d,1" , "vote w/o set vote-attribute");

        // REST-Call beginnt wie neues Format !!!
        // frmt( "", "/1754140907 | 3wxh565purs5 | Es auch gezeigt, dass wir zusammenhalten können..", "");

    }


    formatEntry_test();
    print_test_summary();

?>