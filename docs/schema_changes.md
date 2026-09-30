# تقرير مراجعة قاعدة البيانات والتعديلات الموثقة

المرجع: `docs/reference/stationery_schema.sql` (نسخة التصميم المستلمة، محفوظة كما هي).
التنفيذ: `database/migrations/2026_09_30_0001xx … 0008xx`.

## 1. نتيجة اختبار الاستيراد الفعلي

| البند | النتيجة |
|---|---|
| إصدار MySQL المستخدم | 8.0.46 (Ubuntu 24.04). أعلى من الحد 8.0.16 المطلوب لتفعيل CHECK فعليًا |
| استيراد ملف SQL على قاعدة فارغة (`schema_probe`) | نجح بدون أخطاء ولا تحذيرات |
| الجداول المنشأة | 34 |
| Foreign Keys الفعلية | 75 (بما فيها المفاتيح المركبة والمفتاح المضاف بـ ALTER) |
| CHECK constraints الفعلية | 33 |

اختبارات سلوك أُجريت على القاعدة المستوردة وكلها رفضت البيانات المخالفة كما هو مطلوب:

- `business_settings` برقم غير 1 → مرفوض (`business_settings_chk_1`).
- `tax_codes.rate = 150` → مرفوض (`tax_codes_chk_1`).
- `stock_balances.quantity = -5` → مرفوض (`stock_balances_chk_1`) — منع المخزون السالب مضمون على مستوى القاعدة.
- سطر مستند يربط `item_unit_id` بوحدة تنتمي لصنف آخر → مرفوض بالمفتاح المركب `(item_unit_id, item_id) → item_units(id, item_id)`.
- عدة مستندات مسودة بـ `document_number IS NULL` → مقبولة (UNIQUE في MySQL يسمح بتعدد NULL)، أي أن الرقم النهائي يخصص عند الإصدار فقط كما تصف خريطة المشروع.

بعد تحويل الهيكل إلى migrations، قارنّا القاعدتين آليًا: **لا جدول ولا عمود ولا نوع بيانات مفقود أو مختلف** بين ملف التصميم والقاعدة التي تنتجها migrations. القاعدة الحالية 43 جدولًا = 34 جدول التصميم + 7 جداول تشغيل Laravel + `account_mappings` + `migrations`.

## 2. التعديلات على الهيكل وأسبابها

لا يوجد أي حذف أو تغيير لتصميم الأعمال. كل ما يلي إضافات، ومعلّمة بنفس الرمز داخل ملفات migrations.

| الرمز | التعديل | السبب |
|---|---|---|
| D1 | `users.updated_at`, `users.remember_token`, `items.updated_at`, `parties.updated_at`, `documents.updated_at`, `journal_entries.created_at`, `payments.created_at`, `payments.posted_at` | Laravel يكتب `updated_at` تلقائيًا عبر Eloquent، وجلسات «تذكّرني» تحتاج `remember_token`. أعمدة التوقيت المضافة nullable ولا تغير أي منطق |
| D2 | جداول `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens` | تشغيل Laravel: جلسات آمنة في القاعدة، Queue للمهام المؤجلة (الإبلاغ الإلكتروني)، تخزين مؤقت. ليست جداول أعمال |
| D3 | جدول `account_mappings` (slug, label_ar, account_id, is_required) | المتطلب صريح: ربط حسابات التشغيل (إيراد، مخرجات، مدخلات، تكلفة مبيعات، تحكم مبيعات الكاشير، فرق تقريب، تلف، فروقات جرد، فرق تكلفة مرتجع شراء، حقوق ملكية، افتتاحي) بالحسابات المحاسبية من شاشة إعداد، بدون أرقام حسابات ثابتة داخل الكود |
| D4 | أعمدة على `business_settings`: `phone`, `email`, `logo_path`, `receipt_note_ar`, `invoice_note_ar`, `rounding_decimals`, `rounding_mode`, `fiscal_year_start_month`, `setup_completed_at` + CHECK للنطاقات + CHECK «مسجل ضريبيًا ⇒ يوجد رقم ضريبي» | التصميم المستلم لا يحمل بيانات الطباعة والشعار ولا سياسة التقريب ولا بوابة إتمام الإعداد، وكلها مطلوبة في شاشة الإعداد الأولي |
| D5 | عمود مُولّد `cash_sessions.open_marker` + UNIQUE | «صندوق واحد مفتوح لكل حساب نقدي» كانت قاعدة خدمة فقط؛ صارت مضمونة بالقاعدة أيضًا |
| D6 | عمود مُولّد `stock_counts.active_marker` + UNIQUE | «جلسة جرد نشطة واحدة لكل مخزن» (تجميد الحركات في النسخة الأولى) مضمونة بالقاعدة |
| D7 | `document_tax_totals` UNIQUE (document_id, category, rate) | يمنع تكرار صف تجميع الضريبة لنفس التصنيف والنسبة عند إعادة تشغيل الترحيل |
| D8 | `payments.fee_amount`, `payments.fee_account_id` + CHECK (رسوم فقط على التحويل، وأقل من المبلغ، وتحتاج حسابًا) | تسوية الشبكة ورسومها مطلوبة، ولم يكن في التصميم مكان لتسجيل الرسم مع الحفاظ على قيد متوازن |
| D9 | `document_lines.tax_code_id` (nullable FK) | لقطة كود الضريبة المستخدم وقت الإصدار، لتقارير VAT حسب الكود دون إعادة الحساب من بطاقة الصنف |
| D13 | `users.role` ENUM('owner','accountant','cashier') | صلاحيات مطبقة على السيرفر حتى مع مستخدم واحد |
| D14 | `accounting_periods` UNIQUE (starts_on) + CHECK (closed_at >= opened_at على الصندوق) | حماية إضافية؛ منع تداخل الفترات بالكامل يبقى في `PeriodService` لأن MySQL لا يعبّر عن نطاقات غير متداخلة إعلاميًا |

## 3. قواعد لا يضمنها SQL وحده (تُنفّذ في Services)

هذه ليست نقصًا في الهيكل بل حدودًا للغة SQL؛ مكان تنفيذها محدد في الكود:

- توازن المدين والدائن لكل قيد → `JournalPostingService`.
- عدم تداخل الفترات المحاسبية → `PeriodService`.
- المرتجع لا يتجاوز الكمية الباقية بعد المرتجعات المرحلة والمعلقة → `ReturnService` بقفل سطر الأصل.
- المتاح للبيع = الرصيد ناقص الحجوزات النشطة → `StockPostingService` بقفل الأرصدة بترتيب `warehouse_id` ثم `item_id`.
- `base_quantity = quantity × to_base_factor` ومطابقة إجماليات الرأس مع السطور → `CalculationService`.
- وصفة الخدمة تستهلك أصناف `stock` فقط، والوحدة الأساسية معاملها 1 → `CatalogService`.
- منع تعديل أو حذف المستند أو القيد أو الحركة بعد الترحيل → خدمات التطبيق + حساب قاعدة بيانات بلا DDL.

## 4. حساب قاعدة البيانات

| الحساب | الصلاحيات | الاستخدام |
|---|---|---|
| `stationery_app` | SELECT, INSERT, UPDATE, DELETE, EXECUTE, SHOW VIEW, CREATE TEMPORARY TABLES على `stationery` و`stationery_test` فقط | حساب التطبيق. **بلا DROP أو ALTER أو CREATE أو إدارة مستخدمين** |
| `stationery_admin` | ALL على `stationery` و`stationery_test` | تشغيل migrations واختبارات التكامل فقط (`--database=mysql_admin`) |

كلمتا المرور مولّدتان عشوائيًا ومحفوظتان في `.env` غير المتعقّب في git. `.env.example` لا يحتوي أي قيمة فعلية.

## 5. ما لم يُتحقق منه بعد في هذه المرحلة

- سلوك الأقفال والتزامن تحت حمل فعلي (يُختبر في مرحلة محرك المخزون والترحيل).
- أي شيء يتعلق بقبول زاتكا: لا توجد بيانات ربط، وطبقة التكامل ستعمل بـ mock موسوم.

## 6. مرحلة تطبيق الوحدات (مرتجعات، جرد، كاشير، مصروفات، تقارير، فوترة إلكترونية)

لم يتغيّر الهيكل (migrations) في هذه المرحلة إطلاقًا؛ كل ما بُني فوق نفس الجداول
الموصوفة أعلاه. أهم القواعد المطبَّقة في طبقة الخدمات (Services) دون أي DDL جديد:

- **مرتجع الشراء/البيع** يُصرف/يُستقبل المخزون بتكلفة السطر الأصلي (`unit_cost_snapshot`
  على السطر المرجعي)، لا بمتوسط التكلفة الحالي — عبر `forcedUnitCost` في
  `StockPostingService::issue()` ونفس المبدأ في `receive()`. مرتجع الشراء وحده يحتاج
  حساب فرق (`purchase_return_cost_difference`) لأن قيمته التجارية قد تختلف عن تكلفته
  الأصلية؛ مرتجع البيع لا يحتاج فرقًا لأن الإيراد والتكلفة مستقلان محاسبيًا أصلًا.
- **الكمية القابلة للإرجاع** = كمية السطر الأصلي ناقص كل ما أُرجع منه سابقًا بمستندات
  مرتجع مرحّلة (`PurchaseReturnController`/`SalesReturnController::buildAndValidateLines`)
  — تحقق على مستوى التطبيق، لا قيدًا في القاعدة.
- **جلسة الجرد** تُجمّد حركة المخزن بالكامل (`StockFreezeGuard` يبحث عن `stock_counts`
  بحالة `draft`/`counting`)، وتُعتمد عبر مستند `stock_adjustment` واحد بسبب `stock_count`
  يُمرَّر بـ `allowDuringFreeze = true` حتى لا يحجب الجردُ نفسه اعتماد نتيجته.
- **إقفال الفترة المحاسبية** يرفض الآن أي فترة بها مستندات `draft` بتاريخ ضمنها
  (`PeriodService::close`) — تحقق تطبيقي جديد لا عمود جديد؛ استعلام على `documents`
  وقت الإقفال فقط.
- **الفوترة الإلكترونية طبقة محاكاة (mock) بالكامل** خلف `EInvoicingProvider` interface:
  لا اتصال شبكي حقيقي، لا شهادات، لا onboarding فعلي مع أي جهة. `MockEInvoicingProvider`
  هي التطبيق الوحيد المربوط في `AppServiceProvider`. نتيجة "غير معروفة"
  (`connection_error`/`unknown`) لا تتحول أبدًا تلقائيًا إلى "مرفوضة"، ولا تُبطل أي
  مستند تجاري ولا تُحرِّر أي حجز مخزون — تبقى معلّقة لإعادة المحاولة (`retry`) على نفس
  سجل `einvoice_documents` ونفس `document_id` الأصلي.
- **التقارير** (`ReportService`) تُقرأ كلها من نفس مصادر الحقيقة المستخدمة في الترحيل
  (`journal_lines`, `stock_movements`, `documents`, `document_tax_totals`) ولا تُخزَّن
  في أي جدول ملخّص جديد، فلا يمكن أن يختلف تقرير عمّا رُحِّل فعلًا.
