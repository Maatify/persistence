# PDO Transaction Architecture

This document is the durable architectural reference for the complete PDO Transaction capability in `maatify/persistence`.

## v1.3.0 — Transaction composition

### Components

- `TransactionRunnerInterface`
- `PdoTransactionRunner`

### PdoTransactionRunner Roles

- **Public API:** Yes.
- **Supported:** Yes.
- **Deprecated:** No.
- **Intended use:** Intended for transaction ownership/participation without operation-local savepoint isolation.

### Behavior when no transaction is active

When invoked and no outer transaction is active, the runner assumes ownership:
- Begins a new transaction.
- Executes the callback.
- Commits on success.
- Rolls back on failure.
- Propagates the original `Throwable`.

### Behavior when a caller-owned transaction is already active

When invoked within an active caller-owned transaction, the runner participates in it:
- No new transaction is begun.
- No commit is issued on success.
- No full rollback is issued on failure.
- The original caller-owned transaction remains active for the caller to manage.

### General Guarantees

- **Callback result preservation:** The return value of the callback is preserved and returned by the runner.
- **Same-PDO requirement:** All operations must use the same underlying PDO connection.
- **Transaction ownership boundary:** The package explicitly differentiates between package-owned and caller-owned transactions, avoiding implicit commits or rollbacks of caller-owned state.

## v1.4.0 — Savepoint orchestration

### Components

- `SavepointTransactionRunnerInterface`
- `PdoSavepointTransactionRunner`
- `TransactionExecutionException`

### PdoSavepointTransactionRunner Roles

- **Public API:** Yes.
- **Supported:** Yes.
- **Deprecated:** No.
- **Intended use:** Intended when operation-local savepoint isolation is required inside an existing caller-owned transaction.

> **Note:** Neither runner replaces the other. Choose `PdoTransactionRunner` for standard composition without isolation, and `PdoSavepointTransactionRunner` for isolated operations within a broader transaction.

### Behavior when no transaction is active

If no outer transaction is active, `PdoSavepointTransactionRunner` preserves normal owned-transaction semantics (identical to `PdoTransactionRunner`).

### Behavior when an outer transaction is active

If an outer transaction is active, the runner establishes an operation-local boundary:
- Creates an operation-local savepoint before executing the callback.
- Releases the savepoint on success.
- Rolls back to the savepoint on operation failure.
- The outer transaction remains active.
- No commit or full rollback of the caller-owned transaction occurs.

### Savepoint Contracts

- **Naming contract:** `maatify_persistence_sp_<32 lowercase hexadecimal characters>`.
- **Nesting:** Nested and repeated savepoints are fully supported and safely scoped.
- **Callback return-value preservation:** The callback's result is always preserved and returned.
- **Exact Throwable precedence:** If a cleanup failure (like `RELEASE SAVEPOINT`) occurs after a callback throws, the callback's original `Throwable` takes precedence and is propagated.
- **Cleanup failure behavior:** Package-detected non-throwing PDO control failures (e.g., PDO returning `false` instead of throwing an exception during savepoint control) will throw a `TransactionExecutionException`.
- **Same-PDO requirement:** Savepoint orchestration is strictly connection-local.
- **MySQL 8.4 verified boundary:** The savepoint behavior is verified strictly against MySQL 8.4 boundaries.
- **Cross-connection out of scope:** Cross-connection and distributed transactions are explicitly out of scope.
