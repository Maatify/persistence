<?php

declare(strict_types=1);

namespace Maatify\Persistence\Pdo\Transaction\Internal;

use Closure;
use Maatify\Persistence\Exception\InvalidTransactionConfigurationException;
use PDO;
use PDOException;
use Throwable;

/**
 * Runs a callback in a transaction that this executor begins itself and, when
 * deadlock retries are configured, replays the whole transaction after a MySQL
 * deadlock.
 *
 * Replay happens only when all of the following hold; otherwise the failure is
 * handled exactly like a non-retrying owned transaction (best-effort rollback,
 * the original Throwable is rethrown unchanged):
 * - the callback (not BEGIN or COMMIT) threw a PDOException whose errorInfo is
 *   SQLSTATE 40001 with driver code 1213;
 * - a retry is still available (at most 3);
 * - a no-op probe statement succeeds and the connection then reports no active
 *   transaction, i.e. the server really ended this executor's transaction
 *   (InnoDB rolls back the whole transaction of a deadlock victim). The probe
 *   is needed because pdo_mysql updates inTransaction() only from successful
 *   server replies, not from the error reply that carried the deadlock.
 *
 * Delays before retry 1, 2 and 3: none, uniform 0-10 ms, uniform 0-20 ms. No
 * transaction (and no lock) is held while waiting.
 *
 * @internal Shared mechanism of the deadlock-retry runners; not part of the public API.
 */
final readonly class OwnedTransactionExecutor
{
    public const MAX_DEADLOCK_RETRIES = 3;

    private const DEADLOCK_SQLSTATE = '40001';
    private const DEADLOCK_DRIVER_CODE = 1213;
    private const SERVER_STATE_PROBE = 'DO 0';

    /** @var Closure(int): void */
    private Closure $sleep;

    /** @var Closure(int, int): int */
    private Closure $randomDelay;

    /**
     * @param (Closure(int): void)|null      $sleep       receives microseconds; defaults to usleep()
     * @param (Closure(int, int): int)|null  $randomDelay inclusive microsecond bounds; defaults to random_int()
     *
     * @throws InvalidTransactionConfigurationException when $maxDeadlockRetries is outside 0..3
     */
    public function __construct(
        private PDO $pdo,
        private int $maxDeadlockRetries,
        ?Closure $sleep = null,
        ?Closure $randomDelay = null,
    ) {
        if ($maxDeadlockRetries < 0 || $maxDeadlockRetries > self::MAX_DEADLOCK_RETRIES) {
            throw new InvalidTransactionConfigurationException(sprintf(
                'maxDeadlockRetries must be between 0 and %d, %d given.',
                self::MAX_DEADLOCK_RETRIES,
                $maxDeadlockRetries,
            ));
        }

        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
        $this->randomDelay = $randomDelay ?? static fn (int $min, int $max): int => random_int($min, $max);
    }

    /**
     * Precondition: the caller has observed that no transaction is active on the connection.
     *
     * @template TResult
     *
     * @param callable(): TResult $callback
     * @return TResult
     */
    public function run(callable $callback): mixed
    {
        $retry = 0;

        while (true) {
            $this->pdo->beginTransaction();

            try {
                $result = $callback();
            } catch (Throwable $throwable) {
                if ($retry < $this->maxDeadlockRetries && $this->isDeadlock($throwable) && $this->serverEndedTransaction()) {
                    $retry++;
                    $this->waitBeforeRetry($retry);

                    continue;
                }

                $this->rollBackIfActive();

                throw $throwable;
            }

            try {
                $this->pdo->commit();
            } catch (Throwable $throwable) {
                $this->rollBackIfActive();

                throw $throwable;
            }

            return $result;
        }
    }

    private function isDeadlock(Throwable $throwable): bool
    {
        if (!$throwable instanceof PDOException) {
            return false;
        }

        $errorInfo = $throwable->errorInfo;

        return is_array($errorInfo)
            && ($errorInfo[0] ?? null) === self::DEADLOCK_SQLSTATE
            && ($errorInfo[1] ?? null) === self::DEADLOCK_DRIVER_CODE;
    }

    /**
     * Fail-closed proof that the deadlock ended this executor's transaction on
     * the server. Any probe failure, or a transaction that is still active
     * afterwards (for example a 1213 raised by another connection used inside
     * the callback), means the transaction boundary is not proven clean.
     */
    private function serverEndedTransaction(): bool
    {
        try {
            if ($this->pdo->exec(self::SERVER_STATE_PROBE) === false) {
                return false;
            }

            return !$this->pdo->inTransaction();
        } catch (Throwable) {
            return false;
        }
    }

    private function waitBeforeRetry(int $retry): void
    {
        $ceilingMicroseconds = match ($retry) {
            1 => 0,
            2 => 10_000,
            default => 20_000,
        };

        if ($ceilingMicroseconds === 0) {
            return;
        }

        ($this->sleep)(($this->randomDelay)(0, $ceilingMicroseconds));
    }

    private function rollBackIfActive(): void
    {
        if (!$this->pdo->inTransaction()) {
            return;
        }

        try {
            $this->pdo->rollBack();
        } catch (Throwable) {
        }
    }
}
