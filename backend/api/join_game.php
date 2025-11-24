<?php
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed', 405);
}

$token = get_token_from_request();
if (!$token)
    fail('Token required');

$body = read_json_body();
$game_id = isset($body['game_id']) ? (int) $body['game_id'] : (isset($_POST['game_id']) ? (int) $_POST['game_id'] : 0);
if ($game_id <= 0)
    fail('game_id required');

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

    // Check if already in game
    $stmt = $pdo->prepare('SELECT player_id FROM players WHERE game_id = :g AND login = :login');
    $stmt->execute([':g' => $game_id, ':login' => $login]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        respond(['game_id' => $game_id, 'player_id' => (int) $existing]);
    }

    // Check seats availability
    $stmt = $pdo->prepare('SELECT g.seats, COUNT(p.player_id) AS cnt
                           FROM games g LEFT JOIN players p ON p.game_id = g.game_id
                           WHERE g.game_id = :g
                           GROUP BY g.seats');
    $stmt->execute([':g' => $game_id]);
    $row = $stmt->fetch();
    if (!$row)
        fail('Game not found', 404);
    if ((int) $row['cnt'] >= (int) $row['seats'])
        fail('Game is full', 409);

    $pdo->beginTransaction();

    // Determine next turn order (max + 1)
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(turn_order), 0) + 1 FROM players WHERE game_id = :g');
    $stmt->execute([':g' => $game_id]);
    $next_order = (int) $stmt->fetchColumn();

    // Insert player
    $stmt = $pdo->prepare('INSERT INTO players (login, turn_order, score, game_id)
                           VALUES (:login, :turn_order, 0, :game_id)
                           RETURNING player_id');
    $stmt->execute([':login' => $login, ':turn_order' => $next_order, ':game_id' => $game_id]);
    $player_id = (int) $stmt->fetchColumn();

    // Deal initial tiles (5 unique from tiles table)
    $tq = $pdo->query('SELECT id FROM tiles ORDER BY random() LIMIT 5');
    $ids = $tq->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids || count($ids) < 1) {
        throw new RuntimeException('No tiles available in DB to deal');
    }
    $ins = $pdo->prepare('INSERT INTO players_tiles (id_player, id_tile) VALUES (:pid, :tid)');
    foreach ($ids as $tid) {
        $ins->execute([':pid' => $player_id, ':tid' => (int) $tid]);
    }

    $pdo->commit();

    respond(['game_id' => $game_id, 'player_id' => $player_id]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction())
        $pdo->rollBack();
    fail('join_game failed: ' . $e->getMessage(), 500);
}
