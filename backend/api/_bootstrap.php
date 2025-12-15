<?php

// CORS
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function respond($data, int $code = 200): void
{
    http_response_code($code);
    if (is_string($data)) {
        echo $data;
    } else {
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

function fail(string $message, int $code = 400): void
{
    respond(['error' => $message], $code);
}

// Game configuration
define('MAX_TILES_IN_HAND', 6); // Reduced from 6 for faster gameplay

// Bonus for the player who ends the game (empties hand when pool is empty)
define('END_GAME_BONUS', 6);

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw)
        return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// .env loader (very small)
function env(string $key, $default = null)
{
    static $loaded = false;
    static $vars = [];
    if (!$loaded) {
        $envPath = __DIR__ . '/../.env';
        if (file_exists($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $trim = trim($line);
                if ($trim === '' || substr($trim, 0, 1) === '#')
                    continue;
                $parts = explode('=', $line, 2);
                $k = trim($parts[0]);
                $v = isset($parts[1]) ? trim($parts[1]) : '';
                $v = trim($v, "\"' ");
                if ($k !== '') {
                    $vars[$k] = $v;
                }
            }
        }
        $loaded = true;
    }
    return $vars[$key] ?? getenv($key) ?? $default;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo)
        return $pdo;
    $host = env('DB_HOST', 'helios.cs.ifmo.ru');
    $port = (int) env('DB_PORT', '5432');
    $db = env('DB_NAME', 'studs');
    $user = env('DB_USER', 's373445');
    $pass = env('DB_PASS', 'RnNH9qPQo10HprTE');

    $dsn = "pgsql:host=$host;port=$port;dbname=$db";
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        fail('DB connection failed: ' . $e->getMessage(), 500);
    }
}

function get_token_from_request(): ?string
{
    $hdr = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? null;
    if ($hdr && $hdr !== 'null' && $hdr !== 'undefined')
        return $hdr;
    $q = $_GET['token'] ?? $_GET['p_token'] ?? null;
    return $q ? (string) $q : null;
}

/**
 * Auto-advance turn if the latest step is older than move_time.
 * Inserts as many steps as needed to catch up (cap to 100 to avoid runaway).
 */
function auto_advance_turn(PDO $pdo, int $gameId): void
{
    try {
        $pdo->beginTransaction();

        // Lock the game row to avoid races (fetch seats for readiness check)
        $stmt = $pdo->prepare('SELECT move_time, seats FROM games WHERE game_id = :g FOR UPDATE');
        $stmt->execute([':g' => $gameId]);
        $row = $stmt->fetch();
        if (!$row) {
            $pdo->commit();
            return;
        }
        $moveTime = (int) ($row['move_time'] ?? 60);
        $seatsRequired = (int) ($row['seats'] ?? 0);
        if ($moveTime <= 0)
            $moveTime = 60;

        // Lock players order
        $stmt = $pdo->prepare('SELECT player_id FROM players WHERE game_id = :g ORDER BY turn_order FOR UPDATE');
        $stmt->execute([':g' => $gameId]);
        $players = array_map('intval', array_column($stmt->fetchAll(), 'player_id'));
        $count = count($players);
        // If no players OR not all seats filled yet, do not advance turns
        if ($count === 0 || ($seatsRequired > 0 && $count < $seatsRequired)) {
            $pdo->commit();
            return;
        }

        // Latest step for this game
            // Latest step for this game (lock row) and compute elapsed on DB side to avoid TZ drift
            $stmt = $pdo->prepare('SELECT s.id_player AS player_id,
                                          EXTRACT(EPOCH FROM (NOW() - s.step_begin))::int AS elapsed
                                   FROM steps s
                                   JOIN players p ON p.player_id = s.id_player
                                   WHERE p.game_id = :g
                                   ORDER BY s.id_step DESC
                                   LIMIT 1 FOR UPDATE');
            $stmt->execute([':g' => $gameId]);
            $last = $stmt->fetch();
            if (!$last) {
                // If no current step yet and game is full, initialize to first player
                $firstStmt = $pdo->prepare('SELECT player_id FROM players WHERE game_id = :g ORDER BY turn_order ASC LIMIT 1');
                $firstStmt->execute([':g' => $gameId]);
                $firstPid = (int) ($firstStmt->fetchColumn() ?: 0);
                if ($firstPid > 0) {
                    $ins0 = $pdo->prepare('INSERT INTO steps (id_player, step_begin) VALUES (:pid, NOW())');
                    $ins0->execute([':pid' => $firstPid]);
                }
                $pdo->commit();
                return;
            }

            $lastPid = (int) $last['player_id'];
            $elapsed = (int) ($last['elapsed'] ?? 0);
            if ($elapsed < $moveTime) {
                $pdo->commit();
                return;
            }

        // How many turns to advance
        $stepsToAdd = (int) floor($elapsed / $moveTime);
        if ($stepsToAdd > 100)
            $stepsToAdd = 100; // safety cap

        // Find current index
        $idx = array_search($lastPid, $players, true);
        if ($idx === false)
            $idx = 0;

        // Determine latest step row id for this game
        $latestStmt = $pdo->prepare('SELECT s.id_step FROM steps s JOIN players p ON p.player_id = s.id_player WHERE p.game_id = :g ORDER BY s.id_step DESC LIMIT 1');
        $latestStmt->execute([':g' => $gameId]);
        $latestId = (int) ($latestStmt->fetchColumn() ?: 0);

        // If no step exists yet (and game is full), initialize one to first player
        if ($latestId === 0) {
            $firstStmt = $pdo->prepare('SELECT player_id FROM players WHERE game_id = :g ORDER BY turn_order ASC LIMIT 1');
            $firstStmt->execute([':g' => $gameId]);
            $firstPid = (int) ($firstStmt->fetchColumn() ?: 0);
            if ($firstPid > 0) {
                $init = $pdo->prepare('INSERT INTO steps (id_player, step_begin) VALUES (:pid, NOW())');
                $init->execute([':pid' => $firstPid]);
                // refresh latest id
                $latestStmt->execute([':g' => $gameId]);
                $latestId = (int) ($latestStmt->fetchColumn() ?: 0);
                $lastPid = $firstPid; // set basis for advancement
                $elapsed = $moveTime; // force one advancement loop if needed
            }
        }

        // For each skipped player (timed out), refill their rack and advance current step by UPDATE
            // Insert next steps. For each skipped player (timed out), refill their rack up to 6, then insert a new step for the next player.
            $ins = $pdo->prepare('INSERT INTO steps (id_player, step_begin) VALUES (:pid, NOW())');
            for ($i = 0; $i < $stepsToAdd; $i++) {
                $timedOutPlayerId = $players[$idx];
                try {
                    refill_player_tiles($pdo, (int)$timedOutPlayerId, MAX_TILES_IN_HAND);
                } catch (Throwable $ignore) {}
                $idx = ($idx + 1) % $count;
                $ins->execute([':pid' => $players[$idx]]);
            }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        // Do not fail the request due to timer; just skip advancing
    }
}
// ---- Tile helpers (no DB table required) ----
function tile_shapes(): array
{
    // New shape set: circle, square, star, diamond, x, plus
    return ['circle', 'square', 'star', 'diamond', 'x', 'plus'];
}
function tile_colors(): array
{
    // Order: red, orange, yellow, green, purple, blue
    return ['red', 'orange', 'yellow', 'green', 'purple', 'blue'];
}
// Encode: id = shapeIdx * 10 + colorIdx
function tile_from_id(int $id): array
{
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, color, shape FROM tiles WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception("Tile with id=$id not found in DB");
    }

    return [
        'id' => (int)$row['id'],
        'shape' => strtolower($row['shape']),
        'color' => strtolower($row['color']),
    ];
}
function generate_initial_tiles(int $n = 5): array
{
    $tiles = [];
    $shapes = tile_shapes();
    $colors = tile_colors();
    $all = [];
    for ($i = 0; $i < count($shapes); $i++) {
        for ($j = 0; $j < count($colors); $j++) {
            $all[] = $i * 10 + $j;
        }
    }
    shuffle($all);
    $n = max(1, min($n, count($all)));
    return array_slice($all, 0, $n);
}

/**
 * Ensure player's rack has at least $target tiles by dealing random unique tile ids from tiles table.
 * If unique pool is smaller than needed, may allow duplicates to reach target.
 */
function refill_player_tiles(PDO $pdo, int $playerId, int $target = 6): void
{
    // Current count
    $q = $pdo->prepare('SELECT COUNT(*) FROM players_tiles WHERE id_player = :pid');
    $q->execute([':pid' => $playerId]);
    $cnt = (int) $q->fetchColumn();
    if ($cnt >= $target)
        return;

    $need = $target - $cnt;
    // Determine player's game to avoid assigning tiles already given to other players in same game
    $gstmt = $pdo->prepare('SELECT game_id FROM players WHERE player_id = :pid');
    $gstmt->execute([':pid' => $playerId]);
    $gid = (int) $gstmt->fetchColumn();

    // We'll perform a transactional selection with row locking to avoid races between concurrent deals.
    $shouldCommit = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $shouldCommit = true;
    }

    try {
        if ($gid <= 0) {
            // Select tiles not already in this player's rack
            $selSql = 'SELECT t.id FROM tiles t
                        WHERE NOT EXISTS (
                            SELECT 1 FROM players_tiles pt WHERE pt.id_player = :pid AND pt.id_tile = t.id
                        )
                        ORDER BY random() FOR UPDATE SKIP LOCKED LIMIT :lim';
            $cand = $pdo->prepare($selSql);
            $cand->bindValue(':pid', $playerId, PDO::PARAM_INT);
            $cand->bindValue(':lim', $need, PDO::PARAM_INT);
            $cand->execute();
            $ids = array_map('intval', $cand->fetchAll(PDO::FETCH_COLUMN));
        } else {
            // Select tiles not assigned to any player in same game and not already placed on the game's board
            $selSql = 'SELECT t.id FROM tiles t
                        WHERE NOT EXISTS (
                            SELECT 1 FROM players_tiles pt JOIN players p ON p.player_id = pt.id_player
                            WHERE pt.id_tile = t.id AND p.game_id = :gid
                        )
                        AND NOT EXISTS (
                            SELECT 1 FROM cells c WHERE c.id_tile = t.id AND c.id_game = :gid
                        )
                        ORDER BY random() FOR UPDATE SKIP LOCKED LIMIT :lim';
            $cand = $pdo->prepare($selSql);
            $cand->bindValue(':gid', $gid, PDO::PARAM_INT);
            $cand->bindValue(':lim', $need, PDO::PARAM_INT);
            $cand->execute();
            $ids = array_map('intval', $cand->fetchAll(PDO::FETCH_COLUMN));
        }

        $ins = $pdo->prepare('INSERT INTO players_tiles (id_player, id_tile) VALUES (:pid, :tid)');
        $added = 0;
        foreach ($ids as $tid) {
            if ($added >= $need) break;
            $ins->execute([':pid' => $playerId, ':tid' => $tid]);
            $added++;
        }

        if ($shouldCommit) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($shouldCommit && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// ---- Board / Scoring helpers ----
// Return board cells as associative map keyed by "x:y" => [x,y,id_tile,color,shape]
function get_board_cells(PDO $pdo, int $gameId): array
{
    $stmt = $pdo->prepare('SELECT c.cords_x, c.cords_y, c.id_tile, t.color, t.shape
                            FROM cells c
                            JOIN tiles t ON t.id = c.id_tile
                            WHERE c.id_game = :gid');
    $stmt->execute([':gid' => $gameId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) {
        $key = $r['cords_x'] . ':' . $r['cords_y'];
        $map[$key] = [
            'x' => (int)$r['cords_x'],
            'y' => (int)$r['cords_y'],
            'id_tile' => (int)$r['id_tile'],
            'color' => strtolower($r['color']),
            'shape' => strtolower($r['shape'])
        ];
    }
    return $map;
}

// Compute score for all tiles placed in a step (multi-tile turn)
// Returns ['score' => int, 'qwirkles' => int]
function compute_score_for_step(PDO $pdo, int $gameId, int $stepId): array
{
    $pstmt = $pdo->prepare('SELECT cords_x, cords_y, id_tile FROM placed_tiles WHERE id_game = :gid AND id_step = :sid');
    $pstmt->execute([':gid' => $gameId, ':sid' => $stepId]);
    $placed = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$placed) return ['score' => 0, 'qwirkles' => 0];

    $board = get_board_cells($pdo, $gameId);

    $exists = function($x,$y) use ($board) { return isset($board[$x . ':' . $y]); };

    $lines = [];
    foreach ($placed as $pl) {
        $x = (int)$pl['cords_x'];
        $y = (int)$pl['cords_y'];
        // Horizontal
        $minX = $x; $maxX = $x;
        for ($xx=$x-1; $xx>=-1000; $xx--) { if (!$exists($xx,$y)) break; $minX = $xx; }
        for ($xx=$x+1; $xx<=1000; $xx++) { if (!$exists($xx,$y)) break; $maxX = $xx; }
        $coordsH = []; for ($xx=$minX; $xx<=$maxX; $xx++) $coordsH[] = $xx . ':' . $y;
        $lines['h:' . $y . ':' . $minX . ':' . $maxX] = $coordsH;
        // Vertical
        $minY = $y; $maxY = $y;
        for ($yy=$y-1; $yy>=-1000; $yy--) { if (!$exists($x,$yy)) break; $minY = $yy; }
        for ($yy=$y+1; $yy<=1000; $yy++) { if (!$exists($x,$yy)) break; $maxY = $yy; }
        $coordsV = []; for ($yy=$minY; $yy<=$maxY; $yy++) $coordsV[] = $x . ':' . $yy;
        $lines['v:' . $x . ':' . $minY . ':' . $maxY] = $coordsV;
    }

    // Unique lines
    $uniqueLines = $lines;
    uasort($uniqueLines, function($a,$b){ return count($b) - count($a); });

    $score = 0; $qwirkles = 0; $counted = [];
    foreach ($uniqueLines as $coords) {
        $len = count($coords);
        if ($len <= 1) continue; // singletons do not score unless forming a second line with others
        $score += $len;
        if ($len === 6) { $score += 6; $qwirkles++; }
        foreach ($coords as $c) $counted[$c] = true;
    }

    return ['score' => $score, 'qwirkles' => $qwirkles];
}

// ---- Finished game helpers ----
function ensure_finished_games_table(PDO $pdo): void
{
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS finished_games (
            id_game INTEGER PRIMARY KEY,
            winner_player_id INTEGER NOT NULL,
            finished_at TIMESTAMP NOT NULL DEFAULT NOW()
        )');
    } catch (Throwable $e) {
        // ignore table creation errors
    }
}

function get_game_finished(PDO $pdo, int $gameId): ?int
{
    $stmt = $pdo->prepare('SELECT winner_player_id FROM finished_games WHERE id_game = :gid');
    $stmt->execute([':gid' => $gameId]);
    $w = $stmt->fetchColumn();
    return $w ? (int)$w : null;
}

function mark_game_finished(PDO $pdo, int $gameId, int $winnerPlayerId): void
{
    ensure_finished_games_table($pdo);
    // Insert only if not already finished
    $stmt = $pdo->prepare('INSERT INTO finished_games (id_game, winner_player_id, finished_at)
                           SELECT :gid, :wid, NOW()
                           WHERE NOT EXISTS (SELECT 1 FROM finished_games WHERE id_game = :gid)');
    $stmt->execute([':gid' => $gameId, ':wid' => $winnerPlayerId]);
}

function compute_winner(PDO $pdo, int $gameId): ?int
{
    // Winner: highest score; tie broken by lowest turn_order
    $stmt = $pdo->prepare('SELECT player_id FROM players WHERE game_id = :gid ORDER BY score DESC, turn_order ASC LIMIT 1');
    $stmt->execute([':gid' => $gameId]);
    $pid = $stmt->fetchColumn();
    return $pid ? (int)$pid : null;
}

// ---- Swap tracking helpers (per-step) ----
function ensure_swaps_table(PDO $pdo): void
{
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS swaps (
            id SERIAL PRIMARY KEY,
            id_game INTEGER NOT NULL,
            id_step INTEGER NOT NULL,
            id_player INTEGER NOT NULL,
            swapped_at TIMESTAMP NOT NULL DEFAULT NOW(),
            UNIQUE (id_game, id_step, id_player)
        )');
    } catch (Throwable $e) {
        // ignore
    }
}

function has_swapped_in_step(PDO $pdo, int $gameId, int $stepId, int $playerId): bool
{
    if ($stepId <= 0) return false;
    ensure_swaps_table($pdo);
    $stmt = $pdo->prepare('SELECT 1 FROM swaps WHERE id_game = :gid AND id_step = :sid AND id_player = :pid');
    $stmt->execute([':gid' => $gameId, ':sid' => $stepId, ':pid' => $playerId]);
    return (bool)$stmt->fetchColumn();
}

function mark_swapped_in_step(PDO $pdo, int $gameId, int $stepId, int $playerId): void
{
    if ($stepId <= 0) return;
    ensure_swaps_table($pdo);
    $stmt = $pdo->prepare('INSERT INTO swaps (id_game, id_step, id_player)
                           SELECT :gid, :sid, :pid
                           WHERE NOT EXISTS (
                               SELECT 1 FROM swaps WHERE id_game = :gid AND id_step = :sid AND id_player = :pid
                           )');
    $stmt->execute([':gid' => $gameId, ':sid' => $stepId, ':pid' => $playerId]);
}

function step_has_any_placement(PDO $pdo, int $gameId, int $stepId): bool
{
    if ($stepId <= 0) return false;
    // placed_tiles table is created lazily; if absent, there were no placements
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM placed_tiles WHERE id_game = :gid AND id_step = :sid LIMIT 1');
        $stmt->execute([':gid' => $gameId, ':sid' => $stepId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function game_all_hands_empty(PDO $pdo, int $gameId): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*)
                           FROM players p
                           JOIN players_tiles pt ON pt.id_player = p.player_id
                           WHERE p.game_id = :gid');
    $stmt->execute([':gid' => $gameId]);
    $cnt = (int)$stmt->fetchColumn();
    return $cnt === 0;
}