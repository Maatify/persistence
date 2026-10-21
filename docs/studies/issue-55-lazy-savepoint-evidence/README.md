# Issue #55 evidence: lazy operation-local savepoint in `moveWithinScope()`

Engine: MySQL 8.4.10 (`mysql:8.4.10`, `--log-bin-trust-function-creators=1`), PHP 8.4.4, base `origin/main` `1f1b5b5`.

| File | What it shows |
|---|---|
| `red-lazy-savepoint-integration.txt` | New integration class against the **unchanged** runtime: 22 tests, 11 failures + 1 error (C04, C06, C07, C13 partial state; C15 partial shifts then `1062`; savepoint-count; S1, S2, S5, S6, S7; name format). C10, S3, S4, C14 (setup), owned controls and no-op/missing-row controls already pass: they characterise behavior that must not change. |
| `red-rewritten-published-test.txt` | The rewritten published test (`testParticipantFailureUndoesItsOwnMutationWindowAndLeavesOuterTransactionActive`) failing on the unchanged runtime (`[1,3,4,4]` instead of the pre-call state). |
| `green-targeted.txt` | The same tests after the fix: 23 tests, 214 assertions, OK. |
| `green-integration-run1.txt`, `green-integration-run2.txt` | Full integration suite twice: 199 tests, OK. |
| `green-consumer.txt` | Consumer Verification Harness, two clean runs. |

| `red-scope-lock-eager-savepoint.txt` / `green-scope-lock.txt` | F56-02: the new scope-lock test fails when the savepoint is temporarily moved before the scope lock (eager variant, reverted, not committed) and passes on the committed lazy placement. The caller transaction must have done no earlier write: with a prior write InnoDB keeps the locks even after `ROLLBACK TO`, so only a read-only-so-far caller distinguishes lazy from eager. |
| `red-lowest-deps-phpstan.txt` / `green-lowest-deps-phpstan.txt` | F56-01: PHPStan under `--prefer-lowest` (PHPStan 2.1.0) reports the counters tuple at line 573 before the fix and no errors after. |

Integration is now 200 tests (two runs OK); regression 86. Unit (379) and regression (86) suites, PHPStan max, php-cs-fixer, `composer validate/audit/check-platform-reqs`, `git diff --check` and the table-residue check are reported in the PR description.
