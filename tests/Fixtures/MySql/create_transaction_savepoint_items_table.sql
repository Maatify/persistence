CREATE TABLE `maa_persistence_test_transaction_savepoint_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payload` VARCHAR(128) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
