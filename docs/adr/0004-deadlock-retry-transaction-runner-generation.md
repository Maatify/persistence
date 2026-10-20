# ADR 0004: Deadlock-Retry Transaction Runner Generation

- **Status:** Accepted
- **Decision date:** 2026-10-20
- **Runtime status:** Implemented; Unreleased (not part of the stable `v1.4.0`)
- **Implementation authorization:** Owner architecture resolution in [Issue #32](https://github.com/Maatify/persistence/issues/32) (2026-10-20)
- **Supersedes:** the statement of [ADR 0003](0003-transaction-savepoint-orchestration.md) that `PdoTransactionRunner` and `PdoSavepointTransactionRunner` are not deprecated. The rest of ADR 0003 (savepoint orchestration) remains in force.
- **Related contract:** [PDO Transaction Architecture](../architecture/PDO_TRANSACTION_ARCHITECTURE.md), [Package Reference](../../PERSISTENCE_PACKAGE_REFERENCE.md)

## Context

Transactions owned by the package runners can be chosen by InnoDB as deadlock victims (`1213`, SQLSTATE `40001`), for example when several unrelated aggregates are created concurrently ([Issue #32](https://github.com/Maatify/persistence/issues/32)). The runners propagate the deadlock and the operation fails. The evidence-only study ([PR #41](https://github.com/Maatify/persistence/pull/41), [PR #43](https://github.com/Maatify/persistence/pull/43)) compared an immediate retry policy with a Hybrid one (retry 1 immediate, then uniform 0-10 ms and 0-20 ms): in the synchronized 8-connection decision cell Hybrid reduced operations still failing after four attempts from about 16 % to below 0.3 %, and two clean runs on an isolated host passed the goodput and P95 tolerances. The study also recorded limits: about 27 % goodput cost at 4 synchronized connections where nothing was rescued, and no evidence for less synchronized loads.

The existing runners are `final readonly`, take only `PDO`, and their behavior is part of the stable `v1.4.0` contract. Adding a constructor argument to them was proposed and then rejected by the Owner's class-versioning policy: when a public class contract evolves, a new class generation is introduced, the old class is deprecated, and new consumers are directed to the replacement.

## Decision

1. Add `PdoDeadlockRetryTransactionRunner` (implements the unchanged `TransactionRunnerInterface`) and `PdoDeadlockRetrySavepointTransactionRunner` (implements the unchanged `SavepointTransactionRunnerInterface`), both with `__construct(PDO $pdo, int $maxDeadlockRetries = 0)` and the unchanged `run(callable $callback): mixed`.
2. `$maxDeadlockRetries` is validated to `0..3` (`InvalidTransactionConfigurationException` otherwise). The default `0` keeps the historical behavior: no replay. There is no other tuning or policy-switch parameter.
3. Replay applies only to a transaction the runner itself began, and only when the callback threw a `PDOException` with SQLSTATE `40001` and driver code `1213`, a replay is left, and the server is proven to have ended the transaction (a no-op probe succeeds and the connection then reports no active transaction; `pdo_mysql` refreshes `inTransaction()` only from successful replies). The whole transaction and callback are replayed from a fresh `BEGIN` with the Hybrid delays. After the last attempt the exact final `PDOException` is rethrown.
4. Never replayed: `1205` and all other errors, `BEGIN`/`COMMIT` failures, unproven cleanup, caller-owned transactions, and savepoints (the savepoint path keeps the unchanged ADR 0003 contract).
5. One internal mechanism (`@internal`) implements the owned transaction and replay for both new runners. The deprecated runners are not reimplemented on top of it; their code and tests stay unchanged.
6. `PdoTransactionRunner` and `PdoSavepointTransactionRunner` are `@deprecated` in favor of the corresponding new class. They remain public, autoloadable, and signature- and behavior-compatible through `1.x`; removal requires a separately approved future Major release (Library Presentation Standard §14.6). The interfaces stay supported, unchanged, and not deprecated; no V2 interfaces are introduced.
7. Active documentation (README quick starts, usage and architecture guidance, examples) shows only the new classes and keeps both runner behaviors (plain participation and savepoint isolation). The Package Reference keeps a compact compatibility inventory of the deprecated classes; history (CHANGELOG, ADR 0003, the v1.3.0/v1.4.0 architecture sections) is preserved.

## Alternatives Considered

- **Optional constructor argument on the existing runners** (`__construct(PDO $pdo, int $maxDeadlockRetries = 0)`): compatible for callers, but changes the published class contract in place; rejected by the Owner's class-versioning policy.
- **Automatic retry by default:** rejected; the study supports the policy only for one synchronized workload and shows a cost where nothing is rescued.
- **Wrapping the old runner in a retry loop:** rejected; it cannot tell a callback deadlock from a commit failure.
- **Retrying inside caller-owned transactions or savepoints:** rejected; InnoDB has already rolled back the caller's whole transaction, and only the owner can decide to re-run it.
- **Judging the transaction state from `inTransaction()` immediately after the error:** rejected after real-MySQL tests showed `pdo_mysql` still reports the rolled-back transaction as active; replaced by the explicit probe.

## Consequences

- Consumers can opt in to bounded deadlock replay for transactions the runner owns, without any change for existing code or for the default configuration.
- A replay-enabled callback may run up to four times: consumers own the idempotency of anything outside the transaction (documented prominently). A manually ended runner-owned transaction followed by a later deadlock is not detectable and is excluded by contract.
- `ScopedOrderingManager::moveWithinScope()` keeps using `PdoTransactionRunner` internally; its behavior is unchanged and it does not replay deadlocks.
- Two deprecated public classes remain in the `1.x` line until a future Major release.
