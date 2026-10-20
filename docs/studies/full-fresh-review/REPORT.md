# مراجعة معمارية استباقية شاملة — Maatify Persistence

**نوع الوثيقة:** Audit استكشافي (Study only). ليس Compliance Audit نهائيًا، ولا يغيّر `src/` أو `tests/` أو `composer.json` أو العقود العامة أو المعايير المثبتة، ولا يبدأ أي إصلاح. كل ما يلي أدلة وتصنيفات وتوصيات قرار للمالك.

**الحالة المفحوصة:** `main` عند `91b3963` (بعد #44)، الفرع `claude/maatify-persistence-audit-df2529`.
**تاريخ التنفيذ:** 2026-10-20. **البيئة:** PHP 8.4.4، MySQL 8.4.10 (Docker `mysql:8.4.10`، حاوية مؤقتة خاصة بهذه المراجعة).
**مجموعة المعايير المطبقة:** `docs/php-engineering-standards/STANDARDS_MANIFEST.md` (Adoption Commit `44c8827`، Resolution Status `VALID`، Exception State `NONE`).

---

## 1. الخلاصة التنفيذية

الأساس سليم: كل بوابات المستودع تنجح محليًا على MySQL حقيقي (القسم 3)، وتحليل منطق `OwnedTransactionExecutor` وممرات الـ savepoint لم يكشف عيبًا في المسار المدعوم. لكن المراجعة وجدت خمس نتائج Runtime/Contract (RC-01..05) وسادسة معلوماتية، وسبع فجوات توثيق/مستهلك وثلاث فجوات تحقق، وإحدى عشرة فجوة معيارية، وسبعة بنود مخاطر (بينها نتيجة سلبية). كلها مثبتة بتجربة أو بقراءة دقيقة للكود ما لم يُذكر خلاف ذلك. أهمها:

| # | النتيجة | الخطورة | المصدر |
|---|---|---|---|
| RC-01 | `moveWithinScope()` يفشل بـ `1062` على أي حركة فعلية عند وجود `UNIQUE (scope, order)`، والأدلة والأمثلة توصي بهذا الفهرس كشبكة أمان | **عالية** | Runtime + Docs |
| DG-01 | Quick Usage في README يعتمد على واجهات Unreleased (`PdoDeadlockRetryTransactionRunner`, `getNextPositionForUpdate`) غير موجودة في آخر إصدار مستقر قابل للتثبيت `v1.4.0` | **عالية** | Docs / عملية الإصدار |
| DG-03 | بعد `1213` داخل معاملة يملكها المستدعي، المتابعة بعد الاستثناء تنفّذ كتابات لاحقة في وضع autocommit صامت وتفقد ما قبلها (مثبت) | **عالية** | Docs (+ فرصة تحصين Runtime) |
| RC-02 | فشل `moveWithinScope()` داخل معاملة المستدعي يترك إزاحة المدى مطبّقة (ترتيب مكرر مثبت)، بينما README والمرجع يقولان "Rolls back" | متوسطة–عالية | Runtime (تصميم مختبَر) + Docs |
| RC-03 | مدير الـ Ordering ليس fail-closed خارج `ERRMODE_EXCEPTION` (يرمي `Error` خام) بخلاف الـ Paginator والـ runners الجدد، وشرط وضع الأخطاء غير موثق | متوسطة | Runtime + Docs |
| SG-01 | لا يوجد معيار يغطي deadlock / lock-timeout / retry / isolation رغم أن `PACKAGE_BUILDING §25` يطلب توثيق حدود التزامن | عالية (للدورة الحالية) | Standards |

لا توجد نتيجة تمس سلامة SQL injection أو عزل النطاق. أقرب ما يمس الأمان هو RC-05 (معرّفات تقبل `\n` في نهايتها) وهو غير قابل للاستغلال لأنه داخل backticks.

**التوصية العامة (دون تنفيذ):** قبل تثبيت إصدار المعايير ينبغي حسم RC-01 وDG-01 وDG-03 على مستوى المالك؛ وتسجيل SG-01 وSG-02 وSG-03 وSG-05 كمدخلات لدورة المعايير القادمة.

---

## 2. المنهج والحدود

- **قُرئ كاملًا:** `AGENTS.md`، معيار الاعتماد، `STANDARDS_MANIFEST.md`، الـ Profiles الاثنان، `TESTING_STANDARD.md`، كل `src/` (≈2,000 سطر)، `README.md`، `PERSISTENCE_PACKAGE_REFERENCE.md`، `CHANGELOG.md`، `SECURITY.md`، `CONTRIBUTING.md`، ADR 0003 و0004، `PDO_TRANSACTION_ARCHITECTURE.md`، دليل `MOVE_WITHIN_SCOPE`، `ci.yml`.
- **قُرئ بصورة مستهدفة:** أقسام محددة من `PACKAGE_BUILDING` (§1، 6، 7، 16، 20، 21، 23، 25)، `COMPOSER_PACKAGE` (§18، 19، 23، 26، 27)، `CI_WORKFLOW` (§2، 11)، `LIBRARY_PRESENTATION` (§10، 12، 14، 15، 16)، عقد الـ Pagination (§8–9)، دليل `NEXT_POSITION`، ADR 0001/0002، وثيقة Ordering Phase، و`tests/Consumer/run.php` (جزئيًا).
- **لم يُقرأ بالتفصيل:** `AI_COLLABORATION_WORKFLOW_AR.md` و`GITHUB_PHASE_STACK_WORKFLOW_AR.md` (استُخدما لقواعد اللغة والـ PR فقط)، `PDO_PAGINATION_RUNTIME_BLUEPRINT.md` و`PDO_PAGINATION_TEST_BLUEPRINT.md`، نتائج دراسات `docs/studies/**/results`.
- **لم يُنفَّذ:** أي اختبار على MariaDB، أو على PHP 8.2/8.3/8.5، أو تحت حمل متزامن، أو على إصدارات MySQL غير 8.4.10. كل ما يخص هذه المحاور موضوع في القسم 7 كـ "يحتاج إثباتًا".
- **التجارب** في [`probes/`](probes/) مع مخرجاتها المحفوظة في [`probes/probe-output.txt`](probes/probe-output.txt). كل تجربة تنشئ وتحذف جداول `probe_*` فقط، وتقرأ الاتصال من متغيرات `PERSISTENCE_TEST_MYSQL_*`.

**سلم الخطورة:** عالية = قد تُفقد بيانات أو يُكسر ثابت (invariant) أو يفشل المستهلك فور اتباع التوثيق؛ متوسطة = سلوك مفاجئ قابل للتجنب لمن يعرف؛ منخفضة = اتساق/نظافة. **الأولوية:** P1 قبل تثبيت دورة المعايير أو الإصدار القادم؛ P2 الدورة التالية؛ P3 تحسين.

---

## 3. خط الأساس (Baseline) — ما نجح فعليًا

| البوابة | النتيجة |
|---|---|
| `composer validate --strict` | صالح |
| PHPStan `level max` (`src` + `tests`) | `[OK] No errors` |
| PHP-CS-Fixer `--dry-run` | 0 من 93 ملفًا يحتاج تعديلًا |
| Unit | 362 اختبارًا / 2,524 assertion — OK |
| Regression | 81 اختبارًا / 856 assertion — OK |
| Integration (MySQL 8.4.10 حقيقي) | 177 اختبارًا / 1,042 assertion — OK (45 ث) |
| Consumer Verification Harness | تشغيلان نظيفان من جذر Composer منفصل بلا symlink — OK (44 ث) |
| سلامة الروابط النسبية في وثائق المستودع نفسه | 32 ملف `.md` (باستثناء نتائج الدراسات الخام): 0 رابط مكسور |

---

## 4. Runtime أو Contract Defects مثبتة

### RC-01 — `moveWithinScope()` يتعارض مع `UNIQUE (scope, order)` — **عالية / P1**

- **الأدلة:**
  - [`ScopedOrderingManager.php:445-449`](../../../src/Pdo/Ordering/ScopedOrderingManager.php) و[`:482-486`](../../../src/Pdo/Ordering/ScopedOrderingManager.php): `UPDATE … SET order = order ± 1 WHERE …` بلا `ORDER BY`. MySQL يفحص التفرد صفًا بصف أثناء الـ UPDATE، فيصطدم أول صف مُزاح بالصف التالي قبل تحديثه.
  - التجربة [`probes/p1_unique_index_move.php`](probes/p1_unique_index_move.php): جدول بفهرس `UNIQUE (scope_id, display_order)` وثلاثة صفوف. نقل الصف 3 إلى 1 → `1062 Duplicate entry '1-2'`؛ نقل الصف 1 إلى 3 → `1062 Duplicate entry '1-1'`؛ الصفوف دون تغيير بعد التراجع.
  - التوثيق يوصي بنفس الفهرس: [`NEXT_POSITION_ALLOCATION_HOW_TO_USE.md:160`](../../guides/NEXT_POSITION_ALLOCATION_HOW_TO_USE.md) ("rely on a `UNIQUE (scope, order)` index")، [`README.md:292`](../../../README.md)، وتعليق [`examples/next-position/03_stale_snapshot_vs_plain_read.php:16`](../../../examples/next-position/03_stale_snapshot_vs_plain_read.php) ("Use a UNIQUE index in real [schemas]").
  - فجوة الاختبار: كل Fixtures تتجنب `UNIQUE` صراحة ([`tests/Fixtures/MySql/*.sql`](../../../tests/Fixtures/MySql/) تعليقات الرأس: "no additional UNIQUE constraint is declared")، ولا يوجد اختبار حركة مع فهرس فريد.
  - لا ذكر لهذا التعارض في README أو المرجع أو الأدلة أو ADR 0002 أو وثيقة Ordering Phase (بحث `unique`).
- **الأثر:** مستهلك يتبع الدليل ويضع `UNIQUE` كشبكة أمان للإدراج المتزامن يحصل على `moveWithinScope()` معطّلة في الحالة المعتادة (ترتيب متتابع بلا فجوات: أي حركة فعلية تصطدم؛ لا تفشل عند no-op ولا حين تكون المواضع المزاحة فارغة بسبب فجوات). الفشل صريح لا صامت، لذلك الخطورة "عالية" لا "حرجة".
- **المصدر:** تصميم Runtime منذ خوارزمية v1.0 + تناقض توثيقي لاحق (الأدلة الجديدة أوصت بـ UNIQUE).
- **قرار مطلوب من المالك:** إما (أ) توثيق عدم التوافق فورًا وإزالة التوصية من الأدلة، أو (ب) تعديل Runtime (`UPDATE … ORDER BY order DESC/ASC` حسب الاتجاه — يحتاج إثباتًا على MySQL وتقييم أثره على lock footprint قبل الاعتماد؛ لم يُجرَّب هنا). (ب) تغيير سلوك مرئي فيحتاج مسار الموافقة المعماري (CONTRIBUTING: "Discussion First").

### RC-02 — فشل `moveWithinScope()` داخل معاملة المستدعي يترك حالة جزئية — **متوسطة–عالية / P1**

- **الأدلة:**
  - [`ScopedOrderingManager.php:239`](../../../src/Pdo/Ordering/ScopedOrderingManager.php): يستخدم `PdoTransactionRunner` الذي لا يفعل شيئًا عند وجود معاملة ("participates")، فلا savepoint ولا rollback جزئي. الإزاحة (`:256`/`:258`) تُنفَّذ قبل التحديث النهائي (`:261`).
  - التجربة [`probes/p2_joined_transaction_partial_state.php`](probes/p2_joined_transaction_partial_state.php): trigger يفشل تحديث الصف 3. بعد الاستثناء داخل معاملة المستدعي: `[{1:2},{2:3},{3:3}]` (ترتيب **مكرر**)، والمعاملة ما زالت نشطة؛ وإذا التقط المستدعي الاستثناء وأكمل `COMMIT` يثبت الترتيب المكسور.
  - الاختبار الحالي يثبّت هذه الحالة كسلوك مقصود: [`ScopedOrderingManagerTransactionTest.php:100-120`](../../../tests/Integration/Pdo/Ordering/ScopedOrderingManagerTransactionTest.php) (`[… 4, 4]`).
  - التوثيق يقول غير ذلك لغير المطّلع: [`README.md:311-312`](../../../README.md) ("Rolls back and returns `false` if the final target update fails" / "Rolls back on any Throwable after starting the transaction")، [`PERSISTENCE_PACKAGE_REFERENCE.md:114`](../../../PERSISTENCE_PACKAGE_REFERENCE.md)، [`ORDERING_NULLABLE_SCOPE_ATOMIC_TIMESTAMP_PHASE.md`](../../architecture/ORDERING_NULLABLE_SCOPE_ATOMIC_TIMESTAMP_PHASE.md) ("commit or roll back together"). هذه العبارات صحيحة فقط للمعاملة التي يملكها المدير.
  - مسار `return false` بعد الإزاحة (`:270-272`، `rowCount() === 0`) لا يمكن بلوغه عمليًا إلا بـ trigger يعيد القيمة القديمة؛ لكنه لو حدث داخل معاملة المستدعي لأرجع `false` (يُفهم "لم يتغير شيء") مع إزاحة مطبّقة.
- **المصدر:** قرار تصميم موثق جزئيًا (يشارك ولا يتراجع) + عبارات توثيق مطلقة. الحزمة نفسها تملك الأداة المناسبة (`PdoDeadlockRetrySavepointTransactionRunner`) ولا تستخدمها هنا.
- **الأولوية:** التوثيق P1 (تصحيح العبارات وتحذير صريح بأن على المستدعي ألا يتابع بعد استثناء). تغيير Runtime (savepoint داخلي عند الانضمام) خيار للمالك — قد يُعد متوافقًا لكنه يضيف SQL ويغيّر دلالة الأمر داخل معاملة المستدعي.

### RC-03 — الـ Ordering ليس fail-closed خارج `ERRMODE_EXCEPTION` — **متوسطة / P2**

- **الأدلة:**
  - [`probes/p3_error_modes_and_identifiers.php`](probes/p3_error_modes_and_identifiers.php) (قسم RC-03): تحت `ERRMODE_SILENT` و`ERRMODE_WARNING` ترمي `getNextPosition` و`rowExistsInScope` و`moveWithinScope` الخطأ الخام `Error: Call to a member function execute() on false`، أي ليس `PDOException` ولا `PersistenceException`.
  - قيم `execute()` المعادة مهملة في كل المدير: `ScopedOrderingManager.php` الأسطر 81، 319، 366، 410، 451، 488، 527؛ بينما [`PdoPaginator.php:56,150,168-171`](../../../src/Pdo/Pagination/PdoPaginator.php) و[`OwnedTransactionExecutor.php:104,133`](../../../src/Pdo/Transaction/Internal/OwnedTransactionExecutor.php) يفحصان ويرميان استثناءً مملوكًا للحزمة.
  - مسار الحركة الذي تملكه الحزمة يمر عبر `PdoTransactionRunner` المهجور الذي يتجاهل `false` من `beginTransaction()` (`:38`) و`commit()` (`:42`) — موثق كفارق مقصود، لكنه يبقي `moveWithinScope()` وحدها خارج سلوك fail-closed الذي يعلنه README:322 للـ runners الجدد.
  - لا ذكر لشرط وضع الأخطاء في README أو المرجع أو `SECURITY.md` (خارج النطاق: "PDO configuration … owned by the consuming application"). وحده المرجع يذكر شرط native prepares (`:482`) بلا تطبيق في الكود.
- **لم يثبت:** فساد بيانات صامت فعلي (الفشل الذي جربته يقع عند `prepare()` أصلًا). الخطر نظري لكنه ناتج مباشر عن غياب الفحص؛ لذلك صُنّف متوسطًا لا عاليًا.
- **المصدر:** Runtime (عدم اتساق بين المكونات) + Docs (شرط غير معلن).

### RC-04 — `moveWithinScope()` تعتمد داخليًا على صنف مهجور — **منخفضة / P3**

- [`ScopedOrderingManager.php:8,239`](../../../src/Pdo/Ordering/ScopedOrderingManager.php) تستورد وتستخدم `PdoTransactionRunner` (`@deprecated` في #44). مقبول صراحة في ADR 0004 (Consequences) والمرجع، لذا ليس مخالفة؛ لكنه يعني أن أحدث عملية بيانات في الحزمة تعمل بدلالات الجيل القديم (لا fail-closed على `BEGIN`/`COMMIT`، لا replay اختياري). مرتبط بـ RC-02/RC-03: أي معالجة لهما ستمر عبر هذا القرار.
- **المصدر:** قرار معماري موثق (ADR 0004).

### RC-05 — أنماط المعرّفات تقبل `\n` في النهاية — **منخفضة / P2**

- **الأدلة:** `'/^…$/'` بلا `D` أو `\z`: [`ScopedOrderingConfig.php`](../../../src/Pdo/Ordering/ScopedOrderingConfig.php) (`assertColumnIdentifier`/`assertTableIdentifier`)، [`SortWhitelist.php:11,58,82`](../../../src/Pdo/Pagination/SortWhitelist.php)، [`PaginationConfig.php:11,26,29`](../../../src/Pdo/Pagination/PaginationConfig.php)، [`PageResult.php:15`](../../../src/Pdo/Pagination/PageResult.php)، [`PdoPaginator.php:13-14`](../../../src/Pdo/Pagination/PdoPaginator.php)، [`PdoPaginationQueryDescriptor.php:11`](../../../src/Pdo/Pagination/PdoPaginationQueryDescriptor.php). التجربة (p3، قسم RC-05): `new ScopedOrderingConfig("t\n")` ينجح و`quotedTable()` يعيد "`` `t⏎` ``"؛ كذلك مفتاح `SortWhitelist` ومعرّفه.
- **الأثر:** لا استغلال (الناتج داخل backticks ولا يُنهي الاقتباس إلا backtick)، لكنه يخالف وعد الـ docblock ("Not allowed: spaces … SQL fragments") ويؤجل الفشل إلى SQL خطأ لاحق بدل `InvalidOrderingConfigurationException` فوري. الإصلاح ثانوي (`/D`) لكنه تغيير سلوك validation فيمر بقرار المالك.
- **المصدر:** Runtime (نمط regex).

### RC-06 — قيود موثقة وليست عيوبًا (للتأكيد فقط) — **معلومة**

مؤكدة بالتجربة أو بالقراءة وهي مطابقة للعقد: صفحة أبعد من الأخيرة تعيد الصفحة 1 بصمت (`PDO_PAGINATION_CONTRACT.md:435,714`؛ p3: `page 99 of 2 → 1`)؛ الاستعلامات الثلاثة في `paginate()` غير ذرية (العقد `:855-863`)؛ رفض أي `;` حتى داخل literal نصي مشروع (العقد §9.4، [`PdoPaginationQueryDescriptor.php:37`](../../../src/Pdo/Pagination/PdoPaginationQueryDescriptor.php)؛ جُرّب في scratch: `InvalidPaginationQueryException`). لا إجراء.

---

## 5. Documentation / Consumer Gaps

### DG-01 — Quick Usage يعتمد على واجهات غير موجودة في الإصدار المستقر — **عالية / P1**

- `git tag` يحوي حتى `v1.4.0` فقط؛ `git ls-tree -r v1.4.0` لا يحوي أي ملف `DeadlockRetry*` ولا `Internal/`، و`getNextPositionForUpdate` تظهر 0 مرة في `v1.4.0:src/Pdo/Ordering/ScopedOrderingManager.php`.
- [`README.md:62-107`](../../../README.md) (الـ Quick Usage الرئيسي) يستورد `PdoDeadlockRetryTransactionRunner` ويستدعي `getNextPositionForUpdate()` دون أي وسم Unreleased داخل الكتلة؛ الوسم موجود في أسطر بعيدة (`:37`, `:180`, `:269`, `:380`). كذلك قائمة "Public Runtime API" (`:236-264`) تخلط المستقر بـ Unreleased. قارئ README على الصفحة الافتراضية/Packagist يجرّب `composer require maatify/persistence` فيحصل على `Class not found`.
- المرجع يحمل وسم "Unreleased" لكل صنف (`:139,:237,:400`)، لكن README هو واجهة الدخول.
- يتناقض مع قاعدة [`docs/adr/README.md:11`](../../adr/README.md) ("Package Reference: Documents only the stable public Runtime API and released behavior") — المرجع يوثّق Unreleased صراحة.
- **المصدر:** عملية الإصدار (توثيق مدفوع بالفرع الافتراضي قبل التاغ) + فجوة معيارية SG-05.
- **ملاحظة:** سيزول بمجرد إصدار 1.5.0 إن حدث قبل أن يصل قارئ جديد؛ لكن النافذة الزمنية قائمة الآن، وأي تأخير يمدّدها.

### DG-02 — تناقض بين قاعدة حدود وثائق ADR وواقع المرجع — **منخفضة / P3**

كما في DG-01: `docs/adr/README.md:9-11` تعرّف المرجع كوثيقة للمستقر فقط، والمرجع الفعلي يوثّق Unreleased. أحدهما يحتاج تعديلًا (يُقترح تعديل القاعدة لا المرجع).

### DG-03 — الاستمرار بعد `1213` داخل معاملة المستدعي يتحول إلى كتابة autocommit صامتة — **عالية / P1**

- **الدليل المثبت** ([`probes/p4_deadlock_then_continue.php`](probes/p4_deadlock_then_continue.php) على deadlock حقيقي بين عمليتين): المستدعي يفتح معاملة ويكتب `caller-before`، يستدعي `PdoDeadlockRetrySavepointTransactionRunner` فيحدث `1213`، يلتقط الاستثناء ويتابع (وهذا بالضبط ما يغريه به نمط savepoint). النتيجة: `inTransaction()` يعيد `true` مباشرة بعد الخطأ، ثم `false` بعد الكتابة التالية؛ `caller-after` **يُثبَّت فورًا** بلا معاملة، `caller-before` ضاع، و`commit()` يرمي `There is no active transaction`. مرصود من اتصال آخر: `["caller-after"]`.
- **ما هو موثق:** تعليق رأس المثال [`examples/deadlock-retry/02_caller_owned_no_replay.php`](../../../examples/deadlock-retry/02_caller_owned_no_replay.php) يذكر أن `inTransaction()` قد يبقى `true`؛ والمرجع ([`:256`](../../../PERSISTENCE_PACKAGE_REFERENCE.md)) يقول "the caller decides". **غير موثق** في README/المرجع: العاقبة (كتابات لاحقة تُثبَّت خارج المعاملة)، وأن هذه الحالة تنطبق على أي `1213` داخل savepoint، وأن الاكتشاف الآمن يحتاج statement ناجحًا (مثل `DO 0`) ثم فحص الحالة.
- **الأثر:** فقدان ذرية صامت لأي مستهلك يلتقط الاستثناء من runner بنمط savepoint ويكمل. الـ savepoint نفسه يعد بعزل الفشل الجزئي، وهو في حالة `1213` لا يستطيع الوفاء به لأن InnoDB يلغي المعاملة كاملة.
- **المصدر:** وثائق (تحذير ناقص) + فرصة تحصين Runtime (مثلًا إعادة رمي استثناء مميّز أو علامة حين يتأكد الـ runner بعد `1213` أن المعاملة انتهت) — قرار للمالك لأنه يغيّر عقد استثناءات مسار موجود.

### DG-04 — شروط تهيئة PDO وقاعدة البيانات غير معلنة — **متوسطة / P2**

- **غير مذكور** في README/Requirements (`:44-52`): `ERRMODE_EXCEPTION` (RC-03)، تعطيل emulation (المذكور وحده في [المرجع `:482`](../../../PERSISTENCE_PACKAGE_REFERENCE.md) كـ "required" بلا مصدر في العقد (`grep emulat` في `PDO_PAGINATION_CONTRACT.md` = 0) ولا فرض في الكود)، `ext-pdo_mysql` (السلوك مرتبط بأكواد MySQL `1213` وبصيغة backtick و`DO 0`، وcomposer.json يطلب `ext-pdo` فقط ولا يقترح `pdo_mysql`)، والحد الأدنى لإصدار MySQL (CI على 8.4.10 فقط).
- التجربة p3 (قسم DG-04): نفس الـ named placeholder مرتين → `HY093` مع native prepares، ينجح مع emulation. العقد يقول إنه "caller contract" و`PACKAGE_BUILDING §20` يقول "PDO does not reliably support" التكرار — الصياغة الأدق: يعتمد على وضع الـ prepares؛ README لا يربط الفخ بالشرط.
- **المصدر:** Docs + Standards (SG-03).

### DG-05 — تسمية `PdoDeadlockRetry*` مع الافتراضي `0` — **منخفضة / P3**

الاستخدام الأول في README:85 (`new PdoDeadlockRetryTransactionRunner($pdo)`) لا يعيد أي محاولة. قارئ الاسم يتوقع retry. مقبول بقرار المالك (ADR 0004 §Decision 2)، لكن المثال الأول ينبغي أن يوضّح الافتراضي أو يعرض `maxDeadlockRetries` صراحة.

### DG-06 — حجم وهوية حزمة التوزيع — **منخفضة / P3**

`git archive HEAD` = 1,190 ملفًا، 12.2 MB، منها `docs/studies` وحده 10.9 MB (957 ملفًا، معظمها `.jsonl.gz` خام). لا `.gitattributes` على الجذر. مسموح حرفيًا بـ `COMPOSER_PACKAGE §26` ("Tests and documentation MAY remain") فلا مخالفة؛ لكن كل `composer install --prefer-dist` يحمّل أدلة دراسة لا يحتاجها مستهلك. (المستهلك في harness الحالي ينسخ الشجرة كاملة أيضًا.)

### DG-07 — ملاحظات متفرقة منخفضة

- `docs/adr/0002-…md` يذكر أن الواجهة الحالية ثلاث دوال (تاريخي بتاريخ 2026-07-11)؛ لا تعديل (سجل تاريخي) لكنه يحتاج إشارة "as of" إن قُرئ كحالة راهنة.
- `docs/studies/issue-32-retry-comparison/README.md`: الـ harness **يحذف ويعيد إنشاء قاعدة البيانات المسماة في DSN** عند كل تشغيل — موثق، لكنه فخ لمن ينسخ الأوامر؛ تحذير أبرز مستحسن.
- `CONTRIBUTING.md` لا يذكر `examples/` ولا `docs/studies/` ولا مسار Work Unit/Phase Stack رغم أن `AGENTS.md` يربط التسليم بها.

### Verification / CI gaps (ضمن الفئة نفسها)

- **VG-01 — قائمة residue في CI متقادمة** ([`ci.yml:285-290`](../../../.github/workflows/ci.yml) و[`:451-456`](../../../.github/workflows/ci.yml)): أربعة جداول فقط، بينما أضافت #44 الجدول `maa_persistence_test_deadlock_retry_items` ([`DeadlockRetrySchemaManager.php:12`](../../../tests/Support/MySql/DeadlockRetrySchemaManager.php)) بلا إدراجه. جداول الأمثلة (`maa_example_*`) مغطاة داخل `ExampleSchemaCleanupTest` فقط وليس بفحص CI المستقل. `CI_WORKFLOW §11` يطلب "prove cleanup and repeatability". **أولوية P2** (إضافة سطر؛ لكنه يدل على SG-09).
- **VG-02 — فجوات اختبار:** لا اختبار Ordering/Transaction تحت أوضاع أخطاء غير الاستثناء (الوحيدان `PdoPaginator…` عبر `ScriptedPdo`، و`PdoDeadlockRetryRunnerIntegrationTest` لـ `COMMIT`)؛ لا Fixture بفهرس فريد (RC-01)؛ لا اختبار يغطي الاستمرار بعد `1213` (DG-03).
- **VG-03 — الـ Consumer Harness يعمل في job `integration` فقط** (PHP 8.2 و8.5، تبعيات أحدث) وليس في `lowest-dependencies`؛ يطابق الحد الأدنى للمعيار، معلومة فقط.

---

## 6. Standards Gaps

> كلها اقتراحات مدخلات لدورة المعايير؛ لا شيء منها يُعدَّل هنا (المجموعة مثبتة وفق Manifest).

| # | الفجوة | الدليل | مقترح | أولوية |
|---|---|---|---|---|
| **SG-01** | لا معيار يغطي deadlock / lock wait / retry / isolation / idempotency. `PACKAGE_BUILDING §25` يطلب تحديد مالك المعاملة وحدود التزامن فقط. اضطرت الحزمة لـ ADR 0004 و"Owner-accepted limit" ارتجاليًا. | `grep -i "deadlock\|retry\|1213\|lock wait\|isolation"` على `packages/` و`testing/` و`ai/` و`STANDARDS_ADOPTION` لا يعيد سوى "isolation" بمعنى Git. | قسم في `PACKAGE_BUILDING §25` (أو معيار جديد): من يملك إعادة المحاولة؟ إعلان مستوى العزل المدعوم ومصفوفة اختباره؟ شروط الـ idempotency؟ حدود "unsupported usage" المقبولة؟ | **P1** |
| **SG-02** | تعارض نصي: §7 بنود 7–8 ("Blind catch-all wrapping is forbidden"، "Swallowing errors is forbidden") مع نمط المعاملة في §7 الذي لا يعالج فشل `rollBack()` نفسه، بينما الحزمة تبتلع فشل التنظيف عمدًا لتحفظ الاستثناء الأول ([`PdoTransactionRunner.php:58-61`](../../../src/Pdo/Transaction/PdoTransactionRunner.php)، [`OwnedTransactionExecutor.php:215-218`](../../../src/Pdo/Transaction/Internal/OwnedTransactionExecutor.php)، [`PdoDeadlockRetrySavepointTransactionRunner.php:127-136`](../../../src/Pdo/Transaction/PdoDeadlockRetrySavepointTransactionRunner.php)). | `grep` لا يجد في §7 معالجة لفشل `rollBack()` داخل catch | استثناء صريح: "فشل ثانوي أثناء cleanup يُقمع بشرط التوثيق، والأول يربح"، مع خيار `previous`/suppressed. | P1 |
| **SG-03** | لا اشتراط لإعلان/فحص سمات PDO المطلوبة (`ERRMODE`، `EMULATE_PREPARES`، `ext-pdo_<driver>`)، و§20 يعمّم "PDO لا يدعم تكرار الـ placeholder" بينما الحقيقة تعتمد على وضع الـ prepares (p3). | RC-03، DG-04 | إلزام الحزمة بإعلان السمات + fail-closed أو تحقق؛ تصحيح صياغة §20. | P2 |
| **SG-04** | "MySQL/MariaDB-compatible" كملف افتراضي مقابل "PDO driver availability alone does not establish database support": لا قاعدة تحدد هل ادعاء التوافق يستلزم CI على كل محرك، ولا إعلان حد أدنى للإصدار. الحزمة تصرّح بأن MariaDB غير مفحوصة (مرجع `:23`, `schema/README.md`, `AGENTS.md`) — صادق لكن المعيار غامض. | | تعريف مستويات الادعاء: Verified / Compatible-unverified / Unsupported، وشرط إعلان الحد الأدنى. | P2 |
| **SG-05** | لا قاعدة لتوثيق ميزات Unreleased على الفرع الافتراضي: `LIBRARY_PRESENTATION §12/§15` ("Do not describe future changes as already implemented") لا تحدد ما يُسمح به في Quick Usage قبل التاغ. | DG-01، DG-02 | "Quick Usage يستهدف آخر إصدار منشور؛ الميزات Unreleased تُعزل في قسم موسوم" أو بوابة تحقق آلية. | **P1** |
| **SG-06** | لا معيار لدورة الـ Deprecation (إشعار docblock فقط؟ `E_USER_DEPRECATED`؟ استخدام داخلي لصنف مهجور؟)؛ `§14.6` يحمي Major فقط. | RC-04 | قاعدة: ما الذي يُعد "deprecation كامل" وهل يجوز الاستخدام الداخلي. | P3 |
| **SG-07** | روابط نسبية مكسورة في الملفات المثبتة ذاتها: 4 روابط تشير لملفات غير مثبتة (`governance/STANDARD_VERSIONING_POLICY_AR.md` ×3 في Adoption وAI Collaboration، و`../modules/MODULE_BUILDING_STANDARD.md` في Package Building). معيار الاعتماد §8 يشترط "سلامة الروابط" وفي الوقت نفسه §5.1 يقول إن الـ Consumer لا ينسخ سياسة الإصدارات. وتقرير الـ Manifest "تم التحقق byte-for-byte" غير قابل لإعادة التحقق من داخل المستودع (§16 يمنع إنشاء Resolver). | [`probes/check_links.py`](probes/check_links.py): 4 روابط مكسورة من 36 ملف `.md` | تحديد سياسة الروابط إلى ملفات غير معتمدة (إزالة/استبدال بـ URL مثبت بـ commit)، وتوفير أداة تحقق مرجعية (أو قبول أن التحقق يدوي ويوثق أمره). | P2 |
| **SG-08** | لا إرشاد لحجم/نظافة حزمة التوزيع عند وجود أدلة خام ضخمة (§26 يسمح ببقاء الوثائق والاختبارات دون سقف). | DG-06 | إرشاد `export-ignore` للأدلة أو سقف حجم. | P3 |
| **SG-09** | `CI_WORKFLOW §11` يطلب إثبات التنظيف دون تحديد كيف تُشتق قائمة الكائنات؛ القائمة اليدوية انجرفت (VG-01). | VG-01 | اشتقاق الفحص من بادئة موحدة أو من مصدر الـ Fixtures الوحيد. | P2 |
| **SG-10** | `PACKAGE_BUILDING §16` (الذي تتبعه حزم المستهلكين) يوجّه `display_order` إلى "stable Ordering API" دون تمييز `getNextPosition` عن `getNextPositionForUpdate` (فخ الـ snapshot الافتراضي REPEATABLE READ)، ولا ذكر لتعارض `UNIQUE` (RC-01). كل Module يتبع §16 يرث هذه الفجوات. | | إضافة إرشاد: أيّ قراءة للتخصيص داخل معاملة، وقيود الفهارس. | P2 |
| **SG-11** | `TESTING §3.4` يطلب "suitable concurrency verification" دون مصفوفة عزل؛ سلوك الحزمة يختلف بين REPEATABLE READ وREAD COMMITTED، واختبار READ COMMITTED موجود في ملفين فقط. | `grep -c "READ COMMITTED" tests` | يُضم إلى SG-01 كمطلب مصفوفة عزل. | P2 |

**ما لا يُعد فجوة:** `composer.json`/CI/AGENTS/Manifest متسقة مع المعايير المثبتة (إصدارات الـ 7 معايير في Manifest = ما في رؤوس الملفات؛ `platform.php` = 8.2.0 كما يطلب §23.3؛ الأفعال مثبتة بـ SHA؛ العزل والتكرار مثبتان في CI). الاستثناء الوحيد المتعلق بالتسمية (`PersistenceException`) موثق بالشكل المطلوب في §7 Legacy Published Marker Names.

---

## 7. مخاطر تحتاج إثباتًا إضافيًا

| # | الخطر | لماذا لا يُحسم الآن | الإثبات المطلوب |
|---|---|---|---|
| R-1 | **MariaDB**: ادعاء "MySQL/MariaDB-compatible" بلا CI. الاختلافات المحتملة: سلوك `inTransaction()` بعد `1213`، صياغة `DO 0`، قفل الفجوات. | لم تُشغَّل أي مجموعة على MariaDB هنا. | تشغيل Integration + Consumer على `mariadb:11` و`10.11` (صور `mariadb` متوفرة في Docker المحلي) أو تحديث الادعاء إلى Unverified صراحة في العناوين. |
| R-2 | **اعتماد تحديد الـ replay على سلوك داخلي لـ pdo_mysql**: `inTransaction()` يبقى `true` بعد خطأ (ADR 0004 + p4). قد يتغير بين mysqlnd/libmysqlclient أو إصدارات PHP. | المثبت على PHP 8.4.4/mysqlnd/MySQL 8.4.10 فقط؛ CI يغطي 8.2 و8.5 للتكامل. | تشغيل مجموعة الـ deadlock على 8.3/8.4 وعلى بناء libmysqlclient، وتوثيق المصفوفة. |
| R-3 | **بدائل MySQL المتجمعة/وسيطة** (Galera، Group Replication، ProxySQL): أكواد 1213/40001 قد تُرجَع عند `COMMIT` (certification failure) فلا يُعاد التنفيذ بتصميم الـ runner. | لا تجربة. | اختبار على Galera/ProxySQL أو توثيق "غير مدعوم". |
| R-4 | **أداء `lockScopeForUpdate`**: يقفل ويقرأ كل صفوف النطاق لكل حركة (O(scope))؛ التغيير في #33 أبقى الخطة نفسها لكن أُثبت على Fixture صغير فقط. | `CHANGELOG` نفسه لا يعمّم الأداء. | قياس على نطاق ≥100k صف وفهرس `(scope, order)`، مع زمن القفل وتنافس الحركات المتوازية. |
| R-5 | **حلقة `getNextPositionForUpdate()` بلا سقف** (`:174-187`): تنتهي عندما يتكرر نفس الـ tail مرتين. عمليًا محدودة (القفل على الـ tail يمنع المنافسين الملتزمين بالقفل) لكن الكتّاب بلا locking reads قد يطيلونها. | لم يُحاكَ حمل. | اختبار تنافس بكتّاب بلا قفل تحت READ COMMITTED مع قياس عدد التكرارات. |
| R-6 | **سلوك `lock_wait_timeout` (1205) داخل `moveWithinScope`**: خطأ على مستوى الجملة فقط (ما لم يُفعَّل `innodb_rollback_on_timeout`)؛ تحت RC-02 يعني حالة جزئية في معاملة المستدعي. | يعتمد على إعداد الخادم. | اختبار مع `innodb_rollback_on_timeout` على/خارج. |
| R-7 | **التمسك بـ `autocommit=0`**: جرّبته (p5) فلم يظهر عيب (السلسلة `SET autocommit=0` + SELECT لا تجعل `inTransaction()` صحيحًا، والـ runner يلتزم)؛ يبقى غير مغطى لمسار ADR 0004 M4 (DDL) المقبول من المالك. | مغلق عمليًا للحالة الاعتيادية. | لا إجراء — مسجل كنتيجة سلبية. |

---

## 8. ما فُحص ووُجد سليمًا

- منطق `OwnedTransactionExecutor`: ترتيب `BEGIN` → callback → `COMMIT`، fail-closed على `false`، شروط الـ replay الأربعة، التأخيرات (0 / 0–10 / 0–20 ms)، ومنع الـ replay داخل معاملة المستدعي. لا ثغرة في المسار المدعوم.
- أسماء الـ savepoint (`random_bytes(16)` + ثابت)، أسبقية استثناء الـ callback على فشل التنظيف، سلوك `RELEASE` الفاشل.
- `PdoPaginator`: تطبيع الأعداد الصحيحة بحدود PHP_INT، رفض شكل الـ count، تحقق `PageResult` من الثوابت، `LIMIT/OFFSET` كـ `PARAM_INT`.
- `ScopedOrderingManager`: ربط كل القيم بـ prepared statements، المعرّفات من `ScopedOrderingConfig` فقط، قفل النطاق بترتيب ثابت، حقيقة أن clamp مشتق من نفس القراءة القافلة (#33).
- تشغيل أمثلة المستودع كاختبارات (Examples tests) وداخل الـ Consumer Harness؛ لا انجراف بين الأدلة والأمثلة المتاحة.
- CI: أفعال مثبتة بـ SHA، `permissions: contents: read`، فحص actionlint بـ checksum، `CI Gate` بتجميع مستقر، تشغيل Integration مرتين + residue + `git diff --exit-code`.

---

## 9. ترتيب الأولويات المقترح (لم يُنفَّذ)

1. **P1 — قرارات المالك:** RC-01 (أي مسار: توثيق أم Runtime)، DG-01 (سياسة README قبل التاغ)، DG-03 (تحذير + قرار تحصين)، RC-02 (تصحيح العبارات + قرار savepoint)، SG-01/SG-02/SG-05 (مدخلات المعايير).
2. **P2 — الدورة التالية:** RC-03، RC-05، DG-04، VG-01/VG-02، SG-03/04/07/09/10/11، R-1، R-2.
3. **P3 — تحسينات:** RC-04، DG-02/05/06/07، SG-06/08، R-3..R-6.

لم يُجرَ أي تغيير على Runtime أو العقود أو المعايير. الإضافة الوحيدة في هذا الفرع هي هذه الوثيقة وملفات `probes/`.

---

## 10. إضافات لاحقة (المرحلة الثانية، دراسة فقط)

- [`ADDENDUM-RC01-RC02.md`](ADDENDUM-RC01-RC02.md): حلول دعم `moveWithinScope()` مع `UNIQUE (scope, order)` ونتائج RED/GREEN، وأثرها على RC-02، وتوصية هندسية واحدة. تضيف اكتشافًا جانبيًا RC-07 (أخطاء `1213` بين حركات متزامنة على نطاق واحد في النسخة المشحونة نفسها).
- [`DG03-ASSESSMENT.md`](DG03-ASSESSMENT.md): تقييم مستقل لـ DG-03 (ما يمكن منعه داخل المكتبة وما يُلزم به المستهلك).
