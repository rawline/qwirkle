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
    fail('Пропущены game_id или player_id', 400);

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

    // Before refilling, compute scoring for tiles placed during this player's current step
    // Determine current step id for this player
    $s2 = $pdo->prepare('SELECT id_step FROM steps WHERE id_player = :pid ORDER BY id_step DESC LIMIT 1');
    $s2->execute([':pid' => $player_id]);
    $myStepId = (int) $s2->fetchColumn();

    $scoreDelta = 0;
    $qwirklesDone = 0;
    // Count player's tiles before refill to detect end-game finish
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM players_tiles WHERE id_player = :pid');
    $countStmt->execute([':pid' => $player_id]);
    $tilesBeforeRefill = (int) $countStmt->fetchColumn();

    if ($myStepId > 0) {
        // Compute score based on placed_tiles logged during place_tile
        try {
            $res = compute_score_for_step($pdo, $game_id, $myStepId);
            $scoreDelta = (int) ($res['score'] ?? 0);
            $qwirklesDone = (int) ($res['qwirkles'] ?? 0);
        } catch (Throwable $ee) {
            // ignore scoring errors
            $scoreDelta = 0;
            $qwirklesDone = 0;
        }
    }

    // Check remaining tiles in pool (not assigned to any player nor placed on any board)
    $remStmt = $pdo->prepare('SELECT COUNT(*) FROM tiles t
                              WHERE NOT EXISTS (SELECT 1 FROM players_tiles pt WHERE pt.id_tile = t.id)
                              AND NOT EXISTS (SELECT 1 FROM cells c WHERE c.id_tile = t.id)');
    $remStmt->execute();
    $remainingTiles = (int) $remStmt->fetchColumn();

    // If player had zero tiles before refill and there are no remaining tiles, they finished the game
    $endGameBonus = 0;
    if ($tilesBeforeRefill === 0 && $remainingTiles === 0) {
        $endGameBonus = 6;
    }

    // Now perform refill and advance turn inside a transaction
    $pdo->beginTransaction();
    // Refill finisher's rack up to 6 before passing the turn
    try {
        refill_player_tiles($pdo, $player_id, 6);
    } catch (Throwable $ignore) {
        // ignore refill errors
    }

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

    // Update player's score with computed delta and possible end-game bonus
    if ($scoreDelta !== 0 || $endGameBonus !== 0) {
        $upd = $pdo->prepare('UPDATE players SET score = COALESCE(score,0) + :delta WHERE player_id = :pid');
        $upd->execute([':delta' => $scoreDelta + $endGameBonus, ':pid' => $player_id]);
    }

    $stmt = $pdo->prepare('INSERT INTO steps (id_player, step_begin) VALUES (:pid, NOW())');
    $stmt->execute([':pid' => $nextPlayerId]);

    // Clean up placed_tiles for this step to avoid double scoring
    try {
        $del = $pdo->prepare('DELETE FROM placed_tiles WHERE id_game = :gid AND id_step = :sid');
        $del->execute([':gid' => $game_id, ':sid' => $myStepId]);
    } catch (Throwable $ignore) {
        // ignore
    }

    $pdo->commit();

    respond(['success' => true, 'next_player_id' => $nextPlayerId, 'score_delta' => $scoreDelta, 'qwirkles' => $qwirklesDone, 'end_game_bonus' => $endGameBonus]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction())
        $pdo->rollBack();
    fail('finish_turn failed: ' . $e->getMessage(), 500);
}
