<?php

declare(strict_types=1);

/**
 * Issue #48 study scaffolding. Probes only; nothing here is package runtime.
 * Env: PERSISTENCE_TEST_MYSQL_DSN/_USER/_PASSWORD (the CI variables).
 *
 * Subjects (what is called inside the caller-owned transaction):
 *   real    the shipped ScopedOrderingManager::moveWithinScope() (v1.4.0 behavior, unmodified)
 *   recipe  consumer-side composition: PdoDeadlockRetrySavepointTransactionRunner around the shipped call,
 *           turning a `false` into a rollback (no Runtime change)
 *   proto   Study48\SavepointMovePrototype (the recommended design, standalone)
 *   nested  the prototype called inside PdoDeadlockRetrySavepointTransactionRunner (a consumer that already uses a savepoint runner)
 */

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require __DIR__ . '/SavepointMovePrototype.php';

// PROBE_SRC=<dir containing src/> runs the probes against another source tree (for example the released v1.4.0 tag).
if (is_string(getenv('PROBE_SRC')) && getenv('PROBE_SRC') !== '') {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Maatify\\Persistence\\';
        if (str_starts_with($class, $prefix)) {
            $file = getenv('PROBE_SRC') . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }, true, true);
}

use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;
use Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetrySavepointTransactionRunner;
use Maatify\Persistence\Study48\SavepointMovePrototype;

const P48_T = 'probe_i48';
const P48_AUDIT = 'probe_i48_audit';
const P48_MARK = 'probe_i48_marker';
const P48_FILL = 'probe_i48_filler';

const P48_ORIGINAL = '1:1 2:2 3:3 4:4 5:5';
const P48_MOVED = '1:1 2:3 3:4 4:5 5:2';   // move id 5 to position 2 in scope 1
const P48_MOVE_ID = 5;
const P48_MOVE_TO = 2;
const P48_STAMP_OK = '2026-02-01 00:00:00';
const P48_STAMP_DUP = '2026-01-01 00:00:00'; // equals the seeded updated_at of id 1

/** PDO whose control statements and prepares can be made to fail on demand (fault injection, clearly labeled in results). */
final class FaultyPdo extends PDO
{
    /** @var list<array{on: string, match: string, do: string, thrown: ?Throwable, hits: int}> */
    public array $faults = [];

    /** @var list<string> */
    public array $log = [];

    /** The first Throwable any fault injected (the "original failure" of a cell). */
    public ?Throwable $firstThrown = null;

    public function addFault(string $on, string $match, string $do): int
    {
        $this->faults[] = ['on' => $on, 'match' => $match, 'do' => $do, 'thrown' => null, 'hits' => 0];

        return array_key_last($this->faults);
    }

    public function exec(string $statement): int|false
    {
        $this->log[] = 'exec: ' . strtok($statement, "\n");
        $action = $this->fire('exec', $statement);

        return $action === 'false' ? false : parent::exec($statement);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->fire('prepare', $query);

        return parent::prepare($query, $options);
    }

    /** @return string|null 'false' when the call must report false; throws for 'throw' / 'runtime'. */
    private function fire(string $on, string $sql): ?string
    {
        foreach ($this->faults as $i => $fault) {
            if ($fault['on'] !== $on || preg_match($fault['match'], $sql) !== 1) {
                continue;
            }

            $this->faults[$i]['hits']++;
            if ($fault['do'] === 'false') {
                return 'false';
            }

            $e = $fault['do'] === 'runtime'
                ? new RuntimeException('injected original failure')
                : new PDOException('injected control failure');
            if ($e instanceof PDOException) {
                $e->errorInfo = ['HY000', 9999, 'injected'];
            }
            $this->faults[$i]['thrown'] = $e;
            $this->firstThrown ??= $e;

            throw $e;
        }

        return null;
    }
}

function p48_pdo(bool $faulty = false): PDO
{
    $class = $faulty ? FaultyPdo::class : PDO::class;
    $pdo = new $class(
        (string) getenv('PERSISTENCE_TEST_MYSQL_DSN'),
        (string) getenv('PERSISTENCE_TEST_MYSQL_USER'),
        (string) getenv('PERSISTENCE_TEST_MYSQL_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );

    return $pdo;
}

function p48_engine(PDO $pdo): string
{
    try {
        $iso = 'innodb_snapshot_isolation=' . $pdo->query('SELECT @@innodb_snapshot_isolation')->fetchColumn();
    } catch (PDOException) {
        $iso = 'innodb_snapshot_isolation=(absent)';
    }
    $rot = 'rollback_on_timeout=' . $pdo->query('SELECT @@innodb_rollback_on_timeout')->fetchColumn();

    return $pdo->query('SELECT VERSION()')->fetchColumn() . ' ' . $iso . ' ' . $rot;
}

function p48_config(): ScopedOrderingConfig
{
    return new ScopedOrderingConfig(P48_T, 'scope_id', 'id', 'display_order', 'deleted_at', false, 'updated_at');
}

/** @param 'plain'|'uniq_order'|'uniq_updated' $variant */
function p48_schema(PDO $pdo, string $variant = 'plain'): void
{
    foreach ([P48_T, P48_AUDIT, P48_MARK, P48_FILL] as $table) {
        $pdo->exec('DROP TABLE IF EXISTS ' . $table);
    }
    $extra = match ($variant) {
        'uniq_order' => ', UNIQUE KEY u_scope_order (scope_id, display_order)',
        'uniq_updated' => ', UNIQUE KEY u_updated (updated_at), KEY idx_scope_order (scope_id, display_order)',
        default => ', KEY idx_scope_order (scope_id, display_order)',
    };
    $pdo->exec('CREATE TABLE ' . P48_T . ' (
        id INT AUTO_INCREMENT PRIMARY KEY,
        scope_id INT NOT NULL,
        display_order INT NOT NULL,
        deleted_at DATETIME NULL DEFAULT NULL,
        updated_at DATETIME NULL DEFAULT NULL' . $extra . '
    ) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE ' . P48_AUDIT . ' (id INT PRIMARY KEY, n INT NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE ' . P48_MARK . ' (id INT AUTO_INCREMENT PRIMARY KEY, note VARCHAR(40) NOT NULL) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE ' . P48_FILL . ' (id INT AUTO_INCREMENT PRIMARY KEY, payload VARCHAR(20) NOT NULL) ENGINE=InnoDB');
    $pdo->exec('INSERT INTO ' . P48_T . ' (scope_id, display_order, updated_at) VALUES
        (1,1,"2026-01-01 00:00:00"),(1,2,"2026-01-02 00:00:00"),(1,3,"2026-01-03 00:00:00"),
        (1,4,"2026-01-04 00:00:00"),(1,5,"2026-01-05 00:00:00"),(2,1,"2026-01-06 00:00:00"),(2,2,"2026-01-07 00:00:00")');
    $pdo->exec('INSERT INTO ' . P48_AUDIT . ' VALUES (1,0),(2,0)');
}

function p48_drop(PDO $pdo): void
{
    foreach ([P48_T, P48_AUDIT, P48_MARK, P48_FILL] as $table) {
        $pdo->exec('DROP TABLE IF EXISTS ' . $table);
    }
}

/** Scope-1 state as "id:order ..." (autocommit/committed view when read through a connection outside the caller's transaction). */
function p48_state(PDO $pdo, int $scope = 1): string
{
    $rows = $pdo->query('SELECT id, display_order FROM ' . P48_T . ' WHERE scope_id = ' . $scope . ' ORDER BY id')->fetchAll(PDO::FETCH_NUM);

    return implode(' ', array_map(static fn (array $r): string => $r[0] . ':' . $r[1], $rows));
}

function p48_markers(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM ' . P48_MARK)->fetchColumn();
}

/** ORIGINAL | MOVED | PARTIAL(<state>) ; PARTIAL additionally says whether the orders are still a permutation of 1..5. */
function p48_classify(string $state): string
{
    if ($state === P48_ORIGINAL) {
        return 'ORIGINAL';
    }
    if ($state === P48_MOVED) {
        return 'MOVED';
    }
    $orders = array_map(static fn (string $p): int => (int) explode(':', $p)[1], explode(' ', $state));
    sort($orders);

    return 'PARTIAL[' . $state . ($orders === [1, 2, 3, 4, 5] ? ' perm' : ' CORRUPT') . ']';
}

function p48_trigger(PDO $pdo, string $name, string $timing, string $body): void
{
    $pdo->exec('DROP TRIGGER IF EXISTS ' . $name);
    $pdo->exec('CREATE TRIGGER ' . $name . ' ' . $timing . ' UPDATE ON ' . P48_T . ' FOR EACH ROW BEGIN ' . $body . ' END');
}

final class P48Rollback extends RuntimeException
{
}

/** @return callable(PDO,int,int,string):bool */
function p48_subject(string $subject): callable
{
    $real = new ScopedOrderingManager();
    $proto = new SavepointMovePrototype($real);

    return match ($subject) {
        'real' => static fn (PDO $pdo, int $id, int $to, string $stamp): bool => $real->moveWithinScope($pdo, p48_config(), 1, $id, $to, $stamp),
        'proto' => static fn (PDO $pdo, int $id, int $to, string $stamp): bool => $proto->moveWithinScope($pdo, p48_config(), 1, $id, $to, $stamp),
        'recipe' => static function (PDO $pdo, int $id, int $to, string $stamp) use ($real): bool {
            try {
                return (new PdoDeadlockRetrySavepointTransactionRunner($pdo))->run(
                    static function () use ($real, $pdo, $id, $to, $stamp): bool {
                        if (!$real->moveWithinScope($pdo, p48_config(), 1, $id, $to, $stamp)) {
                            throw new P48Rollback();
                        }

                        return true;
                    },
                );
            } catch (P48Rollback) {
                return false;
            }
        },
        'reuse' => static fn (PDO $pdo, int $id, int $to, string $stamp): bool => (new SavepointMovePrototype($real, 'runner'))->moveWithinScope($pdo, p48_config(), 1, $id, $to, $stamp),
        'nested' => static fn (PDO $pdo, int $id, int $to, string $stamp): bool => (new PdoDeadlockRetrySavepointTransactionRunner($pdo))->run(
            static fn (): bool => $proto->moveWithinScope($pdo, p48_config(), 1, $id, $to, $stamp),
        ),
        default => throw new InvalidArgumentException($subject),
    };
}

function p48_describe(Throwable $e): string
{
    if ($e instanceof PDOException) {
        $info = $e->errorInfo;

        return 'EXC PDO ' . ($info[1] ?? '?') . '/' . ($info[0] ?? '?');
    }

    return 'EXC ' . (new ReflectionClass($e))->getShortName();
}

/** @return array{sp:int,rt:int,rel:int,commit:int,rollback:int} */
function p48_counters(PDO $pdo): array
{
    $rows = $pdo->query(
        "SHOW SESSION STATUS WHERE Variable_name IN ('Com_savepoint','Com_rollback_to_savepoint','Com_release_savepoint','Com_commit','Com_rollback')"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    return [
        'sp' => (int) $rows['Com_savepoint'],
        'rt' => (int) $rows['Com_rollback_to_savepoint'],
        'rel' => (int) $rows['Com_release_savepoint'],
        'commit' => (int) $rows['Com_commit'],
        'rollback' => (int) $rows['Com_rollback'],
    ];
}

/**
 * One caller-owned-transaction cell.
 *
 * @param array{
 *   id: string, variant?: string, triggers?: list<array{string,string,string}>, faulty?: bool, lockWait?: int,
 *   snapshot?: bool, stamp?: string, target?: int, to?: int,
 *   faults?: list<array{string,string,string}>, pre?: callable(PDO,PDO,array<string,mixed>):void,
 *   post?: callable(PDO,PDO,array<string,mixed>):void, owned?: bool
 * } $spec
 */
function p48_cell(string $subject, array $spec): string
{
    $b = p48_pdo();
    p48_schema($b, $spec['variant'] ?? 'plain');
    foreach ($spec['triggers'] ?? [] as [$name, $timing, $body]) {
        p48_trigger($b, $name, $timing, $body);
    }

    $a = p48_pdo($spec['faulty'] ?? false);
    if (isset($spec['lockWait'])) {
        $a->exec('SET SESSION innodb_lock_wait_timeout = ' . $spec['lockWait']);
    }
    $ctx = [];
    $owned = $spec['owned'] ?? false;

    if (!$owned) {
        $a->beginTransaction();
        if ($spec['snapshot'] ?? false) {
            $a->query('SELECT COUNT(*) FROM ' . P48_T)->fetchAll();   // first non-locking read: creates the read view
        }
        $a->exec("INSERT INTO " . P48_MARK . " (note) VALUES ('caller-earlier-work')");
    }
    if (isset($spec['pre'])) {
        ($spec['pre'])($a, $b, $ctx);
    }
    $faultIdx = [];
    if ($a instanceof FaultyPdo) {
        foreach ($spec['faults'] ?? [] as [$on, $match, $do]) {
            $faultIdx[] = $a->addFault($on, $match, $do);
        }
    }

    $steps = $spec['steps'] ?? [[$spec['target'] ?? P48_MOVE_ID, $spec['to'] ?? P48_MOVE_TO, $spec['stamp'] ?? P48_STAMP_OK]];
    $before = p48_counters($a);
    $t0 = microtime(true);
    $thrown = null;
    $results = [];
    foreach ($steps as [$target, $to, $stamp]) {
        try {
            $results[] = p48_subject($subject)($a, $target, $to, $stamp) ? 'true' : 'false';
        } catch (Throwable $e) {
            $thrown = $e;
            $results[] = p48_describe($e);
        }
    }
    $result = implode(',', $results);
    $elapsed = microtime(true) - $t0;
    $inTx = $a->inTransaction() ? 'Y' : 'N';   // read before any further statement: pdo_mysql derives it from the last reply
    $after = p48_counters($a);

    $identity = '';
    if ($a instanceof FaultyPdo && $a->firstThrown !== null) {
        $identity = ' origIdentity=' . ($thrown === $a->firstThrown ? 'kept' : 'REPLACED');
    }

    $ownView = p48_classify(p48_state($a));
    $ownMark = p48_markers($a);
    $bMark = p48_markers($b);                       // uncommitted caller work must be invisible here
    $txn = $owned ? 'n/a' : ($ownMark >= 1 && $bMark === 0 ? 'alive' : ($ownMark === 0 ? 'LOST' : 'committed?!'));

    if (isset($spec['post'])) {
        ($spec['post'])($a, $b, $ctx);
    }

    // The caller "catches and carries on": it commits whatever the transaction holds.
    $commitNote = '';
    if (!$owned) {
        try {
            $a->commit();
        } catch (Throwable $e) {
            $commitNote = ' commit=' . p48_describe($e);
        }
    }
    $final = p48_classify(p48_state($b));
    $finalMark = p48_markers($b);

    $d = static fn (string $k): int => $after[$k] - $before[$k];
    $line = sprintf(
        '%-8s %-6s %-14s | ownView=%s marker=%d txn=%-5s inTx=%s | Δsp/rt/rel=%d/%d/%d Δcommit/rollback=%d/%d | afterCommit=%s marker=%d%s%s (%.1fs)',
        $spec['id'],
        $subject,
        $result,
        $ownView,
        $ownMark,
        $txn,
        $inTx,
        $d('sp'),
        $d('rt'),
        $d('rel'),
        $d('commit'),
        $d('rollback'),
        $final,
        $finalMark,
        $identity . (isset($ctx['note']) ? ' ' . $ctx['note'] : ''),
        $commitNote,
        $elapsed,
    );

    foreach ([...($ctx['conns'] ?? []), $a, $b] as $conn) {
        if ($conn instanceof PDO && $conn->inTransaction()) {
            try {
                $conn->rollBack();
            } catch (Throwable) {
            }
        }
    }
    $thrown = null;
    $e = null;
    p48_drop($b);

    return $line;
}
