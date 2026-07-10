<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Regression\Exception;

use Maatify\Exceptions\Exception\System\SystemMaatifyException;
use Maatify\Persistence\Exception\InvalidOrderingConfigurationException;
use Maatify\Persistence\Exception\PersistenceException;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InvalidOrderingConfigurationExceptionTest extends TestCase
{
    public function testImplementsPersistenceExceptionContract(): void
    {
        $exception = new InvalidOrderingConfigurationException('Invalid ordering configuration.');

        self::assertInstanceOf(PersistenceException::class, $exception);
    }

    public function testIsClassifiedAsSystemMaatifyException(): void
    {
        $exception = new InvalidOrderingConfigurationException('Invalid ordering configuration.');

        self::assertInstanceOf(SystemMaatifyException::class, $exception);
    }

    #[DataProvider('invalidConfigurationProvider')]
    public function testInvalidConfigurationThrowsInvalidOrderingConfigurationException(string $argumentName, string $value): void
    {
        $this->expectException(InvalidOrderingConfigurationException::class);

        if ($argumentName === 'table') {
            new ScopedOrderingConfig($value);
        } elseif ($argumentName === 'scopeColumn') {
            new ScopedOrderingConfig('items', scopeColumn: $value);
        } elseif ($argumentName === 'idColumn') {
            new ScopedOrderingConfig('items', idColumn: $value);
        } elseif ($argumentName === 'orderColumn') {
            new ScopedOrderingConfig('items', orderColumn: $value);
        } elseif ($argumentName === 'deletedAtColumn') {
            new ScopedOrderingConfig('items', deletedAtColumn: $value);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidConfigurationProvider(): iterable
    {
        yield 'invalid table' => ['table', 'items; DROP TABLE items'];
        yield 'invalid scope column' => ['scopeColumn', 'scope.id'];
        yield 'invalid id column' => ['idColumn', 'id column'];
        yield 'invalid order column' => ['orderColumn', 'display-order'];
        yield 'invalid deleted-at column' => ['deletedAtColumn', '`deleted_at`'];
    }
}
