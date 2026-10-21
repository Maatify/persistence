<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Support\MySql;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Real MySQL connection that can make a matching control statement fail
 * (exec() returns false or throws, the statement is NOT executed) or make a
 * matching prepare() throw. Used to exercise savepoint-control failures
 * without simulating the database itself.
 */
final class FaultInjectingPdo extends PDO
{
    /** @var list<string> */
    public array $executed = [];

    /** @var list<array{regex: string, mode: 'false'|'throw'}> */
    private array $execFaults = [];

    /** @var list<string> */
    private array $prepareFaults = [];

    /** @param 'false'|'throw' $mode */
    public function failExec(string $regex, string $mode): void
    {
        $this->execFaults[] = ['regex' => $regex, 'mode' => $mode];
    }

    public function failPrepare(string $regex): void
    {
        $this->prepareFaults[] = $regex;
    }

    public function exec(string $statement): int|false
    {
        $this->executed[] = $statement;

        foreach ($this->execFaults as $fault) {
            if (preg_match($fault['regex'], $statement) === 1) {
                if ($fault['mode'] === 'false') {
                    return false;
                }

                throw new PDOException('Injected control statement failure.');
            }
        }

        return parent::exec($statement);
    }

    /** @param array<mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        foreach ($this->prepareFaults as $regex) {
            if (preg_match($regex, $query) === 1) {
                throw new RuntimeException('Injected prepare failure.');
            }
        }

        return parent::prepare($query, $options);
    }

    /** @return list<string> control statements issued so far, in order */
    public function savepointStatements(): array
    {
        return array_values(array_filter(
            $this->executed,
            static fn (string $sql): bool => str_contains($sql, 'SAVEPOINT'),
        ));
    }
}
