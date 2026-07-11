# PDO Ordering Hard-Delete Compaction Contract

## 1. Status and Authority

**Status:** Owner-approved documentation-only architectural contract.

**Runtime status:** Not implemented. No stable public hard-delete compaction API currently exists.

**Implementation authorization:** Not granted by this document alone. Runtime work requires a separately approved implementation blueprint.

**Release scope:** Unassigned. This decision does not expand the current `v1.1.0` Pagination scope.

Until Runtime implementation is merged and released, this document MUST NOT be presented as proof that hard-delete compaction is available to consumers.

After implementation, the Runtime source, tests, and `PERSISTENCE_PACKAGE_REFERENCE.md` MUST match the approved implementation contract.

## 2. Problem Definition

Removing an actively ordered row can leave a single gap in its ordering scope.

Example:

```text
Before deletion: 1, 2, 3, 4, 5
Removed order:   3
Required result: 1, 2, 3, 4
```

The consuming project owns the entity and its deletion rules, while `maatify/persistence` owns reusable ordering mechanics. The contract must preserve both boundaries without allowing projects to duplicate SQL compaction logic.

## 3. Ownership Boundaries

### 3.1 Consuming project responsibilities

The consuming project owns:

- entity lookup
- authorization and business deletion rules
- relationship and cascade decisions
- choosing hard delete rather than soft delete
- executing the physical `DELETE`
- caller-owned transaction begin, commit, and rollback
- not-found behavior at Repository and Service boundaries
- supplying the correct PDO connection, ordering configuration, and scope

### 3.2 `maatify/persistence` responsibilities

The Ordering domain owns:

- validation of ordering configuration and scope usage
- concurrency-safe locking of the applicable active ordering scope
- support for capturing the target row's current ordering position before deletion
- shifting later active rows after deletion
- preventing cross-scope updates
- preserving package exception and external throwable behavior

The package MUST NOT own:

- entity deletion
- authorization
- host relationships
- cascade behavior
- host-specific repository or service rules
- soft-delete policy

## 4. Atomic Transaction Contract

Hard delete and gap compaction are one atomic project operation.

The consuming project MUST own one transaction covering the complete sequence. All steps MUST use the same PDO connection.

`maatify/persistence` MUST NOT commit or roll back the caller-owned transaction. When a Persistence operation throws, the consuming project is responsible for rolling back and rethrowing the original throwable unless an explicitly approved semantic conversion applies.

A Runtime implementation MUST reject or otherwise make impossible any compaction flow that executes outside the required caller-owned transaction.

## 5. Required Concurrency-Safe Sequence

The required sequence is:

1. The consuming project begins a transaction.
2. The stable Persistence Ordering API locks the complete applicable active scope using its canonical lock order.
3. While that lock is held, the target row's current scope and `display_order` are read and retained.
4. If the target does not exist as an active ordered row, the project applies its approved not-found behavior and does not compact.
5. The project executes the physical `DELETE`.
6. Before commit, the project invokes the stable Persistence compaction operation with the retained scope and removed position.
7. Persistence shifts eligible later rows.
8. The project commits only after deletion and compaction both succeed.
9. Any failure rolls back the complete project-owned transaction.

The project MUST NOT delete first and acquire the ordering-scope lock afterward. That order permits interleaving with another ordering mutation and can create lock-order inversion or inconsistent compaction.

## 6. Compaction Semantics

For a removed active position `R`, compaction applies only to active rows satisfying both:

```text
same ordering scope
display_order > R
```

Each eligible row is changed by exactly:

```text
display_order = display_order - 1
```

The operation:

- closes only the single position removed by the current deletion
- does not globally normalize unrelated pre-existing gaps
- does not affect rows in another scope
- does not affect rows already excluded by configured soft-delete filtering
- treats zero shifted rows as success when the removed row was last
- does not require the deleted row id after the removed position has been captured

## 7. Soft-Delete Boundary

This contract governs physical hard deletion of a row that participates in active ordering immediately before deletion.

It does not define soft-delete compaction.

When a row was already soft-deleted and therefore already excluded from active ordering, physically deleting that row later MUST NOT trigger active-order compaction.

Any future requirement to compact at soft-delete time requires a separate owner-approved architectural decision.

## 8. Public API Boundary

This document approves behavior and ownership, not exact Runtime names.

The implementation blueprint must separately approve:

- exact public method names and signatures
- whether preparation and compaction use one or multiple public operations
- return types
- package-defined validation or transaction exceptions
- exact SQL and lock implementation
- integration-test fixtures and failure injection

Consumers MUST NOT infer or locally invent an API from this document.

## 9. Failure Semantics

The implementation must preserve the package's established failure model:

- invalid package-owned configuration or operation input uses the approved package exception hierarchy
- external PDO or database throwables may propagate unchanged when no stable semantic conversion applies
- blind catch-all wrapping is forbidden
- the original throwable is rethrown after caller-owned rollback unless an approved conversion applies
- a failed compaction must prevent the deletion transaction from committing

## 10. Verification Requirements

Runtime implementation requires real MySQL integration coverage. SQLite is not an acceptable substitute.

Verification must cover at least:

- global ordering
- scoped ordering
- deleting the first active position
- deleting a middle active position
- deleting the final active position
- zero-row shift success
- isolation from other scopes
- configured soft-delete filtering
- already soft-deleted physical deletion without active-row shifting
- rollback when deletion fails
- rollback when compaction fails
- serialization against concurrent move operations
- serialization against concurrent ordered inserts using the approved locking contract
- preservation of unrelated pre-existing gaps
- external throwable propagation
