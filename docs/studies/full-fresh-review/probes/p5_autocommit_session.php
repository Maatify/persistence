<?php

declare(strict_types=1);

/** Negative probe: a session with autocommit=0 does not make the runner mistake an implicit transaction for a caller-owned one. */

require __DIR__ . '/_probe.php';

use Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetryTransactionRunner;

$setup = probe_pdo();
$setup->exec('DROP TABLE IF EXISTS probe_ac');
$setup->exec('CREATE TABLE probe_ac (id INT PRIMARY KEY) ENGINE=InnoDB');

$pdo = probe_pdo();
$observer = probe_pdo();
$pdo->exec('SET autocommit=0');
$pdo->query('SELECT 1')->fetchAll();
echo 'inTransaction() after a plain SELECT under autocommit=0 = ' . var_export($pdo->inTransaction(), true) . "\n";
(new PdoDeadlockRetryTransactionRunner($pdo, 3))->run(fn () => $pdo->exec('INSERT INTO probe_ac VALUES (1)'));
echo 'row committed and visible to another connection: ' . var_export((int) $observer->query('SELECT COUNT(*) FROM probe_ac')->fetchColumn() === 1, true) . "\n";

$setup->exec('DROP TABLE probe_ac');
