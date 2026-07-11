<?php

declare(strict_types=1);

namespace Maatify\Persistence\Pdo\Pagination;

final readonly class PageRequest
{
    public function __construct(
        public int|string|null $page = null,
        public int|string|null $perPage = null,
        public ?string $sortBy = null,
        public ?string $sortDirection = null,
    ) {
    }
}
