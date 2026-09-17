<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Support\MySql;

use PDO;
use PDOStatement;
use PHPUnit\Framework\AssertionFailedError;

final readonly class TransactionSavepointFixture
{
    public function __construct(private PDO $pdo)
    {
    }

    public function insert(string $payload): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO `' . TransactionSavepointSchemaManager::TABLE . '` (`payload`) VALUES (:payload)',
        );

        if (!$statement instanceof PDOStatement) {
            throw new AssertionFailedError('Unable to prepare transaction savepoint fixture statement.');
        }

        $statement->execute(['payload' => $payload]);

        $id = $this->pdo->lastInsertId();
        if ($id === false || !ctype_digit($id)) {
            throw new AssertionFailedError('Unable to read inserted transaction savepoint fixture id.');
        }

        return (int) $id;
    }

    /** @return list<string> */
    public function payloads(): array
    {
        $statement = $this->pdo->query(
            'SELECT `payload` FROM `' . TransactionSavepointSchemaManager::TABLE . '` ORDER BY `id`',
        );

        if (!$statement instanceof PDOStatement) {
            throw new AssertionFailedError('Unable to query transaction savepoint fixture state.');
        }

        $payloads = $statement->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_map(
            static function (mixed $payload): string {
                if (!is_string($payload)) {
                    throw new AssertionFailedError('Unable to read transaction savepoint fixture state.');
                }

                return $payload;
            },
            $payloads,
        ));
    }
}
