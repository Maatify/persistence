<?php

declare(strict_types=1);

/**
 * Read-only MySQL residue check. It lists every table, view, trigger, routine
 * and event in the configured test database whose name carries a package-owned
 * prefix (see mysql-package-objects.php) and exits 1 if any exists. It never
 * drops or alters anything.
 *
 * Environment: PERSISTENCE_TEST_MYSQL_DSN, PERSISTENCE_TEST_MYSQL_USER and
 * PERSISTENCE_TEST_MYSQL_PASSWORD, the same variables the integration suite uses.
 */

/** @var list<non-empty-string> $prefixes */
$prefixes = require __DIR__ . '/mysql-package-objects.php';

$dsn = getenv('PERSISTENCE_TEST_MYSQL_DSN');
$user = getenv('PERSISTENCE_TEST_MYSQL_USER');
$password = getenv('PERSISTENCE_TEST_MYSQL_PASSWORD');

if (!is_string($dsn) || $dsn === '' || !is_string($user) || $user === '' || !is_string($password)) {
    fwrite(STDERR, "Missing MySQL test environment configuration.\n");
    exit(2);
}

try {
    $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $schema = $pdo->query('SELECT DATABASE()')?->fetchColumn();
} catch (PDOException $exception) {
    fwrite(STDERR, 'Unable to inspect the MySQL test database: ' . $exception->getMessage() . "\n");
    exit(2);
}

if (!is_string($schema) || $schema === '') {
    fwrite(STDERR, "The MySQL test DSN must select a dedicated test database.\n");
    exit(2);
}

$inventories = [
    'tables and views' => 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
    'triggers' => 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?',
    'routines' => 'SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?',
    'events' => 'SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA = ?',
];

$residue = [];

foreach ($inventories as $label => $sql) {
    $statement = $pdo->prepare($sql);
    $statement->execute([$schema]);

    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) {
        $name = (string) $name;
        foreach ($prefixes as $prefix) {
            if (str_starts_with(strtolower($name), $prefix)) {
                $residue[$label][] = $name;
                break;
            }
        }
    }
}

if ($residue !== []) {
    fwrite(STDERR, "MySQL residue detected in schema {$schema}.\n");
    foreach ($residue as $label => $names) {
        sort($names);
        fwrite(STDERR, 'Remaining ' . $label . ': ' . implode(', ', $names) . "\n");
    }
    exit(1);
}

echo "MySQL residue verification passed: no package-owned test objects remain in {$schema}.\n";
