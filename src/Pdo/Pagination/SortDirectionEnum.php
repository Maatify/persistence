<?php

declare(strict_types=1);

namespace Maatify\Persistence\Pdo\Pagination;

enum SortDirectionEnum: string
{
    case ASC = 'ASC';
    case DESC = 'DESC';
}
