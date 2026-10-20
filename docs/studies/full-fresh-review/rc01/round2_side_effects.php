<?php

declare(strict_types=1);

/**
 * Round 2 / F45-R02: observable side effects of the parking write. A table with an AFTER UPDATE audit trigger
 * (transactional InnoDB log + non-transactional MyISAM log), an ON UPDATE CURRENT_TIMESTAMP(6) column and the
 * library's own updatedAtColumn. Compares the shipped algorithm (non-unique table) with B3+savepoint (UNIQUE table).
 */

require __DIR__ . '/lib.php';

use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;
use Maatify\Persistence\Pdo\Transaction\PdoDeadlockRetrySavepointTransactionRunner;

$pdo = rc_pdo();
const STAMP = '2030-01-01 00:00:00';

function build(PDO $pdo, bool $unique): void
{
    foreach (['probe_rc01_audit_innodb', 'probe_rc01_audit_myisam'] as $audit) {
        $pdo->exec('DROP TABLE IF EXISTS ' . $audit);
    }

    $pdo->exec('DROP TABLE IF EXISTS ' . RC_T);
    $pdo->exec(
        'CREATE TABLE ' . RC_T . ' (
            id INT AUTO_INCREMENT PRIMARY KEY, scope_id INT NOT NULL, display_order INT NOT NULL,
            deleted_at DATETIME NULL, updated_at DATETIME NULL,
            touched TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            ' . ($unique ? 'UNIQUE' : '') . ' KEY idx_scope_order (scope_id, display_order)
        ) ENGINE=InnoDB',
    );
    $pdo->exec('CREATE TABLE probe_rc01_audit_innodb (n INT AUTO_INCREMENT PRIMARY KEY, row_id INT, old_o INT, new_o INT) ENGINE=InnoDB');
    $pdo->exec('CREATE TABLE probe_rc01_audit_myisam (n INT AUTO_INCREMENT PRIMARY KEY, row_id INT, old_o INT, new_o INT) ENGINE=MyISAM');
    $pdo->exec('DROP TRIGGER IF EXISTS probe_rc01_audit');
    $pdo->exec(
        'CREATE TRIGGER probe_rc01_audit AFTER UPDATE ON ' . RC_T . ' FOR EACH ROW BEGIN
            IF OLD.display_order <> NEW.display_order THEN
                INSERT INTO probe_rc01_audit_innodb (row_id, old_o, new_o) VALUES (NEW.id, OLD.display_order, NEW.display_order);
                INSERT INTO probe_rc01_audit_myisam (row_id, old_o, new_o) VALUES (NEW.id, OLD.display_order, NEW.display_order);
            END IF;
        END',
    );
    $pdo->exec('INSERT INTO ' . RC_T . ' (scope_id, display_order) VALUES (1,1),(1,2),(1,3),(1,4),(1,5),(1,6)');
    $pdo->exec('TRUNCATE TABLE probe_rc01_audit_innodb');
    $pdo->exec('TRUNCATE TABLE probe_rc01_audit_myisam');
}

/** B3 + savepoint with the library's updated_at semantics (written together with the FINAL order only). */
function b3sp(PDO $pdo, int $id, int $new, string $stamp): bool
{
    return (new PdoDeadlockRetrySavepointTransactionRunner($pdo))->run(static function () use ($pdo, $id, $new, $stamp): bool {
        $max = rc_lock_scope($pdo);
        $current = rc_current($pdo, $id);

        if ($current === null) {
            return false;
        }

        $new = min($new, max(1, $max));

        if ($current === $new) {
            return true;
        }

        $top = (int) $pdo->query('SELECT display_order FROM ' . RC_T . ' WHERE scope_id = 1 ORDER BY display_order DESC, id DESC LIMIT 1 FOR UPDATE')->fetchColumn();
        rc_set($pdo, $id, $top + 1);
        rc_shift($pdo, $new, $current, true);
        $pdo->prepare('UPDATE ' . RC_T . ' SET display_order = :o, updated_at = :u WHERE id = :id')->execute(['o' => $new, 'u' => $stamp, 'id' => $id]);

        return true;
    });
}

function report(PDO $pdo, string $label): void
{
    $audit = static fn (string $t): array => array_map(
        static fn (array $r): string => $r[0] . ':' . $r[1] . '->' . $r[2],
        $pdo->query("SELECT row_id, old_o, new_o FROM $t ORDER BY n")->fetchAll(PDO::FETCH_NUM),
    );
    $target = $pdo->query('SELECT display_order, updated_at FROM ' . RC_T . ' WHERE id = 1')->fetch(PDO::FETCH_NUM);
    $targetEvents = array_values(array_filter($audit('probe_rc01_audit_innodb'), static fn (string $e): bool => str_starts_with($e, '1:')));
    printf("  %-34s target final=%s updated_at=%s\n", $label, $target[0], $target[1] ?? 'NULL');
    printf("  %-34s audit events for the TARGET (InnoDB log): %s  [total %d]\n", '', json_encode($targetEvents), count($audit('probe_rc01_audit_innodb')));
    printf("  %-34s MyISAM log total: %d\n", '', count($audit('probe_rc01_audit_myisam')));
}

echo "== 1. successful move id1 -> 4, updatedAtColumn = updated_at\n";
build($pdo, false);
(new ScopedOrderingManager())->moveWithinScope($pdo, new ScopedOrderingConfig(RC_T, 'scope_id', 'id', 'display_order', 'deleted_at', false, 'updated_at'), 1, 1, 4, STAMP);
report($pdo, 'shipped (non-unique)');
build($pdo, true);
b3sp($pdo, 1, 4, STAMP);
report($pdo, 'B3+savepoint (UNIQUE)');

echo "\n== 2. move fails at the final write inside a caller-owned transaction, caller carries on and commits\n";
foreach ([['shipped (non-unique)', false], ['B3+savepoint (UNIQUE)', true]] as [$label, $unique]) {
    build($pdo, $unique);
    $pdo->exec("CREATE TRIGGER probe_rc01_fail BEFORE UPDATE ON " . RC_T . " FOR EACH ROW BEGIN IF NEW.id = 1 AND NEW.display_order = 4 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'final'; END IF; END");
    $pdo->beginTransaction();

    try {
        if ($unique) {
            b3sp($pdo, 1, 4, STAMP);
        } else {
            (new ScopedOrderingManager())->moveWithinScope($pdo, new ScopedOrderingConfig(RC_T, 'scope_id', 'id', 'display_order', 'deleted_at', false, 'updated_at'), 1, 1, 4, STAMP);
        }
    } catch (PDOException) {
    }

    $pdo->commit();
    $pdo->exec('DROP TRIGGER probe_rc01_fail');
    report($pdo, $label);
}

$pdo->exec('DROP TRIGGER IF EXISTS probe_rc01_audit');
foreach (['probe_rc01_audit_innodb', 'probe_rc01_audit_myisam', RC_T] as $t) {
    $pdo->exec('DROP TABLE IF EXISTS ' . $t);
}
