<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Support\Pdo\Pagination;

use LogicException;
use PDO;
use PDOStatement;
use Throwable;

final class ScriptedPdoStatement extends PDOStatement
{
    /** @var list<array{parameter: string, value: mixed, type: int}> */
    public array $bindCalls = [];

    public int $executeCalls = 0;

    public int $fetchCalls = 0;

    /**
     * @param list<mixed> $fetchQueue
     * @param array<string, bool|Throwable> $bindResults
     */
    public function __construct(
        private int $columns = 1,
        private array $fetchQueue = [],
        private bool|Throwable $executeResult = true,
        private ?string $statementErrorCode = '00000',
        private array $bindResults = [],
    ) {
    }

    public static function count(mixed $value): self
    {
        return new self(1, [['total_count' => $value], false]);
    }

    /** @param list<array<string, mixed>> $rows */
    public static function data(array $rows): self
    {
        return new self($rows === [] ? 1 : count($rows[0]), [...$rows, false]);
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $parameter = (string) $param;
        $this->bindCalls[] = [
            'parameter' => $parameter,
            'value' => $value,
            'type' => $type,
        ];

        $result = $this->bindResults[$parameter] ?? true;
        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    /** @param array<mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        unset($params);

        $this->executeCalls++;
        if ($this->executeResult instanceof Throwable) {
            throw $this->executeResult;
        }

        return $this->executeResult;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        unset($mode, $cursorOrientation, $cursorOffset);

        $this->fetchCalls++;
        if ($this->fetchQueue === []) {
            throw new LogicException('Scripted PDO statement fetch queue exhausted.');
        }

        $result = array_shift($this->fetchQueue);
        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    public function columnCount(): int
    {
        return $this->columns;
    }

    public function errorCode(): ?string
    {
        return $this->statementErrorCode;
    }
}
