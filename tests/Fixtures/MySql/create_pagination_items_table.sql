CREATE TABLE `maa_persistence_test_pagination_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NOT NULL,
  `category` VARCHAR(32) NOT NULL,
  `name` VARCHAR(128) NOT NULL,
  `score` INT NOT NULL,
  `is_active` TINYINT(1) NOT NULL,
  `nullable_code` VARCHAR(32) NULL,
  `created_at` DATETIME(6) NOT NULL,
  `deleted_at` DATETIME(6) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pagination_base` (`tenant_id`, `deleted_at`, `id`),
  KEY `idx_pagination_filter` (`tenant_id`, `category`, `is_active`, `nullable_code`, `score`, `id`),
  KEY `idx_pagination_sort` (`created_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
