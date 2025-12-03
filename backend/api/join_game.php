<?php
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed', 405);
}

$token = get_token_from_request();
if (!$token)
    fail('Токен обязателен');

$body = read_json_body();
$game_id = isset($body['game_id']) ? (int) $body['game_id'] : (isset($_POST['game_id']) ? (int) $_POST['game_id'] : 0);
if ($game_id <= 0)
    fail('game_id обязательно');

$pdo = null;
try {
    $pdo = db();

    // Resolve login by token
    $stmt = $pdo->prepare('SELECT login FROM tokens WHERE token = :t');
    $stmt->execute([':t' => (int) $token]);
    $tok = $stmt->fetch();
    if (!$tok)
        fail('Неверный токен', 401);
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
        fail('Игра не найдена', 404);
    if ((int) $row['cnt'] >= (int) $row['seats'])
        fail('Игра заполнена', 409);

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

    // Deal initial tiles: refill player's rack up to 6 unique tiles for the game
    refill_player_tiles($pdo, $player_id, 6);

    $pdo->commit();
    // Если игра стала полной (players == seats), создаём/инициализируем текущий шаг на первого игрока
    try {
        $chk = $pdo->prepare('SELECT g.seats, COUNT(p.player_id) AS cnt FROM games g LEFT JOIN players p ON p.game_id = g.game_id WHERE g.game_id = :g GROUP BY g.seats');
        $chk->execute([':g' => $game_id]);
        $row2 = $chk->fetch(PDO::FETCH_ASSOC);
        if ($row2 && (int)$row2['seats'] > 0 && (int)$row2['cnt'] >= (int)$row2['seats']) {
            // игра полная — найдём первого по порядку
            $firstStmt = $pdo->prepare('SELECT player_id FROM players WHERE game_id = :g ORDER BY turn_order ASC LIMIT 1');
            $firstStmt->execute([':g' => $game_id]);
            $firstPid = (int)$firstStmt->fetchColumn();
            if ($firstPid > 0) {
                // проверим, есть ли уже шаг
                $hasStep = $pdo->prepare('SELECT s.id_step FROM steps s JOIN players p ON p.player_id = s.id_player WHERE p.game_id = :g ORDER BY s.id_step DESC LIMIT 1');
                $hasStep->execute([':g' => $game_id]);
                $existingStep = $hasStep->fetchColumn();
                if (!$existingStep) {
                    // создать стартовый шаг
                    $ins = $pdo->prepare('INSERT INTO steps (id_player, step_begin) VALUES (:pid, NOW())');
                    $ins->execute([':pid' => $firstPid]);
                }
            }
        }
    } catch (Throwable $ignore) {
        // игнорируем ошибки инициализации шага
    }

    respond(['game_id' => $game_id, 'player_id' => $player_id]);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction())
        $pdo->rollBack();
    fail('join_game не удался: ' . $e->getMessage(), 500);
}
