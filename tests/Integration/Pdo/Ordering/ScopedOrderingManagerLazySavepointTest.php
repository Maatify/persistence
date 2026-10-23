<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Integration\Pdo\Ordering;

use Maatify\Persistence\Exception\TransactionExecutionException;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;
use Maatify\Persistence\Pdo\Transaction\PdoSavepointTransactionRunner;
use Maatify\Persistence\Tests\Support\MySql\FaultInjectingPdo;
use Maatify\Persistence\Tests\Support\MySql\LazySavepointSchema;
use Maatify\Persistence\Tests\Support\MySql\MySqlConnectionFactory;
use Maatify\Persistence\Tests\Support\MySql\PartnerProcess;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Issue #55: moveWithinScope() inside a caller-owned transaction undoes its own
 * partial mutation window with a lazy operation-local savepoint (taken after
 * the scope lock, before the first mutating statement) and never commits,
 * fully rolls back or retries the caller's transaction. Real MySQL only; every
 * failure comes from the server (trigger, UNIQUE key, lock wait, deadlock)
 * except the savepoint-control failures S1-S7, which are injected on the
 * client side of a real connection.
 */
final class ScopedOrderingManagerLazySavepointTest extends TestCase
{
    private const INITIAL = [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5];

    private PDO $pdo;
    private PDO $control;
    private LazySavepointSchema $schema;
    private ScopedOrderingManager $manager;

    private const PARTNER_READ_TIMEOUT_SECONDS = 30.0;

    /** @var list<PartnerProcess> */
    private array $partners = [];

    /** @var list<PDO> */
    private array $callerConnections = [];

    protected function setUp(): void
    {
        $this->control = (new MySqlConnectionFactory())->create();
        $this->control->exec('SET SESSION lock_wait_timeout = 10');
        $this->schema = new LazySavepointSchema($this->control);
        $this->schema->initialize();
        $this->pdo = (new MySqlConnectionFactory())->create();
        $this->manager = new ScopedOrderingManager();
    }

    /**
     * Every cleanup step is attempted even when an earlier one fails (a partner that cannot be stopped must not skip the
     * rollbacks or the schema drop); a child that survives SIGKILL still cannot be guaranteed released. All failures are
     * kept in order: a single one is rethrown as is, several are reported together with the first as the primary.
     */
    protected function tearDown(): void
    {
        $failures = [];

        foreach ($this->partners as $partner) {
            try {
                $partner->stop();
            } catch (Throwable $failure) {
                $failures[] = $failure;
            }
        }

        $this->partners = [];

        foreach ([$this->pdo, ...$this->callerConnections] as $connection) {
            try {
                if ($connection->inTransaction() && !$connection->rollBack()) {
                    throw new RuntimeException('Test cleanup rollBack() returned false.');
                }
            } catch (Throwable $failure) {
                $failures[] = $failure;
            }
        }

        $this->callerConnections = [];

        try {
            $this->schema->drop();
        } catch (Throwable $failure) {
            $failures[] = $failure;
        }

        if (count($failures) === 1) {
            throw $failures[0];
        }

        if ($failures !== []) {
            throw new RuntimeException(
                sprintf(
                    'Test cleanup failed: %s (%d additional failure(s): %s)',
                    $failures[0]->getMessage(),
                    count($failures) - 1,
                    implode(' | ', array_map(static fn (Throwable $failure): string => $failure->getMessage(), array_slice($failures, 1))),
                ),
                0,
                $failures[0],
            );
        }
    }

    // ---- C04: `false` after the shifts ----

    public function testFalseAfterShiftsUndoesTheShiftsAndKeepsCallerWork(): void
    {
        $this->schema->createNeutralizingTrigger(LazySavepointSchema::PLAIN);
        $this->beginCallerTransaction();

        $before = $this->counters();
        $result = $this->move(LazySavepointSchema::PLAIN);
        $delta = $this->deltas($before);

        self::assertFalse($result);
        self::assertSame(self::INITIAL, $this->orders($this->pdo, LazySavepointSchema::PLAIN));
        self::assertSame(['commit' => 0, 'rollback' => 0, 'savepoint' => 1, 'release' => 1, 'rollback_to' => 1], $delta);
        $this->assertCallerTransactionIntact();
        $this->pdo->commit();
        self::assertSame(self::INITIAL, $this->orders($this->control, LazySavepointSchema::PLAIN));
        self::assertSame(1, $this->markerCount());
    }

    // ---- C06: 1062 on the target write ----

    public function testDuplicateKeyOnTargetWriteUndoesShiftsAndRethrowsTheOriginal(): void
    {
        $this->beginCallerTransaction();

        try {
            $this->move(LazySavepointSchema::UNIQUE, LazySavepointSchema::STAMP_DUP);
            self::fail('Expected the duplicate-key PDOException.');
        } catch (PDOException $exception) {
            self::assertSame(1062, $exception->errorInfo[1] ?? null);
        }

        self::assertSame(self::INITIAL, $this->orders($this->pdo, LazySavepointSchema::UNIQUE));
        $this->assertCallerTransactionIntact();
        $this->pdo->commit();
        self::assertSame(self::INITIAL, $this->orders($this->control, LazySavepointSchema::UNIQUE));
    }

    // ---- C07: 1205 on the target write (default server option) ----

    public function testLockWaitTimeoutOnTargetWriteUndoesShiftsAndRethrowsTheOriginal(): void
    {
        $this->schema->createAuditTrigger(LazySavepointSchema::PLAIN);
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $holder = (new MySqlConnectionFactory())->create();
        $this->callerConnections[] = $holder;
        $holder->beginTransaction();
        $holder->exec('UPDATE `' . LazySavepointSchema::AUDIT . '` SET `n` = `n` + 1 WHERE `id` = 2');
        $this->beginCallerTransaction();

        try {
            $this->move(LazySavepointSchema::PLAIN);
            self::fail('Expected the lock wait timeout.');
        } catch (PDOException $exception) {
            self::assertSame(1205, $exception->errorInfo[1] ?? null);
        } finally {
            $holder->rollBack();
        }

        self::assertSame(self::INITIAL, $this->orders($this->pdo, LazySavepointSchema::PLAIN));
        $this->assertCallerTransactionIntact();
    }

    // ---- C10: a real deadlock; the transaction is lost and the original 1213 surfaces ----

    public function testDeadlockSurfacesTheOriginalExceptionAndIsNeitherRescuedNorReplaced(): void
    {
        $this->schema->createAuditTrigger(LazySavepointSchema::UNIQUE);
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 20');
        $this->beginCallerTransaction();
        $this->arrangeDeadlockAgainstCaller();
        $before = $this->counters();

        try {
            $this->move(LazySavepointSchema::UNIQUE);
            self::fail('Expected the caller to be the deadlock victim.');
        } catch (PDOException $exception) {
            self::assertSame(1213, $exception->errorInfo[1] ?? null);
            self::assertSame('40001', $exception->errorInfo[0] ?? null);
        }

        $delta = $this->deltas($before);
        self::assertSame(0, $delta['commit']);
        self::assertSame(0, $delta['rollback']);
        self::assertSame(['ACQUIRED'], $this->partnerReports());
        self::assertSame(self::INITIAL, $this->orders($this->control, LazySavepointSchema::UNIQUE));
        self::assertSame(0, $this->markerCount());
    }

    // ---- C15: a caller that swallowed the 1213 and calls again ----

    public function testCallAfterSwallowedDeadlockFailsClosedBeforeAnyMutation(): void
    {
        $this->schema->createAuditTrigger(LazySavepointSchema::UNIQUE);
        $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 20');
        $this->beginCallerTransaction();
        $this->arrangeDeadlockAgainstCaller();

        try {
            $this->move(LazySavepointSchema::UNIQUE);
            self::fail('Expected the caller to be the deadlock victim.');
        } catch (PDOException $exception) {
            self::assertSame(1213, $exception->errorInfo[1] ?? null);
        }

        $this->partnerReports();

        // Forbidden pattern: the caller swallows the 1213 and carries on. The second move would fail with 1062 after its shifts.
        try {
            $this->move(LazySavepointSchema::UNIQUE, LazySavepointSchema::STAMP_DUP);
            self::fail('Expected the dead-transaction guard.');
        } catch (TransactionExecutionException) {
            self::assertFalse($this->pdo->inTransaction());
        }

        self::assertSame(self::INITIAL, $this->orders($this->control, LazySavepointSchema::UNIQUE));
    }

    // ---- the scope lock outlives the failed move and its local rollback (why the savepoint is lazy) ----

    /**
     * The caller transaction has done no write before the call: only then does InnoDB release the row locks taken after
     * a savepoint on ROLLBACK TO SAVEPOINT, so this is the case in which an eager savepoint (taken before the scope
     * lock) would free the scope and a later conflicting writer could slip in. The lazy placement must not.
     */
    public function testScopeLockSurvivesFailedMoveAndLocalRollbackUntilTheCallerEndsItsTransaction(): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->move(LazySavepointSchema::UNIQUE, LazySavepointSchema::STAMP_DUP);
            self::fail('Expected the duplicate-key PDOException.');
        } catch (PDOException $exception) {
            self::assertSame(1062, $exception->errorInfo[1] ?? null);
        }

        // The move was undone locally; the caller's transaction is still open.
        self::assertSame(self::INITIAL, $this->orders($this->pdo, LazySavepointSchema::UNIQUE));
        self::assertTrue($this->pdo->inTransaction());

        $other = (new MySqlConnectionFactory())->create();
        $this->callerConnections[] = $other;
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

        // Every row of the scope is still locked: a savepoint taken before the scope lock would have released it.
        foreach ([1, 2, 3, 4, 5] as $id) {
            $this->assertBlockedByScopeLock($other, 'UPDATE `' . LazySavepointSchema::UNIQUE . '` SET `label` = \'other\' WHERE `id` = ' . $id);
        }

        $this->assertBlockedByScopeLock(
            $other,
            'INSERT INTO `' . LazySavepointSchema::UNIQUE . '` (`display_order`, `updated_at`, `label`) VALUES (6, \'2026-03-01 00:00:00\', \'other\')',
        );

        // Once the caller ends its transaction the scope is free again.
        $this->pdo->rollBack();
        self::assertSame(1, $other->exec('UPDATE `' . LazySavepointSchema::UNIQUE . '` SET `label` = \'other\' WHERE `id` = 3'));
    }

    // ---- C13: operation-local, an earlier successful move of the same transaction is kept ----

    public function testFailedSecondMoveKeepsTheFirstMoveOfTheSameTransaction(): void
    {
        $this->beginCallerTransaction();
        self::assertTrue($this->move(LazySavepointSchema::UNIQUE, LazySavepointSchema::STAMP_OK));
        $afterFirst = $this->orders($this->pdo, LazySavepointSchema::UNIQUE);
        self::assertSame([1 => 1, 2 => 3, 3 => 4, 4 => 5, 5 => 2], $afterFirst);

        try {
            $this->manager->moveWithinScope($this->pdo, $this->config(LazySavepointSchema::UNIQUE), null, 1, 4, LazySavepointSchema::STAMP_OK);
            self::fail('Expected the duplicate-key PDOException.');
        } catch (PDOException $exception) {
            self::assertSame(1062, $exception->errorInfo[1] ?? null);
        }

        self::assertSame($afterFirst, $this->orders($this->pdo, LazySavepointSchema::UNIQUE));
        $this->assertCallerTransactionIntact();
        $this->pdo->commit();
        self::assertSame($afterFirst, $this->orders($this->control, LazySavepointSchema::UNIQUE));
    }

    // ---- C14: the caller's own savepoint stays usable ----

    public function testCallerSavepointSurvivesAFailedMove(): void
    {
        $this->beginCallerTransaction();
        $this->pdo->exec('SAVEPOINT caller_sp');

        try {
            $this->move(LazySavepointSchema::UNIQUE, LazySavepointSchema::STAMP_DUP);
            self::fail('Expected the duplicate-key PDOException.');
        } catch (PDOException) {
        }

        $this->pdo->exec('ROLLBACK TO SAVEPOINT caller_sp');
        self::assertSame(self::INITIAL, $this->orders($this->pdo, LazySavepointSchema::UNIQUE));
        $this->assertCallerTransactionIntact();
    }

    // ---- nested inside the package savepoint runner ----

    public function testMoveFailureInsideSavepointRunnerLeavesCallerWorkAndPriorMoveIntact(): void
    {
        $this->beginCallerTransaction();
        $runner = new PdoSavepointTransactionRunner($this->pdo);

        try {
            $runner->run(function (): bool {
                $this->move(LazySavepointSchema::UNIQUE, LazySavepointSchema::STAMP_DUP);

                return true;
            });
            self::fail('Expected the duplicate-key PDOException.');
        } catch (PDOException $exception) {
            self::assertSame(1062, $exception->errorInfo[1] ?? null);
        }

        self::assertSame(self::INITIAL, $this->orders($this->pdo, LazySavepointSchema::UNIQUE));
        $this->assertCallerTransactionIntact();
        self::assertTrue($runner->run(fn (): bool => $this->move(LazySavepointSchema::UNIQUE, LazySavepointSchema::STAMP_OK)));
        self::assertSame([1 => 1, 2 => 3, 3 => 4, 4 => 5, 5 => 2], $this->orders($this->pdo, LazySavepointSchema::UNIQUE));
    }

    // ---- C01-C03: savepoint statements only around a mutating window ----

    public function testSuccessfulMutatingMoveAddsExactlyOneSavepointAndOneRelease(): void
    {
        $this->beginCallerTransaction();
        $before = $this->counters();

        self::assertTrue($this->move(LazySavepointSchema::PLAIN));
        $delta = $this->deltas($before);

        self::assertSame(['commit' => 0, 'rollback' => 0, 'savepoint' => 1, 'release' => 1, 'rollback_to' => 0], $delta);
        self::assertSame([1 => 1, 2 => 3, 3 => 4, 4 => 5, 5 => 2], $this->orders($this->pdo, LazySavepointSchema::PLAIN));
        $this->assertCallerTransactionIntact();
    }

    public function testNoOpMoveIssuesNoSavepointStatement(): void
    {
        $this->beginCallerTransaction();
        $before = $this->counters();

        self::assertTrue($this->manager->moveWithinScope($this->pdo, $this->config(LazySavepointSchema::PLAIN), null, 5, 99, LazySavepointSchema::STAMP_OK));
        $delta = $this->deltas($before);

        self::assertSame(['commit' => 0, 'rollback' => 0, 'savepoint' => 0, 'release' => 0, 'rollback_to' => 0], $delta);
        self::assertSame(self::INITIAL, $this->orders($this->pdo, LazySavepointSchema::PLAIN));
    }

    public function testMissingRowIssuesNoSavepointStatement(): void
    {
        $this->beginCallerTransaction();
        $before = $this->counters();

        self::assertFalse($this->manager->moveWithinScope($this->pdo, $this->config(LazySavepointSchema::PLAIN), null, 99, 2, LazySavepointSchema::STAMP_OK));
        $delta = $this->deltas($before);

        self::assertSame(['commit' => 0, 'rollback' => 0, 'savepoint' => 0, 'release' => 0, 'rollback_to' => 0], $delta);
        $this->assertCallerTransactionIntact();
    }

    // ---- O1-O3: the package-owned path is unchanged ----

    public function testOwnedPathSuccessUsesNoSavepointAndCommits(): void
    {
        $before = $this->counters();

        self::assertTrue($this->move(LazySavepointSchema::PLAIN));
        $delta = $this->deltas($before);

        self::assertSame(['commit' => 1, 'rollback' => 0, 'savepoint' => 0, 'release' => 0, 'rollback_to' => 0], $delta);
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame([1 => 1, 2 => 3, 3 => 4, 4 => 5, 5 => 2], $this->orders($this->control, LazySavepointSchema::PLAIN));
    }

    public function testOwnedPathFalseRollsBackTheWholeOwnedTransaction(): void
    {
        $this->schema->createNeutralizingTrigger(LazySavepointSchema::PLAIN);
        $before = $this->counters();

        self::assertFalse($this->move(LazySavepointSchema::PLAIN));
        $delta = $this->deltas($before);

        self::assertSame(['commit' => 0, 'rollback' => 1, 'savepoint' => 0, 'release' => 0, 'rollback_to' => 0], $delta);
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(self::INITIAL, $this->orders($this->control, LazySavepointSchema::PLAIN));
    }

    public function testOwnedPathExceptionRollsBackTheWholeOwnedTransaction(): void
    {
        $before = $this->counters();

        try {
            $this->move(LazySavepointSchema::UNIQUE, LazySavepointSchema::STAMP_DUP);
            self::fail('Expected the duplicate-key PDOException.');
        } catch (PDOException $exception) {
            self::assertSame(1062, $exception->errorInfo[1] ?? null);
        }

        $delta = $this->deltas($before);
        self::assertSame(['commit' => 0, 'rollback' => 1, 'savepoint' => 0, 'release' => 0, 'rollback_to' => 0], $delta);
        self::assertFalse($this->pdo->inTransaction());
        self::assertSame(self::INITIAL, $this->orders($this->control, LazySavepointSchema::UNIQUE));
    }

    // ---- S1-S7: savepoint-control failures on a real connection ----

    public function testS1SavepointReturningFalseFailsClosedBeforeAnyMutation(): void
    {
        $faulty = $this->faultyCallerTransaction();
        $faulty->failExec('/^SAVEPOINT /', 'false');

        try {
            $this->moveOn($faulty, LazySavepointSchema::PLAIN);
            self::fail('Expected TransactionExecutionException.');
        } catch (TransactionExecutionException) {
        }

        self::assertSame(self::INITIAL, $this->orders($faulty, LazySavepointSchema::PLAIN));
        self::assertTrue($faulty->inTransaction());
    }

    public function testS2SavepointThrowingPropagatesBeforeAnyMutation(): void
    {
        $faulty = $this->faultyCallerTransaction();
        $faulty->failExec('/^SAVEPOINT /', 'throw');

        try {
            $this->moveOn($faulty, LazySavepointSchema::PLAIN);
            self::fail('Expected the injected PDOException.');
        } catch (PDOException $exception) {
            self::assertSame('Injected control statement failure.', $exception->getMessage());
        }

        self::assertSame(self::INITIAL, $this->orders($faulty, LazySavepointSchema::PLAIN));
    }

    public function testS3OriginalExceptionWinsOverAFalseRollbackTo(): void
    {
        $faulty = $this->faultyCallerTransaction();
        $faulty->failExec('/^ROLLBACK TO SAVEPOINT/', 'false');

        try {
            $this->moveOn($faulty, LazySavepointSchema::UNIQUE, LazySavepointSchema::STAMP_DUP);
            self::fail('Expected the duplicate-key PDOException.');
        } catch (PDOException $exception) {
            self::assertSame(1062, $exception->errorInfo[1] ?? null);
        }

        // Residual (documented): the failed undo leaves the partial shifts for the caller to roll back.
        self::assertTrue($faulty->inTransaction());
        $faulty->rollBack();
        self::assertSame(self::INITIAL, $this->orders($this->control, LazySavepointSchema::UNIQUE));
    }

    public function testS4OriginalExceptionWinsOverAThrowingRollbackTo(): void
    {
        $faulty = $this->faultyCallerTransaction();
        $faulty->failPrepare('/SET `display_order` = :new_order_case/');
        $faulty->failExec('/^ROLLBACK TO SAVEPOINT/', 'throw');

        try {
            $this->moveOn($faulty, LazySavepointSchema::PLAIN);
            self::fail('Expected the injected RuntimeException.');
        } catch (RuntimeException $exception) {
            self::assertSame('Injected prepare failure.', $exception->getMessage());
        }

        $faulty->rollBack();
    }

    public function testS5FalseResultNeverSurvivesAFailedUndo(): void
    {
        $this->schema->createNeutralizingTrigger(LazySavepointSchema::PLAIN);
        $faulty = $this->faultyCallerTransaction();
        $faulty->failExec('/^ROLLBACK TO SAVEPOINT/', 'false');

        try {
            $result = $this->moveOn($faulty, LazySavepointSchema::PLAIN);
            self::fail('Expected TransactionExecutionException, got ' . var_export($result, true) . '.');
        } catch (TransactionExecutionException) {
            // The caller learns the undo failed; the partial shifts stay for the caller's own rollback.
            self::assertTrue($faulty->inTransaction());
        }

        $faulty->rollBack();
    }

    public function testS6ReleaseReturningFalseUndoesTheMoveAndFails(): void
    {
        $faulty = $this->faultyCallerTransaction();
        $faulty->failExec('/^RELEASE SAVEPOINT/', 'false');

        try {
            $this->moveOn($faulty, LazySavepointSchema::PLAIN);
            self::fail('Expected TransactionExecutionException.');
        } catch (TransactionExecutionException) {
        }

        self::assertSame(self::INITIAL, $this->orders($faulty, LazySavepointSchema::PLAIN));
        self::assertTrue($faulty->inTransaction());
    }

    public function testS7ReleaseThrowingUndoesTheMoveAndRethrowsTheReleaseFailure(): void
    {
        $faulty = $this->faultyCallerTransaction();
        $faulty->failExec('/^RELEASE SAVEPOINT/', 'throw');

        try {
            $this->moveOn($faulty, LazySavepointSchema::PLAIN);
            self::fail('Expected the injected PDOException.');
        } catch (PDOException $exception) {
            self::assertSame('Injected control statement failure.', $exception->getMessage());
        }

        self::assertSame(self::INITIAL, $this->orders($faulty, LazySavepointSchema::PLAIN));
    }

    public function testSavepointNameFollowsTheAdr0003Contract(): void
    {
        $faulty = $this->faultyCallerTransaction();

        self::assertTrue($this->moveOn($faulty, LazySavepointSchema::PLAIN));

        $statements = $faulty->savepointStatements();
        self::assertCount(2, $statements);
        self::assertSame(1, preg_match('/^SAVEPOINT (maatify_persistence_sp_[0-9a-f]{32})$/', $statements[0], $matches));
        self::assertSame('RELEASE SAVEPOINT ' . ($matches[1] ?? ''), $statements[1]);
        $faulty->rollBack();
    }

    // ---- tearDown() fail-closed cleanup (#76 / F77-01) ----

    public function testTearDownSurfacesARollbackFailureAndStillCleansTheRest(): void
    {
        $this->beginCallerTransaction();
        $doomed = $this->killedCallerConnection();

        $failure = null;

        try {
            $this->tearDown();
        } catch (Throwable $thrown) {
            $failure = $thrown;
        }

        self::assertInstanceOf(PDOException::class, $failure, 'A failed rollback must not be reported as a clean teardown.');

        self::assertTrue($doomed->inTransaction(), 'The doomed connection really failed to roll back.');
        self::assertFalse($this->pdo->inTransaction(), 'The remaining connections were still rolled back.');
        self::assertSame(0, $this->schemaTableCount(), 'The schema drop was still attempted.');
    }

    public function testTearDownKeepsAFailedPartnerStopPrimaryAndStillCleansTheDatabase(): void
    {
        $gate = new \ArrayObject(['deliver' => false]);
        $partner = PartnerProcess::start(
            ['sh', '-c', 'kill -STOP $$; exec sleep 60'],
            static fn ($process, int $signal): bool => $gate['deliver'] === true && proc_terminate($process, $signal),
        );
        $this->partners[] = $partner;

        try {
            $partner->awaitStopped(10.0);
            $this->beginCallerTransaction();
            $this->killedCallerConnection();

            $failure = null;

            try {
                $this->tearDown();
            } catch (Throwable $thrown) {
                $failure = $thrown;
            }

            self::assertNotNull($failure, 'A failed partner stop must not be reported as a clean teardown.');
            self::assertStringStartsWith('Test cleanup failed: Test partner pid ' . $partner->pid() . ' is still running', $failure->getMessage());
            self::assertStringContainsString('additional failure(s)', $failure->getMessage());
            self::assertInstanceOf(AssertionFailedError::class, $failure->getPrevious());

            self::assertFalse($this->pdo->inTransaction(), 'Rollbacks were still attempted after the failed stop.');
            self::assertSame(0, $this->schemaTableCount(), 'The schema drop was still attempted after the failed stop.');
        } finally {
            $gate['deliver'] = true;
            $partner->stop();
        }
    }

    // ---- helpers ----

    /** A caller connection in an open transaction whose server session is gone: rollBack() fails for real. */
    private function killedCallerConnection(): PDO
    {
        $doomed = (new MySqlConnectionFactory())->create();
        $this->callerConnections[] = $doomed;
        $doomed->beginTransaction();
        $id = $doomed->query('SELECT CONNECTION_ID()');
        self::assertInstanceOf(PDOStatement::class, $id);
        $connectionId = (int) $id->fetchColumn();
        $this->control->exec('KILL ' . $connectionId);
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $alive = $this->control->query('SELECT COUNT(*) FROM information_schema.processlist WHERE id = ' . $connectionId);
            if ($alive !== false && (int) $alive->fetchColumn() === 0) {
                break;
            }
            usleep(50_000);
        }

        return $doomed;
    }

    private function schemaTableCount(): int
    {
        $statement = $this->control->query(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'maa\\_persistence\\_test\\_lsp\\_%'",
        );
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    /** @param non-empty-string $table */
    private function config(string $table): ScopedOrderingConfig
    {
        return new ScopedOrderingConfig(table: $table, updatedAtColumn: 'updated_at');
    }

    /** @param non-empty-string $table */
    private function move(string $table, string $stamp = LazySavepointSchema::STAMP_OK): bool
    {
        return $this->moveOn($this->pdo, $table, $stamp);
    }

    /** @param non-empty-string $table */
    private function moveOn(PDO $pdo, string $table, string $stamp = LazySavepointSchema::STAMP_OK): bool
    {
        return $this->manager->moveWithinScope(
            $pdo,
            $this->config($table),
            null,
            LazySavepointSchema::MOVE_ID,
            LazySavepointSchema::MOVE_TO,
            $stamp,
        );
    }

    private function beginCallerTransaction(): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->exec('INSERT INTO `' . LazySavepointSchema::MARKER . "` (`note`) VALUES ('caller-earlier-work')");
    }

    private function faultyCallerTransaction(): FaultInjectingPdo
    {
        $faulty = (new MySqlConnectionFactory())->createFaultInjecting();
        $this->callerConnections[] = $faulty;
        $faulty->beginTransaction();
        $faulty->exec('INSERT INTO `' . LazySavepointSchema::MARKER . "` (`note`) VALUES ('caller-earlier-work')");

        return $faulty;
    }

    private function assertBlockedByScopeLock(PDO $other, string $sql): void
    {
        try {
            $other->exec($sql);
            self::fail('The scope lock was released too early: ' . $sql);
        } catch (PDOException $exception) {
            self::assertSame(1205, $exception->errorInfo[1] ?? null, $exception->getMessage());
        }
    }

    private function assertCallerTransactionIntact(): void
    {
        self::assertTrue($this->pdo->inTransaction());
        self::assertSame(1, $this->markerCount($this->pdo));
    }

    private function markerCount(?PDO $pdo = null): int
    {
        $statement = ($pdo ?? $this->control)->query('SELECT COUNT(*) FROM `' . LazySavepointSchema::MARKER . '`');
        self::assertInstanceOf(PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    /** @return array<int, int> */
    private function orders(PDO $pdo, string $table): array
    {
        $statement = $pdo->query('SELECT `id`, `display_order` FROM `' . $table . '` ORDER BY `id`');
        self::assertInstanceOf(PDOStatement::class, $statement);
        $orders = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = is_array($row) ? ($row['id'] ?? null) : null;
            $order = is_array($row) ? ($row['display_order'] ?? null) : null;

            if (!is_numeric($id) || !is_numeric($order)) {
                throw new AssertionFailedError('Unexpected ordering row.');
            }

            $orders[(int) $id] = (int) $order;
        }

        return $orders;
    }

    /** @return array{commit: int, rollback: int, savepoint: int, release: int, rollback_to: int} */
    private function counters(): array
    {
        return [
            'commit' => $this->statusCounter('Com_commit'),
            'rollback' => $this->statusCounter('Com_rollback'),
            'savepoint' => $this->statusCounter('Com_savepoint'),
            'release' => $this->statusCounter('Com_release_savepoint'),
            'rollback_to' => $this->statusCounter('Com_rollback_to_savepoint'),
        ];
    }

    /** @param non-empty-string $variable */
    private function statusCounter(string $variable): int
    {
        $statement = $this->pdo->query("SHOW SESSION STATUS LIKE '" . $variable . "'");
        self::assertInstanceOf(PDOStatement::class, $statement);
        $value = $statement->fetchColumn(1);

        if (!is_numeric($value)) {
            throw new AssertionFailedError('Unexpected status counter ' . $variable . '.');
        }

        return (int) $value;
    }

    /**
     * @param array{commit: int, rollback: int, savepoint: int, release: int, rollback_to: int} $before
     *
     * @return array{commit: int, rollback: int, savepoint: int, release: int, rollback_to: int}
     */
    private function deltas(array $before): array
    {
        $after = $this->counters();

        return [
            'commit' => $after['commit'] - $before['commit'],
            'rollback' => $after['rollback'] - $before['rollback'],
            'savepoint' => $after['savepoint'] - $before['savepoint'],
            'release' => $after['release'] - $before['release'],
            'rollback_to' => $after['rollback_to'] - $before['rollback_to'],
        ];
    }

    /**
     * The caller (already in a transaction) takes audit row 1; a heavier partner holding audit row 2 then queues for
     * row 1, so the trigger's audit-row-2 update in the move makes the caller the genuine deadlock victim.
     */
    private function arrangeDeadlockAgainstCaller(): void
    {
        $this->pdo->exec('UPDATE `' . LazySavepointSchema::AUDIT . '` SET `n` = `n` + 1 WHERE `id` = 1');

        $partner = PartnerProcess::start([PHP_BINARY, dirname(__DIR__, 3) . '/Support/MySql/LazySavepointDeadlockPartner.php']);
        $this->partners[] = $partner;
        $ready = $partner->readLine(self::PARTNER_READ_TIMEOUT_SECONDS);
        self::assertStringStartsWith('READY ', $ready);

        $partner->writeLine('go');
        $this->awaitBlockedPartner(substr($ready, strlen('READY ')));
    }

    /** @return list<string> */
    private function partnerReports(): array
    {
        return array_map(
            static fn (PartnerProcess $partner): string => $partner->readLine(self::PARTNER_READ_TIMEOUT_SECONDS),
            $this->partners,
        );
    }

    private function awaitBlockedPartner(string $connectionId): void
    {
        $deadline = microtime(true) + 15;

        do {
            $rows = $this->control->query('SHOW FULL PROCESSLIST');
            self::assertInstanceOf(PDOStatement::class, $rows);

            foreach ($rows->fetchAll() as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $info = $row['Info'] ?? null;
                $time = $row['Time'] ?? null;
                $id = $row['Id'] ?? null;

                if (
                    is_scalar($id) && (string) $id === $connectionId
                    && is_string($info) && str_contains($info, 'FOR UPDATE')
                    && is_numeric($time) && (int) $time >= 1
                ) {
                    return;
                }
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        throw new AssertionFailedError('The deadlock partner never blocked on its locking read.');
    }
}
