CREATE TABLE `maa_persistence_test_pagination_items` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `tenant_id` INT NOT NULL,
  `category` VARCHAR(32) NULL,
  `active` TINYINT(1) NOT NULL,
  `name` VARCHAR(64) NOT NULL,
  `score` INT NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pagination_filter` (`tenant_id`, `category`, `active`, `score`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
