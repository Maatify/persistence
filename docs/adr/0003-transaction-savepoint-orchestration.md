# ADR 0003: Transaction Savepoint Orchestration

- **Status:** Accepted
- **Decision date:** 2026-09-17
- **Runtime status:** Implemented for v1.4.0
- **Implementation authorization:** Implemented
- **Related contract:** [Transaction Savepoint Orchestration Blueprint](../blueprints/TRANSACTION_SAVEPOINT_ORCHESTRATION_BLUEPRINT.md)

## Context
The existing `PdoTransactionRunner` works well for single, global transactions. However, complex orchestration requires operation-local transactional boundaries (savepoints) that do not terminate the outer transaction.

We needed a standardized way to manage `SAVEPOINT`, `ROLLBACK TO SAVEPOINT`, and `RELEASE SAVEPOINT` within a caller-owned transaction, ensuring safe naming, exact Throwable precedence, and isolation of connection behavior.

## Decision
We introduce `SavepointTransactionRunnerInterface` and `PdoSavepointTransactionRunner` to establish operation-local restore points within an active caller-owned transaction.

Both `PdoTransactionRunner` and `PdoSavepointTransactionRunner` remain public, supported, and are not deprecated. Neither replaces the other. Their semantic distinction is:
- **`PdoTransactionRunner`**: provides transaction ownership/participation without operation-local savepoint isolation. When no transaction exists, it owns begin/commit/full rollback; when a caller-owned transaction already exists, it participates without begin/commit/full rollback.
- **`PdoSavepointTransactionRunner`**: provides operation-local savepoint isolation.

Key semantic decisions:
- **Existing Behavior Remains Unchanged:** The existing `PdoTransactionRunner` behavior and `TransactionRunnerInterface` remain completely unchanged.
- **Savepoint Runner Additive Interface:** We add `SavepointTransactionRunnerInterface` extending `TransactionRunnerInterface`.
- **Operation-Local Savepoint Semantics:** Reverts target only the operation-local savepoint. Rollback-to-savepoint never means full rollback of the caller-owned transaction. The runner does not commit or fully roll back the caller-owned transaction, and the outer transaction remains active.
- **Outer Transaction Ownership:** The caller retains ownership of the outer transaction. If no transaction is active, the runner preserves the owned-transaction behavior (no savepoints).
- **Same-PDO Boundary:** Savepoint orchestration is strictly connection-local. The caller-owned outer transaction and all savepoint mutations must execute on the same PDO connection instance.
- **Savepoint Naming Contract:** Savepoints follow the strict format `maatify_persistence_sp_<32 lowercase hex>`, are completely opaque, require no caller input, and are collision-resistant for nested/repeated operations.
- **Throwable Precedence:** The callback's original `Throwable` is always the visible failure. Cleanup failures (like `RELEASE SAVEPOINT` failing) must not obscure or replace the original callback `Throwable`.
- **TransactionExecutionException:** Introduced for package-detected non-throwing failures of transaction control statements (e.g. PDO returning `false` instead of throwing).
- **Nested/Repeated Semantics:** Supports full nesting and repeated sequential savepoints.
- **MySQL Verification Boundary:** The implementation is strictly verified against MySQL 8.4 boundaries.
- **Cross-Connection out of scope:** Cross-connection and distributed transactions are explicitly out of scope.

## Consequences
- Consumers can now safely orchestrate nested operations.
- Strong guarantees that internal failures won't commit or fully roll back caller-owned transactions.
- New capability is entirely opt-in (using `PdoSavepointTransactionRunner`).
