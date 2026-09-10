<?php

declare(strict_types=1);

namespace Maatify\Persistence\Pdo\Transaction;

interface TransactionRunnerInterface
{
    /**
     * @template TResult
     *
     * @param callable(): TResult $callback
     * @return TResult
     */
    public function run(callable $callback): mixed;
}
