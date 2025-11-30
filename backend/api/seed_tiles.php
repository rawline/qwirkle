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

    // Count existing tiles per (color,shape)
    $countsStmt = $pdo->query("SELECT LOWER(TRIM(color)) AS color, LOWER(TRIM(shape)) AS shape, COUNT(*) AS cnt FROM tiles GROUP BY LOWER(TRIM(color)), LOWER(TRIM(shape))");
    $existingCounts = [];
    foreach ($countsStmt->fetchAll() as $row) {
        $key = strtolower($row['color']) . '|' . strtolower($row['shape']);
        $existingCounts[$key] = (int) $row['cnt'];
    }

    // Insert up to 3 copies per combination
    $ins = $pdo->prepare('INSERT INTO tiles (color, shape) VALUES (:color, :shape)');
    $inserted = 0;
    foreach ($desired as [$c, $s]) {
        $key = strtolower($c) . '|' . strtolower($s);
        $have = $existingCounts[$key] ?? 0;
        $toAdd = max(0, 3 - $have);
        for ($i = 0; $i < $toAdd; $i++) {
            $ins->execute([':color' => $c, ':shape' => $s]);
            $inserted++;
        }
    }

    $total = (int) $pdo->query('SELECT COUNT(*) FROM tiles')->fetchColumn();
    respond(['success' => true, 'inserted' => $inserted, 'total' => $total]);
} catch (Throwable $e) {
    fail('seed_tiles failed: ' . $e->getMessage(), 500);
}
