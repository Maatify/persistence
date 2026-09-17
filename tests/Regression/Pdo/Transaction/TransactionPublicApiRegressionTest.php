<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Regression\Pdo\Transaction;

use Maatify\Exceptions\Enum\ErrorCodeEnum;
use Maatify\Exceptions\Exception\System\SystemMaatifyException;
use Maatify\Persistence\Exception\PersistenceException;
use Maatify\Persistence\Exception\TransactionExecutionException;
use Maatify\Persistence\Pdo\Transaction\PdoSavepointTransactionRunner;
use Maatify\Persistence\Pdo\Transaction\PdoTransactionRunner;
use Maatify\Persistence\Pdo\Transaction\SavepointTransactionRunnerInterface;
use Maatify\Persistence\Pdo\Transaction\TransactionRunnerInterface;
use Maatify\Persistence\Tests\Support\Pdo\Transaction\TransactionPdo;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

final class TransactionPublicApiRegressionTest extends TestCase
{
    public function testExistingTransactionRunnerContractRemainsUnchanged(): void
    {
        $interface = new ReflectionClass(TransactionRunnerInterface::class);
        self::assertTrue($interface->isInterface());
        self::assertSame([], $interface->getInterfaceNames());
        self::assertSame(['run'], array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $interface->getMethods(),
        ));

        $runner = new ReflectionClass(PdoTransactionRunner::class);
        self::assertTrue($runner->isFinal());
        self::assertTrue($runner->isReadOnly());
        self::assertSame([TransactionRunnerInterface::class], $runner->getInterfaceNames());
        self::assertSame(PDO::class, self::typeName($runner->getConstructor()?->getParameters()[0]->getType()));
    }

    public function testSavepointRunnerAddsTheApprovedContract(): void
    {
        $interface = new ReflectionClass(SavepointTransactionRunnerInterface::class);
        self::assertTrue($interface->isInterface());
        self::assertSame([TransactionRunnerInterface::class], $interface->getInterfaceNames());

        $method = $interface->getMethod('run');
        self::assertSame(['callback'], array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $method->getParameters(),
        ));
        self::assertSame('mixed', self::typeName($method->getReturnType()));

        $runner = new ReflectionClass(PdoSavepointTransactionRunner::class);
        self::assertTrue($runner->isFinal());
        self::assertTrue($runner->isReadOnly());
        self::assertContains(SavepointTransactionRunnerInterface::class, $runner->getInterfaceNames());
        self::assertSame(PDO::class, self::typeName($runner->getConstructor()?->getParameters()[0]->getType()));
    }

    public function testTransactionExecutionExceptionIsAdditiveAndPackageDefined(): void
    {
        $class = new ReflectionClass(TransactionExecutionException::class);
        $exception = new TransactionExecutionException('control failure.');

        self::assertTrue($class->isFinal());
        self::assertInstanceOf(SystemMaatifyException::class, $exception);
        self::assertInstanceOf(PersistenceException::class, $exception);
        self::assertSame(ErrorCodeEnum::MAATIFY_ERROR, $exception->getErrorCode());
    }

    public function testExistingRunnerStillParticipatesWithoutCommitOrRollback(): void
    {
        $pdo = new TransactionPdo(transactionActive: true);

        self::assertSame('participated', (new PdoTransactionRunner($pdo))->run(
            static fn (): string => 'participated',
        ));
        self::assertSame(0, $pdo->beginTransactionCalls);
        self::assertSame(0, $pdo->commitCalls);
        self::assertSame(0, $pdo->rollBackCalls);
        self::assertTrue($pdo->inTransaction());
    }

    private static function typeName(?\ReflectionType $type): string
    {
        self::assertInstanceOf(ReflectionNamedType::class, $type);

        return $type->getName();
    }
}
