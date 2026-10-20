<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Integration\Pdo\Transaction;

use Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetrySavepointTransactionRunner;
use Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetryTransactionRunner;
use Maatify\Persistence\Pdo\Transaction\PdoTransactionRunner;
use Maatify\Persistence\Pdo\Transaction\TransactionRunnerInterface;
use Maatify\Persistence\Tests\Support\MySql\DeadlockRetryIntegrationTestCase;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

/**
 * Issue #32 on real MySQL: genuine InnoDB deadlocks (1213 / 40001) against a
 * second connection in another process. Owned transactions are replayed from a
 * fresh BEGIN when retries are configured; caller-owned transactions and their
 * savepoints are never replayed.
 */
final class PdoDeadlockRetryRunnerIntegrationTest extends DeadlockRetryIntegrationTestCase
{
    /** @return array<string, array{callable(\PDO): TransactionRunnerInterface}> */
    public static function retryingRunners(): array
    {
        return [
            'PdoDeadlockRetryTransactionRunner' => [static fn (\PDO $pdo): TransactionRunnerInterface => new PdoDeadlockRetryTransactionRunner($pdo, 3)],
            'PdoDeadlockRetrySavepointTransactionRunner' => [static fn (\PDO $pdo): TransactionRunnerInterface => new PdoDeadlockRetrySavepointTransactionRunner($pdo, 3)],
        ];
    }

    public function testDeprecatedRunnerSurfacesTheOwnedDeadlockOfIssue32(): void
    {
        $calls = 0;
        $raised = null;

        $thrown = $this->runCatching(
            new PdoTransactionRunner($this->pdo()),
            function () use (&$calls, &$raised): never {
                $calls++;
                $this->insert('deprecated-runner');

                throw $raised = $this->provokeDeadlockAsVictim();
            },
        );

        self::assertSame($raised, $thrown);
        self::assertSame(1, $calls);
        self::assertFalse($this->pdo()->inTransaction());
        self::assertSame([], $this->committedPayloads());
        self::assertSame(['ACQUIRED'], $this->partnerReports());
    }

    public function testDefaultConfigurationDoesNotReplayARealOwnedDeadlock(): void
    {
        $calls = 0;
        $raised = null;

        $thrown = $this->runCatching(
            new PdoDeadlockRetryTransactionRunner($this->pdo()),
            function () use (&$calls, &$raised): never {
                $calls++;
                $this->insert('default-no-retry');

                throw $raised = $this->provokeDeadlockAsVictim();
            },
        );

        self::assertSame($raised, $thrown);
        self::assertSame(1, $calls);
        self::assertFalse($this->pdo()->inTransaction());
        self::assertSame([], $this->committedPayloads());
        self::assertSame(['ACQUIRED'], $this->partnerReports());
    }

    /** @param callable(\PDO): TransactionRunnerInterface $runnerFactory */
    #[DataProvider('retryingRunners')]
    public function testRealOwnedDeadlockIsReplayedFromAFreshTransactionAndCommittedOnce(callable $runnerFactory): void
    {
        $calls = 0;
        /** @var list<bool> $inTransactionAtStart */
        $inTransactionAtStart = [];
        $raised = null;

        $result = $runnerFactory($this->pdo())->run(function () use (&$calls, &$inTransactionAtStart, &$raised): string {
            $calls++;
            $inTransactionAtStart[] = $this->pdo()->inTransaction();
            $this->insert('attempt-' . $calls);

            if ($calls === 1) {
                throw $raised = $this->provokeDeadlockAsVictim();
            }

            return 'committed after replay';
        });

        self::assertSame('committed after replay', $result);
        self::assertSame(2, $calls);
        self::assertSame([true, true], $inTransactionAtStart);
        self::assertInstanceOf(PDOException::class, $raised);
        self::assertFalse($this->pdo()->inTransaction());
        self::assertSame(['attempt-2'], $this->committedPayloads(), 'The rolled-back first attempt leaves nothing behind.');
        self::assertSame(['ACQUIRED'], $this->partnerReports());
    }

    /** @param callable(\PDO): TransactionRunnerInterface $runnerFactory */
    #[DataProvider('retryingRunners')]
    public function testExhaustionAfterFourRealDeadlocksPropagatesTheExactFinalPdoException(callable $runnerFactory): void
    {
        /** @var list<PDOException> $raised */
        $raised = [];

        $thrown = $this->runCatching(
            $runnerFactory($this->pdo()),
            function () use (&$raised): never {
                $this->insert('attempt-' . (count($raised) + 1));

                throw $raised[] = $this->provokeDeadlockAsVictim();
            },
        );

        self::assertCount(4, $raised, 'Initial attempt plus three retries.');
        self::assertSame($raised[3], $thrown);
        self::assertSame(self::DEADLOCK_SQLSTATE, $raised[3]->errorInfo[0] ?? null);
        self::assertSame(self::DEADLOCK_DRIVER_CODE, $raised[3]->errorInfo[1] ?? null);
        self::assertFalse($this->pdo()->inTransaction());
        self::assertSame([], $this->committedPayloads());
        self::assertSame(['ACQUIRED', 'ACQUIRED', 'ACQUIRED', 'ACQUIRED'], $this->partnerReports());
    }

    /** @param callable(\PDO): TransactionRunnerInterface $runnerFactory */
    #[DataProvider('retryingRunners')]
    public function testRealDeadlockInsideCallerOwnedTransactionIsNeverReplayed(callable $runnerFactory): void
    {
        $calls = 0;
        $raised = null;
        $this->pdo()->beginTransaction();
        $this->insert('caller-before');

        $thrown = $this->runCatching(
            $runnerFactory($this->pdo()),
            function () use (&$calls, &$raised): never {
                $calls++;
                $this->insert('operation');

                throw $raised = $this->provokeDeadlockAsVictim();
            },
        );

        self::assertSame($raised, $thrown);
        self::assertSame(1, $calls);
        // pdo_mysql updates inTransaction() only from successful server replies, so right after the deadlock
        // it still reports the caller's transaction. The connection's own view proves that InnoDB rolled back
        // the whole caller-owned transaction, including the row written before the runner was called, and
        // that the runner neither replayed nor committed anything.
        self::assertSame([], $this->payloadsSeenByConnectionUnderTest());
        self::assertFalse($this->pdo()->inTransaction(), 'After a successful statement no transaction is reported.');
        self::assertSame([], $this->committedPayloads());
        self::assertSame(['ACQUIRED'], $this->partnerReports());
    }

    public function testNestedSavepointDeadlockReplaysTheWholeOwnedTransaction(): void
    {
        $outerCalls = 0;
        $innerCalls = 0;
        $savepoints = new PdoDeadlockRetrySavepointTransactionRunner($this->pdo(), 3);

        $result = (new PdoDeadlockRetryTransactionRunner($this->pdo(), 3))->run(
            function () use ($savepoints, &$outerCalls, &$innerCalls): string {
                $outerCalls++;
                $this->insert('outer-' . $outerCalls);

                $savepoints->run(function () use (&$innerCalls): void {
                    $innerCalls++;
                    $this->insert('inner-' . $innerCalls);

                    if ($innerCalls === 1) {
                        throw $this->provokeDeadlockAsVictim();
                    }
                });

                return 'outer committed';
            },
        );

        self::assertSame('outer committed', $result);
        self::assertSame(2, $outerCalls, 'The owning runner replays its whole callback.');
        self::assertSame(2, $innerCalls, 'The savepoint itself is never replayed; it runs again only as part of the outer replay.');
        self::assertFalse($this->pdo()->inTransaction());
        self::assertSame(['outer-2', 'inner-2'], $this->committedPayloads());
        self::assertSame(['ACQUIRED'], $this->partnerReports());
    }

    /** @param callable(): mixed $callback */
    private function runCatching(TransactionRunnerInterface $runner, callable $callback): Throwable
    {
        try {
            $runner->run($callback);
        } catch (Throwable $throwable) {
            return $throwable;
        }

        self::fail('Expected a Throwable.');
    }
}
