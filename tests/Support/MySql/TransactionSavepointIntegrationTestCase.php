<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Support\MySql;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;

abstract class TransactionSavepointIntegrationTestCase extends TestCase
{
    protected static PDO $pdo;
    protected static PDO $controlPdo;
    protected static TransactionSavepointSchemaManager $schemaManager;

    protected TransactionSavepointFixture $fixture;
    protected TransactionSavepointFixture $controlFixture;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = (new MySqlConnectionFactory())->create();
        self::$controlPdo = (new MySqlConnectionFactory())->create();
        self::assertMySql84(self::$pdo);
        self::$schemaManager = new TransactionSavepointSchemaManager(self::$pdo);
        self::$schemaManager->initialize();
    }

    protected function setUp(): void
    {
        $this->rollBackOpenTransaction(self::$pdo);
        $this->rollBackOpenTransaction(self::$controlPdo);
        self::$schemaManager->reset();
        $this->fixture = new TransactionSavepointFixture(self::$pdo);
        $this->controlFixture = new TransactionSavepointFixture(self::$controlPdo);
    }

    protected function tearDown(): void
    {
        $this->rollBackOpenTransaction(self::$pdo);
        $this->rollBackOpenTransaction(self::$controlPdo);
        self::$schemaManager->reset();
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$pdo)) {
            self::rollBackOpenTransactionStatic(self::$pdo);
        }

        if (isset(self::$controlPdo)) {
            self::rollBackOpenTransactionStatic(self::$controlPdo);
        }

        if (isset(self::$schemaManager)) {
            self::$schemaManager->dropTable();
        }
    }

    protected function pdo(): PDO
    {
        return self::$pdo;
    }

    private static function assertMySql84(PDO $pdo): void
    {
        $statement = $pdo->query('SELECT VERSION()');
        self::assertInstanceOf(PDOStatement::class, $statement);

        $version = $statement->fetchColumn();
        self::assertIsString($version);
        self::assertMatchesRegularExpression('/^8\.4\./', $version);
    }

    private static function rollBackOpenTransactionStatic(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    private function rollBackOpenTransaction(PDO $pdo): void
    {
        self::rollBackOpenTransactionStatic($pdo);
    }
}
