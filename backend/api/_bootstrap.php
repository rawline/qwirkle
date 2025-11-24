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

        // Lock the game row to avoid races
        $stmt = $pdo->prepare('SELECT move_time FROM games WHERE game_id = :g FOR UPDATE');
        $stmt->execute([':g' => $gameId]);
        $row = $stmt->fetch();
        if (!$row) {
            $pdo->commit();
            return;
        }
        $moveTime = (int) ($row['move_time'] ?? 60);
        if ($moveTime <= 0)
            $moveTime = 60;

        // Lock players order
        $stmt = $pdo->prepare('SELECT player_id FROM players WHERE game_id = :g ORDER BY turn_order FOR UPDATE');
        $stmt->execute([':g' => $gameId]);
        $players = array_map('intval', array_column($stmt->fetchAll(), 'player_id'));
        $count = count($players);
        if ($count === 0) {
            $pdo->commit();
            return;
        }

        // Latest step for this game
        $stmt = $pdo->prepare('SELECT s.id_player AS player_id, s.step_begin FROM steps s
                               JOIN players p ON p.player_id = s.id_player
                               WHERE p.game_id = :g
                               ORDER BY s.id_step DESC
                               LIMIT 1 FOR UPDATE');
        $stmt->execute([':g' => $gameId]);
        $last = $stmt->fetch();
        if (!$last) {
            $pdo->commit();
            return;
        }

        $lastPid = (int) $last['player_id'];
        $lastTs = strtotime($last['step_begin'] ?? '');
        if (!$lastTs) {
            $pdo->commit();
            return;
        }

        $elapsed = time() - $lastTs;
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

        // Insert next steps
        $ins = $pdo->prepare('INSERT INTO steps (id_player, step_begin) VALUES (:pid, NOW())');
        for ($i = 0; $i < $stepsToAdd; $i++) {
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
    return ['square', 'circle', 'triangle'];
}
function tile_colors(): array
{
    return ['red', 'blue', 'green', 'yellow', 'purple', 'orange'];
}
// Encode: id = shapeIdx * 10 + colorIdx
function tile_from_id(int $id): array
{
    $shapes = tile_shapes();
    $colors = tile_colors();
    $shapeIdx = (int) floor($id / 10);
    $colorIdx = $id % 10;
    $shape = $shapes[$shapeIdx % count($shapes)];
    $color = $colors[$colorIdx % count($colors)];
    return ['id_tile' => $id, 'shape' => $shape, 'color' => $color];
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
function refill_player_tiles(PDO $pdo, int $playerId, int $target = 5): void
{
    // Current count
    $q = $pdo->prepare('SELECT COUNT(*) FROM players_tiles WHERE id_player = :pid');
    $q->execute([':pid' => $playerId]);
    $cnt = (int) $q->fetchColumn();
    if ($cnt >= $target)
        return;

    $need = $target - $cnt;
    // Unique candidates not already in rack
    $cand = $pdo->prepare('SELECT t.id FROM tiles t
                            WHERE NOT EXISTS (
                                SELECT 1 FROM players_tiles pt WHERE pt.id_player = :pid AND pt.id_tile = t.id
                            )
                            ORDER BY random() LIMIT 50');
    $cand->execute([':pid' => $playerId]);
    $ids = array_map('intval', $cand->fetchAll(PDO::FETCH_COLUMN));
    $ins = $pdo->prepare('INSERT INTO players_tiles (id_player, id_tile) VALUES (:pid, :tid)');

    $added = 0;
    foreach ($ids as $tid) {
        if ($added >= $need)
            break;
        $ins->execute([':pid' => $playerId, ':tid' => $tid]);
        $added++;
    }
    if ($added < $need) {
        // Pool exhausted; allow duplicates to fill up
        $any = $pdo->query('SELECT id FROM tiles ORDER BY random() LIMIT 50')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($any as $tid) {
            if ($added >= $need)
                break;
            $ins->execute([':pid' => $playerId, ':tid' => (int) $tid]);
            $added++;
        }
    }
}