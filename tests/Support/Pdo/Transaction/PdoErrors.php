<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Support\Pdo\Transaction;

use PDOException;

/**
 * Builds PDOExceptions with an explicit errorInfo, shaped like those raised by
 * pdo_mysql in exception mode ([SQLSTATE, driver code, driver message]).
 */
final class PdoErrors
{
    public static function deadlock(string $label = 'deadlock'): PDOException
    {
        return self::driverError('40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction (' . $label . ')');
    }

    public static function driverError(string $sqlState, int $driverCode, string $message = 'driver error'): PDOException
    {
        $exception = new PDOException(sprintf('SQLSTATE[%s]: %d %s', $sqlState, $driverCode, $message));
        $exception->errorInfo = [$sqlState, $driverCode, $message];

        return $exception;
    }
}
