<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * F95 — THE ANONYMOUS ORDER INTAKE HAS NO GUARD AGAINST WRITING THE SAME BASKET TWICE.
 * =====================================================================================
 *
 * `POST {storeSlug?}/whatsapp` and `POST {storeSlug?}/telegram` both reach
 * `PaymentController::whatsapp`, which writes an order and decrements stock. The ledger's F95
 * records what reading all 903 lines of it established: **no replay guard of any kind** — no
 * `claimGatewayReturn`, no `Cache::lock`, no `Cache::add`, no lookup of an existing order. The
 * route immediately above it in `routes/customer.php`, which writes nothing, is throttled.
 *
 * WHAT IS AND IS NOT BEING FIXED HERE, because F95 names two different things.
 *
 *   - **The RATE is not.** Choosing a throttle needs real traffic data this environment does not
 *     have, and a limit set too low answers 429 on a legitimate checkout. That stays the owner's,
 *     and the one-line change is already written down in the ledger so it needs no re-derivation.
 *   - **The RACE is.** Two concurrent posts both read a non-empty cart before either delete runs,
 *     giving two orders and two stock decrements for one basket. That is not a shopper ordering
 *     twice; it is a lost update, and closing it refuses nothing anybody does deliberately. The
 *     sequential case is already handled incidentally — the cart rows are gone, so the second
 *     attempt answers "Cart is empty."
 *
 * WHY THE KEY IS THE BASKET AND NOT SOMETHING BROADER. `Cart.php:26` records that **a logged-in
 * customer's basket rows carry `cookie_session_id` NULL** -- their basket is keyed by
 * `customer_id` -- while a guest's are keyed by the cookie. So `customer:<id>` / `guest:<cookie>`
 * is not a superset of the raced resource chosen for safety: in each case it names exactly the
 * basket the cart read will find. That matters for what the guard COSTS as well as what it buys.
 * The lock spans a body that can sit on SMTP (F96's whole premise), and a key broader than the
 * basket would refuse a legitimate second checkout of a DIFFERENT basket for as long as that took.
 * There is no such case: a logged-in customer has one basket.
 *
 * WHY A LOCK AND NOT A NONCE. F95 records that a nonce-based replay guard **cannot** exist on this
 * path: WhatsApp and Telegram intake must accept a post with no prior session, which is why
 * `$requests_data` falls back to `$request->all()`. A lock is not a nonce. It is a mutex over the
 * read-then-write, keyed on the principal's own basket, and it needs nothing the caller has to
 * have brought with them.
 *
 * THE PRECEDENT IS THIS CODEBASE'S OWN. `PaymentController::getPaymeraStatus` takes
 * `Cache::lock('paymera_lock_' . $orderId)` around its writer, and every gateway return takes a
 * `claimGatewayReturn()` claim. This path was the one with neither.
 *
 * WHAT THESE TESTS CAN AND CANNOT DO. `Cache::lock` on the `array` driver is **process-local**, so
 * no Feature test can stage two genuinely concurrent requests. What a test CAN do is hold the lock
 * and then drive the request — which measures the thing that matters: that the handler asks for
 * the lock, and refuses without writing when it cannot have it.
 */
class WhatsappOrderConcurrencyTest extends SecurityTestCase
{
    use RefreshDatabase;

    public function test_the_handler_refuses_to_write_while_the_basket_is_already_being_processed(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product);

        $opening = (int) DB::table('products')->where('id', $product->id)->value('product_stock');

        /*
         * Hold the lock the handler must ask for, as a concurrent request would. The key is
         * derived the same way the handler derives it: the store and the principal's basket.
         */
        $held = Cache::lock($this->lockKeyFor($store, $sid), 30);
        $this->assertTrue($held->get(), 'the premise: this test really holds the lock');

        try {
            $response = $this->withCookie('cart', $sid)
                ->post('/' . $store->slug . '/whatsapp', $this->postBody($store));

            $response->assertOk();

            $this->assertSame(
                0,
                (int) DB::table('orders')->where('store_id', $store->id)->count(),
                'a second request for the same basket wrote a SECOND order. Two concurrent posts'
                . ' both read a non-empty cart before either delete runs, so one basket becomes'
                . ' two orders and two stock decrements.'
            );
            $this->assertSame(
                $opening,
                (int) DB::table('products')->where('id', $product->id)->value('product_stock'),
                'and it consumed stock for the order it could not write'
            );
        } finally {
            $held->release();
        }
    }

    public function test_an_ordinary_request_is_not_refused_by_the_guard(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product);

        /*
         * THE POSITIVE CONTROL, and it is the half that makes the case above mean anything: a
         * guard that refused everything would satisfy the first test perfectly.
         */
        $response = $this->withCookie('cart', $sid)
            ->post('/' . $store->slug . '/whatsapp', $this->postBody($store));

        $response->assertOk();

        $this->assertSame(
            1,
            (int) DB::table('orders')->where('store_id', $store->id)->count(),
            'the guard refused an ordinary checkout with nothing else holding the lock'
        );
    }

    public function test_the_lock_is_released_so_a_later_checkout_is_not_blocked_forever(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);

        $first = $this->guestCart($store, $product);
        $this->withCookie('cart', $first)->post('/' . $store->slug . '/whatsapp', $this->postBody($store));

        /* The SAME basket identity, a fresh cart: if the lock were never released this fails. */
        Cart::forceCreate([
            'product_id' => $product->id,
            'variant_id' => 0,
            'qty' => 1,
            'price' => 100,
            'store_id' => $store->id,
            'cookie_session_id' => $first,
        ]);

        $response = $this->withCookie('cart', $first)
            ->post('/' . $store->slug . '/whatsapp', $this->postBody($store));

        $response->assertOk();

        $this->assertSame(
            2,
            (int) DB::table('orders')->where('store_id', $store->id)->count(),
            'the lock was not released, so the same shopper can never check out again'
        );
    }

    /**
     * THE KEY IS THE AUTHENTICATED PRINCIPAL'S, NEVER THE BODY'S.
     *
     * A lock key taken from request input would let one caller block another's checkout — the
     * exact inverse of what this guard is for, and the single most productive defect shape in
     * this codebase ("derive the target from the authenticated principal; never from request
     * input"). The handler reads `auth('customers')` and the cart cookie; a posted `customer_id`
     * reaches `$requests_data` and must not reach the key.
     *
     * Driven: a guest posts a victim's customer id while the victim's lock is held. If the key
     * came from the body the guest would be refused; it must not be.
     *
     * TWO INDEPENDENT MECHANISMS CARRY THIS, AND THE BITE IS WHAT ESTABLISHED THE SECOND. A
     * one-line bite that rebuilt the key from `$request->customer_id` did NOT bite: a probe on
     * the lock line printed `cust=''` for a request that posted `4242`. The handler's own
     * `$request->merge(['customer_id' => $customer_id])` at `PaymentController:3753-3754` has
     * already forced the field to the AUTHENTICATED value -- empty for a guest -- before the key
     * is built, so even a key taken straight from the body would not have carried the posted id.
     * Expressing the defect at all needed the posted value captured ahead of that merge.
     *
     * So this case pins the composition, not one line: the body reaches neither `$requests_data`
     * nor the key. Both halves are bitten (`no-intake-lock`, `intake-key-from-the-body`), and the
     * second bite is only faithful because it pre-captures -- which is recorded here rather than
     * left for the next reader to rediscover.
     *
     * AND IT HAS TWO PHASES, BECAUSE ONE WAS INDISTINGUISHABLE FROM THE POSITIVE CONTROL. The
     * independent review pointed out that phase one alone passes against FULLY REVERTED code: with
     * no guard at all the order is written and "the guest was not refused" is satisfied for the
     * wrong reason. Phase two holds the guest's OWN key and shows the same request IS refused, so
     * the pair says what was meant -- the guard is active for this request, and the victim's key is
     * not the one it takes.
     */
    public function test_a_posted_customer_id_does_not_take_another_principals_lock(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product);

        /* Somebody else's lock, held. */
        $victimsLock = Cache::lock('ss_order_intake:' . (int) $store->id . ':customer:4242', 30);
        $this->assertTrue($victimsLock->get(), 'the premise: the victim\'s lock is held');

        try {
            /*
             * `array_merge`, NOT `+`. PHP's union operator keeps the LEFT operand's value for a
             * duplicate key, and `postBody()` already carries `'customer_id' => ''` -- so the
             * first version of this line posted an empty id and 4242 never reached the request.
             * The test passed, and it passed for no reason at all. The bite found it.
             */
            $response = $this->withCookie('cart', $sid)
                ->post('/' . $store->slug . '/whatsapp', array_merge($this->postBody($store), ['customer_id' => 4242]));

            $response->assertOk();

            $this->assertSame(
                1,
                (int) DB::table('orders')->where('store_id', $store->id)->count(),
                'a guest was refused because they posted somebody else\'s customer id — the lock'
                . ' key came from the body, so one caller can block another\'s checkout'
            );
        } finally {
            $victimsLock->release();
        }

        /*
         * PHASE TWO: the same request, the same posted id, but the guest's OWN key held. Without
         * this half the case above is the positive control under another name -- reverted code,
         * which takes no lock at all, satisfies it.
         */
        $second = $this->guestCart($store, $this->productIn($store));
        $ownLock = Cache::lock($this->lockKeyFor($store, $second), 30);
        $this->assertTrue($ownLock->get(), 'the premise: the guest\'s own lock is held');

        try {
            $response = $this->withCookie('cart', $second)
                ->post('/' . $store->slug . '/whatsapp', array_merge($this->postBody($store), ['customer_id' => 4242]));

            $response->assertOk();

            $this->assertSame(
                1,
                (int) DB::table('orders')->where('store_id', $store->id)->count(),
                'the guard is not active for this request at all, so phase one proved nothing:'
                . ' posting a customer id let the write through while the guest\'s own basket was'
                . ' already being written'
            );
        } finally {
            $ownLock->release();
        }
    }

    /**
     * THE REFUSAL IS BILINGUAL, and this is F170's shape, not a nicety.
     *
     * Arabic is the platform's PRIMARY language. A new `__()` literal with no `ar.json` entry
     * falls back to the key, so the Arabic storefront renders an English sentence -- and it
     * renders correctly in English **by accident**, which is why the defect survives review. F86
     * and F170 are both this. The guard above added one new customer-facing string, so the string
     * is asserted in the language most of this product's shoppers read it in.
     *
     * Driven rather than a file check: this asserts what the shopper is actually handed.
     *
     * THE LOCALE IS SET THE WAY THE PRODUCT SETS IT, AND IT TOOK TWO MEASUREMENTS TO GET RIGHT --
     * worth recording, because any later storefront test asserting Arabic output hits the same
     * wall. `$this->app->setLocale('ar')` does NOT survive the request: `SetLocate` is appended to
     * the `web` group (`bootstrap/app.php:36`) and calls `App::setLocale()` itself. Driving
     * `Session::get('LANGUAGE')`, which is what SetLocate reads, did not work either -- this route
     * sits inside `Route::middleware([ActiveTheme::class, 'themelanguage'])`
     * (`routes/customer.php:49`), and `ThemeLanguage` runs AFTER SetLocate and reads
     * **`Cookie::get('LANGUAGE')`**, falling back to the platform admin's own language. On the
     * storefront the cookie is therefore the only lever, and the first two versions of this test
     * failed on the locale rather than on the translation it names.
     */
    public function test_the_refusal_reaches_an_arabic_shopper_in_arabic(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product);

        $held = Cache::lock($this->lockKeyFor($store, $sid), 30);
        $this->assertTrue($held->get(), 'the premise: this test really holds the lock');

        try {
            $response = $this->withCookie('LANGUAGE', 'ar')
                ->withCookie('cart', $sid)
                ->post('/' . $store->slug . '/whatsapp', $this->postBody($store));

            $response->assertOk();

            $message = (string) ($response->json('data.message') ?? '');

            $this->assertNotSame(
                'This order is already being processed. Please wait a moment before trying again.',
                $message,
                'an Arabic shopper was refused in English -- the new key is missing from'
                . ' resources/lang/ar.json, so __() fell back to the key itself'
            );
            $this->assertSame(
                'هذا الطلب قيد المعالجة بالفعل. يرجى الانتظار لحظة قبل المحاولة مرة أخرى.',
                $message,
                'the Arabic refusal is not the one shipped in resources/lang/ar.json'
            );
        } finally {
            $held->release();
        }
    }

    /**
     * THE TELEGRAM ROUTE IS GUARDED TOO, AND IT IS ASSERTED RATHER THAN ARGUED.
     *
     * `POST {storeSlug?}/telegram` (`routes/customer.php:184`) and `POST {storeSlug?}/whatsapp`
     * (`:185`) are two route declarations over ONE handler, so structurally there is one guard and
     * this case cannot fail while the one above passes. It is here because that reasoning is
     * exactly what F46 and F50 were: fixing the file somebody had read left the control reachable
     * by choosing another entry point, and in both cases the argument that it could not happen was
     * the reason nobody checked. Driving the second route costs one test and removes the argument.
     */
    public function test_the_telegram_route_is_behind_the_same_guard(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product);

        $held = Cache::lock($this->lockKeyFor($store, $sid), 30);
        $this->assertTrue($held->get(), 'the premise: this test really holds the lock');

        try {
            $response = $this->withCookie('cart', $sid)
                ->post('/' . $store->slug . '/telegram', $this->postBody($store));

            $response->assertOk();

            $this->assertSame(
                0,
                (int) DB::table('orders')->where('store_id', $store->id)->count(),
                'the telegram intake wrote an order while the same basket was already being'
                . ' written -- the guard covers one of the two routes that reach this handler'
            );
        } finally {
            $held->release();
        }
    }

    // ---------------------------------------------------------------- fixture

    /** The key the handler builds, reproduced here so the test and the code can be compared. */
    private function lockKeyFor($store, string $sid): string
    {
        return 'ss_order_intake:' . (int) $store->id . ':guest:' . $sid;
    }

    private function postBody($store): array
    {
        return [
            'slug' => $store->slug,
            'customer_id' => '',
            'payment_type' => 'whatsapp',
            'shipping_final_price' => 0,
            'tax_price' => 0,
            'cartlist_final_price' => 100.00,
            'billing_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
                'billing_user_telephone' => '123', 'billing_address' => 'x',
                'billing_postecode' => '1', 'billing_country' => '101',
                'billing_state' => '1', 'billing_city' => '1', 'billing_company_name' => '',
                'delivery_address' => 'x', 'delivery_postcode' => '1',
                'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
            ],
        ];
    }

    private function guestCart($store, Product $product): string
    {
        $sid = 'f95-cookie-' . uniqid();

        Cart::forceCreate([
            'product_id' => $product->id,
            'variant_id' => 0,
            'qty' => 1,
            'price' => 100,
            'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        return $sid;
    }

    private function productIn($store): Product
    {
        return Product::forceCreate([
            'name' => 'f95-' . uniqid(), 'slug' => 'f95-' . uniqid(),
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 100.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Store} */
    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'f95-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        foreach ([
            'stock_management' => 'on',
            'out_of_stock_threshold' => '0',
            'notification' => '[]',
        ] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach ([
            ['countries', ['id' => 101, 'name' => 'Syria']],
            ['states', ['id' => 1, 'name' => 'Damascus', 'country_id' => 101]],
            ['cities', ['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1]],
        ] as [$table, $row]) {
            DB::table($table)->insertOrIgnore($row + ['created_at' => now(), 'updated_at' => now()]);
        }

        return [$merchant, $store];
    }
}
