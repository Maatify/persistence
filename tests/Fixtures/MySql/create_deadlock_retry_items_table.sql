-- Test-only, consumer-owned fixture; the integration suite creates and drops it.
-- Deadlock-retry tests lock rows by primary key from two connections and record
-- committed payloads; no UNIQUE constraint beyond the primary key, no ordering
-- or soft-delete policy, no Host foreign keys or joins.
CREATE TABLE `maa_persistence_test_deadlock_retry_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Fixture row identity.',
  `payload` VARCHAR(128) NOT NULL COMMENT 'Fixture transaction payload.',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
