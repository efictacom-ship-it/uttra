-- Stationery accounting blueprint v1
-- MySQL 8.0.16+ / 8.4. Fresh empty database only. No DROP statements.
-- Structure only; no business data, admin password, tax assumptions or ZATCA credentials.
-- Select an empty schema first with USE your_database;
-- CHECK constraints and FKs cover row integrity; posting invariants require application services.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- الحساب وتسجيل الدخول
CREATE TABLE `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- منشأة واحدة وبياناتها الرسمية
CREATE TABLE `business_settings` (
  `id` TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  `legal_name` VARCHAR(255) NOT NULL,
  `trade_name` VARCHAR(255) NULL,
  `vat_registered` BOOLEAN NOT NULL DEFAULT FALSE,
  `vat_number` VARCHAR(30) NULL,
  `registration_number` VARCHAR(50) NULL,
  `address_json` JSON NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'SAR',
  `timezone` VARCHAR(60) NOT NULL DEFAULT 'Asia/Riyadh',
  `einvoice_phase` ENUM('none','phase1','phase2') NOT NULL DEFAULT 'none',
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- شجرة الحسابات
CREATE TABLE `accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `parent_id` BIGINT UNSIGNED,
  FOREIGN KEY (`parent_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT,
  `code` VARCHAR(30) NOT NULL,
  `name` VARCHAR(180) NOT NULL,
  `account_type` ENUM('asset','liability','equity','revenue','expense') NOT NULL,
  `allow_posting` BOOLEAN NOT NULL DEFAULT TRUE,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  UNIQUE (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- إقفال الفترات
CREATE TABLE `accounting_periods` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `starts_on` DATE NOT NULL,
  `ends_on` DATE NOT NULL,
  `status` ENUM('open','closed') NOT NULL DEFAULT 'open',
  `closed_by` BIGINT UNSIGNED,
  FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  `closed_at` DATETIME(6) NULL,
  CHECK (ends_on >= starts_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تعريف المعالجة الضريبية
CREATE TABLE `tax_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(30) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `category` VARCHAR(10) NOT NULL,
  `rate` DECIMAL(7,4) NOT NULL,
  `exemption_reason_code` VARCHAR(50) NULL,
  `exemption_reason` VARCHAR(255) NULL,
  `valid_from` DATE NOT NULL,
  `valid_to` DATE NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  UNIQUE (code),
  CHECK (rate >= 0 AND rate <= 100),
  CHECK (valid_to IS NULL OR valid_to >= valid_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تصنيفات السلع والخدمات
CREATE TABLE `categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `parent_id` BIGINT UNSIGNED,
  FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT,
  `name` VARCHAR(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- وحدات القياس
CREATE TABLE `units` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `einvoice_code` VARCHAR(20) NULL,
  UNIQUE (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- السلع والخدمات
CREATE TABLE `items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `sku` VARCHAR(60) NOT NULL,
  `name_ar` VARCHAR(255) NOT NULL,
  `name_en` VARCHAR(255) NULL,
  `item_type` ENUM('stock','service') NOT NULL,
  `category_id` BIGINT UNSIGNED,
  FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT,
  `base_unit_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`base_unit_id`) REFERENCES `units` (`id`) ON DELETE RESTRICT,
  `tax_code_id` BIGINT UNSIGNED,
  FOREIGN KEY (`tax_code_id`) REFERENCES `tax_codes` (`id`) ON DELETE RESTRICT,
  `reorder_level` DECIMAL(19,6) NOT NULL DEFAULT 0,
  `last_purchase_cost` DECIMAL(19,6) NULL,
  `revenue_account_id` BIGINT UNSIGNED,
  FOREIGN KEY (`revenue_account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT,
  `inventory_account_id` BIGINT UNSIGNED,
  FOREIGN KEY (`inventory_account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT,
  `cost_account_id` BIGINT UNSIGNED,
  FOREIGN KEY (`cost_account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE (sku),
  CHECK (reorder_level >= 0),
  CHECK (last_purchase_cost IS NULL OR last_purchase_cost >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- وحدات الصنف وأسعارها
CREATE TABLE `item_units` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `item_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE RESTRICT,
  `unit_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE RESTRICT,
  `to_base_factor` DECIMAL(19,6) NOT NULL,
  `sale_price` DECIMAL(19,6) NOT NULL DEFAULT 0,
  `price_includes_tax` BOOLEAN NOT NULL DEFAULT TRUE,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  UNIQUE (item_id, unit_id),
  UNIQUE (id, item_id),
  CHECK (to_base_factor > 0),
  CHECK (sale_price >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- باركود لكل وحدة
CREATE TABLE `item_barcodes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `item_unit_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`item_unit_id`) REFERENCES `item_units` (`id`) ON DELETE RESTRICT,
  `barcode` VARCHAR(100) NOT NULL,
  UNIQUE (barcode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- استهلاك اختياري للخامات لكل وحدة خدمة
CREATE TABLE `service_recipe_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `service_item_unit_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`service_item_unit_id`) REFERENCES `item_units` (`id`) ON DELETE RESTRICT,
  `material_item_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`material_item_id`) REFERENCES `items` (`id`) ON DELETE RESTRICT,
  `base_quantity` DECIMAL(19,6) NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  UNIQUE (service_item_unit_id, material_item_id),
  CHECK (base_quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- عميل أو مورد أو كلاهما
CREATE TABLE `parties` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `is_customer` BOOLEAN NOT NULL DEFAULT FALSE,
  `is_supplier` BOOLEAN NOT NULL DEFAULT FALSE,
  `phone` VARCHAR(40) NULL,
  `vat_number` VARCHAR(30) NULL,
  `registration_number` VARCHAR(50) NULL,
  `address_json` JSON NULL,
  `credit_limit` DECIMAL(19,6) NULL,
  `receivable_account_id` BIGINT UNSIGNED,
  FOREIGN KEY (`receivable_account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT,
  `payable_account_id` BIGINT UNSIGNED,
  FOREIGN KEY (`payable_account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  CHECK (is_customer = TRUE OR is_supplier = TRUE),
  CHECK (credit_limit IS NULL OR credit_limit >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- مخزن المحل
CREATE TABLE `warehouses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(30) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  UNIQUE (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الصندوق والبنك والشبكة قيد التسوية
CREATE TABLE `financial_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `kind` ENUM('cash','bank','card_clearing') NOT NULL,
  `ledger_account_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`ledger_account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  UNIQUE (ledger_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- فتح وإغلاق الصندوق
CREATE TABLE `cash_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `financial_account_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`financial_account_id`) REFERENCES `financial_accounts` (`id`) ON DELETE RESTRICT,
  `opened_by` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`opened_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  `opened_at` DATETIME(6) NOT NULL,
  `opening_cash` DECIMAL(19,6) NOT NULL,
  `closed_at` DATETIME(6) NULL,
  `expected_cash` DECIMAL(19,6) NULL,
  `counted_cash` DECIMAL(19,6) NULL,
  `difference_reason` VARCHAR(255) NULL,
  CHECK (opening_cash >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تسلسل تجاري يقفل أثناء تخصيص الرقم
CREATE TABLE `document_sequences` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `kind` VARCHAR(40) NOT NULL,
  `fiscal_year` SMALLINT UNSIGNED NOT NULL,
  `prefix` VARCHAR(30) NOT NULL,
  `next_number` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE (kind, fiscal_year),
  CHECK (next_number > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- رأس مستند موحد للبيع والشراء والمخزون والمصروف
CREATE TABLE `documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `uuid` CHAR(36) NOT NULL,
  `kind` ENUM('sale','sale_return','purchase','purchase_return','expense','stock_opening','stock_adjustment','material_usage') NOT NULL,
  `document_number` VARCHAR(60) NULL,
  `status` ENUM('draft','prepared','posted','cancelled') NOT NULL DEFAULT 'draft',
  `einvoice_type` ENUM('none','simplified','standard') NOT NULL DEFAULT 'none',
  `party_id` BIGINT UNSIGNED,
  FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`) ON DELETE RESTRICT,
  `warehouse_id` BIGINT UNSIGNED,
  FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE RESTRICT,
  `original_document_id` BIGINT UNSIGNED,
  FOREIGN KEY (`original_document_id`) REFERENCES `documents` (`id`) ON DELETE RESTRICT,
  `accounting_period_id` BIGINT UNSIGNED,
  FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE RESTRICT,
  `cash_session_id` BIGINT UNSIGNED,
  FOREIGN KEY (`cash_session_id`) REFERENCES `cash_sessions` (`id`) ON DELETE RESTRICT,
  `issued_at` DATETIME(6) NULL,
  `business_date` DATE NOT NULL,
  `due_date` DATE NULL,
  `supplier_reference` VARCHAR(100) NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'SAR',
  `seller_snapshot` JSON NULL,
  `buyer_snapshot` JSON NULL,
  `subtotal` DECIMAL(19,6) NOT NULL DEFAULT 0,
  `discount_total` DECIMAL(19,6) NOT NULL DEFAULT 0,
  `net_total` DECIMAL(19,6) NOT NULL DEFAULT 0,
  `tax_total` DECIMAL(19,6) NOT NULL DEFAULT 0,
  `rounding_amount` DECIMAL(19,6) NOT NULL DEFAULT 0,
  `grand_total` DECIMAL(19,6) NOT NULL DEFAULT 0,
  `reason` VARCHAR(255) NULL,
  `notes` TEXT NULL,
  `idempotency_key` VARCHAR(100) NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `posted_at` DATETIME(6) NULL,
  UNIQUE (uuid),
  UNIQUE (document_number),
  UNIQUE (idempotency_key),
  CHECK (subtotal >= 0 AND discount_total >= 0 AND net_total >= 0 AND tax_total >= 0 AND grand_total >= 0),
  INDEX ix_documents_kind_date (kind, business_date, status),
  INDEX ix_documents_party_date (party_id, business_date),
  INDEX ix_supplier_reference (party_id, supplier_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- سطور المستند ونسخ الأسعار والضرائب والتكلفة
CREATE TABLE `document_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `document_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE RESTRICT,
  `line_number` INT UNSIGNED NOT NULL,
  `item_id` BIGINT UNSIGNED,
  FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE RESTRICT,
  `item_unit_id` BIGINT UNSIGNED NULL,
  `original_line_id` BIGINT UNSIGNED,
  FOREIGN KEY (`original_line_id`) REFERENCES `document_lines` (`id`) ON DELETE RESTRICT,
  `account_id` BIGINT UNSIGNED,
  FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT,
  `description` VARCHAR(500) NOT NULL,
  `sku_snapshot` VARCHAR(60) NULL,
  `item_type_snapshot` ENUM('stock','service','expense') NOT NULL,
  `unit_name_snapshot` VARCHAR(80) NOT NULL,
  `unit_code_snapshot` VARCHAR(20) NULL,
  `quantity` DECIMAL(19,6) NOT NULL,
  `to_base_factor` DECIMAL(19,6) NOT NULL DEFAULT 1,
  `base_quantity` DECIMAL(19,6) NOT NULL,
  `stock_direction` ENUM('in','out','none') NOT NULL DEFAULT 'none',
  `unit_price` DECIMAL(19,6) NOT NULL DEFAULT 0,
  `price_includes_tax_snapshot` BOOLEAN NOT NULL DEFAULT FALSE,
  `entered_unit_price` DECIMAL(19,6) NOT NULL DEFAULT 0,
  `discount_amount` DECIMAL(19,6) NOT NULL DEFAULT 0,
  `tax_category_snapshot` VARCHAR(10) NULL,
  `tax_rate_snapshot` DECIMAL(7,4) NOT NULL DEFAULT 0,
  `tax_reason_code_snapshot` VARCHAR(50) NULL,
  `tax_reason_snapshot` VARCHAR(255) NULL,
  `net_amount` DECIMAL(19,6) NOT NULL DEFAULT 0,
  `tax_amount` DECIMAL(19,6) NOT NULL DEFAULT 0,
  `gross_amount` DECIMAL(19,6) NOT NULL DEFAULT 0,
  `tax_recoverable_amount` DECIMAL(19,6) NOT NULL DEFAULT 0,
  `unit_cost_snapshot` DECIMAL(19,6) NULL,
  `total_cost_snapshot` DECIMAL(19,6) NULL,
  UNIQUE (document_id, line_number),
  UNIQUE (id, document_id),
  FOREIGN KEY (item_unit_id, item_id) REFERENCES item_units(id, item_id) ON DELETE RESTRICT,
  CHECK ((item_unit_id IS NULL AND item_id IS NULL) OR (item_unit_id IS NOT NULL AND item_id IS NOT NULL)),
  CHECK (quantity > 0 AND to_base_factor > 0 AND base_quantity > 0),
  CHECK (unit_price >= 0 AND entered_unit_price >= 0 AND discount_amount >= 0 AND net_amount >= 0 AND tax_amount >= 0 AND gross_amount >= 0),
  CHECK (tax_rate_snapshot >= 0 AND tax_rate_snapshot <= 100),
  CHECK (tax_recoverable_amount >= 0 AND tax_recoverable_amount <= tax_amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تجميع الضريبة لكل مستند وتصنيف ونسبة
CREATE TABLE `document_tax_totals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `document_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE RESTRICT,
  `category` VARCHAR(10) NOT NULL,
  `rate` DECIMAL(7,4) NOT NULL,
  `taxable_amount` DECIMAL(19,6) NOT NULL,
  `tax_amount` DECIMAL(19,6) NOT NULL,
  `reason_code` VARCHAR(50) NULL,
  CHECK (taxable_amount >= 0 AND tax_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- رصيد قابل لإعادة البناء من الحركات
CREATE TABLE `stock_balances` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `warehouse_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE RESTRICT,
  `item_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE RESTRICT,
  `quantity` DECIMAL(19,6) NOT NULL DEFAULT 0,
  `inventory_value` DECIMAL(19,6) NOT NULL DEFAULT 0,
  `average_cost` DECIMAL(19,6) NOT NULL DEFAULT 0,
  `version` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE (warehouse_id, item_id),
  CHECK (quantity >= 0 AND inventory_value >= 0 AND average_cost >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- حجز مخزون مستند قياسي ينتظر التخليص
CREATE TABLE `stock_reservations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `document_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE RESTRICT,
  `document_line_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`document_line_id`) REFERENCES `document_lines` (`id`) ON DELETE RESTRICT,
  `warehouse_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE RESTRICT,
  `item_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE RESTRICT,
  `component_number` INT UNSIGNED NOT NULL DEFAULT 0,
  `quantity` DECIMAL(19,6) NOT NULL,
  `status` ENUM('active','consumed','released') NOT NULL DEFAULT 'active',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `resolved_at` DATETIME(6) NULL,
  UNIQUE (document_line_id, component_number),
  CHECK (quantity > 0),
  INDEX ix_reservation_available (warehouse_id, item_id, status),
  FOREIGN KEY (document_line_id, document_id) REFERENCES document_lines(id, document_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- دفتر حركات المخزون الدائم
CREATE TABLE `stock_movements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `warehouse_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE RESTRICT,
  `item_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE RESTRICT,
  `document_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE RESTRICT,
  `document_line_id` BIGINT UNSIGNED NOT NULL,
  `component_number` INT UNSIGNED NOT NULL DEFAULT 0,
  `reverses_movement_id` BIGINT UNSIGNED,
  FOREIGN KEY (`reverses_movement_id`) REFERENCES `stock_movements` (`id`) ON DELETE RESTRICT,
  `movement_type` ENUM('opening','purchase','sale','sale_return','purchase_return','adjustment','material_usage') NOT NULL,
  `quantity_delta` DECIMAL(19,6) NOT NULL,
  `value_delta` DECIMAL(19,6) NOT NULL,
  `unit_cost` DECIMAL(19,6) NOT NULL,
  `balance_after` DECIMAL(19,6) NOT NULL,
  `value_after` DECIMAL(19,6) NOT NULL,
  `occurred_at` DATETIME(6) NOT NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE (document_line_id, component_number),
  FOREIGN KEY (document_line_id, document_id) REFERENCES document_lines(id, document_id) ON DELETE RESTRICT,
  CHECK (quantity_delta <> 0),
  CHECK (unit_cost >= 0 AND balance_after >= 0 AND value_after >= 0),
  INDEX ix_stock_item_time (warehouse_id, item_id, occurred_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جلسة الجرد
CREATE TABLE `stock_counts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `warehouse_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE RESTRICT,
  `status` ENUM('draft','counting','posted','cancelled') NOT NULL DEFAULT 'draft',
  `counted_at` DATETIME(6) NOT NULL,
  `adjustment_document_id` BIGINT UNSIGNED,
  FOREIGN KEY (`adjustment_document_id`) REFERENCES `documents` (`id`) ON DELETE RESTRICT,
  `created_by` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  UNIQUE (adjustment_document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الكمية النظرية والفعلية أثناء تجميد الجرد
CREATE TABLE `stock_count_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `stock_count_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`stock_count_id`) REFERENCES `stock_counts` (`id`) ON DELETE RESTRICT,
  `item_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`item_id`) REFERENCES `items` (`id`) ON DELETE RESTRICT,
  `expected_quantity` DECIMAL(19,6) NOT NULL,
  `counted_quantity` DECIMAL(19,6) NULL,
  `unit_cost_snapshot` DECIMAL(19,6) NOT NULL,
  `reason` VARCHAR(255) NULL,
  UNIQUE (stock_count_id, item_id),
  CHECK (expected_quantity >= 0 AND unit_cost_snapshot >= 0),
  CHECK (counted_quantity IS NULL OR counted_quantity >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- رأس القيود وترحيل وحيد لكل مصدر
CREATE TABLE `journal_entries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `entry_number` VARCHAR(60) NOT NULL,
  `document_id` BIGINT UNSIGNED,
  FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE RESTRICT,
  `payment_id` BIGINT UNSIGNED NULL,
  `reverses_entry_id` BIGINT UNSIGNED,
  FOREIGN KEY (`reverses_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE RESTRICT,
  `accounting_period_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE RESTRICT,
  `entry_date` DATE NOT NULL,
  `description` VARCHAR(500) NOT NULL,
  `status` ENUM('draft','posted') NOT NULL DEFAULT 'draft',
  `created_by` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  `posted_at` DATETIME(6) NULL,
  UNIQUE (entry_number),
  UNIQUE (document_id),
  UNIQUE (payment_id),
  UNIQUE (reverses_entry_id),
  CHECK (NOT (document_id IS NOT NULL AND payment_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- الطرف المدين والدائن
CREATE TABLE `journal_lines` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `journal_entry_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE RESTRICT,
  `line_number` INT UNSIGNED NOT NULL,
  `account_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT,
  `party_id` BIGINT UNSIGNED,
  FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`) ON DELETE RESTRICT,
  `debit` DECIMAL(19,6) NOT NULL DEFAULT 0,
  `credit` DECIMAL(19,6) NOT NULL DEFAULT 0,
  `description` VARCHAR(255) NULL,
  UNIQUE (journal_entry_id, line_number),
  CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)),
  INDEX ix_journal_account (account_id, journal_entry_id),
  INDEX ix_journal_party (party_id, journal_entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- قبض وصرف وتحويل واسترداد
CREATE TABLE `payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `payment_number` VARCHAR(60) NOT NULL,
  `direction` ENUM('receipt','disbursement','transfer') NOT NULL,
  `financial_account_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`financial_account_id`) REFERENCES `financial_accounts` (`id`) ON DELETE RESTRICT,
  `destination_financial_account_id` BIGINT UNSIGNED,
  FOREIGN KEY (`destination_financial_account_id`) REFERENCES `financial_accounts` (`id`) ON DELETE RESTRICT,
  `party_id` BIGINT UNSIGNED,
  FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`) ON DELETE RESTRICT,
  `cash_session_id` BIGINT UNSIGNED,
  FOREIGN KEY (`cash_session_id`) REFERENCES `cash_sessions` (`id`) ON DELETE RESTRICT,
  `accounting_period_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods` (`id`) ON DELETE RESTRICT,
  `counter_account_id` BIGINT UNSIGNED,
  FOREIGN KEY (`counter_account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT,
  `reverses_payment_id` BIGINT UNSIGNED,
  FOREIGN KEY (`reverses_payment_id`) REFERENCES `payments` (`id`) ON DELETE RESTRICT,
  `method` ENUM('cash','card','bank_transfer','other') NOT NULL,
  `amount` DECIMAL(19,6) NOT NULL,
  `payment_date` DATE NOT NULL,
  `paid_at` DATETIME(6) NOT NULL,
  `status` ENUM('draft','posted') NOT NULL DEFAULT 'draft',
  `external_reference` VARCHAR(120) NULL,
  `idempotency_key` VARCHAR(100) NOT NULL,
  `notes` VARCHAR(500) NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  UNIQUE (payment_number),
  UNIQUE (idempotency_key),
  UNIQUE (reverses_payment_id),
  CHECK (amount > 0),
  CHECK ((direction = 'transfer' AND destination_financial_account_id IS NOT NULL AND destination_financial_account_id <> financial_account_id) OR (direction <> 'transfer' AND destination_financial_account_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- توزيع القبض والصرف على مستندات
CREATE TABLE `payment_allocations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `payment_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE RESTRICT,
  `document_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE RESTRICT,
  `amount` DECIMAL(19,6) NOT NULL,
  UNIQUE (payment_id, document_id),
  CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- وحدة إصدار الفواتير ومرجع الأسرار
CREATE TABLE `einvoice_devices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `serial_number` VARCHAR(120) NOT NULL,
  `environment` ENUM('sandbox','simulation','production') NOT NULL,
  `credentials_secret_ref` VARCHAR(255) NULL,
  `certificate_expires_at` DATETIME(6) NULL,
  `next_invoice_counter` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  `previous_invoice_hash` VARCHAR(128) NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  UNIQUE (serial_number, environment),
  CHECK (next_invoice_counter > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- النسخة الإلكترونية الأصلية الثابتة
CREATE TABLE `einvoice_documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `document_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE RESTRICT,
  `device_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`device_id`) REFERENCES `einvoice_devices` (`id`) ON DELETE RESTRICT,
  `uuid` CHAR(36) NOT NULL,
  `invoice_counter` BIGINT UNSIGNED NOT NULL,
  `previous_invoice_hash` VARCHAR(128) NOT NULL,
  `invoice_hash` VARCHAR(128) NOT NULL,
  `route` ENUM('reporting','clearance') NOT NULL,
  `xml_storage_key` VARCHAR(500) NOT NULL,
  `xml_checksum` CHAR(64) NOT NULL,
  `cleared_xml_storage_key` VARCHAR(500) NULL,
  `qr_payload` TEXT NULL,
  `status` ENUM('ready','pending','reported','cleared','rejected','connection_error') NOT NULL DEFAULT 'ready',
  `schema_version` VARCHAR(60) NOT NULL,
  `generated_at` DATETIME(6) NOT NULL,
  `reporting_due_at` DATETIME(6) NULL,
  `last_submitted_at` DATETIME(6) NULL,
  UNIQUE (document_id),
  UNIQUE (uuid),
  UNIQUE (device_id, invoice_counter),
  INDEX ix_einvoice_pending (status, reporting_due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- كل محاولة إرسال ونتيجتها
CREATE TABLE `einvoice_submissions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `einvoice_document_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`einvoice_document_id`) REFERENCES `einvoice_documents` (`id`) ON DELETE RESTRICT,
  `attempt_number` INT UNSIGNED NOT NULL,
  `request_correlation_id` VARCHAR(100) NOT NULL,
  `started_at` DATETIME(6) NOT NULL,
  `finished_at` DATETIME(6) NULL,
  `http_status` SMALLINT UNSIGNED NULL,
  `result` ENUM('in_progress','accepted','rejected','unknown','connection_error') NOT NULL,
  `response_json` JSON NULL,
  `error_message` TEXT NULL,
  UNIQUE (einvoice_document_id, attempt_number),
  UNIQUE (request_correlation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- مهام موثوقة تسجل مع المعاملة المحلية
CREATE TABLE `outbox_jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `job_type` VARCHAR(60) NOT NULL,
  `dedupe_key` VARCHAR(190) NOT NULL,
  `document_id` BIGINT UNSIGNED,
  FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE RESTRICT,
  `einvoice_document_id` BIGINT UNSIGNED,
  FOREIGN KEY (`einvoice_document_id`) REFERENCES `einvoice_documents` (`id`) ON DELETE RESTRICT,
  `payload_json` JSON NOT NULL,
  `status` ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `available_at` DATETIME(6) NOT NULL,
  `locked_at` DATETIME(6) NULL,
  `locked_by` VARCHAR(100) NULL,
  `last_error` TEXT NULL,
  UNIQUE (dedupe_key),
  INDEX ix_outbox_available (status, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- مرفقات خاصة ومستندات أصلية
CREATE TABLE `attachments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `document_id` BIGINT UNSIGNED,
  FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE RESTRICT,
  `payment_id` BIGINT UNSIGNED,
  FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE RESTRICT,
  `file_name` VARCHAR(255) NOT NULL,
  `storage_key` VARCHAR(500) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `size_bytes` BIGINT UNSIGNED NOT NULL,
  `checksum` CHAR(64) NOT NULL,
  `uploaded_by` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  `uploaded_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CHECK ((document_id IS NOT NULL AND payment_id IS NULL) OR (document_id IS NULL AND payment_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- تتبع التغييرات والعمليات الحساسة
CREATE TABLE `audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT UNSIGNED,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  `entity_type` VARCHAR(80) NOT NULL,
  `entity_id` BIGINT UNSIGNED NULL,
  `action` VARCHAR(60) NOT NULL,
  `before_json` JSON NULL,
  `after_json` JSON NULL,
  `request_id` VARCHAR(100) NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  INDEX ix_audit_entity (entity_type, entity_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE journal_entries ADD FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT;
