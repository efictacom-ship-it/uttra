<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * document_sequences / documents / document_lines / document_tax_totals.
 * تعديلات موثقة: D9 (tax_code_id لقطة على السطر)، D7 (منع تكرار صف تجميع الضريبة).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE `document_sequences` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `kind` VARCHAR(40) NOT NULL,
  `fiscal_year` SMALLINT UNSIGNED NOT NULL,
  `prefix` VARCHAR(30) NOT NULL,
  `next_number` BIGINT UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE (kind, fiscal_year),
  CHECK (next_number > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `updated_at` DATETIME(6) NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(6),
  `posted_at` DATETIME(6) NULL,
  UNIQUE (uuid),
  UNIQUE (document_number),
  UNIQUE (idempotency_key),
  CHECK (subtotal >= 0 AND discount_total >= 0 AND net_total >= 0 AND tax_total >= 0 AND grand_total >= 0),
  INDEX ix_documents_kind_date (kind, business_date, status),
  INDEX ix_documents_party_date (party_id, business_date),
  INDEX ix_supplier_reference (party_id, supplier_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  -- D9: لقطة كود الضريبة المستخدم وقت الإصدار لتقارير VAT
  `tax_code_id` BIGINT UNSIGNED NULL,
  FOREIGN KEY (`tax_code_id`) REFERENCES `tax_codes` (`id`) ON DELETE RESTRICT,
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

CREATE TABLE `document_tax_totals` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `document_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE RESTRICT,
  `category` VARCHAR(10) NOT NULL,
  `rate` DECIMAL(7,4) NOT NULL,
  `taxable_amount` DECIMAL(19,6) NOT NULL,
  `tax_amount` DECIMAL(19,6) NOT NULL,
  `reason_code` VARCHAR(50) NULL,
  -- D7: صف واحد لكل تصنيف ونسبة داخل المستند
  UNIQUE (document_id, category, rate),
  CHECK (taxable_amount >= 0 AND tax_amount >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS `document_tax_totals`, `document_lines`, `documents`, `document_sequences`;');
    }
};
