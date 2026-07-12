# ADR 0001: PDO Pagination Architecture

- **Status:** Accepted
- **Decision date:** 2026-07-11
- **Runtime status:** Released in v1.1.0
- **Implementation authorization:** Implemented
- **Related contract:** [PDO Pagination Contract](../architecture/PDO_PAGINATION_CONTRACT.md)

## Context

Maatify host projects repeatedly implement the same PDO offset-pagination mechanics across repositories and query readers. The duplicated concerns include page normalization, per-page limits, count execution, deterministic sorting, safe public sort keys, `LIMIT` and `OFFSET` binding, and pagination metadata.

The repeated mechanics are persistence concerns, but host repositories must retain ownership of domain SQL, mandatory scopes, optional filters, search rules, authorization constraints, and row mapping.

## Decision

A new additive package domain will be introduced under:

```text
Maatify\Persistence\Pdo\Pagination
```

The Pagination domain will be separate from the existing Ordering domain. It must not alter the public API, transaction behavior, or Runtime semantics of `ScopedOrderingConfig` or `ScopedOrderingManager`.

### Consuming repository responsibilities

The consuming repository or query reader owns:

- base SQL and JOIN structure
- mandatory tenant, ownership, visibility, and soft-delete scopes
- optional filter and search construction
- parameter values for consumer-supplied SQL
- semantic alignment between total, filtered-count, and data queries
- row mapping into arrays or DTOs

### Package responsibilities

The package owns:

- page and per-page normalization
- total and filtered-count execution
- data query execution using consumer-supplied SQL
- calling the mapper and validating the result type
- safe whitelist-based sorting
- deterministic tie-breaker composition
- `LIMIT` and `OFFSET` calculation and binding
- pagination metadata
- the standardized page result

### Explicit non-goals

The package will not become:

- an ORM
- a Query Builder
- a filter or search builder
- a generic repository abstraction
- an authorization layer
- an HTTP, PSR-7, middleware, or response component

## Detailed Contract

The exact public types, signatures, validation rules, SQL boundaries, exception classifications, result invariants, and MySQL verification requirements are owned by the [PDO Pagination Contract](../architecture/PDO_PAGINATION_CONTRACT.md).

The contract has been implemented and released in `v1.1.0`.

## Alternatives Considered

### Host-local pagination implementations

Rejected as the long-term architecture because they duplicate persistence mechanics and create inconsistent normalization, sorting, and metadata behavior.

### A package-owned Query Builder or filter engine

Rejected because it would move domain SQL ownership into the shared package and expand the package toward ORM or framework territory.

### Adding Pagination methods to Ordering classes

Rejected because Ordering and Pagination are separate persistence domains with different contracts and responsibilities.

## Consequences

- Pagination is an additive Minor-version capability targeted at `v1.1.0`.
- Host repositories remain responsible for domain SQL and mapping.
- The package centralizes only reusable pagination mechanics.
- Existing Ordering Runtime behavior remains unchanged.
- Runtime availability must be documented in `PERSISTENCE_PACKAGE_REFERENCE.md` before consumer adoption.
