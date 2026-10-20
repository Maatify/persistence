<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Unit\Pdo\Transaction\Internal;

use Maatify\Persistence\Exception\InvalidTransactionConfigurationException;
use Maatify\Persistence\Pdo\Transaction\Internal\OwnedTransactionExecutor;
use Maatify\Persistence\Tests\Support\Pdo\Transaction\PdoErrors;
use Maatify\Persistence\Tests\Support\Pdo\Transaction\RetryTransactionPdo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

final class OwnedTransactionExecutorTest extends TestCase
{
    /**
     * Expected random-delay requests (inclusive microsecond bounds) for a run that deadlocks on every attempt.
     *
     * @return array<string, array{int, list<array{int, int}>}>
     */
    public static function delaySchedules(): array
    {
        return [
            'no retries: no delay' => [0, []],
            'one retry: immediate, no delay' => [1, []],
            'two retries: immediate, then 0-10 ms' => [2, [[0, 10_000]]],
            'three retries: immediate, then 0-10 ms, then 0-20 ms' => [3, [[0, 10_000], [0, 20_000]]],
        ];
    }

    /** @param list<array{int, int}> $expectedBounds */
    #[DataProvider('delaySchedules')]
    public function testHybridDelaysAreRequestedOnlyBeforeRetriesTwoAndThree(int $retries, array $expectedBounds): void
    {
        $pdo = new RetryTransactionPdo();
        $requested = [];
        $slept = [];
        $inTransactionWhileSleeping = [];
        $executor = new OwnedTransactionExecutor(
            $pdo,
            $retries,
            static function (int $microseconds) use ($pdo, &$slept, &$inTransactionWhileSleeping): void {
                $slept[] = $microseconds;
                $inTransactionWhileSleeping[] = $pdo->inTransaction();
            },
            static function (int $min, int $max) use (&$requested): int {
                $requested[] = [$min, $max];

                return $max;
            },
        );

        $this->catchThrowable(fn () => $executor->run(static function () use ($pdo): never {
            $pdo->endTransactionOnServer();

            throw PdoErrors::deadlock();
        }));

        self::assertSame($expectedBounds, $requested);
        self::assertSame(array_column($expectedBounds, 1), $slept);
        self::assertNotContains(true, $inTransactionWhileSleeping, 'No transaction (and no lock) may be held while waiting.');
        self::assertSame($retries + 1, $pdo->beginTransactionCalls);
    }

    public function testTheDrawnDelayIsTheDelayThatIsSlept(): void
    {
        $pdo = new RetryTransactionPdo();
        $slept = [];
        $draws = [7_321, 15_004];
        $executor = new OwnedTransactionExecutor(
            $pdo,
            3,
            static function (int $microseconds) use (&$slept): void {
                $slept[] = $microseconds;
            },
            static function () use (&$draws): int {
                return (int) array_shift($draws);
            },
        );

        $this->catchThrowable(fn () => $executor->run(static function () use ($pdo): never {
            $pdo->endTransactionOnServer();

            throw PdoErrors::deadlock();
        }));

        self::assertSame([7_321, 15_004], $slept);
    }

    public function testNoDelayIsRequestedWhenTheReplaySucceeds(): void
    {
        $pdo = new RetryTransactionPdo();
        $requested = 0;
        $calls = 0;
        $executor = new OwnedTransactionExecutor(
            $pdo,
            3,
            static function (): void {
            },
            static function () use (&$requested): int {
                $requested++;

                return 0;
            },
        );

        $result = $executor->run(static function () use ($pdo, &$calls): string {
            $calls++;
            if ($calls === 1) {
                $pdo->endTransactionOnServer();

                throw PdoErrors::deadlock();
            }

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(0, $requested, 'Retry #1 is immediate.');
    }

    public function testDefaultDelayDrawUsesTheDocumentedInclusiveBounds(): void
    {
        $pdo = new RetryTransactionPdo();
        $slept = [];
        $executor = new OwnedTransactionExecutor(
            $pdo,
            3,
            static function (int $microseconds) use (&$slept): void {
                $slept[] = $microseconds;
            },
        );

        for ($run = 0; $run < 200; $run++) {
            $this->catchThrowable(fn () => $executor->run(static function () use ($pdo): never {
                $pdo->endTransactionOnServer();

                throw PdoErrors::deadlock();
            }));
        }

        self::assertCount(400, $slept);
        foreach (array_chunk($slept, 2) as [$beforeSecondRetry, $beforeThirdRetry]) {
            self::assertGreaterThanOrEqual(0, $beforeSecondRetry);
            self::assertLessThanOrEqual(10_000, $beforeSecondRetry);
            self::assertGreaterThanOrEqual(0, $beforeThirdRetry);
            self::assertLessThanOrEqual(20_000, $beforeThirdRetry);
        }
    }

    public function testRetryLimitIsValidatedBySharedMechanism(): void
    {
        $this->expectException(InvalidTransactionConfigurationException::class);
        $this->expectExceptionMessage('maxDeadlockRetries must be between 0 and 3, 4 given.');

        new OwnedTransactionExecutor(new RetryTransactionPdo(), 4);
    }

    public function testExecutorIsAnInternalFinalReadonlyClass(): void
    {
        $class = new ReflectionClass(OwnedTransactionExecutor::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
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
