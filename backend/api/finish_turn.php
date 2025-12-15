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
$player_id = (int) ($body['player_id'] ?? 0);
if (!$player_id)
    fail('Пропущен player_id', 400);

$pdo = null;
try {
    $pdo = db();

    // Resolve login from token (treat token as string)
    $stmt = $pdo->prepare('SELECT login FROM tokens WHERE token = :t');
    $stmt->execute([':t' => $token]);
    $tok = $stmt->fetch();
    if (!$tok)
        fail('Invalid token', 401);
    $login = $tok['login'];

    // Resolve player's game_id and verify ownership
    $stmt = $pdo->prepare('SELECT login, game_id FROM players WHERE player_id = :pid');
    $stmt->execute([':pid' => $player_id]);
    $pRow = $stmt->fetch();
    if (!$pRow)
        fail('Player not in game', 404);
    if ($pRow['login'] !== $login)
        fail('Not your player', 403);
    $game_id = (int) ($pRow['game_id'] ?? 0);
    if ($game_id <= 0)
        fail('Game not found for player', 404);

    // Ensure game is full (all seats occupied) before allowing turn finish actions
    $seatStmt = $pdo->prepare('SELECT g.seats, COUNT(p.player_id) AS cnt
                               FROM games g LEFT JOIN players p ON p.game_id = g.game_id
                               WHERE g.game_id = :gid
                               GROUP BY g.seats');
    $seatStmt->execute([':gid' => $game_id]);
    $seatRow = $seatStmt->fetch(PDO::FETCH_ASSOC);
    if (!$seatRow) fail('Game not found', 404);
    if ((int)$seatRow['seats'] > 0 && (int)$seatRow['cnt'] < (int)$seatRow['seats']) {
        fail('Game not full yet', 409);
    }

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
    // Remaining tiles should be computed per game, not globally
    $remStmt = $pdo->prepare('SELECT COUNT(*) FROM tiles t
                              WHERE NOT EXISTS (
                                  SELECT 1 FROM players_tiles pt
                                  JOIN players p ON p.player_id = pt.id_player
                                  WHERE pt.id_tile = t.id AND p.game_id = :gid
                              )
                              AND NOT EXISTS (
                                  SELECT 1 FROM cells c WHERE c.id_tile = t.id AND c.id_game = :gid
                              )');
    $remStmt->execute([':gid' => $game_id]);
    $remainingTiles = (int) $remStmt->fetchColumn();

    // End game bonus is granted only to the player who ends the whole game:
    // pool is empty AND after this turn everybody has 0 tiles.
    $endGameBonus = 0;

    // Now perform scoring, (optionally) refill, and possibly end the game inside a transaction
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

    // We'll compute end condition after (optional) refill attempt.
    // Game ends only when pool is empty AND all players have 0 tiles.
    $gameFinished = false;

    // Update player's score with computed delta for this step
    if ($scoreDelta !== 0) {
        $upd = $pdo->prepare('UPDATE players SET score = COALESCE(score,0) + :delta WHERE player_id = :pid');
        $upd->execute([':delta' => $scoreDelta, ':pid' => $player_id]);
    }

    $winnerId = null;

    // Refill up to MAX_TILES_IN_HAND if possible (may do nothing when pool empty)
    try {
        refill_player_tiles($pdo, $player_id, MAX_TILES_IN_HAND);
    } catch (Throwable $ignore) {
        // ignore refill errors
    }

    // Determine whether the game is finished now.
    $poolEmptyNow = ($remainingTiles === 0);
    $allHandsEmptyNow = false;
    try {
        $allHandsEmptyNow = game_all_hands_empty($pdo, $game_id);
    } catch (Throwable $ignore) {
        $allHandsEmptyNow = false;
    }
    $gameFinished = ($poolEmptyNow && $allHandsEmptyNow);

    // If game ends, grant bonus to the player who ended it (only once)
    if ($gameFinished && $tilesBeforeRefill === 0) {
        try {
            if (get_game_finished($pdo, $game_id) === null) {
                $endGameBonus = END_GAME_BONUS;
                if ($endGameBonus !== 0) {
                    $upd = $pdo->prepare('UPDATE players SET score = COALESCE(score,0) + :delta WHERE player_id = :pid');
                    $upd->execute([':delta' => $endGameBonus, ':pid' => $player_id]);
                }
            }
        } catch (Throwable $ignore) {}
    }

    if ($gameFinished) {
        // Compute winner and mark finished; do not insert next step
        try {
            $winnerId = compute_winner($pdo, $game_id);
            if ($winnerId !== null) mark_game_finished($pdo, $game_id, $winnerId);
        } catch (Throwable $ignore) {}
    } else {
        // Insert a new step for the next player (new id per turn)
        $ins = $pdo->prepare('INSERT INTO steps (id_player, step_begin) VALUES (:pid, NOW())');
        $ins->execute([':pid' => $nextPlayerId]);
    }

    // Clean up placed_tiles for this step to avoid double scoring
    try {
        $del = $pdo->prepare('DELETE FROM placed_tiles WHERE id_game = :gid AND id_step = :sid');
        $del->execute([':gid' => $game_id, ':sid' => $myStepId]);
    } catch (Throwable $ignore) {
        // ignore
    }

    $pdo->commit();

    respond([
        'success' => true,
        'next_player_id' => $gameFinished ? null : $nextPlayerId,
        'score_delta' => $scoreDelta,
        'qwirkles' => $qwirklesDone,
        'end_game_bonus' => $endGameBonus,
        'game_finished' => $gameFinished,
        'winner_player_id' => $winnerId
    ]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction())
        $pdo->rollBack();
    fail('finish_turn failed: ' . $e->getMessage(), 500);
}
