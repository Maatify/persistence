# Maatify Persistence

[![Latest Version](https://img.shields.io/packagist/v/maatify/persistence.svg?style=for-the-badge)](https://packagist.org/packages/maatify/persistence)
[![PHP Version](https://img.shields.io/packagist/php-v/maatify/persistence.svg?style=for-the-badge)](https://packagist.org/packages/maatify/persistence)
[![License](https://img.shields.io/packagist/l/maatify/persistence.svg?style=for-the-badge)](LICENSE)

![PHPStan](https://img.shields.io/badge/PHPStan-Level%20Max-4E8CAE)

[![Changelog](https://img.shields.io/badge/Changelog-View-blue)](CHANGELOG.md)
[![Package Reference](https://img.shields.io/badge/Package_Reference-View-green)](PERSISTENCE_PACKAGE_REFERENCE.md)
[![Security](https://img.shields.io/badge/Security-Policy-important)](SECURITY.md)

![Monthly Downloads](https://img.shields.io/packagist/dm/maatify/persistence?label=Monthly%20Downloads&color=00A8E8)
![Total Downloads](https://img.shields.io/packagist/dt/maatify/persistence?label=Total%20Downloads&color=2AA9E0)

![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-blueviolet?style=for-the-badge)

[![Install](https://img.shields.io/badge/Install-composer%20require%20maatify%2Fpersistence-blue?style=for-the-badge)](https://packagist.org/packages/maatify/persistence)

Reusable persistence-layer utilities for Maatify projects.

This package contains infrastructure-level helpers that are shared across repositories/modules, without coupling domain modules to each other.

## Installation

```bash
composer require maatify/persistence
```

## Requirements

* PHP `>= 8.2`
* ext-pdo
* maatify/exceptions `^1.0`

Composer installs `maatify/exceptions` automatically as a declared Runtime dependency.

## Namespace

```php
Maatify\Persistence
```

## Current Components

### PDO Ordering

The package currently provides a scoped ordering manager for tables that use integer ordering columns such as `display_order`.

```php
Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;

Maatify\Persistence\Exception\PersistenceException;
Maatify\Persistence\Exception\InvalidOrderingConfigurationException;
Maatify\Persistence\Exception\InvalidOrderingOperationException;
Maatify\Persistence\Exception\OrderingTransactionException;
```

It supports:

* global ordering
* scoped ordering
* SQL identifier validation and quoting
* optional soft-delete filtering
* `getNextPosition()` behavior
* `rowExistsInScope()` behavior
* `moveWithinScope()` behavior
* scope locking
* target-row lookup inside the transaction
* clamping
* gaps are not globally normalized
* scope isolation
* transaction ownership
* caller-owned transaction rejection
* missing target behavior
* target-update failure behavior
* rollback behavior
* PDO/database error propagation

Note that not all failures become package-defined exceptions; some are propagated.
---

## Ordering Configuration

Use `ScopedOrderingConfig` to describe the table and columns used for ordering.

```php
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;

$config = new ScopedOrderingConfig(
    table: 'maa_shipping_rates',
    scopeColumn: 'method_id',
    idColumn: 'id',
    orderColumn: 'display_order',
    deletedAtColumn: 'deleted_at',
);
```

### Constructor Arguments

| Argument          |      Type |         Default | Description                                                    |
| ----------------- | --------: | --------------: | -------------------------------------------------------------- |
| `table`           |  `string` |        required | Trusted table identifier. Supports `table` or `schema.table`.  |
| `scopeColumn`     | `?string` |          `null` | Optional scope column. Example: `method_id`.                   |
| `idColumn`        |  `string` |            `id` | Primary key column.                                            |
| `orderColumn`     |  `string` | `display_order` | Ordering column.                                               |
| `deletedAtColumn` | `?string` |    `deleted_at` | Soft-delete column. Use `null` for tables without soft delete. |

### Important

Table and column names are SQL identifiers and cannot be bound as PDO parameters.

`ScopedOrderingConfig` validates and quotes identifiers internally, but identifiers must still be trusted application constants, never raw user input.

Allowed table identifiers:

```text
table
schema.table
```

Allowed column identifiers:

```text
column_name
```

---

## Getting the Next Position

```php
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;

$ordering = new ScopedOrderingManager();

$nextPosition = $ordering->getNextPosition(
    pdo: $pdo,
    config: $config,
    scopeValue: 12,
);
```

For global ordering:

```php
$config = new ScopedOrderingConfig(
    table: 'maa_shipping_methods',
    scopeColumn: null,
    idColumn: 'id',
    orderColumn: 'display_order',
    deletedAtColumn: null,
);

$nextPosition = $ordering->getNextPosition(
    pdo: $pdo,
    config: $config,
    scopeValue: null,
);
```

### Concurrency Note

`getNextPosition()` does not start a transaction and does not lock the scope.

If used for concurrent inserts, call it from a caller-owned transaction with appropriate repository/application-level locking.

---

## Moving a Row Within Scope

```php
$success = $ordering->moveWithinScope(
    pdo: $pdo,
    config: $config,
    scopeValue: 12,
    id: 55,
    newOrder: 3,
);
```

### Behavior

`moveWithinScope()`:

1. Starts its own transaction.
2. Locks all rows in the configured ordering scope using `SELECT ... FOR UPDATE`.
3. Reads the target row's current order from the database.
4. Clamps `newOrder` to the current maximum position in the scope.
5. Shifts affected rows up or down.
6. Updates the target row to the final order.
7. Commits the transaction.

It does **not** trust a caller-provided current order.

### Return Values

|  Return | Meaning                                            |
| ------: | -------------------------------------------------- |
|  `true` | Move succeeded.                                    |
|  `true` | Row was already at the requested/clamped position. |
| `false` | Target row does not exist in the configured scope. |
| `false` | Target row could not be updated.                   |

### Transaction Ownership

`moveWithinScope()` owns its transaction.

It must be called outside active PDO transactions.

If an active transaction already exists, it throws:

```php
Maatify\Persistence\Exception\OrderingTransactionException
```

---

## Checking Row Existence

```php
$exists = $ordering->rowExistsInScope(
    pdo: $pdo,
    config: $config,
    scopeValue: 12,
    id: 55,
);
```

If `deletedAtColumn` is configured, soft-deleted rows are treated as non-existing.

---

## Examples

### Global Ordering

```php
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;

$config = new ScopedOrderingConfig(
    table: 'maa_shipping_methods',
    scopeColumn: null,
    idColumn: 'id',
    orderColumn: 'display_order',
    deletedAtColumn: null,
);

$ordering = new ScopedOrderingManager();

$next = $ordering->getNextPosition($pdo, $config);

$ordering->moveWithinScope(
    pdo: $pdo,
    config: $config,
    scopeValue: null,
    id: 5,
    newOrder: 1,
);
```

### Scoped Ordering

```php
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;

$config = new ScopedOrderingConfig(
    table: 'maa_shipping_rates',
    scopeColumn: 'method_id',
    idColumn: 'id',
    orderColumn: 'display_order',
    deletedAtColumn: 'deleted_at',
);

$ordering = new ScopedOrderingManager();

$next = $ordering->getNextPosition(
    pdo: $pdo,
    config: $config,
    scopeValue: 2,
);

$ordering->moveWithinScope(
    pdo: $pdo,
    config: $config,
    scopeValue: 2,
    id: 15,
    newOrder: 4,
);
```

---

## Exceptions

All package-defined exceptions implement the package marker interface:

```php
Maatify\Persistence\Exception\PersistenceException
```

This interface extends `\Throwable` and is implemented only by package-defined exceptions. It does not include every possible throwable emitted by PDO or external infrastructure.

### Exception Hierarchy

| Exception | Base Class | Error Code | Purpose |
| --------- | ---------- | ---------- | ------- |
| `InvalidOrderingConfigurationException` | `Maatify\Exceptions\Exception\System\SystemMaatifyException` | `ErrorCodeEnum::MAATIFY_ERROR` | invalid or unsafe trusted SQL configuration identifiers; programming/configuration mistakes |
| `InvalidOrderingOperationException` | `Maatify\Exceptions\Exception\Validation\ValidationMaatifyException` | `ErrorCodeEnum::INVALID_ARGUMENT` | invalid runtime id; invalid new order; invalid scope usage |
| `OrderingTransactionException` | `Maatify\Exceptions\Exception\Unsupported\UnsupportedMaatifyException` (defaultIsSafe: false) | `ErrorCodeEnum::UNSUPPORTED_OPERATION` | `moveWithinScope()` called while PDO already has an active transaction |

### Catching

Consumers may catch package-defined exceptions using:

```php
use Maatify\Persistence\Exception\PersistenceException;

try {
    $ordering->moveWithinScope(
        pdo: $pdo,
        config: $config,
        scopeValue: 2,
        id: 15,
        newOrder: 4,
    );
} catch (PersistenceException $e) {
    // Handle package-level persistence exception (e.g. validation, configuration, transaction rules).
} catch (\PDOException $e) {
    // Handle PDO/database failures which propagate unchanged.
} catch (\Throwable $e) {
    // Outer boundary for any other external failures.
}
```

Do not assume that `PersistenceException` catches every failure originating from a manager call. PDO/database failures propagate unchanged and require a separate catch or an outer `\Throwable` boundary.

---

## Design Notes

### Why not static methods?

`ScopedOrderingManager` is intentionally a service class, not a static helper.

This makes it easier to:

* Inject through a container.
* Test in isolation.
* Replace or decorate later.
* Add logging or driver-specific behavior in the future.

### Why pass PDO per call?

The manager is stateless. Passing `PDO` per call allows the same manager instance to be reused with different connections.

---

## Development and Integration testing

Run static analysis, unit, regression, and integration tests:

```bash
composer analyse
composer test:unit
composer test:regression
composer test:integration
composer test
```

### MySQL Integration testing

Integration tests require real MySQL. SQLite is not supported as an Integration substitute.

The exact MySQL Integration environment contract requires these environment variables:
* `PERSISTENCE_TEST_MYSQL_DSN`
* `PERSISTENCE_TEST_MYSQL_USER`
* `PERSISTENCE_TEST_MYSQL_PASSWORD`

The test user must have package-local table and trigger privileges.
Trigger-based rollback tests may require the temporary/local MySQL server to permit trusted trigger creators when binary logging is enabled.

CI currently verifies Integration behavior against MySQL 8.4.10. This is the verified test baseline, not a declaration that every other MySQL version is supported or unsupported.

### CI Architecture

The current CI architecture tests:
* PHP 8.2–8.5 Unit/Regression
* PHP 8.2 and 8.5 Integration
* latest-compatible dependencies
* lowest-supported dependencies
* real MySQL
* PHPStan max
* formatter dry-run
* Composer audit
* workflow lint
* stable `CI Gate`

## License

MIT
