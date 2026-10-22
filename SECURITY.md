# Security Policy

[![Maatify Persistence](https://img.shields.io/badge/Maatify-PERSISTENCE-blue?style=for-the-badge)](https://github.com/Maatify/persistence)
[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-9C27B0?style=for-the-badge)](https://github.com/Maatify)

## Supported Versions

The actively supported release line is `1.x`.

Security fixes are provided in the latest stable release within the supported `1.x` line. Users should upgrade to the latest available `1.x` version before reporting a vulnerability.

| Version | Supported |
|---------|-----------|
| `1.x`   | Yes       |
| `< 1.0` | No        |

## Reporting a Vulnerability

Please report vulnerabilities privately by emailing [support@maatify.dev](mailto:support@maatify.dev).

Do **not** publish exploitable details in a public issue or pull request.

When reporting a vulnerability, please include where possible:
* Affected package version or commit SHA
* Steps to reproduce
* Impact
* Suggested mitigation

## Security Scope

The scope of this security policy is strictly limited to the boundaries of the `maatify/persistence` package. Security reports may be relevant when they involve:

* Unsafe SQL identifier validation or quoting within the package logic.
* SQL injection vulnerabilities inside package-owned query construction.
* Failures in scope isolation (e.g., unintended movement of rows across different scopes).
* Modification of rows outside the intended scope or affected range.
* Incorrect transaction ownership behavior.
* Failure to rollback an owned transaction during an exception or error.
* Incorrect handling of soft-delete filtering.
* Unsafe handling of external throwables during operations.
* Unexpected disclosure of sensitive information through package exception behavior.
* Vulnerabilities in runtime dependencies that directly affect the package.

## Out of Scope

The following areas are explicitly out of scope for this package's security policy:

* Host application authentication and authorization mechanisms.
* Host application routing, middleware, controllers, or user interfaces.
* Host database credentials and connection security.
* PDO configuration and instantiation owned by the consuming application.
* Server, firewall, deployment, or infrastructure configuration.
* MySQL user privileges or configuration managed by the host environment.
* Misuse of the package that contradicts the documented contracts and intended usage.
* External schema modifications or data corruption not caused by the package itself.

## Dependency Audit Policy

This policy applies [CI Workflow Standard §9](docs/php-engineering-standards/standards/packages/CI_WORKFLOW_STANDARD.md) (Composer Security Audit) to this repository. It covers security advisories, abandoned packages, vulnerable direct dependencies and vulnerable transitive dependencies. The audit gate is fail-closed and this policy does not weaken it.

### Enforcement

The audit runs after dependency resolution, with the same command locally and in CI:

```bash
composer audit --no-interaction --abandoned=fail
```

* Locally it is part of the verification sequence in [CONTRIBUTING.md](CONTRIBUTING.md#local-verification).
* In CI ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) it runs in the `Quality`, `Unit/Regression` and `Integration` jobs after `composer update` and `composer check-platform-reqs`, and in the `Lowest dependencies` job through `composer check:lowest` (`scripts/resolve-lowest-dependencies.sh`).
* A non-zero exit fails the job and therefore the `CI Gate`. The audit step is never `continue-on-error`, never followed by a shell fallback, and no `audit` ignore, `ignore-severity` or `--no-audit` option is configured or used.
* `composer.lock` is not tracked, so the audit covers whatever versions the constraints in `composer.json` resolve to on the day it runs. A green audit is a statement about that resolution and that day's advisory data, not a permanent guarantee.

### Cases covered

| Case | Meaning | Gate result |
| --- | --- | --- |
| Security advisory | Composer reports an advisory for a resolved package version. | Fails. |
| Abandoned package | A resolved package is marked abandoned. `--abandoned=fail` makes this an error. | Fails. |
| Vulnerable direct dependency | The affected package is named in `require` or `require-dev` of `composer.json`. | Fails. |
| Vulnerable transitive dependency | The affected package is only pulled in by another package. | Fails. |

Every case fails the gate regardless of severity, and regardless of whether the affected code path is believed to be reachable. Impact assessment (below) decides how urgent and how broad the response is; it never decides that a finding may be ignored.

### Assessing impact: runtime versus development

Record, for each finding, the package, resolved version, advisory ID, severity, and which of these applies:

* **Runtime** (Composer packages in `require`, currently `maatify/exceptions`, and the packages it pulls in): installed by every consumer, so it can affect consumers of this package. Treat it as in scope of this document's [Security Scope](#security-scope), prepare a fix with priority, and handle it under the [Disclosure Policy](#disclosure-policy) when it affects a published version.
* **PHP platform requirement** (`ext-pdo`, and `php`): not a Composer-installed package, so `composer audit` does not report advisories against it. It is checked by `composer check-platform-reqs`; its security depends on PHP and host patching by the consuming environment.
* **Development** (`require-dev`: PHPUnit, PHPStan, PHP-CS-Fixer and their dependencies): not installed for consumers and not shipped in the package, but it runs on contributor machines and in CI with repository access. It still blocks the gate and must be resolved, but it does not by itself imply a vulnerability in a published release.
* **Both or unclear**: assess it as runtime until the dependency path is shown otherwise (`composer why <package>`, `composer why-not <package> <version>`).

A finding is never described as a false positive without evidence that the advisory does not apply to the resolved version. Findings that differ between environments are recorded with exact versions (PHP, Composer, package and advisory IDs) and investigated separately; a difference is not, by itself, proof of either an exposure or a false positive.

### Remediation

1. Reproduce with the command above and capture `composer audit --format=json --abandoned=fail`, `composer show --tree` for the affected package, and the PHP and Composer versions.
2. Establish the dependency path and whether the package is runtime or development.
3. If a patched version exists within the current constraints, update to it (`composer update <package> --with-dependencies`). If it only exists outside the constraints, widen or raise the constraint in a reviewed pull request that states the compatibility impact.
   * Updating a local `vendor/` does not remove an affected version that the declared constraints still allow. Because `composer.lock` is not tracked and the `Lowest dependencies` job resolves with `--prefer-lowest`, check that every supported resolution (latest and lowest) audits clean. If any of them can select an advisory-affected version, investigate and raise or narrow the relevant allowed lower bound in a separate reviewed dependency change, subject to compatibility review and the owner's governance, then re-run the latest and lowest audits and the tests. Differences between environments are investigated separately (Issue #69), not suppressed.
4. For a transitive dependency, update the direct parent that requires it; if no parent release allows a safe version, report it to that package's maintainers and handle it as step 5 below. Do not patch files in `vendor/`.
5. When no safe version is available yet, or an abandoned package has no replacement:
   * The gate stays failing. Do not merge unrelated work over a failing audit, and do not add an ignore, skip, baseline or severity filter to make it pass.
   * Handle it privately first. When the vulnerability details are undisclosed or sensitive, do not open a public issue or pull request for them; use the private channel in [Reporting a Vulnerability](#reporting-a-vulnerability) and record the dependency path, classification and upstream status there. A public tracking issue is acceptable only for already-public advisory or abandonment metadata (advisory ID, package, affected and fixed versions, upstream status), with no exploitable details and no private report content.
   * Evaluate removing or replacing the dependency, or constraining the version range to exclude the affected versions, in a separate reviewed pull request.
   * For a runtime finding that affects a published release, follow the [Disclosure Policy](#disclosure-policy).
6. Verify the fix by re-running the full audit locally and in CI, including the `Lowest dependencies` job, and by recording the results in the pull request.

This policy defines no exception or waiver mechanism, and none can be granted at repository level while the pinned CI Workflow Standard §9 applies: it forbids ignoring vulnerabilities and abandoned packages and requires the audit to stay fail-closed. No ignore, severity skip, baseline or owner-approved local relaxation is available. A proposal incompatible with the standard would first need its own authoritative standards-governance resolution; only after that could any downstream policy or workflow change be considered.

## Disclosure Policy

Once a security vulnerability is reported via `support@maatify.dev`, the Maatify team will:
1. Acknowledge receipt of the vulnerability report.
2. Review and investigate the report to determine impact and scope.
3. Keep the reporter updated on the status of the investigation.
4. Prepare and test a security patch when deemed necessary.
5. Issue a patched version when appropriate.
6. A security advisory may be published if the issue affects a published version and public disclosure is warranted.
