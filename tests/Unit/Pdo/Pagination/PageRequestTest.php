<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Unit\Pdo\Pagination;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class PageRequestTest extends TestCase
{
    public function testDefaultsAndRawInputArePreserved(): void
    {
        $default = new PageRequest();

        self::assertNull($default->page);
        self::assertNull($default->perPage);
        self::assertNull($default->sortBy);
        self::assertNull($default->sortDirection);

        $request = new PageRequest('abc', ' 7x ', ' created_at ', ' desc ');

        self::assertSame('abc', $request->page);
        self::assertSame(' 7x ', $request->perPage);
        self::assertSame(' created_at ', $request->sortBy);
        self::assertSame(' desc ', $request->sortDirection);
        self::assertSame(
            ['__construct'],
            array_map(
                static fn (ReflectionMethod $method): string => $method->getName(),
                (new ReflectionClass(PageRequest::class))->getMethods(ReflectionMethod::IS_PUBLIC),
            ),
        );
    }
}
