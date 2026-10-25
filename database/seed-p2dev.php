<?php
use App\Models\{User, Store, Role, Permission, Product, Order};
use Illuminate\Support\Facades\{DB, Hash};

// NO REAL CUSTOMER DATA. Every value below is invented; the brief forbids production
// data in screenshots and the #120 dump is not on this branch.

$op = User::firstOrCreate(['email' => 'operator@example.test'], [
    'name' => 'Platform Operator', 'type' => 'super admin', 'password' => Hash::make('password'),
    'is_active' => 1, 'email_verified_at' => now(), 'created_by' => null, 'lang' => 'en',
]);

$merchant = User::firstOrCreate(['email' => 'merchant@example.test'], [
    'name' => 'Amina Haddad', 'type' => 'admin', 'password' => Hash::make('password'),
    'is_active' => 1, 'email_verified_at' => now(), 'created_by' => $op->id, 'plan_id' => 1, 'lang' => 'en',
]);

$store = Store::where('created_by', $merchant->id)->first();
if (! $store) {
    $store = Store::create([
        'name' => 'Damascus Textiles', 'email' => 'shop@example.test', 'slug' => 'damascus-textiles',
        'theme_id' => 'stylique', 'created_by' => $merchant->id, 'is_active' => 1, 'default_language' => 'en',
    ]);
}
$second = Store::where('created_by', $merchant->id)->where('slug', 'aleppo-soap')->first();
if (! $second) {
    $second = Store::create([
        'name' => 'Aleppo Soap Co', 'email' => 'soap@example.test', 'slug' => 'aleppo-soap',
        'theme_id' => 'stylique', 'created_by' => $merchant->id, 'is_active' => 1, 'default_language' => 'en',
    ]);
}
$merchant->current_store = $store->id;
$merchant->save();

// A role holding the merchant permissions, so the sidebar and the screens render.
$role = Role::where('name', 'p2dev-owner')->first();
if (! $role) {
    $role = Role::forceCreate([
        'name' => 'p2dev-owner', 'display_name' => 'Owner', 'guard_name' => 'web',
        'created_by' => $merchant->id, 'store_id' => $store->id,
    ]);
}
foreach ([
    'Manage Dashboard','Manage Order','Show Order','Manage Products','Create Product','Edit Product','Delete Product',
    'Manage Product Category','Manage Product Brand','Manage Customer','Manage User','Manage Role',
    // P2-05-1 grants the EDIT permissions the four converted toggles now require, plus the
    // label and customer-status ones the lists gate their switches on. All five are seeded in
    // app/Models/Utility.php and granted to a real tenant owner's role there, so this brings
    // the dev fixture in line with production rather than loosening anything -- measured: the
    // fixture merchant got 403 on /product-brand-status and was redirected off /product-label.
    'Edit Product Brand','Manage Product Label','Edit Product Label','Status Customer',
    'Manage Store Setting','Manage Reports','Manage Order Reports','Manage Stock Reports','Manage Coupon',
] as $name) {
    $p = Permission::firstOrCreate(['name' => $name], ['display_name' => $name]);
    if (! $role->hasPermission($name)) { $role->givePermission($p); }
}
if (! $merchant->hasRole($role->name)) { $merchant->addRole($role); }
$merchant->flushCache();

// Products, with a deliberate mix of stock states so the list shows real badges.
$rows = [
    ['Damask Cotton Scarf', 24.00, 48, 0],
    ['Hand-loomed Table Runner', 65.00, 3, 5],
    ['Brocade Cushion Cover', 32.50, 0, 5],
    ['Linen Tea Towel Set', 18.00, 120, 10],
    ['Silk Wall Hanging', 180.00, 7, 5],
];
/*
 * P2-06-4 NEEDS MORE THAN ONE PAGE. `check-table-prefs-live.cjs` asserts that a restored page
 * NUMBER is dropped, and with five rows there is no page two -- `t.page(1)` is a no-op, `start`
 * stays 0, and that assertion passes against a feature that stores nothing. Measured: the first
 * version of that check passed with the sanitiser disabled, which is exactly the vacuous shape
 * this project keeps catching. Thirty-two rows gives two pages at a length of 25.
 *
 * "Panel" is in every generated name ON PURPOSE, so a search can match many rows while matching
 * FEWER THAN ALL of them -- a filtered count that differs from the total is what makes
 * "the search term was dropped" measurable rather than assumed.
 */
for ($i = 1; $i <= 27; $i++) {
    $rows[] = [sprintf('Woven Panel No. %02d', $i), 12.00 + $i, ($i % 7) * 4, 5];
}

foreach ($rows as [$name, $price, $stock, $low]) {
    // forceCreate: `created_by` is NOT NULL in the schema and NOT in Product's $fillable,
    // so a mass-assigned create drops it and the insert fails on the constraint.
    $slug = \Illuminate\Support\Str::slug($name);
    if (Product::where('slug', $slug)->where('store_id', $store->id)->exists()) { continue; }
    Product::forceCreate([
        'slug' => $slug, 'store_id' => $store->id,
        'name' => $name, 'description' => 'A sample catalogue row for Phase 02 screenshots.',
        'price' => $price, 'sale_price' => 0, 'track_stock' => 1, 'product_stock' => $stock,
        'low_stock_threshold' => $low, 'status' => 1, 'product_type' => null,
        'variant_product' => 0, 'created_by' => $merchant->id,
        'stock_status' => $stock > 0 ? 'in_stock' : 'out_of_stock',
    ]);
}

/*
 * P2-05-1 fixtures: brands, labels and customers, so the three toggles that became POST can be
 * driven in a real browser. Invented data only -- the brief forbids production data in
 * screenshots and the #120 dump is not on this branch.
 *
 * `created_by` and `store_id` are set EXPLICITLY on every row: neither is in the models'
 * $fillable, so a mass-assigned create drops them and the row becomes invisible to the store
 * predicates the handlers now carry.
 */
foreach ([['Damascene Silk', 1, 1], ['Aleppo Weave', 1, 0], ['Homs Cotton', 0, 0]] as [$bname, $bstatus, $bpopular]) {
    $bslug = \Illuminate\Support\Str::slug($bname);
    if (! \App\Models\ProductBrand::where('slug', $bslug)->where('store_id', $store->id)->exists()) {
        \App\Models\ProductBrand::forceCreate([
            'name' => $bname, 'slug' => $bslug, 'logo' => null,
            'status' => $bstatus, 'is_popular' => $bpopular,
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);
    }
}

foreach ([['New Arrival', 1], ['Clearance', 1], ['Limited', 0]] as [$lname, $lstatus]) {
    $lslug = \Illuminate\Support\Str::slug($lname);
    if (! \App\Models\ProductLabel::where('slug', $lslug)->where('store_id', $store->id)->exists()) {
        \App\Models\ProductLabel::forceCreate([
            'name' => $lname, 'slug' => $lslug, 'status' => $lstatus,
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);
    }
}

foreach ([['Layla', 'Haddad', 1], ['Omar', 'Khalil', 1], ['Nour', 'Saleh', 0]] as [$first, $last, $cstatus]) {
    $email = strtolower($first . '.' . $last) . '@example.test';
    if (! \App\Models\Customer::withoutGlobalScopes()->where('email', $email)->exists()) {
        /*
         * `regiester_date` (sic -- the column is misspelled in the schema) is REQUIRED for the
         * status switch to render: customer/status.blade.php wraps it in
         * `@if ($customer->regiester_date != null)`. Measured -- without it the customer list
         * showed three raw zeros where the switch belongs, which read as a defect and was a
         * fixture gap.
         */
        \App\Models\Customer::forceCreate([
            'first_name' => $first, 'last_name' => $last, 'email' => $email,
            'password' => Hash::make('password'), 'status' => $cstatus,
            'regiester_date' => now()->subDays(30),
            'store_id' => $store->id,
        ]);
    }
}

// Orders across the statuses the dashboard partitions, including 7 and 8 -- the two
// values that are NOT fulfilment states (ledger F0), so the preview's three-reading
// presentation has something real to show.
$orders = [
    ['ORD-2041', 0, 'Unpaid', 148.00],
    ['ORD-2042', 4, 'Paid', 65.00],
    ['ORD-2043', 6, 'Paid', 212.50],
    ['ORD-2044', 1, 'Paid', 36.00],
    ['ORD-2045', 2, 'Unpaid', 180.00],
    ['ORD-2046', 7, 'Partially Paid', 97.00],
    ['ORD-2047', 8, 'Unpaid', 54.00],
];

/*
 * `orders.product_id` IS WHAT THE DASHBOARD'S BEST-SELLING BLOCK READS, not `product_json`.
 * `HomeController` plucks `product_id`, splits it on commas and counts the ids. Leaving it at
 * its default of '0' meant `topSellingProductIds` was `[0]`, nothing matched, and the block
 * rendered its EMPTY state while looking perfectly healthy -- so the feature was built, shipped
 * and never once seen with data in it. Found by driving the dashboard and reading the card.
 *
 * The lists below give the orders overlapping products, so one is genuinely the best seller
 * rather than every product tying at one.
 */
$ssOrderProducts = [
    'ORD-2041' => '1,4',
    'ORD-2042' => '1,2',
    'ORD-2043' => '1,3',
    'ORD-2044' => '2,4',
    'ORD-2045' => '1',
    'ORD-2046' => '2',
    'ORD-2047' => '5',
];

foreach ($orders as [$ref, $status, $pay, $total]) {
    if (Order::where('product_order_id', $ref)->exists()) { continue; }
    Order::create([
        'product_order_id' => $ref,
        'order_date' => now()->subDays(random_int(0, 20)),
        'customer_id' => 0, 'is_guest' => 1,
        'product_json' => json_encode([
            ['product_id' => 1, 'name' => 'Damask Cotton Scarf', 'qty' => 2, 'price' => 24.00,
             'total_orignal_price' => 48.00, 'final_price' => 48.00],
            ['product_id' => 4, 'name' => 'Linen Tea Towel Set', 'qty' => 1, 'price' => 18.00,
             'total_orignal_price' => 18.00, 'final_price' => 18.00],
        ]),
        // `product_price` is what order_detail() reports as the SUBTOTAL
        // (Order.php:516, `SetNumber($order->product_price)`). Leaving it unset made the
        // panel show "Subtotal $0,00" beside real line items -- a fixture artifact, found in
        // a screenshot of the running application.
        'product_price' => 66.00,
        // See the note above the list: this, not product_json, is what the dashboard counts.
        'product_id' => $ssOrderProducts[$ref] ?? '1',
        'tax_price' => 0, 'delivery_price' => 0, 'coupon_price' => 0,
        'final_price' => $total, 'payment_type' => 'cod', 'payment_status' => $pay,
        'delivered_status' => $status, 'store_id' => $store->id,
    ]);
}

echo "seeded: merchant={$merchant->id} store={$store->id} products=" . Product::where('store_id', $store->id)->count()
   . " orders=" . Order::where('store_id', $store->id)->count() . "\n";
