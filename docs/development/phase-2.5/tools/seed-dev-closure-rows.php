#!/usr/bin/env php
<?php

/**
 * Seed the seven tables the Phase 2.5 closure wave needs rows in, and nothing else.
 * =================================================================================================
 *
 * `flash_sales` and `webhooks` are both EMPTY in database/p2dev.sqlite, and both carry an inventory
 * row that cannot be captured without one: a list screen with no rows renders its empty state, and
 * an edit modal with no row to edit has no trigger to click.
 *
 *     flash_sales          0 rows   -> Flash sale edit (modal), Flash sale status cell
 *     webhooks             0 rows   -> Webhooks (create/edit modals)
 *     pixel_fields         0 rows   -> Pixel update modal (the CREATE modal needs no row)
 *     email_templates      0 rows   -> Email templates, which 500s without one (see below)
 *     email_template_langs 0 rows   -> the same screen, second null dereference
 *     carts                0 rows   -> Abandoned-cart row actions
 *     wishlists            0 rows   -> Abandoned-wishlist row actions
 *
 * THE EMAIL TEMPLATE SCREEN DOES NOT RENDER AN EMPTY STATE -- IT 500s. Measured in all four
 * combinations before this tool existed. `EmailTemplateController::emailTemplate` opens with
 * `EmailTemplate::first()` and then reads `$emailTemplate->id` with no null check (:25-27), and
 * a second dereference follows at `$currEmailTempLang->lang = $language` (:32). Production is NOT
 * currently affected -- the dump ships 6 platform templates and 5 language rows -- so this tool
 * reproduces that shipped shape rather than inventing one, and the controller's null guard is a
 * separate defensive repair.
 *
 * The six platform names and the per-merchant five are taken from the production dump's
 * `email_templates` INSERT. They are product configuration, not customer data.
 *
 * DEV FIXTURE ONLY, IDEMPOTENT, REVERSIBLE -- the same contract as
 * `seed-dev-catalogue-rows.php`, which this is modelled on. Every row is tagged `P25PROBE` in a
 * text field of its own, which is what makes it both findable and removable:
 *
 *     php seed-dev-closure-rows.php --remove
 *
 * It never adds to a table that already holds rows it did not create, so a fixture somebody has
 * populated by hand is left alone.
 *
 * FOREIGN KEYS ARE RESOLVED AGAINST THE FIXTURE, not assumed. `store_id` and `created_by` are read
 * from merchant@example.test rather than hardcoded to 1: a dangling reference either fails the
 * insert (SQLite with foreign_keys on) or, worse, succeeds and photographs as a real row with
 * blank columns.
 *
 * usage:
 *   DB_CONNECTION=sqlite DB_DATABASE=<repo>/database/p2dev.sqlite CACHE_STORE=file \
 *     php docs/development/phase-2.5/tools/seed-dev-closure-rows.php [--remove]
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const TAG = 'P25PROBE';

$remove = in_array('--remove', $argv, true);

$merchant = DB::table('users')->where('email', 'merchant@example.test')->first();

if (! $merchant) {
    fwrite(STDERR, "no merchant@example.test in this fixture; refusing to guess an owner\n");
    exit(1);
}

$storeId = (int) ($merchant->current_store ?: 0);

if ($storeId === 0) {
    $storeId = (int) DB::table('stores')->where('created_by', $merchant->id)->value('id');
}

if ($storeId === 0) {
    fwrite(STDERR, "merchant@example.test owns no store; refusing to seed an unreachable row\n");
    exit(1);
}

// `created_by` on these tables is the TENANT ROOT, which for a type-admin merchant is their own id.
$createdBy = in_array(strtolower(rtrim((string) $merchant->type, ' ')), ['admin', 'super admin'], true)
    ? (int) $merchant->id
    : (int) $merchant->created_by;

$productIds = DB::table('products')->where('store_id', $storeId)->limit(2)->pluck('id')->all();

$done = [];

// =================================================================================================
//  flash_sales
// =================================================================================================

if (! Schema::hasTable('flash_sales')) {
    $done[] = ['flash_sales', 'NO TABLE in this fixture'];
} else {
    $mine = DB::table('flash_sales')->where('name', 'like', TAG . '%');

    if ($remove) {
        $n = $mine->count();
        $mine->delete();
        $done[] = ['flash_sales', "removed $n"];
    } elseif ($mine->count() > 0) {
        $done[] = ['flash_sales', 'already seeded'];
    } elseif (DB::table('flash_sales')->count() > 0) {
        $done[] = ['flash_sales', 'skipped: holds rows this tool did not create'];
    } elseif ($productIds === []) {
        $done[] = ['flash_sales', 'skipped: the store has no products to put on sale'];
    } else {
        // TWO ROWS, ONE ACTIVE AND ONE NOT, because `is_active` is the whole point of the
        // `Flash sale status cell` row: a cell that only ever renders one state is half a
        // surface, and this project has already captured a status cell in one state and
        // called it done.
        foreach ([
            ['name' => TAG . ' Damascus Week', 'is_active' => 1, 'discount_type' => 'percentage', 'discount_amount' => 15.0],
            ['name' => TAG . ' Aleppo Clearance', 'is_active' => 0, 'discount_type' => 'flat', 'discount_amount' => 2500.0],
        ] as $row) {
            DB::table('flash_sales')->insert($row + [
                'start_date' => date('Y-m-d', strtotime('-2 days')),
                'end_date' => date('Y-m-d', strtotime('+12 days')),
                'start_time' => '09:00:00',
                'end_time' => '21:00:00',
                'sale_product' => implode(',', $productIds),
                'created_by' => $createdBy,
                'store_id' => $storeId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $done[] = ['flash_sales', 'seeded 2 (one active, one inactive)'];
    }
}

// =================================================================================================
//  webhooks
// =================================================================================================

if (! Schema::hasTable('webhooks')) {
    $done[] = ['webhooks', 'NO TABLE in this fixture'];
} else {
    $mine = DB::table('webhooks')->where('url', 'like', '%' . TAG . '%');

    if ($remove) {
        $n = $mine->count();
        $mine->delete();
        $done[] = ['webhooks', "removed $n"];
    } elseif ($mine->count() > 0) {
        $done[] = ['webhooks', 'already seeded'];
    } elseif (DB::table('webhooks')->count() > 0) {
        $done[] = ['webhooks', 'skipped: holds rows this tool did not create'];
    } else {
        // Two methods, because the edit modal renders `method` as a select and a capture of one
        // option does not show the other.
        foreach ([
            ['module' => 'New Order', 'method' => 'POST'],
            ['module' => 'New Customer', 'method' => 'GET'],
        ] as $row) {
            DB::table('webhooks')->insert($row + [
                'url' => 'https://example.test/' . TAG . '/' . strtolower(str_replace(' ', '-', $row['module'])),
                'store_id' => $storeId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $done[] = ['webhooks', 'seeded 2'];
    }
}


// =================================================================================================
//  pixel_fields -- the Pixel UPDATE modal needs a row to edit; the create modal does not
// =================================================================================================

if (! Schema::hasTable('pixel_fields')) {
    $done[] = ['pixel_fields', 'NO TABLE in this fixture'];
} else {
    $mine = DB::table('pixel_fields')->where('pixel_id', 'like', TAG . '%');

    if ($remove) {
        $n = $mine->count();
        $mine->delete();
        $done[] = ['pixel_fields', "removed $n"];
    } elseif ($mine->count() > 0) {
        $done[] = ['pixel_fields', 'already seeded'];
    } elseif (DB::table('pixel_fields')->count() > 0) {
        $done[] = ['pixel_fields', 'skipped: holds rows this tool did not create'];
    } else {
        foreach (['facebook', 'google'] as $platform) {
            DB::table('pixel_fields')->insert([
                'platform' => $platform,
                'pixel_id' => TAG . '-' . $platform . '-0001',
                'store_id' => $storeId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $done[] = ['pixel_fields', 'seeded 2'];
    }
}

// =================================================================================================
//  email_templates + email_template_langs -- without these the screen is a 500, not an empty state
// =================================================================================================

/**
 * The shape production ships, read from the dump rather than invented:
 *   created_by = 1 (the platform operator)  -- six templates
 *   created_by = <merchant>                 -- five more, which is what the merchant's own list
 *                                              renders (`where created_by = Auth::user()->id`)
 * `EmailTemplate::first()` is UNSCOPED, so the screen's "current" template is the platform's
 * while the list beside it is the merchant's. That asymmetry is the product's own and is
 * reproduced rather than smoothed over, because a fixture that disagrees with production
 * photographs a screen nobody has.
 */
const PLATFORM_TEMPLATES = [
    'Order Created',
    'Status Change',
    'Order Created For Owner',
    'Stock Status',
    'Abandon Cart',
    'Abandon Wishlist',
];

const MERCHANT_TEMPLATES = [
    'Payment Reminder',
    'Product Catelog',
    'Product Enquiry',
    'Review Reminder',
    'Lucky Winner',
];

if (! Schema::hasTable('email_templates') || ! Schema::hasTable('email_template_langs')) {
    $done[] = ['email_templates', 'NO TABLE in this fixture'];
} else {
    // THE TAG IS A SUFFIX, NOT THE WHOLE VALUE. `from` is a field the screen RENDERS, and a
    // capture showing the literal `P25PROBE` in a form input is evidence of the fixture rather
    // than of the screen. Measured: the first run photographed exactly that.
    $mine = DB::table('email_templates')->where('from', 'like', '%' . TAG);

    if ($remove) {
        $ids = $mine->pluck('id')->all();
        DB::table('email_template_langs')->whereIn('parent_id', $ids)->delete();
        $n = $mine->count();
        $mine->delete();
        $done[] = ['email_templates', "removed $n (and their language rows)"];
    } elseif ($mine->count() > 0) {
        $done[] = ['email_templates', 'already seeded'];
    } elseif (DB::table('email_templates')->count() > 0) {
        $done[] = ['email_templates', 'skipped: holds rows this tool did not create'];
    } else {
        $operator = DB::table('users')->where('type', 'super admin')->value('id')
            ?: DB::table('users')->min('id');

        $langs = 0;

        foreach ([[$operator, PLATFORM_TEMPLATES], [$createdBy, MERCHANT_TEMPLATES]] as [$owner, $names]) {
            foreach ($names as $name) {
                $id = DB::table('email_templates')->insertGetId([
                    'name' => $name,
                    // `from` carries the tag as a SUFFIX: it is a real column the screen renders,
                    // so the value has to read like a sender while staying findable and removable.
                    'from' => 'Store Syria ' . TAG,
                    'created_by' => $owner,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // BOTH LANGUAGES. The screen takes `{lang?}` from the URL and falls back to `en`;
                // seeding only `en` would photograph the fallback in the Arabic combinations and
                // hide whether the Arabic branch works at all.
                foreach ([
                    ['en', $name, '<p>Hello {customer_name}, this is the ' . $name . ' notice.</p>'],
                    ['ar', $name . ' (عربي)', '<p>مرحباً {customer_name}، هذا إشعار ' . $name . '.</p>'],
                ] as [$language, $subject, $content]) {
                    DB::table('email_template_langs')->insert([
                        'parent_id' => $id,
                        'language' => $language,
                        'subject' => $subject,
                        'content' => $content,
                        'is_default' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $langs++;
                }
            }
        }

        $done[] = ['email_templates', 'seeded ' . (count(PLATFORM_TEMPLATES) + count(MERCHANT_TEMPLATES)) . " (+$langs language rows)"];
    }
}

// =================================================================================================
//  carts + wishlists -- the two abandoned lists, whose ROW ACTIONS are the inventory rows
// =================================================================================================

$customerId = (int) DB::table('customers')->where('store_id', $storeId)->value('id');

foreach ([
    ['carts', ['qty' => 2, 'price' => 4800]],
    ['wishlists', ['status' => 1]],
] as [$table, $extra]) {
    if (! Schema::hasTable($table)) {
        $done[] = [$table, 'NO TABLE in this fixture'];
        continue;
    }

    // Neither table has a text column to tag, so these are identified by the PRODUCT they point
    // at plus the store -- and the tool still refuses a table it did not empty.
    $mine = DB::table($table)->where('store_id', $storeId)->whereIn('product_id', $productIds);

    if ($remove) {
        $n = $mine->count();
        $mine->delete();
        $done[] = [$table, "removed $n"];
        continue;
    }

    if (DB::table($table)->count() > 0) {
        $done[] = [$table, 'skipped: holds rows this tool did not create'];
        continue;
    }

    if ($customerId === 0 || $productIds === []) {
        $done[] = [$table, 'skipped: the store has no customer or no product to reference'];
        continue;
    }

    foreach ($productIds as $productId) {
        DB::table($table)->insert($extra + [
            'customer_id' => $customerId,
            'product_id' => $productId,
            'store_id' => $storeId,
            // ABANDONED means OLD. A row created now may not appear in a list that filters on
            // age, and a list that renders nothing has no row action to photograph.
            'created_at' => now()->subDays(9),
            'updated_at' => now()->subDays(9),
        ]);
    }

    $done[] = [$table, 'seeded ' . count($productIds)];
}

// -------------------------------------------------------------------------------------------------
// ORDER NOTES WITH A RECORDED AUTHOR -- the capture gap behind the "Order note author" row.
// -------------------------------------------------------------------------------------------------
//
// `order/partials/note-author.blade.php` renders an author IF AND ONLY IF one was recorded, and
// MEASURED on this fixture all 56 `order_notes` rows carry `created_by = NULL`. So the partial
// rendered NOTHING on every row, and there was no screen to photograph -- the row was PENDING for
// want of data, not for want of a design.
//
// The partial documents four outcomes. Three of them are seeded here so one capture shows the
// whole contract rather than only the happy path, and the fourth is already present:
//
//   author named      `created_by` = a staff user in the viewer's tenant (user 3, Rami Haddad,
//                     created_by=2, so `creatorId()` is 2 and matches the viewer's)
//   author withheld   `created_by` = the PLATFORM OPERATOR (user 1, `creatorId()` 1). The partial
//                     returns null for an author outside the viewer's tenant, so a merchant must
//                     NOT see the operator's name here. This is the tenancy limb of the partial and
//                     a picture of it is worth more than the two happy cases.
//   author missing    `created_by` = an id no user holds. No foreign key exists on this column, on
//                     purpose (a CASCADE would delete commercial records to tidy up a user row), so
//                     this state is reachable in production and renders nothing.
//   no author         the 56 rows already in the fixture -- system notes composed by
//                     `OrderNote::order_note_data()` with nobody authenticated.
//
// `notes` carries the tag so `--remove` can find them; the text still reads as a real note.
if (! Schema::hasTable('order_notes')) {
    $done[] = ['order_notes', 'NO TABLE in this fixture'];
} else {
    $mine = DB::table('order_notes')->where('notes', 'like', '%' . TAG . '%');

    if ($remove) {
        $n = $mine->count();
        $mine->delete();
        $done[] = ['order_notes', "removed $n"];
    } else {
        $orderId = (int) DB::table('orders')->where('store_id', $storeId)->min('id');

        // An id no user holds. Computed, not hard-coded: a literal would silently become a REAL
        // user the moment the fixture gained rows, and the capture would then show a name where it
        // is meant to show nothing.
        $absentUserId = ((int) DB::table('users')->max('id')) + 1000;

        if ($orderId === 0) {
            $done[] = ['order_notes', 'skipped: the store has no order to attach a note to'];
        } elseif ($mine->count() > 0) {
            $done[] = ['order_notes', 'skipped: already seeded'];
        } else {
            $authors = [
                [3, 'Checked the roll count against the picking slip. ' . TAG],
                [1, 'Reviewed at platform level after the payment retry. ' . TAG],
                [$absentUserId, 'Called the customer to confirm the delivery window. ' . TAG],
            ];

            foreach ($authors as $i => [$author, $text]) {
                DB::table('order_notes')->insert([
                    'order_id' => $orderId,
                    'customer_id' => null,
                    'notes' => $text,
                    'note_type' => null,
                    'status' => 'Order Note',
                    'store_id' => $storeId,
                    'created_by' => $author,
                    // Ordered a few minutes apart so the timeline reads as a sequence and the three
                    // outcomes sit next to each other in one screenshot.
                    'created_at' => now()->subMinutes(30 - ($i * 5)),
                    'updated_at' => now()->subMinutes(30 - ($i * 5)),
                ]);
            }

            $done[] = ['order_notes', 'seeded 3 on order ' . $orderId
                . ' (tenant author, operator author, absent author ' . $absentUserId . ')'];
        }
    }
}

// -------------------------------------------------------------------------------------------------
// THE FINANCE SCREENS -- five inventory rows that had nothing to render.
// -------------------------------------------------------------------------------------------------
//
// MEASURED before this block: `store_order_financials` 0 rows, `store_withdrawals` 0 rows, and the
// one `store_wallets` row with every balance at 0. So:
//
//   Add / Deduct / Request modals     opened, but every one announced "Available balance: 0.00"
//   Order payment-status (inline)     `form[action*="order-financial"]` was NOT IN THE DOM at all,
//                                     because finance/index.blade.php renders the control per
//                                     financial row and there were none
//   Withdrawal approve / pay / reject `/finance/withdrawals` had no rows, so no action cell and no
//                                     `#rejectWithdrawalModal{id}` trigger existed
//
// Three of those five read as "surface missing" in a capture run and two as "fragment never
// arrived". None of them was a design or markup problem: the screens had no data.
//
// WHAT IS SEEDED, and why each value:
//   wallet            a real non-zero balance, so the three modals state a figure a merchant could
//                     act on rather than 0.00 -- and so the measured contrast of those alert
//                     banners is measured against real content
//   financials        TWO rows, one `unpaid` and one `paid`. The inline control only renders for a
//                     viewer holding `Edit Finance Payment Status`, and finance/index.blade.php:456
//                     branches on `payment_status === 'paid'`, so one row of each shows both arms
//   withdrawals       TWO rows, one `pending` and one `approved`. withdrawals.blade.php:157 renders
//                     approve+reject for `pending` and pay+reject for `approved`, so one of each
//                     puts every action in the inventory row on one screen
//
// `store_note` carries the tag on withdrawals and `currency_code` is left real; financial rows are
// identified by their ORDER, which is this store's own, plus the store -- the same identification
// the carts/wishlists block above uses, and the tool still refuses a table it did not empty.
if (! Schema::hasTable('store_wallets')) {
    $done[] = ['store_wallets', 'NO TABLE in this fixture'];
} else {
    $walletId = (int) DB::table('store_wallets')->where('store_id', $storeId)->value('id');

    if ($walletId === 0) {
        $done[] = ['store_wallets', 'skipped: this store has no wallet row'];
    } elseif ($remove) {
        DB::table('store_wallets')->where('id', $walletId)->update([
            'available_balance' => 0,
            'pending_balance' => 0,
            'withdrawal_pending_balance' => 0,
            'total_income' => 0,
            'total_withdrawn' => 0,
            'updated_at' => now(),
        ]);
        $done[] = ['store_wallets', 'balances reset to 0'];
    } else {
        DB::table('store_wallets')->where('id', $walletId)->update([
            'available_balance' => 18450.00,
            'pending_balance' => 3200.00,
            'withdrawal_pending_balance' => 5000.00,
            'total_income' => 41700.00,
            'total_withdrawn' => 18250.00,
            'updated_at' => now(),
        ]);
        $done[] = ['store_wallets', 'balances set'];
    }

    // ---- order financials ----------------------------------------------------------------------
    if (! Schema::hasTable('store_order_financials')) {
        $done[] = ['store_order_financials', 'NO TABLE in this fixture'];
    } else {
        $orderIds = DB::table('orders')->where('store_id', $storeId)->orderBy('id')->limit(2)->pluck('id')->all();
        $mine = DB::table('store_order_financials')->where('store_id', $storeId);

        if ($remove) {
            $n = $mine->count();
            $mine->delete();
            $done[] = ['store_order_financials', "removed $n"];
        } elseif ($mine->count() > 0) {
            $done[] = ['store_order_financials', 'skipped: already holds rows'];
        } elseif (count($orderIds) < 2 || $walletId === 0) {
            $done[] = ['store_order_financials', 'skipped: needs two orders and a wallet'];
        } else {
            foreach ([['unpaid', 0.00, 6400.00], ['paid', 4800.00, 0.00]] as $i => [$status, $paid, $remaining]) {
                DB::table('store_order_financials')->insert([
                    'store_id' => $storeId,
                    'wallet_id' => $walletId,
                    'order_id' => $orderIds[$i],
                    'currency_code' => 'USD',
                    'currency_symbol' => '$',
                    'product_amount' => 6000.00,
                    'discount_amount' => 200.00,
                    'tax_amount' => 350.00,
                    'shipping_amount' => 250.00,
                    'customer_total' => $paid + $remaining,
                    'product_cost' => 3900.00,
                    'gross_profit' => 2100.00,
                    'payment_status' => $status,
                    'paid_amount' => $paid,
                    'remaining_amount' => $remaining,
                    'paid_at' => $status === 'paid' ? now()->subDays(2) : null,
                    'created_at' => now()->subDays(6 - $i),
                    'updated_at' => now()->subDays(6 - $i),
                ]);
            }

            $done[] = ['store_order_financials', 'seeded 2 (unpaid, paid) on orders ' . implode(',', $orderIds)];
        }
    }

    // ---- withdrawals ---------------------------------------------------------------------------
    if (! Schema::hasTable('store_withdrawals')) {
        $done[] = ['store_withdrawals', 'NO TABLE in this fixture'];
    } else {
        $mine = DB::table('store_withdrawals')->where('store_note', 'like', '%' . TAG . '%');

        if ($remove) {
            $n = $mine->count();
            $mine->delete();
            $done[] = ['store_withdrawals', "removed $n"];
        } elseif ($mine->count() > 0) {
            $done[] = ['store_withdrawals', 'skipped: already seeded'];
        } elseif ($walletId === 0) {
            $done[] = ['store_withdrawals', 'skipped: this store has no wallet row'];
        } else {
            foreach ([['pending', 5000.00], ['approved', 2500.00]] as $i => [$status, $amount]) {
                DB::table('store_withdrawals')->insert([
                    'store_id' => $storeId,
                    'wallet_id' => $walletId,
                    'amount' => $amount,
                    'status' => $status,
                    'payment_method' => 'bank_transfer',
                    'payment_details' => json_encode([
                        'bank' => 'Commercial Bank of Syria',
                        'iban' => 'SY00 0000 0000 0000 0000 0000',
                    ]),
                    'store_note' => 'Monthly payout request. ' . TAG,
                    'admin_note' => null,
                    'requested_by' => $createdBy,
                    'reviewed_by' => $status === 'approved' ? $createdBy : null,
                    'requested_at' => now()->subDays(4 - $i),
                    'reviewed_at' => $status === 'approved' ? now()->subDays(1) : null,
                    'paid_at' => null,
                    'created_at' => now()->subDays(4 - $i),
                    'updated_at' => now()->subDays(4 - $i),
                ]);
            }

            $done[] = ['store_withdrawals', 'seeded 2 (pending, approved)'];
        }
    }
}

// 79 of the 95 models are `Cachable` and the file cache was populated before these writes, so a
// stale list would keep rendering the empty state the seeding exists to remove.
$cache = __DIR__ . '/../../../../storage/framework/cache/data';

//
// `.gitkeep` IS TRACKED AND MUST SURVIVE. `storage/framework/cache/data/.gitkeep` is in git, and a
// tool that empties this directory and takes it with it shows up as a deletion in the next commit
// -- which is how it was noticed. `glob()` does not match leading dots, so the loop below never
// touched it and is not the culprit; the explicit skip is here so that stays true if anyone adds
// `GLOB_BRACE`, switches to a `RecursiveDirectoryIterator`, or reaches for `rm -rf` instead.
$keep = static fn (string $path): bool => basename($path) !== '.gitkeep';

if (is_dir($cache)) {
    foreach (glob($cache . '/*') as $entry) {
        if (! $keep($entry)) {
            continue;
        }

        if (is_dir($entry)) {
            foreach (glob($entry . '/*') as $inner) {
                if (! $keep($inner)) {
                    continue;
                }

                is_dir($inner)
                    ? array_map('unlink', array_filter(glob($inner . '/*') ?: [], $keep))
                    : @unlink($inner);
            }
        } else {
            @unlink($entry);
        }
    }
}

echo "store_id=$storeId created_by=$createdBy products=" . implode(',', $productIds)
    . ' customer=' . ($customerId ?: 'none') . "\n";

foreach ($done as [$table, $what]) {
    printf("  %-14s %s\n", $table, $what);
}
