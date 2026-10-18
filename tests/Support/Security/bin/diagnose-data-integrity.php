#!/usr/bin/env php
<?php

/**
 * P01-SEC-14 — READ-ONLY DATA-INTEGRITY DIAGNOSTIC, for the owner to run against a real database.
 *
 * STRICTLY READ-ONLY. Every statement below is a SELECT. There is no INSERT, UPDATE, DELETE,
 * CREATE or DROP anywhere in this file, and it opens no transaction. It is safe to point at the
 * development or production database, which is the whole reason it exists: the questions it answers
 * are questions about live data, and this package may not touch live data itself.
 *
 * WHY A SCRIPT. Two P01-SEC-14 items turn on counts that no test can obtain:
 *
 *   C5  `resolveStoreCustomer()` admits a customer whose `store_id` is NULL, in any store's slug.
 *       Such a customer is a cross-tenant actor through the handlers that also act on the slug's
 *       store -- payment_sheet reads that store's Stripe secret, update_user_image bills its
 *       owner's quota, add_address / change_address / wishlist write store_id = $store->id, and
 *       cart and order rows land there. The branch was left permissive because refusing would lock
 *       a legacy shopper out of their own account, and because no first-party insert path produces
 *       such a row (config/tenancy.php enumerates the writers of `customers` and every one sets
 *       store_id). Whether to tighten it depends on one count, and only the owner can take it.
 *
 *   C1  `user_delete` hard-deletes a customer's orders with a query-builder mass delete, so no
 *       model events fire. `Order` uses no SoftDeletes, `OrderObserver` has only created/updated
 *       hooks, and `OrderFinancialService` has no reversal method. So the `store_order_financials`
 *       rows written when those orders were created are left pointing at orders that no longer
 *       exist, and any wallet credit is not reversed. That cannot be shown in the test suite at
 *       all: `store_order_financials` and `store_wallets` have no applied migration (only drafts
 *       under database/migrations/_gate_f_pending/), so in tests the observer's try/catch swallows
 *       the failure and no financial row is ever written.
 *
 * HOW TO RUN. It uses the application's own configured default connection, so point it at whatever
 * .env you intend:
 *
 *   php tests/Support/Security/bin/diagnose-data-integrity.php
 *
 * Exit code 0 means it ran. A non-zero count is NOT a failure -- it is the answer. Nothing here
 * decides anything; each section says what the number means and who decides.
 */

require __DIR__ . '/../../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$connection = DB::connection();

echo "P01-SEC-14 data-integrity diagnostic (READ ONLY)\n";
echo str_repeat('=', 78) . "\n";
printf("connection: %s   driver: %s   database: %s\n",
    $connection->getName(),
    $connection->getDriverName(),
    (string) $connection->getDatabaseName());

// Fail with a sentence rather than a stack trace: the likely mistake here is pointing the script
// at an environment whose database is not reachable, and a 40-line trace buries that.
try {
    $connection->getPdo();
} catch (Throwable $e) {
    fwrite(STDERR, "\nCould not connect: {$e->getMessage()}\n");
    fwrite(STDERR, "Check the .env this is running under. Nothing was read or written.\n");
    exit(2);
}
echo "\n";

function section(string $title): void
{
    echo "\n" . $title . "\n" . str_repeat('-', strlen($title)) . "\n";
}

function missing(string $table): bool
{
    try {
        if (! Schema::hasTable($table)) {
            printf("  SKIPPED: table `%s` does not exist on this connection.\n", $table);

            return true;
        }
    } catch (Throwable $e) {
        printf("  SKIPPED: could not inspect `%s`: %s\n", $table, $e->getMessage());

        return true;
    }

    return false;
}

// ---------------------------------------------------------------------------------------------
section('C5 — customers whose store_id is NULL');

if (! missing('customers')) {
    $total = DB::table('customers')->count();
    $nullStore = DB::table('customers')->whereNull('store_id')->count();

    printf("  customers total           : %d\n", $total);
    printf("  customers with NULL store : %d\n", $nullStore);

    if ($nullStore === 0) {
        echo "\n  MEANING: there are none. resolveStoreCustomer()'s null-store branch is\n";
        echo "  unreachable in this database, so it can be tightened to an exact store match with\n";
        echo "  no shopper losing access. That removes the cross-tenant reach described above.\n";
        echo "  The same branch exists in change_password() and user_delete().\n";
    } else {
        echo "\n  MEANING: these accounts WOULD lose access if the branch were tightened, and they\n";
        echo "  currently reach any store's slug. Before deciding, look at where they came from:\n";
        printf("  by register_type:\n");
        foreach (DB::table('customers')->whereNull('store_id')
            ->select('register_type', DB::raw('COUNT(*) AS n'))
            ->groupBy('register_type')->get() as $row) {
            printf("    %-20s %d\n", $row->register_type ?? '(null)', $row->n);
        }
        echo "  Options are to backfill store_id from each account's own orders or addresses, or to\n";
        echo "  tighten and accept the loss. Both are owner decisions.\n";
    }

    // A backfill hint: can store_id be inferred for these rows?
    if ($nullStore > 0 && ! missing('orders')) {
        $inferable = DB::table('customers')
            ->whereNull('customers.store_id')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('orders')
                  ->whereColumn('orders.customer_id', 'customers.id')
                  ->whereNotNull('orders.store_id');
            })->count();
        printf("  of those, %d have at least one order carrying a store_id (so are backfillable)\n",
            $inferable);
    }
}

// ---------------------------------------------------------------------------------------------
section('C1 — financial rows pointing at orders that no longer exist');

if (! missing('store_order_financials')) {
    $totalFinancials = DB::table('store_order_financials')->count();
    $orphaned = DB::table('store_order_financials as f')
        ->leftJoin('orders as o', 'o.id', '=', 'f.order_id')
        ->whereNull('o.id')
        ->count();

    printf("  store_order_financials rows : %d\n", $totalFinancials);
    printf("  orphaned (no matching order): %d\n", $orphaned);

    if ($orphaned === 0) {
        echo "\n  MEANING: no account deletion has yet orphaned a financial row in this database.\n";
        echo "  The defect is still live -- user_delete still hard-deletes orders with no reversal\n";
        echo "  path -- so this is a statement about history, not about the code.\n";
    } else {
        echo "\n  MEANING: these rows record revenue against orders that have been deleted, so the\n";
        echo "  merchant's ledger no longer reconciles to the orders table. Any wallet credit made\n";
        echo "  at the time was not reversed. Deciding what to do with them -- reverse, retain and\n";
        echo "  annotate, or archive -- is a financial retention decision and the owner's alone.\n";
        echo "  This package deliberately changed nothing here.\n";

        if (! missing('store_wallets')) {
            foreach (DB::table('store_order_financials as f')
                ->leftJoin('orders as o', 'o.id', '=', 'f.order_id')
                ->whereNull('o.id')
                ->select('f.store_id', DB::raw('COUNT(*) AS n'))
                ->groupBy('f.store_id')->get() as $row) {
                printf("    store %-6s %d orphaned row(s)\n", $row->store_id, $row->n);
            }
        }
    }
}

// ---------------------------------------------------------------------------------------------
section('C1 adjacent — soft-deleted customers whose email is now unusable');

if (! missing('customers')) {
    $trashed = DB::table('customers')->whereNotNull('deleted_at')->count();
    printf("  soft-deleted customers: %d\n", $trashed);

    if ($trashed > 0) {
        echo "\n  MEANING: `customers` has a table-wide unique index on email and the registration\n";
        echo "  email-taken check looks through trashed rows, so each of these addresses can never\n";
        echo "  register again in that store. A shopper who deleted their account cannot come back\n";
        echo "  under the same address. Whether that is the intended retention behaviour is an\n";
        echo "  owner decision; the asymmetry is that the ACCOUNT is soft-deleted while its ORDERS\n";
        echo "  were hard-deleted.\n";
    }
}

// ---------------------------------------------------------------------------------------------
section('B adjacent — orders whose recorded tax sits in the wrong column');

if (! missing('orders')) {
    $withTaxInProduct = DB::table('orders')
        ->where('tax_price', 0)
        ->whereColumn('product_price', '>', 'final_price')
        ->count();
    $total = DB::table('orders')->count();

    printf("  orders total                                   : %d\n", $total);
    printf("  orders with tax_price = 0 and product > final  : %d\n", $withTaxInProduct);

    if ($withTaxInProduct > 0) {
        echo "\n  MEANING: these orders record 0 tax while their product_price exceeds the amount the\n";
        echo "  customer owes, which is the shape produced by the cart double-counting tax into\n";
        echo "  product_price. OrderFinancialService reads product_price as the ledger's product\n";
        echo "  amount, so the merchant's revenue line is inflated for each of these. Measured in\n";
        echo "  the suite: a 100.00 product under a 10%% tax yields final_price 110, product_price\n";
        echo "  121, tax_price 0. Reconciling the three columns is an order-calculation change and\n";
        echo "  needs owner approval.\n";
    }
}

echo "\n" . str_repeat('=', 78) . "\n";
echo "Diagnostic complete. Nothing was written.\n";

exit(0);
