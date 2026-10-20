<?php

declare(strict_types=1);

/**
 * RC-01/RC-02 study scaffolding. Candidate algorithms live HERE, never in src/.
 * Every candidate has the signature of ScopedOrderingManager::moveWithinScope()
 * for the fixed experiment table and a single integer scope.
 */

require dirname(__DIR__, 3) . '/../vendor/autoload.php';

use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;
use Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetrySavepointTransactionRunner;
use Maatify\Persistence\Pdo\Transaction\PdoTransactionRunner;

const RC_T = 'probe_rc01';

function rc_pdo(): PDO
{
    $dsn = getenv('PERSISTENCE_TEST_MYSQL_DSN');
    $user = getenv('PERSISTENCE_TEST_MYSQL_USER');
    $password = getenv('PERSISTENCE_TEST_MYSQL_PASSWORD');

    if (!is_string($dsn) || $dsn === '' || !is_string($user) || !is_string($password)) {
        fwrite(STDERR, "Set PERSISTENCE_TEST_MYSQL_DSN, _USER and _PASSWORD.\n");

        exit(2);
    }

    return new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function rc_schema(PDO $pdo, bool $unique, bool $unsigned, bool $activeOnlyUnique = false): void
{
    $pdo->exec('DROP TABLE IF EXISTS ' . RC_T);
    $pdo->exec(
        'CREATE TABLE ' . RC_T . ' (
            id INT AUTO_INCREMENT PRIMARY KEY,
            scope_id INT NOT NULL,
            display_order INT ' . ($unsigned ? 'UNSIGNED ' : '') . 'NOT NULL,
            deleted_at DATETIME NULL DEFAULT NULL,
            ' . ($activeOnlyUnique ? 'active_order INT GENERATED ALWAYS AS (IF(deleted_at IS NULL, display_order, NULL)) VIRTUAL,
            UNIQUE KEY uq_scope_active_order (scope_id, active_order),' : '') . '
            ' . ($unique ? 'UNIQUE' : '') . ' KEY idx_scope_order (scope_id, display_order)
        ) ENGINE=InnoDB',
    );
}

/**
 * @param list<int> $activeOrders ids 1..n get these orders; $deletedOrders become extra soft-deleted rows
 * @param list<int> $deletedOrders
 */
function rc_seed(PDO $pdo, array $activeOrders, array $deletedOrders = []): void
{
    $pdo->exec('TRUNCATE TABLE ' . RC_T);
    $insert = $pdo->prepare('INSERT INTO ' . RC_T . ' (scope_id, display_order, deleted_at) VALUES (1, :o, :d)');

    foreach ($activeOrders as $order) {
        $insert->execute(['o' => $order, 'd' => null]);
    }

    foreach ($deletedOrders as $order) {
        $insert->execute(['o' => $order, 'd' => '2026-01-01 00:00:00']);
    }
}

/** @return array<int, int> active rows, id => order */
function rc_state(PDO $pdo): array
{
    $rows = $pdo->query('SELECT id, display_order FROM ' . RC_T . ' WHERE scope_id = 1 AND deleted_at IS NULL ORDER BY id')->fetchAll(PDO::FETCH_NUM);
    $state = [];

    foreach ($rows as [$id, $order]) {
        $state[(int) $id] = (int) $order;
    }

    return $state;
}

function rc_config(): ScopedOrderingConfig
{
    return new ScopedOrderingConfig(RC_T, 'scope_id', 'id', 'display_order', 'deleted_at');
}

/** Same ownership rule as the shipped PdoTransactionRunner (participate when a transaction is active). */
function rc_tx(PDO $pdo, callable $body): mixed
{
    return (new PdoTransactionRunner($pdo))->run($body);
}

function rc_lock_scope(PDO $pdo): int
{
    $statement = $pdo->query('SELECT id, display_order FROM ' . RC_T . ' WHERE scope_id = 1 AND deleted_at IS NULL ORDER BY display_order ASC, id ASC FOR UPDATE');
    $max = 0;

    while (($order = $statement->fetchColumn(1)) !== false) {
        $max = (int) $order;
    }

    $statement->closeCursor();

    return $max;
}

function rc_current(PDO $pdo, int $id): ?int
{
    $statement = $pdo->prepare('SELECT display_order FROM ' . RC_T . ' WHERE scope_id = 1 AND deleted_at IS NULL AND id = :id FOR UPDATE');
    $statement->execute(['id' => $id]);
    $value = $statement->fetchColumn();

    return $value === false ? null : (int) $value;
}

function rc_set(PDO $pdo, int $id, int $order): void
{
    $pdo->prepare('UPDATE ' . RC_T . ' SET display_order = :o WHERE id = :id')->execute(['o' => $order, 'id' => $id]);
}

/** Shift of the shipped algorithm, optionally with a deterministic row order. */
function rc_shift(PDO $pdo, int $new, int $current, bool $ordered): void
{
    if ($new < $current) {
        $sql = 'UPDATE ' . RC_T . ' SET display_order = display_order + 1 WHERE scope_id = 1 AND deleted_at IS NULL AND display_order >= :n AND display_order < :c'
            . ($ordered ? ' ORDER BY display_order DESC' : '');
    } else {
        $sql = 'UPDATE ' . RC_T . ' SET display_order = display_order - 1 WHERE scope_id = 1 AND deleted_at IS NULL AND display_order <= :n AND display_order > :c'
            . ($ordered ? ' ORDER BY display_order ASC' : '');
    }

    $pdo->prepare($sql)->execute(['n' => $new, 'c' => $current]);
}

/** @param callable(PDO,int,int,int,int):bool $algorithm (pdo, id, new, current, lockedActiveMax) after the scope lock */
function rc_candidate_body(PDO $pdo, int $id, int $new, callable $algorithm): bool
{
    $max = rc_lock_scope($pdo);
    $current = rc_current($pdo, $id);

    if ($current === null) {
        return false;
    }

    $new = min($new, max(1, $max));

    if ($current === $new) {
        return true;
    }

    return $algorithm($pdo, $id, $new, $current, $max);
}

/** Candidate registry: name => callable(PDO,int id,int new): bool */
function rc_candidates(): array
{
    return [
        // The shipped implementation, called through the public API.
        'S0 shipped' => static fn (PDO $pdo, int $id, int $new): bool => (new ScopedOrderingManager())->moveWithinScope($pdo, rc_config(), 1, $id, $new),

        // A: only add ORDER BY to the two shift statements.
        'A  ORDER BY only' => static fn (PDO $pdo, int $id, int $new): bool => rc_tx($pdo, static fn (): bool => rc_candidate_body(
            $pdo,
            $id,
            $new,
            static function (PDO $pdo, int $id, int $new, int $current, int $max = 0): bool {
                rc_shift($pdo, $new, $current, true);
                rc_set($pdo, $id, $new);

                return true;
            },
        )),

        // B: park the target on a free slot (max over ALL rows of the scope + 1), ordered shift, final write.
        'B  park+ORDER BY' => static fn (PDO $pdo, int $id, int $new): bool => rc_tx($pdo, static fn (): bool => rc_candidate_body($pdo, $id, $new, 'rc_algo_park')),

        // B+SP: the same, but atomic when it joins a caller-owned transaction (savepoint runner).
        'BS park+ORDER BY+savepoint' => static fn (PDO $pdo, int $id, int $new): bool => (new PdoDeadlockRetrySavepointTransactionRunner($pdo))->run(static fn (): bool => rc_candidate_body($pdo, $id, $new, 'rc_algo_park')),

        // B2: park on (LOCKED active maximum + 1) instead of a plain MAX() over all rows: immune to an older snapshot.
        'B2 park(locked max)+ORDER BY' => static fn (PDO $pdo, int $id, int $new): bool => rc_tx($pdo, static fn (): bool => rc_candidate_body($pdo, $id, $new, 'rc_algo_park_locked')),

        'BS2 B2+savepoint' => static fn (PDO $pdo, int $id, int $new): bool => (new PdoDeadlockRetrySavepointTransactionRunner($pdo))->run(static fn (): bool => rc_candidate_body($pdo, $id, $new, 'rc_algo_park_locked')),

        // B3: park above the maximum of ALL rows of the scope (soft-deleted included), read with a locking read.
        'B3 park(locked max, all rows)+ORDER BY' => static fn (PDO $pdo, int $id, int $new): bool => rc_tx($pdo, static fn (): bool => rc_candidate_body($pdo, $id, $new, 'rc_algo_park_all')),

        'BS3 B3+savepoint' => static fn (PDO $pdo, int $id, int $new): bool => (new PdoDeadlockRetrySavepointTransactionRunner($pdo))->run(static fn (): bool => rc_candidate_body($pdo, $id, $new, 'rc_algo_park_all')),

        // D: negate the affected range, then a single CASE update back to positive (signed column only).
        'D  negate+CASE' => static fn (PDO $pdo, int $id, int $new): bool => rc_tx($pdo, static fn (): bool => rc_candidate_body($pdo, $id, $new, 'rc_algo_negate')),
    ];
}

function rc_algo_park(PDO $pdo, int $id, int $new, int $current, int $max = 0): bool
{
    $park = (int) $pdo->query('SELECT COALESCE(MAX(display_order), 0) + 1 FROM ' . RC_T . ' WHERE scope_id = 1')->fetchColumn();
    rc_set($pdo, $id, $park);
    rc_shift($pdo, $new, $current, true);
    rc_set($pdo, $id, $new);

    return true;
}

function rc_algo_park_locked(PDO $pdo, int $id, int $new, int $current, int $max = 0): bool
{
    rc_set($pdo, $id, $max + 1);
    rc_shift($pdo, $new, $current, true);
    rc_set($pdo, $id, $new);

    return true;
}

function rc_algo_park_all(PDO $pdo, int $id, int $new, int $current, int $max = 0): bool
{
    $top = $pdo->query('SELECT display_order FROM ' . RC_T . ' WHERE scope_id = 1 ORDER BY display_order DESC, id DESC LIMIT 1 FOR UPDATE')->fetchColumn();
    rc_set($pdo, $id, ($top === false ? 0 : (int) $top) + 1);
    rc_shift($pdo, $new, $current, true);
    rc_set($pdo, $id, $new);

    return true;
}

function rc_algo_negate(PDO $pdo, int $id, int $new, int $current, int $max = 0): bool
{
    [$low, $high, $delta] = $new < $current ? [$new, $current, 1] : [$current, $new, -1];
    $pdo->prepare('UPDATE ' . RC_T . ' SET display_order = -display_order WHERE scope_id = 1 AND deleted_at IS NULL AND display_order BETWEEN :l AND :h')->execute(['l' => $low, 'h' => $high]);
    $pdo->prepare(
        'UPDATE ' . RC_T . ' SET display_order = CASE WHEN id = :id THEN :n ELSE -display_order + :d END
          WHERE scope_id = 1 AND deleted_at IS NULL AND display_order BETWEEN :nh AND :nl',
    )->execute(['id' => $id, 'n' => $new, 'd' => $delta, 'nh' => -$high, 'nl' => -$low]);

    return true;
}
