<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Support\MySql;

use PDO;

final readonly class PaginationFixture
{
    public function __construct(private PDO $pdo)
    {
    }

    public function insertItem(
        int $tenantId,
        string $category,
        string $name,
        int $score,
        bool $isActive,
        ?string $nullableCode,
        string $createdAt,
        ?string $deletedAt = null,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO `' . PaginationSchemaManager::TABLE . '` '
            . '(`tenant_id`, `category`, `name`, `score`, `is_active`, `nullable_code`, `created_at`, `deleted_at`) '
            . 'VALUES (:tenant_id, :category, :name, :score, :is_active, :nullable_code, :created_at, :deleted_at)'
        );

        $statement->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':category', $category, PDO::PARAM_STR);
        $statement->bindValue(':name', $name, PDO::PARAM_STR);
        $statement->bindValue(':score', $score, PDO::PARAM_INT);
        $statement->bindValue(':is_active', $isActive, PDO::PARAM_BOOL);
        $statement->bindValue(':nullable_code', $nullableCode, $nullableCode === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->bindValue(':created_at', $createdAt, PDO::PARAM_STR);
        $statement->bindValue(':deleted_at', $deletedAt, $deletedAt === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }
}
