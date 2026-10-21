<?php

declare(strict_types=1);

/**
 * S4 - Differential parity: the shipped manager and the prototype receive the same seeded random operation stream
 * (valid moves, clamped targets, no-ops, missing ids, soft-deleted rows, rows of another scope) on twin tables, once
 * inside a caller-owned transaction and once package-owned. Return values and full table state must be identical after
 * every operation. Failure-free workload: this probe proves the prototype changes nothing on the success path.
 */

require __DIR__ . '/lib.php';

use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;
use Maatify\Persistence\Study48\SavepointMovePrototype;

$pdo = p48_pdo();
echo '== S4 ' . p48_engine($pdo) . "\n";

$real = new ScopedOrderingManager();
$proto = new SavepointMovePrototype($real);

foreach (['scoped' => true, 'global' => false] as $shape => $scoped) {
    foreach (['caller-owned' => true, 'package-owned' => false] as $mode => $inCaller) {
        $tables = ['real' => 'probe_i48_par_real', 'proto' => 'probe_i48_par_proto'];
        foreach ($tables as $t) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $t);
            $pdo->exec('CREATE TABLE ' . $t . ' (id INT AUTO_INCREMENT PRIMARY KEY, scope_id INT NOT NULL, display_order INT NOT NULL,
                deleted_at DATETIME NULL DEFAULT NULL, updated_at DATETIME NULL DEFAULT NULL, KEY idx (scope_id, display_order)) ENGINE=InnoDB');
            $seed = [];
            for ($i = 1; $i <= 24; $i++) {
                $scope = $scoped ? 1 + ($i % 3) : 1;
                $seed[] = '(' . $scope . ',' . (int) ceil($i / ($scoped ? 3 : 1)) . ',' . ($i % 7 === 0 ? "'2026-01-01 00:00:00'" : 'NULL') . ')';
            }
            $pdo->exec('INSERT INTO ' . $t . ' (scope_id, display_order, deleted_at) VALUES ' . implode(',', $seed));
        }
        $cfg = static fn (string $t): ScopedOrderingConfig => $scoped
            ? new ScopedOrderingConfig($t, 'scope_id', 'id', 'display_order', 'deleted_at', false, 'updated_at')
            : new ScopedOrderingConfig($t, null, 'id', 'display_order', 'deleted_at', false, 'updated_at');

        mt_srand(48);
        $ops = 400;
        $mismatch = 0;
        $true = 0;
        $false = 0;
        $savepointStatements = 0;
        if ($inCaller) {
            $pdo->beginTransaction();
        }
        for ($i = 0; $i < $ops; $i++) {
            $scope = $scoped ? mt_rand(1, 3) : null;
            $id = mt_rand(1, 27);
            $to = mt_rand(1, 14);
            $stamp = date('Y-m-d H:i:s', 1_800_000_000 + $i);
            $rr = $real->moveWithinScope($pdo, $cfg($tables['real']), $scope, $id, $to, $stamp);
            $before = p48_counters($pdo)['sp'];
            $rp = $proto->moveWithinScope($pdo, $cfg($tables['proto']), $scope, $id, $to, $stamp);
            $savepointStatements += p48_counters($pdo)['sp'] - $before;
            $rr ? $true++ : $false++;
            $dump = static fn (string $t): array => $pdo->query('SELECT id, scope_id, display_order, deleted_at, updated_at FROM ' . $t . ' ORDER BY id')->fetchAll(PDO::FETCH_NUM);
            if ($rr !== $rp || $dump($tables['real']) !== $dump($tables['proto'])) {
                $mismatch++;
            }
        }
        if ($inCaller) {
            $pdo->commit();
        }
        printf("  %-6s %-13s ops=%d true=%d false=%d mismatches=%d prototypeSavepointStatements=%d\n", $shape, $mode, $ops, $true, $false, $mismatch, $savepointStatements);
        foreach ($tables as $t) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $t);
        }
    }
}
