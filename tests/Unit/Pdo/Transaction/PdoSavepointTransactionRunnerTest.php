<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Unit\Pdo\Transaction;

use Maatify\Persistence\Exception\TransactionExecutionException;
use Maatify\Persistence\Pdo\Transaction\PdoSavepointTransactionRunner;
use Maatify\Persistence\Tests\Support\Pdo\Transaction\SavepointTransactionPdo;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Throwable;

final class PdoSavepointTransactionRunnerTest extends TestCase
{
    public function testNoOuterTransactionUsesOwnedTransactionPathAndNoSavepointSql(): void
    {
        $pdo = new SavepointTransactionPdo();

        $result = (new PdoSavepointTransactionRunner($pdo))->run(
            static fn (): string => 'owned',
        );

        self::assertSame('owned', $result);
        self::assertSame(1, $pdo->beginTransactionCalls);
        self::assertSame(1, $pdo->commitCalls);
        self::assertSame(0, $pdo->rollBackCalls);
        self::assertSame([], $pdo->executedStatements);
        self::assertSame(0, $pdo->execCalls);
        self::assertFalse($pdo->inTransaction());
    }

    public function testNoOuterTransactionFailureUsesOwnedTransactionPathAndNoSavepointSql(): void
    {
        $failure = new RuntimeException('owned failure.');
        $pdo = new SavepointTransactionPdo();

        $thrown = $this->catchThrowable(
            fn () => (new PdoSavepointTransactionRunner($pdo))->run(
                static function () use ($failure): never {
                    throw $failure;
                },
            ),
        );

        self::assertSame($failure, $thrown);
        self::assertSame(1, $pdo->beginTransactionCalls);
        self::assertSame(0, $pdo->commitCalls);
        self::assertSame(1, $pdo->rollBackCalls);
        self::assertSame([], $pdo->executedStatements);
        self::assertSame(0, $pdo->execCalls);
        self::assertFalse($pdo->inTransaction());
    }

    public function testActiveTransactionPreservesScalarArrayObjectAndNullResults(): void
    {
        $object = new stdClass();
        $results = ['scalar', ['array'], $object, null];
        $pdo = new SavepointTransactionPdo(transactionActive: true);
        $runner = new PdoSavepointTransactionRunner($pdo);

        foreach ($results as $expected) {
            $result = $runner->run(static fn () => $expected);

            self::assertSame($expected, $result);
        }

        self::assertSame(0, $pdo->beginTransactionCalls);
        self::assertSame(0, $pdo->commitCalls);
        self::assertSame(0, $pdo->rollBackCalls);
        self::assertTrue($pdo->inTransaction());
        self::assertCount(8, $pdo->executedStatements);
    }

    public function testActiveTransactionUsesExactSavepointNameFormatAndDistinctNames(): void
    {
        $pdo = new SavepointTransactionPdo(transactionActive: true);
        $runner = new PdoSavepointTransactionRunner($pdo);

        $runner->run(static fn (): string => 'first');
        $runner->run(static fn (): string => 'second');

        $names = [
            self::savepointName($pdo->executedStatements[0]),
            self::savepointName($pdo->executedStatements[2]),
        ];

        self::assertSame(55, strlen($names[0]));
        self::assertMatchesRegularExpression(
            '/^maatify_persistence_sp_[0-9a-f]{32}$/',
            $names[0],
        );
        self::assertNotSame($names[0], $names[1]);
    }

    public function testActiveTransactionSuccessReleasesSavepointWithoutTakingOwnership(): void
    {
        $pdo = new SavepointTransactionPdo(transactionActive: true);

        $result = (new PdoSavepointTransactionRunner($pdo))->run(
            static fn (): array => ['participated'],
        );

        self::assertSame(['participated'], $result);
        self::assertSame(['SAVEPOINT ' . self::savepointName($pdo->executedStatements[0]), $pdo->executedStatements[1]], $pdo->executedStatements);
        self::assertStringStartsWith('RELEASE SAVEPOINT ', $pdo->executedStatements[1]);
        self::assertSame(0, $pdo->commitCalls);
        self::assertSame(0, $pdo->rollBackCalls);
        self::assertTrue($pdo->inTransaction());
    }

    public function testActiveTransactionCallbackFailurePreservesExactThrowableAndCleansUp(): void
    {
        $failure = new RuntimeException('callback failure.');
        $pdo = new SavepointTransactionPdo(transactionActive: true);

        $thrown = $this->catchThrowable(
            fn () => (new PdoSavepointTransactionRunner($pdo))->run(
                static function () use ($failure): never {
                    throw $failure;
                },
            ),
        );

        self::assertSame($failure, $thrown);
        self::assertCount(3, $pdo->executedStatements);
        self::assertStringStartsWith('SAVEPOINT ', $pdo->executedStatements[0]);
        self::assertStringStartsWith('ROLLBACK TO SAVEPOINT ', $pdo->executedStatements[1]);
        self::assertStringStartsWith('RELEASE SAVEPOINT ', $pdo->executedStatements[2]);
        self::assertSame(0, $pdo->commitCalls);
        self::assertSame(0, $pdo->rollBackCalls);
        self::assertTrue($pdo->inTransaction());
    }

    public function testSavepointCreationFalseIsTransactionExecutionExceptionAndSkipsCallback(): void
    {
        $callbackCalls = 0;
        $pdo = new SavepointTransactionPdo(
            transactionActive: true,
            execResults: [false],
        );

        $this->expectException(TransactionExecutionException::class);

        try {
            (new PdoSavepointTransactionRunner($pdo))->run(
                static function () use (&$callbackCalls): string {
                    $callbackCalls++;

                    return 'unreachable';
                },
            );
        } finally {
            self::assertSame(0, $callbackCalls);
            self::assertCount(1, $pdo->executedStatements);
            self::assertSame(0, $pdo->commitCalls);
            self::assertSame(0, $pdo->rollBackCalls);
            self::assertTrue($pdo->inTransaction());
        }
    }

    public function testPdoSavepointCreationThrowableIsPropagatedUnchanged(): void
    {
        $failure = new PDOException('savepoint creation failure.');
        $pdo = new SavepointTransactionPdo(
            transactionActive: true,
            execResults: [$failure],
        );

        $thrown = $this->catchThrowable(
            fn () => (new PdoSavepointTransactionRunner($pdo))->run(
                static fn (): string => 'unreachable',
            ),
        );

        self::assertSame($failure, $thrown);
        self::assertCount(1, $pdo->executedStatements);
    }

    public function testRollbackCleanupFailureDoesNotReplaceCallbackThrowable(): void
    {
        $failure = new RuntimeException('callback failure.');
        $pdo = new SavepointTransactionPdo(
            transactionActive: true,
            execResults: [0, false],
        );

        $thrown = $this->catchThrowable(
            fn () => (new PdoSavepointTransactionRunner($pdo))->run(
                static function () use ($failure): never {
                    throw $failure;
                },
            ),
        );

        self::assertSame($failure, $thrown);
        self::assertCount(2, $pdo->executedStatements);
        self::assertStringStartsWith('ROLLBACK TO SAVEPOINT ', $pdo->executedStatements[1]);
        self::assertTrue($pdo->inTransaction());
    }

    public function testReleaseCleanupFailureDoesNotReplaceCallbackThrowable(): void
    {
        $failure = new RuntimeException('callback failure.');
        $pdo = new SavepointTransactionPdo(
            transactionActive: true,
            execResults: [0, 0, false],
        );

        $thrown = $this->catchThrowable(
            fn () => (new PdoSavepointTransactionRunner($pdo))->run(
                static function () use ($failure): never {
                    throw $failure;
                },
            ),
        );

        self::assertSame($failure, $thrown);
        self::assertCount(3, $pdo->executedStatements);
        self::assertTrue($pdo->inTransaction());
    }

    public function testReleaseFailureRemainsVisibleAfterRollbackAttempt(): void
    {
        $releaseFailure = new PDOException('release failure.');
        $pdo = new SavepointTransactionPdo(
            transactionActive: true,
            execResults: [0, $releaseFailure, 0, 0],
        );

        $thrown = $this->catchThrowable(
            fn () => (new PdoSavepointTransactionRunner($pdo))->run(
                static fn (): string => 'successful callback',
            ),
        );

        self::assertSame($releaseFailure, $thrown);
        self::assertCount(4, $pdo->executedStatements);
        self::assertStringStartsWith('ROLLBACK TO SAVEPOINT ', $pdo->executedStatements[2]);
        self::assertStringStartsWith('RELEASE SAVEPOINT ', $pdo->executedStatements[3]);
        self::assertSame(0, $pdo->commitCalls);
        self::assertSame(0, $pdo->rollBackCalls);
        self::assertTrue($pdo->inTransaction());
    }

    public function testSuccessfulCallbackReleaseFalseIsTransactionExecutionException(): void
    {
        $pdo = new SavepointTransactionPdo(
            transactionActive: true,
            execResults: [0, false, 0, 0],
        );

        $thrown = $this->catchThrowable(
            fn () => (new PdoSavepointTransactionRunner($pdo))->run(
                static fn (): string => 'successful callback',
            ),
        );

        self::assertInstanceOf(TransactionExecutionException::class, $thrown);
        self::assertCount(4, $pdo->executedStatements);
        self::assertTrue($pdo->inTransaction());
    }

    public function testReleaseCleanupFailureDoesNotReplaceOriginalReleaseFailure(): void
    {
        $releaseFailure = new PDOException('release failure.');
        $pdo = new SavepointTransactionPdo(
            transactionActive: true,
            execResults: [0, $releaseFailure, 0, false],
        );

        $thrown = $this->catchThrowable(
            fn () => (new PdoSavepointTransactionRunner($pdo))->run(
                static fn (): string => 'successful callback',
            ),
        );

        self::assertSame($releaseFailure, $thrown);
        self::assertCount(4, $pdo->executedStatements);
        self::assertTrue($pdo->inTransaction());
    }

    public function testCallbackCommittingOuterTransactionDoesNotCauseAdditionalFullCommitOrRollback(): void
    {
        $releaseFailure = new PDOException('outer transaction ended after commit.');
        $pdo = new SavepointTransactionPdo(
            transactionActive: true,
            execResults: [0, $releaseFailure],
        );

        $thrown = $this->catchThrowable(
            fn () => (new PdoSavepointTransactionRunner($pdo))->run(
                static function () use ($pdo): string {
                    $pdo->commit();

                    return 'callback result';
                },
            ),
        );

        self::assertSame($releaseFailure, $thrown);
        self::assertSame(1, $pdo->commitCalls);
        self::assertSame(0, $pdo->rollBackCalls);
        self::assertFalse($pdo->inTransaction());
        self::assertCount(2, $pdo->executedStatements);
        self::assertStringStartsWith('RELEASE SAVEPOINT ', $pdo->executedStatements[1]);
    }

    public function testCallbackFullyRollingBackOuterTransactionDoesNotCauseAdditionalFullCommitOrRollback(): void
    {
        $releaseFailure = new PDOException('outer transaction ended.');
        $pdo = new SavepointTransactionPdo(
            transactionActive: true,
            execResults: [0, $releaseFailure],
        );

        $thrown = $this->catchThrowable(
            fn () => (new PdoSavepointTransactionRunner($pdo))->run(
                static function () use ($pdo): string {
                    $pdo->rollBack();

                    return 'callback result';
                },
            ),
        );

        self::assertSame($releaseFailure, $thrown);
        self::assertSame(0, $pdo->commitCalls);
        self::assertSame(1, $pdo->rollBackCalls);
        self::assertFalse($pdo->inTransaction());
        self::assertCount(2, $pdo->executedStatements);
        self::assertStringStartsWith('RELEASE SAVEPOINT ', $pdo->executedStatements[1]);
    }

    public function testCallbackEndingOuterTransactionThenThrowingPreservesExactThrowableWithoutCleanup(): void
    {
        $failure = new RuntimeException('callback failure after ending transaction.');
        $cleanupFailure = new PDOException('cleanup must not run.');
        $pdo = new SavepointTransactionPdo(
            transactionActive: true,
            execResults: [0, $cleanupFailure],
        );

        $thrown = $this->catchThrowable(
            fn () => (new PdoSavepointTransactionRunner($pdo))->run(
                static function () use ($pdo, $failure): never {
                    $pdo->rollBack();

                    throw $failure;
                },
            ),
        );

        self::assertSame($failure, $thrown);
        self::assertSame(0, $pdo->commitCalls);
        self::assertSame(1, $pdo->rollBackCalls);
        self::assertFalse($pdo->inTransaction());
        self::assertCount(1, $pdo->executedStatements);
    }

    public function testRepeatedAndNestedSuccessfulInvocationsUseDistinctSavepoints(): void
    {
        $pdo = new SavepointTransactionPdo(transactionActive: true);
        $runner = new PdoSavepointTransactionRunner($pdo);

        $runner->run(function () use ($runner): string {
            return $runner->run(static fn (): string => 'inner');
        });
        $runner->run(static fn (): string => 'repeated');

        $savepointStatements = array_values(array_filter(
            $pdo->executedStatements,
            static fn (string $statement): bool => str_starts_with($statement, 'SAVEPOINT '),
        ));
        $names = array_map(
            static fn (string $statement): string => substr($statement, strlen('SAVEPOINT ')),
            $savepointStatements,
        );

        self::assertCount(3, $names);
        self::assertCount(3, array_unique($names));
        self::assertSame(0, $pdo->commitCalls);
        self::assertSame(0, $pdo->rollBackCalls);
    }

    public function testNestedFailureCaughtByOuterCallbackAllowsOuterSuccess(): void
    {
        $innerFailure = new RuntimeException('inner failure.');
        $pdo = new SavepointTransactionPdo(transactionActive: true);
        $runner = new PdoSavepointTransactionRunner($pdo);

        $result = $runner->run(function () use ($runner, $innerFailure): string {
            try {
                $runner->run(
                    static function () use ($innerFailure): never {
                        throw $innerFailure;
                    },
                );
            } catch (RuntimeException $caught) {
                self::assertSame($innerFailure, $caught);
            }

            return 'outer success';
        });

        self::assertSame('outer success', $result);
        self::assertCount(5, $pdo->executedStatements);
        self::assertStringStartsWith('ROLLBACK TO SAVEPOINT ', $pdo->executedStatements[2]);
        self::assertStringStartsWith('RELEASE SAVEPOINT ', $pdo->executedStatements[3]);
        self::assertStringStartsWith('RELEASE SAVEPOINT ', $pdo->executedStatements[4]);
        self::assertTrue($pdo->inTransaction());
    }

    public function testNestedFailurePropagatedOutwardRollsBackBothSavepoints(): void
    {
        $innerFailure = new RuntimeException('inner failure.');
        $pdo = new SavepointTransactionPdo(transactionActive: true);
        $runner = new PdoSavepointTransactionRunner($pdo);

        $thrown = $this->catchThrowable(
            fn () => $runner->run(
                static fn () => $runner->run(
                    static function () use ($innerFailure): never {
                        throw $innerFailure;
                    },
                ),
            ),
        );

        self::assertSame($innerFailure, $thrown);
        self::assertCount(6, $pdo->executedStatements);
        self::assertStringStartsWith('ROLLBACK TO SAVEPOINT ', $pdo->executedStatements[2]);
        self::assertStringStartsWith('RELEASE SAVEPOINT ', $pdo->executedStatements[3]);
        self::assertStringStartsWith('ROLLBACK TO SAVEPOINT ', $pdo->executedStatements[4]);
        self::assertStringStartsWith('RELEASE SAVEPOINT ', $pdo->executedStatements[5]);
        self::assertSame(0, $pdo->commitCalls);
        self::assertSame(0, $pdo->rollBackCalls);
        self::assertTrue($pdo->inTransaction());
    }

    /** @param callable(): mixed $callback */
    private function catchThrowable(callable $callback): Throwable
    {
        try {
            $callback();
        } catch (Throwable $throwable) {
            return $throwable;
        }

        self::fail('Expected a Throwable.');
    }

    private static function savepointName(string $statement): string
    {
        self::assertStringStartsWith('SAVEPOINT ', $statement);

        return substr($statement, strlen('SAVEPOINT '));
    }
}
