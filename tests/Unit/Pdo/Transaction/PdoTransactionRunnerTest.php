<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Unit\Pdo\Transaction;

use Maatify\Persistence\Pdo\Transaction\PdoTransactionRunner;
use Maatify\Persistence\Tests\Support\Pdo\Transaction\TransactionPdo;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class PdoTransactionRunnerTest extends TestCase
{
    public function testOwnedTransactionCommitsAndPreservesCallbackResult(): void
    {
        $pdo = new TransactionPdo();
        $callbackCalls = 0;

        $result = (new PdoTransactionRunner())->run(
            $pdo,
            static function () use (&$callbackCalls): array {
                $callbackCalls++;

                return ['committed'];
            },
        );

        self::assertSame(['committed'], $result);
        self::assertSame(1, $callbackCalls);
        self::assertSame(1, $pdo->beginTransactionCalls);
        self::assertSame(1, $pdo->commitCalls);
        self::assertSame(0, $pdo->rollBackCalls);
        self::assertFalse($pdo->inTransaction());
    }

    public function testOwnedTransactionRollsBackAndRethrowsOriginalThrowable(): void
    {
        $pdo = new TransactionPdo();
        $failure = new RuntimeException('owned transaction failure.');

        $thrown = null;
        try {
            (new PdoTransactionRunner())->run(
                $pdo,
                static function () use ($failure): never {
                    throw $failure;
                },
            );
        } catch (Throwable $throwable) {
            $thrown = $throwable;
        }

        self::assertSame($failure, $thrown);
        self::assertSame(1, $pdo->beginTransactionCalls);
        self::assertSame(0, $pdo->commitCalls);
        self::assertSame(1, $pdo->rollBackCalls);
        self::assertFalse($pdo->inTransaction());
    }

    public function testRollbackFailureDoesNotReplaceOriginalThrowable(): void
    {
        $failure = new RuntimeException('callback failure.');
        $rollbackFailure = new RuntimeException('rollback failure.');
        $pdo = new TransactionPdo(rollbackFailure: $rollbackFailure);

        $thrown = null;
        try {
            (new PdoTransactionRunner())->run(
                $pdo,
                static function () use ($failure): never {
                    throw $failure;
                },
            );
        } catch (Throwable $throwable) {
            $thrown = $throwable;
        }

        self::assertSame($failure, $thrown);
        self::assertSame(1, $pdo->rollBackCalls);
        self::assertTrue($pdo->inTransaction());
    }

    public function testExistingTransactionParticipatesWithoutChangingItsOwnership(): void
    {
        $pdo = new TransactionPdo(transactionActive: true);
        $callbackCalls = 0;

        $result = (new PdoTransactionRunner())->run(
            $pdo,
            static function () use (&$callbackCalls): string {
                $callbackCalls++;

                return 'participated';
            },
        );

        self::assertSame('participated', $result);
        self::assertSame(1, $callbackCalls);
        self::assertSame(0, $pdo->beginTransactionCalls);
        self::assertSame(0, $pdo->commitCalls);
        self::assertSame(0, $pdo->rollBackCalls);
        self::assertTrue($pdo->inTransaction());
    }

    public function testFailureInsideExistingTransactionDoesNotRollbackIt(): void
    {
        $pdo = new TransactionPdo(transactionActive: true);
        $failure = new RuntimeException('participating transaction failure.');

        $thrown = null;
        try {
            (new PdoTransactionRunner())->run(
                $pdo,
                static function () use ($failure): never {
                    throw $failure;
                },
            );
        } catch (Throwable $throwable) {
            $thrown = $throwable;
        }

        self::assertSame($failure, $thrown);
        self::assertSame(0, $pdo->beginTransactionCalls);
        self::assertSame(0, $pdo->commitCalls);
        self::assertSame(0, $pdo->rollBackCalls);
        self::assertTrue($pdo->inTransaction());
    }
}
