# Architecture Decision Records

This directory contains the package-specific Architecture Decision Records (ADRs) for `maatify/persistence`.

An ADR records why an architectural decision was made, the chosen boundary, the alternatives considered, and the consequences of the decision. It is not automatically proof that a Runtime capability has been implemented or released.

## Document Boundaries

- **ADR:** Records the architectural decision, rationale, alternatives, status, and consequences.
- **Architecture contract:** Defines detailed implementation requirements for an approved capability. A contract may exist before Runtime implementation.
- **Package Reference:** Documents only the stable public Runtime API and released behavior available to consumers.

An accepted ADR and an implemented Runtime capability are separate states. Runtime availability must be confirmed from `PERSISTENCE_PACKAGE_REFERENCE.md` and the released package version.

## Status Values

The following status values are used:

- `Proposed`: Under architectural review and not approved.
- `Accepted`: Approved as the current architectural direction.
- `Accepted — Deferred`: Approved as a valid future direction, but not assigned to the current implementation or release scope.
- `Rejected`: Considered and explicitly not selected.
- `Superseded`: Replaced by a later ADR; the original record remains for history.
- `Deprecated`: Previously accepted but no longer recommended for new work.

## Numbering and File Rules

- ADR numbers use four digits and increase sequentially.
- A number is never reused, including after an ADR is rejected or superseded.
- Accepted ADRs are not silently rewritten when the decision materially changes; a new ADR supersedes the previous one.
- Every superseded ADR must link to its replacement.
- ADR filenames use lowercase kebab-case after the numeric prefix.

## ADR Index

| ADR | Title | Status | Decision Date | Runtime Status | Related Contract |
|---|---|---|---|---|---|
| [0001](0001-pdo-pagination-architecture.md) | PDO Pagination Architecture | Accepted | 2026-07-11 | Released in v1.1.0 | [PDO Pagination Contract](../architecture/PDO_PAGINATION_CONTRACT.md) |
| [0002](0002-ordering-hard-delete-compaction.md) | Ordering Hard-Delete Compaction | Accepted — Deferred | 2026-07-11 | Not implemented; no stable public API | None |
