<?php

namespace Tests\Feature\Journeys;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GATE P01-G1 / GAP-020 — THE END-TO-END MERCHANT JOURNEY, AS ONE EXERCISE.
 * =================================================================================================
 *
 * `docs/development/phase-1/14-PHASE-01-ACCEPTANCE-CRITERIA.md` §G step 1 asks for:
 *
 *     "End-to-end merchant journey: create product with variants -> receive order -> fulfil ->
 *      return"
 *
 * run "as one exercise, not as a sum of task tests". The register's words for `GAP-020` were "No
 * end-to-end merchant journey exercise exists", and the traceability row recorded the measurement
 * behind that: `find tests -iname '*journey*'` returned nothing but a Flutter contract file.
 *
 * `test_the_merchant_journey_runs_end_to_end_as_one_exercise` IS THE DELIVERABLE: one method, one
 * process, seven legs in order, each driven over HTTP against the routes a merchant's browser and
 * a shopper's browser really use. Nothing is inserted between legs. The product the shopper buys
 * is the product the merchant created through `POST /product` two legs earlier, variants and all;
 * the order the merchant fulfils is the one the storefront checkout wrote; the return the merchant
 * accepts is the one the shopper requested.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY REGISTRATION IS LEG 0 ALTHOUGH §G STEP 1 DOES NOT NAME IT
 * -------------------------------------------------------------------------------------------------
 * A merchant journey that starts from a hand-built `User` row skips the one step that decides
 * whether any of the rest is reachable: `RegisteredUserController::store()` is what creates the
 * store, provisions the store wallet, writes `users.current_store` and attaches the role. Every
 * later leg depends on all four, and `HomeController::index()` SIGNS A STORELESS MERCHANT OUT
 * rather than showing them a dashboard — so a journey built on a merchant with no store would die
 * at the first screen and never reach the legs §G asks about. Driving the real registration is
 * what makes the rest of the exercise an exercise rather than a fixture.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THIS FILE DOES NOT CLAIM
 * -------------------------------------------------------------------------------------------------
 *  - NO MODULE CODE IS EXERCISED. No WorkDo module loads in a test run unless the test inserts an
 *    `add_on_managers` row, so this journey is the HOST application's merchant path only.
 *  - THE COD WALLET CREDIT IS NOT DRIVEN THROUGH A COLLECTION EVENT. The owner's 2026-10-24
 *    decision withdrew the Delivered-based settlement trigger and requires a verified cash
 *    collection event; `cod_collection_events` is still a GATE F draft
 *    (`database/migrations/_gate_f_pending/0009_draft_...`), so no such event can exist. The
 *    journey therefore settles the order the way the product leaves available today — a human
 *    asserting payment through `order_payment_status` — and
 *    `test_control_cod_is_not_settled_by_delivery_alone` pins that delivery alone does NOT do it.
 *  - NO ONLINE GATEWAY. That is `P01-G2`'s blocked leg; see `CustomerJourneyTest`.
 *
 * The four ways a journey passes for the wrong reason here, and the answer to each, are recorded on
 * `CustomerJourneyTest`. The same disciplines apply: the product-create endpoint answers a REFUSAL
 * with HTTP 200 and `{flag: 'error'}`, so the flag is read; every state assertion goes through
 * `DB::table()`; and each leg records itself in `$this->trail` so a red run says where it died.
 */
final class MerchantJourneyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'journey-password';
    private const VARIANTS = ['Red', 'Blue'];
    private const VARIANT_STOCK = 12;
    private const VARIANT_PRICE = 75.00;
    private const BASKET_UNITS = 2;

    /** A 2x2 PNG — real bytes, because `PlatformUploadPolicy` looks at the file and not the name. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFklEQVQImWM8ISfHwMDAxMDAwMDAAAANBAEIG3S2BgAAAABJRU5ErkJggg==';

    private array $trail = [];

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        /* `Utility::upload_file()` writes through the `theme` disk, whose root is `base_path('')`.
           Faking it keeps a product image out of the repository. */
        Storage::fake('theme');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    // =============================================================================================
    //  THE JOURNEY
    // =============================================================================================

    /**
     * ONE EXERCISE: register -> dashboard -> create a product WITH VARIANTS -> a shopper's order
     * arrives -> fulfil it through every status -> see the money -> handle the return.
     */
    public function test_the_merchant_journey_runs_end_to_end_as_one_exercise(): void
    {
        $this->platformPrerequisites();

        // ------------------------------------------------- LEG 0 — REGISTER, AND BE PROVISIONED
        $email = 'journey-merchant-' . uniqid() . '@example.test';
        $storeName = 'Journey Store ' . $this->uniqueFixtureRef();

        $registered = $this->post('/register', [
            'name' => 'Journey Merchant',
            'email' => $email,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'store_name' => $storeName,
        ]);

        $this->leg('register: POST /register', $registered->getStatusCode(), 302);
        $this->assertAuthenticated();

        $merchant = User::where('email', $email)->first();
        $this->assertNotNull($merchant, $this->why('registration created no user row'));
        $this->assertSame('admin', (string) $merchant->type, $this->why(
            'the registered merchant is not type `admin`, so `User::creatorId()` does not make '
            . 'them the root of their own tenant'
        ));
        $this->trail[] = 'register: users row #' . $merchant->id;

        $store = Store::withoutGlobalScopes()->where('created_by', $merchant->id)->first();
        $this->assertNotNull($store, $this->why('registration provisioned no store'));
        $this->assertSame((int) $store->id, (int) $merchant->fresh()->current_store, $this->why(
            'users.current_store does not point at the store registration just created, so '
            . '`getCurrentStore()` resolves the wrong tenant for every later leg'
        ));
        $this->trail[] = 'register: stores row #' . $store->id . ' /' . $store->slug;

        $this->assertSame(
            1,
            (int) DB::table('store_wallets')->where('store_id', $store->id)->count(),
            $this->why('registration provisioned no store wallet — `P4-F032`: without one every '
                . 'order this merchant takes loses its credit permanently')
        );
        $this->assertTrue($merchant->fresh()->hasRole('admin'), $this->why(
            'the registered merchant holds no role, so they can do nothing in their own panel'
        ));
        $this->trail[] = 'register: store_wallets + admin role present';

        /*
         * THE PERMISSIONS THE SEEDER WOULD HAVE CARRIED. `RefreshDatabase` runs migrations and not
         * seeders, so the global `admin` role this merchant now holds exists with NO permissions.
         * That is a fixture gap, not product behaviour — `PermissionTableSeeder` populates it — and
         * it is filled HERE, after registration, through the merchant's own role, rather than by
         * creating a second role the product would never give them.
         */
        $merchant = $this->grantGlobalAdminPermissions($merchant, [
            'Create Product', 'Manage Products', 'Edit Products', 'Show Product',
            'Manage Order', 'Show Order', 'Edit Order',
            'Manage Order Refund Request', 'View Finance Dashboard',
        ]);

        // ---------------------------------------------------- LEG 1 — THE PANEL IS REACHABLE
        $dashboard = $this->onTheAdminSurface()->actingAs($merchant)->get('/dashboard');
        $this->leg('panel: GET /dashboard', $dashboard->getStatusCode(), 200);

        // --------------------------------------- LEG 2 — CREATE A PRODUCT **WITH VARIANTS**
        $categoryId = $this->aCategory($store);
        [$attributeId] = $this->anAttributeWithTerms($store, 'Colour', self::VARIANTS);

        $created = $this->onTheAdminSurface()->actingAs($merchant)
            ->post('/product', $this->variantProductPayload($categoryId, $attributeId));

        $this->leg('product: POST /product', $created->getStatusCode(), 200);
        $this->assertSame('success', (string) $created->json('flag'), $this->why(
            'the product create endpoint REFUSED: ' . (string) $created->json('msg')
        ));

        $product = DB::table('products')->where('store_id', $store->id)->orderByDesc('id')->first();
        $this->assertNotNull($product, $this->why('no products row was written'));
        $this->assertSame(1, (int) $product->variant_product, $this->why(
            'the product was not stored as a variant product, so the variant rows below would be '
            . 'about something else'
        ));
        $this->trail[] = 'product: products row #' . $product->id . ' ' . $product->name;

        $variantRows = DB::table('product_variants')
            ->where('product_id', $product->id)
            ->orderBy('id')
            ->get()
            ->keyBy('variant');

        $this->assertSame(
            self::VARIANTS,
            array_map('strval', $variantRows->keys()->all()),
            $this->why('the variant rows the create wrote are not the combinations posted: '
                . json_encode($variantRows->keys()->all()))
        );

        foreach (self::VARIANTS as $variant) {
            $row = $variantRows[$variant];
            $this->assertSame(self::VARIANT_STOCK, (int) $row->stock, $this->why(
                'variant ' . $variant . ' did not keep the stock the merchant typed'
            ));
            $this->assertSame(self::VARIANT_PRICE, (float) $row->variation_price, $this->why(
                'variant ' . $variant . ' did not keep the price the merchant typed'
            ));
            $this->assertSame((int) $store->id, (int) $row->store_id, $this->why(
                'variant ' . $variant . ' was filed against another store'
            ));
        }
        $this->trail[] = 'product: product_variants ' . implode(' + ', self::VARIANTS)
            . ' @ ' . self::VARIANT_STOCK . ' each';

        /* AND THE MERCHANT CAN SEE IT. A product nobody can find is not a created product. */
        $catalogue = $this->onTheAdminSurface()->actingAs($merchant)->get('/product');
        $this->leg('product: GET /product', $catalogue->getStatusCode(), 200);

        // ------------------------------------------- LEG 3 — A SHOPPER'S ORDER ARRIVES
        $shopper = $this->aCustomerOf($store);
        $shippingId = $this->aShippingMethod($store);
        $variantId = (int) $variantRows[self::VARIANTS[0]]->id;

        $added = $this->asAShopper($shopper)->post('/' . $store->slug . '/product_cart', [
            'product_id' => $product->id,
            'variant_id' => $variantId,
            'qty' => self::BASKET_UNITS,
        ]);
        $this->leg('order: shopper adds the variant to the cart', $added->getStatusCode(), 200);
        $this->assertNotSame(0, (int) ($added->json('status') ?? 1), $this->why(
            'add-to-cart answered with the error shape, which is HTTP 200 here: '
            . $this->excerpt($added)
        ));

        $placed = $this->codCheckout($store, $shopper, $shippingId);
        $this->assertNull($this->flashedError(), $this->why(
            'the shopper\'s COD checkout was REFUSED: ' . (string) $this->flashedError()
        ));
        $this->leg('order: shopper checks out (cod)', $placed->getStatusCode(), 302);

        $order = DB::table('orders')->where('store_id', $store->id)->orderByDesc('id')->first();
        $this->assertNotNull($order, $this->why('the shopper\'s checkout wrote no order'));
        $this->assertSame((int) $store->id, (int) $order->store_id, $this->why(
            'the order was filed against another store'
        ));

        /* A NON-ZERO TOTAL, asserted here so the ledger comparison in the finance leg cannot be
           satisfied by 0.0 == 0.0. The exact arithmetic of a priced basket belongs to
           `OrderTotalCouponTest` and its siblings; what this journey needs is that money moved. */
        $this->assertGreaterThan(0.0, (float) $order->final_price, $this->why(
            'the order carries no money, so every money assertion after this one is vacuous'
        ));
        $this->assertGreaterThanOrEqual(
            self::VARIANT_PRICE * self::BASKET_UNITS,
            (float) $order->final_price,
            $this->why('the order total is below the basket the shopper built')
        );
        $this->trail[] = 'order: orders row #' . $order->id . ' ' . $order->product_order_id
            . ' total ' . $order->final_price;

        /* THE MERCHANT RECEIVES IT — on the list and on the detail screen, by name. */
        $list = $this->onTheAdminSurface()->actingAs($merchant)->get('/order');
        $this->leg('order: merchant order list', $list->getStatusCode(), 200);

        $view = $this->onTheAdminSurface()->actingAs($merchant)
            ->get('/order-view/' . \Illuminate\Support\Facades\Crypt::encrypt($order->id));
        $this->leg('order: merchant order detail', $view->getStatusCode(), 200);
        $this->assertStringContainsString(
            (string) $order->product_order_id,
            (string) $view->getContent(),
            $this->why('the merchant\'s order screen does not name the order that just arrived')
        );

        // ------------------------------------------------------------- LEG 4 — FULFIL
        /* THE WHOLE CHAIN, in the order `FulfilmentStateMachine::PERMITTED` allows:
           pending(0) -> confirmed(4) -> pickedup(5) -> shipped(6) -> delivered(1). */
        foreach ([
            'confirmed' => 4,
            'pickedup' => 5,
            'shipped' => 6,
            'delivered' => 1,
        ] as $word => $expectedState) {
            $moved = $this->moveOrderTo($merchant, (int) $order->id, $word);

            $this->leg('fulfil: ' . $word, $moved->getStatusCode(), 200);
            $this->assertSame('success', (string) $moved->json('status'), $this->why(
                'the merchant could not move the order to `' . $word . '`: ' . $this->excerpt($moved)
            ));
            $this->assertSame(
                $expectedState,
                (int) DB::table('orders')->where('id', $order->id)->value('delivered_status'),
                $this->why('`' . $word . '` reported success and did not move delivered_status')
            );
        }
        $this->trail[] = 'fulfil: confirmed -> pickedup -> shipped -> delivered';

        /* THE TIMELINE RECORDS IT. Four moves, four order notes, which is the record a dispute is
           later settled against. */
        $this->assertGreaterThanOrEqual(
            4,
            (int) DB::table('order_notes')->where('order_id', $order->id)->count(),
            $this->why('the fulfilment moves left no trace on the order\'s own timeline')
        );

        // ------------------------------------------------------- LEG 5 — SEE IT IN FINANCE
        /*
         * DELIVERY IS NOT PAYMENT — owner decision, 2026-10-24. So the order is still unsettled
         * here, and settling it is a SEPARATE deliberate act by a human.
         */
        $this->assertNotSame(
            'paid',
            strtolower((string) DB::table('orders')->where('id', $order->id)->value('payment_status')),
            $this->why('marking an order delivered settled a cash-on-delivery payment by itself, '
                . 'which the 2026-10-24 decision withdrew')
        );

        $settled = $this->onTheAdminSurface()->actingAs($merchant)
            ->postJson('/order-payment-status', [
                'order_id' => $order->id,
                'payment_status' => 'Paid',
            ]);
        $this->leg('finance: merchant asserts the cash was collected', $settled->getStatusCode(), 200);
        $this->assertSame(
            'Paid',
            (string) DB::table('orders')->where('id', $order->id)->value('payment_status'),
            $this->why('the merchant\'s own settlement act did not reach the order')
        );
        $this->trail[] = 'finance: payment_status Paid';

        $finance = $this->onTheAdminSurface()->actingAs($merchant)->get('/finance');
        $this->leg('finance: GET /finance', $finance->getStatusCode(), 200);

        /* THE ORDER REACHED THE LEDGER. `OrderObserver` writes `store_order_financials` through
           `OrderFinancialService`; GATE F declared that table on 2026-11-02, so its absence here
           would mean the credit went nowhere. */
        $ledger = DB::table('store_order_financials')->where('order_id', $order->id)->first();
        $this->assertNotNull($ledger, $this->why(
            'the order has no row in `store_order_financials`, so the merchant\'s credit for it '
            . 'exists nowhere and nothing on any path revisits it'
        ));

        /* AND THE FIGURE IS THE ORDER'S OWN. A ledger row carrying the wrong total is worse than
           no row: it is a number a merchant would act on. */
        $this->assertSame(
            (float) $order->final_price,
            (float) $ledger->customer_total,
            $this->why('the ledger records ' . $ledger->customer_total . ' for an order whose own '
                . 'total is ' . $order->final_price)
        );
        $this->assertSame((int) $store->id, (int) $ledger->store_id, $this->why(
            'the order\'s money was credited to another store'
        ));
        $this->trail[] = 'finance: store_order_financials customer_total ' . $ledger->customer_total;

        // ------------------------------------------------------------- LEG 6 — RETURN
        $requested = $this->refundRequest($store, $shopper, (int) $order->id, (int) $product->id);
        $this->assertNull($this->flashedError(), $this->why(
            'the shopper\'s return request was REFUSED: ' . (string) $this->flashedError()
        ));
        $this->leg('return: shopper requests a return', $requested->getStatusCode(), 302);

        $refund = DB::table('order_refunds')->where('order_id', $order->id)->first();
        $this->assertNotNull($refund, $this->why('the return request wrote no order_refunds row'));
        $this->trail[] = 'return: order_refunds row #' . $refund->id . ' ' . $refund->refund_status;

        $queue = $this->onTheAdminSurface()->actingAs($merchant)->get('/refund-request');
        $this->leg('return: merchant refund queue', $queue->getStatusCode(), 200);

        $accepted = $this->onTheAdminSurface()->actingAs($merchant)
            ->postJson('/update-refund-status', ['refund_id' => $refund->id]);
        $this->leg('return: merchant accepts the return', $accepted->getStatusCode(), 200);
        $this->assertSame(
            'Accept',
            (string) DB::table('order_refunds')->where('id', $refund->id)->value('refund_status'),
            $this->why('the merchant accepted the return and the row does not say so: '
                . $this->excerpt($accepted))
        );

        /* AND THE GOODS COME BACK. `RefundStock` is gated on the return having been ACCEPTED,
           which is why it is driven after the acceptance and not before.

           AND, SINCE GAP-140 / P4-F086, ON THE STORE'S OWN `manage_stock` REFUND SETTING. Nothing
           seeds `order_refund_settings`: a row appears only when a merchant saves the Settings
           screen's Refund tab, which this journey never does, and `order/refund_details.blade.php`
           renders an absent or `0` row as NO BUTTON -- so a store without this row is one the
           product never offers the action to, and the door answers 403. Saving the tab is not part
           of the journey being exercised, so the row is written directly, in the shape
           `OrderRefundController::updateStatus()` writes it.

           WITHOUT THIS the leg below fails 403 and the whole end-to-end journey reds -- which is
           how it was found: an independent review of GAP-140 ran this file after the four
           neighbouring suites had been repaired and this fifth one had not. */
        DB::table('order_refund_settings')->insert([
            'name' => 'manage_stock',
            'is_active' => 1,
            'user_id' => $merchant->id,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stockAfterSale = (int) DB::table('product_variants')->where('id', $variantId)->value('stock');

        $restocked = $this->onTheAdminSurface()->actingAs($merchant)
            ->postJson('/refund-stock', ['refundId' => $refund->id]);
        $this->leg('return: merchant restores the stock', $restocked->getStatusCode(), 200);

        $stockAfterReturn = (int) DB::table('product_variants')->where('id', $variantId)->value('stock');
        $this->assertGreaterThan(
            $stockAfterSale,
            $stockAfterReturn,
            $this->why('accepting the return and restoring the stock put nothing back: the variant '
                . 'held ' . $stockAfterSale . ' before and ' . $stockAfterReturn . ' after')
        );
        $this->trail[] = 'return: variant stock ' . $stockAfterSale . ' -> ' . $stockAfterReturn;

        $this->assertGreaterThanOrEqual(
            12,
            count($this->trail),
            'the journey recorded fewer legs than it has: ' . implode(' | ', $this->trail)
        );
    }

    // =============================================================================================
    //  NEGATIVE CONTROLS
    // =============================================================================================

    /**
     * LEG 0. The registration assertion is not "any POST provisions a merchant".
     *
     * `store_name` is what creates the tenant, so a registration without one must provision
     * nothing at all — otherwise the journey's "a store exists" assertion is satisfied by a
     * handler that always makes one.
     */
    public function test_control_a_registration_without_a_store_name_provisions_nothing(): void
    {
        $this->platformPrerequisites();

        $before = (int) DB::table('stores')->count();

        $response = $this->post('/register', [
            'name' => 'No Store', 'email' => 'nostore-' . uniqid() . '@example.test',
            'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
        ]);

        $response->assertSessionHasErrors('store_name');
        $this->assertGuest();
        $this->assertSame($before, (int) DB::table('stores')->count(),
            'a registration with no store name provisioned a store anyway');
    }

    /**
     * LEG 1. THE STORELESS GUARD, which is why the journey registers instead of hand-building a
     * merchant. `HomeController::index()` logs a merchant with no resolvable store OUT.
     */
    public function test_control_a_storeless_merchant_cannot_reach_the_panel(): void
    {
        $this->platformPrerequisites();

        $storeless = $this->makeMerchant(['current_store' => null]);
        $this->grantGlobalAdminPermissions($storeless, ['Manage Order']);

        $response = $this->actingAs(User::find($storeless->id))->get('/dashboard');

        $this->assertSame(302, $response->getStatusCode(),
            'a merchant with no store reached the dashboard, so the journey\'s provisioning '
            . 'assertions are not load-bearing');
        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * LEG 0/1 — A MEASURED DEFECT, PINNED RATHER THAN FIXED, because the code is not this task's.
     * =============================================================================================
     *
     * `User::assignPlan()` ends in a SEAT CASCADE:
     *
     *     $users = User::where('created_by', '=', $this->id)->where('type','!=','super admin')->get();
     *     ... foreach: $userCount <= $plan->max_users ? is_active = 1 : is_active = 0
     *
     * (`app/Models/User.php:487-519`). It is guarded for the case where the SUBJECT is the platform
     * operator (`$subjectIsPlatformOperator`), and the guard's own comment records why: every
     * merchant is created with `created_by = 1`, so an operator subject selects every merchant on
     * the platform.
     *
     * WHAT IS STILL OPEN. The predicate selects a row whose `created_by` equals the SUBJECT'S OWN
     * id — so a user for whom `id === created_by` is their own staff. `Auth\RegisteredUserController`
     * hard-codes `created_by => 1` (:133 and :286), and the one `type = 'admin'` row that the
     * operator guard does not cover is a merchant who IS id 1. For that merchant, registration's
     * own `assignPlan(1)` counts them as their own first seat, and any `max_users` below 1 writes
     * `is_active = 0` on the account that was just created. `SuspendedAccountGuard` then logs them
     * out of the very next request.
     *
     * MEASURED, on this file's first run, with a `plans` row inserted without `max_users` (the
     * column is `string` DEFAULT 0, so it held '0', and `1 <= '0'` is false):
     *
     *     POST /register   -> 302, provisioned: users +1, stores +1, store_wallets +1, role attached
     *     users.is_active  -> '0'
     *     GET  /dashboard  -> 302 to /login, flash "Your Account is de-activate,please contact
     *                         your Administrator."
     *
     * HOW REACHABLE IT IS, STATED HONESTLY AND NOT OVERSTATED. On a seeded install user 1 is the
     * platform operator (`type = 'super admin'`), so a merchant is never id 1 and the path is
     * LATENT. It is live wherever the first `users` row is a merchant — a from-zero database, a
     * restored install whose operator row was recreated, a tenant-only import — and it is live for
     * ANY plan whose `max_users` is 0, which is the column default, so a plan inserted or imported
     * without that field.
     *
     * WHY IT IS PINNED HERE RATHER THAN FIXED. `app/Models/User.php` is outside the files this task
     * owns, and the fix is a product decision as well as a code change (the right predicate is
     * "rows this user created OTHER THAN itself", and whether a 0-seat plan should deactivate
     * existing staff at all is the owner's). This test records the behaviour so it cannot change
     * silently, in EITHER direction: if the cascade is corrected, it fails and says so.
     */
    public function test_control_the_plan_seat_cascade_can_deactivate_the_subject_itself(): void
    {
        $this->platformPrerequisites();

        /* A plan with the `max_users` the COLUMN DEFAULT gives, which is what an insert that omits
           the field produces. `plans.max_users` is `string` DEFAULT 0 and NOT nullable. */
        $zeroSeatPlan = (int) DB::table('plans')->insertGetId([
            'name' => 'Zero Seats', 'price' => 0, 'duration' => 'Unlimited',
            'max_stores' => 2, 'max_products' => 5,
            'storage_limit' => 100, 'shipping_method' => 'off', 'themes' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('0', (string) DB::table('plans')->where('id', $zeroSeatPlan)->value('max_users'),
            'premise: the column default is no longer 0, so this case is about something else');

        /* THE SUBJECT IS THEIR OWN `created_by`, which is the whole condition. Registration writes
           `created_by => 1`, so this is the shape of a merchant who is id 1. */
        $merchant = $this->makeMerchant();
        DB::table('users')->where('id', $merchant->id)->update(['created_by' => $merchant->id]);
        (new User)->flushCache();

        $subject = User::find($merchant->id);
        $this->assertSame((int) $subject->id, (int) $subject->created_by,
            'premise: the subject is their own creator');
        $this->assertSame('1', (string) $subject->is_active, 'premise: the subject starts active');

        $this->actingAs($subject);
        $subject->assignPlan($zeroSeatPlan);

        $this->assertSame(
            '0',
            (string) DB::table('users')->where('id', $subject->id)->value('is_active'),
            'THE CASCADE NO LONGER DEACTIVATES THE SUBJECT ITSELF. That is an improvement, not a '
            . 'failure -- but it means `User::assignPlan()` has changed, so delete this test and '
            . 'the paragraph above it, and tell whoever is tracking GAP-020 that the finding is '
            . 'closed. app/Models/User.php:487-519.'
        );
    }

    /** LEG 2. The product assertion is not "the endpoint always writes a product". */
    public function test_control_a_product_create_without_a_category_writes_nothing(): void
    {
        [$merchant, $store] = $this->provisionedTenant();
        [$attributeId] = $this->anAttributeWithTerms($store, 'Colour', self::VARIANTS);

        $payload = $this->variantProductPayload(0, $attributeId);
        unset($payload['category_id']);

        $response = $this->actingAs($merchant)->post('/product', $payload);

        $this->assertSame('error', (string) $response->json('flag'),
            'a product with no category was ACCEPTED');
        $this->assertSame(0, (int) DB::table('products')->where('store_id', $store->id)->count(),
            'a refused product create wrote a products row, so the journey\'s row assertion is '
            . 'satisfied by a handler that writes one for anything');
        $this->assertSame(0, (int) DB::table('product_variants')->count(),
            'a refused product create wrote variant rows');
    }

    /** LEG 2b. A merchant without `Create Product` creates nothing — Laratrust, not `@can`. */
    public function test_control_a_merchant_without_the_permission_creates_no_product(): void
    {
        [$merchant, $store] = $this->provisionedTenant(['Manage Order']);
        [$attributeId] = $this->anAttributeWithTerms($store, 'Colour', self::VARIANTS);
        $categoryId = $this->aCategory($store);

        $this->assertFalse(
            User::find($merchant->id)->isAbleTo('Create Product'),
            'premise: this merchant was granted `Create Product` after all, so the control is '
            . 'driving the permitted path'
        );

        $this->actingAs(User::find($merchant->id))
            ->post('/product', $this->variantProductPayload($categoryId, $attributeId));

        $this->assertSame(0, (int) DB::table('products')->where('store_id', $store->id)->count(),
            'a merchant without `Create Product` created one');
    }

    /** LEG 4. The fulfilment assertion is not "any word moves the order". */
    public function test_control_an_unknown_status_word_does_not_move_the_order(): void
    {
        [$merchant, $store, $order] = $this->tenantWithAnOrder();

        $response = $this->moveOrderTo($merchant, (int) $order->id, 'teleported');

        $this->assertNotSame('success', (string) $response->json('status'),
            'an unknown status word was accepted');
        $this->assertSame(0, (int) DB::table('orders')->where('id', $order->id)->value('delivered_status'),
            'an unknown status word moved delivered_status, so the fulfilment leg\'s column '
            . 'assertions are not discriminating');
    }

    /**
     * LEG 4b. A DELIVERED ORDER DOES NOT GO BACKWARDS — owner decision H2. The journey walks
     * forwards through four states, and this is what makes that walk mean something.
     */
    public function test_control_a_delivered_order_cannot_be_moved_back_to_confirmed(): void
    {
        [$merchant, , $order] = $this->tenantWithAnOrder();

        $this->moveOrderTo($merchant, (int) $order->id, 'delivered');
        $this->assertSame(1, (int) DB::table('orders')->where('id', $order->id)->value('delivered_status'),
            'premise: the order is delivered');

        $back = $this->moveOrderTo($merchant, (int) $order->id, 'confirmed');

        $this->assertNotSame('success', (string) $back->json('status'),
            'a delivered order was moved back to confirmed');
        $this->assertSame(1, (int) DB::table('orders')->where('id', $order->id)->value('delivered_status'),
            'a delivered order went backwards');
    }

    /**
     * LEG 5. DELIVERY IS NOT PAYMENT, stated as its own control.
     *
     * The journey settles the COD order by a separate human act. This is the half that says the
     * withdrawn trigger has not come back: nothing about marking an order delivered may settle it.
     */
    public function test_control_cod_is_not_settled_by_delivery_alone(): void
    {
        [$merchant, , $order] = $this->tenantWithAnOrder();

        $before = (string) DB::table('orders')->where('id', $order->id)->value('payment_status');
        $this->assertNotSame('Paid', $before, 'premise: the COD order is not already Paid');

        $this->moveOrderTo($merchant, (int) $order->id, 'delivered');

        $this->assertSame(
            $before,
            (string) DB::table('orders')->where('id', $order->id)->value('payment_status'),
            'marking a cash-on-delivery order delivered changed its payment status. The owner '
            . 'withdrew that trigger on 2026-10-24: "Delivered must never, by itself, mark COD as '
            . 'Paid or credit a merchant wallet."'
        );
        $this->assertSame(
            0,
            (int) DB::table('store_wallet_transactions')->count(),
            'marking delivered moved money in the wallet ledger'
        );
    }

    /** LEG 6. The return assertion is not "any merchant can accept any refund". */
    public function test_control_a_foreign_merchant_cannot_accept_this_stores_refund(): void
    {
        [, $store, $order] = $this->tenantWithAnOrder();

        $refundId = (int) DB::table('order_refunds')->insertGetId([
            'order_id' => $order->id, 'store_id' => $store->id,
            'refund_status' => 'Processing', 'refund_reason' => 'Other',
            'custom_refund_reason' => 'control',
            'product_refund_id' => json_encode([
                ['product_refund_id' => 1, 'return_price' => 10, 'quantity' => 1],
            ]),
            'product_refund_price' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        [$stranger] = $this->provisionedTenant(['Manage Order Refund Request']);

        $this->onTheAdminSurface()->actingAs(User::find($stranger->id))
            ->postJson('/update-refund-status', ['refund_id' => $refundId]);

        $this->assertSame(
            'Processing',
            (string) DB::table('order_refunds')->where('id', $refundId)->value('refund_status'),
            'a merchant of another store accepted this store\'s refund request, so the journey\'s '
            . 'acceptance assertion is satisfied by a handler with no tenant predicate'
        );
    }


    // =============================================================================================
    //  THE OTHER DIRECTION — does the exercise NOTICE when the application breaks?
    // =============================================================================================

    /*
     * WHY THESE EXIST, AND WHY THEY ARE RUNTIME MUTATIONS RATHER THAN SOURCE EDITS.
     * ---------------------------------------------------------------------------------------------
     * This project's TDD rule is stricter than the vendored skill's: after the change, revert it and
     * confirm the test fails again. The skill's rule catches a test that never ran; ours catches a
     * test that PASSES FOR THE WRONG REASON. A journey exercise is unusually exposed to that — it
     * drives a dozen HTTP requests and could be green while asserting nothing that matters — so a
     * green journey is not on its own evidence that the journey is load-bearing.
     *
     * Each case below BREAKS ONE NAMED BEHAVIOUR the journey claims to exercise, re-runs the whole
     * exercise, and asserts it fails AT THE LEG THAT OWNS THAT BEHAVIOUR. The break is applied
     * through a model event, so no file on disk changes: `tests/Support/bin/mutate.py` is the
     * repository's source-mutation harness and the right tool when the behaviour cannot be reached
     * any other way, but a mutation that runs inside the test process leaves no window in which a
     * concurrent reader of the tree sees broken source, and it can live in the suite as evidence
     * rather than in a script somebody has to remember to run.
     *
     * A model event registered in a test does NOT leak into the next one:
     * `InteractsWithTestCaseLifecycle:103` re-points `Model::setEventDispatcher()` at each fresh
     * application's dispatcher in `setUp`.
     *
     * THE ASSERTION IS ON THE MESSAGE, not merely on "something failed". A journey that died for an
     * unrelated reason would otherwise read as caught.
     */

    /** The product leg really checks that a created variant keeps the stock the merchant typed. */
    public function test_the_exercise_notices_when_a_variant_loses_the_stock_that_was_posted(): void
    {
        ProductVariant::saving(static function (ProductVariant $variant): void {
            $variant->stock = 0;
        });

        $failure = $this->runTheJourneyExpectingAFailure();

        $this->assertStringContainsString(
            'did not keep the stock the merchant typed',
            $failure,
            'THE JOURNEY DID NOT NOTICE that every created variant came out with stock 0. Its '
            . 'product leg is therefore not evidence for "create product with variants" (P01-G1, '
            . 'ACCEPTANCE-CRITERIA §G step 1). What it failed on instead: ' . $failure
        );
    }

    /** The fulfilment leg really checks that a move ARRIVES, not just that it reports success. */
    public function test_the_exercise_notices_when_a_fulfilment_move_reports_success_without_arriving(): void
    {
        \App\Models\Order::saving(static function (\App\Models\Order $order): void {
            /* Only the `delivered` arm writes 1, so this leaves every other save alone and makes
               exactly one transition report success while the column stays where it was. */
            if ((int) $order->delivered_status === 1) {
                $order->delivered_status = 0;
            }
        });

        $failure = $this->runTheJourneyExpectingAFailure();

        $this->assertStringContainsString(
            'reported success and did not move delivered_status',
            $failure,
            'THE JOURNEY DID NOT NOTICE that `delivered` never arrived. Its fulfilment leg is '
            . 'therefore reading the handler\'s own answer and not the order. What it failed on '
            . 'instead: ' . $failure
        );
    }

    /**
     * Run the exercise and return the assertion message it died on.
     *
     * `AssertionFailedError` and not `Throwable`: a `TypeError` or a query exception is NOT the
     * exercise noticing something, it is the mutation breaking the fixture, and the two must not
     * be confused. Those propagate.
     */
    private function runTheJourneyExpectingAFailure(): string
    {
        try {
            $this->test_the_merchant_journey_runs_end_to_end_as_one_exercise();
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            return $e->getMessage();
        }

        $this->fail(
            'THE MUTATION SURVIVED: the merchant journey passed with the behaviour above broken, '
            . 'so it is not evidence for that leg. Legs it recorded: ' . implode(' | ', $this->trail)
        );
    }

    // =============================================================================================
    //  THE FIXTURE
    // =============================================================================================

    /**
     * WHAT A SEEDED INSTALL HAS AND `RefreshDatabase` DOES NOT.
     *
     *  - the single global `admin` role with a null `store_id`, exactly as `PermissionTableSeeder`
     *    creates it. Without it `RegisteredUserController::store()` REFUSES the registration
     *    before its first write (GAP-014) — correctly, and that refusal has its own test.
     *  - `plan` id 1, which `assignPlan(1)` reaches for when a fresh merchant has no plan.
     *  - NOTHING for storage: `Utility::upload_file()` reads `Setting::where('created_by', 1)` and,
     *    when that has no `storage_setting`, falls back to `Utility::Setting()` -- whose defaults
     *    carry `storage_setting => local`, `local_storage_validation => jpg,jpeg,png,csv,svg,pdf`
     *    and a size ceiling. INSERTING a bare `storage_setting` row is WORSE than inserting
     *    nothing, and that is measured: with one present the fallback never runs, so
     *    `local_storage_validation` resolved to '' and `PlatformUploadPolicy` refused the product
     *    image with "No file type is enabled for uploads." -- i.e. a fixture that half-configured
     *    storage broke the leg it was meant to enable.
     */
    private function platformPrerequisites(): void
    {
        if (Role::where('name', 'admin')->whereNull('store_id')->count() === 0) {
            Role::create(['name' => 'admin', 'created_by' => null]);
        }

        /*
         * PLAN 1, WITH `PlansTableSeeder`'s OWN NUMBERS -- and the seat caps are not decoration.
         * =========================================================================================
         * `plans.max_users` and `max_stores` are `string` columns with DEFAULT 0 and no null, so a
         * plan row inserted without them carries '0'. `User::assignPlan()` then runs its seat
         * cascade: `User::where('created_by', $this->id)->where('type','!=','super admin')->get()`,
         * and for each row past `max_users` writes `is_active = 0`.
         *
         * MEASURED on the first run of this file, with `max_users` left at the column default: the
         * merchant who had just registered came out of `/register` with `users.is_active = '0'` and
         * `GET /dashboard` answered 302 to `/login` with "Your Account is de-activate" --
         * `SuspendedAccountGuard` logging out the account registration had just created. The reason
         * is that `RegisteredUserController` hard-codes `created_by => 1` and in a from-zero
         * database the registered merchant IS id 1, so the cascade selected the merchant as their
         * own staff. See `test_control_the_plan_seat_cascade_can_deactivate_the_subject_itself`,
         * which pins that measurement rather than leaving it in this comment.
         *
         * Mirroring the seeder is the right fixture because it is the production precondition: a
         * seeded install has these numbers, and user 1 is the platform operator rather than a
         * merchant.
         */
        if (! DB::table('plans')->where('id', 1)->exists()) {
            DB::table('plans')->insert([
                'id' => 1, 'name' => 'Free Plan', 'price' => 0, 'duration' => 'Unlimited',
                'max_stores' => 2, 'max_products' => 5, 'max_users' => 5,
                'storage_limit' => 100, 'shipping_method' => 'off',
                'themes' => 'stylique,greentic,techzonix',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        /* Both registration branches read the platform operator through
           `Cache::remember('super_admin_details', ...)`, and several handlers read
           `admin_details`, so a cached pre-fixture `User` would be served instead. */
        Cache::forget('admin_details');
        Cache::forget('super_admin_details');
    }

    /**
     * A provisioned tenant for the CONTROLS — which do not re-drive registration, because what
     * they are controlling is a later leg and a second registration would only make them slower.
     *
     * @return array{0: User, 1: Store}
     */
    private function provisionedTenant(array $permissions = [
        'Create Product', 'Manage Products', 'Manage Order', 'Show Order',
        'Manage Order Refund Request', 'View Finance Dashboard',
    ]): array
    {
        $this->platformPrerequisites();

        [$merchant, $store] = $this->makeTenant();

        $merchant->plan_id = 1;
        $merchant->save();

        app(\App\Services\StoreWalletProvisioning::class)->ensure((int) $store->id);

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

        $merchant = $this->grantGlobalAdminPermissions($merchant, $permissions);

        return [$merchant, $store];
    }

    /**
     * A provisioned tenant holding one real COD order, written by the storefront checkout.
     *
     * NOT `makeOrder()`: a hand-inserted row cannot show that the fulfilment leg acts on what the
     * checkout actually produces, and the controls below are about the fulfilment and return
     * rules rather than about the writer.
     *
     * @return array{0: User, 1: Store, 2: object}
     */
    private function tenantWithAnOrder(): array
    {
        [$merchant, $store] = $this->provisionedTenant();

        $this->geography();
        $shippingId = $this->aShippingMethod($store);
        $shopper = $this->aCustomerOf($store);

        $product = Product::forceCreate([
            'name' => 'Control Widget', 'slug' => 'control-widget-' . uniqid(),
            'description' => 'x', 'category_id' => $this->aCategory($store),
            'product_stock' => 30, 'track_stock' => 1, 'variant_product' => 0,
            'price' => self::VARIANT_PRICE, 'sale_price' => self::VARIANT_PRICE,
            'stock_status' => 'in_stock', 'status' => 1, 'trending' => 0,
            'store_id' => $store->id, 'created_by' => $merchant->id,
        ]);

        Cart::forceCreate([
            'customer_id' => $shopper->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => self::BASKET_UNITS, 'price' => self::VARIANT_PRICE * self::BASKET_UNITS,
            'store_id' => $store->id,
        ]);
        (new Cart)->flushCache();

        $this->codCheckout($store, $shopper, $shippingId);

        $order = DB::table('orders')->where('store_id', $store->id)->orderByDesc('id')->first();
        $this->assertNotNull($order, 'premise: the control fixture\'s own checkout wrote an order: '
            . (string) $this->flashedError());

        return [User::find($merchant->id), $store, $order];
    }

    /**
     * The payload the product editor posts for a NEW product with variants.
     *
     * The variant half is `ProductController::store()`'s own reading of it, which differs from
     * `update()`'s: the combinations come from `options[]` (:1319-1331), not `options_datas[]`,
     * and every per-variant field is keyed by the combination name with dots replaced by
     * underscores. `visible_attribute_N` is an UNGUARDED array read (`$input['visible_attribute_'
     * . $no]`), so omitting it is a 500 rather than a validation error.
     *
     * @return array<string,mixed>
     */
    private function variantProductPayload(int $categoryId, int $attributeId): array
    {
        $payload = [
            'name' => 'Journey Scarf',
            'category_id' => $categoryId,
            'status' => 1,
            'variant_product' => 1,
            'description' => 'A scarf in two colours.',
            'trending' => 0,
            'track_stock' => 1,
            'tax_status' => 'taxable',
            'cover_image' => $this->image('cover.png'),
            'product_image' => [$this->image('gallery.png')],

            'attribute_no' => [$attributeId],
            'visible_attribute_' . $attributeId => 1,
            'for_variation_' . $attributeId => 1,
            'attribute_options_' . $attributeId => self::VARIANTS,

            /* THE COMBINATIONS. `store()` pushes `$request['options']` once per attribute marked
               for variation and then writes one `product_variants` row per element. */
            'options' => self::VARIANTS,
        ];

        foreach (self::VARIANTS as $variant) {
            $key = str_replace('.', '_', $variant);
            $payload['product_sku_' . $key] = '-' . $variant;
            $payload['product_variation_price_' . $key] = self::VARIANT_PRICE;
            $payload['product_sale_price_' . $key] = self::VARIANT_PRICE;
            /*
             * THE TWO CHECKBOXES THE COMBINATIONS PANEL ACTUALLY POSTS, and both are
             * load-bearing (`resources/views/product/attribute_combinations.blade.php:78,96`).
             *
             *   `enabled`      -- `CartController::product_cartlist` refuses the add outright
             *                     without it: "Product variant is not available.", HTTP 200 with
             *                     the error flag, measured on the second run of this file.
             *   `manage_stock` -- what makes the writer honour `product_stock_N`. Without it the
             *                     row is stored with stock 0 and the order could never be
             *                     fulfilled.
             */
            $payload['variation_option_' . $key] = ['enabled', 'manage_stock'];
            $payload['product_stock_' . $key] = self::VARIANT_STOCK;
            $payload['stock_status_' . $key] = 'in_stock';
            $payload['stock_order_status_' . $key] = 'out_of_stock';
            $payload['low_stock_threshold_' . $key] = 1;
        }

        return $payload;
    }

    /**
     * THE GLOBAL `admin` ROLE'S PERMISSIONS, GIVEN IDEMPOTENTLY.
     *
     * `SecurityTestCase::grantMerchantGlobalRole()` calls `givePermission()` unconditionally, and
     * the `admin` role is ONE SHARED GLOBAL ROW — so a test that provisions a SECOND tenant (the
     * cross-tenant control does) re-gives the same permission to the same role and SQLite answers
     * `UNIQUE constraint failed: permission_role.permission_id, permission_role.role_id`. Measured.
     * Attaching only what the role does not already hold keeps the shared-row shape, which is the
     * production shape, instead of giving each test merchant a private role the product would
     * never hand them.
     */
    private function grantGlobalAdminPermissions(User $merchant, array $permissions): User
    {
        $role = Role::where('name', 'admin')->whereNull('store_id')->first()
            ?: $this->makeGlobalAdminRole();

        $held = DB::table('permission_role')->where('role_id', $role->id)->pluck('permission_id')->all();

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['display_name' => $name]);

            if (! in_array((int) $permission->id, array_map('intval', $held), true)) {
                $role->givePermission($permission);
                $held[] = $permission->id;
            }
        }

        if (! $merchant->hasRole($role->name)) {
            $merchant->addRole($role);
        }

        $merchant->flushCache();

        return User::find($merchant->id);
    }

    private function aCategory(Store $store): int
    {
        return (int) DB::table('categories')->insertGetId([
            'name' => 'Textiles', 'slug' => 'textiles-' . uniqid(),
            'trending' => 0, 'status' => 1, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{0: int, 1: list<int>} the attribute id and its option ids */
    private function anAttributeWithTerms(Store $store, string $label, array $terms): array
    {
        $attributeId = (int) DB::table('product_attributes')->insertGetId([
            'name' => $label, 'slug' => strtolower($label) . '-' . uniqid(),
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $optionIds = [];
        foreach ($terms as $term) {
            $optionIds[] = (int) DB::table('product_attribute_options')->insertGetId([
                'attribute_id' => $attributeId, 'terms' => $term,
                'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$attributeId, $optionIds];
    }

    private function aShippingMethod(Store $store): int
    {
        $this->geography();

        return (int) DB::table('shippings')->insertGetId([
            'name' => 'standard', 'slug' => 'standard-' . uniqid(),
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function geography(): void
    {
        DB::table('countries')->insertOrIgnore([
            'id' => 101, 'name' => 'Syria', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('states')->insertOrIgnore([
            'id' => 1, 'name' => 'Damascus', 'country_id' => 101,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cities')->insertOrIgnore([
            'id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function aCustomerOf(Store $store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Journey', 'last_name' => 'Shopper',
            'email' => 'journey-shopper-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    /** A real PNG on disk, wrapped as an upload — see the PNG constant for why the bytes matter. */
    private function image(string $clientName): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'ssjourney');
        file_put_contents($path, (string) base64_decode(self::PNG));
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $clientName, 'image/png', null, true);
    }

    /**
     * LEAVE THE STOREFRONT BEFORE ENTERING THE PANEL. The mechanism, the measurement and why this
     * is a harness correction rather than a production fix are recorded on
     * `CustomerJourneyTest::onTheAdminSurface()`.
     */
    private function onTheAdminSurface(): self
    {
        \Qirolab\Theme\Theme::clear();
        $this->app['view']->getFinder()->flush();

        return $this;
    }

    /** A signed-in shopper as production sees one — see `CustomerJourneyTest::asAShopper()`. */
    private function asAShopper(Customer $customer): self
    {
        $this->actingAs($customer, 'customers');
        $this->app['auth']->shouldUse('web');

        return $this;
    }

    private function codCheckout(Store $store, Customer $shopper, int $shippingId)
    {
        $billing = [
            'firstname' => 'Journey', 'lastname' => 'Shopper',
            'email' => 'journey-shopper@example.test',
            'billing_user_telephone' => '555111222',
            'billing_address' => 'Street', 'billing_postecode' => '11111',
            'billing_country' => '101', 'billing_state' => '1', 'billing_city' => '1',
            'billing_company_name' => '',
            'delivery_address' => 'Street', 'delivery_postcode' => '11111',
            'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
        ];

        return $this->asAShopper($shopper)
            ->post('/' . $store->slug . '/process-order', [
                'payment_type' => 'cod',
                'delivery_id' => $shippingId,
                'method_id' => $shippingId,
                'billing_info' => $billing,
            ] + $billing);
    }

    /**
     * The merchant moves the order, THROUGH THE SHIPPED CALLER'S SHAPE.
     *
     * `order_status_change` answers `expectsJson()` with JSON and a plain form POST with
     * `redirect()->back()` — so a plain post is a 302 whether it succeeded or was refused. The
     * order detail page posts by AJAX and reads `data.status`; that is the contract driven here.
     */
    private function moveOrderTo(User $merchant, int $orderId, string $word)
    {
        return $this->onTheAdminSurface()->actingAs(User::find($merchant->id))
            ->postJson('/order-status-change/' . $orderId, [
                'delivered' => $word,
                'id' => $orderId,
            ]);
    }

    private function refundRequest(Store $store, Customer $shopper, int $orderId, int $productId)
    {
        return $this->asAShopper($shopper)
            ->post('/' . $store->slug . '/order-refund-request/' . $orderId, [
                'order_id' => $orderId,
                'product_refund_id' => [$productId],
                'quantity' => [self::BASKET_UNITS],
                'return_price' => [self::VARIANT_PRICE * self::BASKET_UNITS],
                'product_sub_total' => self::VARIANT_PRICE * self::BASKET_UNITS,
                'refund_reason' => 'Other',
                'custom_refund_reason' => 'the colour was wrong',
            ]);
    }

    /** See `CustomerJourneyTest::flashedError()` for why this is not `$response->getSession()`. */
    private function flashedError(): ?string
    {
        $error = $this->app['session.store']->get('error');

        return $error === null ? null : (string) $error;
    }

    private function leg(string $name, int $actual, int $expected): void
    {
        $this->assertSame($expected, $actual, $this->why(
            'LEG FAILED — ' . $name . ' answered HTTP ' . $actual . ', expected ' . $expected
        ));

        $this->trail[] = $name . ' -> ' . $actual;
    }

    private function why(string $message): string
    {
        return $message . "\n    legs completed: "
            . ($this->trail === [] ? '(none)' : implode(' | ', $this->trail));
    }

    private function excerpt($response): string
    {
        return substr(preg_replace('/\s+/', ' ', (string) $response->getContent()), 0, 400);
    }
}
