<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Unit\Pdo\Ordering;

use Maatify\Persistence\Exception\TransactionExecutionException;
use Maatify\Persistence\Pdo\Ordering\Internal\OperationSavepoint;
use Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetrySavepointTransactionRunner;
use Maatify\Persistence\Tests\Support\Pdo\Transaction\SavepointTransactionPdo;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * The ordering helper reuses the ADR 0003 conventions of the savepoint runner:
 * the same scripted exec() scenarios must give the same statement sequence and
 * the same exception precedence through the helper and through
 * PdoDeadlockRetrySavepointTransactionRunner. The intended differences are
 * covered by their own tests: fail-closed `false` (undoOrFail), no owned-path
 * switch, and the dead-transaction guard.
 */
final class OperationSavepointTest extends TestCase
{
    public function testSavepointNameFormat(): void
    {
        $pdo = new SavepointTransactionPdo(transactionActive: true);

        OperationSavepoint::begin($pdo)->releaseOrUndo();

        self::assertCount(2, $pdo->executedStatements);
        self::assertMatchesRegularExpression('/^SAVEPOINT maatify_persistence_sp_[0-9a-f]{32}$/', $pdo->executedStatements[0]);
        self::assertSame(
            'RELEASE SAVEPOINT ' . substr($pdo->executedStatements[0], strlen('SAVEPOINT ')),
            $pdo->executedStatements[1],
        );
    }

    /**
     * @return iterable<string, array{list<int|false|Throwable>, 'success'|'failure'}>
     */
    public static function scenarios(): iterable
    {
        $failure = new PDOException('control failure');

        yield 'success' => [[0, 0], 'success'];
        yield 'callback failure, cleanup succeeds' => [[0, 0, 0], 'failure'];
        yield 'callback failure, ROLLBACK TO false' => [[0, false], 'failure'];
        yield 'callback failure, ROLLBACK TO throws' => [[0, $failure], 'failure'];
        yield 'callback failure, RELEASE after ROLLBACK TO false' => [[0, 0, false], 'failure'];
        yield 'RELEASE false' => [[0, false, 0, 0], 'success'];
        yield 'RELEASE throws' => [[0, $failure, 0, 0], 'success'];
        yield 'RELEASE false, cleanup fails' => [[0, false, false], 'success'];
        yield 'SAVEPOINT false' => [[false], 'success'];
        yield 'SAVEPOINT throws' => [[$failure], 'failure'];
    }

    /**
     * @param list<int|false|Throwable> $execResults
     * @param 'success'|'failure' $callbackOutcome
     *
     */
    #[DataProvider('scenarios')]
    public function testSameStatementSequenceAndExceptionPrecedenceAsTheSavepointRunner(array $execResults, string $callbackOutcome): void
    {
        $callbackFailure = new RuntimeException('callback failure');

        $runnerPdo = new SavepointTransactionPdo(transactionActive: true, execResults: $execResults);
        $runnerThrown = $this->catchThrowable(
            static fn () => (new PdoDeadlockRetrySavepointTransactionRunner($runnerPdo))->run(
                static function () use ($callbackOutcome, $callbackFailure): bool {
                    if ($callbackOutcome === 'failure') {
                        throw $callbackFailure;
                    }

                    return true;
                },
            ),
        );

        $helperPdo = new SavepointTransactionPdo(transactionActive: true, execResults: $execResults);
        $helperThrown = $this->catchThrowable(
            static function () use ($helperPdo, $callbackOutcome, $callbackFailure): void {
                $savepoint = OperationSavepoint::begin($helperPdo);

                if ($callbackOutcome === 'failure') {
                    $savepoint->cleanupAfterFailure();

                    throw $callbackFailure;
                }

                $savepoint->releaseOrUndo();
            },
        );

        self::assertSame(
            $this->normalize($runnerPdo->executedStatements),
            $this->normalize($helperPdo->executedStatements),
        );
        self::assertSame($runnerThrown === null, $helperThrown === null);

        if ($runnerThrown !== null && $helperThrown !== null) {
            self::assertSame($runnerThrown::class, $helperThrown::class);
            self::assertSame($runnerThrown->getMessage(), $helperThrown->getMessage());
        }

        self::assertSame(0, $helperPdo->beginTransactionCalls + $helperPdo->commitCalls + $helperPdo->rollBackCalls);
    }

    public function testUndoOrFailRollsBackAndReleasesInOrder(): void
    {
        $pdo = new SavepointTransactionPdo(transactionActive: true);

        OperationSavepoint::begin($pdo)->undoOrFail();

        self::assertSame(['SAVEPOINT SP', 'ROLLBACK TO SAVEPOINT SP', 'RELEASE SAVEPOINT SP'], $this->normalize($pdo->executedStatements));
    }

    public function testUndoOrFailPropagatesAFalseRollbackToInsteadOfSwallowingIt(): void
    {
        $pdo = new SavepointTransactionPdo(transactionActive: true, execResults: [0, false]);
        $savepoint = OperationSavepoint::begin($pdo);

        $this->expectException(TransactionExecutionException::class);

        $savepoint->undoOrFail();
    }

    public function testUndoOrFailPropagatesAThrowingRollbackTo(): void
    {
        $failure = new PDOException('rollback to failed');
        $pdo = new SavepointTransactionPdo(transactionActive: true, execResults: [0, $failure]);
        $savepoint = OperationSavepoint::begin($pdo);

        self::assertSame($failure, $this->catchThrowable(static fn () => $savepoint->undoOrFail()));
    }

    public function testUndoOrFailIgnoresAFailingReleaseAfterASuccessfulRollbackTo(): void
    {
        $pdo = new SavepointTransactionPdo(transactionActive: true, execResults: [0, 0, false]);

        OperationSavepoint::begin($pdo)->undoOrFail();

        self::assertCount(3, $pdo->executedStatements);
    }

    public function testBeginFailsBeforeAnyMutationWhenTheDriverReportsNoActiveTransaction(): void
    {
        $pdo = new SavepointTransactionPdo(transactionActive: false);

        $this->expectException(TransactionExecutionException::class);

        try {
            OperationSavepoint::begin($pdo);
        } finally {
            self::assertSame(['SAVEPOINT SP'], $this->normalize($pdo->executedStatements));
            self::assertSame(0, $pdo->beginTransactionCalls + $pdo->commitCalls + $pdo->rollBackCalls);
        }
    }

    public function testBeginFailsWhenTheSavepointReplyRevealsAnEndedTransaction(): void
    {
        $pdo = new class (true) extends \PDO {
            /** @var list<string> */
            public array $executed = [];

            public function __construct(private bool $active)
            {
            }

            public function inTransaction(): bool
            {
                return $this->active;
            }

            public function exec(string $statement): int
            {
                $this->executed[] = $statement;
                $this->active = false;

                return 0;
            }
        };

        try {
            OperationSavepoint::begin($pdo);
            self::fail('Expected the dead-transaction guard.');
        } catch (TransactionExecutionException) {
        }

        self::assertCount(1, $pdo->executed);
    }

    /**
     * @param list<string> $statements
     *
     * @return list<string>
     */
    private function normalize(array $statements): array
    {
        return array_map(
            static fn (string $sql): string => (string) preg_replace('/maatify_persistence_sp_[0-9a-f]{32}/', 'SP', $sql),
            $statements,
        );
    }

    private function catchThrowable(callable $callback): ?Throwable
    {
        try {
            $callback();
        } catch (Throwable $throwable) {
            return $throwable;
        }

        return null;
    }
}
