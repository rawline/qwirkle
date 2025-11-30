<?php
require __DIR__ . '/_bootstrap.php';

// Endpoint: POST place_tile.php
// Body: { game_id, player_id, tile_id, x, y }
// Responsibilities:
//  - Authenticate via token and ensure player ownership
//  - Ensure it's the player's turn (latest step)
//  - Validate tile belongs to player and target cell is free
//  - Enforce Qwirkle placement rules (adjacent, line consistency, uniqueness, max length 6)
//  - Enforce multi-tile-in-turn rule (same row/column, contiguous)
//  - Place the tile and remove it from rack
//  - Record placement in placed_tiles for later scoring aggregation
//  - Return points for this single placement (horizontal + vertical lines, Qwirkle bonus)

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('Method not allowed', 405);
}

$token = get_token_from_request();
if (!$token) fail('Токен обязателен', 401);

$body = read_json_body();
$game_id = (int) ($body['game_id'] ?? 0);
$player_id = (int) ($body['player_id'] ?? 0);
$tile_id = (int) ($body['tile_id'] ?? 0);
$x_raw = $body['x'] ?? null;
$y_raw = $body['y'] ?? null;

if (!$game_id || !$player_id || !$tile_id || !is_numeric($x_raw) || !is_numeric($y_raw)) {
    fail('Пропущены обязательные поля', 400);
}
$x = (int) $x_raw;
$y = (int) $y_raw;

$pdo = null;
try {
    $pdo = db();

    // Resolve login from token (do NOT cast to int)
    $stmt = $pdo->prepare('SELECT login FROM tokens WHERE token = :t');
    $stmt->execute([':t' => $token]);
    $tok = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tok) fail('Invalid token', 401);
    $login = $tok['login'];

    // Verify player ownership
    $stmt = $pdo->prepare('SELECT login, turn_order FROM players WHERE player_id = :pid AND game_id = :gid');
    $stmt->execute([':pid' => $player_id, ':gid' => $game_id]);
    $pRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pRow) fail('Игрок не в игре', 404);
    if ($pRow['login'] !== $login) fail('Не ваш игрок', 403);

    // Advance turn automatically if needed (timeouts)
    auto_advance_turn($pdo, $game_id);

    // Determine current turn player
    $stmt = $pdo->prepare('SELECT s.id_player FROM steps s JOIN players p ON p.player_id = s.id_player WHERE p.game_id = :gid ORDER BY s.id_step DESC LIMIT 1');
    $stmt->execute([':gid' => $game_id]);
    $turnPlayer = (int) $stmt->fetchColumn();
    if ($turnPlayer !== $player_id) fail('Не ваш ход', 409);

    // Verify tile belongs to player
    $stmt = $pdo->prepare('SELECT 1 FROM players_tiles WHERE id_player = :pid AND id_tile = :tid');
    $stmt->execute([':pid' => $player_id, ':tid' => $tile_id]);
    if (!$stmt->fetch()) fail('Фишка не принадлежит игроку', 404);

    // Ensure cell free
    $stmt = $pdo->prepare('SELECT 1 FROM cells WHERE id_game = :gid AND cords_x = :x AND cords_y = :y');
    $stmt->execute([':gid' => $game_id, ':x' => $x, ':y' => $y]);
    if ($stmt->fetch()) fail('Клетка занята', 409);

    // Decode new tile
    $newTile = tile_from_id($tile_id);
    $newShape = $newTile['shape'];
    $newColor = $newTile['color'];

    // Load board state
    $board = get_board_cells($pdo, $game_id); // key: "x:y"
    $boardEmpty = (count($board) === 0);

    $keyAt = function($xx, $yy) { return $xx . ':' . $yy; };

    // Collect contiguous line (excluding origin)
    $collectLine = function($bx, $by, $dx, $dy) use ($board, $keyAt) {
        $res = [];
        $x1 = $bx + $dx; $y1 = $by + $dy;
        while (isset($board[$keyAt($x1,$y1)])) {
            $res[] = $board[$keyAt($x1,$y1)];
            $x1 += $dx; $y1 += $dy;
        }
        return $res;
    };

    if (!$boardEmpty) {
        $left = $collectLine($x, $y, -1, 0);
        $right = $collectLine($x, $y, 1, 0);
        $up = $collectLine($x, $y, 0, -1);
        $down = $collectLine($x, $y, 0, 1);

        $horTiles = array_merge(array_reverse($left), $right);
        $verTiles = array_merge(array_reverse($up), $down);

        $hasNeighbor = (count($horTiles) > 0) || (count($verTiles) > 0);
        if (!$hasNeighbor) fail('Фишка должна быть прилегающей к существующим фишкам', 409);

        // Validate line consistency
        $validateLine = function(array $tiles, string $newShape, string $newColor) {
            if (count($tiles) === 0) return [true, ''];
            $shapes = [];
            $colors = [];
            foreach ($tiles as $t) { $shapes[$t['shape']] = true; $colors[$t['color']] = true; }
            $shapeCount = count($shapes);
            $colorCount = count($colors);

            // Single neighbor: must match by exactly one property (color OR shape) and differ by the other
            if (count($tiles) === 1) {
                $t = $tiles[0];
                if ($newColor === $t['color'] && $newShape !== $t['shape']) return [true, ''];
                if ($newShape === $t['shape'] && $newColor !== $t['color']) return [true, ''];
                if ($newColor === $t['color'] && $newShape === $t['shape']) return [false, 'Такая фиша уже есть в линии'];
                return [false, 'Фишка должна совпадать с соседней по цвету или форме и отличаться по другим параметрам'];
            }

            // Shape-line: all same shape, colors unique
            if ($shapeCount === 1) {
                $requiredShape = array_keys($shapes)[0];
                if ($newShape !== $requiredShape) return [false, 'Новая форма фишки не соответствует форме линии'];
                if (isset($colors[$newColor])) return [false, 'Дублирование цвета в линии с одинаковой формой'];
                if (count($tiles) + 1 > 6) return [false, 'Линия не может содержать более 6 фишек'];
                return [true, ''];
            }

            // Color-line: all same color, shapes unique
            if ($colorCount === 1) {
                $requiredColor = array_keys($colors)[0];
                if ($newColor !== $requiredColor) return [false, 'Новый цвет фишки не соответствует цветовой линии'];
                if (isset($shapes[$newShape])) return [false, 'Дублирование формы в цветовой линии'];
                if (count($tiles) + 1 > 6) return [false, 'Линия не может содержать более 6 фишек'];
                return [true, ''];
            }

            return [false, 'Existing line inconsistent'];
        };

        $hValid = $validateLine($horTiles, $newShape, $newColor);
        if (!$hValid[0]) fail('Horizontal line invalid: ' . $hValid[1], 409);
        $vValid = $validateLine($verTiles, $newShape, $newColor);
        if (!$vValid[0]) fail('Vertical line invalid: ' . $vValid[1], 409);

        // Prevent identical tile in combined lines
        foreach (array_merge($horTiles, $verTiles) as $t) {
            if ($t['shape'] === $newShape && $t['color'] === $newColor) {
                fail('Такая фишка уже есть в связанной линии', 409);
            }
        }

        // Max length
        if (count($horTiles) + 1 > 6) fail('Горизонтальная линия не может содержать более 6 фишек', 409);
        if (count($verTiles) + 1 > 6) fail('Вертикальная линия не может содержать более 6 фишек', 409);
    }

    // Determine current step id (for multi-tile turn rules)
    $stepId = null;
    try {
        $sstmt = $pdo->prepare('SELECT s.id_step FROM steps s JOIN players p ON p.player_id = s.id_player WHERE p.game_id = :gid ORDER BY s.id_step DESC LIMIT 1');
        $sstmt->execute([':gid' => $game_id]);
        $stepId = (int) $sstmt->fetchColumn();
    } catch (Throwable $ignore) {
        $stepId = null;
    }

    if ($stepId) {
        $pstmt = $pdo->prepare('SELECT cords_x, cords_y FROM placed_tiles WHERE id_game = :gid AND id_step = :sid');
        $pstmt->execute([':gid' => $game_id, ':sid' => $stepId]);
        $placed = $pstmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($placed) > 0) {
            $sameRow = true; $sameCol = true;
            foreach ($placed as $pt) {
                if ((int)$pt['cords_y'] !== $y) $sameRow = false;
                if ((int)$pt['cords_x'] !== $x) $sameCol = false;
            }
            if (!($sameRow || $sameCol)) {
                fail('Несколько фишек в одном ходе должны находиться в одной строке или столбце', 409);
            }
            // Contiguity check
            $coords = array_map(function($p){ return [(int)$p['cords_x'], (int)$p['cords_y']]; }, $placed);
            $coords[] = [$x,$y];
            $vals = array_map(function($c) use ($sameRow) { return $sameRow ? $c[0] : $c[1]; }, $coords);
            sort($vals);
            for ($i = 1; $i < count($vals); $i++) {
                if ($vals[$i] !== $vals[$i-1] + 1) {
                    fail('Фишки в ходе должны быть смежными', 409);
                }
            }
        }
    }

    // Transaction: place tile & update state
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('INSERT INTO cells (id_game, cords_x, cords_y, id_tile) VALUES (:gid, :x, :y, :tid)');
    $stmt->execute([':gid' => $game_id, ':x' => $x, ':y' => $y, ':tid' => $tile_id]);

    $stmt = $pdo->prepare('DELETE FROM players_tiles WHERE id_player = :pid AND id_tile = :tid');
    $stmt->execute([':pid' => $player_id, ':tid' => $tile_id]);

    // Ensure placed_tiles table exists
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS placed_tiles (
            id SERIAL PRIMARY KEY,
            id_game INTEGER NOT NULL,
            id_step INTEGER NOT NULL,
            cords_x INTEGER NOT NULL,
            cords_y INTEGER NOT NULL,
            id_tile INTEGER NOT NULL
        )');
    } catch (Throwable $ignore) {}

    if ($stepId) {
        try {
            $insPlaced = $pdo->prepare('INSERT INTO placed_tiles (id_game, id_step, cords_x, cords_y, id_tile) VALUES (:gid, :sid, :x, :y, :tid)');
            $insPlaced->execute([':gid' => $game_id, ':sid' => $stepId, ':x' => $x, ':y' => $y, ':tid' => $tile_id]);
        } catch (Throwable $ignore) {}
    }

    // Scoring for this single placement
    $board[$keyAt($x,$y)] = ['id_tile'=>$tile_id,'shape'=>$newShape,'color'=>$newColor,'x'=>$x,'y'=>$y];

    $collectFullLine = function($bx,$by,$dx,$dy) use ($board,$keyAt) {
        $res = [];
        // negative direction
        $x1 = $bx - $dx; $y1 = $by - $dy;
        while (isset($board[$keyAt($x1,$y1)])) { array_unshift($res, $board[$keyAt($x1,$y1)]); $x1 -= $dx; $y1 -= $dy; }
        // self
        $res[] = $board[$keyAt($bx,$by)];
        // positive direction
        $x2 = $bx + $dx; $y2 = $by + $dy;
        while (isset($board[$keyAt($x2,$y2)])) { $res[] = $board[$keyAt($x2,$y2)]; $x2 += $dx; $y2 += $dy; }
        return $res;
    };

    $hLine = $collectFullLine($x,$y,1,0);
    $vLine = $collectFullLine($x,$y,0,1);

    $points = 0; $qwirkles = 0;
    if (count($hLine) > 1) {
        $points += count($hLine);
        if (count($hLine) === 6) { $points += 6; $qwirkles++; }
    }
    if (count($vLine) > 1) {
        $points += count($vLine);
        if (count($vLine) === 6) { $points += 6; $qwirkles++; }
    }

    $pdo->commit();

    respond([
        'success' => true,
        'points' => $points,
        'qwirkles_completed' => $qwirkles
    ]);

} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    fail('place_tile failed: ' . $e->getMessage(), 500);
}
?>