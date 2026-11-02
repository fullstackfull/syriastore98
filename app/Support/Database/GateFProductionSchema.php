<?php

namespace App\Support\Database;

/**
 * GATE F -- THE MEASURED PRODUCTION STRUCTURE OF THE 15 UNMANAGED TABLES, AS DATA.
 *
 * WHAT THIS FILE IS. Every `CREATE TABLE` below is a VERBATIM copy of the corresponding block in
 * `docs/GATE-F-VERIFIED-SCHEMA.md` section 2, which was generated from `information_schema` on a
 * restored copy of the production dump (commit `3935dee8`, read 2026-10-24, MariaDB 11.4.9) and
 * then reproduced by running the drafts against a copy of production with these objects stripped
 * and diffing `SHOW CREATE TABLE` back.
 *
 * IT IS NOT TRANSCRIBED. It was extracted from that document by a parser, and
 * `tests/Unit/Database/GateFProductionSchemaFidelityTest` asserts IN BOTH DIRECTIONS that these
 * strings are still exactly the document's blocks -- so the document remains the authority and
 * neither side can drift from the other in silence. A definition that needs changing is changed
 * in the document first, measured against a restored copy.
 *
 * WHY IT IS DDL TEXT AND NOT A HAND-WRITTEN FIELD SPEC. `GateFSchemaGuard` parses BOTH this
 * expected DDL and the live `SHOW CREATE TABLE` with the SAME parser before diffing them. That
 * removes every formatting and server-version quirk from the comparison -- how
 * `information_schema.COLUMN_DEFAULT` quotes a literal, key ordering, the `AUTO_INCREMENT=`
 * counter -- which a field-by-field spec would have had to normalise by hand, wrongly, once per
 * quirk.
 *
 * THE SOURCE WINS ON EVERY DISAGREEMENT. A mismatch between a live table and this file is
 * reported for a human to read, never silently accepted and never "corrected" upward in
 * production: widening a live money column is a separate, deliberate change with its own
 * rounding consequences for the rows already stored.
 */
final class GateFProductionSchema
{
    /** The six money-bearing tables, named so a caller cannot get the list wrong. */
    public const MONEY_TABLES = [
        'store_wallets',
        'store_wallet_transactions',
        'store_withdrawals',
        'store_order_financials',
        'delivery_earnings',
        'currency_rates',
    ];

    /**
     * The measured production DDL for the 15 unmanaged tables, verbatim from
     * `docs/GATE-F-VERIFIED-SCHEMA.md` section 2. Do not edit by hand.
     */
    public const DDL = [
        'store_wallets' => <<<'SQL'
CREATE TABLE `store_wallets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint unsigned NOT NULL,
  `currency_code` varchar(10) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `available_balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `pending_balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `withdrawal_pending_balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_income` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_withdrawn` decimal(15,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_store_wallet_store_id` (`store_id`),
  KEY `idx_store_wallet_store_id` (`store_id`),
  CONSTRAINT `fk_store_wallet_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,

        'store_wallet_transactions' => <<<'SQL'
CREATE TABLE `store_wallet_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint unsigned NOT NULL,
  `wallet_id` bigint unsigned NOT NULL,
  `currency_code` varchar(10) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `type` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `direction` enum('credit','debit') COLLATE utf8mb4_general_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `balance_before` decimal(15,2) NOT NULL DEFAULT '0.00',
  `balance_after` decimal(15,2) NOT NULL DEFAULT '0.00',
  `reference_type` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `reference_id` bigint unsigned DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('pending','completed','cancelled') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'completed',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_wallet_transactions_store_id` (`store_id`),
  KEY `idx_wallet_transactions_wallet_id` (`wallet_id`),
  KEY `idx_wallet_transactions_type` (`type`),
  KEY `idx_wallet_transactions_created_at` (`created_at`),
  KEY `idx_wallet_transactions_reference` (`reference_type`,`reference_id`),
  CONSTRAINT `fk_wallet_transactions_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wallet_transactions_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `store_wallets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=81 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,

        'store_withdrawals' => <<<'SQL'
CREATE TABLE `store_withdrawals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint unsigned NOT NULL,
  `wallet_id` bigint unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `status` enum('pending','approved','rejected','paid','cancelled') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `payment_method` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `payment_details` text COLLATE utf8mb4_general_ci,
  `store_note` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `admin_note` varchar(500) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `requested_by` bigint unsigned DEFAULT NULL,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `requested_at` timestamp NULL DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_withdrawal_store` (`store_id`),
  KEY `idx_withdrawal_wallet` (`wallet_id`),
  KEY `idx_withdrawal_status` (`status`),
  CONSTRAINT `fk_withdrawal_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_withdrawal_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `store_wallets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,

        'store_order_financials' => <<<'SQL'
CREATE TABLE `store_order_financials` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint unsigned NOT NULL,
  `wallet_id` bigint unsigned NOT NULL,
  `order_id` bigint unsigned NOT NULL,
  `currency_code` varchar(10) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `currency_symbol` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `product_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `shipping_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `customer_total` decimal(15,2) NOT NULL DEFAULT '0.00',
  `product_cost` decimal(15,2) NOT NULL DEFAULT '0.00',
  `gross_profit` decimal(15,2) NOT NULL DEFAULT '0.00',
  `payment_status` enum('unpaid','pending','paid','partially_paid','refunded','cancelled') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'unpaid',
  `paid_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `remaining_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `paid_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_store_order_financial_order` (`order_id`),
  KEY `idx_order_financial_store` (`store_id`),
  KEY `idx_order_financial_wallet` (`wallet_id`),
  KEY `idx_order_financial_payment_status` (`payment_status`),
  CONSTRAINT `fk_order_financial_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_financial_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_financial_wallet` FOREIGN KEY (`wallet_id`) REFERENCES `store_wallets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=119 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,

        'delivery_earnings' => <<<'SQL'
CREATE TABLE `delivery_earnings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `delivery_boy_id` bigint unsigned NOT NULL,
  `order_id` bigint unsigned NOT NULL,
  `shipping_method_id` bigint unsigned DEFAULT NULL,
  `shipping_cost` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '????? ?????',
  `commission_percentage` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT '???? ??????? %',
  `commission_amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT '???? ????? ???????',
  `status` enum('pending','paid','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT '???? ?????',
  `paid_at` timestamp NULL DEFAULT NULL COMMENT '????? ??? ????? ???????',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `delivery_earnings_delivery_boy_id_foreign` (`delivery_boy_id`),
  KEY `delivery_earnings_order_id_foreign` (`order_id`),
  KEY `delivery_earnings_shipping_method_id_foreign` (`shipping_method_id`),
  KEY `idx_boy_status` (`delivery_boy_id`,`status`),
  KEY `idx_order_id` (`order_id`),
  CONSTRAINT `delivery_earnings_delivery_boy_id_foreign` FOREIGN KEY (`delivery_boy_id`) REFERENCES `delivery_boys` (`id`) ON DELETE CASCADE,
  CONSTRAINT `delivery_earnings_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `delivery_earnings_shipping_method_id_foreign` FOREIGN KEY (`shipping_method_id`) REFERENCES `shipping_methods` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

        'currency_rates' => <<<'SQL'
CREATE TABLE `currency_rates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint unsigned NOT NULL,
  `currency_code` varchar(10) COLLATE utf8mb4_general_ci NOT NULL,
  `currency_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `symbol` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `rate_per_usd` decimal(20,8) NOT NULL DEFAULT '1.00000000',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_currency_rates_store_code` (`store_id`,`currency_code`),
  KEY `idx_currency_rates_store_id` (`store_id`),
  KEY `idx_currency_rates_active` (`is_active`),
  CONSTRAINT `fk_currency_rates_store` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,

        'audit_logs' => <<<'SQL'
CREATE TABLE `audit_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `store_id` bigint unsigned DEFAULT NULL,
  `action` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `subject_type` varchar(150) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `subject_id` bigint unsigned DEFAULT NULL,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `ip_address` varchar(45) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_store_created` (`store_id`,`created_at`),
  KEY `idx_audit_subject` (`subject_type`,`subject_id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_action` (`action`),
  CONSTRAINT `audit_logs_chk_1` CHECK (json_valid(`old_values`)),
  CONSTRAINT `audit_logs_chk_2` CHECK (json_valid(`new_values`))
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
SQL,

        'otp_integrations' => <<<'SQL'
CREATE TABLE `otp_integrations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint unsigned NOT NULL,
  `base_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'https://otp.lynomia.com',
  `api_secret` text COLLATE utf8mb4_unicode_ci,
  `otp_channel` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sms',
  `sms_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `sms_mode` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sms_device` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sms_gateway` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sms_sim` tinyint unsigned DEFAULT NULL,
  `sms_priority` tinyint unsigned NOT NULL DEFAULT '0',
  `whatsapp_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `whatsapp_account` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `otp_integrations_store_id_unique` (`store_id`),
  KEY `otp_integrations_store_id_index` (`store_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

        'chatwoot_integrations' => <<<'SQL'
CREATE TABLE `chatwoot_integrations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint unsigned NOT NULL,
  `base_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'https://chat.lynomia.com',
  `account_id` bigint unsigned NOT NULL,
  `api_access_token` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `inbox_id` bigint unsigned DEFAULT NULL,
  `inbox_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `last_connected_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chatwoot_integrations_store_id_unique` (`store_id`),
  CONSTRAINT `chatwoot_integrations_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

        'chatwoot_notification_templates' => <<<'SQL'
CREATE TABLE `chatwoot_notification_templates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint unsigned NOT NULL,
  `event_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_language` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `template_category` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parameters` longtext COLLATE utf8mb4_unicode_ci,
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chatwoot_notification_store_event_unique` (`store_id`,`event_type`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

        'delivery_boy_devices' => <<<'SQL'
CREATE TABLE `delivery_boy_devices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `delivery_boy_id` bigint unsigned NOT NULL,
  `fcm_token` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `device_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `platform` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `delivery_boy_devices_delivery_boy_id_foreign` (`delivery_boy_id`),
  KEY `idx_boy_active_token` (`delivery_boy_id`,`is_active`),
  CONSTRAINT `delivery_boy_devices_delivery_boy_id_foreign` FOREIGN KEY (`delivery_boy_id`) REFERENCES `delivery_boys` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

        'delivery_boy_notifications' => <<<'SQL'
CREATE TABLE `delivery_boy_notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `delivery_boy_id` bigint unsigned NOT NULL,
  `order_id` bigint unsigned NOT NULL,
  `type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `push_sent` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `delivery_boy_notifications_delivery_boy_id_foreign` (`delivery_boy_id`),
  KEY `delivery_boy_notifications_order_id_foreign` (`order_id`),
  KEY `idx_boy_unread` (`delivery_boy_id`,`is_read`),
  KEY `idx_type_created` (`type`,`created_at`),
  CONSTRAINT `delivery_boy_notifications_delivery_boy_id_foreign` FOREIGN KEY (`delivery_boy_id`) REFERENCES `delivery_boys` (`id`) ON DELETE CASCADE,
  CONSTRAINT `delivery_boy_notifications_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `delivery_boy_notifications_chk_1` CHECK (json_valid(`data`))
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

        'zid_stores' => <<<'SQL'
CREATE TABLE `zid_stores` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `store_id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `authorization` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `access_token` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `refresh_token` text COLLATE utf8mb4_unicode_ci,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `ds_merchant_id` bigint unsigned DEFAULT NULL,
  `ds_secret` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `store_id` (`store_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

        'zid_orders' => <<<'SQL'
CREATE TABLE `zid_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `zid_order_id` bigint unsigned NOT NULL,
  `store_id` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_name` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_mobile` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_unicode_ci,
  `total` decimal(14,4) DEFAULT NULL,
  `payment_method` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status_code` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `parcel_status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `parcel_tracking` varchar(191) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `parcel_error` text COLLATE utf8mb4_unicode_ci,
  `attempts` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `zid_orders_zid_order_id_unique` (`zid_order_id`),
  KEY `zid_orders_store_id_index` (`store_id`),
  KEY `zid_orders_parcel_status_index` (`parcel_status`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

        'zid_events' => <<<'SQL'
CREATE TABLE `zid_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `store_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_store_event` (`store_id`,`event`),
  KEY `idx_processed` (`processed_at`),
  CONSTRAINT `zid_events_chk_1` CHECK (json_valid(`payload`))
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,

    ];

    /**
     * The three COLUMNS that live on tables which DO have migrations, same source.
     * `products.cost_price` was promoted on 2026-10-26 under D-01 and already has a
     * migration; the two `delivery_boys` commission columns are added by this GATE F work.
     */
    public const COLUMN_DDL = <<<'SQL'
delivery_boys.commission_percentage decimal(5,2) NULL DEFAULT 0.00
delivery_boys.is_commission_enabled tinyint(1) NOT NULL DEFAULT 0
products.cost_price decimal(15,2) NOT NULL DEFAULT 0.00
SQL;

    /** @return list<string> the table names, in the document's order */
    public static function tableNames(): array
    {
        return array_keys(self::DDL);
    }

    /** The verbatim measured `CREATE TABLE` for one of the unmanaged tables. */
    public static function ddlFor(string $table): string
    {
        if (! isset(self::DDL[$table])) {
            throw new \InvalidArgumentException(
                "GATE F knows no measured structure for '{$table}'. The tables it knows are: "
                . implode(', ', self::tableNames()) . '.'
            );
        }

        return self::DDL[$table];
    }

    public static function isMoneyTable(string $table): bool
    {
        return in_array($table, self::MONEY_TABLES, true);
    }
}
