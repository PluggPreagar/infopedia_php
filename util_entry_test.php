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

    require_once "util_entry.php";
    require_once "util_test.php";

    function json_encode_($data) {
        // replace json-"\/" for "/"
        return str_replace( "\/", "/", json_encode($data) );
    }

    function trnsf_run($data_raw, $data_expect, $message) {
        $data = transform_0v02( $data_raw );
        assert_equals(  json_encode_($data_expect), json_encode_($data) , $message);
    }

    function transform_0v02_test() {
        trnsf_run( ['0/0/0/t,p0 | n0 | d0'], ["p0/n0##9-9-9 t##p0|n0|0-0-0 t|d0"], "basic, single, inv time-order, sortable");
        trnsf_run( ['0/0/0/t,"p0 | n0 | d0"'], ["p0/n0##9-9-9 t##p0|n0|0-0-0 t|d0"], "basic, single quoted - Quoted-Hint");
        // allow proper time-format
        trnsf_run( ['0-0-0 t,"p0 | n0 | d0"'], ["p0/n0##9-9-9 t##p0|n0|0-0-0 t|d0"], "basic, time formated already y-m-d");
        // multiline - allow easy reading
        trnsf_run( ['0/0/0/t,"p0 | n0 | d0_
                    _0d"']
            , ["p0/n0##9-9-9 t##p0|n0|0-0-0 t|d0_\\r\\n                    _0d"]
            , "multiline - basic in a string");
        trnsf_run( ['0/0/0/t,"p0 | n0 | d0_'
                    ,'_0d"']
            , ["p0/n0##9-9-9 t##p0|n0|0-0-0 t|d0_\\n_0d"]
            , "multiline - basic in 2 strings, 2 lines");
    }

    /*
     *
     *
     */

    function cleanData_run($data_in, $data_expect, $message) {
        $data_csv=[];
        foreach ($data_in as $d_in) {
            // p|n|t|d --> to csv : t,p|n|d
            $parts= preg_split('/\|/', $d_in);
            $data_csv[]=$parts[2].",".$parts[0]."|".$parts[1]."|".$parts[3];
        }
        $data_raw = transform_0v02($data_csv);
        $data = cleanData( $data_raw );
        $data = array_values($data); // re-index, remove unset indexes / ease test
        assert_equals(json_encode_($data_expect) , json_encode_($data) , $message);
    }

    function cleanData_test() {
        // !! tricky using "##<inverted time>##" to allow sorting the newest first ...
        cleanData_run( ["p0|n0|0-0-0 t|d0"], ["p0|n0|0-0-0 t|d0"], "clean basic keep");
        cleanData_run( ["p0|n0|0-0-0 t|d0--"], [], "clean basic delete");

        cleanData_run( ["p0|n0|0-0-0 t|d0_old", "p0|n0|9-9-9 t|d0_new"]
                    , ["p0|n0|9-9-9 t|d0_new"], "clean keep newest");
        cleanData_run( ["p0|n0|0-0-0 t|d0_old", "p0|n0|9-9-9 t|d0--"]
            , [], "clean remove if newest was deleted");

        cleanData_run( ["p0|n_foo|0-0-0 t|d0_foo", "p0|n_bar|9-9-9 t|d0_bar"]
            , ["p0|n_bar|9-9-9 t|d0_bar","p0|n_foo|0-0-0 t|d0_foo"], "clean sort by asc");

        cleanData_run( [
                "p0|n_foo|0-0-0 t|d0_foo"
                , "p0|n_bar|9-9-9 t|d0_bar"
                , "p0/n_foo|foo2|9-9-9 t|foo_foo2"
            ]
            , [
                 "p0|n_bar|9-9-9 t|d0_bar"
                ,"p0|n_foo|0-0-0 t|d0_foo"
                ,"p0/n_foo|foo2|9-9-9 t|foo_foo2"
            ]
            , "clean sort by asc");
    }

    /*
     *
     *
     */
/* -- split every row instead ... for now ...
    function topicFilter($topic) {
        $filter = "";
        $parts = explode('/', str_replace( "|", "/", $topic));
        foreach ($parts as $part) {
            $filter .= $part."[/|]";
        }
    }

    function topicFilter_run($topic, $data_expect, $message) {
        $data = topicFilter( $topic );
        assert_equals(  json_encode_($data_expect), json_encode_($data) , $message);
    }

    function topicFilter_test(){
        topicFilter_run("p0",    "/^[|]?p0[/|]/" , "1.level - itself, direct childs, grand childs");
        topicFilter_run("/p0",   "/^[|]?p0[/|]/" , "1.level - with root prefix");
        topicFilter_run("p0/p1", "/^[|]?p0[/|](p1[/|])?/", "1.level - itself, direct childs, grand childs");
        topicFilter_run("p0|p1", "/^[|]?p0[/|](p1[/|])?/", "1.level - with delmiter notation");
        topicFilter_run("p0/p1/p2", "/^[|]?p0[/|](p1[/|](p2[/|])?)?/", "2.level - itself, direct childs, grand childs");
    }

    function topicFilter_test_(){
        topicFilter_run("p0",    ["|p0|","p0|","p0/"] , "1.level - itself, direct childs, grand childs");
        topicFilter_run("/p0",   ["|p0|","p0|","p0/"] , "1.level - with root prefix");
        topicFilter_run("p0/p1", ["|p0|","p0|p1|","p0/p1|","p0/p1/"] , "1.level - itself, direct childs, grand childs");
    }
*/

    transform_0v02_test();
    cleanData_test();
    //topicFilter_test();



?>