<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** categories / units / items / item_units / item_barcodes / service_recipe_lines */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE `categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `parent_id` BIGINT UNSIGNED,
  FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT,
  `name` VARCHAR(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `units` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(20) NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `einvoice_code` VARCHAR(20) NULL,
  UNIQUE (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  -- D1: تتبع التعديل عبر Eloquent
  `updated_at` DATETIME(6) NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(6),
  UNIQUE (sku),
  CHECK (reorder_level >= 0),
  CHECK (last_purchase_cost IS NULL OR last_purchase_cost >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE `item_barcodes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `item_unit_id` BIGINT UNSIGNED NOT NULL,
  FOREIGN KEY (`item_unit_id`) REFERENCES `item_units` (`id`) ON DELETE RESTRICT,
  `barcode` VARCHAR(100) NOT NULL,
  UNIQUE (barcode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS `service_recipe_lines`, `item_barcodes`, `item_units`, `items`, `units`, `categories`;');
    }
};
