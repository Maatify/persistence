<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Integration\Pdo\Pagination;

use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Persistence\Pdo\Pagination\PdoPaginator;
use Maatify\Persistence\Tests\Support\MySql\PaginationIntegrationTestCase;
use PDO;

final class PdoPaginatorTransactionTest extends PaginationIntegrationTestCase
{
    public function testAttributesUnchangedOutsideTransactionAndCallerTransactionPreserved(): void
    {
        $this->insertMultiTenantDataset();
        $attributes = [
            PDO::ATTR_ERRMODE,
            PDO::ATTR_DEFAULT_FETCH_MODE,
            PDO::ATTR_EMULATE_PREPARES,
            PDO::ATTR_STRINGIFY_FETCHES,
        ];
        $before = [];
        foreach ($attributes as $attribute) {
            $before[$attribute] = $this->pdo()->getAttribute($attribute);
        }

        self::assertFalse($this->pdo()->inTransaction());
        (new PdoPaginator())->paginate(
            $this->pdo(),
            $this->descriptor(),
            new PageRequest(),
            $this->config(),
            static fn (array $row): array => $row,
        );
        self::assertFalse($this->pdo()->inTransaction());

        foreach ($before as $attribute => $value) {
            self::assertSame($value, $this->pdo()->getAttribute($attribute));
            if (is_bool($value) || $value === 0 || $value === 1) {
                self::assertSame((bool) $value, (bool) $this->pdo()->getAttribute($attribute));
            }
        }

        $this->pdo()->beginTransaction();
        try {
            (new PdoPaginator())->paginate(
                $this->pdo(),
                $this->descriptor(),
                new PageRequest(),
                $this->config(),
                static fn (array $row): array => $row,
            );
            self::assertTrue($this->pdo()->inTransaction());
        } finally {
            $this->pdo()->rollBack();
        }
    }
}
