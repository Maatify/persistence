<?php

declare(strict_types=1);

/**
 * Test-only CLI script that runs a second allocator on its own MySQL connection.
 *
 * It opens a transaction, takes a plain-read snapshot, then calls
 * getNextPositionForUpdate() for the given scope. The call blocks while another
 * connection holds the scope's last row; the script prints its connection id
 * before the call and, after it, either the returned position or the driver
 * error code of a PDOException (for example 1213 for a deadlock).
 *
 * Usage: php NextPositionWaiter.php "<READ COMMITTED|REPEATABLE READ>" <scope> [heavy] [hold]
 *
 * Flags:
 * - heavy: first lock many rows of an unrelated scope, so InnoDB treats this
 *   transaction as the heavier one when it has to pick a deadlock victim.
 * - hold: keep the transaction (and its locks) open after reporting, until the
 *   parent closes this process's stdin.
 */

use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;
use Maatify\Persistence\Tests\Support\MySql\MySqlConnectionFactory;
use Maatify\Persistence\Tests\Support\MySql\OrderingSchemaManager;

require dirname(__DIR__, 2) . '/bootstrap.php';

$rawArguments = $_SERVER['argv'] ?? [];
$arguments = is_array($rawArguments) ? array_values(array_filter($rawArguments, 'is_string')) : [];

$isolationLevel = $arguments[1] ?? '';
$scope = $arguments[2] ?? '';
$flags = array_slice($arguments, 3);

if (
    !in_array($isolationLevel, ['READ COMMITTED', 'REPEATABLE READ'], true)
    || $scope === ''
    || array_diff($flags, ['heavy', 'hold']) !== []
) {
    fwrite(STDERR, "Usage: NextPositionWaiter.php \"<READ COMMITTED|REPEATABLE READ>\" <scope> [heavy] [hold]\n");
    exit(2);
}

$pdo = (new MySqlConnectionFactory())->create();
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 20');
$pdo->exec('SET TRANSACTION ISOLATION LEVEL ' . $isolationLevel);
$pdo->beginTransaction();

try {
    $connectionId = $pdo->query('SELECT CONNECTION_ID()');
    $snapshot = $pdo->query('SELECT COUNT(*) FROM `' . OrderingSchemaManager::SCOPED_TABLE . '`');

    if ($connectionId === false || $snapshot === false) {
        throw new RuntimeException('Unable to prepare the waiting allocator connection.');
    }

    $snapshot->fetchColumn();

    if (in_array('heavy', $flags, true)) {
        $pdo->exec(
            'INSERT INTO `' . OrderingSchemaManager::SCOPED_TABLE . '` (`scope_key`, `display_order`, `label`)
             WITH RECURSIVE `seq` (`n`) AS (SELECT 1 UNION ALL SELECT `n` + 1 FROM `seq` WHERE `n` < 400)
             SELECT \'heavy-scope\', `n`, \'heavy\' FROM `seq`'
        );
    }

    fwrite(STDOUT, 'CONNECTION_ID ' . $connectionId->fetchColumn() . "\n");
    fflush(STDOUT);

    try {
        $position = (new ScopedOrderingManager())->getNextPositionForUpdate(
            $pdo,
            new ScopedOrderingConfig(table: OrderingSchemaManager::SCOPED_TABLE, scopeColumn: 'scope_key'),
            $scope,
        );

        fwrite(STDOUT, 'POSITION ' . $position . "\n");
        fflush(STDOUT);

        if (in_array('hold', $flags, true)) {
            fgets(STDIN);
        }
    } catch (PDOException $exception) {
        $driverCode = $exception->errorInfo[1] ?? null;

        fwrite(STDOUT, 'FAILURE ' . (is_int($driverCode) ? $driverCode : 'unknown') . "\n");
    }
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
