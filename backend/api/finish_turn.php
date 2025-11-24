<?php
require __DIR__ . '/_bootstrap.php';

// Endpoint to finish current player's turn: POST finish_turn.php
// Body: { game_id, player_id }
// Validates token ownership and that it's the player's turn, then moves to the next player.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed', 405);
}

$token = get_token_from_request();
if (!$token)
    fail('Token required', 401);
$body = read_json_body();
$game_id = (int) ($body['game_id'] ?? 0);
$player_id = (int) ($body['player_id'] ?? 0);
if (!$game_id || !$player_id)
    fail('Missing required fields');

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
    $stmt = $pdo->prepare('SELECT login FROM players WHERE player_id = :pid AND game_id = :gid');
    $stmt->execute([':pid' => $player_id, ':gid' => $game_id]);
    $pRow = $stmt->fetch();
    if (!$pRow)
        fail('Player not in game', 404);
    if ($pRow['login'] !== $login)
        fail('Not your player', 403);

    // Apply timeout-based advancement first
    auto_advance_turn($pdo, $game_id);

    // Check it's still this player's turn
    $stmt = $pdo->prepare('SELECT s.id_player FROM steps s JOIN players p ON p.player_id = s.id_player WHERE p.game_id = :gid ORDER BY s.id_step DESC LIMIT 1');
    $stmt->execute([':gid' => $game_id]);
    $turnPlayer = (int) $stmt->fetchColumn();
    if ($turnPlayer !== $player_id)
        fail('Not your turn', 409);

    // Refill finisher's rack up to 5 before passing the turn
    refill_player_tiles($pdo, $player_id, 5);

    // Advance to next player by order
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT player_id FROM players WHERE game_id = :gid ORDER BY turn_order');
    $stmt->execute([':gid' => $game_id]);
    $players = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$players) {
        $pdo->rollBack();
        fail('No players in game', 500);
    }
    $asInts = array_map('intval', $players);
    $idx = array_search($player_id, $asInts, true);
    $nextIdx = ($idx === false) ? 0 : (($idx + 1) % count($asInts));
    $nextPlayerId = (int) $asInts[$nextIdx];

    $stmt = $pdo->prepare('INSERT INTO steps (id_player, step_begin) VALUES (:pid, NOW())');
    $stmt->execute([':pid' => $nextPlayerId]);
    $pdo->commit();

    respond(['success' => true, 'next_player_id' => $nextPlayerId]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction())
        $pdo->rollBack();
    fail('finish_turn failed: ' . $e->getMessage(), 500);
}
