# Maatify Persistence

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](https://opensource.org/licenses/MIT)
[![PHP: >=8.2](https://img.shields.io/badge/PHP-%3E%3D8.2-777bb4.svg)](https://php.net)

`maatify/persistence` provides reusable, framework-agnostic PDO utilities for Maatify projects. Currently, it focuses on providing robust scoped and global ordering utilities for relational database tables using integer ordering columns (such as `display_order`).

## Key Features

* **Global and Scoped Ordering**: Easily manage display order across an entire table or within a specific scope.
* **Transaction Ownership**: Handles its own transactions and locks the necessary scope reliably.
* **SQL Identifier Validation**: Ensures table and column configurations are safe and properly quoted.
* **Soft-Delete Filtering**: Optional support for ignoring soft-deleted rows in ordering calculations.
* **Scope Isolation**: Ensures only the affected range within the configured scope is updated.

## Requirements

* PHP >= 8.2
* `ext-pdo`
* MySQL (Supported for Integration behaviors)
* `maatify/exceptions` `^1.0`

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
    scopeColumn: 'method_id',
    idColumn: 'id',
    orderColumn: 'display_order',
    deletedAtColumn: 'deleted_at', // Use null if soft-deletes are not used
);

$ordering = new ScopedOrderingManager();

// 2. Get the next position for a new insert in scope '2'
$nextPosition = $ordering->getNextPosition(
    pdo: $pdo,
    config: $config,
    scopeValue: 2,
);

// 3. Move an existing row within its scope
$success = $ordering->moveWithinScope(
    pdo: $pdo,
    config: $config,
    scopeValue: 2,
    id: 15,
    newOrder: 4,
);
```

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

* **Transaction Rejection**: `moveWithinScope()` owns its transaction. It rejects any active PDO transaction initiated by the caller by throwing an `OrderingTransactionException`.
* **Scope Locking**: It uses `SELECT ... FOR UPDATE` to exclusively lock the scope during movement.
* **No-op & Missing Target**: `moveWithinScope()` returns `true` if the row is already at the requested position. It returns `false` if the target row does not exist in the configured scope, treating it gracefully.
* **Clamping**: Requests to move a row beyond the maximum available position in the scope will be clamped to the maximum position securely.
* **Range Limitation**: The ordering shift is applied strictly to the affected range. Gaps are not globally normalized as a side effect.
* **Rollback & Error Propagation**: Any failure during the movement causes a rollback that preserves and propagates the original exception or database error.

## Exception and Error-Propagation

All package-defined exceptions implement the marker interface `Maatify\Persistence\Exception\PersistenceException`. However, this interface does not catch infrastructure failures.

```php
use Maatify\Persistence\Exception\PersistenceException;

try {
    $ordering->moveWithinScope($pdo, $config, 2, 15, 4);
} catch (PersistenceException $e) {
    // Handle package-specific errors: configuration bugs, validation errors, active transactions
} catch (\PDOException $e) {
    // Handle database-level errors (these propagate unmodified)
} catch (\Throwable $e) {
    // Handle other general errors
}
```

## Security & Trust Boundaries

The `ScopedOrderingConfig` validates and quotes all configured table and column identifiers. However, these identifiers **must** still be provided as trusted application configurations (e.g., constants), never as raw user input. All actual runtime values (like `$id` and `$scopeValue`) are safely passed using PDO prepared statements.

## Documentation

For a comprehensive guide, please refer to the main technical reference:
* [Persistence Package Reference](PERSISTENCE_PACKAGE_REFERENCE.md)

Other important documentation:
* [Changelog](CHANGELOG.md)
* [Security Policy](SECURITY.md)
* [Contributing Guide](CONTRIBUTING.md)
* [Code of Conduct](CODE_OF_CONDUCT.md)
* [Package Building Standard](docs/standards/PACKAGE_BUILDING_STANDARD.md)
* [CI Workflow Standard](docs/standards/CI_WORKFLOW_STANDARD.md)

## Development and Testing

To verify the project locally:

```bash
composer validate --strict
composer analyse
composer test:unit
composer test:regression
vendor/bin/php-cs-fixer fix --dry-run --diff
```

**Integration testing requires real MySQL**, defined by `PERSISTENCE_TEST_MYSQL_DSN`, `PERSISTENCE_TEST_MYSQL_USER`, and `PERSISTENCE_TEST_MYSQL_PASSWORD`. SQLite cannot be used as a substitute.

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Maatify

Developed and maintained by [Maatify](https://github.com/Maatify).
