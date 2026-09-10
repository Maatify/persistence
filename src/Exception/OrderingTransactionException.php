<?php

declare(strict_types=1);

namespace Maatify\Persistence\Exception;

use Maatify\Exceptions\Contracts\ErrorCodeInterface;
use Maatify\Exceptions\Enum\ErrorCodeEnum;
use Maatify\Exceptions\Exception\Unsupported\UnsupportedMaatifyException;

/**
 * Legacy exception for transaction ownership constraints.
 *
 * @deprecated Retained for backward compatibility with 1.x consumers. Active
 * caller-owned PDO transactions are now supported by moveWithinScope(), so
 * this exception is no longer thrown by that transaction flow.
 */
final class OrderingTransactionException extends UnsupportedMaatifyException implements PersistenceException
{
    protected function defaultErrorCode(): ErrorCodeInterface
    {
        return ErrorCodeEnum::UNSUPPORTED_OPERATION;
    }

    protected function defaultIsSafe(): bool
    {
        return false;
    }
}
