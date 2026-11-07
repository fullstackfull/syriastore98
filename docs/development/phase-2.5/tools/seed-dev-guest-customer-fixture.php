<?php
/**
 * SEED A GUEST CUSTOMER, so P2.5-PKG-G Slice 3's Status-column repair can be SEEN.
 * =================================================================================================
 *
 * WHY. Slice 3 fills two holes in `customer/status.blade.php`, which rendered LITERALLY NOTHING --
 * not a dash, not a word -- under a printed `Status` header, for two populous cases. One of them is
 * a GUEST: a null `regiester_date` is how this codebase models a customer who never registered
 * (`ReportController` queries `regiester_date = NULL` under the name `$new_guest`, and
 * `PaymentController::createCustomer()` writes the column only for a registered one).
 * `CustomerDataTable::query()` applies no `regiester_date` predicate, so guests really do appear in
 * the merchant's list.
 *
 * `database/seed-p2dev.php` leaves store 1 with three customers and ALL THREE REGISTERED:
 *
 *     Layla Haddad   regiester_date 2026-10-08
 *     Omar Khalil    regiester_date 2026-10-08
 *     Nour Saleh     regiester_date 2026-10-08
 *
 * So the guest branch cannot be photographed against the stock fixture, and the only honest
 * before/after is one where the answer visibly changes. That is the same reason
 * `seed-dev-customer-filter-fixtures.php` exists, and this follows its conventions exactly.
 *
 * REVERSIBLE AND TAGGED. The row carries the `p25guest-` prefix on its e-mail, and `--remove`
 * deletes exactly that and nothing else.
 *
 * IDEMPOTENT. Running it twice reconciles rather than appending.
 *
 * THROUGH ELOQUENT, NOT `DB::table()`. `Customer` is `Cachable` and `StoreScope`-scoped; a raw
 * write leaves the list reading pre-write rows, which this project has measured three times.
 * `withoutGlobalScopes()` is used for the LOOKUPS so this script sees what it wrote whatever
 * tenant happens to resolve on the console.
 *
 * usage:
 *   php artisan tinker --execute="require base_path('docs/development/phase-2.5/tools/seed-dev-guest-customer-fixture.php');"
 *   ... and to remove, set P25_REMOVE=1 in the environment (tinker --execute takes no argv).
 */

use App\Models\Customer;

$email = 'p25guest-walkin@example.test';
$storeId = 1;

if (getenv('P25_REMOVE')) {
    $gone = Customer::withoutGlobalScopes()->where('email', $email)->get();
    foreach ($gone as $c) {
        $c->forceDelete();
    }
    echo "REMOVE  {$email}  rows=" . $gone->count() . "\n";
} else {
    $customer = Customer::withoutGlobalScopes()->where('email', $email)->first();

    if (! $customer) {
        $customer = new Customer();
        $customer->email = $email;
    }

    $customer->first_name = 'Walk-in';
    $customer->last_name = 'Guest';
    $customer->password = bcrypt(\Illuminate\Support\Str::random(32));
    $customer->store_id = $storeId;
    $customer->status = 1;
    // THE WHOLE POINT: no registration date. Everything else is an ordinary customer row.
    $customer->regiester_date = null;
    $customer->save();

    echo "ADD     {$email}  id={$customer->id}  regiester_date=" .
        ($customer->regiester_date === null ? 'NULL (guest)' : $customer->regiester_date) . "\n";
}

echo "--- store {$storeId} customers now ---\n";
foreach (Customer::withoutGlobalScopes()->where('store_id', $storeId)->orderBy('id')->get() as $c) {
    printf(
        "%-30s status=%s  %s\n",
        $c->email,
        $c->status,
        $c->regiester_date === null ? 'GUEST (no regiester_date)' : 'registered'
    );
}
