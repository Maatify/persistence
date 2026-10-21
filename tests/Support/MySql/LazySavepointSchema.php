<?php

declare(strict_types=1);

namespace Maatify\Persistence\Tests\Support\MySql;

use PDO;

/**
 * Test-only schema for the moveWithinScope() operation-local savepoint tests:
 * two ordering tables (plain, and with UNIQUE (updated_at) so that the target
 * write can fail with 1062 after the range shift), an audit table that a
 * trigger touches to provoke lock waits and deadlocks, a marker table for
 * the caller's earlier work and a filler table that makes a partner transaction heavy.
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
                . ' `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . ' `display_order` INT NOT NULL,'
                . ' `deleted_at` DATETIME NULL DEFAULT NULL,'
                . ' `updated_at` DATETIME NULL DEFAULT NULL,'
                . ' `label` VARCHAR(191) NOT NULL,'
                . ' PRIMARY KEY (`id`)' . $extra
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );

            for ($i = 1; $i <= 5; $i++) {
                $this->pdo->exec(
                    'INSERT INTO `' . $table . '` (`display_order`, `updated_at`, `label`)'
                    . " VALUES ({$i}, '2026-01-0{$i} 00:00:00', 'row {$i}')"
                );
            }
        }

        $this->pdo->exec('CREATE TABLE `' . self::AUDIT . '` (`id` INT NOT NULL PRIMARY KEY, `n` INT NOT NULL) ENGINE=InnoDB');
        $this->pdo->exec('INSERT INTO `' . self::AUDIT . '` (`id`, `n`) VALUES (1, 0), (2, 0)');
        $this->pdo->exec('CREATE TABLE `' . self::MARKER . '` (`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY, `note` VARCHAR(64) NOT NULL) ENGINE=InnoDB');
        $this->pdo->exec('CREATE TABLE `' . self::FILL . '` (`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY, `payload` VARCHAR(32) NOT NULL) ENGINE=InnoDB');
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
