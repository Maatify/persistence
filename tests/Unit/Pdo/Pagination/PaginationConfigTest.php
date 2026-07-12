<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Unit\Pdo\Pagination;

use Maatify\Persistence\Exception\InvalidPaginationConfigurationException;
use Maatify\Persistence\Pdo\Pagination\PaginationConfig;
use Maatify\Persistence\Pdo\Pagination\SortDirectionEnum;
use Maatify\Persistence\Pdo\Pagination\SortWhitelist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaginationConfigTest extends TestCase
{
    public function testValidConfigDefaultsAndCustom(): void
    {
        $whitelist = new SortWhitelist([
            'id' => 'id',
            'created_at' => 'created_at',
            'alias' => 'id',
        ]);

        $default = new PaginationConfig($whitelist, 'created_at', SortDirectionEnum::DESC, 'id', SortDirectionEnum::ASC);

        self::assertSame(20, $default->defaultPerPage);
        self::assertSame(1, $default->minPerPage);
        self::assertSame(200, $default->maxPerPage);

        $custom = new PaginationConfig($whitelist, 'alias', SortDirectionEnum::ASC, 'id', SortDirectionEnum::DESC, 10, 2, 50);

        self::assertSame(10, $custom->defaultPerPage);
        self::assertSame(2, $custom->minPerPage);
        self::assertSame(50, $custom->maxPerPage);
    }

    #[DataProvider('invalidConfig')]
    public function testInvalidConfig(string $default, string $tie, int $defaultPerPage, int $minPerPage, int $maxPerPage): void
    {
        $this->expectException(InvalidPaginationConfigurationException::class);

        new PaginationConfig(
            new SortWhitelist(['id' => 'id', 'created_at' => 'created_at']),
            $default,
            SortDirectionEnum::ASC,
            $tie,
            SortDirectionEnum::ASC,
            $defaultPerPage,
            $minPerPage,
            $maxPerPage,
        );
    }

    /** @return iterable<string, array{string, string, int, int, int}> */
    public static function invalidConfig(): iterable
    {
        yield 'zero min' => ['id', 'id', 20, 0, 200];
        yield 'negative min' => ['id', 'id', 20, -1, 200];
        yield 'max below min' => ['id', 'id', 20, 10, 5];
        yield 'default below min' => ['id', 'id', 1, 2, 10];
        yield 'default above max' => ['id', 'id', 11, 2, 10];
        yield 'malformed default' => ['bad-key', 'id', 5, 1, 10];
        yield 'malformed tie breaker' => ['id', 'bad-key', 5, 1, 10];
        yield 'missing default' => ['missing', 'id', 5, 1, 10];
        yield 'missing tie breaker' => ['id', 'missing', 5, 1, 10];
        yield 'empty default' => ['', 'id', 5, 1, 10];
    }
}
