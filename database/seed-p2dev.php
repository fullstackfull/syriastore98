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
        'tax_price' => 0, 'delivery_price' => 0, 'coupon_price' => 0,
        'final_price' => $total, 'payment_type' => 'cod', 'payment_status' => $pay,
        'delivered_status' => $status, 'store_id' => $store->id,
    ]);
}

echo "seeded: merchant={$merchant->id} store={$store->id} products=" . Product::where('store_id', $store->id)->count()
   . " orders=" . Order::where('store_id', $store->id)->count() . "\n";
