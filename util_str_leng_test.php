<?php
require_once 'util_test.php';

foreach (['upload.php', 'download.php'] as $file) {
    $src = file_get_contents($file);
    assert_equals(str_contains($src, 'str_leng('), false, "$file: no str_leng() typo");
}

assert_contains(file_get_contents('upload.php'), 'strlen(', 'upload.php: strlen() present');
assert_contains(file_get_contents('download.php'), 'filesize(', 'download.php: filesize() present');

print_test_summary();

