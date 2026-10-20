<?php

declare(strict_types=1);

/** RC-02: a failed moveWithinScope() inside a caller-owned transaction leaves the range shift applied. */

require __DIR__ . '/_probe.php';

use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;

$pdo = probe_pdo();
$pdo->exec('DROP TABLE IF EXISTS probe_ord2');
$pdo->exec('CREATE TABLE probe_ord2 (id INT AUTO_INCREMENT PRIMARY KEY, display_order INT NOT NULL) ENGINE=InnoDB');
$pdo->exec('INSERT INTO probe_ord2 (display_order) VALUES (1),(2),(3)');
$pdo->exec('DROP TRIGGER IF EXISTS probe_fail');
$pdo->exec("CREATE TRIGGER probe_fail BEFORE UPDATE ON probe_ord2 FOR EACH ROW BEGIN IF NEW.id = 3 AND NEW.display_order = 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'boom'; END IF; END");

$manager = new ScopedOrderingManager();
$config = new ScopedOrderingConfig('probe_ord2', null, deletedAtColumn: null);
$rows = static fn (): array => $pdo->query('SELECT id, display_order FROM probe_ord2 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

$pdo->beginTransaction();
probe_try('moveWithinScope (id 3 -> 1) in caller-owned transaction', fn () => $manager->moveWithinScope($pdo, $config, null, 3, 1));
probe_try('caller still in transaction', fn () => $pdo->inTransaction());
probe_try('rows after the failed move (caller catches and continues)', $rows);
$pdo->commit();
probe_try('rows after the caller COMMIT', $rows);

$pdo->exec('DROP TRIGGER probe_fail');
$pdo->exec('DROP TABLE probe_ord2');
