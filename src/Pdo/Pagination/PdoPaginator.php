<?php

declare(strict_types=1);

namespace Maatify\Persistence\Pdo\Pagination;

use Maatify\Persistence\Exception\PaginationExecutionException;
use PDO;
use PDOStatement;

/**
 * @template T of array|object
 */
final readonly class PdoPaginator
{
    private const PUBLIC_KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    private const INTEGER_INPUT_PATTERN = '/^[+-]?[0-9]+$/';
    private const LIMIT_PARAMETER = '__pagination_limit';
    private const OFFSET_PARAMETER = '__pagination_offset';

    /**
     * @template TItem of array|object
     *
     * @param callable(array<string, mixed>): TItem $mapper
     *
     * @return PageResult<TItem>
     */
    public function paginate(
        PDO $pdo,
        PdoPaginationQueryDescriptor $query,
        PageRequest $request,
        PaginationConfig $config,
        callable $mapper,
    ): PageResult {
        $page = $this->normalizePage($request->page);
        $perPage = $this->normalizePerPage($request->perPage, $config);
        $sortBy = $this->resolveSortBy($request->sortBy, $config);
        $sortDirection = $this->resolveSortDirection($request->sortDirection, $config);
        $total = $this->executeCount($pdo, $query->totalSql, $query->totalParams);
        $filtered = $this->executeCount($pdo, $query->filteredCountSql, $query->filteredCountParams);
        $totalPages = $filtered === 0 ? 0 : intdiv($filtered - 1, $perPage) + 1;

        if ($filtered === 0) {
            return new PageResult([], 1, $perPage, $total, $filtered, 0, false, false, $sortBy, $sortDirection);
        }

        if ($page > $totalPages) {
            $page = 1;
        }

        $offset = ($page - 1) * $perPage;
        $sql = $this->buildDataSql($query->dataSql, $config->sortWhitelist->quotedIdentifierFor($sortBy), $sortDirection, $config->sortWhitelist->quotedIdentifierFor($config->tieBreakerSortBy), $config->tieBreakerDirection);
        $statement = $this->prepareStatement($pdo, $sql);
        $this->bindParameters($statement, $query->dataParams);
        $this->bindValue($statement, self::LIMIT_PARAMETER, $perPage);
        $this->bindValue($statement, self::OFFSET_PARAMETER, $offset);
        if (! $statement->execute()) {
            throw new PaginationExecutionException('Failed to execute pagination data query.');
        }

        $data = [];
        while (true) {
            $row = $this->fetchAssociativeOrEof($statement);
            if ($row === false) {
                break;
            }
            $this->assertAssociativeDataRow($row);
            $item = $mapper($row);
            if (! is_array($item) && ! is_object($item)) {
                throw new PaginationExecutionException('Pagination mapper must return array or object.');
            }
            $data[] = $item;
        }

        return new PageResult($data, $page, $perPage, $total, $filtered, $totalPages, $page < $totalPages, $page > 1 && $totalPages > 0, $sortBy, $sortDirection);
    }

    private function normalizePage(int|string|null $value): int
    {
        $parsed = $this->parseIntegerInput($value);
        return $parsed === null || $parsed < 1 ? 1 : $parsed;
    }

    private function normalizePerPage(int|string|null $value, PaginationConfig $config): int
    {
        $parsed = $this->parseIntegerInput($value);
        if ($parsed === null) {
            return $config->defaultPerPage;
        }
        if ($parsed < $config->minPerPage) {
            return $config->minPerPage;
        }
        if ($parsed > $config->maxPerPage) {
            return $config->maxPerPage;
        }
        return $parsed;
    }

    private function parseIntegerInput(int|string|null $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if (! preg_match(self::INTEGER_INPUT_PATTERN, $value)) {
            return null;
        }
        $negative = str_starts_with($value, '-');
        $digits = ltrim($value, '+-');
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            $digits = '0';
        }
        $limit = $negative ? (string) PHP_INT_MIN : (string) PHP_INT_MAX;
        $limitMagnitude = ltrim($limit, '-');
        if (strlen($digits) > strlen($limitMagnitude) || (strlen($digits) === strlen($limitMagnitude) && strcmp($digits, $limitMagnitude) > 0)) {
            return null;
        }
        return (int) (($negative ? '-' : '') . $digits);
    }

    private function resolveSortBy(?string $sortBy, PaginationConfig $config): string
    {
        if ($sortBy === null) {
            return $config->defaultSortBy;
        }
        $sortBy = trim($sortBy);
        if ($sortBy === '' || ! preg_match(self::PUBLIC_KEY_PATTERN, $sortBy) || ! $config->sortWhitelist->contains($sortBy)) {
            return $config->defaultSortBy;
        }
        return $sortBy;
    }

    private function resolveSortDirection(?string $sortDirection, PaginationConfig $config): SortDirectionEnum
    {
        if ($sortDirection === null) {
            return $config->defaultSortDirection;
        }
        return match (strtoupper(trim($sortDirection))) {
            'ASC' => SortDirectionEnum::ASC,
            'DESC' => SortDirectionEnum::DESC,
            default => $config->defaultSortDirection,
        };
    }

    /** @param array<string, string|int|bool|null> $params */
    private function executeCount(PDO $pdo, string $sql, array $params): int
    {
        $statement = $this->prepareStatement($pdo, $sql);
        $this->bindParameters($statement, $params);
        if (! $statement->execute()) {
            throw new PaginationExecutionException('Failed to execute pagination count query.');
        }
        if ($statement->columnCount() !== 1) {
            throw new PaginationExecutionException('Pagination count query must return exactly one column.');
        }
        $row = $this->fetchAssociativeOrEof($statement);
        if ($row === false || count($row) !== 1) {
            throw new PaginationExecutionException('Pagination count query must return exactly one row and one column.');
        }
        if ($this->fetchAssociativeOrEof($statement) !== false) {
            throw new PaginationExecutionException('Pagination count query returned more than one row.');
        }
        return $this->normalizeCountValue(reset($row));
    }

    private function prepareStatement(PDO $pdo, string $sql): PDOStatement
    {
        $statement = $pdo->prepare($sql);
        if ($statement === false) {
            throw new PaginationExecutionException('Failed to prepare pagination query.');
        }
        return $statement;
    }

    /** @param array<string, string|int|bool|null> $params */
    private function bindParameters(PDOStatement $statement, array $params): void
    {
        foreach ($params as $key => $value) {
            $this->bindValue($statement, $key, $value);
        }
    }

    private function bindValue(PDOStatement $statement, string $key, string|int|bool|null $value): void
    {
        if (! $statement->bindValue(':' . $key, $value, $this->pdoParameterType($value))) {
            throw new PaginationExecutionException('Failed to bind pagination parameter.');
        }
    }

    private function pdoParameterType(string|int|bool|null $value): int
    {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            $value === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }

    /** @return array<string, mixed>|false */
    private function fetchAssociativeOrEof(PDOStatement $statement): array|false
    {
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            return $row;
        }
        if ($statement->errorCode() === '00000') {
            return false;
        }
        throw new PaginationExecutionException('Failed to fetch pagination row.');
    }

    /** @param array<mixed, mixed> $row */
    private function assertAssociativeDataRow(array $row): void
    {
        if ($row === []) {
            throw new PaginationExecutionException('Pagination data row must contain at least one column.');
        }
        foreach (array_keys($row) as $key) {
            if (! is_string($key)) {
                throw new PaginationExecutionException('Pagination data row keys must be strings.');
            }
        }
    }

    private function normalizeCountValue(mixed $value): int
    {
        if (is_int($value)) {
            if ($value < 0) {
                throw new PaginationExecutionException('Pagination count cannot be negative.');
            }
            return $value;
        }
        if (! is_string($value) || ! preg_match('/^[0-9]+$/', $value)) {
            throw new PaginationExecutionException('Invalid pagination count value.');
        }
        $digits = ltrim($value, '0');
        if ($digits === '') {
            $digits = '0';
        }
        $max = (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($max) || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw new PaginationExecutionException('Pagination count exceeds PHP integer range.');
        }
        return (int) $digits;
    }

    private function buildDataSql(string $dataSql, string $quotedPrimary, SortDirectionEnum $direction, string $quotedTieBreaker, SortDirectionEnum $tieBreakerDirection): string
    {
        $sql = rtrim($dataSql) . "\nORDER BY " . $quotedPrimary . ' ' . $direction->value;
        if ($quotedPrimary !== $quotedTieBreaker) {
            $sql .= ', ' . $quotedTieBreaker . ' ' . $tieBreakerDirection->value;
        }
        return $sql . "\nLIMIT :" . self::LIMIT_PARAMETER . "\nOFFSET :" . self::OFFSET_PARAMETER;
    }
}
