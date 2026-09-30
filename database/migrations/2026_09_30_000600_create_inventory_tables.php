<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * stock_balances / stock_reservations / stock_movements / stock_counts / stock_count_lines.
 * تعديل موثق: D6 (عمود مُولّد يمنع أكثر من جلسة جرد نشطة لنفس المخزن).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
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
  -- D6: جلسة جرد نشطة واحدة لكل مخزن (تجميد الحركات في النسخة الأولى)
  `active_marker` BIGINT UNSIGNED GENERATED ALWAYS AS (IF(`status` IN ('draft','counting'), `warehouse_id`, NULL)) STORED,
  UNIQUE (`active_marker`),
  UNIQUE (adjustment_document_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS `stock_count_lines`, `stock_counts`, `stock_movements`, `stock_reservations`, `stock_balances`;');
    }
};
