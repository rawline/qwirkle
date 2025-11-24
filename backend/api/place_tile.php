<?php
require __DIR__ . '/_bootstrap.php';

// Endpoint to place a tile on the board: POST place_tile.php
// Body: { game_id, player_id, tile_id, x, y }
// Rules (simplified placeholder):
//  - Verify token owns player_id (players.login = token.login)
//  - Verify it's player's turn (latest steps row belongs to player_id)
//  - Check tile belongs to player in players_tiles
//  - Check target cell not occupied
//  - Place tile: insert into cells (id_game, cords_x, cords_y, id_tile)
//  - Remove tile from players_tiles
//  - Advance turn: create new steps row for next player
//  - Return updated minimal state

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed', 405);
}

$token = get_token_from_request();
if (!$token)
    fail('Token required');
$body = read_json_body();
$game_id = (int) ($body['game_id'] ?? 0);
$player_id = (int) ($body['player_id'] ?? 0);
$tile_id = (int) ($body['tile_id'] ?? 0);
$x = (int) ($body['x'] ?? 0);
$y = (int) ($body['y'] ?? 0);

if (!$game_id || !$player_id || !$tile_id || !is_numeric($body['x']) || !is_numeric($body['y'])) {
    fail('Missing required fields');
}

$pdo = null;
try {
    $pdo = db();

    // Resolve login from token
    $stmt = $pdo->prepare('SELECT login FROM tokens WHERE token = :t');
    $stmt->execute([':t' => (int) $token]);
    $tok = $stmt->fetch();
    if (!$tok)
        fail('Invalid token', 401);
    $login = $tok['login'];

    // Verify player ownership
    $stmt = $pdo->prepare('SELECT login, turn_order FROM players WHERE player_id = :pid AND game_id = :gid');
    $stmt->execute([':pid' => $player_id, ':gid' => $game_id]);
    $pRow = $stmt->fetch();
    if (!$pRow)
        fail('Player not in game', 404);
    if ($pRow['login'] !== $login)
        fail('Not your player', 403);

    // Ensure timeout-based progression before validating turn
    auto_advance_turn($pdo, $game_id);

    // Determine current turn player (latest steps)
    $stmt = $pdo->prepare('SELECT s.id_player FROM steps s JOIN players p ON p.player_id = s.id_player WHERE p.game_id = :gid ORDER BY s.id_step DESC LIMIT 1');
    $stmt->execute([':gid' => $game_id]);
    $turnPlayer = (int) $stmt->fetchColumn();
    if ($turnPlayer !== $player_id)
        fail('Not your turn', 409);

    // Verify tile belongs to player
    $stmt = $pdo->prepare('SELECT 1 FROM players_tiles WHERE id_player = :pid AND id_tile = :tid');
    $stmt->execute([':pid' => $player_id, ':tid' => $tile_id]);
    if (!$stmt->fetch())
        fail('Tile not owned by player', 404);

    // Ensure cell free
    $stmt = $pdo->prepare('SELECT 1 FROM cells WHERE id_game = :gid AND cords_x = :x AND cords_y = :y');
    $stmt->execute([':gid' => $game_id, ':x' => (string) $body['x'], ':y' => (string) $body['y']]);
    if ($stmt->fetch())
        fail('Cell occupied', 409);

    $pdo->beginTransaction();

    // Place tile
    $stmt = $pdo->prepare('INSERT INTO cells (id_game, cords_x, cords_y, id_tile) VALUES (:gid, :x, :y, :tid)');
    $stmt->execute([':gid' => $game_id, ':x' => (string) $body['x'], ':y' => (string) $body['y'], ':tid' => $tile_id]);

    // Remove tile from player's rack
    $stmt = $pdo->prepare('DELETE FROM players_tiles WHERE id_player = :pid AND id_tile = :tid');
    $stmt->execute([':pid' => $player_id, ':tid' => $tile_id]);

    // Refill up to 5 tiles in hand
    refill_player_tiles($pdo, $player_id, 5);

    $pdo->commit();
    // Turn is NOT advanced here; it will advance on finish_turn or by timeout
    respond(['success' => true]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction())
        $pdo->rollBack();
    fail('place_tile failed: ' . $e->getMessage(), 500);
}
