# ADR 0004: Deadlock-Retry Transaction Runner Generation

- **Status:** Accepted; the residual limit of replay attribution is an Owner-accepted limitation ([Owner approval](https://github.com/Maatify/persistence/issues/32#issuecomment-6296393312), 2026-10-20; see the last section)
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
3. Replay applies only to a transaction the runner itself began, and only when the callback threw a `PDOException` with SQLSTATE `40001` and driver code `1213`, a replay is left, the driver still reports the runner's transaction when the deadlock arrives (`pdo_mysql` derives `inTransaction()` from the last successful reply, so a victim on this connection still reports it; a transaction that already ended is never replayed), and a no-op statement (`DO 0`) then shows no active transaction. The whole transaction and callback are replayed from a fresh `BEGIN` with the Hybrid delays. After the last attempt the exact final `PDOException` is rethrown.
4. Never replayed: `1205` and all other errors, `BEGIN`/`COMMIT` failures (thrown or returned as `false`), a transaction that ended before the deadlock, unproven cleanup, caller-owned transactions, and savepoints (the savepoint path keeps the unchanged ADR 0003 contract). Transaction control is fail-closed: `BEGIN` returning `false` stops before the callback and `COMMIT` returning `false` is never reported as success (`TransactionExecutionException`).
5. One internal mechanism (`@internal`) implements the owned transaction and replay for both new runners. The deprecated runners are not reimplemented on top of it; their code and tests stay unchanged.
6. `PdoTransactionRunner` and `PdoSavepointTransactionRunner` are `@deprecated` in favor of the corresponding new class. They remain public, autoloadable, and signature- and behavior-compatible through `1.x`; removal requires a separately approved future Major release (Library Presentation Standard §14.6). The interfaces stay supported, unchanged, and not deprecated; no V2 interfaces are introduced.
7. Active documentation (README quick starts, usage and architecture guidance, examples) shows only the new classes and keeps both runner behaviors (plain participation and savepoint isolation). The Package Reference keeps a compact compatibility inventory of the deprecated classes; history (CHANGELOG, ADR 0003, the v1.3.0/v1.4.0 architecture sections) is preserved.

## Alternatives Considered

- **Optional constructor argument on the existing runners** (`__construct(PDO $pdo, int $maxDeadlockRetries = 0)`): compatible for callers, but changes the published class contract in place; rejected by the Owner's class-versioning policy.
- **Automatic retry by default:** rejected; the study supports the policy only for one synchronized workload and shows a cost where nothing is rescued.
- **Wrapping the old runner in a retry loop:** rejected; it cannot tell a callback deadlock from a commit failure.
- **Retrying inside caller-owned transactions or savepoints:** rejected; InnoDB has already rolled back the caller's whole transaction, and only the owner can decide to re-run it.
- **Judging the transaction end from `inTransaction()` alone, or from the probe alone:** rejected. Real-MySQL tests showed `pdo_mysql` still reports a deadlock victim's transaction as active until the next successful statement, so the state right after the error cannot show the end; and the probe alone (review finding F44-02) cannot tell a deadlock rollback from a transaction the callback already ended. Both are combined: the stale "still active" state attributes the end to this connection's failing statement, the probe confirms it.

## Consequences

- Consumers can opt in to bounded deadlock replay for transactions the runner owns, without any change for existing code or for the default configuration.
- A replay-enabled callback may run up to four times: consumers own the idempotency of anything outside the transaction (documented prominently). Keeping the runner-owned transaction intact inside the callback is a mandatory precondition of replay (next section).
- `ScopedOrderingManager::moveWithinScope()` keeps using `PdoTransactionRunner` internally; its behavior is unchanged and it does not replay deadlocks.
- Two deprecated public classes remain in the `1.x` line until a future Major release.

## Accepted limitation: callback transaction-ownership precondition

**Owner decision ([approval](https://github.com/Maatify/persistence/issues/32#issuecomment-6296393312), 2026-10-20), on the recommendation of the independent review of PR #44 (finding F44-02R):** option 1, accept and disclose.

**Normative boundary.** Replay is supported only for callbacks that keep the runner-owned transaction intact: the callback must not end or replace it, neither explicitly (`commit()`, `rollBack()`, `beginTransaction()`, or SQL such as `START TRANSACTION`, `BEGIN`, `COMMIT`, `ROLLBACK`) nor implicitly (DDL and other implicit-commit statements, or session/autocommit changes). All transactional participants stay on the same PDO connection. This is a mandatory precondition of `maxDeadlockRetries > 0`, like the idempotency of effects outside the transaction. Callbacks that violate it are outside the supported behavior: the runner gives **no transaction-identity guarantee** for them, and neither the driver-reported state nor the `DO 0` probe is a proof of transaction identity.

What the runner does detect, without extra statements: a transaction that already ended before the deadlock while the connection is outside a transaction (it is not replayed; review counterexample M below). What it cannot detect: the callback ended the runner-owned transaction **and** the same connection is inside another transaction when the deadlock hits it (M3, M4), which produces the same driver state as a genuine victim; its already committed work would be replayed.

Evidence (MySQL 8.4.10, PHP 8.4.4 / mysqlnd, two real connections, a real InnoDB deadlock each time; counters measured from right after the runner's `BEGIN`):

| Case (runner-owned transaction, callback) | `inTransaction()` before probe | after `DO 0` | `Com_begin` / `Com_commit` / `Com_rollback` delta | Handled |
|---|---|---|---|---|
| G: genuine victim on the runner's connection | `true` | `false` | +0 / +0 / +0 | replayed (supported) |
| M: callback commits, then another connection raises `1213` (review counterexample) | `false` | `false` | +0 / +1 / +0 | not replayed |
| M3: callback commits, begins a new transaction, which is the victim | `true` | `false` | +1 / +1 / +0 | replayed (outside the supported contract) |
| M4: DDL implicit commit, `SET autocommit = 0`, implicit transaction is the victim | `true` | `false` | +0 / +0 / +0 | replayed (outside the supported contract) |

(`Handler_*` counters change per statement and do not separate M4 from G.)

Alternatives not adopted: a session-counter fingerprint (`SHOW SESSION STATUS` per attempt) would not cover M4 and adds per-attempt cost the Issue #32 study did not measure; privileged transaction identity (`INNODB_TRX` with `PROCESS`, or `performance_schema`) conflicts with least-privilege application accounts and is not a portable contract; deferring replay would remove the capability Issue #32 requested. No runtime check, monitoring or extra statement was added for this limitation.
