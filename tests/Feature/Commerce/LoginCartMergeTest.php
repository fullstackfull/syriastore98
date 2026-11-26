<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P4-F011 -- SIGNING IN WITH A BASKET YOU ALREADY HAD SPLIT ONE PRODUCT INTO TWO LINES.
 * =============================================================================================
 *
 * A guest's basket rows are keyed on `carts.cookie_session_id`; a signed-in customer's are keyed
 * on `carts.customer_id` and carry `cookie_session_id` NULL. `CustomerLoginController::login()`
 * moves the first onto the second at sign-in, one row at a time:
 *
 *     foreach ($cartItems as $cartItem) {
 *         $cartItem->customer_id = auth('customers')->user()->id;
 *         $cartItem->cookie_session_id = null;
 *         $cartItem->save();
 *     }
 *
 * There is no lookup for a line the account ALREADY holds, so when the guest basket and the
 * account basket both contain the same product and variant the account ends up with TWO rows for
 * one product. Nothing in the application writes a second row for one customer+product+variant any
 * other way -- both add-to-cart writers find the existing line by that tuple and increment it --
 * so this merge was the only producer of the duplicate, and that is why the rest of the cart could
 * assume the tuple is unique.
 *
 * WHY IT IS WORSE THAN A COSMETIC DOUBLE ROW. `CartController::change_cart` resolves the row the
 * shopper pressed by `cart_id` (:192) and then re-identifies it by TUPLE inside
 * `ApiController::cart_qty` (:1918-1923, with no `orderBy`), so with two rows for one product a
 * `+`, `-` or Remove press on the second line edits or deletes the FIRST. `cart_remove` deletes by
 * id and is unaffected. The basket total stays arithmetically correct (every line is summed), so
 * nothing warns the shopper.
 *
 * HOW A SHOPPER REACHES IT, without doing anything unusual: add a product while signed in, sign
 * out (or open a second browser, device or private window), add the same product as a guest, sign
 * in again. The guest add keys on the basket cookie and the account rows key on `customer_id`, so
 * the two baskets never meet until the merge runs.
 *
 * WHAT THE THREE EXISTING MERGE TESTS COULD NOT SEE. `NullCookieCartTest`'s login cases (:241,
 * :254, :269) each build a guest basket and a DIFFERENT customer's basket; none gives the
 * logging-in customer a pre-existing row for the same product, so the duplicate state is never
 * created. They remain the controls for this fix: with no existing line the guest row must still
 * simply move, and no other customer's basket may be touched.
 *
 * ASSERTIONS READ THE RAW TABLE. `Cart` is in `config/tenancy.php`'s `scoped_models`, so a plain
 * `Cart::where(...)` answers for whichever tenant happens to resolve rather than for the table,
 * and a count assertion can pass whether or not the fix works.
 */
class LoginCartMergeTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** 40 alphanumeric characters: the shape `session()->getId()` produces and every guest row carries. */
    private const GUEST_COOKIE = 'loginmergeguestcookie000000000000000000a';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /**
     * One store, one product, and two customers of it: `b` is the one who signs in, `a` is the
     * bystander whose basket must not move.
     *
     * @return array{store: \App\Models\Store, p: Product, p2: Product, a: Customer, b: Customer}
     */
    private function world(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $merchant->plan_id = DB::table('plans')->insertGetId([
            'name' => 'lcm-plan-' . $this->uniqueFixtureRef(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->save();
        Cache::forget('admin_details');

        foreach (['stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]'] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value, 'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $product = fn () => Product::forceCreate([
            'name' => 'lcm-' . $this->uniqueFixtureRef(), 'slug' => 'lcm-' . $this->uniqueFixtureRef(),
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10.00, 'sale_price' => 10.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        $customer = fn (string $label) => Customer::forceCreate([
            'first_name' => 'Lcm', 'last_name' => $label,
            'email' => 'lcm-' . $label . '-' . $this->uniqueFixtureRef() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('secret123'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        $p = $product();
        $p2 = $product();
        $a = $customer('a');
        $b = $customer('b');

        return compact('store', 'p', 'p2', 'a', 'b');
    }

    private function customerRow(Customer $customer, Product $product, int $qty, int $variant = 0): Cart
    {
        return Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => $variant,
            'qty' => $qty, 'price' => 20.00, 'store_id' => $customer->store_id,
        ]);
    }

    private function guestRow($store, Product $product, int $qty = 1, int $variant = 0): Cart
    {
        return Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => $variant,
            'qty' => $qty, 'price' => 10.00, 'store_id' => $store->id,
            'cookie_session_id' => self::GUEST_COOKIE,
        ]);
    }

    private function logIn($store, Customer $customer, bool $withCookie = true): \Illuminate\Testing\TestResponse
    {
        $request = $withCookie ? $this->withCookie('cart', self::GUEST_COOKIE) : $this;

        return $request->post('/' . $store->slug . '/login', [
            'email' => $customer->email,
            'password' => 'secret123',
        ]);
    }

    /** The account's lines for one product+variant, from the raw table. */
    private static function lines(Customer $customer, Product $product, int $variant = 0): \Illuminate\Support\Collection
    {
        return DB::table('carts')
            ->where('customer_id', $customer->id)
            ->where('product_id', $product->id)
            ->where('variant_id', $variant)
            ->where('store_id', $customer->store_id)
            ->orderBy('id')
            ->get();
    }

    private static function rowCount(Customer $customer): int
    {
        return (int) DB::table('carts')->where('customer_id', $customer->id)->count();
    }

    /**
     * A SHOPPER AS PRODUCTION HAS ONE. `actingAs($customer, 'customers')` also calls
     * `shouldUse('customers')`, which makes the customer the DEFAULT guard's principal -- so
     * `auth()->user()`, which `StoreScope::resolveTenant()` and `getCurrentStore()` read, starts
     * answering with a Customer and a tenant resolves for a shopper. The storefront then runs
     * under a TIGHTER scope than production, where the default guard is `web` with nobody in it,
     * and a global scope can stand in for a predicate the handler is supposed to carry itself.
     * Same shape as `PlatformUploaderDoorsTest::asAShopper()`.
     */
    private function asAShopper(Customer $customer): self
    {
        $this->actingAs($customer, 'customers');
        $this->app['auth']->shouldUse('web');

        return $this;
    }

    private function assertLoginSucceeded(\Illuminate\Testing\TestResponse $response): void
    {
        $this->assertSame(302, $response->getStatusCode(),
            'Premise: the login did not redirect (HTTP ' . $response->getStatusCode() . '), so no merge ran.');
        $this->assertStringContainsString('/home', (string) $response->headers->get('Location'),
            'Premise: the login failed, so the count assertions below would pass vacuously.');
    }

    /**
     * THE DEFECT ITSELF: the customer already holds the product the guest basket holds.
     */
    public function test_the_login_merge_folds_a_guest_line_into_the_one_the_customer_already_has(): void
    {
        $w = $this->world();
        $mine = $this->customerRow($w['b'], $w['p'], 2);
        $this->guestRow($w['store'], $w['p'], 1);

        $this->assertSame(1, self::lines($w['b'], $w['p'])->count(), 'Premise: the account did not start with exactly one line.');

        $response = $this->logIn($w['store'], $w['b']);
        $this->assertLoginSucceeded($response);

        $lines = self::lines($w['b'], $w['p']);

        $this->assertCount(1, $lines,
            'The login merge left ' . $lines->count() . ' lines for one product: the guest row was re-labelled instead of folded into the line the account already held, and a +/- press on the second one then edits the first.');
        $this->assertSame($mine->id, (int) $lines->first()->id,
            'The line that survived is not the account\'s own row, so the merge replaced it rather than folding into it.');
        $this->assertSame(3, (int) $lines->first()->qty,
            'The guest quantity was dropped instead of added to the line the account already held.');
        $this->assertNull($lines->first()->cookie_session_id,
            'The surviving line still carries a guest cookie, so a guest holding that cookie can still reach the account\'s basket.');
        $this->assertSame(0, (int) DB::table('carts')->where('cookie_session_id', self::GUEST_COOKIE)->count(),
            'The guest row was left behind under its cookie as well as folded in, so the line is counted twice across the two baskets.');
    }

    /** The same, per VARIANT: two lines of one product that differ only by variant are different lines.      *
     * THIS IS A CONTROL, NOT A DEFECT TEST, and the independent review was right to say the
     * docblock read like one. On the pre-fix code the variant-7 guest row is simply re-labelled,
     * so the row count is 2 and both quantity assertions hold -- it does NOT fail on a revert.
     * What it catches is an over-broad fold that matched per PRODUCT and collapsed two genuinely
     * different variants into one line. Labelled explicitly because this project's own rule is
     * that a test presented as biting must bite.
     */
    public function test_the_fold_is_per_variant_not_per_product(): void
    {
        $w = $this->world();
        $this->customerRow($w['b'], $w['p'], 2, 0);
        $this->guestRow($w['store'], $w['p'], 1, 7);

        $response = $this->logIn($w['store'], $w['b']);
        $this->assertLoginSucceeded($response);

        $this->assertSame(2, self::rowCount($w['b']),
            'A guest line for a DIFFERENT variant of the same product was folded into the variant-0 line.');
        $this->assertSame(2, (int) self::lines($w['b'], $w['p'], 0)->first()->qty, 'The untouched variant-0 line changed quantity.');
        $this->assertSame(1, (int) self::lines($w['b'], $w['p'], 7)->first()->qty, 'The variant-7 guest line did not arrive intact.');
    }

    /**
     * CONTROL -- the case the three existing merge tests cover, restated here so a fix that folds
     * everything into one row cannot pass this file: a guest line for a product the account does
     * NOT hold still becomes its own second line, with its own quantity.
     */
    public function test_a_guest_line_for_a_different_product_still_becomes_its_own_line(): void
    {
        $w = $this->world();
        $this->customerRow($w['b'], $w['p'], 2);
        $this->guestRow($w['store'], $w['p2'], 4);

        $response = $this->logIn($w['store'], $w['b']);
        $this->assertLoginSucceeded($response);

        $this->assertSame(2, self::rowCount($w['b']), 'The guest\'s line for a product the account did not hold was lost in the merge.');
        $this->assertSame(2, (int) self::lines($w['b'], $w['p'])->first()->qty, 'The account\'s own line changed quantity.');
        $this->assertSame(4, (int) self::lines($w['b'], $w['p2'])->first()->qty, 'The moved line did not keep its quantity.');
        $this->assertSame(0, (int) DB::table('carts')->where('cookie_session_id', self::GUEST_COOKIE)->count(), 'A merged row still carries the guest cookie.');
    }

    /**
     * THE FOLD IS BOUND TO THE STORE IN THE ROUTE, and that is not theoretical here: P4-F003
     * records `CartController::product_cartlist` writing a `carts` row into store A under a
     * store-B customer's id, so one customer CAN hold a line whose `store_id` is not their own.
     * A guest line in this store must not be folded into a line that lives in another one -- the
     * two baskets are different baskets, and the merged row would then be invisible here while
     * changing a total somewhere else.
     */
    public function test_a_line_the_customer_holds_in_another_store_is_not_folded_into(): void
    {
        $w = $this->world();
        [, $elsewhere] = $this->makeTenant();

        $strayRow = Cart::forceCreate([
            'customer_id' => $w['b']->id, 'product_id' => $w['p']->id, 'variant_id' => 0,
            'qty' => 2, 'price' => 20.00, 'store_id' => $elsewhere->id,
        ]);
        $this->guestRow($w['store'], $w['p'], 1);

        $response = $this->logIn($w['store'], $w['b']);
        $this->assertLoginSucceeded($response);

        $this->assertSame(2, (int) DB::table('carts')->where('id', $strayRow->id)->value('qty'),
            'The guest line was folded into a line the customer holds in a DIFFERENT store.');
        $this->assertSame(1, (int) DB::table('carts')
            ->where('customer_id', $w['b']->id)->where('store_id', $w['store']->id)->count(),
            'The guest line did not arrive as this store\'s own line.');
        $this->assertSame(1, (int) DB::table('carts')
            ->where('customer_id', $w['b']->id)->where('store_id', $w['store']->id)->value('qty'),
            'The moved line did not keep its quantity.');
    }

    /** CONTROL -- nobody else's basket moves, and nothing is folded into a bystander's line. */
    public function test_another_customers_identical_line_is_untouched_by_the_merge(): void
    {
        $w = $this->world();
        $theirs = $this->customerRow($w['a'], $w['p'], 5);
        $this->customerRow($w['b'], $w['p'], 2);
        $this->guestRow($w['store'], $w['p'], 1);

        $response = $this->logIn($w['store'], $w['b']);
        $this->assertLoginSucceeded($response);

        $this->assertSame(1, self::rowCount($w['a']), 'The merge reached another customer\'s basket.');
        $this->assertSame(5, (int) DB::table('carts')->where('id', $theirs->id)->value('qty'),
            'The guest quantity was folded into ANOTHER customer\'s line for the same product.');
        $this->assertSame(3, (int) self::lines($w['b'], $w['p'])->first()->qty, 'Premise: the fold into the logging-in customer\'s own line did not happen.');
    }

    /**
     * THE SHARPENING THE FINDING ASKS FOR, as behaviour rather than as row counts: with one line
     * per product the row the shopper presses is the row that changes.
     *
     * `ApiController::cart_qty` re-identifies the pressed row by tuple, so while a duplicate
     * existed a press on the second line moved the first. This does not fix that re-resolution --
     * it removes the only state in which it can disagree with the `cart_id` the shopper pressed.
     */
    public function test_after_the_merge_a_quantity_press_moves_the_line_it_names(): void
    {
        $w = $this->world();
        $this->customerRow($w['b'], $w['p'], 2);
        $this->guestRow($w['store'], $w['p'], 1);

        $this->assertLoginSucceeded($this->logIn($w['store'], $w['b']));

        $lines = self::lines($w['b'], $w['p']);
        $this->assertCount(1, $lines, 'Premise: the merge still left two lines, so this test is about the duplicate rather than the press.');
        $pressed = (int) $lines->first()->id;

        $response = $this->asAShopper($w['b'])
            ->post('/' . $w['store']->slug . '/change-cart', ['cart_id' => $pressed, 'quantity_type' => 'increase']);

        $response->assertOk();
        $this->assertSame(1, (int) $response->json('status'),
            'Premise: the quantity press was refused (' . substr((string) $response->getContent(), 0, 200) . ').');
        $this->assertSame(4, (int) DB::table('carts')->where('id', $pressed)->value('qty'),
            'The row the shopper pressed is not the row whose quantity changed.');
        $this->assertSame(1, self::rowCount($w['b']), 'The press created or removed a line.');
    }
}
