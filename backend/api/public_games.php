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

    // Resolve current user's login (optional: to exclude own games or detect already joined)
    $stmt = $pdo->prepare('SELECT login FROM tokens WHERE token = :t');
    $stmt->execute([':t' => (int) $token]);
    $tok = $stmt->fetch();
    if (!$tok)
        fail('Invalid token', 401);
    $login = $tok['login'];

    // List games which are not full and where user is NOT already a participant
    $sql = 'WITH counts AS (
                SELECT g.game_id, g.seats, g.move_time, COUNT(p.player_id) AS players_count
                FROM games g
                LEFT JOIN players p ON p.game_id = g.game_id
                GROUP BY g.game_id, g.seats, g.move_time
            )
            SELECT c.game_id, c.seats, c.move_time, c.players_count
            FROM counts c
            WHERE c.players_count < c.seats
              AND NOT EXISTS (
                  SELECT 1 FROM players p2 WHERE p2.game_id = c.game_id AND p2.login = :login
              )
            ORDER BY c.game_id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':login' => $login]);

    $games = $stmt->fetchAll();
    respond(['games' => $games]);
} catch (Throwable $e) {
    fail('public_games failed: ' . $e->getMessage(), 500);
}
