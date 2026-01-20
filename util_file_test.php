<?php

    require_once "util.php";
    require_once "util_file.php";
    require_once "util_test.php";

    function json_encode_($data) {
        // replace json-"\/" for "/"
        return str_replace( "\/", "/", json_encode($data) );
    }

    function loadFiltered_run($data_raw, $folderNode, $data_expect, $message) {
        $data = loadFiltered( $data_raw, $folderNode );
        // [ "parents" => $parents,  "myself" => $myself, "children" => $children , "hasGrandChilds" => $hasGrandChilds ]
        $idxList = [ "parents", "myself", "children", "hasGrandChilds" ];
        foreach ($idxList as $idx) {
            if (isset($data[$idx])) {
                assert_equals(  json_encode_($data_expect[$idx]), json_encode_($data[$idx]) , $message." (".$idx.")");
            }
        }
    }

    function loadFiltered_test() {
        loadFiltered_run( [""], ""
                , [ "parents" => [], "myself" => "", "children" => [], "hasGrandChilds" => [] ]
                , "empty"
        );
        loadFiltered_run(
            ["|p0|c0"]
            , "p0"
            , [ "parents" => [], "myself" => "|p0|c0", "children" => [], "hasGrandChilds" => [] ]
            , "h1 alone"
        );
        loadFiltered_run(
            ["|p00|c00", "|p0|c0", "|p01|c01"]
            , "p0"
            , [ "parents" => [], "myself" => "|p0|c0", "children" => [], "hasGrandChilds" => [] ]
            , "h1 with siblings"
        );

        loadFiltered_run(
            ["|p00|c00", "|p0|c0", "/p0|p0_1|c0_1", "/p0/p0_1|p0_1_1|c0_1_1", "|p01|c01"]
            , "p0"
            , [ "parents" => [], "myself" => "|p0|c0", "children" => ["/p0|p0_1|c0_1"], "hasGrandChilds" => ["/p0|p0_1"] ]
            , "h1 with grand children"
        );
        loadFiltered_run(
            ["|p00|c00", "|p0|c0", "/p0|p0_1|c0_1", "/p0/p0_1|p0_1_1|c0_1_1", "|p01|c01"]
            , "p0/p0_1"
            , [ "parents" => ["|p0|c0"], "myself" => "/p0|p0_1|c0_1", "children" => ["/p0/p0_1|p0_1_1|c0_1_1"], "hasGrandChilds" => [] ]
            , "h2 "
        );
        loadFiltered_run(
            ["|p00|c00", "|p0|c0", "/p0|p0_1|c0_1", "/p0/p0_1|p0_1_1|c0_1_1", "|p01|c01"]
            , "/p0/p0_1|p0_1_1"
            , [ "parents" => ["|p0|c0","/p0|p0_1|c0_1"], "myself" => "/p0/p0_1|p0_1_1|c0_1_1", "children" => [], "hasGrandChilds" => [] ]
            , "h3 "
        );
    }

    loadFiltered_test();

?>