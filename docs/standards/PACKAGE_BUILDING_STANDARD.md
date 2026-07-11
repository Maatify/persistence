# PACKAGE_BUILDING_STANDARD

**Maatify Standalone Composer Package Building Standard — v1**
This document is the law for building any new standalone Composer package in the Maatify ecosystem.
Read it fully before writing a single line of code.

---

## 1. The Package Contract

Every package must be:

- **Standalone** — runs isolated from host applications (depends only on explicit Composer/runtime dependencies) with no knowledge of the host project internals
- **Installable** — packaged as a `composer require` library
- **Host-agnostic** — never FKs or JOINs on host tables. Host provides IDs; package trusts them. Host applications wire dependencies themselves.
- **PDO-based** — all persistence uses PDO directly. No ORM, no external query builder.
  Small internal SQL fragment builders are allowed only for repeated package-local query logic.
- **PHPStan max** — zero errors at level max before the package is considered done

---

## 2. Required Maatify Runtime Dependencies

To maintain standalone Composer package boundaries and a framework-agnostic architecture while ensuring ecosystem consistency, packages must rely on the provided Maatify shared packages rather than defining package-local duplicates.

- **Exceptions:** Package exceptions must depend on `maatify/exceptions`
  Repository: https://github.com/Maatify/exceptions

- **Clock/Date-Time:** Clock and date-time contracts must depend on `maatify/shared-common`
  Repository: https://github.com/Maatify/SharedCommon

**Rule:** Packages must not define a local duplicate exception hierarchy or local clock abstraction if the contract or base is available in Maatify shared packages.

The declaration, constraint, ordering, and validation rules for these dependencies are governed by [COMPOSER_PACKAGE_STANDARD.md](COMPOSER_PACKAGE_STANDARD.md).

This ensures:
- Explicit Composer/runtime dependencies only
- Public API stability
- Backward compatibility as much as possible

---

## 3. Required Files

Repository presentation, governance-document identity, release-facing metadata, and visual consistency MUST follow [LIBRARY_PRESENTATION_STANDARD.md](LIBRARY_PRESENTATION_STANDARD.md).
Composer package metadata, dependency declarations, autoloading, scripts, configuration, stability, and lock-file policy MUST follow [COMPOSER_PACKAGE_STANDARD.md](COMPOSER_PACKAGE_STANDARD.md).


Every package must contain these files at its root (the repository root is the package root):

```
├── README.md                          ← installation, quick examples, what it does / does not
├── CHANGELOG.md                       ← versioned history, starting at [1.0.0]
├── {PACKAGE_NAME}_PACKAGE_REFERENCE.md ← complete API reference and design rules (e.g. docs/EVENT_LOGGING_MODULE_REFERENCE.md)
├── composer.json                      ← governed by COMPOSER_PACKAGE_STANDARD.md
├── phpstan.neon                       ← level: max, paths: [src, tests]
├── src/                               ← all PHP source code
├── tests/                             ← if applicable
├── schema/                            ← if the package owns SQL schema
└── docs/                              ← for architecture/integration/audits
```

---

## 4. Namespace

Pattern: `Maatify\{PackageName}\`

```
Maatify\EventLogging\
Maatify\{NextPackage}\
```

*Note: Host app namespaces such as `App\`, `Athar`, or `EP4N` are strictly forbidden.*

---

## 5. Directory Structure Inside `src/`

```
src/
├── {Domain}/                                        ← subpackage / domain boundary
│   ├── Exception/
│   ├── Contract/
│   ├── DTO/
│   ├── Infrastructure/Repository/
│   └── Service/
│
├── Common/                                          ← framework-neutral shared primitives only
│
├── Factory/                                         ← optional, only if framework-agnostic
│
└── Provider/                                        ← optional, only if framework-agnostic
```

- **No mandatory `Admin/Customer`**: Domain boundaries should reflect logical separation.
- **No mandatory `Bootstrap`**: Host apps are responsible for wiring.
- **No framework-specific ServiceProvider/Bindings**: E.g., no Laravel/Slim/PHP-DI bindings inside the package.

---

## 6. Schema Rules

- Allowed when the package owns persistence.
- Table prefix: `maa_{package_short_name}_` (e.g. `maa_event_logging_`)
- Every table needs: `PRIMARY KEY (id)`, proper indexes, meaningful COMMENTs on columns.
- All policies (soft delete, display order, FK behavior, uniqueness) documented in the SQL header.
- Domain-local schema files are allowed (e.g. `src/{Domain}/Database/`).
- Package-level `schema/README.md` may index domain-local SQL files.
- **No generic shared `logs` or `event_logs` tables**; use strict domain-isolated tables.
- No FK constraints or JOINs to host app tables — use `COMMENT 'Host-provided ID. No FK.'`.

---

## 7. Exception Rules

### Standard Exception Types

1. Every package-defined exception MUST use the appropriate `maatify/exceptions` hierarchy.
2. Every package MUST expose a package marker interface extending `\Throwable`.
3. Stable, package-owned failure classifications SHOULD use named package exceptions.
4. A known domain or storage condition MAY be converted to a named package exception when the package owns a stable semantic classification.
5. Unknown or external `PDOException` / `Throwable` instances MAY propagate unchanged when preserving the original diagnostic contract is intentional.
6. A package MUST document whether it wraps or propagates infrastructure errors.
7. Blind catch-all wrapping is forbidden.
8. Swallowing errors is forbidden.
9. When wrapping, preserve the original throwable as `previous` where supported.
10. Transaction catch blocks must rollback owned transactions and rethrow the original throwable unless an explicitly documented semantic conversion is performed.
11. Rethrowing the original throwable after rollback is valid and is not a violation of named-exception rules.
12. Packages MUST NOT be universally required to convert every external infrastructure failure into a package-defined exception. The chosen wrapping or propagation contract must follow the package-owned semantic boundary and be documented.

### Interface

```php
interface {PackageName}ExceptionInterface extends \Throwable {}
```

### Example: Package-Defined Storage Exception

```php
final class {Domain}DatabaseException extends \Maatify\Exceptions\Exception\System\SystemMaatifyException
    implements {PackageName}ExceptionInterface
{
    // Implementation uses Maatify codes
}
```

Named constructors SHOULD be used when a stable semantic constructor exists.

Direct construction MAY be used when no suitable named constructor exists and the package's documented exception contract permits it.

Call sites MUST NOT construct generic or semantically misleading exceptions merely to avoid defining an appropriate package-owned classification.

### Fail-Open / Fail-Closed Behavior

Behavior must stay domain-specific:
- **Authoritative Domains** (e.g. `AuthoritativeAudit`) are fail-closed where required by the domain.
- **Non-Authoritative Domains** may fail-open only at an explicitly documented boundary.
- **Repositories and Read Queries** must never silently swallow storage failures.

### What the package catches and converts

```php
// SQLSTATE 23xxx = integrity constraint violation (duplicate key)
} catch (\PDOException $e) {
    if (str_starts_with((string) $e->getCode(), '23')) {
        throw {Domain}CodeAlreadyExistsException::withCode($command->code);
    }
    throw $e; // anything else → propagate as-is
}
```

### Transaction Pattern

In any method that uses `beginTransaction()`:

```php
        $this->pdo->beginTransaction();

        try {
            // ...
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // Optional documented semantic conversion may occur here.

            throw $e;
        }
```

Only package-owned transactions are rolled back by this pattern.
Rollback must be attempted only while the transaction is active.
The original throwable is rethrown unless an explicitly documented semantic conversion is performed.
Swallowing the throwable is forbidden.
If a semantic conversion wraps the original throwable, the original should be retained as `previous` where supported.

## 8. Command Rules

Commands are self-validating value objects:

```php
final readonly class CreateSomethingCommand
{
    public function __construct(
        public string  $code,
        public string  $name,
        public bool    $isActive,
        public ?string $notes,
    ) {
        if (trim($code) === '') {
            throw SomethingInvalidArgumentException::emptyField('code');
        }
        if (trim($name) === '') {
            throw SomethingInvalidArgumentException::emptyField('name');
        }
    }
}
```

Rules:
- `final readonly` — always
- Validation only in the constructor — no business logic
- Never contains `display_order` — auto-assigned on create
- Never contains `image` — updated via dedicated method
- Host IDs (e.g. `methodId`, `currencyId`) must be validated with `>= 1` guard
- Decimal strings must be validated with `preg_match('/^\d+(?:\.\d{1,4})?$/', $value)` before any `bcmath` call
- Date/time strings must be validated with `new \DateTimeImmutable($value)` in a try/catch

---

## 9. DTO Rules

```php
final readonly class SomethingDTO implements \JsonSerializable
{
    public function __construct(
        public int    $id,
        public string $name,
    ) {}

    public function jsonSerialize(): mixed
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
```

- `final readonly` — always
- Implements `\JsonSerializable`
- Collection DTOs implement `\IteratorAggregate` + `\JsonSerializable`:

```php
/** @implements \IteratorAggregate<int, SomethingDTO> */
final readonly class SomethingCollectionDTO implements \IteratorAggregate, \JsonSerializable
{
    /** @var list<SomethingDTO> */
    private array $items;

    /** @param list<SomethingDTO> $items */
    public function __construct(array $items) { $this->items = $items; }

    /** @return \ArrayIterator<int, SomethingDTO> */
    public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->items); }

    public function jsonSerialize(): mixed { return $this->items; }
}
```

---

## 10. Repository Rules

### Command Repository Return Types

| Operation | Returns | Why |
|---|---|---|
| `create()` | `int` | `lastInsertId()` |
| `update()` | `bool` | `rowCount() > 0` |
| `updateStatus()` | `bool` | `rowCount() > 0` |
| `updateDisplayOrder()` | `bool` | delegated to `ScopedOrderingManager` |
| `updateImage()` | `bool` | `rowCount() > 0` |
| `softDelete()` | `bool` | `rowCount() > 0` |
| `hardDelete()` | `bool` | `rowCount() > 0` after transaction |
| `findById()` | `?DTO` | `null` if not found — Service decides whether to throw |

### `findById` Pattern

```php
// Repository — returns null, never throws for not-found
public function findById(int $id): ?SomeDTO
{
    $stmt = $this->pdo->prepare('SELECT ... FROM maa_something WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);

    /** @var array<string, mixed>|false $row */
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        return null;
    }

    return $this->hydrateDetail($row);
}

// Service — throws NotFoundException when null
public function getById(int $id): SomeDTO
{
    $dto = $this->queryReader->findById($id);

    if ($dto === null) {
        throw SomethingNotFoundException::withId($id);
    }

    return $dto;
}
```

### Hydration — never cast `mixed` directly

```php
// ❌ PHPStan max rejects this
isActive:     (bool) ($row['is_active']     ?? false),
displayOrder: (int)  ($row['display_order'] ?? 0),

// ✅ extract first, check type, then cast
$isActive     = $row['is_active']     ?? null;
$displayOrder = $row['display_order'] ?? null;

isActive:     (is_int($isActive)     || is_string($isActive)) && (int) $isActive === 1,
displayOrder: (is_int($displayOrder) || is_string($displayOrder)) ? (int) $displayOrder : 0,
```

---

## 11. Pagination Pattern

This section governs reusable PDO offset pagination. It defines the canonical boundary between the caller-owned query and the pagination component. Pagination code MUST remain framework-agnostic, host-agnostic, HTTP-independent, PDO-based, and deterministic.

### Ownership Boundary

The caller Repository / QueryReader MUST own:

- mandatory security, tenant, ownership, visibility, and soft-delete constraints
- optional search and filter construction
- JOINs and selected columns
- SQL placeholder selection
- semantic alignment between count and data queries
- row mapping into the host's expected array or DTO

The pagination component MUST own:

- page and per-page normalization
- total and filtered count execution
- deterministic whitelist-based sorting
- the stable internal tie-breaker
- `LIMIT` / `OFFSET` calculation and binding
- result metadata
- row-mapper invocation

The pagination component MUST NOT become a filter builder, search builder, general Query Builder, ORM, HTTP adapter, request parser, controller, or response emitter.

### Canonical Query Descriptor

The caller MUST provide three separate SQL statements and three separate parameter maps:

1. `totalSql` / `totalParams`
2. `filteredCountSql` / `filteredParams`
3. `dataSql` / `dataParams`

`totalSql` counts the base visible dataset after all mandatory constraints and before optional search/filter.

`filteredCountSql` counts the same base visible dataset after optional search/filter.

`dataSql` returns the same filtered dataset represented by `filteredCountSql`, excluding ordering and pagination.

Even when no optional filters exist, both count statements remain required and MAY be identical.

The caller MUST keep `filteredCountSql`, `filteredParams`, `dataSql`, and `dataParams` semantically aligned. The pagination component does not parse SQL to prove semantic equivalence.

All SQL strings MUST:

- be non-empty after trimming
- omit a trailing semicolon
- be trusted application-built SQL
- contain no raw user-controlled SQL fragments

`dataSql` MUST be suitable for appending one `ORDER BY`, one `LIMIT`, and one `OFFSET` clause. It MUST NOT already contain conflicting top-level pagination or ordering clauses. This is a caller contract; a pagination component MUST NOT claim to provide a complete SQL parser.

### Parameter Contract

Version 1 supports named placeholders only. Positional `?` placeholders and statements that mix positional and named placeholders are unsupported.

Caller parameter maps MUST use keys without a leading colon:

```php
[
    'status' => 1,
    'tenant_id' => 15,
]
```

Supported parameter value types are:

```php
string|int|bool|null
```

Floats MUST NOT be accepted; decimal values must be supplied as validated decimal strings.

The complete reserved internal parameter namespace is:

```text
__pagination_*
```

Caller maps MUST NOT use any key beginning with `__pagination_`. Caller SQL statements MUST NOT contain any named placeholder beginning with `:__pagination_`. Either prefix collision is an invalid query descriptor.

The package currently uses:

```text
__pagination_limit
__pagination_offset
```

This reserved-prefix collision check does not imply general SQL parsing.

Within one SQL statement:

- every named placeholder MUST have one unique occurrence
- every named placeholder MUST have one matching parameter-map entry
- every parameter-map key MUST be used by one matching named placeholder
- reusing the same logical value requires distinct placeholder names and matching parameter entries

These are caller contracts for compatibility with native prepared statements. A pagination component MUST NOT claim to parse SQL or preflight general placeholder correspondence. Violations may surface as an original `PDOException` or a package-classified non-throwing PDO failure state.

The component adds the leading colon internally and binds values by type:

- `int` => `PDO::PARAM_INT`
- `bool` => `PDO::PARAM_BOOL`
- `null` => `PDO::PARAM_NULL`
- `string` => `PDO::PARAM_STR`

`LIMIT` and `OFFSET` MUST be bound explicitly using `PDO::PARAM_INT`.

### Request Normalization

The canonical defaults are:

```text
defaultPerPage = 20
minPerPage     = 1
maxPerPage     = 200
```

These are the canonical constructor defaults. Callers MAY configure different valid values through the approved pagination configuration object, provided its documented invariants remain satisfied.

Accepted page and per-page inputs are integers or trimmed decimal-integer strings that fit inside the PHP integer range. Decimal points and exponent notation are invalid.

Normalization rules:

- missing, empty, malformed, or unrepresentable `page` => `1`
- representable `page < 1` => `1`
- valid `page` => the parsed integer
- missing, empty, malformed, or unrepresentable `per_page` => `defaultPerPage`
- representable `per_page < minPerPage` => `minPerPage`
- representable `per_page > maxPerPage` => `maxPerPage`
- valid `per_page` => the parsed integer

User-input normalization failures MUST fall back and MUST NOT throw package exceptions.

### Count and Overflow Semantics

Counts MUST execute in this order:

1. total count
2. filtered count
3. data query, unless the filtered count is zero

Both count statements MUST return exactly one row containing exactly one column. The component MUST verify both cardinalities before accepting the value.

That single value MUST be a non-negative integer representation fitting inside the PHP integer range. A non-integer, negative, absent, unrepresentable, multi-row, or multi-column count result is an execution failure.

Definitions:

- `total` = base visible dataset after mandatory constraints and before optional search/filter
- `filtered` = the same base visible dataset after optional search/filter
- `total_pages` = pages calculated from `filtered`, not `total`

The total-page calculation MUST avoid integer-overflow-prone addition:

```php
$totalPages = $filtered === 0
    ? 0
    : intdiv($filtered - 1, $perPage) + 1;
```

After zero-result and overflow normalization, and only when `filtered > 0`, offset MUST be calculated exactly as:

```php
$offset = ($page - 1) * $perPage;
```

This is integer-safe because the effective page cannot exceed `totalPages`, so the page start cannot exceed `filtered - 1`.

When `filtered === 0`:

- effective page = `1`
- `total_pages = 0`
- data query is not executed
- `data = []`
- `has_next = false`
- `has_previous = false`

When the normalized requested page is greater than a positive `total_pages`, the effective page MUST reset to `1`, and the first page of the filtered result set is returned.

The component MUST NOT retry automatically when concurrent writes make count and data results differ. Callers requiring a consistent snapshot may use a caller-owned transaction.

### Deterministic Sorting

Every paginated data query MUST have deterministic ordering.

User-selected sorting MUST be resolved through a trusted whitelist. Raw request values, raw column names, directions, expressions, or SQL fragments MUST NOT be interpolated into `ORDER BY`.

Version 1 supports:

- one user/default primary sort
- one internal stable tie-breaker
- directions `ASC` and `DESC` only

The resolved final tie-breaker identifier MUST be unique within every filtered dataset to which the configuration is applied. A primary key such as `id` is the usual choice. The caller owns this guarantee because the component cannot inspect schema constraints or prove uniqueness.

When the primary and tie-breaker resolve to the same identifier, that identifier itself MUST be unique. A non-unique final tie-breaker is a trusted configuration defect even when Runtime validation cannot detect it.

Whitelist values MUST be validated identifier paths, not arbitrary SQL expressions. Supported examples:

```text
created_at
v.created_at
catalog.products.created_at
```

Every identifier segment MUST match:

```text
[A-Za-z_][A-Za-z0-9_]*
```

The component MUST quote each segment internally.

Functions, arithmetic, JSON expressions, collations, `CASE`, commas, directions, comments, semicolons, `LIMIT`, and `OFFSET` are outside the version-1 whitelist contract.

Invalid `sort_by` falls back to the configured default key. Invalid `sort_direction` falls back to the configured default direction. Applied sort metadata MUST report the actual fallback or accepted values, not the invalid raw input.

If the effective primary sort and configured tie-breaker resolve to the same quoted identifier, the component MUST emit that identifier once using the effective primary direction. Otherwise it appends the tie-breaker using its configured direction.

Canonical final SQL shape:

```sql
{dataSql}
ORDER BY {quoted_primary_identifier} {ASC|DESC},
         {quoted_tie_breaker_identifier} {ASC|DESC}
LIMIT :__pagination_limit
OFFSET :__pagination_offset
```

The second ordering expression is omitted when it duplicates the resolved primary identifier.

### Mapper Contract

A reusable paginator MUST require a row mapper with the conceptual signature:

```php
callable(array<string, mixed> $row): array|object
```

Rows MUST be fetched as associative arrays without mutating connection-wide PDO fetch-mode attributes.

The mapper:

- transforms one fetched row into the caller's array or DTO
- MUST NOT perform pagination logic
- MAY intentionally return the raw row through an explicit identity mapper

The result data collection MUST always be a list. Mapper-thrown `Throwable` instances propagate unchanged. A mapper result that is neither an array nor an object is a package-owned execution failure.

### Canonical Result Contract

A typed result object MUST own the canonical result and implement `JsonSerializable`. Its array / JSON representation MUST be:

```php
[
    'data' => $items,
    'pagination' => [
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'filtered' => $filtered,
        'total_pages' => $totalPages,
        'has_next' => $hasNext,
        'has_previous' => $hasPrevious,
        'sort_by' => $effectiveSortBy,
        'sort_direction' => $effectiveSortDirection,
    ],
]
```

Contract rules:

- `data` is always a list
- empty data is `[]`
- `page` is the effective page after normalization and overflow handling
- `sort_by` and `sort_direction` are the applied values
- `sort_direction` is uppercase
- internal `offset` is never exposed

The typed result object MUST reject inconsistent state. At minimum:

- `data` is verified with `array_is_list()`
- every item in `data` is an array or object
- `count($data) <= $perPage`
- `page >= 1` and `per_page >= 1`
- `total`, `filtered`, and `total_pages` are non-negative
- `total_pages` exactly equals `0` when `filtered === 0`, otherwise `intdiv($filtered - 1, $per_page) + 1`
- `filtered === 0` requires `data === []`
- `total_pages === 0` requires page `1` and both navigation flags `false`
- a positive `total_pages` requires `page <= total_pages`
- navigation flags exactly match the effective page and `total_pages`
- `sort_by` matches `^[A-Za-z_][A-Za-z0-9_]*$`

An empty `data` list while `filtered > 0` remains valid because concurrent writes may change the dataset between the count and data statements.

An inconsistent result state is a package-owned execution failure.

### Transaction Contract

A read paginator MUST NOT own transactions.

It MUST:

- execute on the provided PDO connection
- allow execution inside a caller-owned active transaction

It MUST NOT:

- call `beginTransaction()`
- call `commit()`
- call `rollBack()`
- reject an active caller-owned transaction

The guarantee is limited to the paginator making none of those explicit transaction-control calls. External database/driver behavior and mapper-owned code may affect transaction state and are outside the paginator guarantee. Verification MUST NOT claim that every possible external failure preserves an active caller transaction.

### Exception and Error Propagation

Invalid trusted configuration, Runtime-checkable invalid query-descriptor structure, and non-throwing PDO failure states SHOULD use stable package-defined exceptions following the package exception policy.

Non-throwing `PDO::prepare()`, `PDOStatement::bindValue()`, and `PDOStatement::execute()` failures are package-owned execution classifications.

General placeholder correspondence, repetition, positional-placeholder, and mixed-placeholder violations remain caller contracts because the component does not provide a SQL parser. They are not guaranteed constructor-time query-descriptor exceptions.

Actual `PDOException` instances and unknown external `Throwable` instances MAY propagate unchanged when preserving the original diagnostic contract is intentional.

Blind catch-all wrapping and swallowed errors are forbidden.

### Backward-Compatible Adoption

This is the canonical contract for new pagination implementations and for explicitly approved migrations.

Existing endpoints MUST NOT have their public response shape changed silently. Migration MAY:

- return the canonical shape directly when additive fields are approved
- preserve an existing legacy shape through a host-owned adapter
- proceed endpoint-by-endpoint after compatibility review

A reusable pagination component MUST be implemented and verified before host repositories are migrated. Mass migration is forbidden unless separately approved.

### Required Verification

A pagination implementation MUST include:

- Unit coverage for normalization, configuration, identifier validation, query descriptors, result serialization, complete result invariants, reserved-prefix collisions, and package-owned non-throwing PDO failure classifications
- Regression coverage for exact public API signatures, result keys, named-placeholder-only caller boundaries, and the caller-owned tie-breaker uniqueness guarantee
- real supported-database Integration coverage for exact count cardinality, count failures, overflow, sorting, binding, mapper behavior, transaction participation, and failure propagation
- native-prepared-statement coverage for repeated, missing, unused, positional, and mixed placeholder violations without claiming constructor SQL parsing
- deterministic duplicate-primary-value fixtures using a caller-guaranteed unique tie-breaker
- verification that raw user sort input never enters final SQL
- verification that no transaction is started, committed, or rolled back by the paginator

SQLite MUST NOT substitute for MySQL-owned behavior. Integration testing must follow `CI_WORKFLOW_STANDARD.md`.

---

## 12. Translation Pattern

### Always support both paths

```php
// Path 1: with translation (for apps using a translation system)
listByLanguageId(int $languageId): CollectionDTO

// Path 2: base name only (for apps without a translation system)
listWithoutTranslation(): CollectionDTO
```

### JOIN Guard — critical for avoiding duplicate rows

```php
// ONLY join translations when languageId is explicitly provided.
// Without this guard: if a method has 2 translations (ar + en),
// a JOIN without language filter returns 2 rows per method.

if ($languageId !== null) {
    $joinSql           = 'LEFT JOIN maa_something_translations t
                              ON t.something_id = s.id
                             AND t.language_id  = :language_id';
    $translationSelect = 'COALESCE(t.name,  s.name)  AS name,
                          COALESCE(t.image, s.image) AS image';
    $params['language_id'] = $languageId;
} else {
    $joinSql           = '';
    $translationSelect = 'NULL AS translated_name, NULL AS translated_image';
}
```

### COALESCE Fallback Chain

```sql
-- Always in customer queries:
COALESCE(t.name,  s.name)  AS name    -- translated → base
COALESCE(t.image, s.image) AS image   -- translated → base

-- Admin findById (no language): select s.name directly — no JOIN needed
```

### Upsert Pattern — always, never separate INSERT + UPDATE

```sql
INSERT INTO maa_something_translations
    (something_id, language_id, name)
VALUES
    (:something_id, :language_id, :name)
ON DUPLICATE KEY UPDATE
    name       = VALUES(name),
    updated_at = NOW()
```

Requires `UNIQUE KEY (something_id, language_id)` on the translation table.

### Admin Translation List — LEFT JOIN on base table

```sql
SELECT
    t.id,
    t.something_id,
    t.language_id,
    t.name,
    t.image,
    t.created_at,
    t.updated_at,
    s.name  AS base_name,    -- shown alongside translation for admin comparison
    s.image AS base_image
FROM maa_something_translations t
LEFT JOIN maa_something s ON s.id = t.something_id
{$whereSql}
ORDER BY t.something_id ASC, t.language_id ASC
```

### Global Search Scope

| Context | Search fields |
|---|---|
| Admin main list | Base table fields only (`s.name`, `s.code`) — never join translations for search |
| Admin translation list | Translation fields only (`t.name`) — that IS the translation table |
| Customer list | No search — customer receives a filtered, ordered list only |

---

## 13. Service Rules

Responsibility:
- **Business orchestration** lives in Services
- **Validation** lives in Commands and DTO filters
- **SQL** lives in Repositories or package-local/domain-local SQL support builders

```php
// Command service — throws NotFoundException when repo returns false
public function update(UpdateSomethingCommand $command): void
{
    $updated = $this->commandRepo->update($command);
    if (! $updated) {
        throw SomethingNotFoundException::withId($command->id);
    }
}

// Query service — throws NotFoundException when repo returns null
public function getById(int $id): SomethingDTO
{
    $dto = $this->queryReader->findById($id);
    if ($dto === null) {
        throw SomethingNotFoundException::withId($id);
    }
    return $dto;
}
```

Services **never**:
- Contain raw SQL
- Catch exceptions (let them propagate)
- Instantiate repositories directly (use constructor injection)

---

## 14. Admin vs Customer Separation

Some business modules may choose actor-specific namespaces (e.g., `Admin\` vs `Customer\`).

However, **infrastructure packages** like `event-logging` should use their real package/domain boundaries instead.
For example, in `event-logging`, the six logging domains (e.g., `AuthoritativeAudit`, `BehaviorTrace`) serve as the public architectural boundaries rather than arbitrary Admin/Customer folders.

---

## 15. Read / Admin Query API Rules

Packages that have persisted data intended to be viewed, searched, audited, monitored, or reported by host applications should expose framework-agnostic PHP read/query contracts where applicable.

**Important:** This refers strictly to **PHP-level APIs** (e.g., PHP interfaces and DTOs), not HTTP APIs.

These contracts may cover (where applicable and appropriate for the package's domain):
- Admin listing
- Search
- Dashboard summaries
- Reporting summaries

**Explicitly forbidden inside the package:**
- HTTP controllers
- Routes
- Middleware
- Permissions
- UI dashboards
- CSV/PDF/Excel exports
- Host-specific actor/name resolution
- JOINs/FKs on host tables

Remember to uphold the core principles: maintain a standalone, framework-agnostic, and host-agnostic architecture, prioritize domain boundaries over mandatory Admin/Customer folder structures, and ensure all public query capabilities have matching PHP contracts/interfaces.

---

## 16. display_order Rules

- Auto-assigned on `create` via `ScopedOrderingManager::getNextPosition()`
- Never in `CreateCommand` or `UpdateCommand`
- Updated via dedicated `updateDisplayOrder(int $id, int $displayOrder): void`
- `ScopedOrderingManager::moveWithinScope()` handles shift + clamp to valid range
- Soft delete: **no compact** — record still exists in DB
- Hard delete: `compactScopeAfterRemoval()` inside transaction — closes the gap

---

## 17. Image Rules

- `image` column is `VARCHAR(255) NULL` — stores path or URL only, never binary data
- Never in `CreateCommand` or `UpdateCommand`
- Updated via dedicated `updateImage(int $id, ?string $image): void`
- `null` clears the image (column set to NULL)
- Translation images follow the same rule via `updateTranslationImage(int $id, ?string $image): void`

---

## 18. Bootstrap / DI Rules

**Composer packages must not require host-specific bindings.**

Rules:
- No Slim/Laravel/Symfony/PHP-DI bindings as package requirements.
- Optional factories or providers are allowed, but they must be strictly framework-agnostic.
- Host applications are fully responsible for wiring dependencies through their own container or runtime environment.

---

## 19. Decimal / Financial Rules

- All monetary values stored as `string` (DECIMAL precision — never `float`)
- All arithmetic uses `bcmath` — never native PHP arithmetic on monetary values
- Validate decimal format **before** any `bcmath` call:

```php
if (! preg_match('/^\d+(?:\.\d{1,4})?$/', $value)) {
    throw SomethingInvalidArgumentException::invalidDecimal($field, $value);
}
```

- Always pass explicit scale: `bcadd($a, $b, 4)`, `bcmul($a, $b, 4)`, `bcdiv($a, $b, 8)`

---

## 20. PDO Named Placeholder Rule

PDO does not reliably support the same named placeholder more than once per statement.
Every placeholder must appear exactly once per SQL string.

When the same value is needed in multiple subqueries:

```php
// ❌ WRONG — same placeholder used twice
AND gc.country_code = :country_code  -- subquery 1
AND gc.country_code = :country_code  -- subquery 2

// ✅ CORRECT — unique names, same value
AND gc_al.country_code = :country_code_allow   -- allowlist subquery
AND gc_bl.country_code = :country_code_block   -- blocklist subquery

$params['country_code_allow'] = $countryCode;
$params['country_code_block'] = $countryCode;
```

---

## 21. PHPStan and Testing Rules

### `phpstan.neon`

```neon
parameters:
    level: max
    paths:
        - src
        - tests
```

### Testing Strategy

- **Testing Requirements**: Packages that own persistence/database behavior must provide integration tests where practical. Unit/Regression suites are required where applicable.
- **Database Tests**: DB-dependent integration tests must use the real service targeted by the package-owned persistence. They must not depend on host application databases, host schemas, framework bindings, or secrets.
- **MySQL & SQLite Rules**: For MySQL-owned packages, integration tests must use a real MySQL service/DSN, not SQLite. SQLite fallback/support is forbidden for MySQL-owned packages unless SQLite is explicitly declared as a real supported persistence target for that package.
- **Example Code**: Must be purely illustrative, validated via syntax checks (`php -l`), devoid of real credentials or framework bindings.

### PDO fetch results — always annotate

```php
/** @var array<string, mixed>|false $row */
$row = $stmt->fetch(PDO::FETCH_ASSOC);

/** @var list<array<string, mixed>> $rows */
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

### IteratorAggregate — generic annotations required

```php
/** @implements \IteratorAggregate<int, SomethingDTO> */
final readonly class SomethingCollectionDTO implements \IteratorAggregate, \JsonSerializable

/** @return \ArrayIterator<int, SomethingDTO> */
public function getIterator(): \ArrayIterator
```

### list type annotations

```php
/** @var list<SomethingDTO> */
private array $items;

/** @param list<SomethingDTO> $items */
public function __construct(array $items)
```

### Hydration — extract before cast

```php
// ❌ PHPStan max rejects direct cast of mixed
isActive:     (bool) ($row['is_active']     ?? false),
displayOrder: (int)  ($row['display_order'] ?? 0),

// ✅ extract, check type, then cast
$isActive     = $row['is_active']     ?? null;
$displayOrder = $row['display_order'] ?? null;

isActive:     (is_int($isActive)     || is_string($isActive)) && (int) $isActive === 1,
displayOrder: (is_int($displayOrder) || is_string($displayOrder)) ? (int) $displayOrder : 0,
```

### LIMIT / OFFSET — always PDO::PARAM_INT

```php
// ❌ PDO binds as string by default
$stmt->bindValue(':limit', $limit);

// ✅ explicit type
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
```

### Private method annotations

```php
/** @param array<string, mixed> $row */
private function hydrateListItem(array $row): ?SomeListItemDTO

/** @param array<string, mixed> $row */
private function hydrateDetail(array $row): ?SomeDTO

/**
 * @param  list<array<string, mixed>>  $rows
 * @return list<SomeDTO>
 */
private function hydrateAll(array $rows): array

/** @return list<string> */
private function fetchRelatedItems(int $parentId): array

/** @return array<string, mixed>|null */
private function findRawById(int $id): ?array
```

---

## 22. CI Workflow Rules

Every new standalone Composer package in the Maatify ecosystem must adhere to the CI workflow standards defined in [`CI_WORKFLOW_STANDARD.md`](CI_WORKFLOW_STANDARD.md).

CI pipelines MUST be:
- **compliant with `CI_WORKFLOW_STANDARD.md`** (which serves as the single detailed source of truth)
- **reliable always-reporting required checks** (using stable gates, not fragile matrix jobs)
- **testing minimum and latest PHP compatibility**
- **testing lowest and latest dependency compatibility**
- **enforcing PHPStan max**
- **enforcing code-style dry-run** (when configured)
- **using real service Integration tests** (where applicable)
- **running Composer security audit**
- **running workflow linting**
- **following least-privilege and immutable action pinning**
- **free of hidden failures** (no silent continue-on-error)
- **free of required secrets or Host dependencies in baseline CI**

---

## 23. The Package Is NOT Done Until

- [ ] All PHPStan max errors resolved — zero errors, no suppressions
- [ ] CI workflows exist and pass
- [ ] CI workflows comply fully with `CI_WORKFLOW_STANDARD.md`
- [ ] Minimum and latest PHP compatibility tested
- [ ] Lowest and latest dependency compatibility tested
- [ ] PHPStan max passes
- [ ] Code-style dry-run passes (if configured)
- [ ] PHPUnit full suite passes (Unit, Regression, Integration where applicable)
- [ ] Example PHP files are syntax-checked where examples exist
- [ ] DB integration tests use real service dependencies where applicable
- [ ] Composer security audit passes
- [ ] Workflow files pass linting
- [ ] Required gates always report a stable status
- [ ] No baseline CI depends on host app code, host schema, framework bindings, or secrets
- [ ] `README.md` written with installation steps and quick examples
- [ ] `CHANGELOG.md` written starting at `[1.0.0]`
- [ ] `{PACKAGE}_PACKAGE_REFERENCE.md` complete — full API, design rules, extension guide
- [ ] `composer.json` complies with [COMPOSER_PACKAGE_STANDARD.md](COMPOSER_PACKAGE_STANDARD.md).
- [ ] Every public service/repository capability intended for infrastructure substitution has a matching contract (interface)
- [ ] Domain-specific failure semantics are documented
- [ ] Transaction catch blocks rethrow the original `\Throwable` after rollback — never swallow
- [ ] Business orchestration lives in Services, validation in Commands/filters, SQL in Repositories
- [ ] Schema docs align with MySQL/domain-owned tables, no generic `logs` or `event_logs` tables
- [ ] Framework-agnostic boundaries preserved: no host app namespaces, no framework bindings required
- [ ] No generic logger, recorder, or repository
- [ ] Docs reflect current exception rules (using `SystemMaatifyException`), and the package explicitly relies on `maatify/exceptions` and `maatify/shared-common` instead of defining local duplicates
