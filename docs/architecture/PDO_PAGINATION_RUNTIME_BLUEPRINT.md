# Runtime Blueprint — PDO Pagination `v1.1.0`

**الحالة:** جاهز لمراجعة واعتماد المالك.
**مصدر الحقيقة:** `docs/architecture/PDO_PAGINATION_CONTRACT.md` على `main`.
**النطاق:** تنفيذ Runtime فقط داخل `src/**`.

العقد يثبت أن Pagination نطاق جديد مستقل بجوار Ordering، وأن Public API الحالي لـ Ordering ممنوع تغييره. كما يحصر Runtime في عشرة ملفات محددة.

---

## 1. الملفات المسموح بإنشائها

```text
src/Pdo/Pagination/PageRequest.php
src/Pdo/Pagination/SortDirectionEnum.php
src/Pdo/Pagination/SortWhitelist.php
src/Pdo/Pagination/PaginationConfig.php
src/Pdo/Pagination/PdoPaginationQueryDescriptor.php
src/Pdo/Pagination/PageResult.php
src/Pdo/Pagination/PdoPaginator.php

src/Exception/InvalidPaginationConfigurationException.php
src/Exception/InvalidPaginationQueryException.php
src/Exception/PaginationExecutionException.php
```

لا يُعدّل أي ملف موجود داخل:

```text
src/Pdo/Ordering/**
```

ولا يُضاف Interface أو marker أو Query Builder أو Filter/Search abstraction.

---

# 2. قواعد التنفيذ العامة

* PHP `>=8.2` فقط؛ ممنوع استخدام أي syntax أو API تتطلب إصدارًا أحدث.
* كل ملف يبدأ بـ:

```php
<?php

declare(strict_types=1);
```

* الالتزام بنمط الملفات الحالية: PSR-12، `final`، `readonly` حيث يفرض العقد، وDocBlocks دقيقة.
* لا توجد Runtime dependencies جديدة.
* لا يوجد فحص لـ `PDO::ATTR_DRIVER_NAME`.
* التنفيذ مدعوم رسميًا مع PDO driver `mysql` فقط، واستخدام driver آخر خارج العقد.
* لا يبدأ `PdoPaginator` أو ينهي أو يرجع transaction.
* لا يغيّر أي PDO attribute.
* لا يمسك `Throwable` بغرض التغليف العام.
* `PDOException` وأي Throwable خارجي أو صادر عن mapper يمر بنفس الـ instance دون تغيير.

---

# 3. Blueprint لكل ملف

## 3.1 `PageRequest`

```php
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

مسؤوليته الوحيدة حمل الـ raw caller input.

ممنوع داخله:

* normalization.
* validation ترمي exceptions.
* HTTP أو PSR-7 handling.
* PDO أو SQL.
* methods إضافية.

القيم غير الصحيحة لا تُرفض هنا؛ `PdoPaginator` هو المسؤول عن fallback وclamping.

---

## 3.2 `SortDirectionEnum`

```php
enum SortDirectionEnum: string
{
    case ASC = 'ASC';
    case DESC = 'DESC';
}
```

لا methods إضافية.

يُستخدم `$direction->value` فقط عند بناء SQL والـ result envelope.

---

## 3.3 `SortWhitelist`

```php
final readonly class SortWhitelist
```

### Public API

```php
public function __construct(array $sorts);

public function contains(string $key): bool;

public function quotedIdentifierFor(string $key): string;
```

### التخزين الداخلي

خاصية private واحدة فقط تحتوي الخريطة بعد validation والـ quoting:

```php
/** @var non-empty-array<non-empty-string, non-empty-string> */
private array $quotedSorts;
```

لا public getter للخريطة الأصلية أو المقتبسة.

### Constructor validation

يرفض بـ `InvalidPaginationConfigurationException`:

* الخريطة الفارغة.
* أي key غير `string`.
* أي value غير `string`.
* أي public key لا يطابق:

```text
^[A-Za-z_][A-Za-z0-9_]*$
```

* أي identifier path لا يتكون من مقطع إلى ثلاثة مقاطع.
* أي segment لا يطابق نفس regex.

الأشكال المقبولة:

```text
column
alias.column
schema.table.column
```

بعد validation، كل segment يُحاط بـ backticks ثم تُعاد النقاط بين المقاطع:

```text
created_at                  => `created_at`
v.created_at                => `v`.`created_at`
catalog.products.created_at => `catalog`.`products`.`created_at`
```

### Methods

`contains()`:

* exact case-sensitive lookup.
* يستخدم `array_key_exists()` على الخريطة الداخلية.

`quotedIdentifierFor()`:

* يعيد identifier المقتبس.
* unknown key يرمي `InvalidPaginationConfigurationException`.

العقد يمنع expressions والـ functions والـ comments والاتجاهات والـ commas داخل identifier whitelist.

---

## 3.4 `PaginationConfig`

```php
final readonly class PaginationConfig
{
    public function __construct(
        public SortWhitelist $sortWhitelist,
        public string $defaultSortBy,
        public SortDirectionEnum $defaultSortDirection,
        public string $tieBreakerSortBy,
        public SortDirectionEnum $tieBreakerDirection,
        public int $defaultPerPage = 20,
        public int $minPerPage = 1,
        public int $maxPerPage = 200,
    ) {
    }
}
```

### Constructor validation

يرمي `InvalidPaginationConfigurationException` عندما:

* `minPerPage < 1`.
* `maxPerPage < minPerPage`.
* `defaultPerPage < minPerPage`.
* `defaultPerPage > maxPerPage`.
* `defaultSortBy` لا يطابق public-key regex.
* `tieBreakerSortBy` لا يطابق public-key regex.
* `defaultSortBy` غير موجود في whitelist.
* `tieBreakerSortBy` غير موجود في whitelist.

مسموح أن:

* المفتاحين يكونا متساويين.
* مفتاحين مختلفين يشيرا إلى نفس quoted identifier.

لا يحاول الكلاس إثبات uniqueness للـ tie-breaker من قاعدة البيانات؛ هذه مسؤولية المستهلك.

---

## 3.5 `PdoPaginationQueryDescriptor`

```php
final readonly class PdoPaginationQueryDescriptor
{
    public function __construct(
        public string $totalSql,
        public array $totalParams,
        public string $filteredCountSql,
        public array $filteredCountParams,
        public string $dataSql,
        public array $dataParams,
    ) {
    }
}
```

### SQL validation

يُطبّق على الثلاثة:

```text
totalSql
filteredCountSql
dataSql
```

يرفض بـ `InvalidPaginationQueryException`:

* SQL فارغ بعد `trim()`.
* وجود أي `;` في أي مكان.
* وجود substring:

```text
:__pagination_
```

الفحص الأخير مقصود أن يكون conservative collision check، وليس SQL parser.

يجب الاحتفاظ بنص SQL الأصلي داخل public property؛ validation يستخدم `trim()` فقط للفحص ولا يعيد كتابة SQL.

### Parameter-map validation

يُطبّق منفصلًا على:

```text
totalParams
filteredCountParams
dataParams
```

كل key:

* يجب أن يكون `string`.
* لا يبدأ بـ `:`.
* يطابق:

```text
^[A-Za-z_][A-Za-z0-9_]*$
```

* لا يبدأ بـ:

```text
__pagination_
```

القيم المسموحة فقط:

```text
string
int
bool
null
```

يرفض:

```text
float
array
object
resource
```

### ما لا يتحقق منه الكلاس

* matching بين placeholders والـ map.
* unused أو missing placeholders.
* repeated placeholders.
* positional `?`.
* mixed placeholder styles.
* SQL clauses.
* توافق count queries مع data query.

لا methods عامة إضافية، ولا SQL execution داخله.

---

## 3.6 `PageResult`

```php
/**
 * @template T of array|object
 */
final readonly class PageResult implements \JsonSerializable
```

### Constructor

نفس الـ signature المثبت في العقد دون أي factory أو named constructor.

### Invariants

أي فشل يرمي `PaginationExecutionException`.

يجب التحقق من:

1. `data` هي list باستخدام `array_is_list()`.
2. كل عنصر داخل `data` إما `array` أو `object`.
3. `page >= 1`.
4. `perPage >= 1`.
5. `total >= 0`.
6. `filtered >= 0`.
7. `totalPages >= 0`.
8. `count(data) <= perPage`.
9. `sortBy` يطابق public-key regex.
10. `totalPages` يساوي:

```php
$filtered === 0
    ? 0
    : intdiv($filtered - 1, $perPage) + 1;
```

11. عند `filtered === 0`:

* `data === []`.
* `page === 1`.
* `totalPages === 0`.
* `hasNext === false`.
* `hasPrevious === false`.

12. عند `totalPages > 0`:

* `page <= totalPages`.

13. الـ navigation flags تطابق:

```php
$hasNext === ($page < $totalPages);

$hasPrevious === ($page > 1 && $totalPages > 0);
```

### حالات مقبولة

* `filtered > total`.
* `data === []` مع `filtered > 0`.
* mapped arrays.
* mapped objects.

### Serialization

`toArray()` يعيد الـ envelope الثابت:

```php
[
    'data' => $this->data,
    'pagination' => [
        'page' => $this->page,
        'per_page' => $this->perPage,
        'total' => $this->total,
        'filtered' => $this->filtered,
        'total_pages' => $this->totalPages,
        'has_next' => $this->hasNext,
        'has_previous' => $this->hasPrevious,
        'sort_by' => $this->sortBy,
        'sort_direction' => $this->sortDirection->value,
    ],
]
```

`jsonSerialize()` يستدعي `toArray()` مباشرة.

ممنوع:

* deep conversion.
* استدعاء `toArray()` على mapped items.
* استدعاء `jsonSerialize()` على mapped items.
* استبدال object instances.

العقد يضمن الـ outer envelope فقط.

---

# 4. Exceptions

الكلاسات الثلاثة تتبع نفس نمط استثناءات Ordering الحالي:

```php
final class ... extends SystemMaatifyException implements PersistenceException
{
    protected function defaultErrorCode(): ErrorCodeInterface
    {
        return ErrorCodeEnum::MAATIFY_ERROR;
    }
}
```

هذا هو الشكل المطبق حاليًا في الحزمة.

## `InvalidPaginationConfigurationException`

لأخطاء:

* whitelist.
* identifier paths.
* public sort keys.
* PaginationConfig invariants.
* unknown whitelist lookup.

## `InvalidPaginationQueryException`

لأخطاء:

* empty SQL.
* semicolon.
* reserved placeholder namespace.
* parameter keys.
* parameter value types.

## `PaginationExecutionException`

لأخطاء:

* `prepare()` يعيد `false`.
* `bindValue()` يعيد `false`.
* `execute()` يعيد `false`.
* non-throwing fetch failure.
* invalid count shape/value.
* invalid fetched data row.
* invalid mapper result.
* invalid `PageResult` state.

لا custom public constructors ولا error codes جديدة ولا Pagination marker إضافي.

---

# 5. `PdoPaginator`

```php
/**
 * @template T of array|object
 */
final readonly class PdoPaginator
```

لا constructor معلن، ولا properties، ولا state.

## Public method

```php
public function paginate(
    PDO $pdo,
    PdoPaginationQueryDescriptor $query,
    PageRequest $request,
    PaginationConfig $config,
    callable $mapper,
): PageResult;
```

---

## 5.1 Integer parsing

يجب استخدام parser داخلي آمن، ولا يجوز casting مباشر قبل فحص overflow.

السلوك المعتمد:

1. `int` يعاد كما هو.
2. `null` يعيد parsing failure.
3. string يتم `trim()` لها.
4. يجب أن تطابق:

```text
^[+-]?[0-9]+$
```

5. يُفصل sign.
6. تُزال leading zeros مع الاحتفاظ بـ `"0"`.
7. تتم مقارنة عدد الخانات ثم lexical comparison مع:

    * `(string) PHP_INT_MAX` للقيمة الموجبة.
    * magnitude الخاص بـ `PHP_INT_MIN` للقيمة السالبة.
8. القيمة الأكبر من حدود PHP تعيد parsing failure.
9. لا يتم cast إلا بعد نجاح bounds check.

### Page normalization

```text
missing / malformed / unrepresentable => 1
parsed < 1                           => 1
otherwise                            => parsed value
```

### Per-page normalization

```text
missing / malformed / unrepresentable => defaultPerPage
parsed < minPerPage                    => minPerPage
parsed > maxPerPage                    => maxPerPage
otherwise                              => parsed value
```

بهذا تظل القيمة السالبة القابلة للتمثيل مختلفة عن القيمة السالبة التي تجاوزت حدود PHP عند معالجة `perPage`.

---

## 5.2 Sort resolution

### `sortBy`

1. `null` → `defaultSortBy`.
2. `trim()`.
3. empty أو invalid regex → `defaultSortBy`.
4. غير موجود في whitelist → `defaultSortBy`.
5. غير ذلك يُستخدم المفتاح المطلوب.

لا exception بسبب raw sort input.

### `sortDirection`

1. `null` → config default.
2. `trim()`.
3. `strtoupper()`.
4. `ASC` → `SortDirectionEnum::ASC`.
5. `DESC` → `SortDirectionEnum::DESC`.
6. غير ذلك → config default.

---

# 6. Count execution

يستخدم نفس المسار لكل من:

```text
totalSql + totalParams
filteredCountSql + filteredCountParams
```

## الترتيب الإلزامي

1. `$pdo->prepare($sql)`.
2. إذا رجع `false` → `PaginationExecutionException`.
3. bind كل caller parameter منفصلًا.
4. إذا أي `bindValue()` رجع `false` → `PaginationExecutionException`.
5. `$statement->execute()` دون تمرير map جديد.
6. إذا رجع `false` → `PaginationExecutionException`.
7. التأكد أن:

```php
$statement->columnCount() === 1
```

8. fetch أول row باستخدام:

```php
$statement->fetch(PDO::FETCH_ASSOC)
```

9. إذا لم توجد row بنهاية طبيعية → invalid count result.
10. يجب أن تحتوي row على عنصر واحد.
11. fetch للمرة الثانية.
12. وجود row ثانية → invalid count result.
13. EOF طبيعي بعد أول row → cardinality صحيحة.
14. validation وتحويل قيمة count.

## Count values المقبولة

* `int >= 0`.
* digit-only string داخل `PHP_INT_MAX`.

عند string:

* تُرفض signs.
* تُرفض decimals وexponents.
* تزال leading zeros لأغراض bounds check.
* بعد bounds validation تتحول إلى `int`.

لا casting أعمى، ولا قبول bool أو float أو null.

---

# 7. PDO binding

كل caller parameter يُربط باسمه:

```php
':' . $key
```

والنوع:

| القيمة   | النوع             |
| -------- | ----------------- |
| `int`    | `PDO::PARAM_INT`  |
| `bool`   | `PDO::PARAM_BOOL` |
| `null`   | `PDO::PARAM_NULL` |
| `string` | `PDO::PARAM_STR`  |

لا يستخدم:

```php
$statement->execute($params)
```

لأن العقد يفرض typed `bindValue()`.

بالنسبة إلى data statement:

1. bind `dataParams`.
2. bind `:__pagination_limit` كـ `PDO::PARAM_INT`.
3. bind `:__pagination_offset` كـ `PDO::PARAM_INT`.
4. execute مرة واحدة.

---

# 8. Fetch failure handling

يستخدم helper داخلي واحد لكل count وdata fetch.

عندما:

```php
$row = $statement->fetch(PDO::FETCH_ASSOC);
```

يعيد `false`:

```php
$errorCode = $statement->errorCode();
```

* `'00000'` → EOF طبيعي.
* أي قيمة أخرى، بما فيها `null` → `PaginationExecutionException`.

لا يُعاد `PageResult` يحتوي على rows جزئية عند fetch failure.

`PDOException` الصادر من `fetch()` أو `errorCode()` يمر دون catch أو wrapping.

---

# 9. Data SQL assembly

بعد counts وتطبيق zero/overflow policy فقط، يتم جلب quoted identifiers.

البناء المعتمد:

```php
$sql = rtrim($query->dataSql)
    . "\nORDER BY "
    . $quotedPrimary
    . ' '
    . $effectiveDirection->value;
```

إذا كان quoted primary مختلفًا عن quoted tie-breaker:

```php
$sql .= ', '
    . $quotedTieBreaker
    . ' '
    . $config->tieBreakerDirection->value;
```

ثم:

```php
$sql .= "\nLIMIT :__pagination_limit"
    . "\nOFFSET :__pagination_offset";
```

إذا كانا متساويين، لا يُكرر identifier، واتجاه primary هو الذي يفوز.

لا trailing semicolon.

---

# 10. Data row mapping

بعد execute:

```php
while (true) {
    $row = fetchAssociativeOrEof(...);

    if ($row === false) {
        break;
    }

    // validate row
    // call mapper
    // validate mapped result
    // append to list
}
```

### Row validation

الـ fetched row يجب أن:

* تكون array.
* تحتوي على عمود واحد على الأقل.
* جميع مفاتيحها strings.

أي list/numeric-key row غير متوقع يرمي `PaginationExecutionException`.

### Mapper

يُستدعى مرة واحدة لكل row وبنفس ترتيب fetch.

النتيجة المقبولة:

```text
array
object
```

أي:

```text
null
bool
int
float
string
resource
```

ترمي `PaginationExecutionException`.

إذا mapper رمى أي `Throwable`:

* لا catch.
* لا wrap.
* لا rollback.
* نفس الـ instance يخرج للمستهلك.

---

# 11. Exact `paginate()` flow

الترتيب النهائي ممنوع تغييره:

1. normalize page.
2. normalize per-page.
3. resolve effective sort key.
4. resolve effective sort direction.
5. execute total count.
6. execute filtered count.
7. calculate `totalPages`.
8. إذا `filtered === 0`:

    * effective page = `1`.
    * لا quote للـ SQL identifiers مطلوب للتنفيذ.
    * لا prepare أو execute لـ `dataSql`.
    * لا mapper invocation.
    * return empty `PageResult`.
9. إذا requested page أكبر من `totalPages`:

    * effective page = `1`.
10. resolve quoted primary and tie-breaker.
11. calculate offset:

```php
$offset = ($page - 1) * $perPage;
```

12. build final SQL.
13. prepare.
14. bind data params.
15. bind limit.
16. bind offset.
17. execute مرة واحدة.
18. fetch/map rows.
19. calculate:

```php
$hasNext = $page < $totalPages;
$hasPrevious = $page > 1 && $totalPages > 0;
```

20. return `PageResult`.

ممنوع:

* second retry query.
* تعديل metadata لمطابقة rows.
* فرض `filtered <= total`.
* transaction operations.

---

# 12. Private implementation surface

يجوز إنشاء private methods فقط لتقسيم السلوك السابق، مثل:

```text
parseIntegerInput()
normalizePage()
normalizePerPage()
resolveSortBy()
resolveSortDirection()
executeCount()
prepareStatement()
bindParameters()
bindValue()
pdoParameterType()
fetchAssociativeOrEof()
assertAssociativeDataRow()
normalizeCountValue()
buildDataSql()
```

هذه ليست Public API، ولا يجوز تحويل أي منها إلى `public` أو `protected`.

لا class إضافية ولا trait إضافية لتنفيذ helpers.

---

# 13. ممنوع في PR التنفيذ

* تعديل docs.
* تعديل tests أو إضافتها.
* تعديل README.
* تعديل Package Reference.
* تعديل CHANGELOG.
* تعديل `composer.json`.
* إنشاء `composer.lock`.
* تعديل Ordering.
* إضافة Interface.
* إضافة factory.
* إضافة repository abstraction.
* إضافة SQL parser.
* إضافة Runtime driver check.
* إضافة transaction handling.
* catch-all exception wrapping.
* feature أو migration في host projects.

العقد يفصل Runtime الخاص بـ Codex عن مرحلة tests/docs/compliance الخاصة بـ Jules.

---

# 14. تسليم Codex المطلوب لاحقًا

عند إرسال التوجيه إلى Codex، يجب أن يعيد:

1. starting SHA.
2. قائمة الملفات المضافة والمعدلة.
3. diff كامل.
4. نتيجة PHP syntax check.
5. نتيجة PHPStan.
6. نتيجة الاختبارات الحالية.
7. تأكيد عدم تعديل أي ملف خارج العشرة.
8. تأكيد عدم إنشاء `composer.lock`.

## Commit message المعتمد

```text
feat(pagination): implement PDO offset pagination runtime
```

## القرار الحالي

```text
BLUEPRINT_STATUS: READY_FOR_OWNER_APPROVAL
NEXT_ACTION: APPROVE_BLUEPRINT_THEN_SEND_TO_CODEX
```
