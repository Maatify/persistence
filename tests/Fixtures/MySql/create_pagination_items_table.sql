-- Test-only, consumer-owned fixture; the integration suite creates and drops it.
-- Pagination filters deleted_at and sorts through whitelisted fields with id as
-- the deterministic tie-breaker; no additional UNIQUE constraint is declared.
-- Host identity is supplied as tenant_id; this fixture adds no Host FK or JOIN.
CREATE TABLE `maa_persistence_test_pagination_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Fixture row identity.',
  `tenant_id` INT UNSIGNED NOT NULL COMMENT 'Host-provided tenant identity; no FK.',
  `category` VARCHAR(32) NOT NULL COMMENT 'Fixture filter category.',
  `name` VARCHAR(128) NOT NULL COMMENT 'Fixture display name.',
  `score` INT NOT NULL COMMENT 'Fixture sortable score.',
  `is_active` TINYINT(1) NOT NULL COMMENT 'Fixture visibility flag.',
  `nullable_code` VARCHAR(32) NULL COMMENT 'Fixture nullable filter value.',
  `created_at` DATETIME(6) NOT NULL COMMENT 'Fixture creation timestamp.',
  `deleted_at` DATETIME(6) NULL COMMENT 'Optional soft-delete timestamp.',
  PRIMARY KEY (`id`),
  KEY `idx_pagination_base` (`tenant_id`, `deleted_at`, `id`),
  KEY `idx_pagination_filter` (`tenant_id`, `category`, `is_active`, `nullable_code`, `score`, `id`),
  KEY `idx_pagination_sort` (`created_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
