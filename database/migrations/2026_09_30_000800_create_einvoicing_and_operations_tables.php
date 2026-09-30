<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** einvoice_devices / einvoice_documents / einvoice_submissions / outbox_jobs / attachments / audit_logs */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
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
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS `audit_logs`, `attachments`, `outbox_jobs`, `einvoice_submissions`, `einvoice_documents`, `einvoice_devices`;');
    }
};
