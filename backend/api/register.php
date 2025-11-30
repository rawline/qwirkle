<?php
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed', 405);
}

$body = read_json_body();
$login = trim($body['login'] ?? '');
$password = trim($body['password'] ?? '');

if ($login === '' || $password === '')
    fail('login and password required');
if (strlen($login) > 30)
    fail('login too long (max 30)');
if (strlen($password) < 3)
    fail('password too short');

try {
    $pdo = db();
    // Check if exists
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE login = :login');
    $stmt->execute([':login' => $login]);
    if ($stmt->fetch())
        fail('login already taken', 409);

    // Insert user (store plain password per given schema; in production use hashing)
    $stmt = $pdo->prepare('INSERT INTO users (login, password) VALUES (:login, :password)');
    $stmt->execute([':login' => $login, ':password' => $password]);

    // Call auth function to issue token immediately
    $stmt = $pdo->prepare('SELECT auth(:p_login, :p_pass) AS res');
    $stmt->execute([':p_login' => $login, ':p_pass' => $password]);
    $row = $stmt->fetch();
    $resJson = $row['res'] ?? null;
    if (!$resJson)
        fail('registration succeeded but token failed', 500);
    $data = json_decode($resJson, true);
    if (!is_array($data))
        respond($resJson);
    respond($data);
} catch (Throwable $e) {
    fail('register failed: ' . $e->getMessage(), 500);
}
