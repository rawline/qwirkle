<?php
require __DIR__ . '/_bootstrap.php';

// Accept both GET and POST; prefer JSON body for inputs
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    fail('Method not allowed', 405);
}

// перенести плейер id в тело, убрать из параметров
$token = get_token_from_request();
$body = read_json_body();
// Prefer body, fallback to query params for backward compatibility
$playerId = $body['player_id']
    ?? $body['p_player_id']
    ?? ($_GET['p_player_id'] ?? $_GET['player_id'] ?? null);
if (!$token || !$playerId) {
    fail('player_id (в теле запроса) и токен обязательны');
}

try {
    $pdo = db();
    // Advance turn if timeout elapsed for the player's game
    $q = $pdo->prepare('SELECT game_id FROM players WHERE player_id = :pid');
    $q->execute([':pid' => (int) $playerId]);
    $gid = (int) $q->fetchColumn();
    if ($gid) {
        auto_advance_turn($pdo, $gid);
    }
    $stmt = $pdo->prepare('SELECT get_game_state(:p_token, :p_player_id) AS res');
    $stmt->execute([':p_token' => (int) $token, ':p_player_id' => (int) $playerId]);
    $row = $stmt->fetch();
    if (!$row)
        fail('Empty response', 500);

    $resJson = $row['res'] ?? null;
    if (!$resJson)
        fail('Invalid result', 500);

    $data = json_decode($resJson, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        // If DB returns already JSON string, pass through
        respond($resJson);
    }
    // Enrich with remaining_time if absent/null (client expects countdown)
    try {
        // Normalize to array of games for processing
        $gamesArr = [];
        if (isset($data[0]) && is_array($data[0])) {
            $gamesArr = $data; // already array of game objects
        } elseif (isset($data['games']) && is_array($data['games'])) {
            $gamesArr = $data['games'];
        } else {
            $gamesArr = [$data];
        }
        $pdo2 = $pdo; // reuse connection
        $gStmt = $pdo2->prepare('SELECT move_time FROM games WHERE game_id = :g');
        // Compute elapsed seconds on DB side to avoid timezone drift between PHP and DB
        $sStmt = $pdo2->prepare('SELECT EXTRACT(EPOCH FROM (NOW() - s.step_begin))::int AS elapsed
                     FROM steps s JOIN players p ON p.player_id = s.id_player
                     WHERE p.game_id = :g
                     ORDER BY s.id_step DESC LIMIT 1');
        foreach ($gamesArr as &$gobj) {
            if (!is_array($gobj))
                continue;
            $gid2 = isset($gobj['game_id']) ? (int) $gobj['game_id'] : 0;
            if ($gid2 <= 0)
                continue;
            $hasField = array_key_exists('remaining_time', $gobj) || array_key_exists('time_left', $gobj);
            if ($hasField && $gobj['remaining_time'] !== null)
                continue; // already provided
            $gStmt->execute([':g' => $gid2]);
            $moveTime = (int) ($gStmt->fetchColumn() ?: 60);
            $sStmt->execute([':g' => $gid2]);
            $elapsedSec = $sStmt->fetchColumn();
            $remaining = null;
            if ($elapsedSec !== false && $elapsedSec !== null) {
                $remaining = $moveTime - (int)$elapsedSec;
                if ($remaining < 0) $remaining = 0;
            }
            // attach finished status and winner
            try {
                $winnerId = get_game_finished($pdo2, $gid2);
                if ($winnerId !== null) {
                    $gobj['game_finished'] = true;
                    $gobj['winner_player_id'] = $winnerId;
                    $remaining = 0; // no time remaining in finished game
                } else {
                    $gobj['game_finished'] = false;
                }
            } catch (Throwable $ignore) {}
            $gobj['remaining_time'] = $remaining; // may be null if cannot compute
        }
        // Write back into original structure
        if (isset($data[0]) && is_array($data[0])) {
            $data = $gamesArr;
        } elseif (isset($data['games']) && is_array($data['games'])) {
            $data['games'] = $gamesArr;
        } else {
            $data = $gamesArr[0];
        }
    } catch (Throwable $enrichErr) {
        // Silently ignore enrichment errors
    }

    // Enrich with my_tiles for requesting player and cells (board) using tiles table
    try {
        // We already looked up $gid earlier; if present, use it
        if (!empty($gid)) {
            // Recompute current turn from latest step to ensure freshness
            try {
                $turnStmt = $pdo->prepare('SELECT s.id_player FROM steps s JOIN players p ON p.player_id = s.id_player WHERE p.game_id = :gid ORDER BY s.id_step DESC LIMIT 1');
                $turnStmt->execute([':gid' => (int) $gid]);
                $latestTurn = (int) $turnStmt->fetchColumn();
                if ($latestTurn) {
                    if (isset($data[0]) && is_array($data[0])) {
                        foreach ($data as &$gobjCT) {
                            if (isset($gobjCT['game_id']) && (int) $gobjCT['game_id'] === (int) $gid) {
                                $gobjCT['current_turn'] = $latestTurn;
                                break;
                            }
                        }
                        unset($gobjCT);
                    } else {
                        if (is_array($data)) {
                            $data['current_turn'] = $latestTurn;
                        }
                    }
                }
            } catch (Throwable $ignoreTurn) {
                // ignore failures
            }
            // player tiles with color/shape from tiles table
            $tilesStmt = $pdo->prepare('SELECT t.id AS id_tile, t.color, t.shape
                                        FROM players_tiles pt
                                        JOIN tiles t ON t.id = pt.id_tile
                                        WHERE pt.id_player = :pid
                                        ORDER BY t.id');
            $tilesStmt->execute([':pid' => (int) $playerId]);
            $myTiles = $tilesStmt->fetchAll();

            // board cells with color/shape
            $cellsStmt = $pdo->prepare('SELECT c.cords_x, c.cords_y, c.id_tile, t.color, t.shape
                                        FROM cells c
                                        JOIN tiles t ON t.id = c.id_tile
                                        WHERE c.id_game = :gid');
            $cellsStmt->execute([':gid' => (int) $gid]);
            $cells = $cellsStmt->fetchAll();

            // Attach into data structure
            if (isset($data[0]) && is_array($data[0])) {
                foreach ($data as &$gobj) {
                    if (isset($gobj['game_id']) && (int) $gobj['game_id'] === (int) $gid) {
                        $gobj['my_tiles'] = $myTiles;
                        // Provide both keys some clients expect
                        $gobj['cells'] = $cells;
                        $gobj['board_tiles'] = $cells;
                        break;
                    }
                }
                unset($gobj);
            } else {
                // Single object
                if (is_array($data)) {
                    $data['my_tiles'] = $myTiles;
                    $data['cells'] = $cells;
                    $data['board_tiles'] = $cells;
                }
            }
        }
    } catch (Throwable $e2) {
        // ignore enrichment errors
    }
    // If the requested player's game is finished, short-circuit with 410 Gone for subsequent polling clients
    try {
        $gidCheck = (int)$gid;
        if ($gidCheck) {
            $winnerId = get_game_finished($pdo, $gidCheck);
            if ($winnerId !== null) {
                respond([
                    'error' => 'Игра завершена',
                    'game_finished' => true,
                    'winner_player_id' => $winnerId,
                ], 410);
            }
        }
    } catch (Throwable $ignore) {}

    respond($data);
} catch (Throwable $e) {
    fail('get_game_state failed: ' . $e->getMessage(), 500);
}
