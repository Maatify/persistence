<?php

declare(strict_types=1);

namespace Maatify\Persistence\Pdo\Pagination;

use Maatify\Persistence\Exception\InvalidPaginationConfigurationException;

final readonly class PaginationConfig
{
    private const PUBLIC_KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public function __construct(
        public SortWhitelist $sortWhitelist,
        public string $defaultSortBy,
        public SortDirectionEnum $defaultSortDirection,
        public string $tieBreakerSortBy,
        public SortDirectionEnum $tieBreakerDirection,
        public int $defaultPerPage = 20,
        public int $minPerPage = 1,
        public int $maxPerPage = 200,
    ) {
        if ($this->minPerPage < 1 || $this->maxPerPage < $this->minPerPage || $this->defaultPerPage < $this->minPerPage || $this->defaultPerPage > $this->maxPerPage) {
            throw new InvalidPaginationConfigurationException('Invalid pagination per-page configuration.');
        }
        if (! preg_match(self::PUBLIC_KEY_PATTERN, $this->defaultSortBy) || ! $this->sortWhitelist->contains($this->defaultSortBy)) {
            throw new InvalidPaginationConfigurationException('Invalid pagination default sort key.');
        }
        if (! preg_match(self::PUBLIC_KEY_PATTERN, $this->tieBreakerSortBy) || ! $this->sortWhitelist->contains($this->tieBreakerSortBy)) {
            throw new InvalidPaginationConfigurationException('Invalid pagination tie-breaker sort key.');
        }
    }
}
