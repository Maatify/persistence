# Test Blueprint — PDO Pagination `v1.1.0`

**الحالة:** Owner-approved Test Blueprint; test implementation is completed and merged.
**مصدر الحقيقة:**

```text
docs/architecture/PDO_PAGINATION_CONTRACT.md
docs/architecture/PDO_PAGINATION_RUNTIME_BLUEPRINT.md
```

**Runtime baseline:** تم دمج PDO Pagination Runtime على `main`.
**النطاق:** Unit، Regression، Real MySQL Integration، ودعم الاختبارات والـ CI residue verification فقط.

---

## 1. الهدف

إثبات أن PDO Pagination Runtime:

* يطابق الـ Public API المعتمد حرفيًا.
* يطبق normalization والسorting والcount والmapping والmetadata كما هو موثق.
* يصنف package-owned PDO failures بصورة صحيحة.
* يمرر `PDOException` والـ mapper `Throwable` دون تغليف.
* لا يمتلك transaction.
* لا يغيّر PDO attributes.
* يعمل مع native MySQL prepared statements.
* لا يغيّر Ordering Runtime أو Public API.
* لا يضيف SQL parser أو Query Builder أو أي abstraction غير معتمد.

نجاح الاختبارات الحالية الخاصة بـ Ordering لا يعتبر تغطية لـ Pagination.

---

# 2. الملفات المسموح بإنشائها

## 2.1 Unit Support Doubles

```text
tests/Support/Pdo/Pagination/ScriptedPdo.php
tests/Support/Pdo/Pagination/ScriptedPdoStatement.php
```

## 2.2 Unit Tests

```text
tests/Unit/Pdo/Pagination/PageRequestTest.php
tests/Unit/Pdo/Pagination/SortWhitelistTest.php
tests/Unit/Pdo/Pagination/PaginationConfigTest.php
tests/Unit/Pdo/Pagination/PdoPaginationQueryDescriptorTest.php
tests/Unit/Pdo/Pagination/PageResultTest.php
tests/Unit/Pdo/Pagination/PdoPaginatorNormalizationTest.php
tests/Unit/Pdo/Pagination/PdoPaginatorExecutionTest.php
tests/Unit/Pdo/Pagination/PdoPaginatorFailureTest.php
```

## 2.3 Regression Tests

```text
tests/Regression/Pdo/Pagination/PaginationPublicApiRegressionTest.php
tests/Regression/Pdo/Pagination/PaginationSqlContractRegressionTest.php
tests/Regression/Exception/PaginationExceptionContractTest.php
tests/Regression/Pdo/Ordering/OrderingPublicApiRegressionTest.php
```

## 2.4 MySQL Fixtures and Support

```text
tests/Fixtures/MySql/create_pagination_items_table.sql
tests/Support/MySql/PaginationSchemaManager.php
tests/Support/MySql/PaginationFixture.php
tests/Support/MySql/PaginationIntegrationTestCase.php
```

## 2.5 Real MySQL Integration Tests

```text
tests/Integration/Pdo/Pagination/PdoPaginatorQuerySemanticsTest.php
tests/Integration/Pdo/Pagination/PdoPaginatorFailureContractTest.php
tests/Integration/Pdo/Pagination/PdoPaginatorTransactionTest.php
```

---

# 3. الملف المسموح بتعديله

```text
.github/workflows/ci.yml
```

التعديل الوحيد المسموح:

* إضافة جدول Pagination التجريبي إلى فحص MySQL residue.
* تعديل عدد placeholders والـ execution parameters المرتبطة بقائمة الجداول وفقًا لذلك.

اسم الجدول الثابت:

```text
maa_persistence_test_pagination_items
```

ممنوع أي تغيير آخر في CI architecture أو versions أو jobs أو matrices.

لا يحتاج `phpunit.xml` إلى تعديل؛ المسارات الحالية تلتقط الملفات الجديدة تلقائيًا.

---

# 4. الملفات الممنوع تعديلها

```text
src/**
composer.json
phpunit.xml
phpstan.neon
.php-cs-fixer.php
README.md
CHANGELOG.md
PERSISTENCE_PACKAGE_REFERENCE.md
docs/**
tests/Unit/Pdo/Ordering/**
tests/Integration/Pdo/Ordering/**
```

الاستثناء الوحيد داخل `docs/**` هو هذا الـ Blueprint نفسه قبل بدء تنفيذ الاختبارات.

ممنوع إنشاء:

```text
composer.lock
```

---

# 5. القواعد العامة للاختبارات

كل ملف PHP:

```php
<?php

declare(strict_types=1);
```

القواعد:

* كل test class تكون `final`.
* استخدام PHPUnit 11 APIs الحالية.
* استخدام `DataProvider` للحالات الجدولية.
* لا suppressions لـ PHPStan.
* لا `@phpstan-ignore`.
* لا baseline أو `ignoreErrors`.
* لا sleeps.
* لا randomness.
* لا اعتماد على ترتيب اختبارات PHPUnit.
* لا SQLite بدل MySQL.
* لا تعديل Runtime لإتاحة الاختبار.
* لا اختبار private methods مباشرة.
* الاستثناء الوحيد لاستخدام Reflection:

    * تثبيت Public API.
    * تمرير قيم Runtime غير صحيحة تخالف PHPDoc لكنها مقبولة بواسطة native PHP type، مثل scalar item داخل `PageResult` أو invalid parameter maps.
* لا تثبيت exception messages باعتبارها Public API.
* تثبيت exception classes والـ bases والـ markers والـ error codes فقط.
* عند اختبار propagation، يجب استخدام `assertSame()` لإثبات خروج نفس Throwable instance.
* الاختبارات يجب أن تعمل على PHP 8.2 حتى PHP 8.5.
* كل resources تُغلق أو تزال داخل `finally`.

---

# 6. Unit Support Doubles

## 6.1 `ScriptedPdo`

يمتد من:

```php
PDO
```

ولا يتصل بقاعدة بيانات.

مسؤوليته:

* استقبال queue مرتبة من:

    * `ScriptedPdoStatement`
    * أو نتيجة `false` لمحاكاة non-throwing prepare failure.
    * أو Throwable يجب رميه من `prepare()`.
* تسجيل كل SQL تم تمريره إلى `prepare()` بالترتيب.
* إتاحة عدد مرات `prepare()`.
* تسجيل أي استدعاء لـ:

    * `beginTransaction()`
    * `commit()`
    * `rollBack()`
    * `setAttribute()`
* يجب ألا يستدعي constructor الأصلي الذي يحتاج DSN.
* لا يحتوي سلوك Pagination نفسه.

يجب أن تسمح الاختبارات بإثبات:

* ترتيب prepare:

    1. total
    2. filtered count
    3. data
* عدم تجهيز data query عند `filtered === 0`.
* تنفيذ data query مرة واحدة فقط عند page overflow.
* عدم استدعاء أي transaction operation.
* عدم تغيير PDO attributes.

## 6.2 `ScriptedPdoStatement`

يمتد من:

```php
PDOStatement
```

ويدعم configuration للاختبارات فقط:

* `columnCount()`.
* queue لنتائج `fetch(PDO::FETCH_ASSOC)`.
* queue أو قيمة ثابتة لـ `errorCode()`.
* نتيجة `execute()`:

    * `true`
    * `false`
    * Throwable.
* نتائج `bindValue()` حسب اسم parameter:

    * `true`
    * `false`
    * Throwable.
* تسجيل bind calls بالترتيب:

```text
parameter name
value
PDO parameter type
```

* تسجيل عدد مرات execute وfetch.
* إرجاع قيم غير array عمدًا لاختبار unexpected fetch state.
* إرجاع rows ذات numeric keys عمدًا لاختبار associative-row validation.

ممنوع استخدام PHPUnit mocks بدل هذه doubles للحالات الحرجة الخاصة بـ PDO؛ الهدف أن تكون السلوكيات واضحة وثابتة بين PHP 8.2–8.5.

---

# 7. `PageRequestTest`

يغطي أن `PageRequest` value object خام ولا يقوم بأي normalization.

الحالات:

* defaults كلها `null`.
* الاحتفاظ بقيمة page integer كما هي.
* الاحتفاظ بقيمة page malformed string كما هي.
* الاحتفاظ بقيمة per-page malformed string كما هي.
* الاحتفاظ بـ whitespace في sort values كما هو.
* لا يرمي بسبب end-user input غير الصحيح.
* لا يملك methods عامة إضافية غير constructor.

لا يختبر HTTP أو query parameters أو PSR-7.

---

# 8. `SortWhitelistTest`

## 8.1 الحالات الصحيحة

اختبار:

```text
column
alias.column
schema.table.column
```

والـ quoting المتوقع:

```text
created_at                  => `created_at`
p.created_at                => `p`.`created_at`
catalog.products.created_at => `catalog`.`products`.`created_at`
```

اختبار public keys:

```text
created_at
_created_at
created_at2
```

اختبار:

* `contains()` exact وcase-sensitive.
* unknown casing لا يطابق.
* مفتاحان عامان مختلفان يمكن أن يشيرا إلى identifier واحد.
* تعديل input array بعد إنشاء الكائن لا يغيّر حالته الداخلية.
* لا يوجد public getter للخريطة الداخلية.

## 8.2 الحالات المرفوضة

* empty whitelist.
* integer key.
* empty public key.
* public key يبدأ برقم.
* whitespace.
* hyphen.
* dot داخل public key.
* backticks.
* comments.
* semicolon.
* value غير string.
* zero segments الفعلية عبر empty string.
* leading dot.
* trailing dot.
* empty middle segment.
* أكثر من ثلاثة segments.
* function.
* parentheses.
* arithmetic.
* JSON operator.
* `CASE`.
* `COLLATE`.
* comma.
* embedded direction.
* comment.
* semicolon.
* `LIMIT`.
* `OFFSET`.

كل الحالات ترمي:

```text
InvalidPaginationConfigurationException
```

`quotedIdentifierFor()` مع unknown key يرمي الاستثناء نفسه.

---

# 9. `PaginationConfigTest`

## 9.1 valid configuration

إثبات constructor defaults:

```text
defaultPerPage = 20
minPerPage     = 1
maxPerPage     = 200
```

اختبار:

* custom valid bounds.
* default key موجود في whitelist.
* tie-breaker key موجود.
* default وtie-breaker نفس المفتاح.
* مفتاحان مختلفان يشيران إلى نفس identifier.
* اتجاه primary مستقل عن اتجاه tie-breaker.

## 9.2 invalid configuration

* `minPerPage = 0`.
* `minPerPage < 0`.
* `maxPerPage < minPerPage`.
* default أقل من min.
* default أكبر من max.
* malformed default key.
* malformed tie-breaker key.
* default key غير موجود في whitelist.
* tie-breaker key غير موجود.
* empty key.

كلها ترمي:

```text
InvalidPaginationConfigurationException
```

لا يوجد test يحاول إثبات uniqueness لقيم tie-breaker داخل قاعدة البيانات؛ هذه مسؤولية caller.

---

# 10. `PdoPaginationQueryDescriptorTest`

## 10.1 valid descriptor

إثبات:

* كل SQL property تحفظ النص الأصلي دون trim أو rewriting.
* كل parameter map تحفظ خريطتها المنفصلة.
* empty parameter maps مسموحة.
* القيم المسموحة:

```text
int
string
bool
null
```

* decimal string مسموح.
* named placeholder بدون leading colon في map.
* constructor لا ينفذ SQL.

## 10.2 SQL rejection

يُختبر كل SQL slot منفصلًا:

```text
totalSql
filteredCountSql
dataSql
```

الحالات:

* empty string.
* whitespace-only.
* semicolon في البداية.
* semicolon في المنتصف.
* semicolon في النهاية.
* reserved placeholder:

```text
:__pagination_limit
:__pagination_offset
:__pagination_custom
```

كلها ترمي:

```text
InvalidPaginationQueryException
```

## 10.3 parameter rejection

يُختبر في الخرائط الثلاث:

* integer key.
* empty key.
* leading colon.
* starts with digit.
* whitespace.
* hyphen.
* reserved prefix:

```text
__pagination_limit
__pagination_offset
__pagination_anything
```

القيم المرفوضة:

```text
float
array
object
resource
```

مع إغلاق resource داخل `finally`.

## 10.4 no-parser contract

يجب إثبات أن constructor لا يدّعي preflight عام، ولذلك ينجح construction مع descriptors تحتوي:

* missing placeholder correspondence.
* unused parameter.
* repeated named placeholder.
* positional `?`.
* mixed positional and named placeholders.
* caller-owned `ORDER BY`.
* caller-owned `LIMIT`.
* malformed SQL لا يحتوي semicolon ولا reserved prefix.

هذه الحالات لا تعتبر valid Runtime usage؛ الاختبار يثبت فقط أنها ليست constructor-level SQL parsing classifications.

---

# 11. `PageResultTest`

## 11.1 direct valid construction

اختبار construction مباشر باستخدام:

* list من arrays.
* list من objects.
* empty data و`filtered > 0`.
* `filtered > total`.
* first page.
* middle page.
* final page.
* zero-result state.

## 11.2 canonical metadata

إثبات:

```php
$totalPages = $filtered === 0
    ? 0
    : intdiv($filtered - 1, $perPage) + 1;
```

و:

```text
hasNext     = page < totalPages
hasPrevious = page > 1 && totalPages > 0
```

## 11.3 invariant rejection

كل حالة ترمي:

```text
PaginationExecutionException
```

الحالات:

* associative outer data بدل list.
* scalar item.
* resource item.
* `page < 1`.
* `perPage < 1`.
* negative total.
* negative filtered.
* negative totalPages.
* `count(data) > perPage`.
* malformed sort key.
* totalPages غير مطابق للحساب.
* page أكبر من totalPages.
* zero filtered مع non-empty data.
* zero filtered مع page غير 1.
* zero filtered مع navigation flag true.
* inconsistent `hasNext`.
* inconsistent `hasPrevious`.

## 11.4 serialization

تثبيت envelope حرفيًا:

```php
[
    'data' => [],
    'pagination' => [
        'page' => 1,
        'per_page' => 20,
        'total' => 0,
        'filtered' => 0,
        'total_pages' => 0,
        'has_next' => false,
        'has_previous' => false,
        'sort_by' => 'created_at',
        'sort_direction' => 'DESC',
    ],
]
```

إثبات:

* `toArray()` و`jsonSerialize()` متطابقان.
* لا يوجد `offset`.
* mapped arrays كما هي.
* mapped objects نفس instances باستخدام `assertSame()`.
* لا يتم استدعاء `toArray()` أو `jsonSerialize()` على mapped object داخليًا.
* لا تظهر SQL identifiers أو raw request values.

---

# 12. `PdoPaginatorNormalizationTest`

يستخدم scripted PDO statements، ويختبر السلوك من خلال `paginate()` فقط.

## 12.1 page normalization

الحالات:

| Input                    |                         Expected |
| ------------------------ | -------------------------------: |
| `null`                   |                                1 |
| `''`                     |                                1 |
| whitespace               |                                1 |
| malformed alpha          |                                1 |
| decimal                  |                                1 |
| exponent                 |                                1 |
| sign only                |                                1 |
| `0`                      |                                1 |
| negative int             |                                1 |
| negative string          |                                1 |
| `1`                      |                                1 |
| `'+1'`                   |                                1 |
| `'0002'`                 |                                2 |
| valid integer            |                       same value |
| `PHP_INT_MAX`            | accepted when totalPages permits |
| `(string) PHP_INT_MAX`   |                         accepted |
| positive overflow string |                                1 |
| negative overflow string |                                1 |

لرؤية page أكبر من 1، يجب أن يعيد filtered count عدد صفحات كافيًا.

## 12.2 per-page normalization

استخدم config مخصصًا يميّز النتائج بوضوح، مثل:

```text
defaultPerPage = 20
minPerPage     = 2
maxPerPage     = 50
```

الحالات:

| Input                    |   Expected |
| ------------------------ | ---------: |
| `null`                   |         20 |
| empty/malformed          |         20 |
| positive overflow string |         20 |
| negative overflow string |         20 |
| representable negative   |          2 |
| `0`                      |          2 |
| `1`                      |          2 |
| `2`                      |          2 |
| valid middle value       | same value |
| `50`                     |         50 |
| `51`                     |         50 |
| `PHP_INT_MAX`            |         50 |
| `(string) PHP_INT_MAX`   |         50 |
| `'0005'`                 |          5 |

يجب إثبات الفرق بين:

* representable below-min → clamp to min.
* unrepresentable string → fallback to default.

## 12.3 sort normalization

`sortBy`:

* null → default.
* empty/whitespace → default.
* malformed → default.
* unknown → default.
* wrong case → default.
* trimmed valid key → requested key.

`sortDirection`:

* `asc`, `ASC`, mixed case → `ASC`.
* `desc`, `DESC`, mixed case → `DESC`.
* surrounding whitespace يُزال.
* null/empty/invalid → configured default.

الـ result يعيد effective public key والاتجاه النهائي فقط.

---

# 13. `PdoPaginatorExecutionTest`

## 13.1 execution order

تثبيت الترتيب:

```text
totalSql
filteredCountSql
dataSql
```

لا يجوز تجهيز data قبل نجاح counts.

## 13.2 separate parameter maps

استخدم أسماء لا تتكرر بين الخرائط:

```text
total_tenant
filtered_category
data_active
```

إثبات أن كل statement يحصل على map الخاصة به فقط.

## 13.3 typed binding

إثبات:

| Value  | Type              |
| ------ | ----------------- |
| int    | `PDO::PARAM_INT`  |
| bool   | `PDO::PARAM_BOOL` |
| null   | `PDO::PARAM_NULL` |
| string | `PDO::PARAM_STR`  |

وأن:

```text
:__pagination_limit
:__pagination_offset
```

يربطان دائمًا كـ `PDO::PARAM_INT`.

## 13.4 final SQL

Distinct identifiers:

```sql
{dataSql}
ORDER BY `created_at` DESC, `id` ASC
LIMIT :__pagination_limit
OFFSET :__pagination_offset
```

Duplicate resolved identifier:

```sql
{dataSql}
ORDER BY `id` DESC
LIMIT :__pagination_limit
OFFSET :__pagination_offset
```

إثبات:

* لا trailing semicolon.
* raw sort input لا يدخل SQL.
* directions تأتي من enum فقط.
* tie-breaker لا يتكرر عند تطابق quoted identifier.
* ترتيب bind:

    1. data params.
    2. internal limit.
    3. internal offset.

## 13.5 zero result

عند `filtered === 0`:

* page النهائي 1.
* totalPages = 0.
* data = [].
* flags false.
* data SQL لا يتم prepare لها.
* mapper لا يُستدعى.
* primary/tie-breaker lookup غير مطلوب للتنفيذ بعد counts.

## 13.6 overflow

عندما requested page أكبر من totalPages:

* page النهائي 1.
* offset = 0.
* data statement تُجهّز مرة واحدة.
* execute مرة واحدة.
* لا retry ثانٍ.

## 13.7 mapper

* array result.
* object result.
* ترتيب items محفوظ.
* نفس object instances محفوظة.
* empty fetched data مع `filtered > 0` مسموح.
* `filtered > total` مسموح.
* mapper يُستدعى مرة واحدة لكل row ناجحة.

---

# 14. `PdoPaginatorFailureTest`

## 14.1 package-owned non-throwing failures

كل حالة ترمي:

```text
PaginationExecutionException
```

### prepare returns false

* total prepare.
* filtered-count prepare.
* data prepare.

### bindValue returns false

* total caller parameter.
* filtered-count caller parameter.
* data caller parameter.
* internal limit.
* internal offset.

### execute returns false

* total.
* filtered count.
* data.

## 14.2 fetch rules

* `false` مع `errorCode() === '00000'` → EOF طبيعي.
* `false` مع أي SQLSTATE آخر → `PaginationExecutionException`.
* `false` مع `null` أو قيمة غير `'00000'` → failure.
* count fetch failure.
* data fetch failure.
* data fetch failure بعد row ناجحة:

    * يرمي exception.
    * لا يعيد partial `PageResult`.
* fetch يعيد scalar → exception.
* fetch يعيد array ذات numeric key → exception.
* empty data row → exception.

## 14.3 count shape

رفض:

* zero rows.
* أكثر من row.
* `columnCount() = 0`.
* `columnCount() > 1`.
* row فارغة.
* row متعددة الأعمدة.

## 14.4 count values

قبول:

* integer zero.
* positive integer.
* digit string.
* leading-zero digit string.
* `(string) PHP_INT_MAX`.

رفض:

* negative integer.
* `false`.
* `null`.
* empty string.
* signed string.
* decimal.
* exponent.
* negative string.
* greater than `PHP_INT_MAX`.
* array.
* object.
* resource.

## 14.5 mapper failure

رفض mapper result:

```text
null
bool
int
float
string
resource
```

باستخدام Reflection invocation عندما يكون ذلك مطلوبًا لتجنب مخالفة PHPDoc داخل static analysis.

## 14.6 Throwable propagation

استخدم Throwable instances معروفة مسبقًا وأثبت بـ `assertSame()`:

* `PDOException` من prepare.
* `PDOException` من bind.
* `PDOException` من execute.
* `PDOException` من fetch.
* arbitrary RuntimeException من mapper.

ممنوع توقع wrapping أو `previous`.

## 14.7 transaction and attributes through doubles

إثبات عدم استدعاء:

```text
beginTransaction
commit
rollBack
setAttribute
```

---

# 15. `PaginationPublicApiRegressionTest`

يستخدم Reflection لتثبيت الـ Public API فقط، دون تثبيت private implementation.

## 15.1 inventory

إثبات وجود الأنواع العشرة الصحيحة:

```text
Maatify\Persistence\Pdo\Pagination\PageRequest
Maatify\Persistence\Pdo\Pagination\SortDirectionEnum
Maatify\Persistence\Pdo\Pagination\SortWhitelist
Maatify\Persistence\Pdo\Pagination\PaginationConfig
Maatify\Persistence\Pdo\Pagination\PdoPaginationQueryDescriptor
Maatify\Persistence\Pdo\Pagination\PageResult
Maatify\Persistence\Pdo\Pagination\PdoPaginator

Maatify\Persistence\Exception\InvalidPaginationConfigurationException
Maatify\Persistence\Exception\InvalidPaginationQueryException
Maatify\Persistence\Exception\PaginationExecutionException
```

إثبات عدم وجود الأنواع الممنوعة:

```text
Maatify\Persistence\Exception\PaginationException
Maatify\Persistence\Pdo\Pagination\PaginatorInterface
Maatify\Persistence\Pdo\Pagination\RowMapperInterface
Maatify\Persistence\Pdo\Pagination\FilterWhitelist
Maatify\Persistence\Pdo\Pagination\SearchBuilder
```

## 15.2 modifiers

تثبيت `final` و`readonly` للأنواع المعتمدة.

تثبيت:

```text
SortDirectionEnum
ASC = ASC
DESC = DESC
```

ولا public enum methods إضافية.

## 15.3 constructors

تثبيت names، order، native types، nullability، defaults، promoted visibility لكل:

* `PageRequest`.
* `SortWhitelist`.
* `PaginationConfig`.
* `PdoPaginationQueryDescriptor`.
* `PageResult`.

يجب تثبيت الاسم حرفيًا:

```text
filteredCountParams
```

كـ constructor parameter وpublic property.

## 15.4 public methods

`SortWhitelist`:

```text
contains(string): bool
quotedIdentifierFor(string): string
```

`PdoPaginator`:

```php
paginate(
    PDO $pdo,
    PdoPaginationQueryDescriptor $query,
    PageRequest $request,
    PaginationConfig $config,
    callable $mapper,
): PageResult
```

`PageResult`:

```text
toArray(): array
jsonSerialize(): array
```

ممنوع تثبيت private helper names كـ Public API.

---

# 16. `PaginationSqlContractRegressionTest`

يثبت ضد scripted doubles:

* exact final SQL مع distinct tie-breaker.
* exact final SQL مع duplicate resolved identifier.
* internal placeholder names بالضبط.
* no offset field في result.
* fixed result field names والنesting.
* named-placeholder binding only من جانب package-owned params.
* descriptor لا يقدم SQL parser.
* positional/mixed/repeated placeholder examples لا تُرفض في constructor.
* raw sort key لا يظهر في SQL.
* exact execution order.
* overflow لا ينفذ query ثانية.
* zero filtered لا يجهز data SQL.

يجب أن يكون اسم الـ public descriptor:

```text
PdoPaginationQueryDescriptor
```

واسم request/result:

```text
PageRequest
PageResult
```

ولا تقبل الاختبارات aliases أو أسماء بديلة.

---

# 17. `PaginationExceptionContractTest`

لكل استثناء من الثلاثة:

* class `final`.
* يمتد من:

```text
Maatify\Exceptions\Exception\System\SystemMaatifyException
```

* يطبق:

```text
Maatify\Persistence\Exception\PersistenceException
```

* `defaultErrorCode()` يعيد:

```text
ErrorCodeEnum::MAATIFY_ERROR
```

* safety behavior موروث من `SystemMaatifyException`.
* لا يوجد Pagination-specific marker.

تغطية representative triggers:

* invalid whitelist/config → `InvalidPaginationConfigurationException`.
* invalid descriptor → `InvalidPaginationQueryException`.
* package-owned execution failure → `PaginationExecutionException`.

---

# 18. `OrderingPublicApiRegressionTest`

الغرض الوحيد إثبات أن إضافة Pagination لم تغيّر Ordering.

تثبيت Public API الحالية لـ:

```text
ScopedOrderingConfig
ScopedOrderingManager
```

يشمل:

* namespaces.
* final/readonly.
* constructor parameters لـ `ScopedOrderingConfig`.
* public promoted properties.
* public methods:

```text
quotedTable
quotedIdColumn
quotedOrderColumn
quotedScopeColumn
quotedDeletedAtColumn
```

و:

```text
getNextPosition
moveWithinScope
rowExistsInScope
```

مع parameter names/order/types/defaults والreturn types.

لا تعيد اختبار سلوك Ordering الذي تغطيه الاختبارات الحالية.

---

# 19. MySQL Fixture

## 19.1 table

الملف:

```text
tests/Fixtures/MySql/create_pagination_items_table.sql
```

ينشئ:

```text
maa_persistence_test_pagination_items
```

باستخدام:

```text
ENGINE=InnoDB
utf8mb4
```

الأعمدة المطلوبة:

```text
id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
tenant_id      INT UNSIGNED NOT NULL
category       VARCHAR(32) NOT NULL
name           VARCHAR(128) NOT NULL
score          INT NOT NULL
is_active      TINYINT(1) NOT NULL
nullable_code  VARCHAR(32) NULL
created_at     DATETIME(6) NOT NULL
deleted_at     DATETIME(6) NULL
```

Indexes يجب أن تدعم:

* tenant/base scope.
* filter columns.
* `created_at, id` deterministic ordering.

لا FK ولا host tables.

---

# 20. MySQL Support

## 20.1 `PaginationSchemaManager`

ثابت:

```php
public const TABLE = 'maa_persistence_test_pagination_items';
```

Methods:

```text
initialize()
reset()
dropTable()
```

`initialize()`:

1. drop.
2. execute fixture.

`reset()`:

```sql
TRUNCATE TABLE
```

`dropTable()`:

```sql
DROP TABLE IF EXISTS
```

## 20.2 `PaginationFixture`

يوفر method واضحة لإضافة row:

```php
insertItem(
    int $tenantId,
    string $category,
    string $name,
    int $score,
    bool $isActive,
    ?string $nullableCode,
    string $createdAt,
    ?string $deletedAt = null,
): int
```

* يستخدم prepared statement.
* يعيد inserted id.
* لا يستخدم randomness.
* لا يخفي defaults مؤثرة على الاختبار.

## 20.3 `PaginationIntegrationTestCase`

Base مستقلة عن Ordering.

تستخدم:

```text
MySqlConnectionFactory
PaginationSchemaManager
PaginationFixture
```

Lifecycle:

* `setUpBeforeClass()`:

    * connection.
    * initialize schema.
* `setUp()`:

    * rollback لأي transaction مفتوحة.
    * reset.
    * fixture جديدة.
* `tearDown()`:

    * rollback لأي transaction مفتوحة.
    * reset.
* `tearDownAfterClass()`:

    * rollback.
    * drop table.

لا تمتد من Ordering-specific base حتى لا تنشئ أو تعتمد على Ordering tables.

---

# 21. `PdoPaginatorQuerySemanticsTest`

Real MySQL فقط.

## 21.1 total and filtered

Dataset يحتوي:

* أكثر من tenant.
* active/inactive.
* أكثر من category.
* null/non-null code.
* soft-deleted row.

`totalSql` يطبق mandatory base scope فقط.

`filteredCountSql` و`dataSql` يضيفان optional filters.

إثبات:

* total يمثل base visible dataset.
* filtered يمثل filtered visible dataset.
* data متوافقة مع filtered.
* soft-deleted rows لا تظهر عندما يستبعدها caller SQL.

## 21.2 no-filter

* totalSql وfilteredCountSql يمكن أن يكونا متطابقين.
* parameter maps تظل منفصلة.
* total وfiltered متساويان.

## 21.3 parameter types

في descriptor واحد حيث أمكن:

```text
tenant_id      => int
category       => string
is_active      => bool
nullable_code  => null
```

استخدم MySQL null-safe comparison:

```sql
nullable_code <=> :nullable_code
```

## 21.4 native prepares

قبل التشغيل:

```php
PDO::ATTR_EMULATE_PREPARES === false
```

بعد التشغيل تظل القيمة نفسها.

## 21.5 sorting

اختبار:

* default sort.
* requested sort.
* invalid key fallback.
* invalid direction fallback.
* ASC.
* DESC.
* surrounding whitespace.
* duplicate primary values مع unique `id` tie-breaker.
* استدعاء صفحات متتالية وإثبات عدم تكرار أو فقد rows.
* public keys مختلفان يشيران إلى نفس unique `id` ويعملان دون duplicate ordering term.

## 21.6 page behavior

* first page.
* middle page.
* final page.
* page overflow تعيد page 1 وfirst-page rows.
* zero filtered تعيد empty result.

لإثبات skip الحقيقي عند zero filtered:

* استخدم `dataSql` تشير إلى table أو column غير موجودة.
* طالما filtered count = 0، يجب ألا يحدث PDO failure.
* لا يستخدم semicolon أو reserved placeholder.

## 21.7 mapper

* identity array mapper.
* DTO/object mapper.
* ترتيب mapped items.
* object instances كما أعادها mapper.
* `filtered > total` descriptor متعمد لإثبات عدم وجود Runtime enforcement.
* empty data query result مع positive filtered count مسموح ولا تعدل metadata.

---

# 22. `PdoPaginatorFailureContractTest`

Real MySQL فقط.

## 22.1 count cardinality

* exactly one row / one column مقبول.
* zero rows مرفوض.
* multiple rows مرفوض.
* multiple columns مرفوض.

يمكن استخدام SQL ثابت وآمن لا يعتمد على parser.

## 22.2 placeholder failures

يجب أولًا إثبات أن descriptor constructor نجح، ثم تنفيذ paginator.

الحالات:

* repeated named placeholder.
* missing parameter.
* unused parameter.
* positional placeholder.
* mixed positional/named placeholders.

الاختبار يقبل failure طبقًا للعقد على صورة:

```text
PDOException
أو
PaginationExecutionException
```

بحسب مرحلة وطريقة إخفاق PDO.

ممنوع الادعاء أن descriptor constructor يكتشفها.

## 22.3 mapper failure

* scalar result → `PaginationExecutionException`.
* resource result → `PaginationExecutionException`.
* mapper Throwable يخرج بنفس instance.

## 22.4 PDO exception propagation

استخدم invalid table أو invalid column في count/data execution، وأثبت:

* الخارج `PDOException`.
* ليس `PaginationExecutionException`.
* لا wrapping.

لا تثبت message أو vendor-specific numeric code.

---

# 23. `PdoPaginatorTransactionTest`

Real MySQL فقط.

## 23.1 outside transaction

قبل:

```php
$pdo->inTransaction() === false
```

بعد successful pagination:

```php
$pdo->inTransaction() === false
```

إثبات أن paginator لم يبدأ transaction.

## 23.2 caller-owned transaction

1. caller يبدأ transaction.
2. ينفذ pagination.
3. النتيجة صحيحة.
4. transaction ما زالت active.
5. caller يعمل rollback داخل `finally`.

ممنوع أن يعمل paginator:

```text
commit
rollback
```

## 23.3 attributes unchanged

التقط قبل وبعد:

```text
PDO::ATTR_ERRMODE
PDO::ATTR_DEFAULT_FETCH_MODE
PDO::ATTR_EMULATE_PREPARES
PDO::ATTR_STRINGIFY_FETCHES
```

فقط attributes المدعومة فعليًا تُقارن.

ممنوع أن يغير paginator أي attribute.

## 23.4 claim boundary

لا يوجد test بعنوان أو assertion تدعي أن كل external PDO/driver failure يحافظ على transaction state.

الضمان المختبر:

* النجاح داخل caller transaction لا ينهيها.
* النجاح خارج transaction لا ينشئ واحدة.
* paginator لا يستدعي transaction operations بنفسه.

---

# 24. CI Residue Verification

داخل `.github/workflows/ci.yml`:

أضف:

```text
maa_persistence_test_pagination_items
```

إلى قائمة `$tables`.

حدّث query placeholders والـ execute arguments لتشمل الجداول الثلاثة:

```text
maa_persistence_test_global_ordering
maa_persistence_test_scoped_ordering
maa_persistence_test_pagination_items
```

لا تضف triggers جديدة.

الـ CI يجب أن تستمر في تشغيل:

```text
unit
regression
integration
integration مرة ثانية
full suite
```

ويجب أن يمر فحص residue بعد التشغيل المتكرر.

---

# 25. Coverage Mapping Requirement

يجب أن يتضمن PR description جدولًا يربط كل بند من:

```text
PDO_PAGINATION_CONTRACT.md / Section 21
```

باسم test class وtest method التي تغطيه.

لا يُقبل:

* وصف عام مثل “covered”.
* بند دون test محدد.
* اعتبار PHPStan أو syntax check بديلًا عن behavior test.
* اعتبار Unit doubles بديلًا عن Real MySQL حيث يطلب العقد MySQL.
* اعتبار MySQL وحده بديلًا عن package-owned non-throwing failure doubles.

---

# 26. التحقق المطلوب

شغّل:

```bash
composer validate --strict

vendor/bin/phpstan analyse -c phpstan.neon --no-progress

vendor/bin/php-cs-fixer fix --dry-run --diff

vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite regression
vendor/bin/phpunit --testsuite integration
vendor/bin/phpunit --testsuite integration
vendor/bin/phpunit

git diff --check
```

وشغّل syntax check لكل:

```text
src/**/*.php
tests/**/*.php
```

الـ Integration يجب أن تُنفذ فقط على Real MySQL باستخدام environment الحالي:

```text
PERSISTENCE_TEST_MYSQL_DSN
PERSISTENCE_TEST_MYSQL_USER
PERSISTENCE_TEST_MYSQL_PASSWORD
```

لا skip صامت عند غياب environment؛ الأساس الحالي يعتبر غياب configuration فشلًا واضحًا.

---

# 27. معايير القبول

مرحلة الاختبارات تُقبل فقط عند:

* كل Unit tests ناجحة.
* كل Regression tests ناجحة.
* كل Real MySQL Integration tests ناجحة.
* إعادة Integration ناجحة.
* Full suite ناجحة.
* PHPStan max بلا errors.
* Code style ناجح.
* MySQL residue فارغ.
* لا skips غير معتمدة.
* لا suppressions.
* لا Runtime changes.
* لا Composer changes.
* لا Ordering behavior changes.
* لا `composer.lock`.
* كل بند في Contract Section 21 مربوط باختبار محدد.

عدد الاختبارات أو assertions ليس هدفًا مستقلًا؛ اكتمال العقد هو الهدف.

---

# 28. نطاق Jules لاحقًا

بعد اعتماد هذا الـ Blueprint، Jules مسموح له فقط:

* إنشاء ملفات الاختبارات والدعم والfixture المحددة.
* تعديل residue verification المحدد داخل CI.
* تشغيل كل quality gates.
* إنشاء PR دون دمجه.

ممنوع على Jules:

* تعديل `src/**`.
* إصلاح Runtime بنفسه.
* اتخاذ قرار معماري جديد.
* تعديل Contract أو Runtime Blueprint.
* تعديل README أو Package Reference أو CHANGELOG.
* تعديل Composer.
* إضافة abstraction غير موجودة في هذا الملف.
* تنفيذ release أو tag أو merge.

إذا كشف test عن Runtime defect:

```text
STOP
REPORT EXACT FAILURE
DO NOT MODIFY SRC
```

---

# 29. تسليم Jules المطلوب لاحقًا

يجب أن يرسل:

1. Starting SHA.
2. PR URL.
3. Final HEAD SHA.
4. قائمة الملفات المتغيرة.
5. عدد Unit tests/assertions.
6. عدد Regression tests/assertions.
7. عدد Integration tests/assertions.
8. نتيجة Integration run الثانية.
9. نتيجة full suite.
10. نتيجة PHPStan.
11. نتيجة code style.
12. نتيجة MySQL residue.
13. Contract Section 21 coverage mapping.
14. تأكيد عدم تعديل `src/**`.
15. تأكيد عدم تعديل Composer أو docs.
16. تأكيد عدم إنشاء `composer.lock`.
17. أي Runtime defect اكتشفه دون إصلاحه.

Commit message لتنفيذ الاختبارات لاحقًا:

```text
test(pagination): cover PDO pagination contracts
```

---

# 30. القرار الحالي

```text
TEST_BLUEPRINT_STATUS: IMPLEMENTED
NEXT_ACTION: NONE
```
