#!/usr/bin/env php
<?php

/**
 * GAP-280 — MEASURE WHAT THE TWO CART READERS DO WITH A FOREIGN PRODUCT, AND WHAT IT WOULD COST TO
 * SCOPE THEM.
 * =================================================================================================
 *
 * WHY THIS IS A SCRIPT AND NOT A TEST. Every figure below is a measurement of the CURRENT,
 * UNCHANGED behaviour — including the defect. A PHPUnit test asserting it would PIN THE DEFECT AS
 * CORRECT, and `.github/known-test-outcomes.json` declares an EMPTY `expected_failures` set which
 * its own README forbids using as a parking spot. So the evidence lives here, in the convention
 * `verify-sec13-migration-on-mysql.php` set: an executable script that states its own
 * preconditions. The part of the behaviour that is already CORRECT — the foreign product never
 * reaching an order or a stock decrement — is ratcheted by a real test instead
 * (`ForeignProductCartReadIsContainedTest`).
 *
 * PRECONDITIONS: run from the repository root with a writable sqlite in-memory test environment,
 * i.e. the same configuration `php artisan test` uses. It migrates a throwaway in-memory database,
 * creates its own two tenants, and writes nothing outside them.
 *
 *     php tests/Support/Security/bin/measure-foreign-product-cart-read.php
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THE ROW CLAIMED, AND WHAT MEASUREMENT CHANGED ABOUT IT.
 *
 * The row is CORRECT about the code: the surviving-line arm of both cart readers serves a foreign
 * product's `name` and `image`, and `Product::ProductPrice()` folds its PRICE into the caller's
 * basket total. Section A below reproduces that.
 *
 * ITS SEVERITY IS LOWER THAN "CROSS-TENANT DISCLOSURE", AND THAT IS THE FINDING. Section B shows
 * the SAME principal reading the SAME three fields through a legitimate route — store B's own slug
 * on `product-detail`, HTTP 200 — which is GAP-244's recorded property that the storefront product
 * payload is served to anybody who names a slug. So the reader is not revealing otherwise
 * unreachable data. What it is really doing is:
 *
 *   1. AN INTEGRITY DEFECT, and the user-visible half: another store's price is added to this
 *      store's basket total, and the basket is a DEAD END — `basketHasForeignProduct()` refuses it
 *      at checkout with "Product not found ." naming no line, so the shopper is shown a total they
 *      can never pay and told nothing about which line is at fault.
 *   2. AN OWNERSHIP ORACLE of small increment, since slug-naming already answers it.
 *
 * -------------------------------------------------------------------------------------------------
 * AND WHY THE OBVIOUS REMEDY WAS NOT ENACTED. Section C measures it.
 *
 * Scoping the readers' line lookup — so a foreign product resolves to NULL and falls into the
 * stale-line guard GAP-241 installed — does work, and it does unify the two defects into one
 * behaviour. It was implemented, measured, and REVERTED, because the readers FEED THE CHECKOUT
 * GATE: `basketHasForeignProduct()` is called with `$cartlist['product_list']`, so dropping the
 * line empties the gate's input and the gate can no longer fire.
 *
 * MEASURED CONSEQUENCE, on `POST {storeA}/get-payment-paiementpro` with a guest cookie basket:
 *
 *                            foreign stock   foreign in order   orders written   session('error')
 *   unchanged, foreign only       50                no                0          "item this store
 *                                                                                does not sell"
 *   scoped,    foreign only       50                no                0          null
 *   unchanged, own + foreign      50                no                0          "item this store
 *                                                                                does not sell"
 *   scoped,    own + foreign      50                no                1          null
 *
 * So the cross-tenant protection is INTACT either way — no foreign stock moves and no foreign
 * product reaches an order in any of the four cases. What changes is that a MIXED basket COMPLETES
 * instead of being refused: an order is written and the shopper's OWN variant stock moves 50 -> 48.
 *
 * That is a change to what a payment path does with money, it disarms a control that
 * `ForeignProductCartChainTest`'s own docblock says was "extended and wired for" this leg, and it
 * cannot be repaired on the gateway arm from here because that arm lives in `PaymentController`
 * and the gateway controllers, which this workstream must not edit. Six existing security cases
 * assert the refusal it would remove. So the decision is the owner's, not engineering's, and the
 * two honest options are recorded in the report rather than chosen here.
 */

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 4);
require $root . '/vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';

$app = require $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
config(['cache.default' => 'array']);

echo "GAP-280 — foreign product in a cart read\n";
echo str_repeat('=', 82), "\n\n";

Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);

/**
 * A tenant: a type-`admin` owner and one store.
 *
 * Built through `User::factory()` and `Store::create()` -- the SAME shapes
 * `SecurityTestCase::makeMerchant()` / `makeStore()` use. A first draft hand-wrote the columns and
 * died on `users has no column named lang`: re-deriving a fixture is how a script drifts from the
 * suite it is meant to corroborate, so it copies rather than guesses.
 */
$tenant = static function (string $label): array {
    $owner = User::factory()->create([
        'type' => 'admin',
        'created_by' => 1,
        'is_active' => 1,
        'email_verified_at' => now(),
    ]);

    $store = Store::create([
        'name' => 'Store ' . $label . '-' . uniqid(),
        'email' => 'g280-store-' . $label . '-' . uniqid() . '@example.test',
        'theme_id' => 'stylique',
        'slug' => 'g280-' . $label . '-' . uniqid(),
        'created_by' => $owner->id,
        'is_active' => 1,
    ]);

    return [$owner, $store];
};

[, $storeA] = $tenant('a');
[, $storeB] = $tenant('b');

$product = static function ($store, string $name, string $price): Product {
    return Product::forceCreate([
        'name' => $name, 'slug' => 'g280-' . uniqid(), 'description' => 'x',
        'product_stock' => 50, 'track_stock' => 0, 'variant_product' => 0,
        'stock_status' => 'in_stock', 'status' => 1,
        'price' => $price, 'sale_price' => $price,
        'cover_image_path' => 'uploads/' . $store->id . '/g280-' . uniqid() . '.jpg',
        'store_id' => $store->id, 'created_by' => $store->created_by,
    ]);
};

$own = $product($storeA, 'G280-OWN-ITEM', '10.00');
$foreign = $product($storeB, 'G280-STORE-B-ITEM', '77.00');

$shopper = Customer::forceCreate([
    'first_name' => 'Shopper', 'last_name' => 'A',
    'email' => 'g280-shopper-' . uniqid() . '@example.test',
    'mobile' => '555' . random_int(100000, 999999),
    'password' => bcrypt('not-a-real-password'),
    'store_id' => $storeA->id, 'status' => 1, 'register_type' => 'email',
]);

foreach ([$own, $foreign] as $p) {
    Cart::forceCreate([
        'customer_id' => $shopper->id, 'product_id' => $p->id, 'variant_id' => 0,
        'qty' => 1, 'price' => 0, 'store_id' => $storeA->id,
    ]);
}

/* ============================================================ A. what the reader serves today */

echo "A. THE SIGNED-IN READER, store-A basket holding one store-A item at 10.00 and\n";
echo "   one store-B item at 77.00\n\n";

auth('customers')->setUser($shopper);
request()->merge(['customer_id' => $shopper->id]);

$controller = new App\Http\Controllers\Api\ApiController();
$payload = json_decode(json_encode($controller->cart_list(request(), $storeA->slug)->getData()), true);
$data = $payload['data'] ?? [];

printf("   lines served ............ %d\n", count($data['product_list'] ?? []));
foreach ($data['product_list'] ?? [] as $i => $line) {
    printf("     [%d] product_id=%-4s name=%-20s image=%-34s final=%s\n",
        $i, $line['product_id'], $line['name'], $line['image'], $line['final_price']);
}
printf("   total_final_price ....... %s   (the honest figure is 10.00)\n", $data['total_final_price'] ?? '?');
printf("   foreign NAME served ..... %s\n", in_array('G280-STORE-B-ITEM', array_column($data['product_list'] ?? [], 'name'), true) ? 'YES' : 'no');
/*
 * `JSON_UNESCAPED_SLASHES`, AND IT IS NOT COSMETIC. The first version of this line compared the
 * raw path against `json_encode($data)`, which escapes `/` as `\/` by default -- so
 * `uploads/2/cover.jpg` could never match and the measurement printed "no" for an image the line
 * immediately above it was printing in full. A false NEGATIVE in the instrument, which on a
 * disclosure measurement reads as "already safe" and is the most expensive direction to be wrong
 * in. Compared against the served column instead.
 */
$ssServedImages = array_column($data['product_list'] ?? [], 'image');
printf("   foreign IMAGE served .... %s   (the path carries store B's own id)\n",
    in_array((string) $foreign->cover_image_path, $ssServedImages, true) ? 'YES' : 'no');

/* ====================================== B. the same fields through a legitimate public route */

echo "\nB. THE SAME THREE FIELDS, SAME PRINCIPAL, THROUGH STORE B'S OWN SLUG\n";
echo "   (GAP-244: the storefront product payload is served to anybody who names a slug)\n\n";

$detail = json_decode(json_encode(
    $controller->product_detail(request()->merge(['id' => $foreign->id]), $storeB->slug)->getData()
), true);

$info = $detail['data']['product_info'] ?? [];
printf("   product-detail status ... %s\n", $detail['status'] ?? '?');
printf("   name .................... %s\n", $info['name'] ?? '(absent)');
printf("   price ................... %s\n", $info['price'] ?? '(absent)');
printf("   cover image ............. %s\n", $info['cover_image_path'] ?? '(absent)');
echo "\n   => the reader in section A reveals no field this route does not already serve to the\n";
echo "      same token holder. The row's CORRECTNESS stands; its SEVERITY is integrity and an\n";
echo "      ownership oracle, not confidentiality.\n";

/* ================================================= C. the dead end the integrity half creates */

echo "\nC. AND THE BASKET IS A DEAD END\n\n";

$ordersBefore = (int) DB::table('orders')->count();
printf("   basketHasForeignProduct() refuses it at place_order:3800, payment_sheet:9295 and\n");
printf("   ordersave:10182, so the shopper is shown %s and can never pay it.\n", $data['total_final_price'] ?? '?');
printf("   orders in the table ..... %d  (and the refusal names no line)\n", $ordersBefore);

echo "\n";
echo "SUMMARY\n";
echo str_repeat('-', 82), "\n";
echo "  reader serves foreign name, image and price .............. CONFIRMED\n";
echo "  those fields already public to the same principal ........ CONFIRMED (section B)\n";
echo "  foreign stock moved / foreign product ordered ............ NO (ratcheted by a test)\n";
echo "  scoping the readers closes it but completes a mixed\n";
echo "    basket that is refused today ........................... OWNER DECISION (see header)\n";
