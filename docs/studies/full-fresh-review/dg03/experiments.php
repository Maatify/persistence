<?php

declare(strict_types=1);

/**
 * DG-03 assessment experiments (study material, no package change). Reuses the repository's two-process
 * deadlock scaffolding. Scenarios:
 *   E1 caller catches 1213 from the savepoint runner and carries on (baseline of the hazard)
 *   E2 same, but the library-side mitigation "resynchronise the driver state with DO 0 on a 1213" is applied
 *   E3 same as E1 with the session on autocommit=0
 *   E4 lock wait timeout (1205) instead of a deadlock: is carrying on legitimate?
 *   E5 can the server tell us the transaction is gone without a write? (@@in_transaction, probe statement)
 */

require dirname(__DIR__, 4) . '/examples/deadlock-retry/_support.php';

use Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetrySavepointTransactionRunner;

function e_insert(PDO $pdo, string $table, string $label): void
{
    $pdo->prepare('INSERT INTO ' . $table . ' (label) VALUES (:l)')->execute(['l' => $label]);
}

/** Runs the savepoint runner into a real deadlock in a caller-owned transaction; $after runs once 1213 was caught. */
function e_deadlock(string $title, callable $after, bool $autocommitOff = false): void
{
    echo "\n== $title\n";
    example_with_table(static function (PDO $pdo, string $table) use ($after, $autocommitOff): void {
        $observer = example_connect();
        $partner = ExampleDeadlockPartner::start($table);

        if ($autocommitOff) {
            $pdo->exec('SET autocommit = 0');
        }

        $pdo->beginTransaction();
        e_insert($pdo, $table, 'caller-before');

        try {
            (new PdoDeadlockRetrySavepointTransactionRunner($pdo, 3))->run(function () use ($pdo, $table, $observer, $partner): void {
                example_lock_row($pdo, $table, 1);
                $partner->queueBehindRowOne($observer);
                example_lock_row($pdo, $table, 2);
            });
        } catch (PDOException $exception) {
            echo '  caught driver code ' . example_error_code($exception) . '; inTransaction() = ' . var_export($pdo->inTransaction(), true) . "\n";
            $after($pdo, $table);
        }

        echo '  committed (seen by another connection): ' . json_encode(example_added_labels($observer, $table)) . "\n";
        $partner->finish();
    });
}

e_deadlock('E1 caller carries on after 1213 (no mitigation)', static function (PDO $pdo, string $table): void {
    e_insert($pdo, $table, 'caller-after');
    echo '  after next statement inTransaction() = ' . var_export($pdo->inTransaction(), true) . "\n";

    try {
        $pdo->commit();
        echo "  commit() succeeded\n";
    } catch (Throwable $throwable) {
        echo '  commit() -> ' . get_class($throwable) . ': ' . $throwable->getMessage() . "\n";
    }
});

e_deadlock('E2 mitigation: resynchronise with a no-op statement right after the 1213', static function (PDO $pdo, string $table): void {
    $pdo->exec('DO 0');
    echo '  after DO 0, inTransaction() = ' . var_export($pdo->inTransaction(), true) . "  (a caller guard `if (!inTransaction()) abort` now sees the truth BEFORE any further write)\n";

    if (!$pdo->inTransaction()) {
        echo "  caller guard aborts; no later write is made\n";

        return;
    }

    e_insert($pdo, $table, 'caller-after');
});

e_deadlock('E3 session autocommit=0, caller carries on after 1213', static function (PDO $pdo, string $table): void {
    e_insert($pdo, $table, 'caller-after');
    echo '  after next statement inTransaction() = ' . var_export($pdo->inTransaction(), true) . "\n";

    try {
        $pdo->commit();
        echo "  commit() succeeded (silently commits only the post-deadlock work)\n";
    } catch (Throwable $throwable) {
        echo '  commit() -> ' . get_class($throwable) . ': ' . $throwable->getMessage() . "\n";
    }
}, true);

echo "\n== E4 lock wait timeout (1205) inside the savepoint runner, caller-owned transaction\n";
$holder = example_connect();
$main = example_connect();
$table = 'maa_example_deadlock_retry_' . bin2hex(random_bytes(6));
$main->exec('CREATE TABLE ' . $table . ' (id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(40) NOT NULL) ENGINE=InnoDB');
$main->exec("INSERT INTO $table (label) VALUES ('seed-1'), ('seed-2')");

try {
    $holder->beginTransaction();
    example_lock_row($holder, $table, 2);
    $main->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $main->beginTransaction();
    e_insert($main, $table, 'caller-before');

    try {
        (new PdoDeadlockRetrySavepointTransactionRunner($main))->run(function () use ($main, $table): void {
            e_insert($main, $table, 'inside-savepoint');
            example_lock_row($main, $table, 2);
        });
    } catch (PDOException $exception) {
        echo '  caught driver code ' . example_error_code($exception) . '; inTransaction() = ' . var_export($main->inTransaction(), true) . "\n";
        echo '  rows visible inside the caller transaction: ' . json_encode(array_column($main->query("SELECT label FROM $table WHERE id > 2 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC), 'label')) . "  (savepoint rolled back, caller work kept)\n";
        e_insert($main, $table, 'caller-after');
    }

    $main->commit();
    $holder->rollBack();
    echo '  committed after carrying on: ' . json_encode(array_column($holder->query("SELECT label FROM $table WHERE id > 2 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC), 'label')) . "\n";
} finally {
    $main->exec('DROP TABLE IF EXISTS ' . $table);
}

echo "\n== E5 detecting a vanished transaction without writing\n";
$probe = example_connect();
$probe->beginTransaction();

foreach (['SELECT @@in_transaction' => 'MariaDB-only session variable', 'DO 0' => 'works on MySQL and MariaDB'] as $sql => $note) {
    try {
        $result = $probe->query($sql);
        echo "  $sql => OK ($note)\n";
        $result?->fetchAll();
    } catch (Throwable $throwable) {
        echo "  $sql => " . get_class($throwable) . ': ' . $throwable->getMessage() . "\n";
    }
}

$probe->rollBack();
