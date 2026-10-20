<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Integration\Pdo\Transaction;

use Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetrySavepointTransactionRunner;
use Maatify\Persistence\Tests\Support\MySql\TransactionSavepointIntegrationTestCase;
use RuntimeException;
use Throwable;

/**
 * Parity on real MySQL: every case of PdoSavepointTransactionRunnerIntegrationTest,
 * run against the new generation configured with three deadlock retries.
 */
final class PdoDeadlockRetrySavepointTransactionRunnerParityIntegrationTest extends TransactionSavepointIntegrationTestCase
{
    public function testNoOuterTransactionSuccessCommitsMutation(): void
    {
        (new PdoDeadlockRetrySavepointTransactionRunner($this->pdo(), 3))->run(
            fn (): int => $this->fixture->insert('owned-success'),
        );

        self::assertFalse($this->pdo()->inTransaction());
        self::assertSame(['owned-success'], $this->controlFixture->payloads());
    }

    public function testNoOuterTransactionFailureRollsBackMutation(): void
    {
        $failure = new RuntimeException('owned transaction failure.');

        $thrown = $this->catchThrowable(
            fn () => (new PdoDeadlockRetrySavepointTransactionRunner($this->pdo(), 3))->run(
                function () use ($failure): never {
                    $this->fixture->insert('owned-failure');

                    throw $failure;
                },
            ),
        );

        self::assertSame($failure, $thrown);
        self::assertFalse($this->pdo()->inTransaction());
        self::assertSame([], $this->fixture->payloads());
        self::assertSame([], $this->controlFixture->payloads());
    }

    public function testSuccessfulActiveOperationRemainsPendingUntilCallerCommits(): void
    {
        $this->pdo()->beginTransaction();

        try {
            $this->fixture->insert('outer-before');
            (new PdoDeadlockRetrySavepointTransactionRunner($this->pdo(), 3))->run(
                fn (): int => $this->fixture->insert('operation-success'),
            );

            self::assertTrue($this->pdo()->inTransaction());
            self::assertSame(['outer-before', 'operation-success'], $this->fixture->payloads());
            self::assertSame([], $this->controlFixture->payloads());

            $this->pdo()->commit();
        } finally {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
        }

        self::assertSame(['outer-before', 'operation-success'], $this->controlFixture->payloads());
    }

    public function testFailedActiveOperationRollsBackOnlyItsMutationAndCallerCanContinueAndCommit(): void
    {
        $failure = new RuntimeException('isolated operation failure.');
        $this->pdo()->beginTransaction();

        try {
            $this->fixture->insert('outer-before');
            $thrown = $this->catchThrowable(
                fn () => (new PdoDeadlockRetrySavepointTransactionRunner($this->pdo(), 3))->run(
                    function () use ($failure): never {
                        $this->fixture->insert('operation-failure');

                        throw $failure;
                    },
                ),
            );

            self::assertSame($failure, $thrown);
            self::assertTrue($this->pdo()->inTransaction());
            self::assertSame(['outer-before'], $this->fixture->payloads());

            $this->fixture->insert('after-isolated-failure');
            $this->pdo()->commit();
        } finally {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
        }

        self::assertSame(['outer-before', 'after-isolated-failure'], $this->controlFixture->payloads());
    }

    public function testCallerCanRollBackAfterSuccessfulIsolatedOperation(): void
    {
        $this->pdo()->beginTransaction();

        try {
            (new PdoDeadlockRetrySavepointTransactionRunner($this->pdo(), 3))->run(
                fn (): int => $this->fixture->insert('operation-success'),
            );

            self::assertTrue($this->pdo()->inTransaction());
            self::assertSame(['operation-success'], $this->fixture->payloads());
            $this->pdo()->rollBack();
        } finally {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
        }

        self::assertFalse($this->pdo()->inTransaction());
        self::assertSame([], $this->controlFixture->payloads());
    }

    public function testNestedSuccessfulOperationsRemainInOuterTransactionUntilCallerCommits(): void
    {
        $runner = new PdoDeadlockRetrySavepointTransactionRunner($this->pdo(), 3);
        $this->pdo()->beginTransaction();

        try {
            $runner->run(function () use ($runner): void {
                $this->fixture->insert('outer-success');
                $runner->run(fn (): int => $this->fixture->insert('inner-success'));
            });

            self::assertTrue($this->pdo()->inTransaction());
            self::assertSame(['outer-success', 'inner-success'], $this->fixture->payloads());
            self::assertSame([], $this->controlFixture->payloads());
            $this->pdo()->commit();
        } finally {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
        }

        self::assertSame(['outer-success', 'inner-success'], $this->controlFixture->payloads());
    }

    public function testNestedFailureCaughtByOuterOperationRollsBackOnlyInnerMutation(): void
    {
        $runner = new PdoDeadlockRetrySavepointTransactionRunner($this->pdo(), 3);
        $innerFailure = new RuntimeException('inner operation failure.');
        $this->pdo()->beginTransaction();

        try {
            $runner->run(function () use ($runner, $innerFailure): void {
                $this->fixture->insert('outer-before-inner-failure');

                $thrown = $this->catchThrowable(
                    fn () => $runner->run(function () use ($innerFailure): never {
                        $this->fixture->insert('inner-failure');

                        throw $innerFailure;
                    }),
                );

                self::assertSame($innerFailure, $thrown);
                self::assertSame(['outer-before-inner-failure'], $this->fixture->payloads());
                $this->fixture->insert('outer-after-inner-failure');
            });

            self::assertTrue($this->pdo()->inTransaction());
            $this->pdo()->commit();
        } finally {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
        }

        self::assertSame(
            ['outer-before-inner-failure', 'outer-after-inner-failure'],
            $this->controlFixture->payloads(),
        );
    }

    public function testPropagatedNestedFailureRollsBackInnerAndOuterSavepointsAndCallerCanCommit(): void
    {
        $runner = new PdoDeadlockRetrySavepointTransactionRunner($this->pdo(), 3);
        $innerFailure = new RuntimeException('propagated inner operation failure.');
        $this->pdo()->beginTransaction();

        try {
            $thrown = $this->catchThrowable(
                fn () => $runner->run(function () use ($runner, $innerFailure): void {
                    $this->fixture->insert('outer-propagated-failure');
                    $runner->run(function () use ($innerFailure): never {
                        $this->fixture->insert('inner-propagated-failure');

                        throw $innerFailure;
                    });
                }),
            );

            self::assertSame($innerFailure, $thrown);
            self::assertTrue($this->pdo()->inTransaction());
            self::assertSame([], $this->fixture->payloads());

            $this->fixture->insert('after-propagated-failure');
            $this->pdo()->commit();
        } finally {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
        }

        self::assertSame(['after-propagated-failure'], $this->controlFixture->payloads());
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
}
