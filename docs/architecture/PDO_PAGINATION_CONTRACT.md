# PDO Pagination Contract

## 1. Status and Authority

**Status:** Proposed documentation-only implementation contract for owner review.

**Implementation authorization:** Not granted by this document alone. Runtime work may begin only after explicit owner approval of the merged contract.

**Target release:** `v1.1.0`, only after Runtime implementation, complete verification, public documentation alignment, and final owner approval.

Once approved and merged, this document becomes the package-specific source of truth for implementing:

```text
Maatify\Persistence\Pdo\Pagination
```

Once approved, it supersedes earlier host-project pagination proposals when they conflict with this contract.

Until implementation is merged, this document MUST NOT be presented as proof that the Runtime capability already exists.

After implementation, the Runtime source and `PERSISTENCE_PACKAGE_REFERENCE.md` MUST match this document exactly. Any proposed divergence requires a new architectural decision before code changes.

## 2. Problem Definition

Maatify host projects currently duplicate PDO offset-pagination behavior across repositories and query readers. The repeated concerns include:

- page normalization
- per-page normalization and limits
- total count
- filtered count
- deterministic sorting
- safe public sort keys
- stable tie-breakers
- `LIMIT` / `OFFSET` calculation
- typed PDO parameter binding
- result metadata
- row mapping

The package will provide one reusable, standalone implementation so host repositories retain ownership of domain SQL while no longer reimplementing pagination mechanics.

## 3. Architectural Placement

Pagination is a new domain alongside Ordering:

```text
Maatify\Persistence\Pdo\Ordering
Maatify\Persistence\Pdo\Pagination
```

It MUST NOT be added to:

```text
ScopedOrderingConfig
ScopedOrderingManager
```

No existing Ordering constructor, method, exception, transaction rule, SQL behavior, or public return contract may be changed by this feature.

The Pagination domain is an additive Minor-version capability.

## 4. Package Boundaries

The component MUST remain:

- standalone
- framework-agnostic
- host-agnostic
- PDO-based
- MySQL-verified
- independent of Slim
- independent of PSR-7
- independent of HTTP request/response objects
- independent of container bindings
- independent of host tables and host namespaces

The component is not:

- a Query Builder
- an ORM
- a search builder
- a filter builder
- a repository abstraction
- an authorization layer
- a response emitter
- request middleware

The host supplies the PDO connection, trusted SQL, mandatory scopes, optional filters, and row mapper.

## 5. Version-1 Runtime Inventory

The approved Runtime files are:

```text
src/Pdo/Pagination/PageRequest.php
src/Pdo/Pagination/PaginationConfig.php
src/Pdo/Pagination/SortDirection.php
src/Pdo/Pagination/SortWhitelist.php
src/Pdo/Pagination/PdoPaginationQuery.php
src/Pdo/Pagination/PdoPaginator.php
src/Pdo/Pagination/PageResult.php

src/Exception/InvalidPaginationConfigurationException.php
src/Exception/InvalidPaginationQueryException.php
src/Exception/PaginationExecutionException.php
```

Version 1 MUST NOT add:

```text
PaginationException
PaginatorInterface
RowMapperInterface
FilterWhitelist
SearchBuilder
```

The existing package marker remains:

```text
Maatify\Persistence\Exception\PersistenceException
```

All new package-defined Pagination exceptions implement that marker.

## 6. Exact Public API

The following signatures are the approved implementation contract.

### 6.1 `PageRequest`

```php
namespace Maatify\Persistence\Pdo\Pagination;

final readonly class PageRequest
{
    public function __construct(
        public int|string|null $page = null,
        public int|string|null $perPage = null,
        public ?string $sortBy = null,
        public ?string $sortDirection = null,
    ) {
    }
}
```

Contract:

- Represents caller-supplied values before final normalization.
- Contains no HTTP or PSR-7 knowledge.
- Does not normalize or execute queries.
- `sortDirection` is raw input; the final applied direction is represented by `SortDirection`.

### 6.2 `SortDirection`

```php
namespace Maatify\Persistence\Pdo\Pagination;

enum SortDirection: string
{
    case ASC = 'ASC';
    case DESC = 'DESC';
}
```

No additional public methods are approved in version 1.

### 6.3 `SortWhitelist`

Class declaration:

```php
final readonly class SortWhitelist
```

Public signatures:

```php
/**
 * @param non-empty-array<non-empty-string, non-empty-string> $sorts
 */
public function __construct(array $sorts);

public function contains(string $key): bool;

/**
 * @return non-empty-string
 */
public function quotedIdentifierFor(string $key): string;
```

Contract:

- Constructor keys are public sort keys.
- Constructor values are trusted identifier paths.
- `contains()` is an exact, case-sensitive public-key lookup.
- `quotedIdentifierFor()` returns a validated, internally quoted identifier.
- Resolving a missing key is an invalid configuration operation and throws `InvalidPaginationConfigurationException`.
- The internal whitelist map is immutable and is not exposed through a public getter.

### 6.4 `PaginationConfig`

```php
namespace Maatify\Persistence\Pdo\Pagination;

final readonly class PaginationConfig
{
    public function __construct(
        public SortWhitelist $sortWhitelist,
        public string $defaultSortBy,
        public SortDirection $defaultSortDirection,
        public string $tieBreakerSortBy,
        public SortDirection $tieBreakerDirection,
        public int $defaultPerPage = 20,
        public int $minPerPage = 1,
        public int $maxPerPage = 200,
    ) {
    }
}
```

Constructor validation:

- `minPerPage >= 1`
- `maxPerPage >= minPerPage`
- `defaultPerPage` is inside `[minPerPage, maxPerPage]`
- `defaultSortBy` is a valid public key and exists in `sortWhitelist`
- `tieBreakerSortBy` is a valid public key and exists in `sortWhitelist`

`defaultSortBy` and `tieBreakerSortBy` MAY resolve to the same identifier.

The tie-breaker uniqueness guarantee defined in section 8.4 is caller-owned. `PaginationConfig` validates public keys and whitelist membership, but it cannot prove database uniqueness from configuration alone.

The values `20`, `1`, and `200` are the canonical constructor defaults. Callers MAY configure different valid per-page values through `PaginationConfig`; no separate architectural exception is required when the constructor invariants remain satisfied.

### 6.5 `PdoPaginationQuery`

```php
namespace Maatify\Persistence\Pdo\Pagination;

final readonly class PdoPaginationQuery
{
    /**
     * @param array<string, string|int|bool|null> $totalParams
     * @param array<string, string|int|bool|null> $filteredParams
     * @param array<string, string|int|bool|null> $dataParams
     */
    public function __construct(
        public string $totalSql,
        public array $totalParams,
        public string $filteredCountSql,
        public array $filteredParams,
        public string $dataSql,
        public array $dataParams,
    ) {
    }
}
```

All three SQL strings and all three parameter maps are required, including empty parameter maps.

### 6.6 `PageResult`

Class declaration:

```php
/**
 * @template T of array|object
 */
final readonly class PageResult implements \JsonSerializable
```

Constructor:

```php
/**
 * @param list<T> $data
 */
public function __construct(
    public array $data,
    public int $page,
    public int $perPage,
    public int $total,
    public int $filtered,
    public int $totalPages,
    public bool $hasNext,
    public bool $hasPrevious,
    public string $sortBy,
    public SortDirection $sortDirection,
);
```

Constructor invariants:

- `data` MUST satisfy `array_is_list($data)`
- every item in `data` MUST be an array or object
- `count($data) <= $perPage`
- `page >= 1`
- `perPage >= 1`
- `total >= 0`
- `filtered >= 0`
- `totalPages >= 0`
- `totalPages` MUST equal the canonical value calculated from `filtered` and `perPage`:
  `0` when `filtered === 0`, otherwise `intdiv($filtered - 1, $perPage) + 1`
- when `filtered === 0`, `data === []`
- when `totalPages === 0`, `page === 1` and both navigation flags are `false`
- when `totalPages > 0`, `page <= totalPages`
- `hasNext === ($page < $totalPages)`
- `hasPrevious === ($page > 1 && $totalPages > 0)`
- `sortBy` MUST match `^[A-Za-z_][A-Za-z0-9_]*$`

An empty `data` list while `filtered > 0` is valid because concurrent writes may change the dataset between the count and data statements.

An inconsistent result state is a package-owned execution failure and MUST throw `PaginationExecutionException`.

Public serialization signatures:

```php
/**
 * @return array{
 *     data: list<T>,
 *     pagination: array{
 *         page: int,
 *         per_page: int,
 *         total: int,
 *         filtered: int,
 *         total_pages: int,
 *         has_next: bool,
 *         has_previous: bool,
 *         sort_by: string,
 *         sort_direction: 'ASC'|'DESC'
 *     }
 * }
 */
public function toArray(): array;

/**
 * @return array{
 *     data: list<T>,
 *     pagination: array{
 *         page: int,
 *         per_page: int,
 *         total: int,
 *         filtered: int,
 *         total_pages: int,
 *         has_next: bool,
 *         has_previous: bool,
 *         sort_by: string,
 *         sort_direction: 'ASC'|'DESC'
 *     }
 * }
 */
public function jsonSerialize(): array;
```

### 6.7 `PdoPaginator`

Class declaration:

```php
final readonly class PdoPaginator
```

Public signature:

```php
/**
 * @template T of array|object
 *
 * @param callable(array<string, mixed>): T $mapper
 *
 * @return PageResult<T>
 */
public function paginate(
    \PDO $pdo,
    PdoPaginationQuery $query,
    PageRequest $request,
    PaginationConfig $config,
    callable $mapper,
): PageResult;
```

`PdoPaginator` is stateless and has no explicitly declared constructor.

## 7. Page and Per-Page Normalization

### 7.1 Accepted Numeric Form

`page` and `perPage` accept:

- an integer
- a string which, after trimming, contains an optional leading sign followed only by decimal digits
- `null`

Examples accepted as numeric input:

```text
2
"2"
" 2 "
"+2"
"02"
"-2"
```

Examples rejected as malformed numeric input:

```text
""
" "
"2.0"
"2e3"
"1_000"
"1,000"
"abc"
```

A syntactically numeric string whose magnitude cannot be represented as a PHP integer is unrepresentable.

### 7.2 Page Rules

- missing, malformed, or unrepresentable => `1`
- parsed value below `1` => `1`
- otherwise => parsed integer
- after filtered count, page greater than positive `totalPages` => `1`

Page input failures do not throw.

### 7.3 Per-Page Rules

- missing, malformed, or unrepresentable => `defaultPerPage`
- parsed value below `minPerPage` => `minPerPage`
- parsed value above `maxPerPage` => `maxPerPage`
- otherwise => parsed integer

Per-page input failures do not throw.

## 8. Sort Input and Whitelist Contract

### 8.1 Public Sort Keys

Public keys MUST match:

```text
^[A-Za-z_][A-Za-z0-9_]*$
```

Lookup is case-sensitive.

`sortBy` is trimmed before lookup:

- missing, empty, malformed, or absent from whitelist => `defaultSortBy`
- valid and present => requested key

The response returns the effective public key.

### 8.2 Sort Direction Input

`sortDirection` is trimmed and compared case-insensitively:

- `asc` / `ASC` => `SortDirection::ASC`
- `desc` / `DESC` => `SortDirection::DESC`
- missing, empty, or invalid => `defaultSortDirection`

The response always emits uppercase enum values.

### 8.3 Identifier Paths

Whitelist values MUST contain one to three identifier segments:

```text
column
alias.column
schema.table.column
```

Each segment MUST match:

```text
^[A-Za-z_][A-Za-z0-9_]*$
```

The whitelist quotes each segment with backticks:

```text
created_at                  => `created_at`
v.created_at                => `v`.`created_at`
catalog.products.created_at => `catalog`.`products`.`created_at`
```

Version 1 forbids arbitrary ordering expressions, including:

- functions
- parentheses
- arithmetic
- JSON operators
- `CASE`
- `COLLATE`
- commas
- directions
- comments
- semicolons
- `LIMIT`
- `OFFSET`

### 8.4 Tie-Breaker Rules

One internal tie-breaker is mandatory for deterministic pagination.

The resolved tie-breaker identifier MUST produce a unique final ordering within every filtered dataset to which the configuration is applied. A primary key such as `id` is the usual choice.

The caller owns this uniqueness guarantee. The component validates and quotes the identifier but does not inspect schema constraints or prove uniqueness.

If the effective primary and tie-breaker public keys resolve to different quoted identifiers:

```sql
ORDER BY {primary} {effective_direction}, {tie_breaker} {configured_direction}
```

If they resolve to the same quoted identifier, including through two different public keys:

```sql
ORDER BY {primary} {effective_direction}
```

In the duplicate-identifier case, that single resolved identifier MUST itself be unique within the filtered dataset. The effective primary direction wins; the tie-breaker direction is not emitted.

Using a non-unique final tie-breaker is a trusted configuration defect even though version 1 cannot detect it at Runtime.

## 9. SQL Descriptor Contract

### 9.1 `totalSql`

Counts the base visible dataset after all mandatory constraints and before optional search/filter.

Mandatory constraints include, where applicable:

- tenant/module scope
- authorization or ownership scope
- required visibility policy
- soft-delete exclusion
- required system state

It MUST return one non-negative integer scalar.

### 9.2 `filteredCountSql`

Counts the same base visible dataset after applying optional search/filter.

It MUST be semantically aligned with `dataSql` and return one non-negative integer scalar.

### 9.3 `dataSql`

Returns the same filtered dataset represented by `filteredCountSql`.

It excludes:

- ordering supplied by the paginator
- `LIMIT`
- `OFFSET`

### 9.4 Runtime-Checkable Rules

The descriptor constructor MUST reject:

- SQL empty after trimming
- SQL ending in a semicolon after right trimming
- invalid parameter keys
- parameter keys beginning with `:`
- parameter keys beginning with the reserved `__pagination_` prefix
- any SQL string containing a named placeholder beginning with `:__pagination_`
- unsupported parameter value types

The complete `__pagination_` namespace is reserved for package-owned bindings. Detecting that prefix in caller parameter keys or SQL placeholder names is a narrow collision check, not general SQL parsing.

The descriptor constructor does not preflight general placeholder correspondence, repeated placeholder usage, positional placeholder usage, or mixed placeholder styles.

The component does not provide a SQL parser. Top-level `ORDER BY`, `LIMIT`, `OFFSET`, locking clauses, multi-statement content, SELECT compatibility, placeholder correspondence, and semantic alignment remain explicit caller contracts except where PDO itself reports failure.

The documentation and tests MUST NOT claim that Regex checks prove complete SQL grammar safety.

## 10. Parameter Binding Contract

Version 1 supports named placeholders only. Positional `?` placeholders and statements that mix positional and named placeholders are unsupported.

Parameter keys MUST match:

```text
^[A-Za-z_][A-Za-z0-9_]*$
```

They MUST be stored without a leading colon.

Reserved namespace:

```text
__pagination_*
```

Caller parameter keys beginning with `__pagination_` are invalid. Caller SQL placeholder names beginning with `:__pagination_` are also invalid.

The package currently uses:

```text
__pagination_limit
__pagination_offset
```

Supported values and binding:

| PHP value | PDO binding |
|---|---|
| `int` | `PDO::PARAM_INT` |
| `bool` | `PDO::PARAM_BOOL` |
| `null` | `PDO::PARAM_NULL` |
| `string` | `PDO::PARAM_STR` |

Unsupported:

- float
- array
- object
- resource

Decimals are validated strings owned by the caller.

Each SQL statement is executed only with its matching parameter map. One shared map MUST NOT be bound blindly to all statements.

Within one SQL statement:

- every named placeholder MUST have one unique occurrence
- every named placeholder MUST have one matching parameter-map entry
- every parameter-map key MUST be used by one matching named placeholder
- reusing the same logical value requires distinct placeholder names and matching parameter entries

These are caller contracts so behavior remains valid with native prepared statements. The component does not parse SQL to prove them, and `PdoPaginationQuery` MUST NOT claim general placeholder preflight validation. Violations may surface as an unchanged `PDOException` or as a package-classified non-throwing PDO failure state, depending on the connection configuration.

The paginator MUST NOT change PDO connection attributes.

## 11. Count Validation and Metadata

Each count statement MUST return exactly one row containing exactly one column. The paginator MUST verify both cardinalities before accepting the value.

The single count value is then validated using the following representation contract.

Accepted count representation:

- PHP integer `>= 0`
- decimal-digit string representing an integer `>= 0` within `PHP_INT_MAX`

Rejected:

- `false`
- `null`
- empty string
- signed string
- decimal/floating representation
- exponent notation
- negative value
- value larger than `PHP_INT_MAX`
- any other type
- zero rows or more than one row
- a row containing zero columns or more than one column

`totalPages` calculation:

```php
$totalPages = $filtered === 0
    ? 0
    : intdiv($filtered - 1, $perPage) + 1;
```

After zero-result and overflow normalization, and only when `filtered > 0`, offset calculation is exactly:

```php
$offset = ($page - 1) * $perPage;
```

The multiplication is integer-safe under this contract because the effective page is never greater than `totalPages`, and the resulting page start cannot exceed `filtered - 1`.

The paginator MUST NOT enforce `filtered <= total` at Runtime. Concurrent writes between independent count statements can temporarily violate that relationship unless the caller supplies an appropriate consistent transaction.

Metadata:

```text
hasNext     = page < totalPages
hasPrevious = page > 1 && totalPages > 0
```

## 12. Empty and Overflow Behavior

### `filtered === 0`

The paginator:

1. sets effective page to `1`
2. sets `totalPages` to `0`
3. does not prepare or execute `dataSql`
4. returns `data = []`
5. returns both navigation flags as `false`

### Requested Page Overflow

When `filtered > 0` and normalized page is greater than `totalPages`:

1. effective page becomes `1`
2. offset becomes `0`
3. the data query executes once for the first page

No hidden second data-query retry is allowed.

## 13. Execution Flow

The exact operation order is:

1. receive PDO, descriptor, request, config, and mapper
2. normalize page and per-page
3. resolve effective primary sort key and direction
4. execute `totalSql` with `totalParams`
5. validate total count
6. execute `filteredCountSql` with `filteredParams`
7. validate filtered count
8. calculate `totalPages`
9. apply zero-result and page-overflow policy
10. if filtered is zero, return without data execution
11. resolve and compose quoted primary sort and tie-breaker
12. calculate integer-safe offset
13. append `ORDER BY`, `LIMIT`, and `OFFSET`
14. prepare the final data statement
15. bind `dataParams`
16. bind internal limit and offset as integers
17. execute once
18. fetch associative rows
19. map and validate every item
20. return `PageResult`

## 14. Final SQL Assembly

With distinct primary and tie-breaker identifiers:

```sql
{dataSql}
ORDER BY {quoted_primary_identifier} {ASC|DESC},
         {quoted_tie_breaker_identifier} {ASC|DESC}
LIMIT :__pagination_limit
OFFSET :__pagination_offset
```

With a duplicate resolved identifier:

```sql
{dataSql}
ORDER BY {quoted_primary_identifier} {ASC|DESC}
LIMIT :__pagination_limit
OFFSET :__pagination_offset
```

Only trusted whitelist output and enum directions enter `ORDER BY`.

## 15. Mapper Contract

Mapper PHPDoc:

```php
callable(array<string, mixed> $row): array|object
```

The paginator explicitly fetches each row using `PDO::FETCH_ASSOC`.

The mapper MAY return:

- an array
- a DTO or other object

The paginator appends mapped items to preserve `list<T>` semantics.

If the mapper returns any scalar or resource, throw `PaginationExecutionException`.

If the mapper itself throws any `Throwable`, rethrow the same instance unchanged.

Raw rows require an explicit identity mapper:

```php
static fn (array $row): array => $row
```

## 16. Result Shape

`PageResult::toArray()` and `jsonSerialize()` return:

```php
[
    'data' => [],
    'pagination' => [
        'page' => 1,
        'per_page' => 20,
        'total' => 150,
        'filtered' => 37,
        'total_pages' => 2,
        'has_next' => true,
        'has_previous' => false,
        'sort_by' => 'created_at',
        'sort_direction' => 'DESC',
    ],
]
```

Rules:

- `data` is always a list
- no `offset` field
- no raw invalid request values
- no SQL identifiers or expressions exposed
- `sortBy` is the effective public key
- `sortDirection` is the effective primary enum direction
- output field names are fixed

## 17. Transaction and Consistency Contract

The paginator does not own a transaction.

It MUST work:

- without an active transaction
- inside a caller-owned active transaction

It MUST NOT:

- start a transaction
- commit a transaction
- roll back a transaction
- reject an active transaction

The paginator guarantee is limited to never explicitly calling `beginTransaction()`, `commit()`, or `rollBack()`. External database/driver behavior and mapper-owned code may affect transaction state and remain outside the paginator guarantee. Documentation and tests MUST NOT claim that every possible external failure preserves transaction state.

Without a caller-owned consistent transaction, the three statements are independent reads. Concurrent changes may produce:

- counts that differ from the number of returned rows
- a page becoming empty after count
- `filtered > total`

The paginator does not retry or silently change metadata to hide concurrent changes.

## 18. Exception Contract

All package-defined exceptions implement:

```text
Maatify\Persistence\Exception\PersistenceException
```

### 18.1 `InvalidPaginationConfigurationException`

```text
Base: Maatify\Exceptions\Exception\System\SystemMaatifyException
Error code: ErrorCodeEnum::MAATIFY_ERROR
Safety behavior: inherited `SystemMaatifyException` default
```

Triggered by:

- invalid per-page bounds
- default outside bounds
- empty whitelist
- invalid public key
- invalid identifier path
- missing default sort key
- missing tie-breaker sort key
- explicit lookup of an unknown whitelist key

Tie-breaker uniqueness remains a caller-owned semantic configuration guarantee. Version 1 does not promise Runtime detection or an exception for a non-unique database value set.

### 18.2 `InvalidPaginationQueryException`

```text
Base: Maatify\Exceptions\Exception\System\SystemMaatifyException
Error code: ErrorCodeEnum::MAATIFY_ERROR
Safety behavior: inherited `SystemMaatifyException` default
```

Triggered by:

- missing/empty SQL
- trailing semicolon
- invalid parameter key
- leading-colon key
- reserved `__pagination_` parameter-key or SQL-placeholder prefix collision
- unsupported parameter value type

It classifies Runtime-checkable invalid trusted caller descriptors, not end-user validation.

General placeholder correspondence, repetition, positional-placeholder, and mixed-placeholder violations are caller contracts rather than guaranteed constructor classifications. They may surface through PDO during execution.

### 18.3 `PaginationExecutionException`

```text
Base: Maatify\Exceptions\Exception\System\SystemMaatifyException
Error code: ErrorCodeEnum::MAATIFY_ERROR
Safety behavior: inherited `SystemMaatifyException` default
```

Triggered by package-owned execution classifications such as:

- `PDO::prepare()` returns `false`
- `PDOStatement::bindValue()` returns `false`
- `PDOStatement::execute()` returns `false`
- invalid count result
- invalid mapper result type
- unexpected non-associative fetched row state
- inconsistent `PageResult` state

### 18.4 Propagation Rules

- Actual `PDOException` propagates unchanged.
- Unknown external `Throwable` propagates unchanged.
- Mapper-thrown `Throwable` propagates unchanged.
- No blind catch-all wrapping.
- No swallowed error.
- No rollback attempt by the paginator.

Version 1 does not add a Pagination-specific marker interface.

## 19. Security and Trust Boundaries

Trusted application configuration:

- SQL statements
- SQL placeholder names
- public-key-to-identifier whitelist
- mandatory scopes embedded in caller SQL
- mapper

Untrusted input:

- page
- per-page
- sort key
- sort direction
- filter values passed through caller validation/binding

The paginator guarantees:

- raw sort input is never SQL
- directions come only from `SortDirection`
- identifier paths are validated and quoted
- runtime values are bound
- internal pagination names cannot collide with caller parameter maps or caller SQL placeholder names

The paginator cannot guarantee:

- authorization correctness of caller SQL
- mandatory-scope alignment across three statements
- correctness of filters or JOINs
- SQL semantic equivalence
- uniqueness of the caller-selected tie-breaker
- general placeholder correspondence without parsing SQL
- index quality or query performance
- consistent snapshot without caller transaction

Omitting a mandatory security or tenant constraint from any descriptor statement is a caller security defect.

## 20. Backward Compatibility and Migration

Package-level effect:

- additive classes only
- no Ordering API changes
- no existing Runtime behavior changes
- no new framework dependency
- no new Runtime package dependency
- no `composer.lock`

Host migration rules:

1. implement and verify Pagination in `maatify/persistence`
2. publish only after full package review
3. migrate one low-risk host repository
4. preserve its endpoint contract or use a host adapter
5. review counts, sorting, overflow, and response compatibility
6. continue repository-by-repository
7. no mass migration without separate approval

The canonical fields are additive conceptually, but an endpoint response is not allowed to change silently merely because the package offers more metadata.

## 21. Required Tests Before Approval

### Unit

- all page and per-page normalization inputs
- PHP integer boundary and overflow strings
- sort-direction normalization
- valid/invalid public keys
- valid one/two/three-segment identifiers
- forbidden expressions
- identifier quoting
- config invariants
- descriptor SQL validation
- parameter key/type validation
- reserved `__pagination_` prefix collisions in parameter maps and SQL placeholder names
- package-owned non-throwing `prepare()`, `bindValue()`, and `execute()` failure classifications
- `PageResult` serialization
- `PageResult` invariant rejection for non-list data, scalar items, oversized pages, zero-filtered non-empty data, invalid sort keys, inconsistent total pages, and inconsistent navigation flags
- acceptance of empty data while `filtered > 0`

### Regression

- exact class names and namespaces
- final/readonly modifiers
- enum cases
- constructor parameter names, order, types, and defaults
- `paginate()` parameter and return types
- result field names and nesting
- no offset field
- exception bases, marker, and error codes
- named-placeholder-only caller contract without a claimed general SQL parser
- caller-owned tie-breaker uniqueness guarantee
- Ordering public API unchanged

### Real MySQL Integration

- total and filtered semantics
- no-filter identical count statements
- count results with exactly one row and one column
- rejection of zero-row, multi-row, and multi-column count results
- separate parameter maps
- int/string/bool/null binding
- internal integer limit/offset binding
- native prepared statements
- repeated, missing, unused, positional, and mixed placeholder violations surfacing through the documented PDO failure contract rather than constructor SQL parsing
- default and requested sorting
- invalid-sort fallback
- duplicate primary values with a unique tie-breaker proving stable ordering
- duplicate resolved primary/tie-breaker emitted once only when the resolved identifier is caller-guaranteed unique
- zero filtered rows skip data execution
- page overflow returns first page
- mapper arrays and objects
- invalid mapper result
- PDO exception propagation
- successful operation inside caller transaction
- caller transaction remains active after successful pagination
- no claim that every external PDO/driver failure preserves transaction state
- no transaction created outside caller transaction
- repeated execution and fixture cleanup

SQLite is forbidden as a substitute for MySQL Integration proof.

## 22. Explicit Non-Goals for Version 1

- cursor pagination
- keyset pagination
- multiple user sorts
- comma-separated sorts
- arbitrary SQL sort expressions
- FilterWhitelist
- SearchBuilder
- QueryBuilder
- ORM integration
- automatic JOINs
- automatic count optimization
- approximate counts
- caching
- HTTP query parsing
- HTTP response generation
- Slim middleware
- PSR-7 dependency
- framework service providers
- automatic SQL parser
- automatic transaction ownership
- automatic retry after concurrent changes
- host repository migration inside the package implementation PR

## 23. Implementation Gate

Codex implementation is authorized only after this contract and the matching Pagination section of `PACKAGE_BUILDING_STANDARD.md` are reviewed and explicitly approved.

Implementation delivery MUST:

- match the exact Public API in section 6
- add complete tests
- keep all Ordering files behaviorally unchanged
- avoid unrelated refactors
- avoid documentation claims outside implemented behavior
- create no `composer.lock`

Merge, tag creation, GitHub Release publication, and Packagist publication remain separate owner-controlled actions.
