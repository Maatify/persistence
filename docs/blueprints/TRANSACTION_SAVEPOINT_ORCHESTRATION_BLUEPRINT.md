# Transaction Savepoint Orchestration Blueprint

## 1. Current State

The current transaction capability published in `v1.3.0` provides a foundational PDO transaction runner that safely delegates or assumes transaction ownership.

The existing public contract is defined by `TransactionRunnerInterface::run(callable $callback): mixed` and its implementation `PdoTransactionRunner`. The runtime behavior is strictly protected:

```text
No active transaction:
    begin transaction
    execute callback
    commit on success
    rollback owned transaction on failure
    preserve callback return value
    preserve original Throwable

Existing caller-owned transaction:
    execute callback
    do not begin
    do not commit
    do not roll back caller transaction
```

Current tests protecting these semantics include:
- `tests/Unit/Pdo/Transaction/PdoTransactionRunnerTest.php`
- `tests/Integration/Pdo/Ordering/ScopedOrderingManagerTransactionTest.php`
- `tests/Integration/Pdo/Pagination/PdoPaginatorTransactionTest.php`

The published `v1.3.0` behavior is backward-compatibility protected.

## 2. Exact Gap

Inside an already-active caller/Host-owned PDO transaction, a reusable operation currently has no isolated rollback boundary. If a nested operation fails, it either leaves the outer transaction in an inconsistent state or forces the entire outer transaction to roll back, without allowing the caller to catch the failure and recover.

Required behavior for the missing capability:

```text
No active transaction:
    existing owned-transaction behavior

Active outer transaction:
    create operation-local savepoint
    execute callback

    success:
        release operation savepoint
        return callback result

    failure:
        rollback to operation savepoint
        clean up/release savepoint where valid
        keep outer transaction active
        rethrow the exact original operation Throwable
```

This is explicitly a savepoint-based operation isolation capability, **not a second/nested database transaction**.

## 3. Persistence Ownership Decision

This capability is a reusable `maatify/persistence` concern. It must remain:
- PDO-based
- Framework-agnostic
- Host-agnostic
- Business-domain agnostic
- Reusable by any Maatify package requiring operation-local atomicity inside a caller-owned transaction

No Eligibility names, schema assumptions, repositories, DTOs, or domain exceptions may enter the design. Transaction savepoints are purely an infrastructure orchestration concern.

## 4. Public / Internal Contract Decision

To maintain strict backward compatibility, the current public API will remain unmodified.

```text
TransactionRunnerInterface
PdoTransactionRunner
    remain unchanged

NEW:
SavepointTransactionRunnerInterface extends TransactionRunnerInterface
PdoSavepointTransactionRunner implements SavepointTransactionRunnerInterface
```

**Reasoning:**
- The new `SavepointTransactionRunnerInterface` naturally extends `TransactionRunnerInterface` because it provides the identical `run(callable $callback): mixed` signature, but with stronger local-isolation guarantees.
- We must **not** add methods to the already-published `TransactionRunnerInterface` as this would break existing implementations.
- We must **not** silently change `PdoTransactionRunner::run()` to inject savepoints. Existing consumers relying on `v1.3.0` may expect a failure inside the callback to natively poison or require rolling back the outer transaction without the overhead or semantics of savepoints. Altering `PdoTransactionRunner` would be a silent semantic change and a backward-compatibility break.

## 5. Runtime Semantics

### No active transaction
The savepoint-capable runner must preserve the existing transaction-runner behavior. It should prefer reuse/delegation (e.g., composing `PdoTransactionRunner` internally) over duplicating the owned-transaction engine where practical.

### Active caller-owned transaction
The runner must execute:
```text
SAVEPOINT <package-generated unique name>

execute callback

on success:
    RELEASE SAVEPOINT
    return callback result

on callback failure:
    ROLLBACK TO SAVEPOINT
    cleanup/release where valid
    rethrow the same callback Throwable
```

The savepoint runner must **never**:
- commit the outer transaction
- perform full rollback of the outer transaction
- take ownership of a transaction it did not start

## 6. Savepoint Naming Contract

Savepoint names must be strictly controlled by the package:
- Generated internally.
- No caller/user supplied SQL identifier.
- No domain-derived value.
- Valid/safe MySQL identifier format.
- Deterministic format rules (e.g., `maatify_savepoint_[uniqid]`).
- Unique per operation to prevent accidental overwrites.
- Collision-safe for repeated/nested operations.
- Bounded length (safely under MySQL identifier limits).
- Opaque to consumers.

Name generation does not need to become public API. An internal private helper or a purely internal/package-private class is sufficient to keep it testable while preventing unnecessary public extensibility.

## 7. Failure Semantics

Precedence rules are strictly defined:
- The callback's `Throwable` is **always** the visible failure for callback failures.
- Rollback-to-savepoint cleanup failure must not replace the callback `Throwable`.
- Release cleanup failure during failure handling must not replace the callback `Throwable`.
- Savepoint creation failure prevents callback execution and propagates as an infrastructure failure (e.g., `PersistenceException` / `PDOException`).
- If the callback succeeds but the required normal `RELEASE SAVEPOINT` fails, the operation must not report false success (it must propagate the failure).
- Owned-transaction failure semantics remain consistent with the existing `PdoTransactionRunner`.
- If the callback improperly ends or replaces the caller-owned transaction, subsequent savepoint statements will fail. This surfaces as an infrastructure error, but if the callback threw, the original callback `Throwable` still takes precedence.

## 8. Return-Value Preservation

The callback return value must be returned exactly as provided by the callback:
- scalar
- array
- object identity
- `null`

No DTO/wrapper/result envelope should be introduced solely for transaction orchestration.

## 9. Repeated and Nested Operations

Savepoint stack behavior must safely support complex orchestration:
- **Repeated sequential operations:** Each operation generates a unique savepoint, executes, and releases it sequentially.
- **Nested successful operations:** Inner operation creates and releases savepoint B; outer operation creates and releases savepoint A.
- **Inner failure caught by outer callback:** Inner operation rolls back to savepoint B. The outer callback catches the exception and successfully completes, releasing savepoint A.
- **Inner failure propagated to outer runner:** Inner operation rolls back to savepoint B and rethrows. Outer operation catches the exception, rolls back to savepoint A, and rethrows.
- **Unique names:** Ensuring unique savepoint names at every level prevents MySQL savepoint collisions (as reusing a name in MySQL overwrites the previous savepoint).

## 10. MySQL-Specific Boundaries

The package remains PDO-based, but current verified database behavior targets MySQL. Relevant MySQL rules include:
- `SAVEPOINT name` creates the savepoint.
- `ROLLBACK TO SAVEPOINT name` reverses mutations back to the savepoint but does **not** end the outer transaction.
- `RELEASE SAVEPOINT name` destroys the savepoint from the transaction state.
- **InnoDB Lock Limitation:** Row/table locks acquired during the savepoint execution are **not** released by `ROLLBACK TO SAVEPOINT`. They are held until the outer transaction commits or rolls back. This must be documented.
- **Implicit Commit Boundaries:** DDL statements (like `CREATE TABLE`) cause implicit commits, which destroy savepoints and outer transactions. The runner does not parse SQL to prevent this, but it will surface the resulting PDO errors if callers misuse DDL inside savepoints.

No broader cross-database support claims will be made without explicit verification.

## 11. Backward Compatibility

The intended change is completely additive.

Explicit protections:
- `TransactionRunnerInterface` remains unchanged.
- `PdoTransactionRunner` and its existing `run()` behavior remain unchanged.
- Ordering behavior remains unaffected (unless explicitly migrated later, which is out of scope for this architecture change).
- Pagination behavior remains unaffected.
- Existing public exceptions remain the same.
- Existing consumers of `v1.3.0` will experience no changes.

Expected SemVer impact: Minor version bump (e.g., `1.4.0`).

## 12. Scope / Out of Scope

**In scope:**
- Generic savepoint-aware transaction orchestration
- Ownership semantics
- Safe naming
- Cleanup semantics
- Nested/repeated behavior
- Testability
- MySQL verification design
- Compatibility

**Out of scope:**
- Eligibility implementation
- Host framework integration
- DI/container bindings
- ORM support
- Distributed transactions
- Cross-connection atomicity
- Automatic transaction retries
- Domain-specific transaction policies
- Changing existing Ordering/Pagination contracts

## 13. Work Units

WU1 — Savepoint transaction contract/runtime + unit/regression coverage
WU2 — Real MySQL savepoint integration verification

Each WU must be independently reviewable and later squash-merged into the Draft Integration branch.

## 14. Test Matrix

The test matrix must cover:
- No outer transaction / success
- No outer transaction / failure
- Successful outer transaction participation
- Operation failure inside outer transaction
- Rollback-to-savepoint data restoration
- Host mutations before savepoint remain pending
- Release on success
- Release/cleanup after rollback
- Original `Throwable` identity preservation
- Rollback cleanup failure (simulated)
- Release cleanup failure (simulated)
- Savepoint creation failure (simulated)
- Callback return preservation
- Repeated operations
- Nested operations
- Inner failure caught and outer operation continues
- Inner failure propagated outward
- Outer transaction remains active after internal rollback
- Host commit after successful operation
- Host rollback after successful operation
- Host commit after isolated failed operation
- Regression coverage proving `PdoTransactionRunner` semantics did not change
- Actual MySQL integration behavior, not mocks only

## 15. Verification Plan

Verification must satisfy the repository's strict quality gates:
- Composer strict validation (`composer validate --strict`)
- PHP syntax checks
- PHPStan Level Max (`composer analyse`)
- Unit tests (`composer test:unit`)
- Regression tests (`composer test:regression`)
- Real MySQL integration tests (`composer test:integration`)
- Full test suite execution
- Lowest-supported dependencies verification
- Code style formatting (`php-cs-fixer fix --dry-run --diff`)
- Repository integrity checks
- MySQL residue checks to ensure clean state
- Existing CI Gate must pass completely

Real MySQL verification is mandatory. SQLite is not an acceptable substitute.

## 16. Documentation Impact

Documentation Sweep targets include:
- `PERSISTENCE_PACKAGE_REFERENCE.md` (to document the new savepoint capabilities)
- `README.md` (to mention savepoint capability)
- `CHANGELOG.md`
- `CONTRIBUTING.md` (if transaction rules are mentioned)
- Transaction architecture ADR / ADR index (if required by repository architecture governance)

Ecosystem standards-governance follow-up will be required to make `maatify/persistence` the authoritative shared Transaction/Savepoint implementation and prohibit package-local duplicates in other Maatify packages.

## 17. Definition of Done

- No breaking change to `v1.3.0` API or behavior.
- No semantic drift in existing `PdoTransactionRunner`.
- Generic savepoint capability implemented.
- Outer transaction ownership rigorously preserved.
- Operation-local rollback mathematically proven.
- Exact original `Throwable` preservation proven.
- Exact callback result preservation proven.
- Repeated/nested behavior proven.
- Real MySQL verification completed.
- Complete regression coverage maintained.
- Documentation synchronized with final runtime.
- Final Review against latest `main`.
- No divergence before Ready state.
- All Work Units squash-merged into the Draft Integration branch.
- Final Draft squash-merged to `main` only after all gates close successfully.
