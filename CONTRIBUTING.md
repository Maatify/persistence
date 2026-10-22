# Contributing to maatify/persistence

Thank you for considering contributing to `maatify/persistence`! This document provides guidelines for contributing to this specific package.

## Package Identity

Before contributing, please understand the scope and intent of this package:

* **Standalone Composer Package**: This is a standalone, reusable package.
* **Framework-Agnostic**: It does not depend on or bind to any specific framework.
* **Host-Agnostic**: It does not contain namespaces, logic, or dependencies tied to a host application.
* **PDO-Based**: It relies entirely on native `\PDO` for database interactions.
* **NOT an ORM**: This package is not an Object-Relational Mapper.
* **No UI/HTTP/Routing**: It does not provide HTTP endpoints, controllers, routes, UI components, or generic application repository frameworks.

## Ways to Contribute

We welcome contributions in the following areas:

* Bug fixes within the package-owned behavior.
* Documentation improvements.
* Adding or improving tests.
* Static analysis and code-style improvements.

Please do not submit PRs that add new architectural domains or components outside the current scope of the package.

## Repository Structure

Contributions should respect the current directory structure:

* `src/`: Contains the production source code.
* `tests/`: Contains the test suites (`unit`, `regression`, and `integration`).
* `docs/`: Contains internal documentation, architecture decisions, and the pinned engineering standards.

## Local Verification

Before submitting a Pull Request, please ensure all local verification steps pass:

```bash
composer install
composer validate --strict
composer dump-autoload --optimize --strict-psr
composer check-platform-reqs
composer audit --no-interaction --abandoned=fail
composer analyse
composer test:unit
composer test:regression
composer test:integration
composer test:consumer
vendor/bin/php-cs-fixer fix --dry-run --diff
composer check:syntax
composer check:whitespace
composer check:residue
```

The commands above are the local parity sequence for the CI quality and test
gates. `composer test:integration` and `composer test:consumer` require the
real MySQL service configured below. The consumer harness must be run from the
package root; it creates and removes its own clean consumer root and test
table twice.

### CI-to-local gate mapping

Every CI gate runs a repository-owned command that you can run locally. The
workflow only coordinates jobs and services; the verification logic lives in
`composer.json` scripts and `scripts/`.

| CI job / step | Local invocation | Prerequisites |
| --- | --- | --- |
| Workflow lint | `actionlint -color` | actionlint `v1.7.12` installed |
| Validate and resolve dependencies | `composer validate --strict`, `composer update --no-interaction --prefer-dist --no-progress`, `composer check-platform-reqs`, `composer audit --no-interaction --abandoned=fail` | PHP >= 8.2, Composer |
| Autoload (quality) | `composer dump-autoload --optimize --strict-psr` | `composer install` |
| PHP syntax check | `composer check:syntax` | PHP, bash |
| PHPStan | `composer analyse` (CI: `vendor/bin/phpstan analyse -c phpstan.neon --no-progress`) | `composer install` |
| Code style | `vendor/bin/php-cs-fixer fix --dry-run --diff` | `composer install` |
| Repository integrity (whitespace) | `composer check:whitespace`, then `git diff --exit-code` | git; checks the committed tree, staged, unstaged and untracked changes |
| Unit/Regression | `composer test:unit`, `composer test:regression` | `composer install` |
| Lowest dependencies | `composer check:lowest`, then the PHPStan and code style commands above, then `composer test:unit`, `composer test:regression`, `composer test:integration` twice | MySQL (below); rewrites `vendor/`, so run `composer update` afterwards |
| Integration | `composer test:consumer`, then `composer test:integration` twice | MySQL (below) |
| Verify MySQL residue | `composer check:residue` | MySQL environment variables below; read-only |

The residue check reports every table, view, trigger, routine and event in the
test database whose name starts with a package-owned prefix listed in
`scripts/mysql-package-objects.php` (the single source of truth); it never drops
anything and ignores all other objects. A unit test fails when tests or examples
use a `maa_` name outside those prefixes. Run it after the integration and
consumer runs, against the dedicated test database only.

### Dependency audit

Dependency security is governed by the [Dependency Audit Policy](SECURITY.md#dependency-audit-policy) in `SECURITY.md`. The enforcing command is the same locally and in CI, and it is fail-closed:

```bash
composer audit --no-interaction --abandoned=fail
```

Run it after dependency resolution (`composer install` or `composer update`). Any advisory or abandoned package, direct or transitive, runtime or development, fails the command and the CI job. Do not add `audit` ignores, `--no-audit`, `continue-on-error` or shell fallbacks, and do not weaken the CI workflow to get past a finding; follow the remediation steps in the policy instead. If your local result differs from CI (for example because of a different PHP or Composer version), record the exact versions and advisory IDs in the pull request rather than treating the difference as a false positive.

### Integration Testing

Integration tests require a real MySQL database. SQLite is explicitly **not** supported as a substitute for these tests.

To run the integration suite, ensure you have a local MySQL server and set the following environment variables (adjust values to your local setup):

```bash
export PERSISTENCE_TEST_MYSQL_DSN="mysql:host=127.0.0.1;port=3306;dbname=your_test_database"
export PERSISTENCE_TEST_MYSQL_USER="your_test_user"
export PERSISTENCE_TEST_MYSQL_PASSWORD="your_test_password"
```

#### MySQL 8.4 with binary logging enabled

Some integration tests create triggers. MySQL 8.4 enables binary logging by
default, and while it is enabled an account without the `SUPER` privilege
cannot create triggers unless `log_bin_trust_function_creators` is `1`. Without
it, those tests fail with `SQLSTATE[HY000]: General error: 1419 You do not have
the SUPER privilege and binary logging is enabled` (11 errors on a stock
`mysql:8.4.10` server).

CI sets the variable on its disposable MySQL service in both integration jobs.
For a local disposable test server, do the same with an account that is allowed
to change global variables (for example `root`; the unprivileged test user from
the environment variables above cannot). With the CI image and credentials:

```bash
docker run -d --name persistence-test-mysql -p 127.0.0.1:3306:3306 \
  -e MYSQL_ROOT_PASSWORD=root_ci_password \
  -e MYSQL_DATABASE=maatify_persistence_test \
  -e MYSQL_USER=maatify_persistence \
  -e MYSQL_PASSWORD=persistence_ci_password \
  mysql:8.4.10
```

Once the server accepts connections, set the variable and verify it:

```bash
docker exec -e MYSQL_PWD=root_ci_password persistence-test-mysql \
  mysql --user=root --batch --skip-column-names \
  --execute="SET GLOBAL log_bin_trust_function_creators = 1; SELECT @@GLOBAL.log_bin_trust_function_creators;"
```

The query must print `1`. `SET GLOBAL` is lost when the server restarts, so
repeat it after a restart, or start the disposable server with
`--log-bin-trust-function-creators=1` appended to the `docker run` command
(after the image name) instead. For a server you already run, set the variable
the same way, or through its provisioning.

This is a setting for the throwaway test server only. It is not a
recommendation to change global settings, trust function creators or disable
binary logging on any production or shared server.

Then run:

```bash
composer test:integration
```

Or to run the full test suite:

```bash
composer test
```

Workflow syntax is verified locally with actionlint `v1.7.12`, using the
checksum pinned in `.github/workflows/ci.yml`:

```bash
actionlint -color
```

## Architectural Contribution Rules

When contributing code, you must adhere to the following architectural rules:

* **No Framework Bindings**: Do not introduce dependencies on any framework (e.g., Laravel, Symfony).
* **No Host Application Namespaces**: All code must reside within `Maatify\Persistence`.
* **No ORM**: Stick to raw `\PDO`.
* **No Host Table Coupling**: The package operates on tables provided via configuration; it must not hardcode host table names.
* **No Generic Repository Abstraction**: Keep the logic specific to the current goals (e.g., PDO ordering utilities).
* **SQL Identifiers**: Table and column names must remain trusted configurations, not raw user input.
* **Prepared Statements**: All runtime values must be bound using prepared statements.
* **Transaction Ownership**: `moveWithinScope()` owns a transaction only when no transaction is active and participates in an active caller-owned transaction without committing or fully rolling it back, undoing only its own mutation window with an operation-local savepoint taken after the scope lock. Composed operations and savepoint orchestrations must use the same PDO connection.
* **Scope Isolation**: Do not break scope isolation; rows outside the affected range must not be moved.
* **No Global Normalization**: Do not perform global normalization of gaps as a side effect of a scoped operation.
* **Rollback Behavior**: Rollbacks must preserve the original error/exception.
* **Exception Handling**: Do not catch every `\PDOException` or external `\Throwable` randomly to wrap it in a package exception. `PersistenceException` is strictly for package-defined exceptions.
* **Composer Lock**: This reusable library does not track `composer.lock`, in accordance with the [Composer Package Standard](docs/php-engineering-standards/standards/packages/COMPOSER_PACKAGE_STANDARD.md). Remove any locally generated `composer.lock` before submitting changes.

## Pull Request Rules

* **Focused PRs**: Submit one PR per specific fix or feature.
* **No Unrelated Changes**: Keep PRs clean of unnecessary or unrelated modifications.
* **Update Tests and Docs**: Any change in behavior must be accompanied by updated tests and documentation.
* **BC Impact**: Clearly state any Backwards Compatibility (BC) impact in the PR description.
* **Discussion First**: Public API and runtime behavior must not be changed without prior architectural discussion and explicit approval.
* **Security**: Do NOT open public issues or PRs for security vulnerabilities. Follow the instructions in `SECURITY.md`.
* **Changelog**: Notable changes should be recorded in `CHANGELOG.md`.
