<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\OrderRefundController;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * GAP-140 / P04-P6-04 -- THE REMAINING HALVES: P4-F086 (the refund policy toggles are never read
 * server-side), P4-F087 (no mutator records an actor) and P4-F089 (the operator is locked out of
 * the refund list).
 * ==============================================================================================
 *
 * WHAT WAS MEASURED BEFORE ANY CODE WAS CHANGED, because the register's claim had to be confirmed
 * rather than taken on trust. The complete set of `OrderRefundSetting` references outside its own
 * model was FOUR files, and in only two of them does a value decide anything:
 *
 *   app/Http/Controllers/OrderRefundController.php:289   show()   -> hands the array to a VIEW
 *   app/Http/Controllers/OrderRefundController.php:311-319        -> the settings WRITER itself
 *   app/Http/Controllers/AccountProfileController.php:1646 order_refund() -> hands it to a VIEW
 *   app/Http/Middleware/OrderDocumentAccessGuard.php:283         -> a COMMENT, nothing else
 *   resources/views/setting/refund_setting.blade.php:29          -> renders the three switches
 *
 * So all three toggles gated MARKUP and nothing else:
 *
 *   manage_stock    order/refund_details.blade.php:224  shows/hides the "Manage Stock" button
 *   shiping_deduct  order/refund_details.blade.php:141  shows/hides the "Refund Shipping" box
 *   attachment      front_end/pages/refund_order.blade.php:156  shows/hides the shopper's file input
 *
 * AND NOTHING SEEDS THE TABLE. `order_refund_settings` rows exist only once a merchant saves the
 * refund tab (`is_active` defaults to 0, no seeder writes it, `Utility::addNewData()` does not
 * know the table). The settings screen renders an ABSENT row as an OFF switch, so "never
 * configured" and "configured off" are the same claim to the merchant -- which is why the gates
 * below treat them the same way.
 *
 * TWO OF THE THREE ARE ENFORCED HERE AND THE THIRD DELIBERATELY IS NOT.
 *
 *   manage_stock  ENFORCED at `RefundStock()`. A stock movement, so the refusal moves no money.
 *   attachment    ENFORCED at the storefront refund request. An intake decision, no money.
 *   shiping_deduct NOT ENFORCED, and `test_the_shipping_deduction_toggle_is_still_read_by_no_php_writer`
 *                 pins that so a later change has to say why. It decides whether the delivery
 *                 charge is FOLDED INTO THE AMOUNT REFUNDED: the figure arrives at
 *                 `RefundAmonut` as one client-typed number with no breakdown, so the server
 *                 cannot tell a total that includes shipping from one that does not, and any
 *                 enforcement here would change a refund AMOUNT rather than a permission.
 *                 Reported instead of changed.
 *
 * ORACLES. `orders` and `products` are read RAW through `DB::table()`: both models are `Cachable`
 * and under `CACHE_STORE=array` a cached row outlives the write for the rest of the test, which
 * has produced three measured false readings in this repository. `order_refunds` is read raw for a
 * second reason -- `OrderRefund` IS in `config/tenancy.php`'s `scoped_models`, so a plain
 * `OrderRefund::find()` answers against whichever tenant happens to be resolved rather than
 * against the table. `audit_logs` is one of the sixteen models that is NOT `Cachable`, so it may
 * be read either way; it is read raw anyway, for uniformity.
 *
 * It extends `SecurityTestCase` for the same reason `RefundStockCrossDoorTest` does: that base
 * class records the on-disk migrations so `XSS`'s updater gate does not redirect the caller away
 * from the controller before it is reached. Without it every assertion here would pass without
 * exercising anything.
 */
class RefundPolicyEnforcementTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFklEQVQImWM8ISfHwMDAxMDAwMDAAAANBAEIG3S2BgAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $temporary = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('theme');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    // =============================================================================================
    //  Fixture
    // =============================================================================================

    /** A merchant, their store, and a plan, with `admin_details` forgotten so the fixture is seen. */
    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'gap140-plan-' . $this->uniqueFixtureRef(),
            'price' => 0,
            'themes' => '[]',
            'storage_limit' => '100',
            'shipping_method' => 'on',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        return [$merchant->fresh(), $store];
    }

    /**
     * Write a refund-policy row exactly as the settings screen's own POST writes it.
     *
     * The READ side the views use is `where('store_id', ...)->pluck('is_active', 'name')` with NO
     * user predicate, while `updateStatus()` writes one row per (store, user, name). So two users
     * of one store produce two rows per name and `pluck` keeps whichever comes last -- recorded
     * rather than relied on; every fixture here writes one row per name.
     */
    private function refundSetting(Store $store, User $as, string $name, int $active): void
    {
        DB::table('order_refund_settings')->insert([
            'name' => $name,
            'user_id' => $as->id,
            'is_active' => $active,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function shopper(Store $store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shop',
            'last_name' => 'Per',
            'email' => 'shopper' . $this->uniqueFixtureRef() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => $store->id,
            'status' => 1,
            'billable_id' => $store->created_by,
            'billable_type' => User::class,
        ]);
    }

    private function productIn(Store $store, int $stock): int
    {
        return (int) DB::table('products')->insertGetId([
            'name' => 'Widget',
            'slug' => 'widget-' . $this->uniqueFixtureRef(),
            'product_stock' => $stock,
            'variant_product' => 0,
            'track_stock' => 1,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A DELIVERED order carrying one line of this product. */
    private function order(Store $store, Customer $owner, int $productId, int $qty = 3): Order
    {
        return $this->makeOrder($store, [
            'product_order_id' => 'ORD-' . strtoupper($this->uniqueFixtureRef()),
            'customer_id' => $owner->id,
            'is_guest' => 0,
            'delivered_status' => 1,
            'product_price' => 300,
            'delivery_price' => 20,
            'tax_price' => 0,
            'coupon_price' => 0,
            'final_price' => 320,
            'payment_type' => 'cod',
            'product_json' => json_encode([[
                'product_id' => $productId,
                'name' => 'Widget',
                'variant_id' => 0,
                'qty' => $qty,
                'final_price' => 300,
            ]]),
        ]);
    }

    private function handRefund(Order $order, Store $store, string $status, int $productId, int $quantity = 1): int
    {
        return (int) DB::table('order_refunds')->insertGetId([
            'order_id' => $order->id,
            'refund_status' => $status,
            'refund_reason' => 'Other',
            'product_refund_id' => json_encode([[
                'product_refund_id' => $productId,
                'return_price' => 'P',
                'quantity' => $quantity,
            ]]),
            'refund_amount' => 0,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function stockOf(int $productId): int
    {
        return (int) DB::table('products')->where('id', $productId)->value('product_stock');
    }

    /** @return list<array<string, mixed>> the audit rows for a refund action, raw */
    private function auditRows(string $like = 'refund.'): array
    {
        return DB::table('audit_logs')
            ->where('action', 'like', $like . '%')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => (array) $row)
            ->all();
    }

    private function asMerchantWithTheRefundPermission(User $merchant): User
    {
        $this->grantPermission($merchant, 'Manage Order Refund Request');

        return $merchant->fresh();
    }

    private function restock(int $refundId)
    {
        return (new OrderRefundController())->RefundStock(
            Request::create('/refund-stock', 'POST', ['refundId' => $refundId])
        );
    }

    private function accept(int $refundId)
    {
        return (new OrderRefundController())->updateRefundStatus(
            Request::create('/update-refund-status', 'POST', ['refund_id' => $refundId])
        );
    }

    private function reject(int $refundId)
    {
        return (new OrderRefundController())->CancelRefundStatus(
            Request::create('/cancel-refund-status', 'POST', ['refund_id' => $refundId, 'subtotal' => 100])
        );
    }

    private function payOut(Order $order, int $refundId, string $amount = '50')
    {
        return (new OrderRefundController())->RefundAmonut(
            Request::create('/refund-amount/' . $order->id, 'POST', [
                'refundId' => $refundId,
                'refund_amount' => $amount,
            ]),
            $order->id
        );
    }

    private function upload(string $clientName, string $bytes): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'gap140');
        file_put_contents($path, $bytes);
        $this->temporary[] = $path;

        return new UploadedFile($path, $clientName, null, null, true);
    }

    private function png(): string
    {
        return (string) base64_decode(self::PNG);
    }

    /** @return list<string> what the faked `theme` disk holds */
    private function stored(): array
    {
        return Storage::disk('theme')->allFiles();
    }

    private function postRefundRequest(Store $store, Customer $customer, Order $order, int $productId, array $files)
    {
        $this->actingAs($customer, 'customers');
        // CLAUDE.md: actingAs on `customers` also makes it the DEFAULT guard, which
        // `getCurrentStore()` reads. Production's default guard is `web` with nobody in it.
        $this->app['auth']->shouldUse('web');

        return $this->post('/' . $store->slug . '/order-refund-request/' . $order->id, [
            'order_id' => $order->id,
            'product_refund_id' => [$productId],
            'quantity' => [1],
            'return_price' => ['P'],
            'product_sub_total' => 100,
            'refund_reason' => 'Other',
            'custom_refund_reason' => 'x',
            'attachments' => $files,
        ]);
    }

    // =============================================================================================
    //  P4-F086 -- THE CENSUS, so the claim "a view only" cannot quietly become false again
    // =============================================================================================

    /**
     * Each enforced toggle is consulted by the door that enforces it, measured over
     * COMMENT-STRIPPED source.
     *
     * KEYED ON THE CONSTANT, NOT ON THE COLUMN VALUE, and the first version of this test was keyed
     * on the value and FAILED against the finished fix -- which is the point. `manage_stock`
     * appears nowhere in `OrderRefundController` any more; the literal lives once, in
     * `RefundPolicy`, and the doors name the constant. A census keyed on the string would have had
     * to be satisfied by a comment, which is exactly the failure mode this project has recorded
     * nine times.
     *
     * Comment-stripped all the same, because both controllers now carry long comments naming all
     * three toggles, written while fixing this row.
     */
    public function test_the_two_enforceable_toggles_are_consulted_by_the_doors_that_enforce_them(): void
    {
        $refundController = $this->sourceWithoutComments('app/Http/Controllers/OrderRefundController.php');
        $storefront = $this->sourceWithoutComments('app/Http/Controllers/AccountProfileController.php');

        $this->assertStringContainsString(
            'RefundPolicy::MANAGE_STOCK',
            $refundController,
            'RefundStock() must consult the store\'s manage_stock refund setting; before GAP-140 it '
            . 'gated only the button in order/refund_details.blade.php.'
        );

        $this->assertStringContainsString(
            'RefundPolicy::ATTACHMENT',
            $storefront,
            'The storefront refund request must consult the store\'s attachment refund setting; '
            . 'before GAP-140 it gated only the file input in front_end/pages/refund_order.blade.php.'
        );
    }

    /**
     * THE GATE MUST READ THE NAME THE FORM WRITES, and that is asserted against the form itself
     * rather than against a list I typed twice.
     *
     * A constant spelled `manage-stock`, or a settings form renamed to post `restock`, would leave
     * both gates permanently closed with every test above still green -- the gate would ask about
     * a row that never exists, and default-deny would answer false for ever. Nothing else in this
     * file can see that, because every fixture here writes the row through the constant.
     */
    public function test_each_enforced_constant_is_a_name_the_settings_form_actually_posts(): void
    {
        $form = $this->sourceWithoutComments('resources/views/setting/refund_setting.blade.php');

        foreach ([
            \App\Services\RefundPolicy::MANAGE_STOCK,
            \App\Services\RefundPolicy::ATTACHMENT,
        ] as $name) {
            $this->assertStringContainsString(
                'name="' . $name . '"',
                $form,
                'resources/views/setting/refund_setting.blade.php posts no field called ' . $name
                . ', so the gate reading it can never be satisfied.'
            );
        }
    }

    /**
     * THE DELIBERATE NON-ENFORCEMENT, PINNED. `shiping_deduct` decides whether the delivery charge
     * is folded into the amount refunded, and the amount reaches `RefundAmonut` as one
     * client-typed figure with no breakdown. Enforcing it would change a refund AMOUNT, which is
     * an owner decision, so it is reported and not changed.
     *
     * If this assertion ever fails, somebody has enforced it: read GAP-140's row first.
     */
    public function test_the_shipping_deduction_toggle_is_still_read_by_no_php_writer(): void
    {
        foreach ([
            'app/Http/Controllers/OrderRefundController.php',
            'app/Http/Controllers/AccountProfileController.php',
            'app/Services/RefundPolicy.php',
        ] as $file) {
            $this->assertStringNotContainsString(
                'shiping_deduct',
                $this->sourceWithoutComments($file),
                $file . ': shiping_deduct decides a refund AMOUNT and the server has no breakdown to '
                . 'enforce it with. See GAP-140 before wiring it up.'
            );
        }
    }

    // =============================================================================================
    //  P4-F086 -- manage_stock, ENFORCED
    // =============================================================================================

    /**
     * The merchant switched Manage Stock OFF and the server restored the goods anyway.
     *
     * Both halves are asserted: the refusal AND the stock. A status assertion on its own passes if
     * the endpoint 500s for everybody.
     */
    public function test_the_restock_door_refuses_when_the_store_turned_manage_stock_off(): void
    {
        [$merchant, $store] = $this->tenant();
        $merchant = $this->asMerchantWithTheRefundPermission($merchant);
        $this->refundSetting($store, $merchant, 'manage_stock', 0);

        $customer = $this->shopper($store);
        $productId = $this->productIn($store, 10);
        $order = $this->order($store, $customer, $productId);
        $refundId = $this->handRefund($order, $store, OrderRefundController::STATE_ACCEPT, $productId);

        $this->actingAs($merchant, 'web');

        $response = $this->restock($refundId);

        $this->assertSame(
            403,
            $response->getStatusCode(),
            'A store with manage_stock off must be refused the restock door; got '
            . $response->getStatusCode() . ' ' . $response->getContent()
        );

        $this->assertSame(
            10,
            $this->stockOf($productId),
            'The refused restock still credited stock, which is the defect itself.'
        );

        $this->assertSame(
            0,
            DB::table('order_notes')
                ->where('note_type', OrderRefundController::stockRestoredNoteType($refundId))
                ->count(),
            'A refused restock must not stamp the idempotency marker: it would block a legitimate '
            . 'credit for ever once the setting is switched on.'
        );
    }

    /**
     * NEVER CONFIGURED IS THE SAME CLAIM AS CONFIGURED OFF, because the settings screen renders an
     * absent row as an OFF switch and nothing seeds the table. This is the case that changes
     * behaviour for the largest number of merchants, so it is a case of its own.
     */
    public function test_the_restock_door_refuses_a_store_that_never_configured_refund_settings(): void
    {
        [$merchant, $store] = $this->tenant();
        $merchant = $this->asMerchantWithTheRefundPermission($merchant);

        $this->assertSame(
            0,
            DB::table('order_refund_settings')->where('store_id', $store->id)->count(),
            'Premise: this store has no refund-policy rows at all.'
        );

        $customer = $this->shopper($store);
        $productId = $this->productIn($store, 10);
        $order = $this->order($store, $customer, $productId);
        $refundId = $this->handRefund($order, $store, OrderRefundController::STATE_ACCEPT, $productId);

        $this->actingAs($merchant, 'web');

        $response = $this->restock($refundId);

        $this->assertSame(403, $response->getStatusCode(), $response->getContent());
        $this->assertSame(10, $this->stockOf($productId));
    }

    /**
     * THE CONTROL. Without it every assertion above passes with the door bricked shut, which is
     * the commonest way a policy test passes for the wrong reason.
     */
    public function test_the_restock_door_credits_the_stock_when_the_store_turned_manage_stock_on(): void
    {
        [$merchant, $store] = $this->tenant();
        $merchant = $this->asMerchantWithTheRefundPermission($merchant);
        $this->refundSetting($store, $merchant, 'manage_stock', 1);

        $customer = $this->shopper($store);
        $productId = $this->productIn($store, 10);
        $order = $this->order($store, $customer, $productId);
        $refundId = $this->handRefund($order, $store, OrderRefundController::STATE_ACCEPT, $productId);

        $this->actingAs($merchant, 'web');

        $response = $this->restock($refundId);

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'A store with manage_stock ON must still be able to restock: ' . $response->getContent()
        );

        $this->assertSame(
            11,
            $this->stockOf($productId),
            'Premise: with the policy on, the one refunded unit comes back.'
        );
    }

    /**
     * THE REFUSAL MUST NOT LEAK. Ownership and state are decided BEFORE the policy, so a merchant
     * naming another tenant's refund id still gets the uniform 404 and learns nothing about
     * whether that store has the toggle on.
     */
    public function test_a_foreign_refund_id_still_answers_404_whatever_the_policy_says(): void
    {
        [$owner, $theirStore] = $this->tenant();
        $this->refundSetting($theirStore, $owner, 'manage_stock', 1);
        $customer = $this->shopper($theirStore);
        $productId = $this->productIn($theirStore, 10);
        $order = $this->order($theirStore, $customer, $productId);
        $refundId = $this->handRefund($order, $theirStore, OrderRefundController::STATE_ACCEPT, $productId);

        [$attacker] = $this->tenant();
        $attacker = $this->asMerchantWithTheRefundPermission($attacker);
        $this->actingAs($attacker, 'web');

        $response = $this->restock($refundId);

        $this->assertSame(
            404,
            $response->getStatusCode(),
            'A refund outside the caller\'s tenant must answer 404, not a policy refusal: '
            . $response->getContent()
        );

        $this->assertSame(10, $this->stockOf($productId));
    }

    /**
     * A refund that is not yet accepted is refused for its STATE, not for the policy -- the order
     * of the two checks, asserted rather than assumed, because a policy refusal placed first would
     * hide the state machine.
     */
    public function test_an_unaccepted_refund_is_still_refused_for_its_state(): void
    {
        [$merchant, $store] = $this->tenant();
        $merchant = $this->asMerchantWithTheRefundPermission($merchant);
        $this->refundSetting($store, $merchant, 'manage_stock', 1);

        $customer = $this->shopper($store);
        $productId = $this->productIn($store, 10);
        $order = $this->order($store, $customer, $productId);
        $refundId = $this->handRefund($order, $store, OrderRefundController::STATE_PROCESSING, $productId);

        $this->actingAs($merchant, 'web');

        $response = $this->restock($refundId);

        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
        $this->assertStringContainsString('accepted', (string) $response->getContent());
    }

    // =============================================================================================
    //  P4-F086 -- attachment, ENFORCED
    // =============================================================================================

    /**
     * The merchant switched Attachments OFF, the storefront stopped rendering the file input, and
     * the server went on accepting and storing files posted to it.
     *
     * The refund itself must still be RECORDED: this is an intake policy, not a reason to lose a
     * shopper's return. Both are asserted.
     */
    public function test_a_refund_request_stores_no_attachment_when_the_store_turned_attachments_off(): void
    {
        [$merchant, $store] = $this->tenant();
        $this->refundSetting($store, $merchant, 'attachment', 0);

        $customer = $this->shopper($store);
        $productId = $this->productIn($store, 10);
        $order = $this->order($store, $customer, $productId);

        $this->postRefundRequest($store, $customer, $order, $productId, [$this->upload('proof.png', $this->png())]);

        $this->assertSame(
            [],
            $this->stored(),
            'A shopper stored a file through the refund door of a store whose merchant switched '
            . 'attachments off: ' . json_encode($this->stored())
        );

        $row = DB::table('order_refunds')->where('order_id', $order->id)->first();

        $this->assertNotNull($row, 'The refund request itself must still be recorded.');
        $this->assertEmpty(
            json_decode((string) ($row->attachments ?? ''), true) ?: [],
            'order_refunds.attachments must be empty when the store accepts no attachments, got '
            . var_export($row->attachments ?? null, true)
        );
    }

    /** THE CONTROL: with the policy on, the same request stores the file. */
    public function test_a_refund_request_stores_an_attachment_when_the_store_turned_attachments_on(): void
    {
        [$merchant, $store] = $this->tenant();
        $this->refundSetting($store, $merchant, 'attachment', 1);

        $customer = $this->shopper($store);
        $productId = $this->productIn($store, 10);
        $order = $this->order($store, $customer, $productId);

        $this->postRefundRequest($store, $customer, $order, $productId, [$this->upload('proof.png', $this->png())]);

        $this->assertCount(
            1,
            $this->stored(),
            'Premise: with the policy ON the refund door stores a PNG. Files: '
            . json_encode($this->stored()) . ' Error: ' . json_encode(session('error'))
        );

        $row = DB::table('order_refunds')->where('order_id', $order->id)->first();

        $this->assertCount(
            1,
            json_decode((string) $row->attachments, true) ?: [],
            'order_refunds.attachments must name the stored file.'
        );
    }

    /** Never configured reads as off here too, for the same reason it does at the restock door. */
    public function test_a_refund_request_stores_no_attachment_for_a_store_that_never_configured_refund_settings(): void
    {
        [, $store] = $this->tenant();

        $this->assertSame(
            0,
            DB::table('order_refund_settings')->where('store_id', $store->id)->count(),
            'Premise: this store has no refund-policy rows at all.'
        );

        $customer = $this->shopper($store);
        $productId = $this->productIn($store, 10);
        $order = $this->order($store, $customer, $productId);

        $this->postRefundRequest($store, $customer, $order, $productId, [$this->upload('proof.png', $this->png())]);

        $this->assertSame([], $this->stored(), json_encode($this->stored()));
        $this->assertNotNull(
            DB::table('order_refunds')->where('order_id', $order->id)->first(),
            'The refund request itself must still be recorded.'
        );
    }

    // =============================================================================================
    //  P4-F089 -- the operator is locked out of the refund list
    // =============================================================================================

    /**
     * `index()` gated on `isAbleTo('Manage Order Refund Request')` with NO operator limb, while
     * its five mutating siblings share a predicate that tests the operator FIRST. `isAbleTo()`
     * alone refuses the platform operator, so the operator could accept, reject, restock and pay
     * out a refund and could not open the list of them.
     */
    public function test_the_platform_operator_can_open_the_refund_list(): void
    {
        [, $store] = $this->tenant();
        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);

        $this->assertFalse(
            $operator->isAbleTo('Manage Order Refund Request'),
            'Premise: isAbleTo() alone refuses the platform operator, which is why the limb is needed.'
        );

        $response = $this->actingAs($operator, 'web')->get('/refund-request');

        $this->assertNotSame(
            302,
            $response->getStatusCode(),
            'The platform operator was redirected away from the refund list: '
            . json_encode(session('error'))
        );
        $this->assertSame(200, $response->getStatusCode());
    }

    /** THE CONTROL on the other side: the gate still refuses somebody who holds nothing. */
    public function test_a_staff_member_without_the_permission_still_cannot_open_the_refund_list(): void
    {
        [$merchant] = $this->tenant();
        $staff = $this->makeStaff($merchant);

        $response = $this->actingAs($staff, 'web')->get('/refund-request');

        $this->assertSame(
            302,
            $response->getStatusCode(),
            'A staff member with no refund permission must still be refused the list.'
        );
    }

    /** And the merchant who holds the permission still gets in. */
    public function test_a_merchant_holding_the_permission_can_open_the_refund_list(): void
    {
        [$merchant] = $this->tenant();
        $merchant = $this->asMerchantWithTheRefundPermission($merchant);

        $response = $this->actingAs($merchant, 'web')->get('/refund-request');

        $this->assertSame(200, $response->getStatusCode());
    }

    // =============================================================================================
    //  P4-F087 -- actor attribution
    // =============================================================================================

    /**
     * Four state-changing mutators recorded nobody. `audit_logs` is the mechanism this application
     * already uses for exactly this (18 call sites before this change) and needs no migration.
     *
     * `updateFinalPrice` is deliberately absent from this set and that is a measurement, not an
     * omission: it reads `$request->finalPrice`, formats it and returns it. It persists NOTHING,
     * so there is no actor to attribute to a change that did not happen.
     */
    public function test_accepting_a_refund_records_who_accepted_it(): void
    {
        [$merchant, $store] = $this->tenant();
        $merchant = $this->asMerchantWithTheRefundPermission($merchant);
        $customer = $this->shopper($store);
        $productId = $this->productIn($store, 10);
        $order = $this->order($store, $customer, $productId);
        $refundId = $this->handRefund($order, $store, OrderRefundController::STATE_PROCESSING, $productId);

        $this->actingAs($merchant, 'web');
        $response = $this->accept($refundId);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $rows = $this->auditRows('refund.accepted');

        $this->assertCount(1, $rows, 'Accepting a refund must record exactly one audit row.');
        $this->assertSame($merchant->id, (int) $rows[0]['user_id'], 'The row must name the acting user.');
        $this->assertSame($store->id, (int) $rows[0]['store_id']);
        $this->assertSame($refundId, (int) $rows[0]['subject_id']);
    }

    public function test_rejecting_a_refund_records_who_rejected_it(): void
    {
        [$merchant, $store] = $this->tenant();
        $merchant = $this->asMerchantWithTheRefundPermission($merchant);
        $customer = $this->shopper($store);
        $productId = $this->productIn($store, 10);
        $order = $this->order($store, $customer, $productId);
        $refundId = $this->handRefund($order, $store, OrderRefundController::STATE_PROCESSING, $productId);

        $this->actingAs($merchant, 'web');
        $response = $this->reject($refundId);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $rows = $this->auditRows('refund.rejected');

        $this->assertCount(1, $rows, 'Rejecting a refund must record exactly one audit row.');
        $this->assertSame($merchant->id, (int) $rows[0]['user_id']);
        $this->assertSame($refundId, (int) $rows[0]['subject_id']);
    }

    public function test_restocking_a_refund_records_who_restocked_it(): void
    {
        [$merchant, $store] = $this->tenant();
        $merchant = $this->asMerchantWithTheRefundPermission($merchant);
        $this->refundSetting($store, $merchant, 'manage_stock', 1);
        $customer = $this->shopper($store);
        $productId = $this->productIn($store, 10);
        $order = $this->order($store, $customer, $productId);
        $refundId = $this->handRefund($order, $store, OrderRefundController::STATE_ACCEPT, $productId);

        $this->actingAs($merchant, 'web');
        $response = $this->restock($refundId);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $rows = $this->auditRows('refund.stock_restored');

        $this->assertCount(1, $rows, 'Restocking a refund must record exactly one audit row.');
        $this->assertSame($merchant->id, (int) $rows[0]['user_id']);
        $this->assertSame($refundId, (int) $rows[0]['subject_id']);
    }

    public function test_paying_out_a_refund_records_who_paid_it(): void
    {
        [$merchant, $store] = $this->tenant();
        $merchant = $this->asMerchantWithTheRefundPermission($merchant);
        $customer = $this->shopper($store);
        $productId = $this->productIn($store, 10);
        $order = $this->order($store, $customer, $productId);
        $refundId = $this->handRefund($order, $store, OrderRefundController::STATE_ACCEPT, $productId);

        $this->actingAs($merchant, 'web');
        $response = $this->payOut($order, $refundId);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $rows = $this->auditRows('refund.settled');

        $this->assertCount(1, $rows, 'Paying out a refund must record exactly one audit row.');
        $this->assertSame($merchant->id, (int) $rows[0]['user_id']);
        $this->assertSame($refundId, (int) $rows[0]['subject_id']);
    }

    /**
     * THE OPERATOR IS A REAL PRINCIPAL HERE AND IT IS THE ONE THE ATTRIBUTION MUST NOT LOSE.
     * `AuditAttribution` files an operator's row under the SUBJECT's store rather than under the
     * fabricated store 1 that `getCurrentStore()` hands them.
     */
    public function test_an_operator_accepting_a_refund_is_recorded_as_the_actor(): void
    {
        [, $store] = $this->tenant();
        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);
        $customer = $this->shopper($store);
        $productId = $this->productIn($store, 10);
        $order = $this->order($store, $customer, $productId);
        $refundId = $this->handRefund($order, $store, OrderRefundController::STATE_PROCESSING, $productId);

        $this->actingAs($operator, 'web');
        $response = $this->accept($refundId);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());

        $rows = $this->auditRows('refund.accepted');

        $this->assertCount(1, $rows);
        $this->assertSame($operator->id, (int) $rows[0]['user_id']);
        $this->assertSame(
            $store->id,
            (int) $rows[0]['store_id'],
            'The operator\'s row must be filed under the refund\'s own store, not store 1.'
        );
    }

    /**
     * A REFUSAL MUST NOT SIGN AN AUDIT ROW. A row describing an action that did not happen is
     * worse than no row at all -- the same reasoning `markRefundedLinesRestored()` records for the
     * stock marker.
     */
    public function test_a_refused_mutation_records_no_actor_row(): void
    {
        [$merchant, $store] = $this->tenant();
        $merchant = $this->asMerchantWithTheRefundPermission($merchant);
        $this->refundSetting($store, $merchant, 'manage_stock', 0);
        $customer = $this->shopper($store);
        $productId = $this->productIn($store, 10);
        $order = $this->order($store, $customer, $productId);
        $refundId = $this->handRefund($order, $store, OrderRefundController::STATE_ACCEPT, $productId);

        $this->actingAs($merchant, 'web');

        $this->assertSame(403, $this->restock($refundId)->getStatusCode());
        $this->assertSame([], $this->auditRows(), 'A refused restock signed an audit row.');

        // And a refusal from the state machine, which is a different limb.
        $this->assertSame(422, $this->accept($refundId)->getStatusCode());
        $this->assertSame([], $this->auditRows(), 'A refused transition signed an audit row.');
    }
}
