<?php

declare(strict_types=1);

/**
 * RC-01 RED/GREEN matrix: for every (target, newOrder) pair, run each candidate on a UNIQUE (scope, order)
 * table and compare the final state with the oracle = the shipped algorithm on an identical NON-unique table.
 * GREEN = no exception and same state as the oracle. Run: php docs/studies/full-fresh-review/rc01/matrix.php
 */

require __DIR__ . '/lib.php';

$pdo = rc_pdo();
$datasets = [
    'contiguous 1..6' => [[1, 2, 3, 4, 5, 6], []],
    'gaps 1,2,5,6,9,10' => [[1, 2, 5, 6, 9, 10], []],
    'soft-deleted row inside range (active 1,2,4,5,6; deleted 3)' => [[1, 2, 4, 5, 6], [3]],
];
$schemas = ['UNIQUE signed' => [true, false, false], 'UNIQUE unsigned' => [true, true, false], 'UNIQUE active-only (generated col)' => [false, false, true]];
$results = [];

foreach ($datasets as $dataName => [$active, $deleted]) {
    $max = max($active);

    // Oracle table: same data, non-unique index, shipped algorithm.
    $oracle = [];
    rc_schema($pdo, false, false);

    foreach (array_keys($active) as $index) {
        $id = $index + 1;

        for ($new = 1; $new <= $max + 1; $new++) {
            rc_seed($pdo, $active, $deleted);
            rc_candidates()['S0 shipped']($pdo, $id, $new);
            $oracle[$id][$new] = rc_state($pdo);
        }
    }

    foreach ($schemas as $schemaName => [$unique, $unsigned, $activeOnly]) {
        rc_schema($pdo, $unique, $unsigned, $activeOnly);

        foreach (rc_candidates() as $candidateName => $candidate) {
            foreach (['owned txn', 'caller txn'] as $mode) {
                $green = 0;
                $total = 0;
                $errors = [];

                foreach ($oracle as $id => $byNew) {
                    foreach ($byNew as $new => $expected) {
                        rc_seed($pdo, $active, $deleted);
                        ++$total;

                        try {
                            if ($mode === 'caller txn') {
                                $pdo->beginTransaction();
                            }

                            $candidate($pdo, $id, $new);

                            if ($mode === 'caller txn') {
                                $pdo->commit();
                            }

                            if (rc_state($pdo) === $expected) {
                                ++$green;
                            } else {
                                $errors['state differs from oracle'] = ($errors['state differs from oracle'] ?? 0) + 1;
                            }
                        } catch (Throwable $throwable) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }

                            $key = $throwable instanceof PDOException
                                ? 'SQLSTATE ' . ($throwable->errorInfo[0] ?? '?') . '/' . ($throwable->errorInfo[1] ?? '?')
                                : get_class($throwable);
                            $errors[$key] = ($errors[$key] ?? 0) + 1;
                        }
                    }
                }

                $results[] = [$dataName, $schemaName, $candidateName, $mode, $green, $total, $errors];
            }
        }
    }
}

$pdo->exec('DROP TABLE IF EXISTS ' . RC_T);

printf("%-62s | %-34s | %-27s | %-10s | %s\n", 'dataset', 'schema', 'candidate', 'mode', 'GREEN / total  (errors)');
echo str_repeat('-', 160), "\n";

foreach ($results as [$dataName, $schemaName, $candidateName, $mode, $green, $total, $errors]) {
    printf(
        "%-62s | %-34s | %-27s | %-10s | %3d / %-3d %s %s\n",
        $dataName,
        $schemaName,
        $candidateName,
        $mode,
        $green,
        $total,
        $green === $total ? 'GREEN' : 'RED  ',
        $errors === [] ? '' : json_encode($errors, JSON_UNESCAPED_SLASHES),
    );
}
