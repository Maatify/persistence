<?php

declare(strict_types=1);

/**
 * P6 - Ordinary concurrency (no pre-existing stale snapshot). Transaction A holds the scope's tail lock; child B
 * queues behind it in getNextPositionForUpdate(); A then bumps the locked tail row (UPDATE) and commits; the new tail is A's row. Reports what B returns.
 * Outcome (all engines): 1213, the documented gap-lock deadlock of getNextPositionForUpdate(); never 1020, because B holds no older snapshot-created conflict. Control only.
 */

require __DIR__ . '/lib.php';

use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;

$a = p47_pdo();
echo "== P6 " . p47_engine($a) . "\n";
$manager = new ScopedOrderingManager();

foreach (['fresh', 'plain-first'] as $variant) {
    p47_seed($a);
    $a->beginTransaction();
    $tail = $manager->getNextPositionForUpdate($a, p47_config(), 1);       // A locks the tail, expects 4
    $proc = proc_open([PHP_BINARY, __DIR__ . '/p6_race_child.php', $variant], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, getenv());
    fgets($pipes[1]);                                                      // child-started
    usleep(700_000);                                                       // let B block on the tail row
    $a->exec('UPDATE ' . P47_T . ' SET display_order = display_order + 1 WHERE scope_id = 1 AND display_order = ' . ($tail - 1)); // A bumps the locked tail row
    $a->commit();
    $out = trim((string) stream_get_contents($pipes[1]));
    $err = trim((string) stream_get_contents($pipes[2]));
    proc_close($proc);
    printf("  B=%-12s A bumped tail %d->%d and committed; B returned: %s%s\n", $variant, $tail - 1, $tail, $out, $err !== '' ? ' [stderr: ' . substr($err, 0, 80) . ']' : '');
}
$a->exec('DROP TABLE IF EXISTS ' . P47_T);
