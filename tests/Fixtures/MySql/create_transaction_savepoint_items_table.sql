-- Test-only, consumer-owned fixture; the integration suite creates and drops it.
-- Transaction/savepoint tests use only the primary-key identity; no additional
-- UNIQUE constraint is declared, and no ordering or soft-delete policy applies.
-- No Host foreign keys or joins; no Host tables are used.
CREATE TABLE `maa_persistence_test_transaction_savepoint_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Fixture row identity.',
  `payload` VARCHAR(128) NOT NULL COMMENT 'Fixture transaction payload.',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
