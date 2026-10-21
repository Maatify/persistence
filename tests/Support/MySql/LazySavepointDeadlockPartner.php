<?php

declare(strict_types=1);

/**
 * Test-only CLI script: the other side of a real InnoDB deadlock for the
 * moveWithinScope() savepoint tests, on its own MySQL connection.
 *
 * It opens a heavy transaction (200 inserted filler rows, so that InnoDB
 * chooses the caller's lighter transaction as the victim), locks audit row 2
 * and prints "READY <connection id>". After a line on stdin it requests audit
 * row 1, held by the caller, and prints "ACQUIRED" or "FAILURE <driver code>".
 * It always rolls back, so it leaves no data behind.
 */

use Maatify\Persistence\Tests\Support\MySql\LazySavepointSchema;
use Maatify\Persistence\Tests\Support\MySql\MySqlConnectionFactory;

require dirname(__DIR__, 2) . '/bootstrap.php';

$pdo = (new MySqlConnectionFactory())->create();
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 20');
$pdo->beginTransaction();

$lockAudit = static function (int $id) use ($pdo): void {
    $statement = $pdo->prepare('SELECT `id` FROM `' . LazySavepointSchema::AUDIT . '` WHERE `id` = :id FOR UPDATE');
    if ($statement === false) {
        throw new RuntimeException('Unable to prepare the partner locking read.');
    }

    $statement->execute(['id' => $id]);
    $statement->fetchAll();
};

try {
    $pdo->exec(
        'INSERT INTO `' . LazySavepointSchema::FILL . '` (`payload`)
         WITH RECURSIVE `seq` (`n`) AS (SELECT 1 UNION ALL SELECT `n` + 1 FROM `seq` WHERE `n` < 200)
         SELECT \'partner-heavy\' FROM `seq`'
    );
    $lockAudit(2);

    $connectionId = $pdo->query('SELECT CONNECTION_ID()');
    if ($connectionId === false) {
        throw new RuntimeException('Unable to read the partner connection id.');
    }

    fwrite(STDOUT, 'READY ' . $connectionId->fetchColumn() . "\n");
    fflush(STDOUT);
    fgets(STDIN);

    try {
        $lockAudit(1);
        fwrite(STDOUT, "ACQUIRED\n");
    } catch (PDOException $exception) {
        $driverCode = $exception->errorInfo[1] ?? null;
        fwrite(STDOUT, 'FAILURE ' . (is_int($driverCode) ? $driverCode : 'unknown') . "\n");
    }

    fflush(STDOUT);
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
