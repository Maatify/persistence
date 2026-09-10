<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Regression\Pdo\Ordering;

use Maatify\Exceptions\Contracts\ErrorCodeInterface;
use Maatify\Exceptions\Enum\ErrorCodeEnum;
use Maatify\Exceptions\Exception\Unsupported\UnsupportedMaatifyException;
use Maatify\Persistence\Exception\OrderingTransactionException;
use Maatify\Persistence\Exception\PersistenceException;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;
use Maatify\Persistence\Pdo\Transaction\PdoTransactionRunner;
use Maatify\Persistence\Pdo\Transaction\TransactionRunnerInterface;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;

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
            ['nullableScope', 'bool', false, true, false],
            ['updatedAtColumn', 'string', true, true, null],
        ]);

        foreach (['table', 'scopeColumn', 'idColumn', 'orderColumn', 'deletedAtColumn', 'nullableScope', 'updatedAtColumn'] as $property) {
            $reflectionProperty = $class->getProperty($property);
            self::assertTrue($reflectionProperty->isPublic());
            self::assertTrue($reflectionProperty->isPromoted());
        }

        self::assertPublicMethod($class, 'quotedTable', [], 'string');
        self::assertPublicMethod($class, 'quotedIdColumn', [], 'string');
        self::assertPublicMethod($class, 'quotedOrderColumn', [], 'string');
        self::assertPublicMethod($class, 'quotedScopeColumn', [], 'string');
        self::assertPublicMethod($class, 'quotedDeletedAtColumn', [], 'string');
        self::assertPublicMethod($class, 'quotedUpdatedAtColumn', [], 'string');
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
            ['updatedAtValue', 'string', true, true, null],
        ], 'bool');
        self::assertPublicMethod($class, 'rowExistsInScope', [
            ['pdo', PDO::class, false, false, null],
            ['config', ScopedOrderingConfig::class, false, false, null],
            ['scopeValue', 'int|string', true, false, null],
            ['id', 'int', false, false, null],
        ], 'bool');
    }

    public function testPdoTransactionRunnerApiMatchesRepositoryReality(): void
    {
        $class = new ReflectionClass(PdoTransactionRunner::class);

        self::assertSame('Maatify\\Persistence\\Pdo\\Transaction', $class->getNamespaceName());
        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertConstructorParameters($class, [
            ['pdo', PDO::class, false, false, null],
        ]);
        self::assertContains(TransactionRunnerInterface::class, class_implements(PdoTransactionRunner::class));

        self::assertPublicMethod($class, 'run', [
            ['callback', 'callable', false, false, null],
        ], 'mixed');

        $interface = new ReflectionClass(TransactionRunnerInterface::class);
        self::assertTrue($interface->isInterface());
        self::assertPublicMethod($interface, 'run', [
            ['callback', 'callable', false, false, null],
        ], 'mixed');
    }

    public function testOrderingTransactionExceptionRemainsPublicDeprecatedAndCompatible(): void
    {
        self::assertTrue(class_exists(OrderingTransactionException::class));

        $class = new ReflectionClass(OrderingTransactionException::class);

        self::assertSame('Maatify\\Persistence\\Exception', $class->getNamespaceName());
        self::assertTrue($class->isFinal());
        $parentClass = $class->getParentClass();
        if ($parentClass === false) {
            self::fail('Expected OrderingTransactionException to have a parent class.');
        }

        self::assertSame(UnsupportedMaatifyException::class, $parentClass->getName());
        self::assertContains(PersistenceException::class, class_implements(OrderingTransactionException::class));
        self::assertStringContainsString('@deprecated', (string) $class->getDocComment());

        $exception = new OrderingTransactionException();
        self::assertSame(ErrorCodeEnum::UNSUPPORTED_OPERATION, $exception->getErrorCode());
        self::assertFalse($exception->isSafe());

        $defaultErrorCode = $class->getMethod('defaultErrorCode');
        self::assertTrue($defaultErrorCode->isProtected());
        self::assertSame(ErrorCodeInterface::class, self::typeName($defaultErrorCode->getReturnType()));

        $defaultIsSafe = $class->getMethod('defaultIsSafe');
        self::assertTrue($defaultIsSafe->isProtected());
        self::assertSame('bool', self::typeName($defaultIsSafe->getReturnType()));
    }

    public function testOrderingTransactionExceptionIsNotUsedByTheNewOrderingFlow(): void
    {
        $managerFile = (new ReflectionClass(ScopedOrderingManager::class))->getFileName();
        self::assertIsString($managerFile);

        $managerSource = file_get_contents($managerFile);
        self::assertIsString($managerSource);
        self::assertStringNotContainsString('OrderingTransactionException', $managerSource);
    }

    /**
     * @param ReflectionClass<object> $class
     * @param list<array{string, string, bool, bool, mixed}> $expected
     */
    private static function assertConstructorParameters(ReflectionClass $class, array $expected): void
    {
        $constructor = $class->getConstructor();
        self::assertNotNull($constructor);
        self::assertParameters($constructor->getParameters(), $expected);
    }

    /**
     * @param ReflectionClass<object> $class
     * @param list<array{string, string, bool, bool, mixed}> $expectedParameters
     */
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

    private static function typeName(?ReflectionType $type): string
    {
        self::assertNotNull($type);

        if ($type instanceof ReflectionNamedType) {
            return $type->getName();
        }

        if ($type instanceof ReflectionUnionType) {
            $parts = [];
            foreach ($type->getTypes() as $innerType) {
                $name = self::typeName($innerType);
                if ($name !== 'null') {
                    $parts[] = $name;
                }
            }
            sort($parts);

            return implode('|', $parts);
        }

        if ($type instanceof ReflectionIntersectionType) {
            $parts = [];
            foreach ($type->getTypes() as $innerType) {
                $parts[] = self::typeName($innerType);
            }
            sort($parts);

            return implode('&', $parts);
        }

        self::fail('Unsupported reflection type.');
    }

}
