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

// ---------------------------------------------------------------------------------------------
//  P1-ORD-15 -- the geography the order writers have already stored.
// ---------------------------------------------------------------------------------------------

echo "\n" . str_repeat('-', 78) . "\n";
echo "P1-ORD-15  cities and order_billing_details rows written before the resolver\n\n";

if (! missing('cities')) {
    $zeroGeography = DB::table('cities')
        ->where(fn ($q) => $q->where('state_id', 0)->orWhere('country_id', 0))
        ->count();

    $nullGeography = DB::table('cities')
        ->where(fn ($q) => $q->whereNull('state_id')->orWhereNull('country_id'))
        ->count();

    /*
     * The junk the name-keyed lookup produced: the posted value was a city ID, the lookup was by
     * NAME, so it never matched and a city whose NAME is that number was inserted per order.
     *
     * DRIVER-SPECIFIC, and branched rather than assumed: MySQL has REGEXP and SQLite does not
     * (it answers "no such function: REGEXP" and, because this script bootstraps the framework,
     * Laravel's exception handler would RENDER that and exit 0 -- the silent half-run the MySQL
     * runner's own header warns about). Anything else says so instead of guessing.
     */
    $numericNames = null;
    $driver = $connection->getDriverName();

    try {
        if ($driver === 'mysql') {
            $numericNames = (int) DB::table('cities')
                ->whereRaw("TRIM(COALESCE(name, '')) REGEXP '^[0-9]+$'")
                ->count();
        } elseif ($driver === 'sqlite') {
            $numericNames = (int) DB::table('cities')
                ->whereRaw("TRIM(COALESCE(name, '')) GLOB '[0-9]*'")
                ->whereRaw("NOT TRIM(COALESCE(name, '')) GLOB '*[^0-9]*'")
                ->count();
        }
    } catch (\Throwable $e) {
        $numericNames = null;
    }

    printf("  cities total                                   : %d\n", DB::table('cities')->count());
    printf("  cities with state_id = 0 or country_id = 0     : %d\n", $zeroGeography);
    printf("  cities with state_id NULL or country_id NULL   : %d\n", $nullGeography);
    printf("  cities whose NAME is entirely digits           : %s\n",
        $numericNames === null ? "not counted on driver '{$driver}'" : (string) $numericNames);

    if ($zeroGeography > 0) {
        echo "\n  MEANING (state_id/country_id = 0): a city pointing at country 0 or state 0, which no\n";
        echo "  countries or states row carries. MEASURED on MySQL 8.0.46 by\n";
        echo "  verify-ord15-billing-geography-on-mysql.php: this is only producible where the\n";
        echo "  declared foreign keys are ABSENT -- with them present the insert is refused with\n";
        echo "  error 1452. So a non-zero count here is also evidence about the live schema.\n";
        echo "  REPAIR IS AN OWNER DECISION: these rows are referenced by order_billing_details, so\n";
        echo "  deleting them would blank the city on historical orders. Re-pointing them needs a\n";
        echo "  judgement about which state each city belongs to.\n";
    }

    if (($numericNames ?? 0) > 0) {
        echo "\n  MEANING (numeric names): each of these is one order's worth of junk from the\n";
        echo "  name-keyed lookup P1-ORD-15 replaced. Their state_id and country_id ARE valid, so\n";
        echo "  the only harm is a city list polluted with numbers and an order detail that shows a\n";
        echo "  number where the city should be. The count is roughly the number of affected orders.\n";
    }
}

if (! missing('order_billing_details')) {
    // These three string columns are joined to countries.id / states.id / cities.id by
    // OrderBillingDetail's relations, so a value that resolves to no row displays as blank.
    $unresolvable = [];

    foreach ([['city', 'cities'], ['state', 'states'], ['country', 'countries'],
              ['delivery_city', 'cities'], ['delivery_state', 'states'], ['delivery_country', 'countries']] as [$column, $table]) {
        if (missing($table)) {
            continue;
        }

        $unresolvable[$column] = DB::table('order_billing_details')
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->where($column, '!=', '0')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from($table)
                ->whereColumn($table . '.id', 'order_billing_details.' . $column))
            ->count();
    }

    printf("\n  order_billing_details total                    : %d\n", DB::table('order_billing_details')->count());

    foreach ($unresolvable as $column => $count) {
        printf("  %-45s: %d\n", $column . ' resolving to no row', $count);
    }

    if (array_sum($unresolvable) > 0) {
        echo "\n  MEANING: these orders carry a value in an id-joined column that matches no row --\n";
        echo "  a posted NAME, most likely, from a caller of the storefront API. Order::order_detail()\n";
        echo "  renders these through the relations, so the merchant sees an EMPTY field for each.\n";
        echo "  The data is not lost: the name is still in the column and can be resolved to an id\n";
        echo "  by matching it against states/countries/cities. That is a data migration, and it is\n";
        echo "  the owner's decision whether these historical orders are worth repairing.\n";
    }
}

echo "\n" . str_repeat('-', 78) . "\n";
echo "P1-PLT-08  delivery_boys rows that the tenant scope would make invisible\n\n";

/*
 * THE ONE PRECONDITION P1-PLT-08 CANNOT ANSWER FROM THE REPOSITORY.
 *
 * `App\Models\DeliveryBoy` is now in config/tenancy.php's `scoped_models`, so a merchant's web-guard
 * queries carry `store_id IN ({their stores})`. A row with `store_id` NULL matches no such predicate
 * and becomes invisible TO ITS OWN MERCHANT -- the silent outage that looks like data loss.
 *
 * This codebase writes no such row: both writers set the column
 * (Api/DeliveryBoyApiController.php:160 and DeliveryBoyController.php:71), and that was measured
 * rather than assumed. But `delivery_boys.store_id` is `nullable()->index()`, so a deployment may
 * hold a row from before the column existed or from a manual insert. Only the owner can look.
 *
 * A non-zero count is NOT a reason to revert the allowlist entry: it is a reason to set `store_id` on
 * those rows first. Which store each belongs to is answerable from `created_by` -- the query below
 * prints that alongside, so the repair is a decision rather than a guess.
 */
if (! missing('delivery_boys')) {
    $total = DB::table('delivery_boys')->count();
    $nullStore = DB::table('delivery_boys')->whereNull('store_id')->count();
    $zeroStore = DB::table('delivery_boys')->where('store_id', 0)->count();

    printf("  delivery_boys total                           : %d\n", $total);
    printf("  store_id IS NULL                              : %d\n", $nullStore);
    printf("  store_id = 0                                  : %d\n", $zeroStore);

    $orphaned = DB::table('delivery_boys')
        ->whereNotNull('store_id')
        ->where('store_id', '!=', 0)
        ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('stores')
            ->whereColumn('stores.id', 'delivery_boys.store_id'))
        ->count();

    printf("  store_id naming a store that no longer exists : %d\n", $orphaned);

    if ($nullStore + $zeroStore > 0) {
        echo "\n  MEANING: these drivers are now invisible to the merchant who owns them -- the driver\n";
        echo "  list, the driver's edit screen and driver assignment on an order will all skip them.\n";
        echo "  The rows are intact and the DRIVER CAN STILL SIGN IN (driver login resolves no web\n";
        echo "  tenant, so no predicate applies there). The repair is to set store_id, and created_by\n";
        echo "  says which merchant each belongs to:\n\n";

        $byCreator = DB::table('delivery_boys')
            ->selectRaw('created_by, COUNT(*) as n')
            ->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', 0))
            ->groupBy('created_by')
            ->get();

        foreach ($byCreator as $row) {
            $stores = DB::table('stores')->where('created_by', $row->created_by)->pluck('id')->all();
            printf("    created_by %-8s : %d row(s); that user's stores: %s\n",
                (string) $row->created_by, (int) $row->n, $stores === [] ? 'NONE' : implode(', ', $stores));
        }

        echo "\n  A creator with exactly ONE store is unambiguous. A creator with several, or none, needs\n";
        echo "  the owner to say which store the driver worked for. Nothing here writes anything.\n";
    } else {
        echo "\n  MEANING: no row is at risk. The allowlist entry changes visibility for nobody who can\n";
        echo "  see their drivers today.\n";
    }
}

echo "\n" . str_repeat('=', 78) . "\n";
echo "Diagnostic complete. Nothing was written.\n";

exit(0);
