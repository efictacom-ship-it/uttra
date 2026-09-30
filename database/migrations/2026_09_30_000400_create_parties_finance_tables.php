<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * parties / warehouses / financial_accounts / cash_sessions.
 * تعديل موثق: D5 (عمود مُولّد يمنع أكثر من صندوق مفتوح لنفس الحساب على مستوى القاعدة).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
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
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(6),
  CHECK (is_customer = TRUE OR is_supplier = TRUE),
  CHECK (credit_limit IS NULL OR credit_limit >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `warehouses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(30) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  UNIQUE (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `financial_accounts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `kind` ENUM('cash','bank','card_clearing') NOT NULL,
  `ledger_account_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`ledger_account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  UNIQUE (ledger_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  -- D5: صندوق واحد مفتوح لكل حساب نقدي، مضمون بالقاعدة لا بالخدمة فقط
  `open_marker` BIGINT UNSIGNED GENERATED ALWAYS AS (IF(`closed_at` IS NULL, `financial_account_id`, NULL)) STORED,
  UNIQUE (`open_marker`),
  CHECK (opening_cash >= 0),
  CHECK (closed_at IS NULL OR closed_at >= opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS `cash_sessions`, `financial_accounts`, `warehouses`, `parties`;');
    }
};
