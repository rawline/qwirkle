<?php
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed', 405);
}

$body = read_json_body();
$login = trim($body['login'] ?? '');
$password = trim($body['password'] ?? '');
if ($login === '' || $password === '') {
    fail('login and password are required');
}

try {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT auth(:p_login, :p_pass) AS res');
    $stmt->execute([':p_login' => $login, ':p_pass' => $password]);
    $row = $stmt->fetch();
    if (!$row)
        fail('Empty response from auth', 500);

    $resJson = $row['res'] ?? null; // function returns JSON string
    if (!$resJson)
        fail('Invalid auth result', 500);

    // The DB function returns a JSON string; decode and return as JSON
    $data = json_decode($resJson, true);
    if (!is_array($data)) {
        // Fallback: return as string
        respond($resJson);
    }
    respond($data);
} catch (Throwable $e) {
    fail('Auth failed: ' . $e->getMessage(), 500);
}
