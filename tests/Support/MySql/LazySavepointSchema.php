<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Support\MySql;

use PDO;

/**
 * Test-only schema for the moveWithinScope() operation-local savepoint tests
 * (PACKAGE_BUILDING_STANDARD section 6; policies below stand in for an SQL header).
 *
 * Tables (all InnoDB, utf8mb4, PRIMARY KEY (id), no foreign keys or joins to host tables):
 * - PLAIN:  global (NULL-scope) ordering table. Display order is `display_order`,
 *           mutated by the shift; soft delete is `deleted_at` (NULL = active,
 *           never set by these tests); `updated_at` is the optional target
 *           mutation timestamp; `label` is free text. Uniqueness: none besides
 *           the primary key.
 * - UNIQUE: same columns as PLAIN plus UNIQUE KEY `u_updated` (`updated_at`), so
 *           that the target write can fail with 1062 after the range shift.
 * - AUDIT:  two counter rows; a trigger on the ordering tables updates row 2 to
 *           provoke lock waits (1205) and deadlocks (1213).
 * - MARKER: records the caller's earlier work inside its transaction.
 * - FILL:   filler rows that make the partner transaction heavy enough to lose
 *           the deadlock victim choice.
 *
 * Indexes are deliberate: there is NO index on `display_order` (nor a scope
 * column). The scope lock is `SELECT ... ORDER BY display_order, id FOR UPDATE`
 * and the tests assert that it keeps every row and the insert gap (id 6) locked;
 * an extra index would change the access path and therefore which records and
 * gaps are locked, so the table stays PRIMARY KEY plus the documented UNIQUE.
 * LazySavepointSchemaComplianceTest pins this index set.
 */
final readonly class LazySavepointSchema
{
    public const PLAIN = 'maa_persistence_test_lsp_plain';
    public const UNIQUE = 'maa_persistence_test_lsp_unique';
    public const AUDIT = 'maa_persistence_test_lsp_audit';
    public const MARKER = 'maa_persistence_test_lsp_marker';
    public const FILL = 'maa_persistence_test_lsp_fill';

    public const MOVE_ID = 5;
    public const MOVE_TO = 2;
    public const STAMP_OK = '2026-02-01 00:00:00';
    /** Equals the seeded updated_at of row 1: a duplicate under UNIQUE (updated_at). */
    public const STAMP_DUP = '2026-01-01 00:00:00';

    public function __construct(private PDO $pdo)
    {
    }

    public function initialize(): void
    {
        $this->drop();

        foreach ([self::PLAIN => '', self::UNIQUE => ', UNIQUE KEY `u_updated` (`updated_at`)'] as $table => $extra) {
            $this->pdo->exec(
                'CREATE TABLE `' . $table . '` ('
                . " `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Fixture row identity.',"
                . " `display_order` INT NOT NULL COMMENT 'Mutable ordering position shifted by moveWithinScope(); deliberately unindexed.',"
                . " `deleted_at` DATETIME NULL DEFAULT NULL COMMENT 'Optional soft-delete timestamp; NULL means active.',"
                . " `updated_at` DATETIME NULL DEFAULT NULL COMMENT 'Optional target mutation timestamp; UNIQUE only in the UNIQUE variant.',"
                . " `label` VARCHAR(191) NOT NULL COMMENT 'Fixture row label.',"
                . ' PRIMARY KEY (`id`)' . $extra
                . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Test-only ordering fixture for the lazy savepoint scenarios.'"
            );

            for ($i = 1; $i <= 5; $i++) {
                $this->pdo->exec(
                    'INSERT INTO `' . $table . '` (`display_order`, `updated_at`, `label`)'
                    . " VALUES ({$i}, '2026-01-0{$i} 00:00:00', 'row {$i}')"
                );
            }
        }

        $this->pdo->exec('CREATE TABLE `' . self::AUDIT . '` ('
            . " `id` INT NOT NULL COMMENT 'Fixed audit row identity (1 and 2); no auto increment.',"
            . " `n` INT NOT NULL COMMENT 'Counter touched by the trigger to provoke lock waits and deadlocks.',"
            . ' PRIMARY KEY (`id`)'
            . ") ENGINE=InnoDB COMMENT='Test-only lock-contention counter rows.'");
        $this->pdo->exec('INSERT INTO `' . self::AUDIT . '` (`id`, `n`) VALUES (1, 0), (2, 0)');
        $this->pdo->exec('CREATE TABLE `' . self::MARKER . '` ('
            . " `id` INT NOT NULL AUTO_INCREMENT COMMENT 'Marker row identity.',"
            . " `note` VARCHAR(64) NOT NULL COMMENT 'Caller earlier-work marker text.',"
            . ' PRIMARY KEY (`id`)'
            . ") ENGINE=InnoDB COMMENT='Test-only marker rows proving the caller transaction kept earlier work.'");
        $this->pdo->exec('CREATE TABLE `' . self::FILL . '` ('
            . " `id` INT NOT NULL AUTO_INCREMENT COMMENT 'Filler row identity.',"
            . " `payload` VARCHAR(32) NOT NULL COMMENT 'Filler payload that makes the partner transaction heavy.',"
            . ' PRIMARY KEY (`id`)'
            . ") ENGINE=InnoDB COMMENT='Test-only filler rows for the deadlock partner transaction.'");
    }

    public function drop(): void
    {
        foreach ([self::PLAIN, self::UNIQUE, self::AUDIT, self::MARKER, self::FILL] as $table) {
            $this->pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }

    /** BEFORE UPDATE trigger that turns the target write into an update that changes nothing (the `false` path). */
    public function createNeutralizingTrigger(string $table): void
    {
        $this->pdo->exec(
            'CREATE TRIGGER `maa_persistence_test_lsp_neutral` BEFORE UPDATE ON `' . $table . '` FOR EACH ROW BEGIN'
            . ' IF NEW.`id` = ' . self::MOVE_ID . ' THEN'
            . ' SET NEW.`display_order` = OLD.`display_order`; SET NEW.`updated_at` = OLD.`updated_at`;'
            . ' END IF; END'
        );
    }

    /** AFTER UPDATE trigger: the target write then needs the lock on audit row 2 (lock wait / deadlock cells). */
    public function createAuditTrigger(string $table): void
    {
        $this->pdo->exec(
            'CREATE TRIGGER `maa_persistence_test_lsp_audit` AFTER UPDATE ON `' . $table . '` FOR EACH ROW BEGIN'
            . ' IF NEW.`id` = ' . self::MOVE_ID . ' THEN UPDATE `' . self::AUDIT . '` SET `n` = `n` + 1 WHERE `id` = 2; END IF; END'
        );
    }
}
