-- Consumer-owned fixture table for global ordering.
-- No Host foreign keys or joins; soft-deleted rows are excluded by the package.
CREATE TABLE `maa_persistence_test_global_ordering` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Fixture row identity.',
  `display_order` INT NOT NULL COMMENT 'Mutable ordering position.',
  `deleted_at` DATETIME NULL DEFAULT NULL COMMENT 'Optional soft-delete timestamp.',
  `label` VARCHAR(191) NOT NULL COMMENT 'Fixture row label.',
  PRIMARY KEY (`id`),
  KEY `idx_maa_persistence_test_global_ordering_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
