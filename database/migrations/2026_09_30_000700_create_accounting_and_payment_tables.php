<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * journal_entries / journal_lines / payments / payment_allocations.
 * تعديل موثق: D8 (رسوم تسوية الشبكة على الدفعة).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
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
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `posted_at` DATETIME(6) NULL,
  UNIQUE (entry_number),
  UNIQUE (document_id),
  UNIQUE (payment_id),
  UNIQUE (reverses_entry_id),
  CHECK (NOT (document_id IS NOT NULL AND payment_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  -- D8: رسوم مزود الشبكة عند تسوية حساب الشبكة إلى البنك
  `fee_amount` DECIMAL(19,6) NOT NULL DEFAULT 0,
  `fee_account_id` BIGINT UNSIGNED NULL,
  FOREIGN KEY (`fee_account_id`) REFERENCES `accounts` (`id`) ON DELETE RESTRICT,
  `payment_date` DATE NOT NULL,
  `paid_at` DATETIME(6) NOT NULL,
  `status` ENUM('draft','posted') NOT NULL DEFAULT 'draft',
  `external_reference` VARCHAR(120) NULL,
  `idempotency_key` VARCHAR(100) NOT NULL,
  `notes` VARCHAR(500) NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `posted_at` DATETIME(6) NULL,
  UNIQUE (payment_number),
  UNIQUE (idempotency_key),
  UNIQUE (reverses_payment_id),
  CHECK (amount > 0),
  CHECK (fee_amount >= 0 AND fee_amount < amount),
  CHECK (fee_amount = 0 OR fee_account_id IS NOT NULL),
  CHECK (fee_amount = 0 OR direction = 'transfer'),
  CHECK ((direction = 'transfer' AND destination_financial_account_id IS NOT NULL AND destination_financial_account_id <> financial_account_id) OR (direction <> 'transfer' AND destination_financial_account_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

ALTER TABLE journal_entries ADD FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS `payment_allocations`, `journal_lines`, `journal_entries`, `payments`;');
    }
};
