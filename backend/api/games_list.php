<?php
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    fail('Method not allowed', 405);
}

$token = get_token_from_request();
if (!$token)
    fail('Token required');

try {
    $pdo = db();

    // Resolve login by token
    $stmt = $pdo->prepare('SELECT login FROM tokens WHERE token = :t');
    $stmt->execute([':t' => (int) $token]);
    $tok = $stmt->fetch();
    if (!$tok)
        fail('Invalid token', 401);
    $login = $tok['login'];

    // Games where this user participates
    $sql = 'SELECT g.game_id, g.seats, g.move_time, p.player_id, p.turn_order
            FROM players p
            JOIN games g ON g.game_id = p.game_id
            WHERE p.login = :login
            ORDER BY g.game_id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':login' => $login]);

    $games = $stmt->fetchAll();
    respond(['games' => $games]);
} catch (Throwable $e) {
    fail('games_list failed: ' . $e->getMessage(), 500);
}
