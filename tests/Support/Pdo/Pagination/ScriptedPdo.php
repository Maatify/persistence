<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Support\Pdo\Pagination;

use LogicException;
use PDO;
use PDOStatement;
use Throwable;

final class ScriptedPdo extends PDO
{
    /** @var list<string> */
    public array $preparedSql = [];

    public int $beginTransactionCalls = 0;

    public int $commitCalls = 0;

    public int $rollBackCalls = 0;

    /** @var list<array{attribute: int, value: mixed}> */
    public array $setAttributeCalls = [];

    /** @param list<ScriptedPdoStatement|false|Throwable> $prepareQueue */
    public function __construct(private array $prepareQueue)
    {
    }

    /** @param array<mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        unset($options);

        $this->preparedSql[] = $query;
        if ($this->prepareQueue === []) {
            throw new LogicException('Scripted PDO prepare queue exhausted.');
        }

        $result = array_shift($this->prepareQueue);
        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    public function beginTransaction(): bool
    {
        $this->beginTransactionCalls++;

        return true;
    }

    public function commit(): bool
    {
        $this->commitCalls++;

        return true;
    }

    public function rollBack(): bool
    {
        $this->rollBackCalls++;

        return true;
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        $this->setAttributeCalls[] = [
            'attribute' => $attribute,
            'value' => $value,
        ];

        return true;
    }
}
