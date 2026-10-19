-- =============================================================================
-- Product V1 schema: Product, Variant, Option, Option Value, Content, Image Role, and Image Assignment
-- Package: maatify/php-product
-- =============================================================================
--
-- Runtime baseline:
--   - MySQL 8.0.16 or later. This minimum is part of the storage contract
--     because the schema relies on enforced CHECK constraints.
--   - InnoDB, utf8mb4, utf8mb4_unicode_ci. Identifier comparison (codes, SKU,
--     barcode, role keys, language codes, platforms) is therefore
--     case-insensitive and PAD SPACE; no PHP-only case folding is added.
--
-- Scope:
--   - Exactly ten tables: maa_product_products, maa_product_product_contents,
--     maa_product_options, maa_product_option_contents,
--     maa_product_option_values, maa_product_option_value_contents,
--     maa_product_variants, maa_product_variant_option_values,
--     maa_product_image_roles, and maa_product_image_assignments.
--   - It defines no Pricing, Inventory, Supplier, Category, Order, Customer,
--     Catalog/Tenant, Host, or Media Storage table, and no foreign key or JOIN
--     to any of them. One Product identity namespace exists per deployed set
--     of these tables; no catalog_id, tenant_id, or store scope is stored.
--   - No data is seeded. Product Image Roles are created by the Host.
--
-- Timestamp contract:
--   - created_at, updated_at, and deleted_at are assigned by the Product
--     application from the Host-injected Clock. No DEFAULT CURRENT_TIMESTAMP
--     or ON UPDATE CURRENT_TIMESTAMP is used and no timezone is normalized.
--
-- Status contract:
--   - status is exactly lowercase active or inactive. Each status column uses
--     the utf8mb4_bin collation and a binary CHECK, so uppercase and mixed-case
--     values are rejected by the database itself.
--   - status and deleted_at are independent dimensions. Soft deletion never
--     changes status, and restoration preserves the saved status.
--   - Defaults follow the architecture: Product, Option, and Option Value are
--     created inactive; Product Image Role is created active. Variant status
--     has no database default because the application assigns it explicitly.
--
-- Deletion contract:
--   - deleted_at IS NULL means the record is not soft deleted. Runtime hard
--     delete is not used for normal lifecycle operations.
--   - Identity unique keys never include deleted_at, so a soft-deleted logical
--     identity stays reserved and must be restored, not re-inserted.
--
-- Relationship contract:
--   - Every foreign key is internal to this schema and uses ON DELETE RESTRICT
--     and ON UPDATE RESTRICT. No cascade is defined.
--   - Candidate keys UNIQUE (id, product_id) on options and variants and
--     UNIQUE (id, option_id) on option values are the targets of composite
--     foreign keys that make same-Product and same-Option ownership a database
--     invariant rather than a PHP-only check.
--
-- Ordering contract:
--   - display_order exists only on options, option values, variants, and image
--     assignments (Product has none, DEC-006). It is a positive position within
--     its scope (product_id, option_id, product_id, and the generated
--     ordering_scope respectively), assigned by the application through the
--     shared maatify/persistence Ordering API. No database default is given and
--     it is not unique, because the Ordering API shifts positions.
--
-- Content contract:
--   - language_code is NULL for unlocalized Content and non-NULL for exact
--     language Content; NULL is an exact scope, never a fallback.
--   - language_code_identity maps NULL to '' so the unique key
--     (owner_id, language_code_identity) allows one NULL-language row per owner.
--     Empty and whitespace-only non-NULL codes (spaces, tabs, newlines, and other
--     characters matching [[:space:]]) are rejected by CHECK, so they
--     cannot collide with that sentinel.
--   - The (owner_id, language_code) identity is immutable by application
--     contract and stays unique across soft-deleted history.
--
-- Variant contract:
--   - composition_fingerprint is the lowercase hexadecimal SHA-256 digest of
--     the canonical composition string defined in the architecture (section
--     5.3). UNIQUE (product_id, composition_fingerprint) spans all history, so
--     no duplicate composition identity can exist, active or not. The database
--     cannot compute or verify the digest; the application owns that.
--   - sku and barcode are optional secondary identifiers: NULL or a non-empty
--     string up to 128 characters. Each is unique while assigned; MySQL allows
--     any number of NULL values in a unique key, which is the intended
--     "unique while assigned" semantics.
--
-- Composition contract:
--   - maa_product_variant_option_values rows are immutable pairs with no
--     independent update, delete, or soft-delete lifecycle; they follow the
--     Variant. UNIQUE (variant_id, option_id) allows one value per Option.
--
-- Image Assignment contract:
--   - media_asset_id is a Host-owned external identity (BIGINT UNSIGNED > 0)
--     with deliberately no foreign key. Product Image Role ids are a separate
--     namespace from any Category role id.
--   - A target is the Product itself (variant_id IS NULL) or one Variant of
--     that Product. product_id always has an independent foreign key, and the
--     composite foreign key (variant_id, product_id) proves a Variant belongs
--     to the same Product. variant_id = NULL is valid only for a Product-level
--     target and never bypasses the product_id foreign key.
--   - role_id, language_code, and platform are exact nullable scope dimensions;
--     NULL means exactly NULL, never a wildcard or fallback.
--   - The stable identity (product_id, variant_id, media_asset_id, role_id,
--     language_code, platform) is unique across soft-deleted history through
--     generated NULL-safe identity columns; their sentinels (0 and '') are
--     made unreachable by CHECK constraints.
--   - ordering_scope is a generated, length-prefixed exact scope
--     (target, language, platform, role) shared by ordering and by the default
--     rule. default_scope_identity is NULL unless the row is an active default,
--     so its unique key allows at most one active default per exact scope while
--     any number of non-default or soft-deleted rows remain allowed. A soft
--     deleted row can never be default (CHECK); no row is promoted
--     automatically.
--
-- Boundaries of this DDL:
--   - These constraints do not, and cannot, prove invariants that need
--     transactions, locking, or PHP logic: structural validity of a Product
--     configuration, exact active-Option coverage per Variant, the fingerprint
--     value, usable Content names for activation, immutability of identity
--     columns, atomic default replacement, or active-Role requirements on new
--     assignments. The application enforces those under the Product aggregate
--     lock; the unique keys here are the final concurrency guard for identity.
-- =============================================================================

CREATE TABLE `maa_product_products`
(
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Internal immutable Product identity generated by the database',
    `code`       VARCHAR(100) NOT NULL COMMENT 'Stable immutable Product business key; unique in the package identity namespace and permanently reserved after soft deletion',
    `status`     VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'inactive' COMMENT 'Product lifecycle status; exact lowercase active or inactive; independent of deleted_at',
    `created_at` DATETIME NOT NULL COMMENT 'Host Clock timestamp assigned by the Product application when the Product is created',
    `updated_at` DATETIME NOT NULL COMMENT 'Host Clock timestamp assigned by the Product application on mutation, soft delete, or restore',
    `deleted_at` DATETIME NULL COMMENT 'Host Clock timestamp assigned by the Product application on soft delete; NULL means not deleted',

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_maa_product_products_code` (`code`),
    KEY `idx_maa_product_products_status_deleted_id` (`status`, `deleted_at`, `id`),
    CONSTRAINT `chk_maa_product_products_code` CHECK (
        `code` REGEXP '[^[:space:]]' AND CHAR_LENGTH(`code`) <= 100
    ),
    CONSTRAINT `chk_maa_product_products_status` CHECK (
        BINARY `status` IN (BINARY 'active', BINARY 'inactive')
    )
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci
COMMENT = 'Product commercial identity and lifecycle; owns no price, stock, supplier, category, or slug';

CREATE TABLE `maa_product_product_contents`
(
    `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Internal immutable Product Content identity generated by the database',
    `product_id`             BIGINT UNSIGNED NOT NULL COMMENT 'Internal Product identity owning this Content; no Host-table reference',
    `language_code`          VARCHAR(16) NULL COMMENT 'Nullable language identity: NULL is unlocalized Content; non-NULL is exact-language Content; Host owns semantic language support and fallback',
    `language_code_identity` VARCHAR(16) GENERATED ALWAYS AS (COALESCE(`language_code`, '')) STORED COMMENT 'Generated uniqueness key mapping the unlocalized NULL identity to one database value',
    `name`                   VARCHAR(255) NOT NULL COMMENT 'Product name for the unlocalized or exact-language Content; stored text, not HTML',
    `short_description`      VARCHAR(500) NULL COMMENT 'Optional short Product description; stored content value, never rendered or sanitized by Product',
    `description`            TEXT NULL COMMENT 'Optional Product description; stored content value, never rendered or sanitized by Product',
    `created_at`             DATETIME NOT NULL COMMENT 'Host Clock timestamp assigned by the Product application when the Content is created',
    `updated_at`             DATETIME NOT NULL COMMENT 'Host Clock timestamp assigned by the Product application on Content mutation, soft delete, or restore',
    `deleted_at`             DATETIME NULL COMMENT 'Host Clock timestamp assigned by the Product application on soft delete; NULL means not deleted',

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_maa_product_product_contents_identity` (`product_id`, `language_code_identity`),
    CONSTRAINT `chk_maa_product_product_contents_language_code` CHECK (
        `language_code` IS NULL
        OR (`language_code` REGEXP '[^[:space:]]' AND CHAR_LENGTH(`language_code`) <= 16)
    ),
    CONSTRAINT `chk_maa_product_product_contents_name` CHECK (`name` REGEXP '[^[:space:]]'),
    CONSTRAINT `fk_maa_product_product_contents_product` FOREIGN KEY (`product_id`)
        REFERENCES `maa_product_products` (`id`)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci
COMMENT = 'Unlocalized and exact-language Product Content keyed by Product and nullable language identity';

CREATE TABLE `maa_product_options`
(
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Internal immutable Option identity generated by the database',
    `product_id`    BIGINT UNSIGNED NOT NULL COMMENT 'Internal Product identity owning this variant-defining Option',
    `code`          VARCHAR(100) NOT NULL COMMENT 'Stable immutable Option business key; unique within its Product and permanently reserved after soft deletion',
    `status`        VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'inactive' COMMENT 'Option lifecycle status; exact lowercase active or inactive; independent of deleted_at',
    `display_order` INT NOT NULL COMMENT 'Business-controlled positive order within the Product scope; assigned by the Product application through maatify/persistence',
    `created_at`    DATETIME NOT NULL COMMENT 'Host Clock timestamp assigned by the Product application when the Option is created',
    `updated_at`    DATETIME NOT NULL COMMENT 'Host Clock timestamp assigned by the Product application on mutation, ordering, soft delete, or restore',
    `deleted_at`    DATETIME NULL COMMENT 'Host Clock timestamp assigned by the Product application on soft delete; NULL means not deleted',

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_maa_product_options_product_code` (`product_id`, `code`),
    UNIQUE KEY `uq_maa_product_options_id_product` (`id`, `product_id`),
    KEY `idx_maa_product_options_product_status_deleted_order` (`product_id`, `status`, `deleted_at`, `display_order`, `id`),
    CONSTRAINT `chk_maa_product_options_code` CHECK (
        `code` REGEXP '[^[:space:]]' AND CHAR_LENGTH(`code`) <= 100
    ),
    CONSTRAINT `chk_maa_product_options_status` CHECK (
        BINARY `status` IN (BINARY 'active', BINARY 'inactive')
    ),
    CONSTRAINT `chk_maa_product_options_display_order` CHECK (`display_order` > 0),
    CONSTRAINT `fk_maa_product_options_product` FOREIGN KEY (`product_id`)
        REFERENCES `maa_product_products` (`id`)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci
COMMENT = 'Variant-defining Product Options with stable codes; candidate key (id, product_id) serves composite foreign keys';

CREATE TABLE `maa_product_option_contents`
(
    `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Internal immutable Option Content identity generated by the database',
    `option_id`              BIGINT UNSIGNED NOT NULL COMMENT 'Internal Option identity owning this Content',
    `language_code`          VARCHAR(16) NULL COMMENT 'Nullable language identity: NULL is unlocalized Content; non-NULL is exact-language Content; Host owns semantic language support and fallback',
    `language_code_identity` VARCHAR(16) GENERATED ALWAYS AS (COALESCE(`language_code`, '')) STORED COMMENT 'Generated uniqueness key mapping the unlocalized NULL identity to one database value',
    `name`                   VARCHAR(255) NOT NULL COMMENT 'Option label for the unlocalized or exact-language Content; stored text, not HTML',
    `created_at`             DATETIME NOT NULL COMMENT 'Host Clock timestamp assigned by the Product application when the Content is created',
    `updated_at`             DATETIME NOT NULL COMMENT 'Host Clock timestamp assigned by the Product application on Content mutation, soft delete, or restore',
    `deleted_at`             DATETIME NULL COMMENT 'Host Clock timestamp assigned by the Product application on soft delete; NULL means not deleted',

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_maa_product_option_contents_identity` (`option_id`, `language_code_identity`),
    CONSTRAINT `chk_maa_product_option_contents_language_code` CHECK (
        `language_code` IS NULL
        OR (`language_code` REGEXP '[^[:space:]]' AND CHAR_LENGTH(`language_code`) <= 16)
    ),
    CONSTRAINT `chk_maa_product_option_contents_name` CHECK (`name` REGEXP '[^[:space:]]'),
    CONSTRAINT `fk_maa_product_option_contents_option` FOREIGN KEY (`option_id`)
        REFERENCES `maa_product_options` (`id`)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci
COMMENT = 'Unlocalized and exact-language Option labels keyed by Option and nullable language identity';

CREATE TABLE `maa_product_option_values`
(
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Internal immutable Option Value identity generated by the database',
    `option_id`     BIGINT UNSIGNED NOT NULL COMMENT 'Internal Option identity owning this Value',
    `code`          VARCHAR(100) NOT NULL COMMENT 'Stable immutable Option Value business key; unique within its Option and permanently reserved after soft deletion',
    `status`        VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'inactive' COMMENT 'Option Value lifecycle status; exact lowercase active or inactive; independent of deleted_at',
    `display_order` INT NOT NULL COMMENT 'Business-controlled positive order within the Option scope; assigned by the Product application through maatify/persistence',
    `created_at`    DATETIME NOT NULL COMMENT 'Host Clock timestamp assigned by the Product application when the Value is created',
    `updated_at`    DATETIME NOT NULL COMMENT 'Host Clock timestamp assigned by the Product application on mutation, ordering, soft delete, or restore',
    `deleted_at`    DATETIME NULL COMMENT 'Host Clock timestamp assigned by the Product application on soft delete; NULL means not deleted',

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_maa_product_option_values_option_code` (`option_id`, `code`),
    UNIQUE KEY `uq_maa_product_option_values_id_option` (`id`, `option_id`),
    KEY `idx_maa_product_option_values_option_status_deleted_order` (`option_id`, `status`, `deleted_at`, `display_order`, `id`),
    CONSTRAINT `chk_maa_product_option_values_code` CHECK (
        `code` REGEXP '[^[:space:]]' AND CHAR_LENGTH(`code`) <= 100
    ),
    CONSTRAINT `chk_maa_product_option_values_status` CHECK (
        BINARY `status` IN (BINARY 'active', BINARY 'inactive')
    ),
    CONSTRAINT `chk_maa_product_option_values_display_order` CHECK (`display_order` > 0),
    CONSTRAINT `fk_maa_product_option_values_option` FOREIGN KEY (`option_id`)
        REFERENCES `maa_product_options` (`id`)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci
COMMENT = 'Allowed Values of a variant-defining Option with stable codes; candidate key (id, option_id) serves composite foreign keys';

CREATE TABLE `maa_product_option_value_contents`
(
    `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Internal immutable Option Value Content identity generated by the database',
    `option_value_id`        BIGINT UNSIGNED NOT NULL COMMENT 'Internal Option Value identity owning this Content',
    `language_code`          VARCHAR(16) NULL COMMENT 'Nullable language identity: NULL is unlocalized Content; non-NULL is exact-language Content; Host owns semantic language support and fallback',
    `language_code_identity` VARCHAR(16) GENERATED ALWAYS AS (COALESCE(`language_code`, '')) STORED COMMENT 'Generated uniqueness key mapping the unlocalized NULL identity to one database value',
    `name`                   VARCHAR(255) NOT NULL COMMENT 'Option Value label for the unlocalized or exact-language Content; stored text, not HTML',
    `created_at`             DATETIME NOT NULL COMMENT 'Host Clock timestamp assigned by the Product application when the Content is created',
    `updated_at`             DATETIME NOT NULL COMMENT 'Host Clock timestamp assigned by the Product application on Content mutation, soft delete, or restore',
    `deleted_at`             DATETIME NULL COMMENT 'Host Clock timestamp assigned by the Product application on soft delete; NULL means not deleted',

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_maa_product_option_value_contents_identity` (`option_value_id`, `language_code_identity`),
    CONSTRAINT `chk_maa_product_option_value_contents_language_code` CHECK (
        `language_code` IS NULL
        OR (`language_code` REGEXP '[^[:space:]]' AND CHAR_LENGTH(`language_code`) <= 16)
    ),
    CONSTRAINT `chk_maa_product_option_value_contents_name` CHECK (`name` REGEXP '[^[:space:]]'),
    CONSTRAINT `fk_maa_product_option_value_contents_option_value` FOREIGN KEY (`option_value_id`)
        REFERENCES `maa_product_option_values` (`id`)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci
COMMENT = 'Unlocalized and exact-language Option Value labels keyed by Option Value and nullable language identity';

CREATE TABLE `maa_product_variants`
(
    `id`                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Internal immutable Variant identity; canonical sellable-unit reference for external domains',
    `product_id`              BIGINT UNSIGNED NOT NULL COMMENT 'Internal Product identity owning this Variant',
    `composition_fingerprint` CHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Immutable lowercase hexadecimal SHA-256 of the canonical V1 composition string; unique per Product across all history',
    `sku`                     VARCHAR(128) NULL COMMENT 'Optional secondary SKU identifier; NULL or non-empty; unique while assigned; not a Variant identity or an external reference',
    `barcode`                 VARCHAR(128) NULL COMMENT 'Optional secondary barcode identifier stored as a string to preserve leading zeroes; NULL or non-empty; unique while assigned',
    `status`                  VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Variant lifecycle status; exact lowercase active or inactive; independent of deleted_at; assigned explicitly by the application',
    `display_order`           INT NOT NULL COMMENT 'Business-controlled positive order within the Product scope; assigned by the Product application through maatify/persistence',
    `created_at`              DATETIME NOT NULL COMMENT 'Host Clock timestamp assigned by the Product application when the Variant is created',
    `updated_at`              DATETIME NOT NULL COMMENT 'Host Clock timestamp assigned by the Product application on mutation, ordering, soft delete, or restore',
    `deleted_at`              DATETIME NULL COMMENT 'Host Clock timestamp assigned by the Product application on soft delete; NULL means not deleted',

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_maa_product_variants_product_fingerprint` (`product_id`, `composition_fingerprint`),
    UNIQUE KEY `uq_maa_product_variants_id_product` (`id`, `product_id`),
    UNIQUE KEY `uq_maa_product_variants_sku` (`sku`),
    UNIQUE KEY `uq_maa_product_variants_barcode` (`barcode`),
    KEY `idx_maa_product_variants_product_status_deleted_order` (`product_id`, `status`, `deleted_at`, `display_order`, `id`),
    CONSTRAINT `chk_maa_product_variants_fingerprint` CHECK (`composition_fingerprint` REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT `chk_maa_product_variants_sku` CHECK (
        `sku` IS NULL
        OR (`sku` REGEXP '[^[:space:]]' AND CHAR_LENGTH(`sku`) <= 128)
    ),
    CONSTRAINT `chk_maa_product_variants_barcode` CHECK (
        `barcode` IS NULL
        OR (`barcode` REGEXP '[^[:space:]]' AND CHAR_LENGTH(`barcode`) <= 128)
    ),
    CONSTRAINT `chk_maa_product_variants_status` CHECK (
        BINARY `status` IN (BINARY 'active', BINARY 'inactive')
    ),
    CONSTRAINT `chk_maa_product_variants_display_order` CHECK (`display_order` > 0),
    CONSTRAINT `fk_maa_product_variants_product` FOREIGN KEY (`product_id`)
        REFERENCES `maa_product_products` (`id`)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci
COMMENT = 'Sellable-unit Variant identity with immutable composition fingerprint; candidate key (id, product_id) serves composite foreign keys';

CREATE TABLE `maa_product_variant_option_values`
(
    `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Internal immutable composition pair identity generated by the database',
    `product_id`      BIGINT UNSIGNED NOT NULL COMMENT 'Internal Product identity; proves the Variant and Option belong to the same Product through composite foreign keys',
    `variant_id`      BIGINT UNSIGNED NOT NULL COMMENT 'Internal Variant identity owning this composition pair',
    `option_id`       BIGINT UNSIGNED NOT NULL COMMENT 'Internal Option identity selected by this pair; must belong to product_id',
    `option_value_id` BIGINT UNSIGNED NOT NULL COMMENT 'Internal Option Value identity selected by this pair; must belong to option_id',

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_maa_product_variant_option_values_variant_option` (`variant_id`, `option_id`),
    KEY `idx_maa_product_variant_option_values_variant_product` (`variant_id`, `product_id`),
    KEY `idx_maa_product_variant_option_values_option_product` (`option_id`, `product_id`),
    KEY `idx_maa_product_variant_option_values_value_option` (`option_value_id`, `option_id`),
    CONSTRAINT `fk_maa_product_variant_option_values_variant_product` FOREIGN KEY (`variant_id`, `product_id`)
        REFERENCES `maa_product_variants` (`id`, `product_id`)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT,
    CONSTRAINT `fk_maa_product_variant_option_values_option_product` FOREIGN KEY (`option_id`, `product_id`)
        REFERENCES `maa_product_options` (`id`, `product_id`)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT,
    CONSTRAINT `fk_maa_product_variant_option_values_value_option` FOREIGN KEY (`option_value_id`, `option_id`)
        REFERENCES `maa_product_option_values` (`id`, `option_id`)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci
COMMENT = 'Immutable Variant composition pairs with composite foreign keys enforcing same-Product and same-Option ownership';

CREATE TABLE `maa_product_image_roles`
(
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Internal immutable Product Image Role identity generated by the database; separate namespace from any Category role',
    `role_key`   VARCHAR(100) NOT NULL COMMENT 'Host-created immutable Role key; unique in the Product role namespace and permanently reserved after soft deletion',
    `status`     VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT 'active' COMMENT 'Role lifecycle status; exact lowercase active or inactive; independent of deleted_at',
    `created_at` DATETIME NOT NULL COMMENT 'Host Clock timestamp assigned by the Product application when the Role is created',
    `updated_at` DATETIME NOT NULL COMMENT 'Host Clock timestamp assigned by the Product application on mutation, soft delete, or restore',
    `deleted_at` DATETIME NULL COMMENT 'Host Clock timestamp assigned by the Product application on soft delete; NULL means not deleted',

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_maa_product_image_roles_role_key` (`role_key`),
    KEY `idx_maa_product_image_roles_status_deleted_key` (`status`, `deleted_at`, `role_key`, `id`),
    CONSTRAINT `chk_maa_product_image_roles_role_key` CHECK (
        `role_key` REGEXP '[^[:space:]]' AND CHAR_LENGTH(`role_key`) <= 100
    ),
    CONSTRAINT `chk_maa_product_image_roles_status` CHECK (
        BINARY `status` IN (BINARY 'active', BINARY 'inactive')
    )
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci
COMMENT = 'Package-owned immutable Product Image Role registry with lifecycle state; no roles are seeded';

CREATE TABLE `maa_product_image_assignments`
(
    `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Internal immutable Product Image Assignment identity generated by the database',
    `product_id`             BIGINT UNSIGNED NOT NULL COMMENT 'Internal Product identity anchoring this assignment; always validated by an independent foreign key, including Product-level targets',
    `variant_id`             BIGINT UNSIGNED NULL COMMENT 'Nullable internal Variant target; NULL is the Product-level target; non-NULL must belong to product_id through a composite foreign key',
    `media_asset_id`         BIGINT UNSIGNED NOT NULL COMMENT 'Host-provided external Media Asset identity; positive; deliberately has no foreign key',
    `role_id`                BIGINT UNSIGNED NULL COMMENT 'Nullable internal Product Image Role identity; NULL is the exact generic/unclassified scope, not a fallback',
    `language_code`          VARCHAR(16) NULL COMMENT 'Nullable exact language scope; NULL is a distinct unlocalized scope; Host owns semantic language support and fallback',
    `platform`               VARCHAR(255) NULL COMMENT 'Nullable exact platform scope; NULL is a distinct exact NULL scope; no hardcoded platform enum; Host owns platform semantics',
    `variant_id_identity`    BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(`variant_id`, 0)) STORED COMMENT 'Generated uniqueness key mapping the Product-level NULL target to one database value',
    `role_id_identity`       BIGINT UNSIGNED GENERATED ALWAYS AS (COALESCE(`role_id`, 0)) STORED COMMENT 'Generated uniqueness key mapping NULL Role to one database value',
    `language_code_identity` VARCHAR(16) GENERATED ALWAYS AS (COALESCE(`language_code`, '')) STORED COMMENT 'Generated uniqueness key mapping NULL language to one database value',
    `platform_identity`      VARCHAR(255) GENERATED ALWAYS AS (COALESCE(`platform`, '')) STORED COMMENT 'Generated uniqueness key mapping NULL platform to one database value',
    `ordering_scope`         VARCHAR(512) GENERATED ALWAYS AS (
        CONCAT(
            'P', CAST(`product_id` AS CHAR), '|',
            CASE
                WHEN `variant_id` IS NULL THEN 'N:'
                ELSE CONCAT('V', CAST(`variant_id` AS CHAR))
            END,
            '|',
            CASE
                WHEN `language_code` IS NULL THEN 'N:'
                ELSE CONCAT('L', CHAR_LENGTH(`language_code`), ':', `language_code`)
            END,
            '|',
            CASE
                WHEN `platform` IS NULL THEN 'N:'
                ELSE CONCAT('L', CHAR_LENGTH(`platform`), ':', `platform`)
            END,
            '|',
            CASE
                WHEN `role_id` IS NULL THEN 'N:'
                ELSE CONCAT('R', CAST(`role_id` AS CHAR))
            END
        )
    ) STORED COMMENT 'Generated exact Product/Variant target plus language/platform/role scope for maatify/persistence ordering and default uniqueness',
    `is_default`             TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Explicit default marker within the exact target and scope; zero or one active default is allowed; never auto-promoted',
    `display_order`          INT NOT NULL COMMENT 'Business-controlled positive order within the exact target and scope; assigned by the Product application through maatify/persistence',
    `created_at`             DATETIME NOT NULL COMMENT 'Host Clock timestamp assigned by the Product application when the assignment is created',
    `updated_at`             DATETIME NOT NULL COMMENT 'Host Clock timestamp assigned by the Product application on ordering, default mutation, soft delete, or restore',
    `deleted_at`             DATETIME NULL COMMENT 'Host Clock timestamp assigned by the Product application on soft delete; NULL means not deleted',
    `default_scope_identity` VARCHAR(512) GENERATED ALWAYS AS (
        CASE
            WHEN `is_default` = 1 AND `deleted_at` IS NULL THEN `ordering_scope`
            ELSE NULL
        END
    ) STORED COMMENT 'Conditional generated exact-scope identity; only an active default row participates in the unique key',

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_maa_product_image_assignments_identity` (
        `product_id`, `variant_id_identity`, `media_asset_id`, `role_id_identity`, `language_code_identity`, `platform_identity`
    ),
    UNIQUE KEY `uq_maa_product_image_assignments_default_scope` (`default_scope_identity`),
    KEY `idx_maa_product_image_assignments_variant_product` (`variant_id`, `product_id`),
    KEY `idx_maa_product_image_assignments_role` (`role_id`),
    KEY `idx_maa_product_image_assignments_ordering` (`ordering_scope`, `deleted_at`, `display_order`, `id`),
    CONSTRAINT `chk_maa_product_image_assignments_variant_id` CHECK (`variant_id` IS NULL OR `variant_id` > 0),
    CONSTRAINT `chk_maa_product_image_assignments_media_asset_id` CHECK (`media_asset_id` > 0),
    CONSTRAINT `chk_maa_product_image_assignments_role_id` CHECK (`role_id` IS NULL OR `role_id` > 0),
    CONSTRAINT `chk_maa_product_image_assignments_language_code` CHECK (
        `language_code` IS NULL
        OR (`language_code` REGEXP '[^[:space:]]' AND CHAR_LENGTH(`language_code`) <= 16)
    ),
    CONSTRAINT `chk_maa_product_image_assignments_platform` CHECK (
        `platform` IS NULL
        OR (`platform` REGEXP '[^[:space:]]' AND CHAR_LENGTH(`platform`) <= 255)
    ),
    CONSTRAINT `chk_maa_product_image_assignments_is_default` CHECK (`is_default` IN (0, 1)),
    CONSTRAINT `chk_maa_product_image_assignments_default_not_deleted` CHECK (`deleted_at` IS NULL OR `is_default` = 0),
    CONSTRAINT `chk_maa_product_image_assignments_display_order` CHECK (`display_order` > 0),
    CONSTRAINT `fk_maa_product_image_assignments_product` FOREIGN KEY (`product_id`)
        REFERENCES `maa_product_products` (`id`)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT,
    CONSTRAINT `fk_maa_product_image_assignments_variant_product` FOREIGN KEY (`variant_id`, `product_id`)
        REFERENCES `maa_product_variants` (`id`, `product_id`)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT,
    CONSTRAINT `fk_maa_product_image_assignments_role` FOREIGN KEY (`role_id`)
        REFERENCES `maa_product_image_roles` (`id`)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
)
ENGINE = InnoDB
DEFAULT CHARSET = utf8mb4
COLLATE = utf8mb4_unicode_ci
COMMENT = 'Product-owned exact-scope links from a Product or one of its Variants to externally owned Media Asset identities';
