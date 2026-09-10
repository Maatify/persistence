# Ordering Capability Phase — Nullable Scopes and Atomic Mutation Timestamps

**Status:** Release-finalized for `v1.2.0`; ready for publication after CI verification.

**Scope:** `Maatify\Persistence\Pdo\Ordering`

**Related reference:** [Persistence Package Reference](../../PERSISTENCE_PACKAGE_REFERENCE.md)

## Context

Consumers need one shared ordering capability for both nested scopes and root
rows represented by a nullable foreign key. They also need a mutation timestamp
to change atomically with the display order. The ordering lock, range shifts,
target update, and transaction boundary belong to this package; consumers must
not duplicate that behavior in package-local SQL.

## Delivered API

`ScopedOrderingConfig` now supports:

* `nullableScope`: explicitly permits a configured scope column to be matched
  with `IS NULL` when the operation receives a null scope value.
* `updatedAtColumn`: optionally identifies the target mutation timestamp column.

`ScopedOrderingManager::moveWithinScope()` now accepts an optional
`updatedAtValue`. When configured, the value is written with the target order
in the same SQL `UPDATE`.

## Runtime invariants

* Global ordering remains the configuration with no `scopeColumn`.
* A nullable scoped ordering is distinct from global ordering and uses
  `scopeColumn IS NULL` for a null scope value.
* The manager owns the movement transaction when no transaction is active and
  participates in an active caller-owned transaction without committing or
  rolling it back.
* Scope locking, affected-range shifting, target order update, and the optional
  timestamp update commit or roll back together.
* A no-op does not issue a target mutation and therefore does not change its
  timestamp.
* Failure cleanup calls `rollBack()` only while the PDO transaction is active;
  the original throwable is propagated unchanged.

## Compatibility and release

The new constructor and method parameters are appended with defaults, so
existing calls remain source-compatible. Consumers may adopt this capability
only after it is published as a stable package version; this phase does not
introduce host-specific schema or framework behavior.

## Verification

The phase is verified by configuration unit tests, public API regression tests,
MySQL integration coverage for nullable scopes and atomic timestamp updates,
failure-injection rollback coverage, PHPStan at level max, and the package code
style check. The capability is prepared for the `v1.2.0` release. Publish the
`v1.2.0` tag and release notes from the verified release HEAD.
