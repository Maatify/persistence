<?php

declare(strict_types=1);

/**
 * Round 2 / F45-R03 (study material). What does a DO 0 refresh really establish?
 *   A  default session: DO 0 after 1213
 *   B  autocommit=0 session: DO 0 after 1213 (does the no-op open a new server transaction?)
 *   C  savepoint-based detection (ROLLBACK TO SAVEPOINT -> 1305) in both session modes
 *   D  the refresh statement itself fails (connection killed): which exception reaches the caller?
 */

require dirname(__DIR__, 4) . '/examples/deadlock-retry/_support.php';

use Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetrySavepointTransactionRunner;

/** @param callable(PDO,string,PDOException,PDO,int):void $after */
function r2_run(string $title, bool $autocommitOff, callable $after): void
{
    echo "\n== $title\n";
    example_with_table(static function (PDO $pdo, string $table) use ($autocommitOff, $after): void {
        $observer = example_connect();
        $partner = ExampleDeadlockPartner::start($table);

        if ($autocommitOff) {
            $pdo->exec('SET autocommit = 0');
        }

        $connectionId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO $table (label) VALUES ('caller-before')");
        $pdo->exec('SAVEPOINT study_caller_sp');

        try {
            (new PdoDeadlockRetrySavepointTransactionRunner($pdo))->run(function () use ($pdo, $table, $observer, $partner): void {
                example_lock_row($pdo, $table, 1);
                $partner->queueBehindRowOne($observer);
                example_lock_row($pdo, $table, 2);
            });
        } catch (PDOException $exception) {
            echo '  caught ' . example_error_code($exception) . '; inTransaction() = ' . var_export($pdo->inTransaction(), true) . "\n";
            $after($pdo, $table, $exception, $observer, $connectionId);
        }

        echo '  committed: ' . json_encode(example_added_labels($observer, $table)) . "\n";
        $partner->finish();
    });
}

function r2_savepoint_probe(PDO $pdo): string
{
    try {
        $pdo->exec('ROLLBACK TO SAVEPOINT study_caller_sp');

        return 'savepoint STILL EXISTS (transaction alive)';
    } catch (PDOException $exception) {
        return 'ROLLBACK TO SAVEPOINT -> ' . ($exception->errorInfo[1] ?? '?') . ' (savepoint gone: the transaction ended)';
    }
}

foreach ([['A default session (autocommit=1)', false], ['B session autocommit=0', true]] as [$label, $off]) {
    r2_run("$label: DO 0 after the 1213", $off, static function (PDO $pdo) use ($off): void {
        $pdo->exec('DO 0');
        echo '  after DO 0 inTransaction() = ' . var_export($pdo->inTransaction(), true) . ($off ? "  <- a guard on this flag is FOOLED if true\n" : "\n");
    });

    r2_run("$label: savepoint-based detection (no DO 0)", $off, static function (PDO $pdo): void {
        echo '  ' . r2_savepoint_probe($pdo) . "\n";
    });
}

r2_run('B session autocommit=0: DO 0 first, then the savepoint probe', true, static function (PDO $pdo): void {
    $pdo->exec('DO 0');
    echo '  after DO 0 inTransaction() = ' . var_export($pdo->inTransaction(), true) . "\n";
    echo '  ' . r2_savepoint_probe($pdo) . "\n";
});

try {
    r2_run('D the refresh statement itself fails (connection killed right after the 1213)', false, static function (PDO $pdo, string $table, PDOException $original, PDO $observer, int $connectionId): void {
    $observer->exec('KILL ' . $connectionId);
    usleep(200000);
    $refreshFailure = null;

    try {
        $pdo->exec('DO 0');
    } catch (Throwable $throwable) {
        $refreshFailure = $throwable;
    }

    echo '  refresh failed with: ' . ($refreshFailure === null ? 'nothing (!)' : get_class($refreshFailure) . ' ' . ($refreshFailure->errorInfo[1] ?? '?')) . "\n";
    echo '  the ORIGINAL exception object is untouched: code ' . ($original->errorInfo[1] ?? '?') . ", so a swallow-and-rethrow-original pattern preserves it\n";
    echo '  inTransaction() stays ' . var_export($pdo->inTransaction(), true) . " (the refresh did not happen; a guard on this flag proves nothing)\n";
});
} catch (PDOException $scaffold) {
    echo "  (scaffolding cleanup on the killed connection failed as expected: " . ($scaffold->errorInfo[1] ?? '?') . ")\n";
}

$cleanup = example_connect();

foreach ($cleanup->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'maa_example_deadlock_retry_%'")->fetchAll(PDO::FETCH_COLUMN) as $leftover) {
    $cleanup->exec('DROP TABLE IF EXISTS `' . $leftover . '`');
}
