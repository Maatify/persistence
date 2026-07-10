# Changelog

All notable changes to `maatify/persistence` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0]

### Added
* Standalone PDO scoped-ordering package.
* Global and scoped ordering.
* SQL identifier validation and quoting.
* Configurable id, order, scope, and deleted-at columns.
* Soft-delete filtering for missing targets.
* Next-position lookup capability.
* Scoped row-existence lookup.
* Transaction-owned movement operations.
* Scope locking capability (`SELECT ... FOR UPDATE`).
* Position clamping logic.
* Package exception marker (`PersistenceException`).
* Integration with `maatify/exceptions`.
* Unit, Regression, and MySQL Integration test suites.
* Rollback failure-injection coverage.
* PHP compatibility CI.
* Lowest-supported dependency CI.
* MySQL repeatability/residue verification.
* Stable CI Gate.
* Package reference documentation (`PERSISTENCE_PACKAGE_REFERENCE.md`).
* Security policy (`SECURITY.md`).

### Changed
* Refined exception architecture to distinguish between runtime validation (`InvalidOrderingOperationException`), configuration errors (`InvalidOrderingConfigurationException`), and operational constraints (`OrderingTransactionException`).
* Enforced strict propagation for original throwables on rollback failures.
* Enforced real MySQL testing; SQLite substitution is explicitly disabled.

### Fixed
* Fixed gap normalization side effects by keeping operations restricted to the affected range only.
