<?php

namespace Tests\Feature\Merchant;

use App\Models\Order;
use App\Models\OrderRefund;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ReturnInspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Security\SecurityTestCase;

/**
 * RET-002 — THE MERCHANT CAN ACTUALLY REACH THE CONDITION CONTROL, IN BOTH DIRECTIONS.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHY A RENDER TEST AND NOT A SOURCE CENSUS
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `App\Services\ReturnInspection`'s gate is consulted by three restock doors and written by
 * `OrderController::order_return_inspection()`. Both are tested. Neither proves a merchant can
 * reach the control — and **F237 is this repository's own cautionary case**: a mechanism recorded
 * as DONE whose production callers never existed, so a guard that read as working was inert for
 * every real order. A control nobody can see is the same failure one layer up.
 *
 * A source census would not do, for two reasons this project has paid for:
 *
 *   - **A census that reads comments can be satisfied by prose about the thing it measures.**
 *     Eight instances, in three syntaxes. A rendered page cannot be satisfied by a comment.
 *   - **A layout that `@yield`s never prints a `$slot`** — HTTP 200, perfect chrome, empty content
 *     area, invisible to a status assertion. Only a render shows the markup is really there.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * BOTH DIRECTIONS, because the admin is bilingual Arabic (RTL) and English (LTR)
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Every label here goes through `__()`, so the Arabic run is what shows a hardcoded English string
 * that slipped in. The run does NOT assert a particular translation: the Arabic catalogue is
 * another workstream's, and asserting a specific string here would fail on the day it lands. It
 * asserts the CONTROL and its association, which must be present whichever direction the page is
 * laid out in.
 *
 * **CONTRAST IS NOT MEASURED HERE AND THIS FILE DOES NOT CLAIM IT.** The control reuses
 * `form-select`, `form-label`, `x-ss.field-help` and `ss-btn ss-btn--secondary ss-btn--sm`, all
 * already on this screen, and introduces no colour of its own — so it inherits whatever the design
 * system gives those classes. That is an argument, not a measurement, and the harness is the
 * compositing text-node walk with `APP_DEBUG` off. Carried as HUMAN ACCEPTANCE in the report.
 */
class ReturnConditionControlRendersTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const STORE_A = 11;
    private const OPERATOR = 9;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(':memory:', config('database.connections.sqlite.database'));

        /*
         * CLAUDE.md, MEASURED HERE RATHER THAN TAKEN ON TRUST: a first version of this file
         * extended `Tests\TestCase` and every case answered `302 http://localhost/update`.
         * `XSS::handle()`'s updater gate sends a `super admin` to `LaravelUpdater::welcome` when
         * on-disk migrations are not recorded as run, and under `RefreshDatabase` none of the
         * module ones are -- so the request never reached the handler and a status assertion would
         * have photographed a redirect. `satisfyUpdaterGate()` records them, touching only the
         * framework's `migrations` bookkeeping table.
         */
        $this->satisfyUpdaterGate();

        DB::table('users')->insert([
            'id' => self::OPERATOR, 'name' => 'Operator', 'email' => 'op@example.test',
            'type' => \App\Policies\UserPolicy::PLATFORM_ADMIN, 'password' => bcrypt('secret'),
            'current_store' => self::STORE_A, 'created_at' => now(), 'updated_at' => now(),
        ]);
        /* The real columns, read from `create_stores_table` rather than remembered: `name`,
           `email`, `theme_id` and `slug` are all NOT NULL with no default. A hand-invented
           `store_name` is the scaffold error CLAUDE.md records being copied into four suites. */
        DB::table('stores')->insert([
            'id' => self::STORE_A, 'name' => 'Store A', 'email' => 'store-a@example.test',
            'theme_id' => '1', 'slug' => 'store-a',
            'created_by' => self::OPERATOR, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('customers')->insert([
            'id' => 1, 'first_name' => 'Return', 'last_name' => 'Buyer',
            'email' => 'buyer@example.test', 'store_id' => self::STORE_A,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Auth::login(User::withoutGlobalScopes()->find(self::OPERATOR));
    }

    protected function tearDown(): void
    {
        Auth::logout();
        Cache::flush();

        parent::tearDown();
    }

    /** @return array<int, array{0: string}> */
    public static function directions(): array
    {
        return [['en'], ['ar']];
    }

    #[DataProvider('directions')]
    public function test_the_condition_control_renders_for_every_refunded_line(string $locale): void
    {
        app()->setLocale($locale);

        $product = $this->makeProduct();
        $order = $this->orderWithLines([
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 2, 'final_price' => 20, 'name' => 'Alpha'],
        ]);
        $this->makeRefund($order, [['product_refund_id' => $product->id, 'quantity' => 2, 'return_price' => '20']]);

        $html = $this->screen($order);

        $this->assertStringContainsString(
            'return_condition_' . $product->id,
            $html,
            "[{$locale}] the condition control must render for the refunded line. A merchant who "
                . 'cannot record a verdict leaves the RET-002 gate inert for every real order.'
        );
        $this->assertStringContainsString(
            'class="form-select form-select-sm return-condition-select"',
            $html,
            "[{$locale}] and it must be the select the screen's JS posts from"
        );
        $this->assertStringContainsString(
            'save-return-condition',
            $html,
            "[{$locale}] with the button that posts it"
        );

        /*
         * THE LABEL IS ASSOCIATED. `GAP-060` on this very screen was a caption carrying `for=""` —
         * an attribute that is present and names no control, so clicking the words toggled nothing
         * and no screen reader tied them to the input. A control with an unassociated label is a
         * control a keyboard or screen-reader user cannot reach.
         */
        $this->assertMatchesRegularExpression(
            '/<label[^>]*for="return_condition_' . $product->id . '"/',
            $html,
            "[{$locale}] the label must name the select, not carry an empty `for`"
        );
        $this->assertStringContainsString(
            'aria-describedby="return_condition_' . $product->id . '-help"',
            $html,
            "[{$locale}] and the guidance must be announced with it"
        );
    }

    #[DataProvider('directions')]
    public function test_every_verdict_in_the_vocabulary_is_offered_and_none_is_raw(string $locale): void
    {
        app()->setLocale($locale);

        $product = $this->makeProduct();
        $order = $this->orderWithLines([
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 1, 'final_price' => 10, 'name' => 'Alpha'],
        ]);
        $this->makeRefund($order, [['product_refund_id' => $product->id, 'quantity' => 1, 'return_price' => '10']]);

        $html = $this->screen($order);

        foreach (array_keys(ReturnInspection::VERDICTS) as $verdict) {
            $this->assertStringContainsString(
                'value="' . $verdict . '"',
                $html,
                "[{$locale}] every verdict the service accepts must be offered, or a merchant "
                    . 'cannot express a condition the gate understands'
            );
        }

        /*
         * AND NONE OF THEM IS SHOWN AS ITS RAW KEY. The select's `@default` arm prints the bare
         * verdict, which is the honest fallback for a vocabulary term nobody has worded — but it
         * must not be what a merchant actually sees for the three that exist. `quarantined` is the
         * one that would read worst.
         */
        $this->assertStringNotContainsString(
            '>quarantined<',
            $html,
            "[{$locale}] a verdict must reach the merchant as a sentence, not as its storage key"
        );
    }

    public function test_a_recorded_verdict_comes_back_selected(string $locale = 'en'): void
    {
        app()->setLocale($locale);

        $product = $this->makeProduct();
        $order = $this->orderWithLines([
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 1, 'final_price' => 10, 'name' => 'Alpha'],
        ]);
        $this->makeRefund($order, [['product_refund_id' => $product->id, 'quantity' => 1, 'return_price' => '10']]);

        app(ReturnInspection::class)
            ->record(self::STORE_A, $order->id, $product->id, 0, ReturnInspection::VERDICT_DAMAGED, self::OPERATOR);

        $html = $this->screen($order);

        /*
         * A screen that forgot the verdict would read as "not inspected yet" and invite the
         * merchant to record it again — and since `record()` upserts, they would never be told the
         * first one existed.
         */
        $this->assertMatchesRegularExpression(
            '/value="damaged"\s+selected/',
            $html,
            'the recorded verdict must come back selected'
        );
    }

    public function test_the_control_posts_the_variant_the_gate_will_read(): void
    {
        $product = $this->makeProduct(['variant_product' => 1]);
        $variant = $this->makeVariant($product->id);

        $order = $this->orderWithLines([
            ['product_id' => $product->id, 'variant_id' => $variant->id, 'qty' => 1, 'final_price' => 10, 'name' => 'Alpha'],
        ]);
        $this->makeRefund($order, [['product_refund_id' => $product->id, 'quantity' => 1, 'return_price' => '10']]);

        $html = $this->screen($order);

        /*
         * THE ONE THING ABOUT THIS CONTROL THAT COULD BE SILENTLY WRONG, AND THE REASON IT IS
         * TESTED SEPARATELY.
         *
         * The refund payload records NO variant id — `AccountProfileController` writes only
         * `product_refund_id`, `return_price` and `quantity` — so the variant has to come from the
         * ORDER's `product_json`, which is exactly where `RefundStock()` gets it
         * (`resolveVariantIdsFromOrder()`). If this control posted `variant_id = 0` for a variant
         * line, the verdict would be filed under a key the gate never reads: the merchant would see
         * "damaged" recorded and the goods would go back on sale anyway. A failure with no symptom
         * on either screen.
         */
        $this->assertStringContainsString(
            'data-variant-id="' . $variant->id . '"',
            $html,
            'the control must post the variant the ORDER records, not 0 — otherwise the verdict is '
                . 'filed under a key the restock gate never reads'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Fixture
    |--------------------------------------------------------------------------
    */

    /**
     * The refund-details screen, through the real route.
     *
     * `Crypt::encrypt($order->id)` is what `order/refund-action.blade.php` builds the link with, so
     * that is what is driven. (CLAUDE.md: `APP_KEY` is in git history, so this is an encoding
     * rather than a control — which is why the screen's ownership comes from `OrderRefund`'s store
     * scope and `findOrderInCurrentStore()`, not from the segment.)
     */
    private function screen(Order $order): string
    {
        $response = $this->get('/refund-request/' . Crypt::encrypt($order->id));

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'the refund-details screen must render. Landed: ' . $response->getStatusCode()
                . ' ' . ($response->headers->get('Location') ?? '')
        );

        $html = $response->getContent();

        /*
         * THE PREMISE, because `show()` wraps its whole body in `try { } catch` and answers a
         * REDIRECT on any throw — and a redirect that the assertion above would catch is not the
         * only failure shape: the WorkDo layout `@yield`s, so a view that rendered nothing would
         * still be HTTP 200 with perfect chrome. The items table's own heading is what proves the
         * content area is populated at all.
         */
        $this->assertStringContainsString(
            'return-condition-cell',
            $html,
            'the items table must have rendered its cells, or every assertion here is vacuous'
        );

        return $html;
    }

    private function makeProduct(array $attributes = []): Product
    {
        $product = new Product();
        $product->forceFill(array_merge([
            'name' => 'Alpha', 'track_stock' => 1, 'stock_order_status' => 'not_allow',
            'price' => 10, 'product_stock' => 4, 'variant_product' => 0,
            'store_id' => self::STORE_A, 'created_by' => self::OPERATOR,
        ], $attributes));
        $product->save();

        return $product;
    }

    private function makeVariant(int $productId): ProductVariant
    {
        $variant = new ProductVariant();
        $variant->forceFill([
            'product_id' => $productId, 'variant' => 'Red', 'sku' => 'sku-' . uniqid('', false),
            'price' => 10, 'stock' => 3, 'stock_order_status' => 'not_allow',
            'store_id' => self::STORE_A,
        ]);
        $variant->save();

        return $variant;
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function orderWithLines(array $lines): Order
    {
        $order = new Order();
        $order->forceFill([
            'product_order_id' => 'ORD-' . uniqid('', false), 'order_date' => now(),
            'customer_id' => 1, 'product_json' => json_encode($lines),
            'product_id' => implode(',', array_column($lines, 'product_id')),
            'final_price' => 20, 'product_price' => 20, 'delivery_price' => 0,
            'tax_price' => 0, 'coupon_price' => 0, 'payment_type' => 'cod',
            'payment_status' => 'Paid', 'delivered_status' => 1, 'store_id' => self::STORE_A,
        ]);
        $order->save();

        return $order;
    }

    private function makeRefund(Order $order, array $refundLines): OrderRefund
    {
        $refund = new OrderRefund();
        $refund->forceFill([
            'order_id' => $order->id, 'refund_status' => 'Accept', 'refund_reason' => 'Other',
            'product_refund_id' => json_encode($refundLines), 'product_refund_price' => 20,
            'refund_amount' => 0, 'store_id' => self::STORE_A,
        ]);
        $refund->save();

        return $refund;
    }
}
