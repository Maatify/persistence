<?php

declare(strict_types=1);

/** RC-01: moveWithinScope() against a UNIQUE (scope, order) index, the safety net the guides recommend. */

require __DIR__ . '/_probe.php';

use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;

$pdo = probe_pdo();
$pdo->exec('DROP TABLE IF EXISTS probe_uq');
$pdo->exec('CREATE TABLE probe_uq (id INT AUTO_INCREMENT PRIMARY KEY, scope_id INT NOT NULL, display_order INT NOT NULL, UNIQUE KEY uq (scope_id, display_order)) ENGINE=InnoDB');
$pdo->exec('INSERT INTO probe_uq (scope_id, display_order) VALUES (1,1),(1,2),(1,3)');

$manager = new ScopedOrderingManager();
$config = new ScopedOrderingConfig('probe_uq', 'scope_id', deletedAtColumn: null);

probe_try('move id 3 -> 1 (shift up)', fn () => $manager->moveWithinScope($pdo, $config, 1, 3, 1));
probe_try('move id 1 -> 3 (shift down)', fn () => $manager->moveWithinScope($pdo, $config, 1, 1, 3));
probe_try('rows afterwards', fn () => $pdo->query('SELECT id, display_order FROM probe_uq ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));

$pdo->exec('DROP TABLE probe_uq');
