<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Unit\Pdo\Pagination;

use Maatify\Persistence\Exception\InvalidPaginationConfigurationException;
use Maatify\Persistence\Exception\InvalidPaginationQueryException;
use Maatify\Persistence\Exception\PaginationExecutionException;
use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Persistence\Pdo\Pagination\PageResult;
use Maatify\Persistence\Pdo\Pagination\PaginationConfig;
use Maatify\Persistence\Pdo\Pagination\PdoPaginationQueryDescriptor;
use Maatify\Persistence\Pdo\Pagination\PdoPaginator;
use Maatify\Persistence\Pdo\Pagination\SortDirectionEnum;
use Maatify\Persistence\Pdo\Pagination\SortWhitelist;
use Maatify\Persistence\Tests\Support\Pdo\Pagination\ScriptedPdo;
use Maatify\Persistence\Tests\Support\Pdo\Pagination\ScriptedPdoStatement;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PageRequestTest extends TestCase
{
    public function testDefaultsAndRawInputArePreserved(): void
    {
        $default = new PageRequest();
        self::assertNull($default->page); self::assertNull($default->perPage); self::assertNull($default->sortBy); self::assertNull($default->sortDirection);
        $request = new PageRequest('abc', ' 7x ', ' created_at ', ' desc ');
        self::assertSame('abc', $request->page); self::assertSame(' 7x ', $request->perPage); self::assertSame(' created_at ', $request->sortBy); self::assertSame(' desc ', $request->sortDirection);
        self::assertSame(['__construct'], array_values(array_map(fn($m) => $m->getName(), (new \ReflectionClass(PageRequest::class))->getMethods(\ReflectionMethod::IS_PUBLIC))));
    }
}
