# قاموس قاعدة بيانات المكتبة

الإصدار 1 — 34 جدولًا. MySQL 8.0.16 أو أحدث، مع تفضيل بيئة MySQL 8.4 عند التنفيذ.

التصميم لمنشأة واحدة، عملة SAR ومخزن واحد في البداية. الجداول تسمح بإضافة حساب آخر لاحقًا، ولا تحتوي على نظام تعدد شركات. الحقول المالية والكمية بدقة ست منازل لتقليل فقد دقة الحساب؛ التقريب الفعلي حسب سياسة الحساب ومتطلبات المستند.

لا توجد بيانات تشغيل أو نسب ضريبية افتراضية أو كلمة مرور. يجب إعداد دليل الحسابات والضرائب والأرصدة الافتتاحية قبل التشغيل.

## users

الحساب وتسجيل الدخول

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `name` | `VARCHAR(120) NOT NULL` | الاسم |
| `email` | `VARCHAR(190) NOT NULL` | الدخول |
| `password_hash` | `VARCHAR(255) NOT NULL` | هاش كلمة المرور |
| `is_active` | `BOOLEAN NOT NULL DEFAULT TRUE` | حالة الحساب |
| `created_at` | `DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)` | وقت الإنشاء |

**قيود إضافية:** `UNIQUE (email)`

## business_settings

منشأة واحدة وبياناتها الرسمية

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `TINYINT UNSIGNED NOT NULL PRIMARY KEY` | سجل وحيد رقمه 1 |
| `legal_name` | `VARCHAR(255) NOT NULL` | الاسم القانوني |
| `trade_name` | `VARCHAR(255) NULL` | الاسم التجاري |
| `vat_registered` | `BOOLEAN NOT NULL DEFAULT FALSE` | مسجلة في الضريبة |
| `vat_number` | `VARCHAR(30) NULL` | الرقم الضريبي |
| `registration_number` | `VARCHAR(50) NULL` | السجل |
| `address_json` | `JSON NULL` | العنوان الوطني المنظم |
| `currency` | `CHAR(3) NOT NULL DEFAULT 'SAR'` | العملة |
| `timezone` | `VARCHAR(60) NOT NULL DEFAULT 'Asia/Riyadh'` | توقيت العرض |
| `einvoice_phase` | `ENUM('none','phase1','phase2') NOT NULL DEFAULT 'none'` | مرحلة التطبيق |
| `updated_at` | `DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)` | التعديل |

**قيود إضافية:** `CHECK (id = 1)`

## accounts

شجرة الحسابات

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `parent_id` | `BIGINT UNSIGNED` | ref:accounts.id |
| `code` | `VARCHAR(30) NOT NULL` | كود الحساب |
| `name` | `VARCHAR(180) NOT NULL` | اسم الحساب |
| `account_type` | `ENUM('asset','liability','equity','revenue','expense') NOT NULL` | المجموعة |
| `allow_posting` | `BOOLEAN NOT NULL DEFAULT TRUE` | حساب تفصيلي |
| `is_active` | `BOOLEAN NOT NULL DEFAULT TRUE` | نشط |

**قيود إضافية:** `UNIQUE (code)`

## accounting_periods

إقفال الفترات

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `name` | `VARCHAR(100) NOT NULL` | الفترة |
| `starts_on` | `DATE NOT NULL` | من |
| `ends_on` | `DATE NOT NULL` | إلى |
| `status` | `ENUM('open','closed') NOT NULL DEFAULT 'open'` | الحالة |
| `closed_by` | `BIGINT UNSIGNED` | ref:users.id |
| `closed_at` | `DATETIME(6) NULL` | الإقفال |

**قيود إضافية:** `CHECK (ends_on >= starts_on)`

## tax_codes

تعريف المعالجة الضريبية

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `code` | `VARCHAR(30) NOT NULL` | كود |
| `name` | `VARCHAR(120) NOT NULL` | اسم |
| `category` | `VARCHAR(10) NOT NULL` | تصنيف زاتكا بحسب المواصفات |
| `rate` | `DECIMAL(7,4) NOT NULL` | نسبة مئوية |
| `exemption_reason_code` | `VARCHAR(50) NULL` | سبب الإعفاء |
| `exemption_reason` | `VARCHAR(255) NULL` | الوصف |
| `valid_from` | `DATE NOT NULL` | بداية السريان |
| `valid_to` | `DATE NULL` | نهاية السريان |
| `is_active` | `BOOLEAN NOT NULL DEFAULT TRUE` | نشط |

**قيود إضافية:** `UNIQUE (code)`؛ `CHECK (rate >= 0 AND rate <= 100)`؛ `CHECK (valid_to IS NULL OR valid_to >= valid_from)`

## categories

تصنيفات السلع والخدمات

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `parent_id` | `BIGINT UNSIGNED` | ref:categories.id |
| `name` | `VARCHAR(120) NOT NULL` | الاسم |

**قيود إضافية:** العلاقات المرجعية الموضحة أعلاه

## units

وحدات القياس

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `code` | `VARCHAR(20) NOT NULL` | كود |
| `name` | `VARCHAR(80) NOT NULL` | الاسم |
| `einvoice_code` | `VARCHAR(20) NULL` | كود الوحدة الإلكتروني |

**قيود إضافية:** `UNIQUE (code)`

## items

السلع والخدمات

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `sku` | `VARCHAR(60) NOT NULL` | الكود الداخلي |
| `name_ar` | `VARCHAR(255) NOT NULL` | اسم عربي |
| `name_en` | `VARCHAR(255) NULL` | اسم إنجليزي |
| `item_type` | `ENUM('stock','service') NOT NULL` | نوع |
| `category_id` | `BIGINT UNSIGNED` | ref:categories.id |
| `base_unit_id` | `BIGINT UNSIGNED NOT NULL` | ref:units.id |
| `tax_code_id` | `BIGINT UNSIGNED` | ref:tax_codes.id |
| `reorder_level` | `DECIMAL(19,6) NOT NULL DEFAULT 0` | حد الطلب بوحدة أساسية |
| `last_purchase_cost` | `DECIMAL(19,6) NULL` | آخر شراء ليس متوسط التكلفة |
| `revenue_account_id` | `BIGINT UNSIGNED` | ref:accounts.id |
| `inventory_account_id` | `BIGINT UNSIGNED` | ref:accounts.id |
| `cost_account_id` | `BIGINT UNSIGNED` | ref:accounts.id |
| `is_active` | `BOOLEAN NOT NULL DEFAULT TRUE` | إيقاف بدل حذف |
| `created_at` | `DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)` | إنشاء |

**قيود إضافية:** `UNIQUE (sku)`؛ `CHECK (reorder_level >= 0)`؛ `CHECK (last_purchase_cost IS NULL OR last_purchase_cost >= 0)`

## item_units

وحدات الصنف وأسعارها

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `item_id` | `BIGINT UNSIGNED NOT NULL` | ref:items.id |
| `unit_id` | `BIGINT UNSIGNED NOT NULL` | ref:units.id |
| `to_base_factor` | `DECIMAL(19,6) NOT NULL` | عدد الوحدات الأساسية |
| `sale_price` | `DECIMAL(19,6) NOT NULL DEFAULT 0` | سعر بيع الوحدة |
| `price_includes_tax` | `BOOLEAN NOT NULL DEFAULT TRUE` | السعر شامل |
| `is_active` | `BOOLEAN NOT NULL DEFAULT TRUE` | نشط |

**قيود إضافية:** `UNIQUE (item_id, unit_id)`؛ `UNIQUE (id, item_id)`؛ `CHECK (to_base_factor > 0)`؛ `CHECK (sale_price >= 0)`

## item_barcodes

باركود لكل وحدة

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `item_unit_id` | `BIGINT UNSIGNED NOT NULL` | ref:item_units.id |
| `barcode` | `VARCHAR(100) NOT NULL` | الباركود |

**قيود إضافية:** `UNIQUE (barcode)`

## service_recipe_lines

استهلاك اختياري للخامات لكل وحدة خدمة

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `service_item_unit_id` | `BIGINT UNSIGNED NOT NULL` | ref:item_units.id |
| `material_item_id` | `BIGINT UNSIGNED NOT NULL` | ref:items.id |
| `base_quantity` | `DECIMAL(19,6) NOT NULL` | كمية خامة بوحدتها الأساسية |
| `is_active` | `BOOLEAN NOT NULL DEFAULT TRUE` | نشط |

**قيود إضافية:** `UNIQUE (service_item_unit_id, material_item_id)`؛ `CHECK (base_quantity > 0)`

## parties

عميل أو مورد أو كلاهما

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `name` | `VARCHAR(255) NOT NULL` | الاسم |
| `is_customer` | `BOOLEAN NOT NULL DEFAULT FALSE` | عميل |
| `is_supplier` | `BOOLEAN NOT NULL DEFAULT FALSE` | مورد |
| `phone` | `VARCHAR(40) NULL` | الهاتف |
| `vat_number` | `VARCHAR(30) NULL` | رقم ضريبي |
| `registration_number` | `VARCHAR(50) NULL` | السجل |
| `address_json` | `JSON NULL` | عنوان |
| `credit_limit` | `DECIMAL(19,6) NULL` | سقف ائتمان |
| `receivable_account_id` | `BIGINT UNSIGNED` | ref:accounts.id |
| `payable_account_id` | `BIGINT UNSIGNED` | ref:accounts.id |
| `is_active` | `BOOLEAN NOT NULL DEFAULT TRUE` | نشط |

**قيود إضافية:** `CHECK (is_customer = TRUE OR is_supplier = TRUE)`؛ `CHECK (credit_limit IS NULL OR credit_limit >= 0)`

## warehouses

مخزن المحل

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `code` | `VARCHAR(30) NOT NULL` | كود |
| `name` | `VARCHAR(100) NOT NULL` | اسم |
| `is_active` | `BOOLEAN NOT NULL DEFAULT TRUE` | نشط |

**قيود إضافية:** `UNIQUE (code)`

## financial_accounts

الصندوق والبنك والشبكة قيد التسوية

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `name` | `VARCHAR(120) NOT NULL` | الاسم |
| `kind` | `ENUM('cash','bank','card_clearing') NOT NULL` | نوع |
| `ledger_account_id` | `BIGINT UNSIGNED NOT NULL` | ref:accounts.id |
| `is_active` | `BOOLEAN NOT NULL DEFAULT TRUE` | نشط |

**قيود إضافية:** `UNIQUE (ledger_account_id)`

## cash_sessions

فتح وإغلاق الصندوق

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `financial_account_id` | `BIGINT UNSIGNED NOT NULL` | ref:financial_accounts.id |
| `opened_by` | `BIGINT UNSIGNED NOT NULL` | ref:users.id |
| `opened_at` | `DATETIME(6) NOT NULL` | وقت الفتح |
| `opening_cash` | `DECIMAL(19,6) NOT NULL` | افتتاحي |
| `closed_at` | `DATETIME(6) NULL` | وقت الإغلاق |
| `expected_cash` | `DECIMAL(19,6) NULL` | متوقع وقت الإغلاق |
| `counted_cash` | `DECIMAL(19,6) NULL` | فعلي |
| `difference_reason` | `VARCHAR(255) NULL` | سبب الفرق |

**قيود إضافية:** `CHECK (opening_cash >= 0)`

## document_sequences

تسلسل تجاري يقفل أثناء تخصيص الرقم

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `kind` | `VARCHAR(40) NOT NULL` | نوع |
| `fiscal_year` | `SMALLINT UNSIGNED NOT NULL` | سنة |
| `prefix` | `VARCHAR(30) NOT NULL` | بادئة |
| `next_number` | `BIGINT UNSIGNED NOT NULL DEFAULT 1` | التالي |

**قيود إضافية:** `UNIQUE (kind, fiscal_year)`؛ `CHECK (next_number > 0)`

## documents

رأس مستند موحد للبيع والشراء والمخزون والمصروف

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `uuid` | `CHAR(36) NOT NULL` | معرف ثابت |
| `kind` | `ENUM('sale','sale_return','purchase','purchase_return','expense','stock_opening','stock_adjustment','material_usage') NOT NULL` | نوع المستند |
| `document_number` | `VARCHAR(60) NULL` | يخصص عند الإصدار |
| `status` | `ENUM('draft','prepared','posted','cancelled') NOT NULL DEFAULT 'draft'` | prepared محجوز للفواتير القياسية قبل التخليص |
| `einvoice_type` | `ENUM('none','simplified','standard') NOT NULL DEFAULT 'none'` | نوع الفاتورة |
| `party_id` | `BIGINT UNSIGNED` | ref:parties.id |
| `warehouse_id` | `BIGINT UNSIGNED` | ref:warehouses.id |
| `original_document_id` | `BIGINT UNSIGNED` | ref:documents.id |
| `accounting_period_id` | `BIGINT UNSIGNED` | ref:accounting_periods.id |
| `cash_session_id` | `BIGINT UNSIGNED` | ref:cash_sessions.id |
| `issued_at` | `DATETIME(6) NULL` | وقت إصدار UTC |
| `business_date` | `DATE NOT NULL` | تاريخ العمل السعودي |
| `due_date` | `DATE NULL` | استحقاق |
| `supplier_reference` | `VARCHAR(100) NULL` | رقم فاتورة المورد |
| `currency` | `CHAR(3) NOT NULL DEFAULT 'SAR'` | عملة |
| `seller_snapshot` | `JSON NULL` | نسخة بيانات البائع |
| `buyer_snapshot` | `JSON NULL` | نسخة بيانات المشتري |
| `subtotal` | `DECIMAL(19,6) NOT NULL DEFAULT 0` | قبل الخصم والضريبة |
| `discount_total` | `DECIMAL(19,6) NOT NULL DEFAULT 0` | إجمالي الخصم الموزع |
| `net_total` | `DECIMAL(19,6) NOT NULL DEFAULT 0` | بعد الخصم قبل الضريبة |
| `tax_total` | `DECIMAL(19,6) NOT NULL DEFAULT 0` | الضريبة |
| `rounding_amount` | `DECIMAL(19,6) NOT NULL DEFAULT 0` | تسوية تقريب موثقة |
| `grand_total` | `DECIMAL(19,6) NOT NULL DEFAULT 0` | الإجمالي |
| `reason` | `VARCHAR(255) NULL` | سبب المرتجع أو التسوية |
| `notes` | `TEXT NULL` | ملاحظات |
| `idempotency_key` | `VARCHAR(100) NOT NULL` | منع تكرار الطلب |
| `created_by` | `BIGINT UNSIGNED NOT NULL` | ref:users.id |
| `created_at` | `DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)` | إنشاء |
| `posted_at` | `DATETIME(6) NULL` | ترحيل |

**قيود إضافية:** `UNIQUE (uuid)`؛ `UNIQUE (document_number)`؛ `UNIQUE (idempotency_key)`؛ `CHECK (subtotal >= 0 AND discount_total >= 0 AND net_total >= 0 AND tax_total >= 0 AND grand_total >= 0)`؛ `INDEX ix_documents_kind_date (kind, business_date, status)`؛ `INDEX ix_documents_party_date (party_id, business_date)`؛ `INDEX ix_supplier_reference (party_id, supplier_reference)`

## document_lines

سطور المستند ونسخ الأسعار والضرائب والتكلفة

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `document_id` | `BIGINT UNSIGNED NOT NULL` | ref:documents.id |
| `line_number` | `INT UNSIGNED NOT NULL` | ترتيب |
| `item_id` | `BIGINT UNSIGNED` | ref:items.id |
| `item_unit_id` | `BIGINT UNSIGNED NULL` | وحدة الصنف المطابقة له |
| `original_line_id` | `BIGINT UNSIGNED` | ref:document_lines.id |
| `account_id` | `BIGINT UNSIGNED` | ref:accounts.id |
| `description` | `VARCHAR(500) NOT NULL` | وصف ثابت |
| `sku_snapshot` | `VARCHAR(60) NULL` | نسخة الكود |
| `item_type_snapshot` | `ENUM('stock','service','expense') NOT NULL` | نوع وقت العملية |
| `unit_name_snapshot` | `VARCHAR(80) NOT NULL` | نسخة الوحدة |
| `unit_code_snapshot` | `VARCHAR(20) NULL` | كود إلكتروني |
| `quantity` | `DECIMAL(19,6) NOT NULL` | كمية بوحدة البيع |
| `to_base_factor` | `DECIMAL(19,6) NOT NULL DEFAULT 1` | معامل التحويل وقت العملية |
| `base_quantity` | `DECIMAL(19,6) NOT NULL` | الكمية الأساسية |
| `stock_direction` | `ENUM('in','out','none') NOT NULL DEFAULT 'none'` | اتجاه للجرد والافتتاحي والخامات |
| `unit_price` | `DECIMAL(19,6) NOT NULL DEFAULT 0` | سعر موحد قبل الضريبة |
| `price_includes_tax_snapshot` | `BOOLEAN NOT NULL DEFAULT FALSE` | طريقة إدخال السعر |
| `entered_unit_price` | `DECIMAL(19,6) NOT NULL DEFAULT 0` | سعر الإدخال الأصلي |
| `discount_amount` | `DECIMAL(19,6) NOT NULL DEFAULT 0` | كل خصومات السطر الموزعة |
| `tax_category_snapshot` | `VARCHAR(10) NULL` | تصنيف ثابت |
| `tax_rate_snapshot` | `DECIMAL(7,4) NOT NULL DEFAULT 0` | نسبة ثابتة |
| `tax_reason_code_snapshot` | `VARCHAR(50) NULL` | سبب |
| `tax_reason_snapshot` | `VARCHAR(255) NULL` | وصف سبب |
| `net_amount` | `DECIMAL(19,6) NOT NULL DEFAULT 0` | قبل الضريبة بعد الخصم |
| `tax_amount` | `DECIMAL(19,6) NOT NULL DEFAULT 0` | ضريبة |
| `gross_amount` | `DECIMAL(19,6) NOT NULL DEFAULT 0` | إجمالي السطر |
| `tax_recoverable_amount` | `DECIMAL(19,6) NOT NULL DEFAULT 0` | مدخلات مسموح خصمها وفق المراجعة |
| `unit_cost_snapshot` | `DECIMAL(19,6) NULL` | تكلفة الوحدة الأساسية وقت الترحيل |
| `total_cost_snapshot` | `DECIMAL(19,6) NULL` | تكلفة السطر |

**قيود إضافية:** `UNIQUE (document_id, line_number)`؛ `UNIQUE (id, document_id)`؛ `FOREIGN KEY (item_unit_id, item_id) REFERENCES item_units(id, item_id) ON DELETE RESTRICT`؛ `CHECK ((item_unit_id IS NULL AND item_id IS NULL) OR (item_unit_id IS NOT NULL AND item_id IS NOT NULL))`؛ `CHECK (quantity > 0 AND to_base_factor > 0 AND base_quantity > 0)`؛ `CHECK (unit_price >= 0 AND entered_unit_price >= 0 AND discount_amount >= 0 AND net_amount >= 0 AND tax_amount >= 0 AND gross_amount >= 0)`؛ `CHECK (tax_rate_snapshot >= 0 AND tax_rate_snapshot <= 100)`؛ `CHECK (tax_recoverable_amount >= 0 AND tax_recoverable_amount <= tax_amount)`

## document_tax_totals

تجميع الضريبة لكل مستند وتصنيف ونسبة

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `document_id` | `BIGINT UNSIGNED NOT NULL` | ref:documents.id |
| `category` | `VARCHAR(10) NOT NULL` | تصنيف |
| `rate` | `DECIMAL(7,4) NOT NULL` | نسبة |
| `taxable_amount` | `DECIMAL(19,6) NOT NULL` | وعاء |
| `tax_amount` | `DECIMAL(19,6) NOT NULL` | ضريبة |
| `reason_code` | `VARCHAR(50) NULL` | سبب |

**قيود إضافية:** `CHECK (taxable_amount >= 0 AND tax_amount >= 0)`

## stock_balances

رصيد قابل لإعادة البناء من الحركات

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `warehouse_id` | `BIGINT UNSIGNED NOT NULL` | ref:warehouses.id |
| `item_id` | `BIGINT UNSIGNED NOT NULL` | ref:items.id |
| `quantity` | `DECIMAL(19,6) NOT NULL DEFAULT 0` | رصيد أساسي |
| `inventory_value` | `DECIMAL(19,6) NOT NULL DEFAULT 0` | قيمة رصيد |
| `average_cost` | `DECIMAL(19,6) NOT NULL DEFAULT 0` | متوسط متحرك |
| `version` | `BIGINT UNSIGNED NOT NULL DEFAULT 0` | نسخة تزامن |

**قيود إضافية:** `UNIQUE (warehouse_id, item_id)`؛ `CHECK (quantity >= 0 AND inventory_value >= 0 AND average_cost >= 0)`

## stock_reservations

حجز مخزون مستند قياسي ينتظر التخليص

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `document_id` | `BIGINT UNSIGNED NOT NULL` | ref:documents.id |
| `document_line_id` | `BIGINT UNSIGNED NOT NULL` | ref:document_lines.id |
| `warehouse_id` | `BIGINT UNSIGNED NOT NULL` | ref:warehouses.id |
| `item_id` | `BIGINT UNSIGNED NOT NULL` | ref:items.id |
| `component_number` | `INT UNSIGNED NOT NULL DEFAULT 0` | رقم مكون الخامة |
| `quantity` | `DECIMAL(19,6) NOT NULL` | كمية أساسية محجوزة |
| `status` | `ENUM('active','consumed','released') NOT NULL DEFAULT 'active'` | الحالة |
| `created_at` | `DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)` | وقت |
| `resolved_at` | `DATETIME(6) NULL` | إنهاء |

**قيود إضافية:** `UNIQUE (document_line_id, component_number)`؛ `CHECK (quantity > 0)`؛ `INDEX ix_reservation_available (warehouse_id, item_id, status)`؛ `FOREIGN KEY (document_line_id, document_id) REFERENCES document_lines(id, document_id) ON DELETE RESTRICT`

## stock_movements

دفتر حركات المخزون الدائم

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `warehouse_id` | `BIGINT UNSIGNED NOT NULL` | ref:warehouses.id |
| `item_id` | `BIGINT UNSIGNED NOT NULL` | ref:items.id |
| `document_id` | `BIGINT UNSIGNED NOT NULL` | ref:documents.id |
| `document_line_id` | `BIGINT UNSIGNED NOT NULL` | سطر مصدر |
| `component_number` | `INT UNSIGNED NOT NULL DEFAULT 0` | 0 للصنف، أرقام للخامات |
| `reverses_movement_id` | `BIGINT UNSIGNED` | ref:stock_movements.id |
| `movement_type` | `ENUM('opening','purchase','sale','sale_return','purchase_return','adjustment','material_usage') NOT NULL` | السبب |
| `quantity_delta` | `DECIMAL(19,6) NOT NULL` | موجب وارد وسالب صادر |
| `value_delta` | `DECIMAL(19,6) NOT NULL` | تغير قيمة |
| `unit_cost` | `DECIMAL(19,6) NOT NULL` | تكلفة أساسية |
| `balance_after` | `DECIMAL(19,6) NOT NULL` | رصيد بعد |
| `value_after` | `DECIMAL(19,6) NOT NULL` | قيمة بعد |
| `occurred_at` | `DATETIME(6) NOT NULL` | وقت |
| `created_at` | `DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)` | إنشاء |

**قيود إضافية:** `UNIQUE (document_line_id, component_number)`؛ `FOREIGN KEY (document_line_id, document_id) REFERENCES document_lines(id, document_id) ON DELETE RESTRICT`؛ `CHECK (quantity_delta <> 0)`؛ `CHECK (unit_cost >= 0 AND balance_after >= 0 AND value_after >= 0)`؛ `INDEX ix_stock_item_time (warehouse_id, item_id, occurred_at, id)`

## stock_counts

جلسة الجرد

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `warehouse_id` | `BIGINT UNSIGNED NOT NULL` | ref:warehouses.id |
| `status` | `ENUM('draft','counting','posted','cancelled') NOT NULL DEFAULT 'draft'` | الحالة |
| `counted_at` | `DATETIME(6) NOT NULL` | وقت |
| `adjustment_document_id` | `BIGINT UNSIGNED` | ref:documents.id |
| `created_by` | `BIGINT UNSIGNED NOT NULL` | ref:users.id |

**قيود إضافية:** `UNIQUE (adjustment_document_id)`

## stock_count_lines

الكمية النظرية والفعلية أثناء تجميد الجرد

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `stock_count_id` | `BIGINT UNSIGNED NOT NULL` | ref:stock_counts.id |
| `item_id` | `BIGINT UNSIGNED NOT NULL` | ref:items.id |
| `expected_quantity` | `DECIMAL(19,6) NOT NULL` | نظري |
| `counted_quantity` | `DECIMAL(19,6) NULL` | فعلي |
| `unit_cost_snapshot` | `DECIMAL(19,6) NOT NULL` | تكلفة |
| `reason` | `VARCHAR(255) NULL` | سبب فرق |

**قيود إضافية:** `UNIQUE (stock_count_id, item_id)`؛ `CHECK (expected_quantity >= 0 AND unit_cost_snapshot >= 0)`؛ `CHECK (counted_quantity IS NULL OR counted_quantity >= 0)`

## journal_entries

رأس القيود وترحيل وحيد لكل مصدر

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `entry_number` | `VARCHAR(60) NOT NULL` | رقم قيد |
| `document_id` | `BIGINT UNSIGNED` | ref:documents.id |
| `payment_id` | `BIGINT UNSIGNED NULL` | دفعة مصدر يضاف FK بعد جدول الدفعات |
| `reverses_entry_id` | `BIGINT UNSIGNED` | ref:journal_entries.id |
| `accounting_period_id` | `BIGINT UNSIGNED NOT NULL` | ref:accounting_periods.id |
| `entry_date` | `DATE NOT NULL` | تاريخ |
| `description` | `VARCHAR(500) NOT NULL` | بيان |
| `status` | `ENUM('draft','posted') NOT NULL DEFAULT 'draft'` | حالة |
| `created_by` | `BIGINT UNSIGNED NOT NULL` | ref:users.id |
| `posted_at` | `DATETIME(6) NULL` | ترحيل |

**قيود إضافية:** `UNIQUE (entry_number)`؛ `UNIQUE (document_id)`؛ `UNIQUE (payment_id)`؛ `UNIQUE (reverses_entry_id)`؛ `CHECK (NOT (document_id IS NOT NULL AND payment_id IS NOT NULL))`

## journal_lines

الطرف المدين والدائن

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `journal_entry_id` | `BIGINT UNSIGNED NOT NULL` | ref:journal_entries.id |
| `line_number` | `INT UNSIGNED NOT NULL` | ترتيب |
| `account_id` | `BIGINT UNSIGNED NOT NULL` | ref:accounts.id |
| `party_id` | `BIGINT UNSIGNED` | ref:parties.id |
| `debit` | `DECIMAL(19,6) NOT NULL DEFAULT 0` | مدين |
| `credit` | `DECIMAL(19,6) NOT NULL DEFAULT 0` | دائن |
| `description` | `VARCHAR(255) NULL` | بيان |

**قيود إضافية:** `UNIQUE (journal_entry_id, line_number)`؛ `CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))`؛ `INDEX ix_journal_account (account_id, journal_entry_id)`؛ `INDEX ix_journal_party (party_id, journal_entry_id)`

## payments

قبض وصرف وتحويل واسترداد

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `payment_number` | `VARCHAR(60) NOT NULL` | رقم |
| `direction` | `ENUM('receipt','disbursement','transfer') NOT NULL` | الاتجاه |
| `financial_account_id` | `BIGINT UNSIGNED NOT NULL` | ref:financial_accounts.id |
| `destination_financial_account_id` | `BIGINT UNSIGNED` | ref:financial_accounts.id |
| `party_id` | `BIGINT UNSIGNED` | ref:parties.id |
| `cash_session_id` | `BIGINT UNSIGNED` | ref:cash_sessions.id |
| `accounting_period_id` | `BIGINT UNSIGNED NOT NULL` | ref:accounting_periods.id |
| `counter_account_id` | `BIGINT UNSIGNED` | ref:accounts.id |
| `reverses_payment_id` | `BIGINT UNSIGNED` | ref:payments.id |
| `method` | `ENUM('cash','card','bank_transfer','other') NOT NULL` | وسيلة |
| `amount` | `DECIMAL(19,6) NOT NULL` | قيمة |
| `payment_date` | `DATE NOT NULL` | تاريخ |
| `paid_at` | `DATETIME(6) NOT NULL` | وقت |
| `status` | `ENUM('draft','posted') NOT NULL DEFAULT 'draft'` | حالة |
| `external_reference` | `VARCHAR(120) NULL` | مرجع خارجي |
| `idempotency_key` | `VARCHAR(100) NOT NULL` | منع التكرار |
| `notes` | `VARCHAR(500) NULL` | بيان |
| `created_by` | `BIGINT UNSIGNED NOT NULL` | ref:users.id |

**قيود إضافية:** `UNIQUE (payment_number)`؛ `UNIQUE (idempotency_key)`؛ `UNIQUE (reverses_payment_id)`؛ `CHECK (amount > 0)`؛ `CHECK ((direction = 'transfer' AND destination_financial_account_id IS NOT NULL AND destination_financial_account_id <> financial_account_id) OR (direction <> 'transfer' AND destination_financial_account_id IS NULL))`

## payment_allocations

توزيع القبض والصرف على مستندات

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `payment_id` | `BIGINT UNSIGNED NOT NULL` | ref:payments.id |
| `document_id` | `BIGINT UNSIGNED NOT NULL` | ref:documents.id |
| `amount` | `DECIMAL(19,6) NOT NULL` | المبلغ المخصص |

**قيود إضافية:** `UNIQUE (payment_id, document_id)`؛ `CHECK (amount > 0)`

## einvoice_devices

وحدة إصدار الفواتير ومرجع الأسرار

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `serial_number` | `VARCHAR(120) NOT NULL` | معرف الوحدة |
| `environment` | `ENUM('sandbox','simulation','production') NOT NULL` | بيئة |
| `credentials_secret_ref` | `VARCHAR(255) NULL` | مرجع مخزن أسرار وليس المفتاح نفسه |
| `certificate_expires_at` | `DATETIME(6) NULL` | انتهاء |
| `next_invoice_counter` | `BIGINT UNSIGNED NOT NULL DEFAULT 1` | عداد يقفل مع التسلسل |
| `previous_invoice_hash` | `VARCHAR(128) NULL` | هاش سابق بحسب المواصفات |
| `is_active` | `BOOLEAN NOT NULL DEFAULT TRUE` | نشط |

**قيود إضافية:** `UNIQUE (serial_number, environment)`؛ `CHECK (next_invoice_counter > 0)`

## einvoice_documents

النسخة الإلكترونية الأصلية الثابتة

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `document_id` | `BIGINT UNSIGNED NOT NULL` | ref:documents.id |
| `device_id` | `BIGINT UNSIGNED NOT NULL` | ref:einvoice_devices.id |
| `uuid` | `CHAR(36) NOT NULL` | معرف مستند |
| `invoice_counter` | `BIGINT UNSIGNED NOT NULL` | عداد |
| `previous_invoice_hash` | `VARCHAR(128) NOT NULL` | هاش سابق |
| `invoice_hash` | `VARCHAR(128) NOT NULL` | هاش |
| `route` | `ENUM('reporting','clearance') NOT NULL` | مسار |
| `xml_storage_key` | `VARCHAR(500) NOT NULL` | مسار XML الأصلي في تخزين خاص |
| `xml_checksum` | `CHAR(64) NOT NULL` | SHA256 للملف المخزن |
| `cleared_xml_storage_key` | `VARCHAR(500) NULL` | نسخة نتيجة التخليص عند وجودها |
| `qr_payload` | `TEXT NULL` | QR الأصلي |
| `status` | `ENUM('ready','pending','reported','cleared','rejected','connection_error') NOT NULL DEFAULT 'ready'` | حالة الإرسال منفصلة عن المحاسبة |
| `schema_version` | `VARCHAR(60) NOT NULL` | نسخة متطلبات |
| `generated_at` | `DATETIME(6) NOT NULL` | إنشاء |
| `reporting_due_at` | `DATETIME(6) NULL` | موعد الإبلاغ |
| `last_submitted_at` | `DATETIME(6) NULL` | آخر إرسال |

**قيود إضافية:** `UNIQUE (document_id)`؛ `UNIQUE (uuid)`؛ `UNIQUE (device_id, invoice_counter)`؛ `INDEX ix_einvoice_pending (status, reporting_due_at)`

## einvoice_submissions

كل محاولة إرسال ونتيجتها

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `einvoice_document_id` | `BIGINT UNSIGNED NOT NULL` | ref:einvoice_documents.id |
| `attempt_number` | `INT UNSIGNED NOT NULL` | رقم محاولة |
| `request_correlation_id` | `VARCHAR(100) NOT NULL` | تتبع |
| `started_at` | `DATETIME(6) NOT NULL` | بداية |
| `finished_at` | `DATETIME(6) NULL` | نهاية |
| `http_status` | `SMALLINT UNSIGNED NULL` | HTTP |
| `result` | `ENUM('in_progress','accepted','rejected','unknown','connection_error') NOT NULL` | unknown نتيجة غير محسومة |
| `response_json` | `JSON NULL` | الرد بعد تنقيح الأسرار |
| `error_message` | `TEXT NULL` | شرح |

**قيود إضافية:** `UNIQUE (einvoice_document_id, attempt_number)`؛ `UNIQUE (request_correlation_id)`

## outbox_jobs

مهام موثوقة تسجل مع المعاملة المحلية

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `job_type` | `VARCHAR(60) NOT NULL` | نوع |
| `dedupe_key` | `VARCHAR(190) NOT NULL` | مفتاح وحيد |
| `document_id` | `BIGINT UNSIGNED` | ref:documents.id |
| `einvoice_document_id` | `BIGINT UNSIGNED` | ref:einvoice_documents.id |
| `payload_json` | `JSON NOT NULL` | بيانات بدون أسرار |
| `status` | `ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending'` | حالة |
| `attempts` | `INT UNSIGNED NOT NULL DEFAULT 0` | محاولات |
| `available_at` | `DATETIME(6) NOT NULL` | موعد |
| `locked_at` | `DATETIME(6) NULL` | وقت حجز |
| `locked_by` | `VARCHAR(100) NULL` | عامل |
| `last_error` | `TEXT NULL` | آخر خطأ |

**قيود إضافية:** `UNIQUE (dedupe_key)`؛ `INDEX ix_outbox_available (status, available_at)`

## attachments

مرفقات خاصة ومستندات أصلية

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `document_id` | `BIGINT UNSIGNED` | ref:documents.id |
| `payment_id` | `BIGINT UNSIGNED` | ref:payments.id |
| `file_name` | `VARCHAR(255) NOT NULL` | الاسم |
| `storage_key` | `VARCHAR(500) NOT NULL` | مسار خاص |
| `mime_type` | `VARCHAR(100) NOT NULL` | نوع |
| `size_bytes` | `BIGINT UNSIGNED NOT NULL` | حجم |
| `checksum` | `CHAR(64) NOT NULL` | SHA256 |
| `uploaded_by` | `BIGINT UNSIGNED NOT NULL` | ref:users.id |
| `uploaded_at` | `DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)` | وقت |

**قيود إضافية:** `CHECK ((document_id IS NOT NULL AND payment_id IS NULL) OR (document_id IS NULL AND payment_id IS NOT NULL))`

## audit_logs

تتبع التغييرات والعمليات الحساسة

| الحقل | نوع MySQL | المعنى أو العلاقة |
|---|---|---|
| `id` | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY` | المعرف |
| `user_id` | `BIGINT UNSIGNED` | ref:users.id |
| `entity_type` | `VARCHAR(80) NOT NULL` | كيان |
| `entity_id` | `BIGINT UNSIGNED NULL` | معرف تتبع منطقي |
| `action` | `VARCHAR(60) NOT NULL` | إجراء |
| `before_json` | `JSON NULL` | قبل بدون أسرار |
| `after_json` | `JSON NULL` | بعد بدون أسرار |
| `request_id` | `VARCHAR(100) NULL` | طلب |
| `ip_address` | `VARCHAR(45) NULL` | IP |
| `created_at` | `DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)` | وقت |

**قيود إضافية:** `INDEX ix_audit_entity (entity_type, entity_id, created_at)`
