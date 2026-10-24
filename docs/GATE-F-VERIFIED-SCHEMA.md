# GATE F — the verified production schema

**This file replaces inference with measurement.** Until 2026-10-24 every structural claim about the
15 SCHEMA-01 tables came from reading `$fillable` and `$casts`, because no backup had been restored.
On 2026-10-24 the production dump was restored into an isolated local MySQL and read. Several
load-bearing claims in this repository turned out to be **wrong**, and they are corrected here and
in the documents that carried them.

| | |
|---|---|
| source | `storesyria_ecommercego.sql` on `main` at commit `3935dee8` |
| size / digest | 20,470,785 bytes, sha256 `6e54b88f769b1216e7d5196bd6f95222b4598be74ebb8398d5aeb0ed9c7df78e` |
| format | phpMyAdmin SQL Dump 5.2.3 |
| **source server** | **MariaDB 11.4.9** — this project's notes assume MySQL |
| contents | 198 `CREATE TABLE`, 482 `INSERT`, 445 `ALTER TABLE`, 89 `FOREIGN KEY`; 0 views, triggers or routines |
| credentials in dump | **0** `DEFINER=`, 0 `IDENTIFIED BY`, 0 `GRANT` |
| restored | 198 tables, **0 import errors**, 84 tables holding rows, ~28,158 rows |
| migration history | 237 rows, batches 1–55 |

**The dump itself was never committed, never copied to a tracked branch, and was destroyed after
the verification.** It was held at mode 0600 in a directory outside every git repository, and the
tooling that reads it is read-only by construction. Only structure appears below — no row data.

---

## 1. The three claims the dump refuted

Each of these was stated confidently in this repository, each was inferred from the migrations
because that was the only evidence available, and each is **false**.

| claim, as it was written | what the dump shows |
|---|---|
| *"the financial subsystem is dead, not merely untested — its tables do not exist, the service throws, an Observer swallows it, so releasing GATE F switches it on rather than fixing it"* | **All 15 tables exist in production, with live data.** `store_wallet_transactions` holds 80 ledger rows, `store_order_financials` 92 P&L rows, `currency_rates` 40, `store_wallets` 2. The service does not throw. The subsystem is **running**, and has been. |
| *"`delivery_boys` lacks `commission_percentage` and `is_commission_enabled` … so those handlers 500 for everyone"* | **Both columns exist** — `commission_percentage decimal(5,2) NULL DEFAULT 0.00`, `is_commission_enabled tinyint(1) NOT NULL DEFAULT 0` — across 2 rows, both with a non-zero percentage and one with the flag set. Those handlers do **not** 500. |
| *"`products.cost_price` does not exist"* | **It exists**, `decimal(15,2) NOT NULL DEFAULT 0.00`, populated on all 45 products. |

**What remains true, and is the actual GATE F finding:** *no migration in the production history
creates any of these 15 tables or 3 columns.* They were applied out of band. So the schema exists
but is **unmanaged** — a fresh environment (staging, CI, a new tenant database) does not get it, and
nothing in version control describes it. That is the gap GATE F closes, and it is a different gap
from the one this project spent two months describing.

**Why the error happened, recorded because the mechanism will recur:** the repository's migrations
are a complete description of what *this codebase* creates, and were treated as a complete
description of what *production has*. For a codebase whose schema is partly applied by hand, those
are different questions. No amount of reading the repository could have answered the second one.

---

## 2. The measured structure

Generated from `information_schema` on the restored copy, then reproduced: the drafts in
`database/migrations/_gate_f_pending/` were run against a copy of production with these objects
stripped, and the resulting columns, index sets and foreign keys compared back. **All six money
tables and all three columns are now identical to production.**

```
----- store_wallets
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

----- store_wallet_transactions
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

----- store_withdrawals
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

----- store_order_financials
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

----- delivery_earnings
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

----- currency_rates
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

----- audit_logs
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

----- otp_integrations
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

----- chatwoot_integrations
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

----- chatwoot_notification_templates
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

----- delivery_boy_devices
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

----- delivery_boy_notifications
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

----- zid_stores
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

----- zid_orders
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

----- zid_events
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

----- products.cost_price / delivery_boys commission columns
delivery_boys.commission_percentage decimal(5,2) NULL DEFAULT 0.00
delivery_boys.is_commission_enabled tinyint(1) NOT NULL DEFAULT 0
products.cost_price decimal(15,2) NOT NULL DEFAULT 0.00
```

---

## 3. What the drafts got wrong, and what fixing them took

The drafts were corrected against the measured structure — generated from `information_schema`
rather than transcribed, so no typo can diverge from it. Three classes of error, in increasing
severity.

**Money scales — 22 contradictions.** Every money column was declared `decimal(18,4)` by a standing
project decision arguing scale 4 for 3-decimal KWD/BHD/OMR. Production uses `decimal(15,2)`
throughout, `delivery_earnings` uses `(10,2)` and `(5,2)`, and `currency_rates.rate_per_usd` is
`(20,8)` not `(18,8)`. The standing decision was reasonable and is now **superseded**: widening a
live money column is a separate, deliberate change with its own rounding consequences for rows
already stored, so a promotion must not "correct" production upward.

**Type weakenings.** The drafts turned four enums into `varchar(255)`, including
`store_wallet_transactions.direction enum('credit','debit') NOT NULL` — the ledger's credit/debit
discriminator — into a nullable free-text column. They also narrowed `currency_code` from
`varchar(10)` to `varchar(3)`, dropped `currency_name`'s NOT NULL, and set
`currency_rates.rate_per_usd`'s default to `0.00000000` where production has `1.00000000`. **A
default of zero on an FX multiplier means a newly inserted rate row multiplies every converted
price by nothing.**

**Missing constraints — the serious class.** Production carries **3 unique keys and 12 foreign
keys** across the six money tables. The drafts declared **none of either**. Two of those keys are
the enforcement for integrity properties this project has been asserting by measurement rather than
by construction:

| key | what it makes true |
|---|---|
| `uq_store_order_financial_order (order_id)` | **one financial row per order** — i.e. no duplicate financial entries, which is otherwise only an observation about today's rows |
| `uq_store_wallet_store_id (store_id)` | **one wallet per store** |
| `uq_currency_rates_store_code (store_id, currency_code)` | one rate per currency per store |

The 12 `ON DELETE CASCADE` / `SET NULL` rules are what stop a deleted store leaving money rows
behind. Releasing GATE F from the uncorrected drafts would have created the six tables **without a
single one of these**, silently removing guarantees production already has.

**Two destructive `down()` methods, measured not reasoned.** `0004` (cost_price) and `0006`
(commission columns) guarded their `up()` on `hasColumn(...)` — returning early when the column
already exists — but their `down()` dropped the column **because** it exists. Run against a copy of
production, `migrate:rollback` executed them in 30.28ms and 46.82ms and **destroyed all 45
`cost_price` values (summing 1170.65) and both `delivery_boys` commission columns with their data**.
An `up()` that creates nothing paired with a `down()` that drops regardless. Both are now
documented no-ops, matching the precedent the six table drafts already set for exactly this reason.
After the fix the same rollback leaves every value intact.

**Ordering.** The README declared two constraints — `cost_price` **before** `store_order_financials`,
and the commission columns **with or before** `delivery_earnings`. Laravel sorts by filename, and the
drafts were numbered `0004 = financials`, `0007 = cost_price`, `0005 = earnings`, `0008 = commission`:
**both constraints were violated by the numbering itself.** Renumbered so cost_price is `0004`
before financials `0005`, and commission `0006` before earnings `0007`.

---

## 4. `migrate` exits 0 in three different wrong states

This is the reason a green `migrate` run is not evidence that the GATE F schema is in place.
Measured, all three:

| run | result | what actually happened |
|---|---|---|
| the 9 drafts against a copy of **production** | exit 0, 9 × DONE | **8 of 9 created nothing.** Every `hasTable`/`hasColumn` guard returned early. Only `cod_collection_events` was created. |
| the 9 drafts against an **empty** database | exit 0, 9 × DONE | **2 of 9 created nothing** — `products` and `delivery_boys` do not exist, so the column drafts' `hasTable` guard returned early. `store_order_financials` was created *without* `cost_price`: exactly the failure the README warns about. |
| after promotion, any `migrate:status` | all 9 "Ran" | The migration history would **claim the GATE F schema is managed** while production's tables stayed exactly as they were, 22 type disagreements and all. The history becomes a false record. |

So the promotion of these drafts to production is a **no-op by design**, and that is the intended
outcome — it brings fresh environments into line and puts the schema under version control without
touching the live tables. It does **not** reconcile production to the drafts, and it must never be
reported as having done so.

---

## 5. The one object that is genuinely absent

`cod_collection_events` does not exist in production. That is **by design** (decision D15) and it is
the gate on COD settlement: `CodCollectionService` guards on `Schema::hasTable()` and logs
`critical` when it is missing, and `PaymentVerificationService::codMayBeSettled()` returns false
with no event. So today a COD order cannot be settled by a collection event, because no event can
exist.

Its draft (`0009`) is therefore the only one where the draft is the authority rather than production.
Its `amount` is `decimal(15,2)` to match the money scale production actually uses — it must match,
because `codMayBeSettled()` compares it against `orders.final_price` with a 0.005 tolerance and a
scale mismatch there is a settlement that silently refuses.

---

## 6. How to reproduce this

```bash
# read-only; states its own preconditions; never writes
php tests/Support/Security/bin/verify-gate-f-schema-on-mysql.php <database> \
    [--host= --port= --user= --pass= --socket=]
```

Exit 1 on any disagreement between the database and the drafts. Every message says **the source
wins**. Run it against a restored copy, never against production.
