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

Current tests protecting these semantics directly include:
- `tests/Unit/Pdo/Transaction/PdoTransactionRunnerTest.php` (Direct regression coverage of the runner itself)

Adjacent/composition coverage includes:
- `tests/Integration/Pdo/Ordering/ScopedOrderingManagerTransactionTest.php`
- `tests/Integration/Pdo/Pagination/PdoPaginatorTransactionTest.php`

The published `v1.3.0` behavior is backward-compatibility protected.

## 2. Exact Gap

Inside an already-active caller/Host-owned PDO transaction, a reusable operation currently has no isolated rollback boundary.

The exact current gap is:
- an active caller-owned transaction is left under caller ownership
- `PdoTransactionRunner` does not establish an operation-local restore point
- mutations performed by a failing participating operation can remain pending in the caller-owned transaction
- the caller currently has no reusable Persistence-provided way to roll back only that operation while preserving earlier outer-transaction work

Required behavior for the missing capability:

```text
if no PDO transaction is active:
    immediately use the existing owned-transaction path
    do not generate a savepoint identifier
    do not execute any savepoint control statement

if a PDO transaction is already active:
    generate the operation savepoint identifier
    create the savepoint
    execute the operation

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
- We must **not** silently change `PdoTransactionRunner::run()` to inject savepoints. This ensures that the compatibility argument is based only on the published `v1.3.0` contract, preserving exact current behavior.

## 5. Runtime Semantics

### Connection Contract
Transaction/savepoint composition is rigorously connection-local:
- `PdoSavepointTransactionRunner` operates exclusively on its injected PDO connection.
- The caller-owned outer transaction and all mutations intended to participate in that savepoint boundary must execute through the **same PDO instance / underlying database connection**.
- A savepoint cannot isolate mutations performed on another PDO connection.

### No active transaction
The savepoint-capable runner must exactly preserve the existing transaction-runner behavior. It must not generate a savepoint identifier, and it must not execute any savepoint control statements. It should prefer reuse/delegation (e.g., composing `PdoTransactionRunner` internally) over duplicating the owned-transaction engine where practical.

### Active caller-owned transaction
The runner must execute:
```text
generate the operation savepoint identifier

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

Savepoint names must strictly follow an exact package-owned format:

```text
maatify_persistence_sp_<32 lowercase hexadecimal characters>
```
The suffix represents 16 cryptographically random bytes encoded using lowercase hexadecimal.

Contract requirements:
- **Generation timing:** Only generated if a PDO transaction is already active.
- no caller input
- no business/domain-derived input
- ASCII only
- exact allowed shape: `^maatify_persistence_sp_[0-9a-f]{32}$`
- total length: 55 characters
- safely below the MySQL identifier limit
- opaque to consumers
- collision-resistant for repeated/nested operations
- name generation remains internal and must not introduce unnecessary public extensibility (do not use `uniqid()`)
- Savepoint-name generation failure occurs before the callback and propagates without invoking the callback.

## 7. Failure Semantics

Precedence rules are strictly defined for callback failures:
- The callback's `Throwable` is **always** the visible failure for callback failures.
- Rollback-to-savepoint cleanup failure must not replace the callback `Throwable`.
- Release cleanup failure during failure handling must not replace the callback `Throwable`.
- Savepoint creation failure prevents callback execution and propagates as an infrastructure failure.
- Owned-transaction failure semantics remain consistent with the existing `PdoTransactionRunner`.
- If the callback improperly ends or replaces the caller-owned transaction, subsequent savepoint statements will fail. The original callback `Throwable` still takes precedence if it threw.

**Success-path RELEASE failure semantics:**
This is explicitly separate from callback-failure cleanup semantics. Required contract:

```text
callback succeeds
RELEASE SAVEPOINT fails

    the operation must not report success

    preserve the original RELEASE failure as the visible failure

    if the caller-owned transaction is still active:
        best-effort ROLLBACK TO SAVEPOINT
        if that rollback succeeds:
            best-effort RELEASE SAVEPOINT

    cleanup failures must not replace the original RELEASE failure

    never full-rollback or commit the caller-owned transaction
```

**Transaction-control failure taxonomy:**
- PDO-thrown infrastructure `Throwable` instances continue to propagate unchanged.
- Savepoint control statements must not silently accept a non-throwing PDO `false` result.
- For a package-detected non-throwing failure of `SAVEPOINT`, `ROLLBACK TO SAVEPOINT`, or `RELEASE SAVEPOINT`, define a package-owned `TransactionExecutionException` implementing the existing `PersistenceException` marker.
- This is additive public API and will be included in Backward Compatibility, WU1, tests, Package Reference impact, and eventual CHANGELOG impact.
- Do not change existing `PdoTransactionRunner` behavior as part of this design.

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

## 10. MySQL-Specific Boundaries

The package remains PDO-based, but current verified database behavior targets MySQL. Relevant MySQL 8.4 rules include:
- `ROLLBACK TO SAVEPOINT` rolls back row modifications after the savepoint without terminating the outer transaction.
- Savepoints created later than the target savepoint are deleted.
- `RELEASE SAVEPOINT` removes the savepoint without commit or rollback.
- Reusing an existing savepoint name replaces the old savepoint.
- InnoDB does not release row locks stored in memory after the savepoint when rolling back to it; the exception being newly inserted rows (as documented by MySQL).
- MySQL defines specific implicit-commit statements, including many DDL statements. However, not all DDL behaves exactly the same; for instance, `CREATE TEMPORARY TABLE` does not cause the same implicit commit but is itself not rollbackable, so transactional atomicity can still be violated.

No broader cross-database support claims will be made without explicit verification.

## 11. Backward Compatibility

The intended change is completely additive.

Explicit protections:
- `TransactionRunnerInterface` remains unchanged.
- `PdoTransactionRunner` and its existing `run()` behavior remain unchanged.
- Ordering behavior remains unaffected.
- Pagination behavior remains unaffected.
- Existing public exceptions remain the same.
- `TransactionExecutionException` is introduced as a new, additive public API.
- Existing consumers of `v1.3.0` will experience no changes.

Expected SemVer impact: Minor version bump (e.g., `1.4.0`).

## 12. Scope / Out of Scope

**In scope:**
- Generic savepoint-aware transaction orchestration
- Ownership semantics
- Safe naming (`maatify_persistence_sp_<32 lowercase hex>`)
- Cleanup semantics
- Success-path RELEASE failure semantics
- Nested/repeated behavior
- Testability
- MySQL verification design
- Compatibility
- Same-connection enforcement semantics

**Out of scope:**
- Cross-connection atomicity (explicitly out of scope)
- Eligibility implementation
- Host framework integration
- DI/container bindings
- ORM support
- Distributed transactions
- Automatic transaction retries
- Domain-specific transaction policies
- Changing existing Ordering/Pagination contracts

## 13. Work Units

WU1 — Savepoint transaction contract/runtime (`SavepointTransactionRunnerInterface`, `PdoSavepointTransactionRunner`, exact savepoint naming, `TransactionExecutionException`) + unit/regression coverage
WU2 — Real MySQL savepoint integration verification

Each WU must be independently reviewable and later squash-merged into the Draft Integration branch.

## 14. Test Matrix

The test matrix must cover:
- No outer transaction / success
- No outer transaction / failure
- **Explicit coverage proving the no-active-transaction path does not use savepoint orchestration (no name generated, no SQL issued).**
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
- successful callback + normal RELEASE failure
- best-effort rollback after that RELEASE failure
- original RELEASE Throwable preservation when cleanup also fails
- exact savepoint-name format validation
- repeated/generated-name collision-safety coverage
- non-throwing PDO control-statement failure classification
- callback improperly commits or fully rolls back the caller-owned transaction
- exact precedence when the callback throws after altering transaction state
- **Same connection requirement validation.**

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

Documentation Sweep targets explicitly include:
- `docs/adr/0003-transaction-savepoint-orchestration.md` (must record connection-local boundary rules)
- `docs/adr/README.md`
- `PERSISTENCE_PACKAGE_REFERENCE.md` (to document the new savepoint capabilities, `TransactionExecutionException`, and connection boundaries)
- `README.md` (to mention savepoint capability)
- `CHANGELOG.md`
- `CONTRIBUTING.md` (if transaction rules are mentioned)

Ecosystem standards-governance follow-up will be required to make `maatify/persistence` the authoritative shared Transaction/Savepoint implementation and prohibit package-local duplicates in other Maatify packages.

## 17. Definition of Done

- No breaking change to `v1.3.0` API or behavior.
- No semantic drift in existing `PdoTransactionRunner`.
- Generic savepoint capability implemented.
- Outer transaction ownership rigorously preserved.
- Operation-local rollback behavior verified by unit/regression coverage and real MySQL integration tests.
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