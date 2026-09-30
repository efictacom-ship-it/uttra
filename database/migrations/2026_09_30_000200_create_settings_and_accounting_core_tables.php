<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * business_settings / accounts / accounting_periods / tax_codes من stationery_schema.sql.
 * تعديلات موثقة: D4 (حقول الطباعة والتقريب وبوابة الإعداد)، D3 (جدول account_mappings).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
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
  -- D4: بيانات الطباعة والتواصل وسياسة التقريب وبوابة إتمام الإعداد
  `phone` VARCHAR(40) NULL,
  `email` VARCHAR(190) NULL,
  `logo_path` VARCHAR(500) NULL,
  `receipt_note_ar` VARCHAR(500) NULL,
  `invoice_note_ar` VARCHAR(500) NULL,
  `rounding_decimals` TINYINT UNSIGNED NOT NULL DEFAULT 2,
  `rounding_mode` ENUM('half_up','half_even') NOT NULL DEFAULT 'half_up',
  `fiscal_year_start_month` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `setup_completed_at` DATETIME(6) NULL,
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CHECK (id = 1),
  CHECK (rounding_decimals BETWEEN 0 AND 6),
  CHECK (fiscal_year_start_month BETWEEN 1 AND 12),
  CHECK (vat_registered = FALSE OR vat_number IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE `accounting_periods` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `starts_on` DATE NOT NULL,
  `ends_on` DATE NOT NULL,
  `status` ENUM('open','closed') NOT NULL DEFAULT 'open',
  `closed_by` BIGINT UNSIGNED,
  FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  `closed_at` DATETIME(6) NULL,
  CHECK (ends_on >= starts_on),
  UNIQUE (starts_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- D3: ربط حسابات التشغيل بالحسابات المحاسبية بدل أرقام ثابتة في الكود
CREATE TABLE `account_mappings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `slug` VARCHAR(60) NOT NULL,
  `label_ar` VARCHAR(180) NOT NULL,
  `account_id` BIGINT UNSIGNED NULL,
  FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT,
  `is_required` BOOLEAN NOT NULL DEFAULT TRUE,
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS `account_mappings`, `tax_codes`, `accounting_periods`, `accounts`, `business_settings`;');
    }
};
