# Changelog

All notable changes to `maatify/persistence` will be documented in this file.

The format is intentionally simple and follows release-style sections:
`Added`, `Changed`, `Fixed`, `Deprecated`, `Removed`, and `Security`.

## [Unreleased]

## [1.0.0]

### Added

* standalone PDO scoped-ordering package
* global and scoped ordering
* SQL identifier validation and quoting
* configurable id/order/scope/deleted-at columns
* soft-delete filtering
* next-position lookup
* scoped row-existence lookup
* transaction-owned movement
* scope locking
* clamping
* package exception marker
* integration with `maatify/exceptions`
* Unit, Regression, and MySQL Integration test suites
* rollback failure-injection coverage
* PHP compatibility CI
* lowest-supported dependency CI
* MySQL repeatability/residue verification
* stable `CI Gate`
* package reference and security policy

### Exception architecture

* `PersistenceException` (package marker interface extending `\Throwable`)
* `InvalidOrderingConfigurationException` (extends `SystemMaatifyException`, ErrorCode: `MAATIFY_ERROR`)
* `InvalidOrderingOperationException` (extends `ValidationMaatifyException`, ErrorCode: `INVALID_ARGUMENT`)
* `OrderingTransactionException` (extends `UnsupportedMaatifyException`, ErrorCode: `UNSUPPORTED_OPERATION`)

### Reliability

* rollback and original throwable propagation
* real MySQL testing
* repeated Integration runs
* trigger/table residue verification
* no SQLite substitution

### Documentation

* Rebuilt the README
* Added the canonical package reference (`PERSISTENCE_PACKAGE_REFERENCE.md`)
* Added `SECURITY.md`
* Rebuilt changelog
* Clarified the general exception-standard
