<?php
/**
 * SEED THE CUSTOMER ROWS THE P2.5-R-06 FILTER EVIDENCE NEEDS. Dev fixture only, idempotent,
 * reversible.
 * =================================================================================================
 *
 * WHY. The four faults P2.5-R-06 repaired are WRONG-ANSWER faults, so the only honest evidence is a
 * before/after where the answer visibly changes. Three of the four can be shown against the shipped
 * dev fixture; the fourth cannot, and the reason is arithmetic rather than preference.
 *
 * `database/seed-p2dev.php` leaves store 1 with three customers and orders for exactly ONE of them:
 *
 *     Layla Haddad   55 orders, 550.00 spend  ->  AOV  10.00
 *     Omar Khalil     0 orders,   0.00 spend  ->  AOV  none (no orders, so no average)
 *     Nour Saleh      0 orders,   0.00 spend  ->  AOV  none
 *
 * With one computable AOV in the store, the AOV 'Equal' fault is INVISIBLE: the assignment bug
 * matched "every customer with a computable average", and that set and the correct set are both
 * {Layla}. The screen looks right while the code is wrong — which is exactly the trap this phase
 * keeps recording.
 *
 * So this adds a second customer with orders, giving a DIFFERENT average:
 *
 *     Omar Khalil     2 orders, 300.00 spend  ->  AOV 150.00
 *
 * and then "AOV Equal 150" separates them: correct behaviour returns Omar alone, the assignment bug
 * returns Layla AND Omar. One card versus two, on screen.
 *
 * WHAT IT DOES **NOT** DO. It adds no customer and changes no customer. It writes two ORDER rows
 * against an existing customer, because the filters read `Ordercount()` and `total_spend()` through
 * `Customer::ownStoreOrders()`, which keys on `orders.store_id` + `orders.customer_id`.
 *
 * REVERSIBLE ON PURPOSE, and tagged the way `seed-dev-interaction-fixtures.php` tags its own rows:
 * every order this writes carries the `P25CUSTFILTER-` prefix on `product_order_id`, and `--remove`
 * deletes exactly those and nothing else.
 *
 * ADDITIVE, so `--remove` first if you need exact counts. Running it twice does not double the
 * orders: it reconciles to the target count rather than appending.
 *
 * THROUGH ELOQUENT, NOT `DB::table()`. `Order` is `Cachable` (79 of the 95 models are), and a raw
 * write leaves `Ordercount()` and `total_spend()` reading the pre-write rows — a false reading this
 * project has measured three times. `Order`'s `creating` hook also writes `currency_code` and
 * `currency_symbol`, which a raw insert would leave null.
 *
 * usage:
 *   php artisan tinker --execute="require base_path('docs/development/phase-2.5/tools/seed-dev-customer-filter-fixtures.php');"
 *   ... and with --remove, set P25_REMOVE=1 in the environment, since tinker --execute takes no argv.
 */

use App\Models\Customer;
use App\Models\Order;

if (! app()->environment(['local', 'testing'])) {
    throw new \RuntimeException(
        'seed-dev-customer-filter-fixtures.php is a development fixture and refuses to run outside '
        . 'local/testing.'
    );
}

const P25_CF_TAG = 'P25CUSTFILTER-';

$remove = (bool) (getenv('P25_REMOVE') ?: false);

/** The target profile. Keyed by customer e-mail so it cannot hit the wrong row by id. */
$targets = [
    'omar.khalil@example.test' => ['orders' => 2, 'each' => 150.00],
];

if ($remove) {
    $deleted = 0;
    foreach (Order::where('product_order_id', 'like', P25_CF_TAG . '%')->get() as $order) {
        $order->delete();   // through Eloquent so the model cache is flushed
        $deleted++;
    }
    echo "removed {$deleted} tagged order(s)\n";
    return;
}

foreach ($targets as $email => $profile) {
    /*
     * withoutGlobalScopes(), deliberately. `Customer` IS in config/tenancy.php's `scoped_models`,
     * so a plain Customer::where() answers against whichever tenant happens to be resolved — and a
     * console script resolves none. CLAUDE.md records this as a cause of false readings.
     */
    $customer = Customer::withoutGlobalScopes()->where('email', $email)->first();

    if (! $customer) {
        echo "SKIP {$email}: not in this fixture. Run database/seed-p2dev.php first.\n";
        continue;
    }

    $storeId = (int) $customer->store_id;

    if ($storeId <= 0) {
        echo "SKIP {$email}: customer has no store_id, so ownStoreOrders() would refuse anyway.\n";
        continue;
    }

    $existing = Order::where('store_id', $storeId)
        ->where('customer_id', $customer->id)
        ->where('product_order_id', 'like', P25_CF_TAG . '%')
        ->count();

    $wanted = (int) $profile['orders'];

    if ($existing === $wanted) {
        echo "OK   {$email}: already has {$wanted} tagged order(s)\n";
        continue;
    }

    // Reconcile rather than append, so a second run is a no-op rather than a doubling.
    if ($existing > $wanted) {
        $surplus = Order::where('store_id', $storeId)
            ->where('customer_id', $customer->id)
            ->where('product_order_id', 'like', P25_CF_TAG . '%')
            ->orderByDesc('id')
            ->limit($existing - $wanted)
            ->get();
        foreach ($surplus as $order) {
            $order->delete();
        }
        echo "TRIM {$email}: {$existing} -> {$wanted}\n";
        continue;
    }

    for ($i = $existing; $i < $wanted; $i++) {
        Order::create([
            'product_order_id' => P25_CF_TAG . $customer->id . '-' . ($i + 1),
            'order_date' => now()->subDays($wanted - $i),
            'customer_id' => $customer->id,
            'is_guest' => 0,
            'product_json' => '[]',
            'final_price' => $profile['each'],
            'payment_type' => 'cod',
            'payment_status' => 'Paid',
            'delivered_status' => 0,
            'store_id' => $storeId,
        ]);
    }

    $customer = Customer::withoutGlobalScopes()->find($customer->id);
    printf(
        "ADD  %-28s orders=%-4d spend=%-10s AOV=%s\n",
        $email,
        $customer->Ordercount(),
        number_format((float) $customer->total_spend(), 2),
        $customer->Ordercount() > 0
            ? number_format((float) $customer->total_spend() / $customer->Ordercount(), 2)
            : 'n/a'
    );
}

echo "--- store 1 customer profiles now ---\n";
foreach (Customer::withoutGlobalScopes()->where('store_id', 1)->orderBy('id')->get() as $c) {
    $n = $c->Ordercount();
    $s = (float) $c->total_spend();
    printf(
        "%-22s orders=%-4d spend=%-10s AOV=%s\n",
        $c->first_name . ' ' . $c->last_name,
        $n,
        number_format($s, 2),
        $n > 0 ? number_format($s / $n, 2) : 'n/a'
    );
}
