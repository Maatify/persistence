<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Unit\Pdo\Transaction;

use Maatify\Persistence\Exception\InvalidTransactionConfigurationException;
use Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetrySavepointTransactionRunner;
use Maatify\Persistence\Pdo\Transaction\TransactionRunnerInterface;
use Maatify\Persistence\Tests\Support\Pdo\Transaction\PdoErrors;
use Maatify\Persistence\Tests\Support\Pdo\Transaction\RetryTransactionPdo;
use Maatify\Persistence\Tests\Support\Pdo\Transaction\SavepointTransactionPdo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

final class PdoDeadlockRetrySavepointTransactionRunnerRetryTest extends TestCase
{
    /** @return array<string, array{int}> */
    public static function invalidRetries(): array
    {
        return ['negative' => [-1], 'above the limit' => [4]];
    }

    #[DataProvider('invalidRetries')]
    public function testRetryLimitOutsideZeroToThreeIsRejectedAtConstruction(int $retries): void
    {
        $this->expectException(InvalidTransactionConfigurationException::class);

        new PdoDeadlockRetrySavepointTransactionRunner(new RetryTransactionPdo(), $retries);
    }

    public function testOwnedPathReplaysADeadlockWithoutSavepointSql(): void
    {
        $pdo = new RetryTransactionPdo();
        $calls = 0;

        $result = (new PdoDeadlockRetrySavepointTransactionRunner($pdo, 3))->run(
            static function () use ($pdo, &$calls): string {
                $calls++;
                if ($calls === 1) {
                    $pdo->endTransactionOnServer();

                    throw PdoErrors::deadlock();
                }

                return 'owned replay committed';
            },
        );

        self::assertSame('owned replay committed', $result);
        self::assertSame(2, $calls);
        self::assertSame(2, $pdo->beginTransactionCalls);
        self::assertSame(1, $pdo->commitCalls);
    }

    public function testOwnedPathWithDefaultConfigurationDoesNotReplay(): void
    {
        $pdo = new RetryTransactionPdo();
        $deadlock = PdoErrors::deadlock();
        $calls = 0;

        $thrown = $this->runCatching(
            new PdoDeadlockRetrySavepointTransactionRunner($pdo),
            static function () use ($pdo, $deadlock, &$calls): never {
                $calls++;
                $pdo->endTransactionOnServer();

                throw $deadlock;
            },
        );

        self::assertSame($deadlock, $thrown);
        self::assertSame(1, $calls);
        self::assertSame(1, $pdo->beginTransactionCalls);
    }

    public function testOwnedPathExhaustionPropagatesTheExactFinalDeadlock(): void
    {
        $pdo = new RetryTransactionPdo();
        $raised = [];

        $thrown = $this->runCatching(
            new PdoDeadlockRetrySavepointTransactionRunner($pdo, 3),
            static function () use ($pdo, &$raised): never {
                $deadlock = PdoErrors::deadlock('attempt ' . (count($raised) + 1));
                $raised[] = $deadlock;
                $pdo->endTransactionOnServer();

                throw $deadlock;
            },
        );

        self::assertCount(4, $raised);
        self::assertSame($raised[3], $thrown);
        self::assertSame(4, $pdo->beginTransactionCalls);
    }

    public function testDeadlockInsideCallerOwnedSavepointIsNeverReplayedAndCleanupIsSkippedOnceMySqlEndedTheTransaction(): void
    {
        $pdo = new SavepointTransactionPdo(transactionActive: true);
        $deadlock = PdoErrors::deadlock();
        $calls = 0;

        $thrown = $this->runCatching(
            new PdoDeadlockRetrySavepointTransactionRunner($pdo, 3),
            static function () use ($pdo, $deadlock, &$calls): never {
                $calls++;
                // InnoDB rolls back the whole caller-owned transaction of a deadlock victim.
                $pdo->rollBack();

                throw $deadlock;
            },
        );

        self::assertSame($deadlock, $thrown);
        self::assertSame(1, $calls);
        self::assertSame(0, $pdo->beginTransactionCalls);
        self::assertSame(0, $pdo->commitCalls);
        self::assertSame(1, $pdo->rollBackCalls, 'Only the simulated server rollback; the runner adds none.');
        self::assertCount(1, $pdo->executedStatements);
        self::assertStringStartsWith('SAVEPOINT maatify_persistence_sp_', $pdo->executedStatements[0]);
    }

    public function testDeadlockInsideCallerOwnedSavepointWithTransactionStillActiveRollsBackOnlyTheSavepoint(): void
    {
        $pdo = new SavepointTransactionPdo(transactionActive: true);
        $deadlock = PdoErrors::deadlock('reported while the caller transaction is still active');
        $calls = 0;

        $thrown = $this->runCatching(
            new PdoDeadlockRetrySavepointTransactionRunner($pdo, 3),
            static function () use ($deadlock, &$calls): never {
                $calls++;

                throw $deadlock;
            },
        );

        self::assertSame($deadlock, $thrown);
        self::assertSame(1, $calls);
        self::assertTrue($pdo->inTransaction());
        self::assertSame(0, $pdo->rollBackCalls);
        self::assertCount(3, $pdo->executedStatements);
        self::assertStringStartsWith('ROLLBACK TO SAVEPOINT maatify_persistence_sp_', $pdo->executedStatements[1]);
        self::assertStringStartsWith('RELEASE SAVEPOINT maatify_persistence_sp_', $pdo->executedStatements[2]);
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
