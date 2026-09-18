-- Test-only, consumer-owned fixture; the integration suite creates and drops it.
-- Scoped ordering uses display_order within the exact scope_key; no additional
-- UNIQUE constraint is declared, and NULL scope is exact rather than a wildcard.
-- No Host foreign keys or joins; soft-deleted rows are excluded by the package.
CREATE TABLE `maa_persistence_test_scoped_ordering` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Fixture row identity.',
  `scope_key` VARCHAR(191) NULL DEFAULT NULL COMMENT 'Exact consumer-owned scope key.',
  `display_order` INT NOT NULL COMMENT 'Mutable ordering position.',
  `deleted_at` DATETIME NULL DEFAULT NULL COMMENT 'Optional soft-delete timestamp.',
  `updated_at` DATETIME NULL DEFAULT NULL COMMENT 'Optional target mutation timestamp.',
  `label` VARCHAR(191) NOT NULL COMMENT 'Fixture row label.',
  PRIMARY KEY (`id`),
  KEY `idx_maa_persistence_test_scoped_ordering_scope_order` (`scope_key`, `display_order`),
  KEY `idx_maa_persistence_test_scoped_ordering_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
