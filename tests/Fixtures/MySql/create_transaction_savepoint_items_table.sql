-- Consumer-owned fixture table for transaction/savepoint integration.
-- The table is created and removed by the test suite; no Host tables are used.
CREATE TABLE `maa_persistence_test_transaction_savepoint_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Fixture row identity.',
  `payload` VARCHAR(128) NOT NULL COMMENT 'Fixture transaction payload.',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
