<?php
 $test  = true;
 $filter = "";
 require "infopedia.php";

 $topic = "";
 setFilters();
 $filteredData = loadFilteredContent("entries_for_test.cache", $filter, parentsToTopicFilter($topic) );

 ?>