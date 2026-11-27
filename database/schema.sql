-- SmartSys baseline schema: result of migrations 001-051.
-- Generated from a local migration-038 snapshot, then upgraded with the official runner.
-- Generated and verified with MariaDB 10.4.32; other server versions need compatibility testing.
-- Fresh-database bootstrap only; migration ledger is included. No application records are included.
SET NAMES utf8mb4;


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `accounting_periods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `period_name` varchar(100) NOT NULL,
  `start_date` datetime NOT NULL,
  `end_date` datetime NOT NULL,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `closed_by` int(11) DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `status` (`status`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `accounting_periods_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `accounting_periods_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(255) NOT NULL,
  `type` enum('asset','liability','equity','revenue','expense') NOT NULL,
  `debit_balance` decimal(12,2) DEFAULT 0.00,
  `credit_balance` decimal(12,2) DEFAULT 0.00,
  `is_active` tinyint(1) DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_id` (`tenant_id`,`code`),
  KEY `idx_accounts_tenant` (`tenant_id`),
  CONSTRAINT `fk_accounts_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=79 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(50) DEFAULT NULL,
  `entity` varchar(50) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `tenant_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_auditlog_user_id` (`user_id`),
  KEY `inventory/fk_auditlog_tenant` (`tenant_id`),
  KEY `idx_audit_tenant_action` (`tenant_id`,`action`),
  KEY `idx_audit_created_at` (`created_at`),
  CONSTRAINT `fk_auditlog_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory/fk_auditlog_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=169 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `blocked_ips` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `reason` enum('trial_abuse','rate_limit','suspicious_activity','manual_block') NOT NULL,
  `blocked_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `blocked_until` timestamp NULL DEFAULT NULL,
  `is_permanent` tinyint(1) DEFAULT 0,
  `blocked_by` varchar(50) DEFAULT 'system',
  `notes` text DEFAULT NULL,
  `attempt_count` int(11) DEFAULT 0,
  `last_attempt_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_address` (`ip_address`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_blocked_until` (`blocked_until`),
  KEY `idx_reason` (`reason`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branch_products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `quantity` int(11) DEFAULT 0,
  `last_update` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `quantity_cost` decimal(15,4) DEFAULT NULL,
  `gl_reconciled` tinyint(1) DEFAULT 0,
  `last_gl_posting_date` datetime DEFAULT NULL,
  `minimum_quantity` decimal(10,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_warehouse_product_tenant` (`branch_id`,`product_id`,`tenant_id`),
  KEY `warehouse_id` (`branch_id`),
  KEY `product_id` (`product_id`),
  KEY `idx_warehouse_id` (`branch_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_branch_products_gl_status` (`gl_reconciled`),
  KEY `idx_bp_tenant_branch_product` (`tenant_id`,`branch_id`,`product_id`),
  KEY `idx_quantity_min` (`quantity`),
  CONSTRAINT `fk_warehouse_products_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_warehouse_products_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_warehouse_products_warehouse` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=615 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branch_settings` (
  `tenant_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `key_name` varchar(100) NOT NULL,
  `value` text NOT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'string',
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`tenant_id`,`branch_id`,`key_name`),
  KEY `idx_branch_settings_branch` (`branch_id`,`tenant_id`),
  KEY `fk_branch_settings_updated_by` (`updated_by`),
  CONSTRAINT `fk_branch_settings_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_branch_settings_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_branch_settings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) DEFAULT NULL,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `location` varchar(500) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `cost_center_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_branch_tenant` (`id`,`tenant_id`),
  KEY `fk_warehouses_tenant_1` (`tenant_id`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_active` (`active`),
  KEY `idx_warehouses_account_id` (`account_id`),
  KEY `idx_warehouses_cost_center_id` (`cost_center_id`),
  KEY `fk_warehouses_created_by` (`created_by`),
  CONSTRAINT `fk_warehouses_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_warehouses_cost_center` FOREIGN KEY (`cost_center_id`) REFERENCES `cost_centers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_warehouses_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_warehouses_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cash_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `customer_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `type` enum('income','expense','return_receipt','return_payment') NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `payment_method_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `return_id` int(11) DEFAULT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'completed',
  `notes` text DEFAULT NULL,
  `journal_entry_id` int(11) DEFAULT NULL,
  `session_id` int(11) DEFAULT NULL,
  `cost_center_id` int(11) DEFAULT NULL COMMENT 'مركز التكلفة (اختياري)',
  PRIMARY KEY (`id`),
  KEY `payment_method_id` (`payment_method_id`),
  KEY `created_by` (`created_by`),
  KEY `fk_cash_transactions_tenant` (`tenant_id`),
  KEY `fk_cash_transactions_customer` (`customer_id`),
  KEY `fk_cash_transactions_supplier` (`supplier_id`),
  KEY `idx_journal_entry_id` (`journal_entry_id`),
  KEY `idx_cashtrx_session` (`session_id`),
  KEY `idx_cash_transactions_cost_center_id` (`cost_center_id`),
  KEY `idx_cash_transactions_warehouse_id` (`branch_id`),
  KEY `fk040_cash_transactions_return_id` (`return_id`),
  CONSTRAINT `cash_transactions_ibfk_1` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`),
  CONSTRAINT `cash_transactions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk040_cash_transactions_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_cash_transactions_return_id` FOREIGN KEY (`return_id`) REFERENCES `returns` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_cash_transactions_session_id` FOREIGN KEY (`session_id`) REFERENCES `cashier_sessions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cash_transactions_cost_center` FOREIGN KEY (`cost_center_id`) REFERENCES `cost_centers` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_cash_transactions_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cash_transactions_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cash_transactions_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cash_tx_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cash_vouchers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `type` enum('receipt','payment') NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'posted',
  `date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency` varchar(10) DEFAULT 'EGP',
  `customer_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `account_id` int(11) NOT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `journal_entry_id` int(11) DEFAULT NULL,
  `cost_center_id` int(11) DEFAULT NULL,
  `idempotency_key` varchar(255) DEFAULT NULL,
  `request_hash` char(64) DEFAULT NULL,
  `transaction_currency_code` char(3) DEFAULT NULL,
  `base_currency_code` char(3) DEFAULT NULL,
  `exchange_rate` decimal(24,12) DEFAULT NULL,
  `base_amount` decimal(18,4) DEFAULT NULL,
  `foreign_total_amount` decimal(18,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cash_vouchers_tenant_idempotency_key` (`tenant_id`,`idempotency_key`),
  KEY `customer_id` (`customer_id`),
  KEY `supplier_id` (`supplier_id`),
  KEY `account_id` (`account_id`),
  KEY `idx_cash_vouchers_tenant` (`tenant_id`),
  KEY `idx_cash_vouchers_branch` (`branch_id`),
  KEY `fk_cash_vouchers_journal_entry` (`journal_entry_id`),
  KEY `idx_cash_vouchers_cost_center_id` (`cost_center_id`),
  KEY `fk040_cash_vouchers_created_by` (`created_by`),
  CONSTRAINT `cash_vouchers_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `cash_vouchers_ibfk_2` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `cash_vouchers_ibfk_3` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `cash_vouchers_ibfk_4` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `fk040_cash_vouchers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cash_vouchers_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_cash_vouchers_cost_center` FOREIGN KEY (`cost_center_id`) REFERENCES `cost_centers` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_cash_vouchers_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cashier_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `cashier_id` int(11) NOT NULL,
  `device_id` varchar(64) DEFAULT NULL,
  `device_name` varchar(255) DEFAULT NULL,
  `session_type` enum('morning','evening','manual','daily','admin') NOT NULL DEFAULT 'manual',
  `start_time` datetime NOT NULL DEFAULT current_timestamp(),
  `end_time` datetime DEFAULT NULL,
  `opening_cash_amount` decimal(12,2) DEFAULT 0.00,
  `closing_cash_amount` decimal(12,2) DEFAULT NULL,
  `variance_reason` text DEFAULT NULL,
  `status` enum('open','closed','cancelled') NOT NULL DEFAULT 'open',
  `created_by` int(11) DEFAULT NULL,
  `closed_by` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `terminal_id` int(10) unsigned DEFAULT NULL,
  `shift_id` bigint(20) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cs_tenant` (`tenant_id`),
  KEY `idx_cs_cashier` (`cashier_id`),
  KEY `idx_cs_status` (`status`),
  KEY `idx_cs_warehouse` (`branch_id`),
  KEY `fk_session_created_by` (`created_by`),
  KEY `idx_cs_t_wh_c_start` (`tenant_id`,`branch_id`,`cashier_id`,`start_time`),
  KEY `idx_cs_t_wh_c_status` (`tenant_id`,`branch_id`,`cashier_id`,`status`),
  KEY `fk_cs_closed_by` (`closed_by`),
  KEY `idx_cashier_sessions_terminal` (`tenant_id`,`branch_id`,`terminal_id`),
  KEY `idx_cashier_sessions_shift` (`tenant_id`,`shift_id`),
  KEY `idx_cs_tenant_branch_status` (`tenant_id`,`branch_id`,`status`),
  KEY `idx_cs_cashier_status` (`cashier_id`,`status`),
  KEY `fk040_cashier_sessions_terminal_id` (`terminal_id`),
  KEY `fk040_cashier_sessions_shift_id` (`shift_id`),
  CONSTRAINT `fk040_cashier_sessions_shift_id` FOREIGN KEY (`shift_id`) REFERENCES `cashier_shifts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_cashier_sessions_terminal_id` FOREIGN KEY (`terminal_id`) REFERENCES `terminals` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cashier_id` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_cs_closed_by` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_cs_warehouse_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_session_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_session_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_session_warehouse` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cashier_shifts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `terminal_id` bigint(20) unsigned DEFAULT NULL,
  `cashier_id` bigint(20) unsigned DEFAULT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime DEFAULT NULL,
  `opening_cash_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `closing_cash_amount` decimal(15,2) DEFAULT NULL,
  `status` enum('open','closed','force_closed') NOT NULL DEFAULT 'open',
  `notes` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `closed_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_shifts_tenant` (`tenant_id`),
  KEY `idx_shifts_tenant_wh_term_status` (`tenant_id`,`branch_id`,`terminal_id`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) DEFAULT NULL,
  `branch_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `parent_id` (`parent_id`),
  KEY `fk_categories_tenant` (`tenant_id`),
  KEY `idx_branch_tenant` (`branch_id`,`tenant_id`),
  CONSTRAINT `categories_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_categories_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_categories_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cost_centers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `code` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk040_cost_centers_tenant_id` (`tenant_id`),
  CONSTRAINT `fk040_cost_centers_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=65 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `currency_exchange_rates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `base_currency_code` char(3) NOT NULL,
  `quote_currency_code` char(3) NOT NULL,
  `rate` decimal(24,12) NOT NULL,
  `provider` varchar(64) NOT NULL,
  `rate_date` date NOT NULL,
  `fetched_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_currency_exchange_rate_day` (`tenant_id`,`base_currency_code`,`quote_currency_code`,`rate_date`),
  KEY `idx_currency_exchange_rates_lookup` (`tenant_id`,`base_currency_code`,`quote_currency_code`,`rate_date`),
  CONSTRAINT `fk_currency_exchange_rates_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=64 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customer_payment_allocations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `payment_id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `allocated_amount` decimal(15,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_payment_allocations_payment_sale` (`tenant_id`,`payment_id`,`sale_id`),
  KEY `idx_customer_payment_allocations_sale` (`tenant_id`,`sale_id`),
  KEY `idx_customer_payment_allocations_payment` (`tenant_id`,`payment_id`),
  KEY `fk_customer_payment_allocations_payment` (`payment_id`),
  KEY `fk_customer_payment_allocations_sale` (`sale_id`),
  CONSTRAINT `fk040_customer_payment_allocations_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_customer_payment_allocations_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_customer_payment_allocations_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `tax_number` varchar(50) DEFAULT NULL,
  `credit_limit` decimal(10,2) DEFAULT 0.00,
  `address` varchar(255) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `account_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_customers_tenant_1` (`tenant_id`),
  KEY `fk_customers_accounts` (`account_id`),
  KEY `idx_customers_branch_id` (`branch_id`),
  CONSTRAINT `fk040_customers_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_customers_accounts` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_customers_tenant_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `device_fingerprints` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fingerprint_hash` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent_hash` varchar(255) NOT NULL,
  `first_seen_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_seen_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `usage_count` int(11) DEFAULT 1,
  `is_suspicious` tinyint(1) DEFAULT 0,
  `suspicion_reason` text DEFAULT NULL,
  `risk_score` decimal(3,2) DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fingerprint_hash` (`fingerprint_hash`),
  KEY `idx_fingerprint_hash` (`fingerprint_hash`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_user_agent_hash` (`user_agent_hash`),
  KEY `idx_risk_score` (`risk_score`),
  KEY `idx_is_suspicious` (`is_suspicious`),
  KEY `idx_last_seen_at` (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_verification_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `token` text DEFAULT NULL,
  `token_hash` varchar(255) NOT NULL,
  `purpose` enum('registration','email_change','password_reset','account_recovery') NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `used_at` timestamp NULL DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `device_fingerprint` varchar(255) DEFAULT NULL,
  `attempts` int(11) DEFAULT 0,
  `max_attempts` int(11) DEFAULT 3,
  `is_revoked` tinyint(1) DEFAULT 0,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `revoked_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  UNIQUE KEY `token` (`token`(255)) USING BTREE,
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_email` (`email`),
  KEY `idx_token_hash` (`token_hash`),
  KEY `idx_purpose` (`purpose`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_is_revoked` (`is_revoked`),
  CONSTRAINT `fk040_email_verification_tokens_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk040_email_verification_tokens_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_cost_snapshot` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `product_branch_mapping_id` int(11) DEFAULT NULL,
  `layer_date` date NOT NULL,
  `layer_sequence` int(11) NOT NULL,
  `unit_cost` decimal(12,4) NOT NULL,
  `quantity_received` decimal(16,4) NOT NULL,
  `quantity_remaining` decimal(16,4) NOT NULL,
  `total_cost` decimal(15,4) GENERATED ALWAYS AS (`unit_cost` * `quantity_remaining`) STORED,
  `source_type` enum('OPENING_BALANCE','PURCHASE_INVOICE','TRANSFER') DEFAULT 'PURCHASE_INVOICE',
  `source_document_id` int(11) DEFAULT NULL,
  `is_fully_consumed` tinyint(1) DEFAULT 0,
  `depletion_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_ics_mapping` (`product_branch_mapping_id`),
  KEY `idx_ics_fifo_lookup` (`product_id`,`branch_id`,`layer_sequence`),
  KEY `fk040_inventory_cost_snapshot_tenant_id` (`tenant_id`),
  KEY `fk040_inventory_cost_snapshot_branch_id` (`branch_id`),
  CONSTRAINT `fk040_inventory_cost_snapshot_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk040_inventory_cost_snapshot_product_id` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk040_inventory_cost_snapshot_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_ics_mapping` FOREIGN KEY (`product_branch_mapping_id`) REFERENCES `product_branch_gl_mapping` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=68 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tracks inventory cost layers for FIFO/LIFO valuation';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `branch_from` int(11) DEFAULT NULL,
  `branch_to` int(11) DEFAULT NULL,
  `quantity` decimal(16,4) NOT NULL,
  `unit_cost` decimal(15,4) DEFAULT NULL,
  `total_cost` decimal(15,4) DEFAULT NULL,
  `movement_type` varchar(50) NOT NULL,
  `movement_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `batch_number` varchar(100) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `serial` varchar(255) DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_id` int(11) DEFAULT NULL,
  `journal_entry_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `warehouse_from` (`branch_from`),
  KEY `warehouse_to` (`branch_to`),
  KEY `fk_inventory_transactions_tenant_1` (`tenant_id`),
  KEY `fk_inventory_transactions_user` (`user_id`),
  KEY `idx_tenant_product_date` (`tenant_id`,`product_id`,`movement_date`),
  KEY `idx_tenant_product` (`tenant_id`,`product_id`),
  KEY `idx_date_product` (`movement_date`,`product_id`),
  KEY `idx_reference` (`reference_type`,`reference_id`),
  KEY `idx_it_warehouses` (`branch_from`,`branch_to`),
  KEY `idx_it_movement_type` (`movement_type`),
  KEY `idx_it_journal` (`journal_entry_id`),
  KEY `idx_inv_tx_tenant_product` (`tenant_id`,`product_id`),
  KEY `idx_inv_tx_tenant_branch` (`tenant_id`,`branch_from`),
  KEY `idx_inv_tx_movement_date` (`movement_date`),
  KEY `idx_inv_tx_reference` (`reference_type`,`reference_id`),
  KEY `fk040_inventory_transactions_unit_id` (`unit_id`),
  CONSTRAINT `fk040_inventory_transactions_journal_entry_id` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_inventory_transactions_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_transactions_tenant_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inventory_transactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_transactions_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `inventory_transactions_ibfk_2` FOREIGN KEY (`branch_from`) REFERENCES `branches` (`id`),
  CONSTRAINT `inventory_transactions_ibfk_3` FOREIGN KEY (`branch_to`) REFERENCES `branches` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=107 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `invoice_approvals` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `action` enum('submit','approve','reject','override') NOT NULL,
  `previous_status` varchar(20) DEFAULT NULL,
  `new_status` varchar(20) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `action_by` int(11) DEFAULT NULL,
  `action_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_invoice_approvals_tenant_sale` (`tenant_id`,`sale_id`),
  KEY `idx_invoice_approvals_action_at` (`action_at`),
  KEY `fk040_invoice_approvals_sale_id` (`sale_id`),
  KEY `fk040_invoice_approvals_action_by` (`action_by`),
  CONSTRAINT `fk040_invoice_approvals_action_by` FOREIGN KEY (`action_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_invoice_approvals_sale_id` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`),
  CONSTRAINT `fk040_invoice_approvals_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=122 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `journal_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `voucher_id` int(11) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('draft','posted','cancelled','reversed') DEFAULT 'draft',
  `entry_date` date DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `cost_center_id` int(11) DEFAULT NULL,
  `reference_unique_helper` varchar(255) GENERATED ALWAYS AS (case when `reference_id` is not null and `reference_type` not in ('manual','reversal') then concat(`tenant_id`,'-',`reference_type`,'-',`reference_id`) else NULL end) STORED,
  `idempotency_key` varchar(255) DEFAULT NULL,
  `idempotency_unique_helper` varchar(255) GENERATED ALWAYS AS (case when `idempotency_key` is not null then concat(`tenant_id`,'-',`idempotency_key`) else NULL end) STORED,
  `reversal_of` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_je_reference` (`reference_unique_helper`),
  UNIQUE KEY `uk_je_idempotency` (`idempotency_unique_helper`),
  UNIQUE KEY `uq_idempotency` (`tenant_id`,`idempotency_key`),
  KEY `voucher_id` (`voucher_id`),
  KEY `fk_created_by_user` (`created_by`),
  KEY `idx_journal_entries_tenant` (`tenant_id`),
  KEY `idx_je_tenant_ref` (`tenant_id`,`reference_type`,`reference_id`),
  KEY `idx_journal_entries_cost_center_id` (`cost_center_id`),
  KEY `idx_je_search` (`tenant_id`,`reference_type`,`entry_date`),
  KEY `idx_je_user_audit` (`tenant_id`,`created_by`,`created_at`),
  KEY `idx_je_reversal_of` (`reversal_of`),
  CONSTRAINT `fk_created_by_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_je_reversal` FOREIGN KEY (`reversal_of`) REFERENCES `journal_entries` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_journal_entries_cost_center` FOREIGN KEY (`cost_center_id`) REFERENCES `cost_centers` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `journal_entries_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `journal_entries_ibfk_2` FOREIGN KEY (`voucher_id`) REFERENCES `cash_vouchers` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=130 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `journal_entry_lines` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `journal_entry_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `debit_amount` decimal(15,2) DEFAULT NULL,
  `credit_amount` decimal(15,2) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `tenant_id` int(11) NOT NULL,
  `cost_center_id` int(11) DEFAULT NULL COMMENT 'مركز التكلفة (اختياري)',
  PRIMARY KEY (`id`),
  KEY `fk_jel_tenant` (`tenant_id`),
  KEY `idx_journal_entry_id` (`journal_entry_id`),
  KEY `idx_account_id` (`account_id`),
  KEY `idx_jel_cost_center_id` (`cost_center_id`),
  CONSTRAINT `fk_jel_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_journal_entry_lines_cost_center` FOREIGN KEY (`cost_center_id`) REFERENCES `cost_centers` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `journal_entry_lines_ibfk_1` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `journal_entry_lines_ibfk_2` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=373 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_update_account_balance_insert` AFTER INSERT ON `journal_entry_lines` FOR EACH ROW BEGIN

    IF NEW.debit_amount > 0 THEN
        UPDATE accounts
        SET debit_balance = debit_balance + NEW.debit_amount,
            updated_at = NOW()
        WHERE id = NEW.account_id
          AND tenant_id = NEW.tenant_id;
    END IF;


    IF NEW.credit_amount > 0 THEN
        UPDATE accounts
        SET credit_balance = credit_balance + NEW.credit_amount,
            updated_at = NOW()
        WHERE id = NEW.account_id
          AND tenant_id = NEW.tenant_id;
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_update_account_balance_update` AFTER UPDATE ON `journal_entry_lines` FOR EACH ROW BEGIN
    DECLARE old_debit DECIMAL(15,4);
    DECLARE old_credit DECIMAL(15,4);

    SET old_debit = COALESCE(OLD.debit_amount, 0);
    SET old_credit = COALESCE(OLD.credit_amount, 0);


    IF old_debit > 0 THEN
        UPDATE accounts
        SET debit_balance = debit_balance - old_debit
        WHERE id = OLD.account_id
          AND tenant_id = OLD.tenant_id;
    END IF;

    IF old_credit > 0 THEN
        UPDATE accounts
        SET credit_balance = credit_balance - old_credit
        WHERE id = OLD.account_id
          AND tenant_id = OLD.tenant_id;
    END IF;


    IF NEW.debit_amount > 0 THEN
        UPDATE accounts
        SET debit_balance = debit_balance + NEW.debit_amount,
            updated_at = NOW()
        WHERE id = NEW.account_id
          AND tenant_id = NEW.tenant_id;
    END IF;

    IF NEW.credit_amount > 0 THEN
        UPDATE accounts
        SET credit_balance = credit_balance + NEW.credit_amount,
            updated_at = NOW()
        WHERE id = NEW.account_id
          AND tenant_id = NEW.tenant_id;
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_update_account_balance_delete` AFTER DELETE ON `journal_entry_lines` FOR EACH ROW BEGIN

    IF OLD.debit_amount > 0 THEN
        UPDATE accounts
        SET debit_balance = debit_balance - OLD.debit_amount,
            updated_at = NOW()
        WHERE id = OLD.account_id
          AND tenant_id = OLD.tenant_id;
    END IF;


    IF OLD.credit_amount > 0 THEN
        UPDATE accounts
        SET credit_balance = credit_balance - OLD.credit_amount,
            updated_at = NOW()
        WHERE id = OLD.account_id
          AND tenant_id = OLD.tenant_id;
    END IF;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jwt_blacklist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `token_id` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_id` (`token_id`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `attempt_time` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `username_ip_idx` (`username`,`ip_address`,`success`,`attempt_time`)
) ENGINE=InnoDB AUTO_INCREMENT=107 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  `executed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `migration` (`migration`)
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `batch_id` varchar(64) NOT NULL,
  `tenant_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `notification_type` varchar(50) NOT NULL,
  `event_count` int(11) DEFAULT 0,
  `total_amount` decimal(15,2) DEFAULT NULL,
  `status` enum('pending','sent','cancelled') DEFAULT 'pending',
  `scheduled_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_batch_id` (`batch_id`),
  KEY `idx_tenant_user_type` (`tenant_id`,`user_id`,`notification_type`),
  KEY `idx_status_scheduled` (`status`,`scheduled_at`),
  KEY `fk_batch_user` (`user_id`),
  CONSTRAINT `fk_batch_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_batch_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Batch tracking for aggregated notifications';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_preferences` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `notification_type` varchar(50) NOT NULL,
  `delivery_mode` enum('instant','batched','digest','disabled') NOT NULL DEFAULT 'batched',
  `batch_interval_minutes` int(11) DEFAULT 30,
  `threshold_enabled` tinyint(1) DEFAULT 0,
  `threshold_value` decimal(15,2) DEFAULT NULL,
  `enable_in_app` tinyint(1) DEFAULT 1,
  `enable_email` tinyint(1) DEFAULT 0,
  `enable_push` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_notification_type` (`tenant_id`,`user_id`,`notification_type`),
  KEY `idx_tenant_user` (`tenant_id`,`user_id`),
  KEY `idx_notification_type` (`notification_type`),
  KEY `fk_notif_pref_user` (`user_id`),
  CONSTRAINT `fk_notif_pref_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notif_pref_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='User notification preferences for smart batching and filtering';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `batch_id` varchar(64) DEFAULT NULL,
  `aggregation_key` varchar(128) DEFAULT NULL,
  `aggregated_count` int(11) DEFAULT 1,
  `priority` enum('low','normal','high','urgent') DEFAULT 'normal',
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_tenant_user_created` (`tenant_id`,`user_id`,`created_at`),
  KEY `idx_is_read` (`is_read`),
  KEY `idx_notification_type` (`type`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_batch_id` (`batch_id`),
  KEY `idx_aggregation_key` (`tenant_id`,`user_id`,`aggregation_key`),
  KEY `idx_priority_unread` (`user_id`,`is_read`,`priority`,`created_at`),
  CONSTRAINT `fk_notifications_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_notifications_data_json` CHECK (json_valid(`data`))
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='System notifications for users';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `opening_balances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `quantity` decimal(16,4) NOT NULL,
  `unit_cost` decimal(12,4) NOT NULL,
  `entry_date` datetime DEFAULT current_timestamp(),
  `reversed_at` datetime DEFAULT NULL,
  `reversed_by` int(11) DEFAULT NULL,
  `reversal_journal_entry_id` int(11) DEFAULT NULL,
  `reversal_inventory_transaction_id` int(11) DEFAULT NULL,
  `inventory_transaction_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `warehouse_id` (`branch_id`),
  KEY `fk_opening_balances_tenant_1` (`tenant_id`),
  KEY `idx_opening_balances_active_lookup` (`tenant_id`,`product_id`,`branch_id`,`reversed_at`),
  CONSTRAINT `fk_opening_balances_tenant_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `opening_balances_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `opening_balances_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=74 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `owner_contributions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `contribution_type` enum('capital','owner_loan','opening_balance') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `target_account_id` int(11) NOT NULL,
  `related_gl_account_id` int(11) NOT NULL,
  `interest_rate` decimal(5,2) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `repayment_terms` varchar(255) DEFAULT NULL,
  `repayment_schedule` enum('monthly','quarterly','at_due_date') DEFAULT NULL,
  `journal_entry_id` int(11) DEFAULT NULL,
  `idempotency_key` varchar(255) DEFAULT NULL,
  `status` enum('recorded','settled','voided') DEFAULT 'recorded',
  `created_by_user_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `voided_at` timestamp NULL DEFAULT NULL,
  `voided_by_user_id` int(11) DEFAULT NULL,
  `reversal_je_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `journal_entry_id` (`journal_entry_id`),
  UNIQUE KEY `uk_oc_idempotency` (`tenant_id`,`idempotency_key`),
  KEY `idx_oc_tenant` (`tenant_id`),
  KEY `idx_oc_je` (`journal_entry_id`),
  KEY `idx_oc_status` (`status`),
  KEY `idx_oc_type` (`contribution_type`),
  KEY `idx_oc_created` (`created_at`),
  KEY `fk_oc_target_account` (`target_account_id`),
  KEY `fk_oc_gl_account` (`related_gl_account_id`),
  KEY `fk_oc_created_by` (`created_by_user_id`),
  KEY `fk_oc_voided_by` (`voided_by_user_id`),
  KEY `fk_oc_reversal_je` (`reversal_je_id`),
  CONSTRAINT `fk_oc_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_oc_gl_account` FOREIGN KEY (`related_gl_account_id`) REFERENCES `accounts` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_oc_je` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_oc_reversal_je` FOREIGN KEY (`reversal_je_id`) REFERENCES `journal_entries` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_oc_target_account` FOREIGN KEY (`target_account_id`) REFERENCES `accounts` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_oc_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oc_voided_by` FOREIGN KEY (`voided_by_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Owner contributions: Capital/Loan/Opening Balance with full GL linkage and void support';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_applications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `reference_type` varchar(50) NOT NULL DEFAULT 'return',
  `reference_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_payment_applications_reference` (`reference_type`,`reference_id`),
  KEY `fk040_payment_applications_payment_id` (`payment_id`),
  KEY `fk040_payment_applications_created_by` (`created_by`),
  CONSTRAINT `fk040_payment_applications_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_payment_applications_payment_id` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_payment_applications_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=70 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_idempotency_keys` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `idempotency_key` varchar(128) NOT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `response_json` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenant_idem_key` (`tenant_id`,`idempotency_key`),
  KEY `idx_idem_expires` (`expires_at`),
  CONSTRAINT `fk040_payment_idempotency_keys_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_methods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `kind` enum('cash','bank','card','credit','wallet','other') NOT NULL DEFAULT 'cash',
  `description` text DEFAULT NULL,
  `payment_terms` text DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `tenant_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `account_type` enum('cash','bank','wallet','card','suspense','payable','other') DEFAULT 'other',
  `allow_negative` tinyint(1) DEFAULT 0,
  `overdraft_limit` decimal(15,2) DEFAULT NULL,
  `credit_limit` decimal(15,2) DEFAULT NULL,
  `wallet_type` enum('prepaid','postpaid') DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenant_kind` (`tenant_id`,`kind`),
  KEY `idx_payment_methods_tenant_id` (`tenant_id`),
  KEY `idx_payment_methods_account_id` (`account_id`),
  KEY `idx_payment_methods_tenant_id_id` (`tenant_id`,`id`),
  CONSTRAINT `fk_payment_methods_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_payment_methods_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `payment_method_id` int(11) NOT NULL,
  `reference_number` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `type` varchar(50) DEFAULT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `purchase_id` int(11) DEFAULT NULL,
  `return_id` int(11) DEFAULT NULL,
  `expense_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `wallet_id` int(11) DEFAULT NULL,
  `currency` varchar(10) DEFAULT 'EGP',
  `status` varchar(50) DEFAULT 'completed',
  `is_draft` tinyint(1) NOT NULL DEFAULT 0,
  `journal_entry_id` int(11) DEFAULT NULL,
  `idempotency_key` varchar(255) DEFAULT NULL,
  `session_id` int(11) DEFAULT NULL,
  `cost_center_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `transaction_currency_code` char(3) DEFAULT NULL,
  `base_currency_code` char(3) DEFAULT NULL,
  `exchange_rate` decimal(24,12) DEFAULT NULL,
  `base_amount` decimal(18,4) DEFAULT NULL,
  `foreign_total_amount` decimal(18,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_idempotency_key` (`tenant_id`,`idempotency_key`),
  KEY `payment_method_id` (`payment_method_id`),
  KEY `created_by` (`created_by`),
  KEY `fk_payments_tenant` (`tenant_id`),
  KEY `fk_payments_return` (`return_id`),
  KEY `fk_payments_supplier` (`supplier_id`),
  KEY `idx_payments_journal_entry_id` (`journal_entry_id`),
  KEY `idx_payments_customer_id` (`customer_id`),
  KEY `idx_payments_sale_id` (`sale_id`),
  KEY `idx_payments_purchase_id` (`purchase_id`),
  KEY `idx_payments_session` (`session_id`),
  KEY `idx_payments_sale_draft` (`sale_id`,`is_draft`),
  KEY `idx_payments_cost_center_id` (`cost_center_id`),
  KEY `idx_payments_tenant_customer` (`tenant_id`,`customer_id`),
  KEY `idx_payments_tenant_status` (`tenant_id`,`status`),
  KEY `fk040_payments_branch_id` (`branch_id`),
  CONSTRAINT `fk040_payments_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_payments_session_id` FOREIGN KEY (`session_id`) REFERENCES `cashier_sessions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payments_cost_center` FOREIGN KEY (`cost_center_id`) REFERENCES `cost_centers` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_journal` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payments_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payments_return` FOREIGN KEY (`return_id`) REFERENCES `returns` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payments_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`),
  CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `payments_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=80 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permission_overrides` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `subject_type` enum('role','user') NOT NULL,
  `subject_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `effect` enum('allow','deny') NOT NULL,
  `reason` varchar(500) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permission_override` (`tenant_id`,`subject_type`,`subject_id`,`permission_id`),
  KEY `idx_permission_override_subject` (`tenant_id`,`subject_type`,`subject_id`),
  KEY `idx_permission_override_permission` (`permission_id`),
  KEY `fk040_permission_overrides_created_by` (`created_by`),
  CONSTRAINT `fk040_permission_overrides_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk040_permission_overrides_permission_id` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`),
  CONSTRAINT `fk040_permission_overrides_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(50) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `tenant_id` int(11) DEFAULT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `tenant_id` (`tenant_id`),
  CONSTRAINT `permissions_ibfk_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=92 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) DEFAULT NULL,
  `code` varchar(64) NOT NULL,
  `name` varchar(255) NOT NULL,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(8) NOT NULL DEFAULT 'SAR',
  `billing_cycle_days` int(11) NOT NULL,
  `features_json` longtext DEFAULT NULL CHECK (json_valid(`features_json`)),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plans_code` (`code`),
  KEY `fk_plans_tenant` (`tenant_id`),
  CONSTRAINT `fk_plans_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_activation_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_branch_mapping_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `event_type` enum('CREATED_DRAFT','ACTIVATED_IN_BRANCH','OPENING_BALANCE_POSTED','GL_RECONCILED','DEACTIVATED') NOT NULL,
  `gl_journal_entry_id` int(11) DEFAULT NULL,
  `debit_amount` decimal(15,4) DEFAULT NULL,
  `credit_amount` decimal(15,4) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_pal_mapping` (`product_branch_mapping_id`),
  KEY `idx_pal_audit_trail` (`product_id`,`branch_id`,`event_type`),
  KEY `fk040_product_activation_log_tenant_id` (`tenant_id`),
  KEY `fk040_product_activation_log_branch_id` (`branch_id`),
  KEY `fk040_product_activation_log_gl_journal_entry_id` (`gl_journal_entry_id`),
  KEY `fk040_product_activation_log_user_id` (`user_id`),
  CONSTRAINT `fk040_product_activation_log_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk040_product_activation_log_gl_journal_entry_id` FOREIGN KEY (`gl_journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_product_activation_log_product_id` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk040_product_activation_log_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk040_product_activation_log_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_pal_mapping` FOREIGN KEY (`product_branch_mapping_id`) REFERENCES `product_branch_gl_mapping` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=75 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Audit trail for product lifecycle events';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_branch_gl_mapping` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `inventory_gl_account_id` int(11) NOT NULL,
  `purchase_gl_account_id` int(11) NOT NULL,
  `cogs_gl_account_id` int(11) NOT NULL,
  `cost_method` enum('FIFO','LIFO','AVERAGE','STANDARD') DEFAULT 'AVERAGE',
  `average_cost` decimal(12,4) DEFAULT NULL,
  `activation_status` enum('DRAFT','ACTIVE_IN_BRANCH','INACTIVE') DEFAULT 'DRAFT',
  `activation_date` datetime DEFAULT NULL,
  `last_gl_posting_date` datetime DEFAULT NULL,
  `gl_balance` decimal(15,4) DEFAULT 0.0000,
  `gl_reconciliation_status` enum('NOT_POSTED','PARTIAL','RECONCILED') DEFAULT 'NOT_POSTED',
  `created_by_user_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_by_user_id` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_product_branch_mapping` (`product_id`,`branch_id`,`tenant_id`),
  KEY `idx_pbm_tenant_product` (`tenant_id`,`product_id`),
  KEY `idx_pbm_branch_activation` (`branch_id`,`activation_status`),
  KEY `idx_pbm_gl_reconciliation` (`gl_reconciliation_status`),
  KEY `fk040_product_branch_gl_mapping_inventory_gl_account_id` (`inventory_gl_account_id`),
  KEY `fk040_product_branch_gl_mapping_purchase_gl_account_id` (`purchase_gl_account_id`),
  KEY `fk040_product_branch_gl_mapping_cogs_gl_account_id` (`cogs_gl_account_id`),
  KEY `fk040_product_branch_gl_mapping_created_by_user_id` (`created_by_user_id`),
  KEY `fk040_product_branch_gl_mapping_updated_by_user_id` (`updated_by_user_id`),
  CONSTRAINT `fk040_product_branch_gl_mapping_cogs_gl_account_id` FOREIGN KEY (`cogs_gl_account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `fk040_product_branch_gl_mapping_created_by_user_id` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_product_branch_gl_mapping_inventory_gl_account_id` FOREIGN KEY (`inventory_gl_account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `fk040_product_branch_gl_mapping_purchase_gl_account_id` FOREIGN KEY (`purchase_gl_account_id`) REFERENCES `accounts` (`id`),
  CONSTRAINT `fk040_product_branch_gl_mapping_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk040_product_branch_gl_mapping_updated_by_user_id` FOREIGN KEY (`updated_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pbm_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pbm_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=655 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_expiry` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `expiry_date` date NOT NULL,
  `quantity` decimal(16,4) NOT NULL DEFAULT 0.0000,
  `batch_number` varchar(50) DEFAULT NULL,
  `tenant_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `warehouse_id` (`branch_id`),
  KEY `fk_product_expiry_tenant` (`tenant_id`),
  CONSTRAINT `fk_product_expiry_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `product_expiry_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `product_expiry_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_serials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `tenant_id` int(11) NOT NULL,
  `serial_number` varchar(100) NOT NULL,
  `status` enum('in_stock','sold','transferred','damaged') DEFAULT 'in_stock',
  `transaction_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `warehouse_id` (`branch_id`),
  KEY `transaction_id` (`transaction_id`),
  KEY `fk_product_serials_tenant` (`tenant_id`),
  CONSTRAINT `fk_product_serials_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_product_serials_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_product_serials_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `inventory_transactions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_product_serials_warehouse` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_units` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `conversion_factor` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `is_main_unit` tinyint(1) NOT NULL DEFAULT 0,
  `tenant_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_unit_unique` (`product_id`,`unit_id`),
  KEY `product_id` (`product_id`),
  KEY `unit_id` (`unit_id`),
  KEY `fk043_product_units_tenant_id` (`tenant_id`),
  CONSTRAINT `fk043_product_units_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_pu_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pu_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=150 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `min_quantity` decimal(10,2) DEFAULT NULL,
  `sale_price` decimal(10,2) NOT NULL,
  `has_expiry_date` tinyint(1) NOT NULL DEFAULT 0,
  `default_expiry_date` date DEFAULT NULL,
  `has_batch_number` tinyint(1) NOT NULL DEFAULT 0,
  `default_batch_number` varchar(100) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `active` tinyint(1) DEFAULT 1,
  `purchase_price` decimal(12,2) DEFAULT 0.00,
  `min_sale_price` decimal(10,2) DEFAULT 0.00,
  `fixed_discount_percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `has_serial_number` tinyint(1) DEFAULT 0,
  `default_serial_number` varchar(100) DEFAULT NULL,
  `product_code` varchar(255) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `maximum_quantity` int(11) DEFAULT 0,
  `unit_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `default_category_gl_account_id` int(11) DEFAULT NULL,
  `product_status` enum('DRAFT','ACTIVE') DEFAULT 'DRAFT',
  `product_type` enum('stock','service') NOT NULL DEFAULT 'stock',
  `opening_balance_posted` tinyint(1) DEFAULT 0,
  `profit_calculation_type` enum('margin','markup') DEFAULT 'margin',
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  KEY `fk_products_tenant_1` (`tenant_id`),
  KEY `fk_supplier` (`supplier_id`),
  KEY `fk_products_unit` (`unit_id`),
  KEY `fk_products_created_by` (`created_by`),
  KEY `fk_products_updated_by` (`updated_by`),
  KEY `idx_products_category` (`category_id`),
  KEY `idx_products_supplier` (`supplier_id`),
  KEY `idx_products_status` (`product_status`),
  KEY `idx_opening_balance_posted` (`opening_balance_posted`),
  KEY `fk040_products_default_category_gl_account_id` (`default_category_gl_account_id`),
  CONSTRAINT `fk040_products_default_category_gl_account_id` FOREIGN KEY (`default_category_gl_account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_products_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_products_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_products_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_products_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_products_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `batch_number` varchar(100) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `serial` varchar(100) DEFAULT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `cost` decimal(10,2) DEFAULT 0.00,
  `total` decimal(10,2) NOT NULL DEFAULT 0.00,
  `branch_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `discount_amount` decimal(10,2) DEFAULT NULL,
  `tax_rate` decimal(5,2) DEFAULT NULL,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `subtotal` decimal(10,2) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `purchase_id` (`purchase_id`),
  KEY `product_id` (`product_id`),
  KEY `fk_purchase_items_warehouse` (`branch_id`),
  KEY `fk_purchase_items_category` (`category_id`),
  KEY `fk_purchase_items_tenant` (`tenant_id`),
  KEY `fk_unit_id` (`unit_id`),
  CONSTRAINT `fk_purchase_items_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_purchase_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_purchase_items_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_purchase_items_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_purchase_items_warehouse` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`),
  CONSTRAINT `purchase_items_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`),
  CONSTRAINT `purchase_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=289 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT 0,
  `invoice_number` varchar(100) DEFAULT NULL,
  `invoice_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(10,2) DEFAULT 0.00,
  `discount_type` enum('percentage','fixed') DEFAULT 'fixed',
  `discount_value` decimal(10,2) DEFAULT 0.00,
  `user_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `total_items` int(11) DEFAULT 0,
  `status` varchar(50) DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `payment_method_id` int(11) DEFAULT NULL,
  `tax_rate` decimal(10,2) DEFAULT 0.00,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `updated_at` datetime DEFAULT NULL,
  `journal_entry_id` int(11) DEFAULT NULL,
  `cost_center_id` int(11) DEFAULT NULL,
  `idempotency_key` varchar(128) DEFAULT NULL,
  `request_hash` char(64) DEFAULT NULL,
  `transaction_currency_code` char(3) DEFAULT NULL,
  `base_currency_code` char(3) DEFAULT NULL,
  `exchange_rate` decimal(24,12) DEFAULT NULL,
  `base_amount` decimal(18,4) DEFAULT NULL,
  `foreign_total_amount` decimal(18,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_purchases_tenant_idempotency_key` (`tenant_id`,`idempotency_key`),
  UNIQUE KEY `uq_purchases_tenant_invoice_number` (`tenant_id`,`invoice_number`),
  KEY `supplier_id` (`supplier_id`),
  KEY `fk_purchases_tenant_1` (`tenant_id`),
  KEY `fk_purchases_user` (`user_id`),
  KEY `fk_purchases_warehouse` (`branch_id`),
  KEY `idx_purchase_date` (`invoice_date`),
  KEY `idx_purchases_supplier` (`supplier_id`),
  KEY `fk_purchases_payment_method` (`payment_method_id`),
  KEY `idx_journal_entry_id` (`journal_entry_id`),
  KEY `idx_purchases_cost_center_id` (`cost_center_id`),
  KEY `idx_purchases_tenant_status` (`tenant_id`,`status`),
  KEY `idx_purchases_tenant_supplier` (`tenant_id`,`supplier_id`),
  KEY `idx_purchases_tenant_branch` (`tenant_id`,`branch_id`),
  CONSTRAINT `fk040_purchases_journal_entry_id` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_purchases_cost_center` FOREIGN KEY (`cost_center_id`) REFERENCES `cost_centers` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_purchases_payment_method` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`),
  CONSTRAINT `fk_purchases_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_purchases_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_purchases_tenant_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_purchases_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_purchases_warehouse` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `purchases_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rate_limiting_rules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rule_name` varchar(100) NOT NULL,
  `endpoint_pattern` varchar(255) NOT NULL,
  `max_requests` int(11) NOT NULL,
  `window_minutes` int(11) NOT NULL,
  `ip_based` tinyint(1) DEFAULT 1,
  `user_based` tinyint(1) DEFAULT 0,
  `tenant_based` tinyint(1) DEFAULT 0,
  `device_based` tinyint(1) DEFAULT 0,
  `penalty_minutes` int(11) DEFAULT 0,
  `penalty_multiplier` decimal(2,1) DEFAULT 1.0,
  `is_active` tinyint(1) DEFAULT 1,
  `priority` int(11) DEFAULT 0,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `rule_name` (`rule_name`),
  KEY `idx_rule_name` (`rule_name`),
  KEY `idx_endpoint_pattern` (`endpoint_pattern`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_priority` (`priority`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rate_limiting_tracking` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rule_id` bigint(20) unsigned NOT NULL,
  `identifier` varchar(255) NOT NULL,
  `identifier_type` enum('ip','user','tenant','device') NOT NULL,
  `request_count` int(11) DEFAULT 1,
  `window_start` timestamp NOT NULL DEFAULT current_timestamp(),
  `window_end` timestamp NOT NULL DEFAULT (current_timestamp() + interval 1 hour),
  `is_penalized` tinyint(1) DEFAULT 0,
  `penalty_until` timestamp NULL DEFAULT NULL,
  `total_violations` int(11) DEFAULT 0,
  `last_violation_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_rule_identifier_window` (`rule_id`,`identifier`,`identifier_type`,`window_start`),
  KEY `idx_rule_id` (`rule_id`),
  KEY `idx_identifier` (`identifier`),
  KEY `idx_window_end` (`window_end`),
  KEY `idx_is_penalized` (`is_penalized`),
  KEY `idx_penalty_until` (`penalty_until`),
  CONSTRAINT `fk043_rate_limiting_tracking_rule_id` FOREIGN KEY (`rule_id`) REFERENCES `rate_limiting_rules` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rate_limits` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(64) NOT NULL,
  `route` varchar(128) NOT NULL,
  `window_start` datetime NOT NULL,
  `counter` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_ip_route` (`ip`,`route`),
  KEY `idx_window` (`window_start`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `recent_security_events` AS SELECT
 1 AS `id`,
  1 AS `event_type`,
  1 AS `tenant_id`,
  1 AS `user_id`,
  1 AS `target_user_id`,
  1 AS `ip_address`,
  1 AS `user_agent`,
  1 AS `status`,
  1 AS `severity`,
  1 AS `details`,
  1 AS `created_at`,
  1 AS `updated_at`,
  1 AS `user_username`,
  1 AS `user_name`,
  1 AS `target_username`,
  1 AS `target_name` */;
SET character_set_client = @saved_cs_client;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `refresh_tokens` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token` text NOT NULL,
  `is_revoked` tinyint(1) DEFAULT 0,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_token` (`token`(255)),
  KEY `idx_rt_user_revoked` (`user_id`,`is_revoked`),
  KEY `idx_rt_expires_at` (`expires_at`),
  CONSTRAINT `refresh_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1444 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `repair_order_parts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `repair_order_id` bigint(20) unsigned NOT NULL,
  `product_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `quantity` decimal(16,4) NOT NULL,
  `sale_price` decimal(15,4) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_repair_parts_order` (`tenant_id`,`repair_order_id`),
  KEY `fk_repair_parts_order` (`repair_order_id`),
  KEY `fk040_repair_order_parts_product_id` (`product_id`),
  KEY `fk040_repair_order_parts_unit_id` (`unit_id`),
  CONSTRAINT `fk040_repair_order_parts_product_id` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk040_repair_order_parts_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk040_repair_order_parts_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`),
  CONSTRAINT `fk_repair_parts_order` FOREIGN KEY (`repair_order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `repair_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `order_number` varchar(40) DEFAULT NULL,
  `device_type` varchar(100) NOT NULL,
  `brand` varchar(120) DEFAULT NULL,
  `model` varchar(120) DEFAULT NULL,
  `serial_number` varchar(120) DEFAULT NULL,
  `accessories` text DEFAULT NULL,
  `issue_description` text NOT NULL,
  `condition_received` text DEFAULT NULL,
  `diagnosis` text DEFAULT NULL,
  `resolution` text DEFAULT NULL,
  `estimated_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `labor_product_id` int(11) DEFAULT NULL,
  `labor_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `expected_completion_date` date DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'received',
  `invoice_id` int(11) DEFAULT NULL,
  `received_by` int(11) DEFAULT NULL,
  `delivered_by` int(11) DEFAULT NULL,
  `received_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `delivered_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_repair_order_tenant_number` (`tenant_id`,`order_number`),
  KEY `idx_repair_orders_tenant_status` (`tenant_id`,`status`),
  KEY `idx_repair_orders_tenant_branch` (`tenant_id`,`branch_id`),
  KEY `idx_repair_orders_customer` (`tenant_id`,`customer_id`),
  KEY `idx_repair_orders_invoice` (`tenant_id`,`invoice_id`),
  KEY `fk040_repair_orders_branch_id` (`branch_id`),
  KEY `fk040_repair_orders_customer_id` (`customer_id`),
  KEY `fk040_repair_orders_labor_product_id` (`labor_product_id`),
  KEY `fk040_repair_orders_invoice_id` (`invoice_id`),
  KEY `fk040_repair_orders_received_by` (`received_by`),
  KEY `fk040_repair_orders_delivered_by` (`delivered_by`),
  CONSTRAINT `fk040_repair_orders_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk040_repair_orders_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk040_repair_orders_delivered_by` FOREIGN KEY (`delivered_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_repair_orders_invoice_id` FOREIGN KEY (`invoice_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_repair_orders_labor_product_id` FOREIGN KEY (`labor_product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_repair_orders_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_repair_orders_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `return_credit_allocations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `return_id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `allocated_amount` decimal(15,2) NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_return_sale` (`tenant_id`,`return_id`,`sale_id`),
  KEY `idx_tenant` (`tenant_id`),
  KEY `idx_return` (`tenant_id`,`return_id`),
  KEY `idx_sale` (`tenant_id`,`sale_id`),
  KEY `fk_rca_return` (`return_id`),
  KEY `fk_rca_sale` (`sale_id`),
  KEY `fk040_return_credit_allocations_created_by` (`created_by`),
  CONSTRAINT `fk040_return_credit_allocations_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_return_credit_allocations_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_rca_return` FOREIGN KEY (`return_id`) REFERENCES `returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rca_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `return_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `return_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_code` varchar(100) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(5,2) DEFAULT 0.00,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `discount` decimal(5,2) DEFAULT 0.00,
  `discount_amount` decimal(15,2) DEFAULT 0.00,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `batch_number` varchar(100) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `serial` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `tenant_id` int(11) NOT NULL,
  `unit_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `return_id` (`return_id`),
  KEY `tenant_id` (`tenant_id`),
  KEY `fk_return_items_unit` (`unit_id`),
  KEY `fk_return_items_product` (`product_id`),
  CONSTRAINT `fk040_return_items_return_id` FOREIGN KEY (`return_id`) REFERENCES `returns` (`id`),
  CONSTRAINT `fk_return_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_return_items_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_return_items_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `returns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `return_number` varchar(50) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `return_type` enum('sale','purchase') NOT NULL,
  `return_date` date NOT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `discount_amount` decimal(15,2) DEFAULT 0.00,
  `paid_amount` decimal(10,2) DEFAULT 0.00,
  `payment_method_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` varchar(20) DEFAULT 'completed',
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `tenant_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `purchase_id` int(11) DEFAULT NULL,
  `is_cash` tinyint(1) NOT NULL DEFAULT 1,
  `grand_total` decimal(10,2) GENERATED ALWAYS AS (`total_amount` + coalesce(`tax_amount`,0)) STORED,
  `journal_entry_id` int(11) DEFAULT NULL,
  `cost_center_id` int(11) DEFAULT NULL,
  `refund_amount` decimal(12,2) DEFAULT 0.00,
  `refund_method` varchar(50) DEFAULT NULL,
  `transaction_currency_code` char(3) DEFAULT NULL,
  `base_currency_code` char(3) DEFAULT NULL,
  `exchange_rate` decimal(24,12) DEFAULT NULL,
  `base_amount` decimal(18,4) DEFAULT NULL,
  `foreign_total_amount` decimal(18,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_returns_tenant_return_number` (`tenant_id`,`return_number`),
  KEY `invoice_id` (`invoice_id`),
  KEY `customer_id` (`customer_id`),
  KEY `supplier_id` (`supplier_id`),
  KEY `return_date` (`return_date`),
  KEY `tenant_id` (`tenant_id`),
  KEY `fk_returns_warehouse` (`branch_id`),
  KEY `fk_returns_sale` (`sale_id`),
  KEY `fk_returns_purchase` (`purchase_id`),
  KEY `fk_returns_journal_entry` (`journal_entry_id`),
  KEY `idx_returns_cost_center_id` (`cost_center_id`),
  KEY `idx_returns_tenant_type` (`tenant_id`,`return_type`),
  KEY `idx_returns_tenant_date` (`tenant_id`,`created_at`),
  KEY `fk040_returns_payment_method_id` (`payment_method_id`),
  KEY `fk040_returns_created_by` (`created_by`),
  CONSTRAINT `fk040_returns_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk040_returns_payment_method_id` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_returns_cost_center` FOREIGN KEY (`cost_center_id`) REFERENCES `cost_centers` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_returns_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_returns_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_returns_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_returns_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_returns_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_returns_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_returns_warehouse` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissions` (
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `permission_id` (`permission_id`),
  KEY `created_by` (`created_by`),
  KEY `tenant_id` (`tenant_id`),
  CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `role_permissions_ibfk_4` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `display_name` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `is_system_role` tinyint(1) DEFAULT 0,
  `tenant_id` int(11) DEFAULT NULL,
  `tenant_scope_id` int(11) GENERATED ALWAYS AS (ifnull(`tenant_id`,0)) STORED,
  `role_code` varchar(100) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 1000,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_name_tenant_scope` (`name`,`tenant_scope_id`),
  KEY `created_by` (`created_by`),
  KEY `tenant_id` (`tenant_id`),
  KEY `idx_roles_scope_order` (`tenant_id`,`sort_order`,`id`),
  KEY `idx_roles_tenant_active` (`tenant_id`,`is_active`,`sort_order`,`id`),
  CONSTRAINT `roles_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `roles_ibfk_2` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) DEFAULT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `sale_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `net_total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(10,2) DEFAULT 0.00,
  `discount_type` varchar(50) DEFAULT NULL,
  `discount_value` decimal(10,2) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'pending',
  `user_id` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approval_note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL,
  `total_items` int(11) DEFAULT 0,
  `total_profit` decimal(10,2) DEFAULT 0.00,
  `branch_id` int(11) DEFAULT NULL,
  `invoice_number` varchar(255) DEFAULT NULL,
  `payment_method_id` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `tax_rate` decimal(10,2) DEFAULT NULL,
  `tax_amount` decimal(15,2) DEFAULT 0.00,
  `journal_entry_id` int(11) DEFAULT NULL,
  `session_id` int(11) DEFAULT NULL,
  `cost_center_id` int(11) DEFAULT NULL COMMENT 'مركز التكلفة (اختياري)',
  `transaction_currency_code` char(3) DEFAULT NULL,
  `base_currency_code` char(3) DEFAULT NULL,
  `exchange_rate` decimal(24,12) DEFAULT NULL,
  `base_amount` decimal(18,4) DEFAULT NULL,
  `foreign_total_amount` decimal(18,4) DEFAULT NULL,
  `tax_inclusive` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_invoice_number_tenant` (`tenant_id`,`invoice_number`),
  KEY `customer_id` (`customer_id`),
  KEY `fk_sales_tenant_1` (`tenant_id`),
  KEY `fk_sales_user` (`user_id`),
  KEY `fk_sales_warehouse` (`branch_id`),
  KEY `idx_sales_date` (`sale_date`),
  KEY `fk_sales_payment_method` (`payment_method_id`),
  KEY `idx_tenant_sale_date` (`tenant_id`,`sale_date`),
  KEY `idx_journal_entry_id` (`journal_entry_id`),
  KEY `idx_sales_session` (`session_id`),
  KEY `idx_sales_status` (`status`),
  KEY `idx_sales_approved_by` (`approved_by`),
  KEY `idx_sales_approved_at` (`approved_at`),
  KEY `idx_sales_cost_center_id` (`cost_center_id`),
  KEY `idx_sales_tenant_status` (`tenant_id`,`status`),
  KEY `idx_sales_tenant_customer` (`tenant_id`,`customer_id`),
  KEY `idx_sales_tenant_branch_date` (`tenant_id`,`branch_id`,`sale_date`),
  KEY `idx_sales_created_at` (`created_at`),
  CONSTRAINT `fk040_sales_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_sales_journal_entry_id` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_sales_session_id` FOREIGN KEY (`session_id`) REFERENCES `cashier_sessions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sales_cost_center` FOREIGN KEY (`cost_center_id`) REFERENCES `cost_centers` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_sales_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sales_payment_method` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`),
  CONSTRAINT `fk_sales_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sales_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_sales_warehouse` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `batch_number` varchar(50) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `serial` varchar(255) DEFAULT NULL,
  `unit_id` int(11) DEFAULT NULL,
  `conversion_factor` decimal(12,4) NOT NULL DEFAULT 1.0000,
  `quantity` decimal(10,3) NOT NULL,
  `sale_price` decimal(15,4) NOT NULL,
  `purchase_price` decimal(15,4) NOT NULL,
  `discount_type` enum('percentage','amount') DEFAULT 'percentage',
  `discount_value` decimal(10,2) DEFAULT 0.00,
  `net_price` decimal(15,4) GENERATED ALWAYS AS (case when `discount_type` = 'percentage' then `sale_price` * (1 - `discount_value` / 100) when `discount_type` = 'amount' then `sale_price` - `discount_value` else `sale_price` end) STORED,
  `total` decimal(15,4) GENERATED ALWAYS AS (`quantity` * `conversion_factor` * case when `discount_type` = 'percentage' then `sale_price` * (1 - `discount_value` / 100) when `discount_type` = 'amount' then `sale_price` - `discount_value` else `sale_price` end) STORED,
  `net_total` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `profit` decimal(15,4) GENERATED ALWAYS AS ((case when `discount_type` = 'percentage' then `sale_price` * (1 - `discount_value` / 100) when `discount_type` = 'amount' then `sale_price` - `discount_value` else `sale_price` end - `purchase_price`) * `quantity` * `conversion_factor`) STORED,
  `tenant_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `sale_id` (`sale_id`),
  KEY `product_id` (`product_id`),
  KEY `unit_id` (`unit_id`),
  KEY `idx_tenant_sale` (`tenant_id`,`sale_id`),
  KEY `fk040_sales_items_created_by` (`created_by`),
  KEY `fk040_sales_items_updated_by` (`updated_by`),
  CONSTRAINT `fk040_sales_items_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_sales_items_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk043_sales_items_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `sales_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sales_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `sales_items_ibfk_3` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=614 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_price_campaign_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `campaign_id` bigint(20) unsigned NOT NULL,
  `product_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `campaign_price` decimal(15,4) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_price_campaign_item` (`tenant_id`,`campaign_id`,`product_id`,`unit_id`),
  KEY `idx_price_campaign_items_product` (`tenant_id`,`product_id`,`unit_id`),
  KEY `fk_price_campaign_item_campaign` (`campaign_id`),
  KEY `fk040_sales_price_campaign_items_product_id` (`product_id`),
  KEY `fk040_sales_price_campaign_items_unit_id` (`unit_id`),
  CONSTRAINT `fk040_sales_price_campaign_items_product_id` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk040_sales_price_campaign_items_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk040_sales_price_campaign_items_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`),
  CONSTRAINT `fk_price_campaign_item_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `sales_price_campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_price_campaigns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `name` varchar(160) NOT NULL,
  `campaign_type` varchar(24) NOT NULL DEFAULT 'custom',
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `priority` smallint(6) NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_price_campaign_active_dates` (`tenant_id`,`status`,`start_date`,`end_date`,`branch_id`,`priority`),
  KEY `fk040_sales_price_campaigns_branch_id` (`branch_id`),
  KEY `fk040_sales_price_campaigns_created_by` (`created_by`),
  CONSTRAINT `fk040_sales_price_campaigns_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk040_sales_price_campaigns_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk040_sales_price_campaigns_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_quotations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `quote_number` varchar(40) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'active',
  `valid_until` date NOT NULL,
  `items_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`items_json`)),
  `discount_type` varchar(16) NOT NULL DEFAULT 'fixed',
  `discount_value` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(7,4) NOT NULL DEFAULT 0.0000,
  `tax_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `currency_code` char(3) NOT NULL DEFAULT 'EGP',
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `tax_inclusive` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sales_quotations_tenant_number` (`tenant_id`,`quote_number`),
  KEY `idx_sales_quotations_tenant_branch_status` (`tenant_id`,`branch_id`,`status`,`valid_until`),
  KEY `idx_sales_quotations_tenant_customer` (`tenant_id`,`customer_id`),
  KEY `fk040_sales_quotations_branch_id` (`branch_id`),
  KEY `fk040_sales_quotations_customer_id` (`customer_id`),
  KEY `fk040_sales_quotations_created_by` (`created_by`),
  CONSTRAINT `fk040_sales_quotations_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk040_sales_quotations_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk040_sales_quotations_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_sales_quotations_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `security_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(100) NOT NULL,
  `event_severity` enum('low','medium','high','critical') NOT NULL DEFAULT 'low',
  `description` text NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `target_user_id` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `status` enum('success','failed','pending','blocked') NOT NULL DEFAULT 'success',
  `details` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_tenant_id` (`tenant_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_target_user_id` (`target_user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_tenant_event_type` (`tenant_id`,`event_type`),
  KEY `idx_user_event_type` (`user_id`,`event_type`),
  KEY `idx_event_type_created` (`event_type`,`created_at`),
  KEY `idx_severity_created` (`created_at`),
  KEY `idx_se_tenant_type` (`tenant_id`,`event_type`),
  KEY `idx_se_created_at` (`created_at`),
  CONSTRAINT `fk040_security_events_target_user_id` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_security_events_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_security_events_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=157 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Security events log for auditing and monitoring';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `tenant_id` int(11) NOT NULL,
  `key_name` varchar(255) NOT NULL,
  `value` text DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`tenant_id`,`key_name`),
  CONSTRAINT `fk_settings_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_transfers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `from_branch` int(11) DEFAULT NULL,
  `to_branch` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(16,4) NOT NULL,
  `batch_number` varchar(100) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `serial` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_st_tenant` (`tenant_id`),
  KEY `idx_st_warehouses` (`from_branch`,`to_branch`),
  KEY `idx_st_product` (`product_id`),
  KEY `idx_st_created_at` (`created_at`),
  KEY `fk_stock_to_warehouse` (`to_branch`),
  KEY `fk_stock_created_by` (`created_by`),
  KEY `idx_batch_number` (`batch_number`),
  KEY `idx_expiry_date` (`expiry_date`),
  KEY `idx_serial` (`serial`),
  CONSTRAINT `fk_stock_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_stock_from_warehouse` FOREIGN KEY (`from_branch`) REFERENCES `branches` (`id`),
  CONSTRAINT `fk_stock_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk_stock_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_stock_to_warehouse` FOREIGN KEY (`to_branch`) REFERENCES `branches` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subscription_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `attempt_type` enum('registration','trial_creation','plan_upgrade','payment','login') NOT NULL,
  `status` enum('success','failed','blocked','suspicious') NOT NULL,
  `attempt_data` longtext DEFAULT NULL,
  `device_fingerprint` varchar(255) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `tenant_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `risk_score` decimal(3,2) DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_email` (`email`),
  KEY `idx_attempt_type` (`attempt_type`),
  KEY `idx_attempted_at` (`attempted_at`),
  KEY `idx_status` (`status`),
  KEY `idx_risk_score` (`risk_score`),
  KEY `fk040_subscription_attempts_tenant_id` (`tenant_id`),
  KEY `fk040_subscription_attempts_user_id` (`user_id`),
  CONSTRAINT `fk040_subscription_attempts_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_subscription_attempts_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `subscription_attempts_summary` AS SELECT
 1 AS `attempt_date`,
  1 AS `attempt_type`,
  1 AS `status`,
  1 AS `attempt_count`,
  1 AS `unique_ips`,
  1 AS `unique_emails`,
  1 AS `avg_risk_score` */;
SET character_set_client = @saved_cs_client;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subscription_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `subscription_id` int(11) NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `event_date` datetime NOT NULL DEFAULT current_timestamp(),
  `meta_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta_json`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_sub_events_sub` (`subscription_id`),
  CONSTRAINT `fk_sub_events_sub` FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=62 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `start_date` datetime NOT NULL,
  `end_date` datetime NOT NULL,
  `status` enum('trial','active','expired','cancelled','pending') NOT NULL DEFAULT 'trial',
  `payment_status` enum('n/a','pending','paid','failed') NOT NULL DEFAULT 'n/a',
  `auto_renew` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `user_id` int(11) DEFAULT NULL,
  `security_flags` longtext DEFAULT NULL CHECK (json_valid(`security_flags`)),
  `last_security_check` timestamp NULL DEFAULT NULL,
  `risk_score` decimal(3,2) DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_subs_tenant` (`tenant_id`),
  KEY `idx_subs_status` (`status`),
  KEY `fk_subs_plan` (`plan_id`),
  KEY `fk_subs_user` (`user_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_status_created` (`status`,`created_at`),
  CONSTRAINT `fk_subs_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_subs_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_subs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `address` varchar(255) NOT NULL,
  `active` tinyint(1) DEFAULT 1,
  `discount_percentage` decimal(5,2) DEFAULT 0.00,
  `credit_limit` decimal(15,2) DEFAULT 0.00,
  `account_id` int(11) DEFAULT NULL,
  `tax_number` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_suppliers_tenant_1` (`tenant_id`),
  KEY `fk_suppliers_accounts` (`account_id`),
  KEY `idx_suppliers_branch_id` (`branch_id`),
  CONSTRAINT `fk043_suppliers_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_suppliers_accounts` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_suppliers_tenant_1` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tenants` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_name` varchar(255) NOT NULL,
  `signup_ip` varchar(45) DEFAULT NULL,
  `device_fingerprint` varchar(255) DEFAULT NULL,
  `logo_url` varchar(255) DEFAULT NULL,
  `plan_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive','trial') NOT NULL DEFAULT 'trial',
  `is_setup_complete` tinyint(1) NOT NULL DEFAULT 0,
  `current_step` varchar(50) DEFAULT 'business_info',
  `setup_completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `plan_id` (`plan_id`),
  KEY `idx_tenants_setup` (`is_setup_complete`,`current_step`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_tenants_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `terminals` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_terminals_tenant_code` (`tenant_id`,`code`),
  KEY `idx_terminals_tenant` (`tenant_id`),
  KEY `idx_terminals_warehouse` (`branch_id`),
  KEY `fk040_terminals_created_by` (`created_by`),
  CONSTRAINT `fk040_terminals_branch_id` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_terminals_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_terminals_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `units` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(20) NOT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `fk040_units_tenant_id` (`tenant_id`),
  CONSTRAINT `fk040_units_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `role_id` int(11) DEFAULT NULL,
  `status` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `tenant_id` int(11) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `is_owner` tinyint(1) DEFAULT 0,
  `two_fa_enabled` tinyint(1) DEFAULT 0,
  `two_fa_secret` varchar(255) DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `verification_token` varchar(255) DEFAULT NULL,
  `verification_token_expires` datetime DEFAULT NULL,
  `token_version` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `tenant_id` (`tenant_id`),
  KEY `fk_users_role` (`role_id`),
  KEY `fk_users_warehouse` (`branch_id`),
  KEY `idx_users_owner` (`is_owner`,`tenant_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_email_tenant` (`email`,`tenant_id`),
  KEY `fk040_users_created_by` (`created_by`),
  CONSTRAINT `fk040_users_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `fk_users_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_users_warehouse` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users_role` (
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `tenant_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`user_id`,`role_id`),
  KEY `fk_users_role_role` (`role_id`),
  KEY `fk_users_role_created_by` (`created_by`),
  KEY `fk_users_role_tenant` (`tenant_id`),
  CONSTRAINT `fk_users_role_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_users_role_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_users_role_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_users_role_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_product_branch_gl_status` AS SELECT
 1 AS `id`,
  1 AS `tenant_id`,
  1 AS `product_id`,
  1 AS `product_name`,
  1 AS `branch_id`,
  1 AS `branch_name`,
  1 AS `activation_status`,
  1 AS `gl_reconciliation_status`,
  1 AS `quantity`,
  1 AS `average_cost`,
  1 AS `quantity_cost`,
  1 AS `expected_gl_balance`,
  1 AS `actual_bp_value`,
  1 AS `reconciliation_variance`,
  1 AS `status_label`,
  1 AS `last_gl_posting_date` */;
SET character_set_client = @saved_cs_client;
SET @saved_cs_client     = @@character_set_client;
SET character_set_client = utf8;
/*!50001 CREATE VIEW `v_products_pending_gl_posting` AS SELECT
 1 AS `id`,
  1 AS `tenant_id`,
  1 AS `product_name`,
  1 AS `branch_name`,
  1 AS `pending_action`,
  1 AS `quantity`,
  1 AS `unit_cost` */;
SET character_set_client = @saved_cs_client;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `warranty_attachments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `warranty_request_id` int(10) unsigned NOT NULL,
  `filename` varchar(255) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `size` int(10) unsigned DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wa_wr` (`warranty_request_id`),
  KEY `idx_wa_tenant` (`tenant_id`),
  KEY `fk040_warranty_attachments_uploaded_by` (`uploaded_by`),
  CONSTRAINT `fk040_warranty_attachments_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk040_warranty_attachments_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_wa_wr` FOREIGN KEY (`warranty_request_id`) REFERENCES `warranty_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `warranty_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `warranty_request_id` int(10) unsigned NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(12,3) NOT NULL DEFAULT 1.000,
  `issue_notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wi_wr` (`warranty_request_id`),
  KEY `idx_wi_tenant` (`tenant_id`),
  KEY `fk040_warranty_items_product_id` (`product_id`),
  CONSTRAINT `fk040_warranty_items_product_id` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `fk040_warranty_items_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_wi_wr` FOREIGN KEY (`warranty_request_id`) REFERENCES `warranty_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `warranty_notes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `warranty_request_id` int(10) unsigned NOT NULL,
  `note` text NOT NULL,
  `is_internal` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wn_wr` (`warranty_request_id`),
  KEY `idx_wn_tenant` (`tenant_id`),
  KEY `fk040_warranty_notes_created_by` (`created_by`),
  CONSTRAINT `fk040_warranty_notes_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk040_warranty_notes_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_wn_wr` FOREIGN KEY (`warranty_request_id`) REFERENCES `warranty_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `warranty_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'open',
  `priority` varchar(16) NOT NULL DEFAULT 'medium',
  `issue_description` text DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `product_serial` varchar(100) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `sla_due_at` datetime DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_wr_tenant` (`tenant_id`),
  KEY `idx_wr_status` (`status`),
  KEY `idx_wr_customer` (`customer_id`),
  KEY `idx_wr_invoice` (`invoice_id`),
  KEY `fk040_warranty_requests_created_by` (`created_by`),
  KEY `fk040_warranty_requests_assigned_to` (`assigned_to`),
  CONSTRAINT `fk040_warranty_requests_assigned_to` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_warranty_requests_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk040_warranty_requests_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_warranty_requests_invoice_id` FOREIGN KEY (`invoice_id`) REFERENCES `sales` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk040_warranty_requests_tenant_id` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
DELIMITER ;;
CREATE DEFINER=`root`@`localhost` PROCEDURE `cleanup_old_notifications`(IN `days_to_keep` INT)
BEGIN
  DELETE FROM notifications
  WHERE created_at < DATE_SUB(NOW(), INTERVAL days_to_keep DAY)
    AND is_read = 1;
END ;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50001 DROP VIEW IF EXISTS `recent_security_events`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `recent_security_events` AS select `se`.`id` AS `id`,`se`.`event_type` AS `event_type`,`se`.`tenant_id` AS `tenant_id`,`se`.`user_id` AS `user_id`,`se`.`target_user_id` AS `target_user_id`,`se`.`ip_address` AS `ip_address`,`se`.`user_agent` AS `user_agent`,`se`.`status` AS `status`,`se`.`event_severity` AS `severity`,`se`.`details` AS `details`,`se`.`created_at` AS `created_at`,`se`.`updated_at` AS `updated_at`,`u1`.`username` AS `user_username`,`u1`.`name` AS `user_name`,`u2`.`username` AS `target_username`,`u2`.`name` AS `target_name` from ((`security_events` `se` left join `users` `u1` on(`se`.`user_id` = `u1`.`id`)) left join `users` `u2` on(`se`.`target_user_id` = `u2`.`id`)) where `se`.`created_at` >= current_timestamp() - interval 30 day order by `se`.`created_at` desc */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `subscription_attempts_summary`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `subscription_attempts_summary` AS select cast(`subscription_attempts`.`attempted_at` as date) AS `attempt_date`,`subscription_attempts`.`attempt_type` AS `attempt_type`,`subscription_attempts`.`status` AS `status`,count(0) AS `attempt_count`,count(distinct `subscription_attempts`.`ip_address`) AS `unique_ips`,count(distinct `subscription_attempts`.`email`) AS `unique_emails`,avg(`subscription_attempts`.`risk_score`) AS `avg_risk_score` from `subscription_attempts` where `subscription_attempts`.`attempted_at` >= current_timestamp() - interval 30 day group by cast(`subscription_attempts`.`attempted_at` as date),`subscription_attempts`.`attempt_type`,`subscription_attempts`.`status` order by cast(`subscription_attempts`.`attempted_at` as date) desc,`subscription_attempts`.`attempt_type` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_product_branch_gl_status`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_product_branch_gl_status` AS select `pbm`.`id` AS `id`,`pbm`.`tenant_id` AS `tenant_id`,`p`.`id` AS `product_id`,`p`.`name` AS `product_name`,`b`.`id` AS `branch_id`,`b`.`name` AS `branch_name`,`pbm`.`activation_status` AS `activation_status`,`pbm`.`gl_reconciliation_status` AS `gl_reconciliation_status`,`bp`.`quantity` AS `quantity`,`pbm`.`average_cost` AS `average_cost`,`bp`.`quantity_cost` AS `quantity_cost`,round(`bp`.`quantity` * coalesce(`pbm`.`average_cost`,0),2) AS `expected_gl_balance`,`bp`.`quantity_cost` AS `actual_bp_value`,round(`bp`.`quantity` * coalesce(`pbm`.`average_cost`,0),2) - coalesce(`bp`.`quantity_cost`,0) AS `reconciliation_variance`,case when `pbm`.`activation_status` = 'DRAFT' then 'Not Activated' when `pbm`.`gl_reconciliation_status` = 'NOT_POSTED' then 'Activated - Awaiting GL Post' when `pbm`.`gl_reconciliation_status` = 'PARTIAL' then 'Partially Reconciled' when `pbm`.`gl_reconciliation_status` = 'RECONCILED' then 'Fully Reconciled' end AS `status_label`,`pbm`.`last_gl_posting_date` AS `last_gl_posting_date` from (((`product_branch_gl_mapping` `pbm` left join `products` `p` on(`pbm`.`product_id` = `p`.`id`)) left join `branches` `b` on(`pbm`.`branch_id` = `b`.`id`)) left join `branch_products` `bp` on(`bp`.`product_id` = `p`.`id` and `bp`.`branch_id` = `b`.`id`)) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!50001 DROP VIEW IF EXISTS `v_products_pending_gl_posting`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_general_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */
/*!50001 VIEW `v_products_pending_gl_posting` AS select `pbm`.`id` AS `id`,`pbm`.`tenant_id` AS `tenant_id`,`p`.`name` AS `product_name`,`b`.`name` AS `branch_name`,'OPENING_BALANCE' AS `pending_action`,`bp`.`quantity` AS `quantity`,0 AS `unit_cost` from (((`product_branch_gl_mapping` `pbm` join `products` `p` on(`pbm`.`product_id` = `p`.`id`)) join `branches` `b` on(`pbm`.`branch_id` = `b`.`id`)) left join `branch_products` `bp` on(`bp`.`product_id` = `p`.`id` and `bp`.`branch_id` = `b`.`id`)) where `pbm`.`activation_status` = 'ACTIVE_IN_BRANCH' and `pbm`.`gl_reconciliation_status` = 'NOT_POSTED' union all select `pbm`.`id` AS `id`,`pbm`.`tenant_id` AS `tenant_id`,`p`.`name` AS `name`,`b`.`name` AS `name`,'RECONCILIATION' AS `RECONCILIATION`,`bp`.`quantity` AS `quantity`,`pbm`.`average_cost` AS `average_cost` from (((`product_branch_gl_mapping` `pbm` join `products` `p` on(`pbm`.`product_id` = `p`.`id`)) join `branches` `b` on(`pbm`.`branch_id` = `b`.`id`)) left join `branch_products` `bp` on(`bp`.`product_id` = `p`.`id` and `bp`.`branch_id` = `b`.`id`)) where `pbm`.`gl_reconciliation_status` in ('PARTIAL','NOT_POSTED') and (`pbm`.`last_gl_posting_date` is null or `pbm`.`last_gl_posting_date` < current_timestamp() - interval 1 day) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;


-- Mark the included schema migrations as already applied.

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

INSERT INTO `migrations` VALUES (1,'001_create_system_configuration',0,'2026-09-26 08:30:09'),(2,'002_create_roles_and_permissions',0,'2026-09-26 08:30:09'),(3,'003_insert_core_data',0,'2026-09-26 08:30:09'),(4,'004_add_accounting_period_close_override',1,'2026-09-26 08:32:49'),(5,'005_allow_optional_opening_snapshot_mapping',1,'2026-09-26 08:32:49'),(6,'006_tenant_scoped_operational_roles',2,'2026-09-26 16:04:50'),(7,'007_add_purchase_request_idempotency',3,'2026-09-27 14:08:48'),(8,'008_add_liquidity_policy_columns',4,'2026-09-29 18:02:13'),(9,'009_create_owner_contributions_table',5,'2026-09-29 18:13:27'),(10,'010_add_cash_negative_setting',6,'2026-09-29 18:33:08'),(11,'011_add_owner_contribution_permissions',6,'2026-09-29 18:33:08'),(12,'012_harden_payment_method_liquidity_config',7,'2026-09-30 14:48:05'),(13,'013_reconcile_legacy_sale_cancellation_stock',8,'2026-10-01 08:46:28'),(14,'014_add_reversed_journal_entry_status',9,'2026-10-02 12:25:05'),(15,'015_normalize_sale_approval_status',9,'2026-10-02 12:25:05'),(16,'016_add_cash_voucher_status',9,'2026-10-02 12:25:05'),(17,'017_add_cash_voucher_edit_permission',9,'2026-10-02 12:25:05'),(18,'018_grant_cash_voucher_edit_permission',10,'2026-10-02 12:50:33'),(19,'019_consolidate_shared_operational_roles',11,'2026-10-02 19:20:32'),(20,'020_mark_finance_officer_as_system_role',12,'2026-10-02 20:12:10'),(21,'021_add_cash_voucher_idempotency',13,'2026-10-06 10:35:47'),(22,'022_add_supplier_tax_number',13,'2026-10-06 10:35:47'),(23,'023_allocate_customer_payments_fifo',14,'2026-10-07 12:36:32'),(24,'024_reconcile_legacy_stock_adjustment_costs',15,'2026-10-10 07:55:21'),(25,'025_create_request_rate_limits',16,'2026-10-13 06:54:44'),(26,'026_add_multi_currency_foundation',17,'2026-10-14 12:40:46'),(27,'027_track_opening_balance_reversals',18,'2026-10-15 08:58:15'),(28,'028_add_purchase_invoice_number_uniqueness',19,'2026-10-16 16:21:46'),(29,'029_add_return_number_uniqueness',20,'2026-10-16 18:28:16'),(30,'030_grant_owner_contribution_permissions_to_admin_roles',21,'2026-10-17 08:27:07'),(31,'031_revoke_sessions_after_password_change',22,'2026-10-18 08:47:49'),(32,'032_create_repair_orders',23,'2026-10-18 11:10:30'),(33,'033_consolidate_branch_manager_role',24,'2026-10-22 07:13:19'),(34,'034_manage_branch_categories',24,'2026-10-22 07:13:19'),(35,'035_add_tenant_permission_overrides',25,'2026-10-22 14:30:02'),(36,'036_add_arabic_permission_names',26,'2026-10-22 14:56:16'),(37,'037_create_sales_quotations',27,'2026-10-23 13:59:11'),(38,'038_create_sales_price_campaigns',28,'2026-10-23 14:33:43'),(39,'039_rename_admin_role_display_name',29,'2026-11-26 18:40:22'),(40,'040_add_missing_data_integrity_constraints',29,'2026-11-26 18:40:33'),(41,'041_remove_reviewed_unused_columns',29,'2026-11-26 18:40:34'),(42,'042_finalize_schema_requirement_cleanup',29,'2026-11-26 18:40:34'),(43,'043_add_remaining_requested_foreign_keys',29,'2026-11-26 18:40:35'),(44,'044_remove_redundant_product_active_flag',29,'2026-11-26 18:40:35'),(45,'045_drop_confirmed_unused_tables',29,'2026-11-26 18:40:35'),(46,'046_add_role_codes_and_sort_order',29,'2026-11-26 18:40:35'),(47,'047_add_custom_role_metadata',29,'2026-11-26 18:40:35'),(48,'048_clarify_core_role_metadata',29,'2026-11-26 18:40:35'),(49,'049_create_branch_print_settings',29,'2026-11-26 18:40:35'),(50,'050_add_tax_inclusive_invoice_mode',29,'2026-11-26 18:40:35'),(51,'051_reconcile_email_token_and_notification_json_constraints',29,'2026-11-26 18:40:36');
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

