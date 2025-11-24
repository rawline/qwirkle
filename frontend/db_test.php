<?php

$host = 'pg';
$db = 'studs';
$db_user = 's373445';
$db_password = 'RnNH9qPQo10HprTE';

$dns = "pgsql:host=$host;port=5432;dbname=$db";

try {
    $pdo = new PDO($dns, $db_user, $db_password);
    // echo "jf;sjdflsadjf";
} catch (PDOException $e) {
    print_r($e);
}