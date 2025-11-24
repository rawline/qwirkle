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

    // Check permissions: only creator (turn_order = 1) can delete
    $stmt = $pdo->prepare('SELECT p.login AS creator_login
                           FROM players p
                           WHERE p.game_id = :g AND p.turn_order = 1
                           LIMIT 1');
    $stmt->execute([':g' => $game_id]);
    $row = $stmt->fetch();
    if (!$row)
        fail('Game not found or no creator', 404);
    if ($row['creator_login'] !== $login)
        fail('Forbidden: only game creator can delete', 403);

    $pdo->beginTransaction();

    // Collect player_ids to clean related tables
    $stmt = $pdo->prepare('SELECT player_id FROM players WHERE game_id = :g');
    $stmt->execute([':g' => $game_id]);
    $playerIds = array_map('intval', array_column($stmt->fetchAll(), 'player_id'));

    if (!empty($playerIds)) {
        // Delete players_tiles for these players
        $in = implode(',', array_fill(0, count($playerIds), '?'));
        $pdo->prepare("DELETE FROM players_tiles WHERE id_player IN ($in)")->execute($playerIds);

        // Delete steps for these players
        $pdo->prepare("DELETE FROM steps WHERE id_player IN ($in)")->execute($playerIds);
    }

    // Delete cells for the game
    $stmt = $pdo->prepare('DELETE FROM cells WHERE id_game = :g');
    $stmt->execute([':g' => $game_id]);

    // Delete players
    $stmt = $pdo->prepare('DELETE FROM players WHERE game_id = :g');
    $stmt->execute([':g' => $game_id]);

    // Delete the game
    $stmt = $pdo->prepare('DELETE FROM games WHERE game_id = :g');
    $stmt->execute([':g' => $game_id]);

    $pdo->commit();

    respond(['success' => true]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction())
        $pdo->rollBack();
    fail('delete_game failed: ' . $e->getMessage(), 500);
}
