<?php

declare(strict_types=1);

namespace Maatify\Persistence\Pdo\Transaction\Internal;

use Closure;
use Maatify\Persistence\Exception\InvalidTransactionConfigurationException;
use Maatify\Persistence\Exception\TransactionExecutionException;
use PDO;
use PDOException;
use Throwable;

/**
 * Runs a callback in a transaction that this executor begins itself and, when
 * deadlock retries are configured, replays the whole transaction after a MySQL
 * deadlock.
 *
 * Transaction control is fail-closed: a beginTransaction() that returns false
 * stops before the callback, and a commit() that returns false is never
 * reported as success; both throw TransactionExecutionException (after a
 * best-effort rollback for COMMIT). Thrown control failures propagate unchanged.
 *
 * Replay happens only when all of the following hold; otherwise the failure is
 * handled exactly like a non-retrying owned transaction (best-effort rollback,
 * the original Throwable is rethrown unchanged):
 * - the callback (not BEGIN or COMMIT) threw a PDOException whose errorInfo is
 *   SQLSTATE 40001 with driver code 1213;
 * - a retry is still available (at most 3);
 * - the driver still reports the transaction as active when the deadlock
 *   arrives: pdo_mysql derives inTransaction() from the last successful server
 *   reply, so for a victim on this connection it still reports the runner's
 *   transaction (the deadlock came as an error reply). If it already reports
 *   none, the transaction had ended before the deadlock (for example the
 *   callback committed or rolled it back) and is never replayed;
 * - a no-op statement (DO 0) then succeeds and the server reports no active
 *   transaction any more (InnoDB rolled back the whole victim transaction).
 *
 * Residual limit (not detectable without privileged server introspection): a
 * callback that ends the runner's transaction and leaves this connection inside
 * ANOTHER transaction when a deadlock hits it (an explicit new BEGIN, or an
 * implicit commit followed by an implicit transaction under autocommit = 0)
 * looks exactly like a deadlock victim. The callback contract forbids ending
 * the runner-owned transaction (ADR 0004).
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
            if (!$this->pdo->beginTransaction()) {
                throw new TransactionExecutionException(
                    'PDO beginTransaction() failed without throwing; the callback was not run.',
                );
            }

            try {
                $result = $callback();
            } catch (Throwable $throwable) {
                if ($retry < $this->maxDeadlockRetries && $this->isDeadlock($throwable) && $this->deadlockEndedOwnedTransaction()) {
                    $retry++;
                    $this->waitBeforeRetry($retry);

                    continue;
                }

                $this->rollBackIfActive();

                throw $throwable;
            }

            try {
                $committed = $this->pdo->commit();
            } catch (Throwable $throwable) {
                $this->rollBackIfActive();

                throw $throwable;
            }

            if (!$committed) {
                $this->rollBackIfActive();

                throw new TransactionExecutionException(
                    'PDO commit() failed without throwing; the transaction is not reported as committed.',
                );
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
     * Fail-closed attribution of the transaction end to the deadlock (see the
     * class documentation): the driver must still report the runner's
     * transaction (otherwise it had already ended before the deadlock), and
     * after a successful no-op statement the server must report none (otherwise
     * the deadlock did not end it, for example a 1213 raised by another
     * connection used inside the callback). Any failure means: not proven.
     */
    private function deadlockEndedOwnedTransaction(): bool
    {
        try {
            if (!$this->driverReportsTransaction()) {
                return false;
            }

            if ($this->pdo->exec(self::SERVER_STATE_PROBE) === false) {
                return false;
            }

            return !$this->driverReportsTransaction();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The transaction state reported by the driver. It changes with successful
     * statements on the connection (here: the probe), so it is read each time.
     *
     * @phpstan-impure
     */
    private function driverReportsTransaction(): bool
    {
        return $this->pdo->inTransaction();
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
