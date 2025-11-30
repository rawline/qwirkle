<?php
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed', 405);
}

$token = get_token_from_request();
if (!$token)
    fail('Token required');

$body = read_json_body();
$seats = isset($body['seats']) ? (int) $body['seats'] : 4;
$move_time = isset($body['move_time']) ? (int) $body['move_time'] : 60;

// prepare $pdo for catch scope
$pdo = null;
try {
    $pdo = db();

    // Resolve login by token
    $stmt = $pdo->prepare('SELECT login FROM tokens WHERE token = :t');
    $stmt->execute([':t' => (int) $token]);
    $tok = $stmt->fetch();
    if (!$tok)
        fail('Invalid token', 401);
    $login = $tok['login'];

    $pdo->beginTransaction();

    // Create game
    $stmt = $pdo->prepare('INSERT INTO games (seats, move_time) VALUES (:seats, :move_time) RETURNING game_id');
    $stmt->execute([':seats' => $seats, ':move_time' => $move_time]);
    $game_id = (int) $stmt->fetchColumn();

    // Add player (creator) to players
    $stmt = $pdo->prepare('INSERT INTO players (login, turn_order, score, game_id) VALUES (:login, :turn_order, :score, :game_id) RETURNING player_id');
    $stmt->execute([':login' => $login, ':turn_order' => 1, ':score' => 0, ':game_id' => $game_id]);
    $player_id = (int) $stmt->fetchColumn();

    // Deal initial tiles (5 unique from tiles table)
    $tq = $pdo->query('SELECT id FROM tiles ORDER BY random() LIMIT 5');
    $ids = $tq->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids || count($ids) < 1) {
        // If no tiles in DB, better fail explicitly
        throw new RuntimeException('No tiles available in DB to deal');
    }
    $ins = $pdo->prepare('INSERT INTO players_tiles (id_player, id_tile) VALUES (:pid, :tid)');
    foreach ($ids as $tid) {
        $ins->execute([':pid' => $player_id, ':tid' => (int) $tid]);
    }

    // Initialize first turn (creator starts)
    $stmt = $pdo->prepare('INSERT INTO steps (id_player, step_begin) VALUES (:pid, NOW())');
    $stmt->execute([':pid' => $player_id]);

    $pdo->commit();

    respond(['game_id' => $game_id, 'player_id' => $player_id]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction())
        $pdo->rollBack();
    fail('create_game failed: ' . $e->getMessage(), 500);
}
