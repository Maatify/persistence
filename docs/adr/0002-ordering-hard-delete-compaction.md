# ADR 0002: Ordering Hard-Delete Compaction

- **Status:** Accepted — Deferred
- **Decision date:** 2026-07-11
- **Runtime status:** Not implemented; no stable public API
- **Release target:** Unassigned
- **Supersedes:** `docs/architecture/PDO_ORDERING_HARD_DELETE_COMPACTION_CONTRACT.md`

## Context

The `athar-admin` codebase contains an older ArPlatform ordering helper with a `compactScopeAfterRemoval()` capability. That helper is part of the implementation history from which the standalone Persistence package was derived.

The current `Modules/Persistence` implementation and the current stable standalone `maatify/persistence` public API expose:

- `getNextPosition()`
- `moveWithinScope()`
- `rowExistsInScope()`

They do not currently expose `compactScopeAfterRemoval()` or another stable public hard-delete compaction operation.

This difference is recorded as architectural history. This ADR does not classify it as an extraction defect, an accidental omission, or an already available package feature.

## Decision

Scoped ordering compaction after removal is preserved as a valid future candidate within the Persistence Ordering domain.

The consuming project continues to own:

- the decision to perform a hard delete
- entity lookup and not-found behavior
- authorization and domain deletion rules
- relationship and cascade handling
- capture of project-required row data before deletion
- the physical `DELETE`
- transaction orchestration, commit, rollback, and error propagation

A future `maatify/persistence` capability, if separately approved, must remain limited to reusable ordering mechanics. It must not become an entity-deletion API, authorization layer, cascade engine, or host repository abstraction.

## Deferred Decisions

This ADR intentionally does not approve:

- an exact public method name or signature
- whether the operation joins a caller-owned transaction or owns a transaction
- a locking strategy or lock order
- a preparation or removed-position DTO
- return-value semantics
- new exceptions
- exact SQL construction
- a release version

Those decisions require a future code-grounded blueprint based on the Runtime and consuming-project usage that exist at that time.

## Current Consumer Boundary

Until a stable package API is implemented, released, and documented in `PERSISTENCE_PACKAGE_REFERENCE.md`:

- existing project-specific hard-delete flows remain project-owned
- no consumer may claim that the package provides hard-delete compaction
- no dependency version may be selected on the assumption that this deferred capability exists
- this ADR does not require immediate consumer migration

## Alternatives Considered

### Treating compaction as an existing stable package capability

Rejected because the current public Runtime API and Package Reference do not expose it.

### Expanding the package to own the complete hard-delete lifecycle

Rejected because deletion, authorization, relationships, and cascade behavior are consuming-domain responsibilities.

### Assigning the capability automatically to `v1.1.0`

Rejected because `v1.1.0` is currently reserved for the approved Pagination scope. This deferred decision does not expand that release.

### Keeping an implementation contract before the API decision is reviewed

Rejected because a detailed contract would incorrectly imply that method shape, locking, and transaction behavior are already approved.

## Consequences

- The architectural history and future candidate remain discoverable.
- The current stable Runtime and Package Reference remain authoritative.
- No Runtime implementation is authorized by this ADR.
- A future implementation starts with a separate architectural review and blueprint.
- The former hard-delete compaction contract is removed to avoid presenting deferred design detail as an implementation-ready contract.
