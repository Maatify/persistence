<?php

declare(strict_types=1);

/**
 * P1 - RED reproduction on the unmodified package: the two shipped locking-read APIs inside a caller-owned
 * transaction whose snapshot predates another connection's commit (the Issue #33 scenario).
 */

require __DIR__ . '/lib.php';

use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;

$pdo = p47_pdo();
$other = p47_pdo();
echo "== P1 " . p47_engine($pdo) . "\n";

$manager = new ScopedOrderingManager();
$cases = [
    'getNextPositionForUpdate' => static fn (PDO $c): mixed => $manager->getNextPositionForUpdate($c, p47_config(), 1),
    'moveWithinScope(1->5)' => static fn (PDO $c): mixed => $manager->moveWithinScope($c, p47_config(), 1, 1, 5),
];

foreach ($cases as $name => $call) {
    if (str_starts_with($name, 'getNextPositionForUpdate') && !method_exists($manager, 'getNextPositionForUpdate')) {
        printf("  %-26s not present in this source tree\n", $name);

        continue;
    }

    p47_seed($pdo);
    $pdo->beginTransaction();
    $pdo->query('SELECT COUNT(*) FROM ' . P47_T)->fetchAll();                       // snapshot established
    $other->exec('INSERT INTO ' . P47_T . ' (scope_id, display_order) VALUES (1,4),(1,5)'); // autocommit
    $result = p47_try(static fn () => $call($pdo));
    $active = $pdo->inTransaction() ? 'txn-active' : 'txn-gone';
    $pdo->rollBack();
    printf("  %-26s %-28s %s\n", $name, $result, $active);
}

// Control: package OWNS the transaction (no caller snapshot) - outermost moveWithinScope on a concurrently changed scope.
p47_seed($pdo);
$other->exec('INSERT INTO ' . P47_T . ' (scope_id, display_order) VALUES (1,4),(1,5)');
printf("  %-26s %-28s (owned txn, no prior snapshot)\n", 'moveWithinScope(1->5)', p47_try(static fn () => $manager->moveWithinScope($pdo, p47_config(), 1, 1, 5)));
$pdo->exec('DROP TABLE IF EXISTS ' . P47_T);
