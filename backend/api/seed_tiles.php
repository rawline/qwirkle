<?php
require __DIR__ . '/_bootstrap.php';

// Seed the tiles table with base shapes and colors if it's empty or partially filled.
// GET or POST: optional; idempotent. Returns inserted count and total count.

try {
    $pdo = db();
    $shapes = tile_shapes();
    $colors = tile_colors();

    // Build desired set (color, shape)
    $desired = [];
    foreach ($shapes as $s) {
        foreach ($colors as $c) {
            $desired[] = [$c, $s];
        }
    }

    // Existing tuples
    $stmt = $pdo->query('SELECT color, shape FROM tiles');
    $existing = [];
    foreach ($stmt->fetchAll() as $row) {
        $existing[strtolower(trim($row['color'])) . '|' . strtolower(trim($row['shape']))] = true;
    }

    $ins = $pdo->prepare('INSERT INTO tiles (color, shape) VALUES (:color, :shape)');
    $inserted = 0;
    foreach ($desired as [$c, $s]) {
        $key = strtolower($c) . '|' . strtolower($s);
        if (!isset($existing[$key])) {
            $ins->execute([':color' => $c, ':shape' => $s]);
            $inserted++;
        }
    }

    $total = (int) $pdo->query('SELECT COUNT(*) FROM tiles')->fetchColumn();
    respond(['success' => true, 'inserted' => $inserted, 'total' => $total]);
} catch (Throwable $e) {
    fail('seed_tiles failed: ' . $e->getMessage(), 500);
}
