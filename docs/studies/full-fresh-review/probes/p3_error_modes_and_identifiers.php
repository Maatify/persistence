<?php

declare(strict_types=1);

/** RC-03 (non-exception error modes), RC-05 (trailing-newline identifiers), DG-04 (native vs emulated prepares). */

require __DIR__ . '/_probe.php';

use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;
use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Persistence\Pdo\Pagination\PaginationConfig;
use Maatify\Persistence\Pdo\Pagination\PdoPaginationQueryDescriptor;
use Maatify\Persistence\Pdo\Pagination\PdoPaginator;
use Maatify\Persistence\Pdo\Pagination\SortDirectionEnum;
use Maatify\Persistence\Pdo\Pagination\SortWhitelist;

echo "-- RC-05: identifiers ending in a newline are accepted\n";
probe_try('ScopedOrderingConfig table "t\n"', fn () => (new ScopedOrderingConfig("t\n"))->quotedTable());
probe_try('SortWhitelist key "id\n"', fn () => (new SortWhitelist(["id\n" => 'id']))->contains("id\n"));
probe_try('SortWhitelist identifier "id\n"', fn () => (new SortWhitelist(['id' => "id\n"]))->quotedIdentifierFor('id'));

echo "-- RC-03: ordering manager outside ERRMODE_EXCEPTION (missing table)\n";
$manager = new ScopedOrderingManager();
$config = new ScopedOrderingConfig('probe_no_such_table', null, deletedAtColumn: null);
foreach ([PDO::ERRMODE_SILENT => 'SILENT', PDO::ERRMODE_WARNING => 'WARNING'] as $mode => $label) {
    $pdo = probe_pdo($mode);
    probe_try('getNextPosition ' . $label, fn () => @$manager->getNextPosition($pdo, $config));
    probe_try('rowExistsInScope ' . $label, fn () => @$manager->rowExistsInScope($pdo, $config, null, 1));
    probe_try('moveWithinScope ' . $label, fn () => @$manager->moveWithinScope($pdo, $config, null, 1, 2));
}

echo "-- DG-04: the same named placeholder twice, native vs emulated prepares; page beyond the last page\n";
foreach ([false, true] as $emulate) {
    $pdo = probe_pdo(PDO::ERRMODE_EXCEPTION, $emulate);
    $pdo->exec('DROP TABLE IF EXISTS probe_pg');
    $pdo->exec('CREATE TABLE probe_pg (id INT PRIMARY KEY, name VARCHAR(20)) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO probe_pg VALUES (1,'a'),(2,'b'),(3,'c')");
    $paginationConfig = new PaginationConfig(new SortWhitelist(['id' => 'id']), 'id', SortDirectionEnum::ASC, 'id', SortDirectionEnum::ASC, 2, 1, 10);
    $repeated = new PdoPaginationQueryDescriptor('SELECT COUNT(*) FROM probe_pg', [], 'SELECT COUNT(*) FROM probe_pg WHERE name = :n OR name = :n', ['n' => 'a'], 'SELECT id, name FROM probe_pg WHERE name = :n OR name = :n', ['n' => 'a']);
    $plain = new PdoPaginationQueryDescriptor('SELECT COUNT(*) FROM probe_pg', [], 'SELECT COUNT(*) FROM probe_pg', [], 'SELECT id, name FROM probe_pg', []);
    probe_try('emulate=' . var_export($emulate, true) . ' repeated :n', fn () => (new PdoPaginator())->paginate($pdo, $repeated, new PageRequest(1, 2), $paginationConfig, fn (array $row): array => $row)->filtered);
    probe_try('emulate=' . var_export($emulate, true) . ' page 99 of 2', fn () => (new PdoPaginator())->paginate($pdo, $plain, new PageRequest(99, 2), $paginationConfig, fn (array $row): array => $row)->page);
    $pdo->exec('DROP TABLE probe_pg');
}
