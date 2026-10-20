<?php

declare(strict_types=1);

/** Child of p6_race.php: a competing transaction whose FIRST statement is the package's locking read. */

require __DIR__ . '/lib.php';

use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;

$pdo = p47_pdo();
$pdo->beginTransaction();
if (($argv[1] ?? '') === 'plain-first') {
    $pdo->query('SELECT COUNT(*) FROM ' . P47_T)->fetchAll();   // a snapshot before the locking read
}
fwrite(STDOUT, "child-started\n");
fflush(STDOUT);
echo p47_try(static fn () => (new ScopedOrderingManager())->getNextPositionForUpdate($pdo, p47_config(), 1)) . "\n";
if ($pdo->inTransaction()) {
    $pdo->rollBack();
}
