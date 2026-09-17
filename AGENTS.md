# تعليمات مستودع maatify/persistence

## التفعيل المعياري

قبل التخطيط أو التنفيذ أو المراجعة، يجب قراءة [ملف معيار الاعتماد المثبت](docs/php-engineering-standards/standards/STANDARDS_ADOPTION_STANDARD_AR.md) كاملًا، ثم قراءة المعايير المنطبقة المسجلة في [STANDARDS_MANIFEST.md](docs/php-engineering-standards/STANDARDS_MANIFEST.md) بحسب نطاق المهمة.

يسجل [STANDARDS_MANIFEST.md](docs/php-engineering-standards/STANDARDS_MANIFEST.md) مجموعة الاعتماد المحلية ونتيجة الحل، ولا يجوز استخدام مرجع upstream عائم أو نسخ معايير إضافية خارج المجموعة المسجلة.

## قواعد خاصة بالمشروع

- هذه الحزمة مكتبة Composer مستقلة، framework-agnostic وhost-agnostic، وتستخدم PDO المباشر مع MySQL/MariaDB-compatible SQL.
- يجب الحفاظ على عقد v1.4.0 العام، بما في ذلك ملكية المعاملات، ودعم المعاملة الخارجية، ودلالات savepoint المعتمدة؛ لا يُجرى تغيير breaking public API.
- اختبارات قاعدة البيانات تتطلب MySQL حقيقيًا؛ لا يجوز استخدام SQLite أو mocks لإثبات سلوك persistence الحقيقي.
- لا يُتتبّع composer.lock، ولا يُضاف حقل Composer باسم version، ولا تُنشأ Tag أو GitHub Release ضمن أعمال هذا المستودع ما لم يصدر تصريح صريح بذلك.
- يجب أن تبقى تغييرات Work Unit داخل نطاقها، وأن تمر عبر بوابات التحقق الفعلية الموثقة في [CONTRIBUTING.md](CONTRIBUTING.md) قبل النشر أو فتح Pull Request.

## تعليمات إضافية

لا توجد ملفات AGENTS.md إضافية خاصة بمسارات فرعية في هذا المستودع.
