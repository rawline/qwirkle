<?php
echo "Extensions:\n";
foreach (['pdo_pgsql', 'pgsql'] as $ext) {
    echo $ext . ': ' . (extension_loaded($ext) ? 'loaded' : 'missing') . "\n";
}

$dsn = 'pgsql:host=127.0.0.1;port=5432;dbname=studs';
$user = 's373445';
$pass = 'RnNH9qPQo10HprTE';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "Connection OK\n";
    $r = $pdo->query('SELECT 1')->fetch();
    var_dump($r);
} catch (Throwable $e) {
    echo "Connection FAIL: " . $e->getMessage() . "\n";
}