<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Unit\Pdo\Pagination;

use Maatify\Persistence\Exception\InvalidPaginationConfigurationException;
use Maatify\Persistence\Pdo\Pagination\SortWhitelist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SortWhitelistTest extends TestCase
{
    public function testValidKeysQuotingCaseSensitivityAndInternalCopy(): void
    {
        $input = [
            'created_at' => 'created_at',
            '_created_at' => 'p.created_at',
            'created_at2' => 'catalog.products.created_at',
            'same' => 'created_at',
        ];
        $whitelist = new SortWhitelist($input);
        $input['created_at'] = 'evil';

        self::assertSame('`created_at`', $whitelist->quotedIdentifierFor('created_at'));
        self::assertSame('`p`.`created_at`', $whitelist->quotedIdentifierFor('_created_at'));
        self::assertSame('`catalog`.`products`.`created_at`', $whitelist->quotedIdentifierFor('created_at2'));
        self::assertTrue($whitelist->contains('created_at'));
        self::assertFalse($whitelist->contains('CREATED_AT'));
        self::assertSame('`created_at`', $whitelist->quotedIdentifierFor('same'));
        self::assertFalse((new ReflectionClass(SortWhitelist::class))->hasMethod('sorts'));
    }

    /** @param array<mixed, mixed> $sorts */
    #[DataProvider('invalidSorts')]
    public function testInvalidSorts(array $sorts): void
    {
        $this->expectException(InvalidPaginationConfigurationException::class);

        $this->newWhitelist($sorts);
    }

    /** @return iterable<string, array{array<mixed, mixed>}> */
    public static function invalidSorts(): iterable
    {
        yield 'empty' => [[]];
        yield 'integer key' => [[1 => 'id']];
        yield 'empty key' => [['' => 'id']];
        yield 'starts digit' => [['1a' => 'id']];
        yield 'whitespace key' => [['a b' => 'id']];
        yield 'hyphen key' => [['a-b' => 'id']];
        yield 'dot key' => [['a.b' => 'id']];
        yield 'empty identifier' => [['a' => '']];
        yield 'leading dot' => [['a' => '.id']];
        yield 'trailing dot' => [['a' => 'id.']];
        yield 'middle empty' => [['a' => 'a..b']];
        yield 'too many segments' => [['a' => 'a.b.c.d']];
        yield 'function' => [['a' => 'COUNT(id)']];
        yield 'arithmetic' => [['a' => 'id + 1']];
        yield 'json operator' => [['a' => 'data->x']];
        yield 'case' => [['a' => 'CASE WHEN id THEN id END']];
        yield 'collate' => [['a' => 'name COLLATE utf8mb4_bin']];
        yield 'comma' => [['a' => 'id, name']];
        yield 'direction' => [['a' => 'id DESC']];
        yield 'comment' => [['a' => 'id -- x']];
        yield 'semicolon' => [['a' => 'id;']];
        yield 'limit' => [['a' => 'id LIMIT 1']];
        yield 'offset' => [['a' => 'id OFFSET 1']];
        yield 'non string value' => [['a' => 1]];
        yield 'backticks' => [['a' => '`id`']];
    }

    public function testUnknownLookupThrows(): void
    {
        $this->expectException(InvalidPaginationConfigurationException::class);

        (new SortWhitelist(['id' => 'id']))->quotedIdentifierFor('missing');
    }

    /** @param array<mixed, mixed> $sorts */
    private function newWhitelist(array $sorts): SortWhitelist
    {
        $reflection = new ReflectionClass(SortWhitelist::class);

        /** @var SortWhitelist $whitelist */
        $whitelist = $reflection->newInstanceArgs([$sorts]);

        return $whitelist;
    }
}
