<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Unit\Pdo\Transaction;

use Error;
use Maatify\Persistence\Exception\InvalidTransactionConfigurationException;
use Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetryTransactionRunner;
use Maatify\Persistence\Pdo\Transaction\TransactionRunnerInterface;
use Maatify\Persistence\Tests\Support\Pdo\Transaction\PdoErrors;
use Maatify\Persistence\Tests\Support\Pdo\Transaction\RetryTransactionPdo;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class PdoDeadlockRetryTransactionRunnerTest extends TestCase
{
    /** @return array<string, array{int}> */
    public static function configuredRetries(): array
    {
        return ['default (0)' => [0], 'one' => [1], 'two' => [2], 'three' => [3]];
    }

    /** @return array<string, array{int}> */
    public static function invalidRetries(): array
    {
        return ['negative' => [-1], 'above the limit' => [4], 'far above the limit' => [PHP_INT_MAX]];
    }

    /** @return array<string, array{Throwable}> */
    public static function nonReplayableFailures(): array
    {
        $withoutErrorInfo = new PDOException('PDOException without errorInfo');

        return [
            'lock wait timeout 1205' => [PdoErrors::driverError('HY000', 1205, 'Lock wait timeout exceeded')],
            'duplicate key 1062' => [PdoErrors::driverError('23000', 1062, 'Duplicate entry')],
            '1213 with another SQLSTATE' => [PdoErrors::driverError('HY000', 1213, 'Deadlock with wrong SQLSTATE')],
            '40001 with another driver code' => [PdoErrors::driverError('40001', 1205, 'Serialization failure without deadlock code')],
            'PDOException without errorInfo' => [$withoutErrorInfo],
            'non-PDO throwable' => [new RuntimeException('callback failure')],
            'engine error' => [new Error('engine failure')],
        ];
    }

    #[DataProvider('configuredRetries')]
    public function testValidRetryLimitsAreAccepted(int $retries): void
    {
        $pdo = new RetryTransactionPdo();

        self::assertSame('ok', (new PdoDeadlockRetryTransactionRunner($pdo, $retries))->run(static fn (): string => 'ok'));
    }

    #[DataProvider('invalidRetries')]
    public function testRetryLimitOutsideZeroToThreeIsRejectedAtConstruction(int $retries): void
    {
        $this->expectException(InvalidTransactionConfigurationException::class);

        new PdoDeadlockRetryTransactionRunner(new RetryTransactionPdo(), $retries);
    }

    #[DataProvider('configuredRetries')]
    public function testOwnedTransactionCommitsOnceAndPreservesCallbackResult(int $retries): void
    {
        $pdo = new RetryTransactionPdo();
        $calls = 0;

        $result = (new PdoDeadlockRetryTransactionRunner($pdo, $retries))->run(
            static function () use (&$calls): array {
                $calls++;

                return ['committed'];
            },
        );

        self::assertSame(['committed'], $result);
        self::assertSame(1, $calls);
        self::assertSame(1, $pdo->beginTransactionCalls);
        self::assertSame(1, $pdo->commitCalls);
        self::assertSame(0, $pdo->rollBackCalls);
        self::assertFalse($pdo->inTransaction());
    }

    #[DataProvider('configuredRetries')]
    public function testOwnedNonDeadlockFailureRollsBackOnceAndRethrowsExactThrowable(int $retries): void
    {
        $pdo = new RetryTransactionPdo();
        $failure = new RuntimeException('owned transaction failure.');
        $calls = 0;

        $thrown = $this->runCatching(
            new PdoDeadlockRetryTransactionRunner($pdo, $retries),
            static function () use ($failure, &$calls): never {
                $calls++;

                throw $failure;
            },
        );

        self::assertSame($failure, $thrown);
        self::assertSame(1, $calls);
        self::assertSame(1, $pdo->beginTransactionCalls);
        self::assertSame(0, $pdo->commitCalls);
        self::assertSame(1, $pdo->rollBackCalls);
        self::assertFalse($pdo->inTransaction());
    }

    #[DataProvider('configuredRetries')]
    public function testRollbackFailureDoesNotReplaceOriginalThrowable(int $retries): void
    {
        $failure = new RuntimeException('callback failure.');
        $pdo = new RetryTransactionPdo(rollbackFailure: new RuntimeException('rollback failure.'));

        $thrown = $this->runCatching(
            new PdoDeadlockRetryTransactionRunner($pdo, $retries),
            static function () use ($failure): never {
                throw $failure;
            },
        );

        self::assertSame($failure, $thrown);
        self::assertSame(1, $pdo->rollBackCalls);
        self::assertTrue($pdo->inTransaction());
    }

    #[DataProvider('configuredRetries')]
    public function testExistingTransactionParticipatesWithoutChangingItsOwnership(int $retries): void
    {
        $pdo = new RetryTransactionPdo(transactionActive: true);

        self::assertSame('participated', (new PdoDeadlockRetryTransactionRunner($pdo, $retries))->run(
            static fn (): string => 'participated',
        ));
        self::assertSame(0, $pdo->beginTransactionCalls);
        self::assertSame(0, $pdo->commitCalls);
        self::assertSame(0, $pdo->rollBackCalls);
        self::assertTrue($pdo->inTransaction());
    }

    #[DataProvider('configuredRetries')]
    public function testDeadlockInsideCallerOwnedTransactionIsNeverRetried(int $retries): void
    {
        $pdo = new RetryTransactionPdo(transactionActive: true);
        $deadlock = PdoErrors::deadlock();
        $calls = 0;

        $thrown = $this->runCatching(
            new PdoDeadlockRetryTransactionRunner($pdo, $retries),
            static function () use ($pdo, $deadlock, &$calls): never {
                $calls++;
                $pdo->endTransactionOnServer();

                throw $deadlock;
            },
        );

        self::assertSame($deadlock, $thrown);
        self::assertSame(1, $calls);
        self::assertSame(0, $pdo->beginTransactionCalls);
        self::assertSame(0, $pdo->commitCalls);
        self::assertSame(0, $pdo->rollBackCalls);
    }

    public function testDefaultConfigurationDoesNotReplayAnOwnedDeadlock(): void
    {
        $pdo = new RetryTransactionPdo();
        $deadlock = PdoErrors::deadlock();
        $calls = 0;

        $thrown = $this->runCatching(
            new PdoDeadlockRetryTransactionRunner($pdo),
            static function () use ($pdo, $deadlock, &$calls): never {
                $calls++;
                $pdo->endTransactionOnServer();

                throw $deadlock;
            },
        );

        self::assertSame($deadlock, $thrown);
        self::assertSame(1, $calls);
        self::assertSame(1, $pdo->beginTransactionCalls);
        self::assertSame(1, $pdo->rollBackCalls, 'pdo_mysql still reports the deadlocked transaction as active, so the runner sends one final best-effort ROLLBACK (a no-op on the server).');
    }

    public function testOwnedDeadlockIsReplayedFromAFreshTransactionAndThenCommits(): void
    {
        $pdo = new RetryTransactionPdo();
        $calls = 0;
        $inTransactionAtStart = [];

        $result = (new PdoDeadlockRetryTransactionRunner($pdo, 3))->run(
            static function () use ($pdo, &$calls, &$inTransactionAtStart): string {
                $calls++;
                $inTransactionAtStart[] = $pdo->inTransaction();

                if ($calls === 1) {
                    $pdo->endTransactionOnServer();

                    throw PdoErrors::deadlock('first attempt');
                }

                return 'committed after replay';
            },
        );

        self::assertSame('committed after replay', $result);
        self::assertSame(2, $calls);
        self::assertSame([true, true], $inTransactionAtStart);
        self::assertSame(2, $pdo->beginTransactionCalls);
        self::assertSame(1, $pdo->commitCalls);
        self::assertSame(0, $pdo->rollBackCalls);
        self::assertFalse($pdo->inTransaction());
    }

    #[DataProvider('configuredRetries')]
    public function testExhaustionPropagatesTheExactFinalDeadlockAfterOnePlusRetriesAttempts(int $retries): void
    {
        $pdo = new RetryTransactionPdo();
        /** @var list<PDOException> $raised */
        $raised = [];

        $thrown = $this->runCatching(
            new PdoDeadlockRetryTransactionRunner($pdo, $retries),
            static function () use ($pdo, &$raised): never {
                $deadlock = PdoErrors::deadlock('attempt ' . (count($raised) + 1));
                $raised[] = $deadlock;
                $pdo->endTransactionOnServer();

                throw $deadlock;
            },
        );

        self::assertCount($retries + 1, $raised);
        $finalDeadlock = array_pop($raised);
        self::assertNotNull($finalDeadlock);
        self::assertSame($finalDeadlock, $thrown);
        self::assertSame($retries + 1, $pdo->beginTransactionCalls);
        self::assertSame(
            array_fill(0, $retries, 'DO 0'),
            $pdo->executedStatements,
            'One server-state probe per replay decision; none at the default and none after the last attempt.',
        );
        self::assertSame(0, $pdo->commitCalls);
        self::assertSame(1, $pdo->rollBackCalls, 'pdo_mysql still reports the deadlocked transaction as active, so the runner sends one final best-effort ROLLBACK (a no-op on the server).');
        self::assertFalse($pdo->inTransaction());
    }

    #[DataProvider('nonReplayableFailures')]
    public function testOnlyRealDeadlocksAreReplayed(Throwable $failure): void
    {
        $pdo = new RetryTransactionPdo();
        $calls = 0;

        $thrown = $this->runCatching(
            new PdoDeadlockRetryTransactionRunner($pdo, 3),
            static function () use ($pdo, $failure, &$calls): never {
                $calls++;
                // Even with the server-side transaction already gone, the failure itself is not replayable.
                $pdo->endTransactionOnServer();

                throw $failure;
            },
        );

        self::assertSame($failure, $thrown);
        self::assertSame(1, $calls);
        self::assertSame(1, $pdo->beginTransactionCalls);
    }

    public function testDeadlockThatLeavesTheOwnedTransactionActiveIsNotReplayed(): void
    {
        // A genuine InnoDB deadlock victim has no transaction left. A 1213 that leaves this connection's
        // transaction active (for example from another connection used inside the callback) does not prove
        // that the owned transaction ended, so it is rolled back once and rethrown, never replayed.
        $pdo = new RetryTransactionPdo();
        $deadlock = PdoErrors::deadlock('other connection');
        $calls = 0;

        $thrown = $this->runCatching(
            new PdoDeadlockRetryTransactionRunner($pdo, 3),
            static function () use ($deadlock, &$calls): never {
                $calls++;

                throw $deadlock;
            },
        );

        self::assertSame($deadlock, $thrown);
        self::assertSame(1, $calls);
        self::assertSame(1, $pdo->beginTransactionCalls);
        self::assertSame(1, $pdo->rollBackCalls);
        self::assertFalse($pdo->inTransaction());
    }

    public function testDeadlockRaisedByCommitIsNotReplayed(): void
    {
        $commitDeadlock = PdoErrors::deadlock('commit');
        $pdo = new RetryTransactionPdo(commitFailures: [$commitDeadlock]);
        $calls = 0;

        $thrown = $this->runCatching(
            new PdoDeadlockRetryTransactionRunner($pdo, 3),
            static function () use (&$calls): string {
                $calls++;

                return 'callback completed';
            },
        );

        self::assertSame($commitDeadlock, $thrown);
        self::assertSame(1, $calls);
        self::assertSame(1, $pdo->beginTransactionCalls);
        self::assertSame(1, $pdo->commitCalls);
        self::assertSame(1, $pdo->rollBackCalls);
    }

    public function testCommitFailureAfterTheCallbackEndedTheTransactionIsNotReplayed(): void
    {
        $pdo = new RetryTransactionPdo();
        $calls = 0;

        $thrown = $this->runCatching(
            new PdoDeadlockRetryTransactionRunner($pdo, 3),
            static function () use ($pdo, &$calls): string {
                $calls++;
                $pdo->commit();

                return 'manually committed';
            },
        );

        self::assertInstanceOf(PDOException::class, $thrown);
        self::assertSame('There is no active transaction', $thrown->getMessage());
        self::assertSame(1, $calls);
        self::assertSame(1, $pdo->beginTransactionCalls);
    }

    public function testBeginFailureIsPropagatedWithoutRunningTheCallback(): void
    {
        $beginFailure = PdoErrors::deadlock('begin');
        $pdo = new RetryTransactionPdo(beginFailures: [$beginFailure]);
        $calls = 0;

        $thrown = $this->runCatching(
            new PdoDeadlockRetryTransactionRunner($pdo, 3),
            static function () use (&$calls): string {
                $calls++;

                return 'never';
            },
        );

        self::assertSame($beginFailure, $thrown);
        self::assertSame(0, $calls);
        self::assertSame(1, $pdo->beginTransactionCalls);
    }

    public function testBeginFailureOfAReplayIsPropagatedAndEndsTheRetries(): void
    {
        $replayBeginFailure = new PDOException('server has gone away');
        $pdo = new RetryTransactionPdo(beginFailures: [null, $replayBeginFailure]);
        $calls = 0;

        $thrown = $this->runCatching(
            new PdoDeadlockRetryTransactionRunner($pdo, 3),
            static function () use ($pdo, &$calls): never {
                $calls++;
                $pdo->endTransactionOnServer();

                throw PdoErrors::deadlock('first attempt');
            },
        );

        self::assertSame($replayBeginFailure, $thrown);
        self::assertSame(1, $calls);
        self::assertSame(2, $pdo->beginTransactionCalls);
        self::assertSame(0, $pdo->commitCalls);
    }

    /** @return array<string, array{int|false|Throwable}> */
    public static function failingProbes(): array
    {
        return [
            'probe throws' => [new PDOException('MySQL server has gone away')],
            'probe returns false' => [false],
        ];
    }

    #[DataProvider('failingProbes')]
    public function testDeadlockIsNotReplayedWhenTheServerStateCannotBeProven(int|false|Throwable $probeResult): void
    {
        $pdo = new RetryTransactionPdo(execResults: [$probeResult]);
        $deadlock = PdoErrors::deadlock();
        $calls = 0;

        $thrown = $this->runCatching(
            new PdoDeadlockRetryTransactionRunner($pdo, 3),
            static function () use ($pdo, $deadlock, &$calls): never {
                $calls++;
                $pdo->endTransactionOnServer();

                throw $deadlock;
            },
        );

        self::assertSame($deadlock, $thrown, 'The original deadlock stays visible; the probe failure never replaces it.');
        self::assertSame(1, $calls);
        self::assertSame(1, $pdo->beginTransactionCalls);
        self::assertSame(['DO 0'], $pdo->executedStatements);
    }

    public function testReplayDecisionProbesTheServerStateWithANoOpStatementBeforeBeginningAgain(): void
    {
        $pdo = new RetryTransactionPdo();
        $calls = 0;
        $statementsSeenByAttempt = [];

        (new PdoDeadlockRetryTransactionRunner($pdo, 3))->run(
            static function () use ($pdo, &$calls, &$statementsSeenByAttempt): string {
                $calls++;
                $statementsSeenByAttempt[] = $pdo->executedStatements;
                if ($calls === 1) {
                    $pdo->endTransactionOnServer();

                    throw PdoErrors::deadlock();
                }

                return 'ok';
            },
        );

        self::assertSame([[], ['DO 0']], $statementsSeenByAttempt);
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
