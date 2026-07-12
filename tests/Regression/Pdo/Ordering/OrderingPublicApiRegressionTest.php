<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Regression\Pdo\Ordering;

use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

final class OrderingPublicApiRegressionTest extends TestCase
{
    public function testScopedOrderingConfigApiMatchesRepositoryReality(): void
    {
        $class = new ReflectionClass(ScopedOrderingConfig::class);

        self::assertSame('Maatify\\Persistence\\Pdo\\Ordering', $class->getNamespaceName());
        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertConstructorParameters($class, [
            ['table', 'string', false, false, null],
            ['scopeColumn', 'string', true, true, null],
            ['idColumn', 'string', false, true, 'id'],
            ['orderColumn', 'string', false, true, 'display_order'],
            ['deletedAtColumn', 'string', true, true, 'deleted_at'],
        ]);

        foreach (['table', 'scopeColumn', 'idColumn', 'orderColumn', 'deletedAtColumn'] as $property) {
            $reflectionProperty = $class->getProperty($property);
            self::assertTrue($reflectionProperty->isPublic());
            self::assertTrue($reflectionProperty->isPromoted());
        }

        self::assertPublicMethod($class, 'quotedTable', [], 'string');
        self::assertPublicMethod($class, 'quotedIdColumn', [], 'string');
        self::assertPublicMethod($class, 'quotedOrderColumn', [], 'string');
        self::assertPublicMethod($class, 'quotedScopeColumn', [], '?string');
        self::assertPublicMethod($class, 'quotedDeletedAtColumn', [], '?string');
    }

    public function testScopedOrderingManagerApiMatchesRepositoryReality(): void
    {
        $class = new ReflectionClass(ScopedOrderingManager::class);

        self::assertSame('Maatify\\Persistence\\Pdo\\Ordering', $class->getNamespaceName());
        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertNull($class->getConstructor());

        self::assertPublicMethod($class, 'getNextPosition', [
            ['pdo', PDO::class, false, false, null],
            ['config', ScopedOrderingConfig::class, false, false, null],
            ['scopeValue', 'int|string', true, true, null],
        ], 'int');
        self::assertPublicMethod($class, 'moveWithinScope', [
            ['pdo', PDO::class, false, false, null],
            ['config', ScopedOrderingConfig::class, false, false, null],
            ['scopeValue', 'int|string', true, false, null],
            ['id', 'int', false, false, null],
            ['newOrder', 'int', false, false, null],
        ], 'bool');
        self::assertPublicMethod($class, 'rowExistsInScope', [
            ['pdo', PDO::class, false, false, null],
            ['config', ScopedOrderingConfig::class, false, false, null],
            ['scopeValue', 'int|string', true, false, null],
            ['id', 'int', false, false, null],
        ], 'bool');
    }

    /** @param list<array{string, string, bool, bool, mixed}> $expected */
    private static function assertConstructorParameters(ReflectionClass $class, array $expected): void
    {
        $constructor = $class->getConstructor();
        self::assertNotNull($constructor);
        self::assertParameters($constructor->getParameters(), $expected);
    }

    /** @param list<array{string, string, bool, bool, mixed}> $expectedParameters */
    private static function assertPublicMethod(ReflectionClass $class, string $methodName, array $expectedParameters, string $returnType): void
    {
        $method = $class->getMethod($methodName);
        self::assertTrue($method->isPublic());
        self::assertParameters($method->getParameters(), $expectedParameters);
        self::assertSame($returnType, self::typeName($method->getReturnType()));
    }

    /**
     * @param list<ReflectionParameter> $parameters
     * @param list<array{string, string, bool, bool, mixed}> $expected
     */
    private static function assertParameters(array $parameters, array $expected): void
    {
        self::assertCount(count($expected), $parameters);

        foreach ($expected as $index => [$name, $type, $allowsNull, $hasDefault, $default]) {
            $parameter = $parameters[$index];
            self::assertSame($name, $parameter->getName());
            self::assertSame($type, self::typeName($parameter->getType()));
            self::assertSame($allowsNull, $parameter->allowsNull());
            self::assertSame($hasDefault, $parameter->isDefaultValueAvailable());
            if ($hasDefault) {
                self::assertSame($default, $parameter->getDefaultValue());
            }
        }
    }

    private static function typeName(?\ReflectionType $type): string
    {
        if ($type instanceof ReflectionNamedType) {
            return ($type->allowsNull() && $type->getName() !== 'mixed' ? '?' : '') . $type->getName();
        }

        self::assertNotNull($type);

        $parts = array_map(static fn (ReflectionNamedType $namedType): string => $namedType->getName(), $type->getTypes());
        sort($parts);

        return implode('|', $parts);
    }
}
