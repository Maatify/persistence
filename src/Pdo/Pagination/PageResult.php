<?php

declare(strict_types=1);

namespace Maatify\Persistence\Pdo\Pagination;

use JsonSerializable;
use Maatify\Persistence\Exception\PaginationExecutionException;

/**
 * @template T of array|object
 */
final readonly class PageResult implements JsonSerializable
{
    private const PUBLIC_KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * @param list<T> $data
     */
    public function __construct(
        public array $data,
        public int $page,
        public int $perPage,
        public int $total,
        public int $filtered,
        public int $totalPages,
        public bool $hasNext,
        public bool $hasPrevious,
        public string $sortBy,
        public SortDirectionEnum $sortDirection,
    ) {
        $this->assertValid();
    }

    /**
     * @return array{data: list<T>, pagination: array{page: int, per_page: int, total: int, filtered: int, total_pages: int, has_next: bool, has_previous: bool, sort_by: string, sort_direction: 'ASC'|'DESC'}}
     */
    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'pagination' => [
                'page' => $this->page,
                'per_page' => $this->perPage,
                'total' => $this->total,
                'filtered' => $this->filtered,
                'total_pages' => $this->totalPages,
                'has_next' => $this->hasNext,
                'has_previous' => $this->hasPrevious,
                'sort_by' => $this->sortBy,
                'sort_direction' => $this->sortDirection->value,
            ],
        ];
    }

    /**
     * @return array{data: list<T>, pagination: array{page: int, per_page: int, total: int, filtered: int, total_pages: int, has_next: bool, has_previous: bool, sort_by: string, sort_direction: 'ASC'|'DESC'}}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function assertValid(): void
    {
        if (! array_is_list($this->data)) {
            throw new PaginationExecutionException('Pagination data must be a list.');
        }
        foreach ($this->data as $item) {
            if (! is_array($item) && ! is_object($item)) {
                throw new PaginationExecutionException('Pagination data item must be array or object.');
            }
        }
        if ($this->page < 1 || $this->perPage < 1 || $this->total < 0 || $this->filtered < 0 || $this->totalPages < 0 || count($this->data) > $this->perPage || ! preg_match(self::PUBLIC_KEY_PATTERN, $this->sortBy)) {
            throw new PaginationExecutionException('Invalid pagination result state.');
        }
        $expectedTotalPages = $this->filtered === 0 ? 0 : intdiv($this->filtered - 1, $this->perPage) + 1;
        if ($this->totalPages !== $expectedTotalPages) {
            throw new PaginationExecutionException('Invalid pagination total pages.');
        }
        if ($this->filtered === 0 && ($this->data !== [] || $this->page !== 1 || $this->totalPages !== 0 || $this->hasNext || $this->hasPrevious)) {
            throw new PaginationExecutionException('Invalid empty pagination result state.');
        }
        if ($this->totalPages > 0 && $this->page > $this->totalPages) {
            throw new PaginationExecutionException('Pagination page exceeds total pages.');
        }
        if ($this->hasNext !== ($this->page < $this->totalPages) || $this->hasPrevious !== ($this->page > 1 && $this->totalPages > 0)) {
            throw new PaginationExecutionException('Invalid pagination navigation state.');
        }
    }
}
