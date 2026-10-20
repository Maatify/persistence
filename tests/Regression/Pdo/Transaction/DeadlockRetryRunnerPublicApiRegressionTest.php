<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Regression\Pdo\Transaction;

use Maatify\Exceptions\Enum\ErrorCodeEnum;
use Maatify\Exceptions\Exception\System\SystemMaatifyException;
use Maatify\Persistence\Exception\InvalidTransactionConfigurationException;
use Maatify\Persistence\Exception\PersistenceException;
use Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetrySavepointTransactionRunner;
use Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetryTransactionRunner;
use Maatify\Persistence\Pdo\Transaction\PdoSavepointTransactionRunner;
use Maatify\Persistence\Pdo\Transaction\PdoTransactionRunner;
use Maatify\Persistence\Pdo\Transaction\SavepointTransactionRunnerInterface;
use Maatify\Persistence\Pdo\Transaction\TransactionRunnerInterface;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Issue #32: the deadlock-retry generation is additive, the shared interfaces
 * are unchanged and not deprecated, and the previous runners stay public,
 * loadable and signature-compatible while being marked deprecated.
 */
final class DeadlockRetryRunnerPublicApiRegressionTest extends TestCase
{
    /** @return array<string, array{class-string, class-string}> */
    public static function newRunners(): array
    {
        return [
            'owned/participating runner' => [PdoDeadlockRetryTransactionRunner::class, TransactionRunnerInterface::class],
            'savepoint runner' => [PdoDeadlockRetrySavepointTransactionRunner::class, SavepointTransactionRunnerInterface::class],
        ];
    }

    /** @return array<string, array{class-string, class-string}> */
    public static function deprecatedRunners(): array
    {
        return [
            'PdoTransactionRunner' => [PdoTransactionRunner::class, PdoDeadlockRetryTransactionRunner::class],
            'PdoSavepointTransactionRunner' => [PdoSavepointTransactionRunner::class, PdoDeadlockRetrySavepointTransactionRunner::class],
        ];
    }

    /**
     * @param class-string $class
     * @param class-string $interface
     */
    #[DataProvider('newRunners')]
    public function testNewRunnerImplementsTheUnchangedInterfaceWithTheApprovedConstructor(string $class, string $interface): void
    {
        $runner = new ReflectionClass($class);

        self::assertTrue($runner->isFinal());
        self::assertTrue($runner->isReadOnly());
        self::assertTrue($runner->implementsInterface($interface));
        self::assertStringNotContainsString('@deprecated', (string) $runner->getDocComment());
        $publicMethods = array_values(array_filter(
            $runner->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn (\ReflectionMethod $method): bool => !$method->isConstructor(),
        ));
        self::assertSame(['run'], array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $publicMethods,
        ));

        $parameters = $runner->getConstructor()?->getParameters() ?? [];
        self::assertSame(['pdo', 'maxDeadlockRetries'], array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $parameters,
        ));
        self::assertSame(PDO::class, self::typeName($parameters[0]->getType()));
        self::assertSame('int', self::typeName($parameters[1]->getType()));
        self::assertTrue($parameters[1]->isDefaultValueAvailable());
        self::assertSame(0, $parameters[1]->getDefaultValue());

        $run = $runner->getMethod('run');
        self::assertSame(['callback'], array_map(
            static fn (ReflectionParameter $parameter): string => $parameter->getName(),
            $run->getParameters(),
        ));
        self::assertSame('callable', self::typeName($run->getParameters()[0]->getType()));
        self::assertSame('mixed', self::typeName($run->getReturnType()));
    }

    /**
     * @param class-string $class
     * @param class-string $replacement
     */
    #[DataProvider('deprecatedRunners')]
    public function testPreviousRunnerIsDeprecatedButKeepsItsPublicContract(string $class, string $replacement): void
    {
        $runner = new ReflectionClass($class);
        $docComment = (string) $runner->getDocComment();

        self::assertStringContainsString('@deprecated', $docComment);
        self::assertStringContainsString(substr($replacement, (int) strrpos($replacement, '\\') + 1), $docComment);
        self::assertTrue($runner->isFinal());
        self::assertTrue($runner->isReadOnly());

        $parameters = $runner->getConstructor()?->getParameters() ?? [];
        self::assertCount(1, $parameters);
        self::assertSame('pdo', $parameters[0]->getName());
        self::assertSame(PDO::class, self::typeName($parameters[0]->getType()));
    }

    public function testSharedInterfacesAreUnchangedAndNotDeprecated(): void
    {
        $runnerInterface = new ReflectionClass(TransactionRunnerInterface::class);
        $savepointInterface = new ReflectionClass(SavepointTransactionRunnerInterface::class);

        self::assertSame(['run'], array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $runnerInterface->getMethods(),
        ));
        self::assertSame([TransactionRunnerInterface::class], $savepointInterface->getInterfaceNames());
        self::assertSame(['run'], array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $savepointInterface->getMethods(),
        ));
        self::assertStringNotContainsString('@deprecated', (string) $runnerInterface->getDocComment());
        self::assertStringNotContainsString('@deprecated', (string) $savepointInterface->getDocComment());
    }

    public function testInvalidTransactionConfigurationExceptionIsAPackageDefinedConfigurationException(): void
    {
        $class = new ReflectionClass(InvalidTransactionConfigurationException::class);
        $exception = new InvalidTransactionConfigurationException('invalid retry limit.');

        self::assertTrue($class->isFinal());
        self::assertInstanceOf(SystemMaatifyException::class, $exception);
        self::assertInstanceOf(PersistenceException::class, $exception);
        self::assertSame(ErrorCodeEnum::MAATIFY_ERROR, $exception->getErrorCode());
    }

    private static function typeName(?\ReflectionType $type): string
    {
        self::assertInstanceOf(ReflectionNamedType::class, $type);

        return $type->getName();
    }
}
