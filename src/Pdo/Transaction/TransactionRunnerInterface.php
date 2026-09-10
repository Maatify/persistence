<?php

declare(strict_types=1);

namespace Maatify\Persistence\Pdo\Transaction;

use PDO;

interface TransactionRunnerInterface
{
    /**
     * @template TResult
     *
     * @param callable(): TResult $callback
     * @return TResult
     */
    public function run(PDO $pdo, callable $callback): mixed;
}
