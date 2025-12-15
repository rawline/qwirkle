<?php
require __DIR__ . '/_bootstrap.php';

// Endpoint: POST swap_tiles.php
// Body: { player_id }
// Rules:
//  - Must be player's turn
//  - Player can swap only if they have NOT placed any tile in the current step
//  - After swapping, player cannot place tiles in the same step (enforced in place_tile.php)
//  - Swapped tiles are returned to the pool implicitly by deleting from players_tiles

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed', 405);
}

$token = get_token_from_request();
if (!$token) fail('Токен обязателен', 401);

$body = read_json_body();
$player_id = (int) ($body['player_id'] ?? 0);
if (!$player_id) fail('Пропущен player_id', 400);

$pdo = null;
try {
    $pdo = db();

    // Resolve login from token
    $stmt = $pdo->prepare('SELECT login FROM tokens WHERE token = :t');
    $stmt->execute([':t' => $token]);
    $tok = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tok) fail('Invalid token', 401);
    $login = $tok['login'];

    // Resolve player's game_id and verify ownership
    $stmt = $pdo->prepare('SELECT login, game_id FROM players WHERE player_id = :pid');
    $stmt->execute([':pid' => $player_id]);
    $pRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pRow) fail('Игрок не найден', 404);
    if ($pRow['login'] !== $login) fail('Не ваш игрок', 403);
    $game_id = (int) ($pRow['game_id'] ?? 0);
    if ($game_id <= 0) fail('Игра не найдена для игрока', 404);

    // Ensure game is full
    $seatStmt = $pdo->prepare('SELECT g.seats, COUNT(p.player_id) AS cnt
                               FROM games g LEFT JOIN players p ON p.game_id = g.game_id
                               WHERE g.game_id = :gid
                               GROUP BY g.seats');
    $seatStmt->execute([':gid' => $game_id]);
    $seatRow = $seatStmt->fetch(PDO::FETCH_ASSOC);
    if (!$seatRow) fail('Игра не найдена', 404);
    if ((int)$seatRow['seats'] > 0 && (int)$seatRow['cnt'] < (int)$seatRow['seats']) {
        fail('Игра ещё не набрала всех игроков', 409);
    }

    // Block if game finished
    $winner = get_game_finished($pdo, $game_id);
    if ($winner !== null) {
        fail('Игра уже завершена', 409);
    }

    // Apply timeout-based advancement first
    auto_advance_turn($pdo, $game_id);

    // Determine current turn player and step id
    $stmt = $pdo->prepare('SELECT s.id_step, s.id_player
                           FROM steps s
                           JOIN players p ON p.player_id = s.id_player
                           WHERE p.game_id = :gid
                           ORDER BY s.id_step DESC
                           LIMIT 1');
    $stmt->execute([':gid' => $game_id]);
    $turnRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$turnRow) fail('Ход не найден', 500);
    $stepId = (int) ($turnRow['id_step'] ?? 0);
    $turnPlayer = (int) ($turnRow['id_player'] ?? 0);
    if ($turnPlayer !== $player_id) fail('Не ваш ход', 409);

    // Cannot swap if already placed any tile this step
    if ($stepId > 0 && step_has_any_placement($pdo, $game_id, $stepId)) {
        fail('Нельзя сбросить фишки после выкладки в этом ходе', 409);
    }

    // Cannot swap twice
    if ($stepId > 0 && has_swapped_in_step($pdo, $game_id, $stepId, $player_id)) {
        fail('Вы уже сбрасывали фишки в этом ходе', 409);
    }

    $pdo->beginTransaction();

    // Remove all tiles from player's rack (returned to pool implicitly)
    $del = $pdo->prepare('DELETE FROM players_tiles WHERE id_player = :pid');
    $del->execute([':pid' => $player_id]);

    // Refill to target
    refill_player_tiles($pdo, $player_id, MAX_TILES_IN_HAND);

    // Mark swap usage for this step
    if ($stepId > 0) {
        mark_swapped_in_step($pdo, $game_id, $stepId, $player_id);
    }

    $pdo->commit();

    // Return updated tiles
    $tilesStmt = $pdo->prepare('SELECT t.id AS id_tile, t.color, t.shape
                                FROM players_tiles pt
                                JOIN tiles t ON t.id = pt.id_tile
                                WHERE pt.id_player = :pid
                                ORDER BY t.id');
    $tilesStmt->execute([':pid' => $player_id]);
    $myTiles = $tilesStmt->fetchAll();

    respond([
        'success' => true,
        'my_tiles' => $myTiles,
    ]);

} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    fail('swap_tiles failed: ' . $e->getMessage(), 500);
}
