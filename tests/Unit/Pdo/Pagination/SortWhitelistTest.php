<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Unit\Pdo\Pagination;

use Maatify\Persistence\Exception\InvalidPaginationConfigurationException;
use Maatify\Persistence\Exception\InvalidPaginationQueryException;
use Maatify\Persistence\Exception\PaginationExecutionException;
use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Persistence\Pdo\Pagination\PageResult;
use Maatify\Persistence\Pdo\Pagination\PaginationConfig;
use Maatify\Persistence\Pdo\Pagination\PdoPaginationQueryDescriptor;
use Maatify\Persistence\Pdo\Pagination\PdoPaginator;
use Maatify\Persistence\Pdo\Pagination\SortDirectionEnum;
use Maatify\Persistence\Pdo\Pagination\SortWhitelist;
use Maatify\Persistence\Tests\Support\Pdo\Pagination\ScriptedPdo;
use Maatify\Persistence\Tests\Support\Pdo\Pagination\ScriptedPdoStatement;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SortWhitelistTest extends TestCase
{
    public function testValidKeysQuotingCaseSensitivityAndInternalCopy(): void
    {
        $input = ['created_at'=>'created_at','_created_at'=>'p.created_at','created_at2'=>'catalog.products.created_at','same'=>'created_at'];
        $w = new SortWhitelist($input); $input['created_at']='evil';
        self::assertSame('`created_at`', $w->quotedIdentifierFor('created_at'));
        self::assertSame('`p`.`created_at`', $w->quotedIdentifierFor('_created_at'));
        self::assertSame('`catalog`.`products`.`created_at`', $w->quotedIdentifierFor('created_at2'));
        self::assertTrue($w->contains('created_at')); self::assertFalse($w->contains('CREATED_AT'));
        self::assertSame('`created_at`', $w->quotedIdentifierFor('same'));
        self::assertFalse((new \ReflectionClass(SortWhitelist::class))->hasMethod('sorts'));
    }
    #[DataProvider('invalidSorts')] public function testInvalidSorts(array $sorts): void { $this->expectException(InvalidPaginationConfigurationException::class); new SortWhitelist($sorts); }
    public static function invalidSorts(): iterable { foreach ([[], [1=>'id'], [''=>'id'], ['1a'=>'id'], ['a b'=>'id'], ['a-b'=>'id'], ['a.b'=>'id'], ['a'=>''], ['a'=>'.id'], ['a'=>'id.'], ['a'=>'a..b'], ['a'=>'a.b.c.d'], ['a'=>'COUNT(id)'], ['a'=>'id + 1'], ['a'=>'data->x'], ['a'=>'CASE WHEN id THEN id END'], ['a'=>'name COLLATE utf8mb4_bin'], ['a'=>'id, name'], ['a'=>'id DESC'], ['a'=>'id -- x'], ['a'=>'id;'], ['a'=>'id LIMIT 1'], ['a'=>'id OFFSET 1'], ['a'=>1], ['a'=>'`id`']] as $c) yield [$c]; }
    public function testUnknownLookupThrows(): void { $this->expectException(InvalidPaginationConfigurationException::class); (new SortWhitelist(['id'=>'id']))->quotedIdentifierFor('missing'); }
}
