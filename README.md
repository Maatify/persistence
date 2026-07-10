# Maatify Persistence

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)
[![PHP: >=8.2](https://img.shields.io/badge/PHP-%3E%3D8.2-777bb4.svg)](https://php.net)

`maatify/persistence` provides reusable, framework-agnostic PDO utilities for Maatify projects. Currently, it focuses on providing robust scoped and global ordering utilities for database tables using integer ordering columns (such as `display_order`).

## Key Features

* **Global and Scoped Ordering**: Easily manage display order across an entire table or within a specific scope.
* **Transaction Ownership**: Handles its own transactions and locks the necessary scope reliably.
* **SQL Identifier Validation**: Ensures table and column configurations are safe and properly quoted.
* **Soft-Delete Filtering**: Optional support for ignoring soft-deleted rows in ordering calculations.
* **Scope Isolation**: Ensures only the affected range within the configured scope is updated.

## Requirements

**Runtime requirements:**
* PHP `>= 8.2`
* `ext-pdo`
* `maatify/exceptions ^1.0`

**Database behavior:**
* The package behavior is designed and verified against MySQL.

## Installation

```bash
composer require maatify/persistence
```

## Quick Usage

```php
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
use Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;

// 1. Configure the ordering behavior for a table
$config = new ScopedOrderingConfig(
    table: 'maa_shipping_rates',
    scopeColumn: 'method_id', // Use null for global ordering
    idColumn: 'id',
    orderColumn: 'display_order',
    deletedAtColumn: 'deleted_at', // Use null if soft-deletes are not used
);

$ordering = new ScopedOrderingManager();

// 2. Get the next position for a new insert
$nextPosition = $ordering->getNextPosition(
    pdo: $pdo,
    config: $config,
    scopeValue: 2, // Use null for global ordering
);

// 3. Move an existing row within its scope
$success = $ordering->moveWithinScope(
    pdo: $pdo,
    config: $config,
    scopeValue: 2, // Use null for global ordering
    id: 15,
    newOrder: 4,
);
```

### Note on `getNextPosition()` Concurrency
`getNextPosition()` does not start a transaction and does not lock the scope. When using it for concurrent inserts, the host application must provide an appropriate transaction and locking mechanism at the repository/application level.

## Public Runtime API

The package currently provides the following public classes for PDO ordering:

```php
Maatify\Persistence\Pdo\Ordering\ScopedOrderingConfig;
Maatify\Persistence\Pdo\Ordering\ScopedOrderingManager;

// Exceptions
Maatify\Persistence\Exception\PersistenceException;
Maatify\Persistence\Exception\InvalidOrderingConfigurationException;
Maatify\Persistence\Exception\InvalidOrderingOperationException;
Maatify\Persistence\Exception\OrderingTransactionException;
```

## Critical Runtime Behavior

**`moveWithinScope()`:**
* Rejects inconsistent scope usage.
* Rejects `id <= 0`.
* Rejects `newOrder <= 0`.
* Rejects caller-owned active PDO transactions.
* Owns its own transaction.
* Locks the applicable active scope using `SELECT ... FOR UPDATE`.
* Reads the current order from the database within the same transaction.
* Does not trust a current order provided by the caller.
* Returns `false` if the target row is missing.
* Clamps values higher than the maximum position to the maximum available position.
* Returns `true` if the movement is a no-op (already at the requested position).
* Moves only the affected range.
* Does not globally normalize pre-existing gaps.
* Rolls back and returns `false` if the final target update fails.
* Rolls back on any Throwable after starting the transaction.
* Rethrows the original Throwable without arbitrary wrapping.

**`rowExistsInScope()`:**
* Returns `false` for `id <= 0`.
* Returns `false` if the row is not found within the configured scope.
* Treats soft-deleted rows as non-existent if `deletedAtColumn` is configured.
* Throws `InvalidOrderingOperationException` on invalid scope usage.
* External PDO errors propagate unmodified.

## Architecture Guarantees

* Standalone Composer package.
* Framework-agnostic.
* Host-agnostic.
* PDO-based.
* No ORM.
* No framework bindings.
* No HTTP endpoints, UI, controllers, or routes.
* No host table ownership.
* No generic application repository abstraction.
* The host provides the PDO connection.
* Trusted SQL identifiers.
* Runtime values use prepared statements.

## Exception and Error-Propagation

All package-defined exceptions implement the marker interface `Maatify\Persistence\Exception\PersistenceException`. However, this interface is not a catch-all. `PDOException` or other external `Throwable`s may propagate without wrapping and require a separate catch or an outer `Throwable` boundary if handling is needed.

## Security & Trust Boundaries

The `ScopedOrderingConfig` validates and quotes all configured table and column identifiers. However, these identifiers **must** still be provided as trusted application configurations (e.g., constants), never as raw user input. All actual runtime values are safely passed using PDO prepared statements.

## Documentation

For a comprehensive guide, please refer to the main technical reference:
* [Persistence Package Reference](PERSISTENCE_PACKAGE_REFERENCE.md)

Other important documentation:
* [Changelog](CHANGELOG.md)
* [Security Policy](SECURITY.md)
* [Contributing Guide](CONTRIBUTING.md)
* [Code of Conduct](CODE_OF_CONDUCT.md)

## Quality Status

* PHP 8.2–8.5 verification in CI.
* PHPStan Level Max.
* Unit, Regression, and MySQL Integration tests.
* Lowest dependencies verification.
* Stable CI Gate.

**Integration testing:**
* Real MySQL is required for Integration tests.
* SQLite is not an Integration substitute.
* MySQL `8.4.10` is the currently verified CI baseline.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.
