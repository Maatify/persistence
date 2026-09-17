<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;
use Maatify\Persistence\Pdo\Pagination\PageRequest;
use Maatify\Persistence\Pdo\Pagination\PaginationConfig;
use Maatify\Persistence\Pdo\Pagination\PdoPaginationQueryDescriptor;
use Maatify\Persistence\Pdo\Pagination\PdoPaginator;
use Maatify\Persistence\Pdo\Pagination\SortDirectionEnum;
use Maatify\Persistence\Pdo\Pagination\SortWhitelist;
use Maatify\Persistence\Pdo\Transaction\PdoSavepointTransactionRunner;

$repositoryRoot = dirname(__DIR__, 2);

if (($argv[1] ?? null) === '--single-run') {
    runSingleConsumerVerification($repositoryRoot, $argv[2] ?? '1');

    exit(0);
}

for ($run = 1; $run <= 2; $run++) {
    runCommand([PHP_BINARY, __FILE__, '--single-run', (string) $run], $repositoryRoot);
}

echo "Consumer Verification Harness passed: two clean Composer consumer and MySQL runs completed.\n";

/**
 * @param string $repositoryRoot
 * @param string $runLabel
 */
function runSingleConsumerVerification(string $repositoryRoot, string $runLabel): void
{
    $consumerRoot = sys_get_temp_dir() . '/maatify-persistence-consumer-' . bin2hex(random_bytes(8));
    $table = 'maa_persistence_consumer_' . bin2hex(random_bytes(8));
    $pdo = createPdo();
    $tableCreated = false;

    try {
        if (! mkdir($consumerRoot, 0700, true) && ! is_dir($consumerRoot)) {
            throw new RuntimeException('Unable to create a clean consumer root.');
        }

        $consumerComposer = [
            'name' => 'maatify/persistence-consumer-verification',
            'description' => 'External consumer verification for maatify/persistence.',
            'repositories' => [
                [
                    'type' => 'path',
                    'url' => $repositoryRoot,
                    'options' => ['symlink' => false],
                ],
            ],
            'require' => [
                'ext-pdo' => '*',
                'maatify/persistence' => '*',
                'php' => '>=8.2',
            ],
            'minimum-stability' => 'dev',
            'prefer-stable' => true,
            'config' => [
                'allow-plugins' => false,
            ],
        ];
        $composerJson = json_encode(
            $consumerComposer,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;

        if (file_put_contents($consumerRoot . '/composer.json', $composerJson) === false) {
            throw new RuntimeException('Unable to write the clean consumer composer.json.');
        }

        runCommand(
            [
                composerBinary(),
                'install',
                '--no-interaction',
                '--prefer-dist',
                '--no-progress',
                '--no-ansi',
                '--no-scripts',
            ],
            $consumerRoot,
        );

        require $consumerRoot . '/vendor/autoload.php';

        $installedPath = InstalledVersions::getInstallPath('maatify/persistence');
        if (! is_string($installedPath) || $installedPath === '') {
            throw new RuntimeException('Composer did not install maatify/persistence as a dependency.');
        }

        if (is_link($installedPath) || realpath($installedPath) === realpath($repositoryRoot)) {
            throw new RuntimeException('The consumer dependency must be installed without a source-tree symlink.');
        }

        if (! class_exists(ScopedOrderingManager::class)) {
            throw new RuntimeException('The installed package production autoload is unavailable.');
        }

        $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        $pdo->exec(
            'CREATE TABLE ' . $table . ' (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT "Consumer-owned row identity.",
                scope_key VARCHAR(64) NOT NULL COMMENT "Consumer-owned ordering scope.",
                display_order INT NOT NULL COMMENT "Consumer-owned mutable ordering position.",
                deleted_at DATETIME NULL DEFAULT NULL COMMENT "Optional consumer-owned soft-delete timestamp.",
                name VARCHAR(128) NOT NULL COMMENT "Consumer-visible row name.",
                PRIMARY KEY (id),
                KEY idx_consumer_scope_order (scope_key, display_order),
                KEY idx_consumer_scope_deleted (scope_key, deleted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT="Temporary consumer verification rows."',
        );
        $tableCreated = true;

        $insert = requireStatement(
            $pdo->prepare(
                'INSERT INTO ' . $table . ' (scope_key, display_order, name)
                 VALUES (:scope_key, :display_order, :name)',
            ),
            'consumer insert',
        );

        $ids = [];
        foreach ([1, 2, 3] as $order) {
            $insert->execute([
                'scope_key' => 'consumer',
                'display_order' => $order,
                'name' => 'item-' . $order,
            ]);
            $id = $pdo->lastInsertId();
            if ($id === false || ! ctype_digit($id)) {
                throw new RuntimeException('Unable to read the consumer row identity.');
            }
            $ids[] = (int) $id;
        }

        $orderingConfig = new ScopedOrderingConfig(
            table: $table,
            scopeColumn: 'scope_key',
            idColumn: 'id',
            orderColumn: 'display_order',
            deletedAtColumn: 'deleted_at',
        );
        $ordering = new ScopedOrderingManager();

        expectSame(4, $ordering->getNextPosition($pdo, $orderingConfig, 'consumer'), 'next consumer position');
        expectTrue(
            $ordering->rowExistsInScope($pdo, $orderingConfig, 'consumer', $ids[0]),
            'consumer row existence',
        );

        $savepointRunner = new PdoSavepointTransactionRunner($pdo);
        $pdo->beginTransaction();

        try {
            executeStatement(
                $pdo->prepare(
                    'UPDATE ' . $table . ' SET name = :name WHERE id = :id',
                ),
                ['name' => 'outer-name', 'id' => $ids[0]],
                'outer consumer update',
            );

            $callbackFailure = new RuntimeException('consumer savepoint callback failure.');
            $thrown = null;

            try {
                $savepointRunner->run(function () use ($pdo, $table, $ids, $callbackFailure): never {
                    executeStatement(
                        $pdo->prepare(
                            'UPDATE ' . $table . ' SET name = :name WHERE id = :id',
                        ),
                        ['name' => 'rolled-back-name', 'id' => $ids[0]],
                        'isolated consumer update',
                    );

                    throw $callbackFailure;
                });
            } catch (Throwable $throwable) {
                $thrown = $throwable;
            }

            expectSame($callbackFailure, $thrown, 'original savepoint callback Throwable');
            expectTrue($pdo->inTransaction(), 'caller-owned transaction remains active');
            expectSame(
                'outer-name',
                readScalar($pdo, 'SELECT name FROM ' . $table . ' WHERE id = ' . (int) $ids[0]),
                'savepoint rollback state',
            );

            $savepointRunner->run(
                fn (): bool => $ordering->moveWithinScope($pdo, $orderingConfig, 'consumer', $ids[2], 1),
            );
            $pdo->commit();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        expectSame(
            'outer-name',
            readScalar($pdo, 'SELECT name FROM ' . $table . ' WHERE id = ' . (int) $ids[0]),
            'committed outer transaction state',
        );

        $paginationConfig = new PaginationConfig(
            sortWhitelist: new SortWhitelist([
                'order' => 'display_order',
                'id' => 'id',
            ]),
            defaultSortBy: 'order',
            defaultSortDirection: SortDirectionEnum::ASC,
            tieBreakerSortBy: 'id',
            tieBreakerDirection: SortDirectionEnum::ASC,
            defaultPerPage: 10,
            minPerPage: 1,
            maxPerPage: 10,
        );
        $paginationQuery = new PdoPaginationQueryDescriptor(
            totalSql: 'SELECT COUNT(*) AS total_count FROM ' . $table . ' WHERE scope_key = :total_scope',
            totalParams: ['total_scope' => 'consumer'],
            filteredCountSql: 'SELECT COUNT(*) AS filtered_count FROM ' . $table . ' WHERE scope_key = :filtered_scope AND deleted_at IS NULL',
            filteredCountParams: ['filtered_scope' => 'consumer'],
            dataSql: 'SELECT id, name, display_order FROM ' . $table . ' WHERE scope_key = :data_scope AND deleted_at IS NULL',
            dataParams: ['data_scope' => 'consumer'],
        );
        $result = (new PdoPaginator())->paginate(
            pdo: $pdo,
            query: $paginationQuery,
            request: new PageRequest(page: 1, perPage: 10, sortBy: 'order', sortDirection: 'ASC'),
            config: $paginationConfig,
            mapper: static fn (array $row): array => $row,
        );

        expectSame(3, $result->total, 'consumer total count');
        expectSame(3, $result->filtered, 'consumer filtered count');
        expectSame(3, count($result->data), 'consumer page size');
        $first = $result->data[0] ?? null;
        if (! is_array($first)) {
            throw new RuntimeException('Consumer pagination returned an invalid first row.');
        }
        $firstId = $first['id'] ?? null;
        if (! is_int($firstId) && ! is_string($firstId)) {
            throw new RuntimeException('Consumer pagination returned an invalid row identity.');
        }
        if (is_string($firstId) && ! ctype_digit($firstId)) {
            throw new RuntimeException('Consumer pagination returned a non-integer row identity.');
        }
        expectSame($ids[2], (int) $firstId, 'consumer ordering result');

        echo 'Consumer run ' . $runLabel . " passed.\n";
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($tableCreated) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
        removeDirectory($consumerRoot);
    }
}

function createPdo(): PDO
{
    $dsn = requiredEnvironment('PERSISTENCE_TEST_MYSQL_DSN');
    $user = requiredEnvironment('PERSISTENCE_TEST_MYSQL_USER');
    $password = getenv('PERSISTENCE_TEST_MYSQL_PASSWORD');

    if (! is_string($password)) {
        $password = '';
    }

    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('Consumer verification requires the MySQL PDO driver.');
    }

    return $pdo;
}

/**
 * @return non-empty-string
 */
function requiredEnvironment(string $name): string
{
    $value = getenv($name);

    if (! is_string($value) || $value === '') {
        throw new RuntimeException('Missing required environment variable ' . $name . '.');
    }

    return $value;
}

/**
 * @return non-empty-string
 */
function composerBinary(): string
{
    $value = getenv('COMPOSER_BIN');

    return is_string($value) && $value !== '' ? $value : 'composer';
}

/**
 * @param list<string> $command
 */
function runCommand(array $command, string $workingDirectory): void
{
    $pipes = [];
    $process = proc_open(
        $command,
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $workingDirectory,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start command: ' . implode(' ', $command));
    }

    $processPipes = requireProcessPipes($pipes, $process, $command);
    $stdout = stream_get_contents($processPipes[1]);
    $stderr = stream_get_contents($processPipes[2]);
    fclose($processPipes[1]);
    fclose($processPipes[2]);
    $exitCode = proc_close($process);

    if (is_string($stdout) && $stdout !== '') {
        echo $stdout;
    }

    if ($exitCode !== 0) {
        throw new RuntimeException(
            'Command failed with exit code ' . $exitCode . ': ' . implode(' ', $command)
            . (is_string($stderr) && $stderr !== '' ? PHP_EOL . $stderr : ''),
        );
    }
}

/**
 * @param mixed $pipes
 * @param list<string> $command
 * @return array{1: resource, 2: resource}
 */
function requireProcessPipes(mixed $pipes, mixed $process, array $command): array
{
    if (! is_array($pipes) || ! isset($pipes[1], $pipes[2])) {
        if (is_resource($process)) {
            proc_close($process);
        }
        throw new RuntimeException('Command pipes were not opened: ' . implode(' ', $command));
    }

    $stdoutPipe = $pipes[1];
    $stderrPipe = $pipes[2];
    if (! is_resource($stdoutPipe) || ! is_resource($stderrPipe)) {
        if (is_resource($process)) {
            proc_close($process);
        }
        throw new RuntimeException('Command pipes were not opened: ' . implode(' ', $command));
    }

    return [1 => $stdoutPipe, 2 => $stderrPipe];
}

function requireStatement(PDOStatement|false $statement, string $operation): PDOStatement
{
    if (! $statement instanceof PDOStatement) {
        throw new RuntimeException('Unable to prepare ' . $operation . '.');
    }

    return $statement;
}

/**
 * @param array<string, int|string> $parameters
 */
function executeStatement(PDOStatement|false $statement, array $parameters, string $operation): void
{
    requireStatement($statement, $operation)->execute($parameters);
}

function readScalar(PDO $pdo, string $sql): mixed
{
    $statement = $pdo->query($sql);
    if (! $statement instanceof PDOStatement) {
        throw new RuntimeException('Unable to query consumer state.');
    }

    return $statement->fetchColumn();
}

function expectSame(mixed $expected, mixed $actual, string $description): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Unexpected ' . $description . '.');
    }
}

function expectTrue(bool $actual, string $description): void
{
    if (! $actual) {
        throw new RuntimeException('Expected ' . $description . '.');
    }
}

function removeDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        if (! $item instanceof SplFileInfo) {
            throw new RuntimeException('Unable to inspect the consumer root.');
        }

        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($directory);
}
