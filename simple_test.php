<?php

require_once __DIR__ . '/GoogleAppsEmail.class.php';

if (empty($argv[1])) {
    exit("Usage: php simple_test.php username\n");
}

echo  "Querying for user: $argv[1]\n";

$query = GoogleAppsEmail::_query($argv[1]);

var_dump($query);

echo "Done\n";
