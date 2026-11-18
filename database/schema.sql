-- =====================================================================
-- CALIMAX ERP - Trading / Wholesale / Distribution ERP
-- Database: calimax   (completely separate from the calipos database)
-- Engine: InnoDB, Charset: utf8mb4
--
-- INSTALL:  php database/install.php          (creates DB + schema + demo data)
--      or:  mysql -uroot < database/schema.sql  then  php database/seed_demo.php
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `calimax` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `calimax`;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- SYSTEM: users, audit, settings, numbering
-- ---------------------------------------------------------------------
CREATE TABLE `territories` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(120) NOT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `role` VARCHAR(30) NOT NULL DEFAULT 'sales_rep',
  `territory_id` INT UNSIGNED DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`territory_id`) REFERENCES `territories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `login_attempts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`username`, `attempted_at`), INDEX (`ip_address`, `attempted_at`)
) ENGINE=InnoDB;

CREATE TABLE `activity_log` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(40) DEFAULT NULL,
  `entity_id` INT UNSIGNED DEFAULT NULL,
  `details` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`user_id`), INDEX (`action`), INDEX (`created_at`), INDEX (`entity_type`, `entity_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `settings` (
  `setting_key` VARCHAR(100) PRIMARY KEY,
  `setting_value` TEXT
) ENGINE=InnoDB;

CREATE TABLE `document_sequences` (
  `seq_key` VARCHAR(40) PRIMARY KEY,
  `next_value` INT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- CATALOGUE: categories, products, packaging, pricing
-- ---------------------------------------------------------------------
CREATE TABLE `categories` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE `products` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(40) NOT NULL UNIQUE,
  `barcode` VARCHAR(60) DEFAULT NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `brand` VARCHAR(100) DEFAULT NULL,
  `base_unit` VARCHAR(30) NOT NULL DEFAULT 'Unit',
  `tracking` ENUM('none','batch','serial') NOT NULL DEFAULT 'none',
  `track_expiry` TINYINT(1) NOT NULL DEFAULT 0,
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `avg_cost` DECIMAL(14,4) NOT NULL DEFAULT 0,
  `default_price` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `min_order_qty` INT UNSIGNED NOT NULL DEFAULT 1,
  `reorder_level` INT UNSIGNED NOT NULL DEFAULT 0,
  `warranty_months` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (`name`), INDEX (`barcode`), INDEX (`category_id`),
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Packaging: 1 Carton = 24 base units, etc.
CREATE TABLE `product_units` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(30) NOT NULL,
  `factor` INT UNSIGNED NOT NULL,
  UNIQUE KEY (`product_id`, `name`),
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `customer_categories` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(80) NOT NULL UNIQUE,
  `description` VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB;

-- Price per BASE unit for each customer category.
CREATE TABLE `product_prices` (
  `product_id` INT UNSIGNED NOT NULL,
  `category_id` INT UNSIGNED NOT NULL,
  `price` DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (`product_id`, `category_id`),
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`category_id`) REFERENCES `customer_categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Bulk quantity discounts (min qty in base units).
CREATE TABLE `product_price_breaks` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT UNSIGNED NOT NULL,
  `min_qty` INT UNSIGNED NOT NULL,
  `discount_pct` DECIMAL(5,2) NOT NULL,
  UNIQUE KEY (`product_id`, `min_qty`),
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- WAREHOUSES & STOCK
-- ---------------------------------------------------------------------
CREATE TABLE `warehouses` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(20) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- One row per product / warehouse / batch (batch_id 0 = untracked or serial item).
CREATE TABLE `stock` (
  `product_id` INT UNSIGNED NOT NULL,
  `warehouse_id` INT UNSIGNED NOT NULL,
  `batch_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `on_hand` INT NOT NULL DEFAULT 0,        -- saleable condition
  `quarantine` INT NOT NULL DEFAULT 0,     -- awaiting inspection / blocked
  `damaged` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`product_id`, `warehouse_id`, `batch_id`),
  INDEX (`warehouse_id`),
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`),
  FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `stock_movements` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT UNSIGNED NOT NULL,
  `warehouse_id` INT UNSIGNED NOT NULL,
  `batch_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `serial_id` INT UNSIGNED DEFAULT NULL,
  `bucket` ENUM('on_hand','quarantine','damaged') NOT NULL DEFAULT 'on_hand',
  `change_qty` INT NOT NULL,
  `balance_after` INT NOT NULL DEFAULT 0,
  `type` VARCHAR(30) NOT NULL,
  `reference_type` VARCHAR(40) DEFAULT NULL,
  `reference_id` INT UNSIGNED DEFAULT NULL,
  `reference_no` VARCHAR(40) DEFAULT NULL,
  `unit_cost` DECIMAL(14,4) NOT NULL DEFAULT 0,
  `note` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`product_id`, `warehouse_id`), INDEX (`batch_id`), INDEX (`serial_id`), INDEX (`type`), INDEX (`reference_type`, `reference_id`), INDEX (`created_at`)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- SUPPLIERS & PROCUREMENT
-- ---------------------------------------------------------------------
CREATE TABLE `suppliers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(20) NOT NULL UNIQUE,
  `name` VARCHAR(150) NOT NULL,
  `contact_person` VARCHAR(100) DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `tax_no` VARCHAR(40) DEFAULT NULL,
  `payment_terms_days` INT UNSIGNED NOT NULL DEFAULT 30,
  `notes` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE `purchase_orders` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `po_no` VARCHAR(30) NOT NULL UNIQUE,
  `supplier_id` INT UNSIGNED NOT NULL,
  `warehouse_id` INT UNSIGNED NOT NULL,
  `status` ENUM('draft','pending_approval','approved','sent','partially_received','received','closed','cancelled','rejected') NOT NULL DEFAULT 'draft',
  `order_date` DATE NOT NULL,
  `expected_date` DATE DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `tax_total` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `decision_note` VARCHAR(255) DEFAULT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`supplier_id`), INDEX (`status`),
  FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`),
  FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `purchase_order_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `po_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `pack_name` VARCHAR(30) DEFAULT NULL,
  `pack_factor` INT UNSIGNED NOT NULL DEFAULT 1,
  `qty` INT UNSIGNED NOT NULL,
  `unit_cost` DECIMAL(14,4) NOT NULL,
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `received_qty` INT UNSIGNED NOT NULL DEFAULT 0,
  `line_total` DECIMAL(14,2) NOT NULL DEFAULT 0,
  INDEX (`po_id`),
  FOREIGN KEY (`po_id`) REFERENCES `purchase_orders`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `supplier_invoices` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bill_no` VARCHAR(30) NOT NULL UNIQUE,
  `supplier_ref` VARCHAR(60) NOT NULL,
  `supplier_id` INT UNSIGNED NOT NULL,
  `po_id` INT UNSIGNED DEFAULT NULL,
  `invoice_date` DATE NOT NULL,
  `due_date` DATE NOT NULL,
  `subtotal` DECIMAL(14,2) NOT NULL,
  `tax_total` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total` DECIMAL(14,2) NOT NULL,
  `paid_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `status` ENUM('unpaid','partial','paid','void') NOT NULL DEFAULT 'unpaid',
  `grn_value` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `variance` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `match_status` ENUM('matched','variance','unmatched') NOT NULL DEFAULT 'unmatched',
  `match_note` VARCHAR(255) DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`supplier_id`), INDEX (`status`), INDEX (`due_date`),
  FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `goods_receipts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `grn_no` VARCHAR(30) NOT NULL UNIQUE,
  `po_id` INT UNSIGNED DEFAULT NULL,
  `supplier_id` INT UNSIGNED NOT NULL,
  `warehouse_id` INT UNSIGNED NOT NULL,
  `received_date` DATE NOT NULL,
  `supplier_ref` VARCHAR(60) DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `total_value` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `tax_value` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `supplier_invoice_id` INT UNSIGNED DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`po_id`), INDEX (`supplier_id`),
  FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`),
  FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `grn_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `grn_id` INT UNSIGNED NOT NULL,
  `po_item_id` INT UNSIGNED DEFAULT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `qty_received` INT UNSIGNED NOT NULL,
  `qty_rejected` INT UNSIGNED NOT NULL DEFAULT 0,
  `unit_cost` DECIMAL(14,4) NOT NULL,
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `batch_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `reject_reason` VARCHAR(150) DEFAULT NULL,
  INDEX (`grn_id`),
  FOREIGN KEY (`grn_id`) REFERENCES `goods_receipts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `supplier_payments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `pay_no` VARCHAR(30) NOT NULL UNIQUE,
  `supplier_id` INT UNSIGNED NOT NULL,
  `kind` ENUM('payment','return_credit') NOT NULL DEFAULT 'payment',
  `amount` DECIMAL(14,2) NOT NULL,
  `method` VARCHAR(30) NOT NULL DEFAULT 'bank_transfer',
  `pay_date` DATE NOT NULL,
  `reference` VARCHAR(60) DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`supplier_id`),
  FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `supplier_payment_allocations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `payment_id` INT UNSIGNED NOT NULL,
  `invoice_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  INDEX (`invoice_id`),
  FOREIGN KEY (`payment_id`) REFERENCES `supplier_payments`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`invoice_id`) REFERENCES `supplier_invoices`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `purchase_returns` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `return_no` VARCHAR(30) NOT NULL UNIQUE,
  `supplier_id` INT UNSIGNED NOT NULL,
  `warehouse_id` INT UNSIGNED NOT NULL,
  `return_date` DATE NOT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `total` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `payment_id` INT UNSIGNED DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `purchase_return_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `return_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `batch_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `serial_id` INT UNSIGNED DEFAULT NULL,
  `qty` INT UNSIGNED NOT NULL,
  `unit_cost` DECIMAL(14,4) NOT NULL,
  `from_bucket` VARCHAR(20) NOT NULL DEFAULT 'on_hand',
  FOREIGN KEY (`return_id`) REFERENCES `purchase_returns`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- BATCHES & SERIAL NUMBERS (traceability)
-- ---------------------------------------------------------------------
CREATE TABLE `batches` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT UNSIGNED NOT NULL,
  `batch_no` VARCHAR(60) NOT NULL,
  `supplier_id` INT UNSIGNED DEFAULT NULL,
  `grn_id` INT UNSIGNED DEFAULT NULL,
  `received_date` DATE NOT NULL,
  `mfg_date` DATE DEFAULT NULL,
  `expiry_date` DATE DEFAULT NULL,
  `received_qty` INT UNSIGNED NOT NULL DEFAULT 0,
  `unit_cost` DECIMAL(14,4) NOT NULL DEFAULT 0,
  `status` ENUM('active','quarantined','recalled','expired') NOT NULL DEFAULT 'active',
  `status_note` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY (`product_id`, `batch_no`), INDEX (`expiry_date`), INDEX (`status`),
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `serial_numbers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT UNSIGNED NOT NULL,
  `serial_no` VARCHAR(80) NOT NULL,
  `warehouse_id` INT UNSIGNED DEFAULT NULL,
  `status` ENUM('available','reserved','dispatched','sold','returned','under_inspection','under_repair','damaged','returned_to_supplier','written_off') NOT NULL DEFAULT 'available',
  `supplier_id` INT UNSIGNED DEFAULT NULL,
  `po_id` INT UNSIGNED DEFAULT NULL,
  `grn_id` INT UNSIGNED DEFAULT NULL,
  `received_date` DATE DEFAULT NULL,
  `unit_cost` DECIMAL(14,4) NOT NULL DEFAULT 0,
  `sales_order_id` INT UNSIGNED DEFAULT NULL,
  `delivery_id` INT UNSIGNED DEFAULT NULL,
  `invoice_id` INT UNSIGNED DEFAULT NULL,
  `customer_id` INT UNSIGNED DEFAULT NULL,
  `sold_date` DATE DEFAULT NULL,
  `warranty_months` INT UNSIGNED NOT NULL DEFAULT 0,
  `warranty_expiry` DATE DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY (`product_id`, `serial_no`), INDEX (`serial_no`), INDEX (`status`), INDEX (`customer_id`), INDEX (`warehouse_id`, `status`),
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`)
) ENGINE=InnoDB;

-- Lifecycle timeline for serials and batches.
CREATE TABLE `trace_events` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `entity_type` ENUM('serial','batch') NOT NULL,
  `entity_id` INT UNSIGNED NOT NULL,
  `event_type` VARCHAR(40) NOT NULL,
  `title` VARCHAR(150) NOT NULL,
  `detail` VARCHAR(255) DEFAULT NULL,
  `ref_type` VARCHAR(40) DEFAULT NULL,
  `ref_id` INT UNSIGNED DEFAULT NULL,
  `ref_no` VARCHAR(40) DEFAULT NULL,
  `user_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`entity_type`, `entity_id`)
) ENGINE=InnoDB;

CREATE TABLE `batch_recalls` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `recall_no` VARCHAR(30) NOT NULL UNIQUE,
  `batch_id` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `status` ENUM('open','closed') NOT NULL DEFAULT 'open',
  `initiated_by` INT UNSIGNED DEFAULT NULL,
  `initiated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_at` DATETIME DEFAULT NULL,
  FOREIGN KEY (`batch_id`) REFERENCES `batches`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `recall_customers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `recall_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `qty_delivered` INT NOT NULL DEFAULT 0,
  `qty_recovered` INT NOT NULL DEFAULT 0,
  `status` ENUM('pending','contacted','partial','recovered') NOT NULL DEFAULT 'pending',
  `contacted_at` DATETIME DEFAULT NULL,
  `note` VARCHAR(255) DEFAULT NULL,
  UNIQUE KEY (`recall_id`, `customer_id`),
  FOREIGN KEY (`recall_id`) REFERENCES `batch_recalls`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `warranty_claims` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `claim_no` VARCHAR(30) NOT NULL UNIQUE,
  `serial_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED DEFAULT NULL,
  `invoice_id` INT UNSIGNED DEFAULT NULL,
  `issue` VARCHAR(255) NOT NULL,
  `in_warranty` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('open','in_repair','replaced','resolved','rejected') NOT NULL DEFAULT 'open',
  `resolution` VARCHAR(255) DEFAULT NULL,
  `replacement_serial_id` INT UNSIGNED DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_at` DATETIME DEFAULT NULL,
  FOREIGN KEY (`serial_id`) REFERENCES `serial_numbers`(`id`)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- CUSTOMERS, REPS, DELIVERY RESOURCES
-- ---------------------------------------------------------------------
CREATE TABLE `customers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(20) NOT NULL UNIQUE,
  `business_name` VARCHAR(150) NOT NULL,
  `contact_person` VARCHAR(100) DEFAULT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `city` VARCHAR(80) DEFAULT NULL,
  `tax_no` VARCHAR(40) DEFAULT NULL,
  `category_id` INT UNSIGNED DEFAULT NULL,
  `rep_id` INT UNSIGNED DEFAULT NULL,
  `territory_id` INT UNSIGNED DEFAULT NULL,
  `credit_limit` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `payment_terms_days` INT UNSIGNED NOT NULL DEFAULT 0,
  `notes` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`business_name`), INDEX (`rep_id`), INDEX (`category_id`),
  FOREIGN KEY (`category_id`) REFERENCES `customer_categories`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`rep_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`territory_id`) REFERENCES `territories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `customer_prices` (
  `customer_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `price` DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (`customer_id`, `product_id`),
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `drivers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(30) DEFAULT NULL,
  `licence_no` VARCHAR(40) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE `vehicles` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `reg_no` VARCHAR(30) NOT NULL UNIQUE,
  `type` VARCHAR(50) DEFAULT NULL,
  `capacity_kg` INT UNSIGNED DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE `rep_targets` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED NOT NULL,
  `month` CHAR(7) NOT NULL,
  `target_amount` DECIMAL(14,2) NOT NULL,
  UNIQUE KEY (`user_id`, `month`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- SALES: orders, deliveries, invoices, receipts, credit notes, returns
-- ---------------------------------------------------------------------
CREATE TABLE `sales_orders` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `so_no` VARCHAR(30) NOT NULL UNIQUE,
  `customer_id` INT UNSIGNED NOT NULL,
  `rep_id` INT UNSIGNED DEFAULT NULL,
  `warehouse_id` INT UNSIGNED NOT NULL,
  `order_date` DATE NOT NULL,
  `required_date` DATE DEFAULT NULL,
  `payment_type` ENUM('cash','credit') NOT NULL DEFAULT 'credit',
  `status` ENUM('draft','pending_approval','confirmed','partially_fulfilled','dispatched','completed','cancelled','rejected') NOT NULL DEFAULT 'draft',
  `customer_po_ref` VARCHAR(60) DEFAULT NULL,
  `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `discount_total` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `tax_total` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `approval_reason` VARCHAR(500) DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `decision_note` VARCHAR(255) DEFAULT NULL,
  `cancel_reason` VARCHAR(255) DEFAULT NULL,
  `cancelled_by` INT UNSIGNED DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`customer_id`), INDEX (`rep_id`), INDEX (`status`), INDEX (`order_date`),
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`),
  FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `sales_order_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `so_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `pack_name` VARCHAR(30) DEFAULT NULL,
  `pack_factor` INT UNSIGNED NOT NULL DEFAULT 1,
  `qty` INT UNSIGNED NOT NULL,
  `unit_price` DECIMAL(14,4) NOT NULL,
  `list_price` DECIMAL(14,4) NOT NULL DEFAULT 0,
  `discount_pct` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `line_net` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `tax_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `line_total` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `qty_dispatched` INT UNSIGNED NOT NULL DEFAULT 0,
  `qty_cancelled` INT UNSIGNED NOT NULL DEFAULT 0,
  INDEX (`so_id`), INDEX (`product_id`),
  FOREIGN KEY (`so_id`) REFERENCES `sales_orders`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `deliveries` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `dn_no` VARCHAR(30) NOT NULL UNIQUE,
  `so_id` INT UNSIGNED NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `warehouse_id` INT UNSIGNED NOT NULL,
  `status` ENUM('pending_pick','picked','packed','dispatched','delivered','failed','cancelled') NOT NULL DEFAULT 'pending_pick',
  `planned_date` DATE DEFAULT NULL,
  `picked_by` INT UNSIGNED DEFAULT NULL,
  `picked_at` DATETIME DEFAULT NULL,
  `packed_by` INT UNSIGNED DEFAULT NULL,
  `packed_at` DATETIME DEFAULT NULL,
  `driver_id` INT UNSIGNED DEFAULT NULL,
  `vehicle_id` INT UNSIGNED DEFAULT NULL,
  `dispatched_by` INT UNSIGNED DEFAULT NULL,
  `dispatched_at` DATETIME DEFAULT NULL,
  `delivered_at` DATETIME DEFAULT NULL,
  `received_by_name` VARCHAR(100) DEFAULT NULL,
  `pod_note` VARCHAR(255) DEFAULT NULL,
  `failure_reason` VARCHAR(255) DEFAULT NULL,
  `has_discrepancy` TINYINT(1) NOT NULL DEFAULT 0,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`so_id`), INDEX (`status`), INDEX (`customer_id`),
  FOREIGN KEY (`so_id`) REFERENCES `sales_orders`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `delivery_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `delivery_id` INT UNSIGNED NOT NULL,
  `so_item_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `qty_planned` INT UNSIGNED NOT NULL,
  `qty_picked` INT UNSIGNED NOT NULL DEFAULT 0,
  `qty_accepted` INT UNSIGNED NOT NULL DEFAULT 0,
  `qty_rejected` INT UNSIGNED NOT NULL DEFAULT 0,
  `reject_reason` VARCHAR(150) DEFAULT NULL,
  `qty_invoiced` INT UNSIGNED NOT NULL DEFAULT 0,
  INDEX (`delivery_id`),
  FOREIGN KEY (`delivery_id`) REFERENCES `deliveries`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`so_item_id`) REFERENCES `sales_order_items`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `delivery_allocations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `delivery_item_id` INT UNSIGNED NOT NULL,
  `batch_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `serial_id` INT UNSIGNED DEFAULT NULL,
  `qty` INT UNSIGNED NOT NULL,
  `unit_cost` DECIMAL(14,4) NOT NULL DEFAULT 0,
  `rejected_qty` INT UNSIGNED NOT NULL DEFAULT 0,
  INDEX (`delivery_item_id`), INDEX (`batch_id`), INDEX (`serial_id`),
  FOREIGN KEY (`delivery_item_id`) REFERENCES `delivery_items`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `sales_invoices` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `inv_no` VARCHAR(30) NOT NULL UNIQUE,
  `so_id` INT UNSIGNED DEFAULT NULL,
  `delivery_id` INT UNSIGNED DEFAULT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `rep_id` INT UNSIGNED DEFAULT NULL,
  `warehouse_id` INT UNSIGNED DEFAULT NULL,
  `invoice_date` DATE NOT NULL,
  `due_date` DATE NOT NULL,
  `payment_type` ENUM('cash','credit') NOT NULL DEFAULT 'credit',
  `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `tax_total` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `paid_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `credited_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `status` ENUM('unpaid','partial','paid','void') NOT NULL DEFAULT 'unpaid',
  `cogs` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`customer_id`), INDEX (`rep_id`), INDEX (`status`), INDEX (`invoice_date`), INDEX (`due_date`),
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `sales_invoice_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `invoice_id` INT UNSIGNED NOT NULL,
  `delivery_item_id` INT UNSIGNED DEFAULT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `pack_name` VARCHAR(30) DEFAULT NULL,
  `pack_factor` INT UNSIGNED NOT NULL DEFAULT 1,
  `qty` INT UNSIGNED NOT NULL,
  `unit_price` DECIMAL(14,4) NOT NULL,
  `discount_pct` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `line_net` DECIMAL(14,2) NOT NULL,
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `tax_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `line_total` DECIMAL(14,2) NOT NULL,
  `unit_cost` DECIMAL(14,4) NOT NULL DEFAULT 0,
  `returned_qty` INT UNSIGNED NOT NULL DEFAULT 0,
  INDEX (`invoice_id`), INDEX (`product_id`),
  FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `customer_payments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `receipt_no` VARCHAR(30) NOT NULL UNIQUE,
  `customer_id` INT UNSIGNED NOT NULL,
  `rep_id` INT UNSIGNED DEFAULT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `allocated` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `method` VARCHAR(30) NOT NULL DEFAULT 'cash',
  `pay_date` DATE NOT NULL,
  `reference` VARCHAR(60) DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`customer_id`), INDEX (`pay_date`), INDEX (`rep_id`),
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `customer_payment_allocations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `payment_id` INT UNSIGNED NOT NULL,
  `invoice_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  INDEX (`invoice_id`),
  FOREIGN KEY (`payment_id`) REFERENCES `customer_payments`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `sales_returns` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `rma_no` VARCHAR(30) NOT NULL UNIQUE,
  `customer_id` INT UNSIGNED NOT NULL,
  `invoice_id` INT UNSIGNED NOT NULL,
  `warehouse_id` INT UNSIGNED NOT NULL,
  `status` ENUM('requested','approved','received','closed','rejected') NOT NULL DEFAULT 'requested',
  `reason` VARCHAR(255) DEFAULT NULL,
  `decision_note` VARCHAR(255) DEFAULT NULL,
  `requested_by` INT UNSIGNED DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `received_at` DATETIME DEFAULT NULL,
  `closed_at` DATETIME DEFAULT NULL,
  `credit_note_id` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`customer_id`), INDEX (`invoice_id`), INDEX (`status`),
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`),
  FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `sales_return_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `return_id` INT UNSIGNED NOT NULL,
  `invoice_item_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `batch_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `serial_id` INT UNSIGNED DEFAULT NULL,
  `qty` INT UNSIGNED NOT NULL,
  `unit_price` DECIMAL(14,4) NOT NULL,
  `discount_pct` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `tax_rate` DECIMAL(5,2) NOT NULL DEFAULT 0,
  `unit_cost` DECIMAL(14,4) NOT NULL DEFAULT 0,
  `reason` VARCHAR(150) DEFAULT NULL,
  `condition_note` VARCHAR(255) DEFAULT NULL,
  `disposition` ENUM('pending','restock','repair','replace','return_supplier','dispose') NOT NULL DEFAULT 'pending',
  `give_credit` TINYINT(1) NOT NULL DEFAULT 1,
  `resolved_at` DATETIME DEFAULT NULL,
  INDEX (`return_id`),
  FOREIGN KEY (`return_id`) REFERENCES `sales_returns`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `credit_notes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `cn_no` VARCHAR(30) NOT NULL UNIQUE,
  `customer_id` INT UNSIGNED NOT NULL,
  `invoice_id` INT UNSIGNED NOT NULL,
  `return_id` INT UNSIGNED DEFAULT NULL,
  `cn_date` DATE NOT NULL,
  `subtotal` DECIMAL(14,2) NOT NULL,
  `tax_total` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `total` DECIMAL(14,2) NOT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`customer_id`), INDEX (`invoice_id`),
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`),
  FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices`(`id`)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- WAREHOUSE OPERATIONS: transfers, adjustments, counts
-- ---------------------------------------------------------------------
CREATE TABLE `stock_transfers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `tr_no` VARCHAR(30) NOT NULL UNIQUE,
  `from_wh` INT UNSIGNED NOT NULL,
  `to_wh` INT UNSIGNED NOT NULL,
  `status` ENUM('draft','in_transit','received','cancelled') NOT NULL DEFAULT 'draft',
  `transfer_date` DATE NOT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `sent_by` INT UNSIGNED DEFAULT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  `received_by` INT UNSIGNED DEFAULT NULL,
  `received_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`from_wh`) REFERENCES `warehouses`(`id`),
  FOREIGN KEY (`to_wh`) REFERENCES `warehouses`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `stock_transfer_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `transfer_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `batch_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `qty_sent` INT UNSIGNED NOT NULL,
  `qty_received` INT UNSIGNED NOT NULL DEFAULT 0,
  `unit_cost` DECIMAL(14,4) NOT NULL DEFAULT 0,
  FOREIGN KEY (`transfer_id`) REFERENCES `stock_transfers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `stock_transfer_serials` (
  `transfer_id` INT UNSIGNED NOT NULL,
  `serial_id` INT UNSIGNED NOT NULL,
  `received` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`transfer_id`, `serial_id`),
  FOREIGN KEY (`transfer_id`) REFERENCES `stock_transfers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `stock_adjustments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `adj_no` VARCHAR(30) NOT NULL UNIQUE,
  `warehouse_id` INT UNSIGNED NOT NULL,
  `reason` VARCHAR(30) NOT NULL,
  `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `source` ENUM('manual','count') NOT NULL DEFAULT 'manual',
  `count_id` INT UNSIGNED DEFAULT NULL,
  `note` VARCHAR(255) DEFAULT NULL,
  `value_change` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `requested_by` INT UNSIGNED DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `stock_adjustment_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `adjustment_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `batch_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `serial_id` INT UNSIGNED DEFAULT NULL,
  `qty_change` INT NOT NULL,
  `unit_cost` DECIMAL(14,4) NOT NULL DEFAULT 0,
  `note` VARCHAR(255) DEFAULT NULL,
  FOREIGN KEY (`adjustment_id`) REFERENCES `stock_adjustments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `stock_counts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `count_no` VARCHAR(30) NOT NULL UNIQUE,
  `warehouse_id` INT UNSIGNED NOT NULL,
  `status` ENUM('open','submitted','approved','cancelled') NOT NULL DEFAULT 'open',
  `note` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `submitted_at` DATETIME DEFAULT NULL,
  `approved_by` INT UNSIGNED DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `stock_count_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `count_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `batch_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `system_qty` INT NOT NULL,
  `counted_qty` INT DEFAULT NULL,
  FOREIGN KEY (`count_id`) REFERENCES `stock_counts`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- ACCOUNTING: chart of accounts, double-entry journal, expenses
-- ---------------------------------------------------------------------
CREATE TABLE `accounts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(10) NOT NULL UNIQUE,
  `name` VARCHAR(100) NOT NULL,
  `type` ENUM('asset','liability','equity','income','cogs','expense') NOT NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE `journal_entries` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `je_no` VARCHAR(30) NOT NULL UNIQUE,
  `entry_date` DATE NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `ref_type` VARCHAR(40) DEFAULT NULL,
  `ref_id` INT UNSIGNED DEFAULT NULL,
  `ref_no` VARCHAR(40) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`entry_date`), INDEX (`ref_type`, `ref_id`)
) ENGINE=InnoDB;

CREATE TABLE `journal_lines` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `entry_id` INT UNSIGNED NOT NULL,
  `account_id` INT UNSIGNED NOT NULL,
  `debit` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `credit` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `memo` VARCHAR(150) DEFAULT NULL,
  INDEX (`account_id`),
  FOREIGN KEY (`entry_id`) REFERENCES `journal_entries`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `expense_categories` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `account_id` INT UNSIGNED NOT NULL,
  FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`)
) ENGINE=InnoDB;

CREATE TABLE `expenses` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `exp_no` VARCHAR(30) NOT NULL UNIQUE,
  `category_id` INT UNSIGNED NOT NULL,
  `expense_date` DATE NOT NULL,
  `amount` DECIMAL(14,2) NOT NULL,
  `method` VARCHAR(30) NOT NULL DEFAULT 'cash',
  `payee` VARCHAR(120) DEFAULT NULL,
  `reference` VARCHAR(60) DEFAULT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX (`expense_date`),
  FOREIGN KEY (`category_id`) REFERENCES `expense_categories`(`id`)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- ESSENTIAL SEED DATA (chart of accounts, settings, owner account)
-- ---------------------------------------------------------------------
INSERT INTO `accounts` (`code`,`name`,`type`,`is_system`) VALUES
('1000','Cash in Hand','asset',1),
('1010','Bank Account','asset',1),
('1100','Accounts Receivable','asset',1),
('1200','Inventory','asset',1),
('1300','Input Tax (VAT Receivable)','asset',1),
('2000','Accounts Payable','liability',1),
('2100','Goods Received Not Invoiced','liability',1),
('2200','Output Tax (VAT Payable)','liability',1),
('3000','Owner''s Capital','equity',1),
('4000','Sales Revenue','income',1),
('4100','Sales Returns & Allowances','income',1),
('4900','Other Income','income',0),
('5000','Cost of Goods Sold','cogs',1),
('5100','Inventory Adjustments & Write-offs','cogs',1),
('5200','Purchase Price Variance','cogs',1),
('6000','Warehouse Rent','expense',0),
('6010','Employee Salaries','expense',0),
('6020','Vehicle Fuel','expense',0),
('6030','Transportation','expense',0),
('6040','Vehicle Maintenance','expense',0),
('6050','Electricity & Utilities','expense',0),
('6060','Packaging','expense',0),
('6070','Office Expenses','expense',0),
('6900','Other Expenses','expense',0);

INSERT INTO `expense_categories` (`name`,`account_id`)
SELECT n, (SELECT id FROM accounts WHERE code = c) FROM (
  SELECT 'Warehouse rent' n, '6000' c UNION ALL SELECT 'Employee salaries','6010' UNION ALL SELECT 'Vehicle fuel','6020'
  UNION ALL SELECT 'Transportation','6030' UNION ALL SELECT 'Vehicle maintenance','6040' UNION ALL SELECT 'Electricity','6050'
  UNION ALL SELECT 'Packaging','6060' UNION ALL SELECT 'Office expenses','6070' UNION ALL SELECT 'Other','6900') t;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('company_name', 'Calimax Distributors (Pvt) Ltd'),
('company_address', 'No. 45, Baseline Road, Colombo 09'),
('company_phone', '+94 11 234 5678'),
('company_email', 'info@calimax.lk'),
('tax_name', 'VAT'),
('tax_no', 'VAT 114455667-7000'),
('currency_symbol', 'Rs.'),
('allocation_policy', 'fefo'),
('expiry_warning_days', '90'),
('expiry_critical_days', '30'),
('credit_include_open_orders', '1'),
('flag_overdue_customers', '1'),
('max_rep_discount_pct', '5'),
('po_approval_threshold', '500000'),
('invoice_match_tolerance_pct', '1'),
('default_payment_terms', '30'),
('so_prefix', 'SO'), ('po_prefix', 'PO'), ('grn_prefix', 'GRN'), ('dn_prefix', 'DN'), ('inv_prefix', 'INV');

-- Owner account: username "admin", password "admin123" (CHANGE THIS IN PRODUCTION)
INSERT INTO `users` (`username`, `password_hash`, `full_name`, `email`, `role`, `is_active`) VALUES
('admin', '$2y$10$A1Djd5beAmr4hIRvvjK.eeSK85AQSpOGhzxHCy0H5jHhEmDHgeevS', 'Business Owner', 'tech@shopq.lk', 'owner', 1);

