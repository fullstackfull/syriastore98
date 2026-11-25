<?php

namespace Tests\Feature\Saas;

use App\Models\Plan;
use App\Models\PlanOrder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-192 / SAAS-007 — THE PLATFORM REVENUE SCREEN, ITS DOOR, AND THE SEPARATION ITSELF.
 * =================================================================================================
 *
 * TWO HALVES. The door is the whole control: this screen reports what every merchant on the
 * platform pays, which is commercial information about third parties, and neither `User` nor
 * `PlanOrder` is in `config/tenancy.php`'s `scoped_models`, so nothing narrows the read for a
 * principal who gets in. The differentiating test is
 * `test_a_staff_member_holding_manage_reports_is_still_refused`, which asserts the premise first —
 * the same principal really can open `/analytics` — so the refusal cannot be passing because the
 * permission was never granted.
 *
 * The second half is the REQUIREMENT: that platform revenue and merchant sales stay apart. A
 * screen could pass every door test and still quietly add a shopper's payment into an MRR, so
 * `test_a_merchants_sale_does_not_appear_on_the_platform_revenue_screen` drives a paid store order
 * of 5,000 through and asserts the figure does not move.
 */
final class PlatformRevenueScreenTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const URI = '/platform/revenue';

    private const PERMISSION = 'Manage Reports';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    private function makePlan(array $attributes = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Plan ' . $this->uniqueFixtureRef(),
            'price' => 100,
            'duration' => 'Month',
            'max_stores' => 1,
            'max_products' => 10,
            'max_users' => 2,
            'storage_limit' => 100,
            'themes' => '',
            'trial' => 0,
            'trial_days' => 0,
        ], $attributes));
    }

    private function planOrder(User $user, Plan $plan, array $attributes = []): void
    {
        $order = new PlanOrder();

        $order->forceFill(array_merge([
            'order_id' => 'PO-' . $this->uniqueFixtureRef(),
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'price' => 10,
            'price_currency' => 'USD',
            'txn_id' => 'TXN-' . $this->uniqueFixtureRef(),
            'payment_type' => 'Stripe',
            'payment_status' => 'succeeded',
            'is_refund' => 0,
        ], $attributes))->save();
    }

    /** Figures are wrapped in bidi isolates; strip them before matching rendered text. */
    private function plain(string $html): string
    {
        return str_replace(["\u{2066}", "\u{2069}"], '', $html);
    }

    /** The slice of `$html` between two markers, closing marker sought FROM the opening one. */
    private function between(string $html, string $open, string $close): string
    {
        $from = strpos($html, $open);

        if ($from === false) {
            return '';
        }

        $to = strpos($html, $close, $from);

        return $to === false ? '' : substr($html, $from, $to - $from);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The door
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_signed_out_visitor_cannot_open_it(): void
    {
        $response = $this->get(self::URI);

        $this->assertTrue($response->isRedirect(),
            'the platform revenue screen was served to a guest (status ' . $response->status() . ')');
        $this->assertNotSame(401, $response->status(),
            'a 401 forces a logout in the shipped Flutter client; `auth` runs first and redirects');
    }

    public function test_a_merchant_cannot_open_it(): void
    {
        [$merchant] = $this->makeTenant();

        $this->actingAs($merchant->fresh())->get(self::URI)->assertStatus(403);
    }

    public function test_a_staff_member_cannot_open_it(): void
    {
        [$merchant] = $this->makeTenant();

        $this->actingAs($this->makeStaff($merchant)->fresh())->get(self::URI)->assertStatus(403);
    }

    public function test_a_staff_member_holding_manage_reports_is_still_refused(): void
    {
        [$merchant] = $this->makeTenant();
        $holder = $this->makeStaff($merchant);
        $this->grantPermission($holder, self::PERMISSION);

        $holder = $holder->fresh();

        $this->assertTrue($holder->isAbleTo(self::PERMISSION),
            'Premise: this principal really does hold ' . self::PERMISSION . '.');
        $this->actingAs($holder)->get('/analytics')->assertStatus(200);

        $this->actingAs($holder)->get(self::URI)->assertStatus(403);
    }

    /**
     * A MERCHANT WHO IS ALSO A PAYING SUBSCRIBER IS STILL REFUSED.
     *
     * Not a restatement of the test above: this is the principal with the strongest intuitive
     * claim to the screen — it is partly their own money — and the answer is still no, because the
     * screen reports every OTHER merchant's payments too.
     */
    public function test_a_paying_merchant_is_refused_their_own_platform_revenue_screen(): void
    {
        $plan = $this->makePlan();
        [$merchant] = $this->makeTenant();
        $merchant->plan_id = $plan->id;
        $merchant->plan_expire_date = CarbonImmutable::now()->addMonth()->format('Y-m-d');
        $merchant->save();
        $this->planOrder($merchant, $plan);

        $this->actingAs($merchant->fresh())->get(self::URI)->assertStatus(403);
    }

    public function test_the_platform_operator_can_open_it(): void
    {
        $this->actingAs($this->makePlatformAdmin()->fresh())->get(self::URI)->assertStatus(200);
    }

    public function test_the_operator_needs_no_store_selected(): void
    {
        $operator = $this->makePlatformAdmin(['current_store' => null]);

        $this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * What the page says
     * ---------------------------------------------------------------------------------------------
     */

    public function test_the_page_prints_the_recurring_figures(): void
    {
        $monthly = $this->makePlan(['price' => 100, 'duration' => 'Month']);
        $yearly = $this->makePlan(['price' => 1200, 'duration' => 'Year']);

        $this->makeMerchant([
            'plan_id' => $monthly->id,
            'plan_expire_date' => CarbonImmutable::now()->addMonth()->format('Y-m-d'),
        ]);
        $this->makeMerchant([
            'plan_id' => $yearly->id,
            'plan_expire_date' => CarbonImmutable::now()->addYear()->format('Y-m-d'),
        ]);

        $html = $this->plain($this->actingAs($this->makePlatformAdmin()->fresh())
            ->get(self::URI)->assertStatus(200)->getContent());

        /*
         * A DIGIT BOUNDARY BEFORE THE NUMBER, because a page assertion cannot be an equality and
         * `1,200.00 USD` CONTAINS `200.00 USD`. A mutation on the figures side proved that exact
         * trap in the sibling test: an inflated figure of `2,010.00 USD` satisfied a
         * `assertStringContainsString('10.00', ...)` and the mutant survived.
         */
        $this->assertMatchesRegularExpression('/(?<![\d,])200\.00 USD/', $html,
            'MRR of 100 + 1200/12 = 200 is not on the page');
        $this->assertMatchesRegularExpression('/(?<![\d,])2,400\.00 USD/', $html,
            'ARR of 200 x 12 = 2,400 is not on the page');
    }

    /**
     * THE REQUIREMENT, AT THE SCREEN. A shopper's payment to a merchant must not move any figure
     * on the platform's own revenue screen.
     *
     * 5,000 is chosen to be unmistakable: if it reaches this page at all, it is because something
     * read `store_order_financials` or `orders`, which this screen must never do.
     */
    public function test_a_merchants_sale_does_not_appear_on_the_platform_revenue_screen(): void
    {
        $plan = $this->makePlan(['price' => 100, 'duration' => 'Month']);
        [$merchant, $store] = $this->makeTenant();
        $merchant->plan_id = $plan->id;
        $merchant->plan_expire_date = CarbonImmutable::now()->addMonth()->format('Y-m-d');
        $merchant->save();

        $order = $this->makeOrder($store, ['order_date' => CarbonImmutable::now()->subDay()]);

        DB::table('store_order_financials')->where('order_id', $order->id)->update([
            'payment_status' => 'paid',
            'currency_code' => 'USD',
            'paid_amount' => 5000.00,
            'remaining_amount' => 0.00,
            'paid_at' => CarbonImmutable::now()->subDay(),
        ]);

        $html = $this->plain($this->actingAs($this->makePlatformAdmin()->fresh())
            ->get(self::URI)->assertStatus(200)->getContent());

        $this->assertMatchesRegularExpression('/(?<![\d,])100\.00 USD/', $html,
            'premise: the platform\'s own MRR of 100 should be on the page');
        $this->assertStringNotContainsString('5,000.00', $html,
            'a merchant\'s sale reached the PLATFORM revenue screen. Separating the two is the '
            . 'whole of SAAS-007.');
    }

    /** The screen says in words what it is not, and points at where merchant sales live. */
    public function test_the_page_says_that_merchant_sales_are_a_different_figure(): void
    {
        $html = $this->actingAs($this->makePlatformAdmin()->fresh())
            ->get(self::URI)->assertStatus(200)->getContent();

        $this->assertStringContainsString('NOT what shoppers pay merchants', $html,
            'the screen does not distinguish itself from merchant sales, which is the requirement');
        $this->assertStringContainsString(route('platform.analytics'), $html,
            'the screen does not say where merchant sales are reported instead');
    }

    /**
     * EVERY SUBSCRIPTION KIND IS ON THE PAGE WITH ITS COUNT.
     *
     * This is the slice's refusal to invent a commercial policy, rendered: an owner who disagrees
     * with "a lapsed plan is not revenue" can see how many merchants that decision moves.
     */
    public function test_the_page_counts_the_subscriptions_that_are_not_in_the_figure(): void
    {
        $lifetime = $this->makePlan(['price' => 999, 'duration' => 'Unlimited']);
        $monthly = $this->makePlan(['price' => 100, 'duration' => 'Month']);

        $this->makeMerchant(['plan_id' => $lifetime->id, 'plan_expire_date' => null]);
        $this->makeMerchant(['plan_id' => $monthly->id, 'plan_expire_date' => '2020-01-01']);

        $html = $this->actingAs($this->makePlatformAdmin()->fresh())
            ->get(self::URI)->assertStatus(200)->getContent();

        $kinds = $this->between($html, 'Every subscription, by kind', 'Plan payments received');

        $this->assertNotSame('', $kinds, 'premise: the subscription-kind table was not found');
        $this->assertStringContainsString('Lifetime subscriptions', $kinds);
        $this->assertStringContainsString('Lapsed', $kinds);
        $this->assertStringContainsString('In trial', $kinds);
        $this->assertStringContainsString('Plan no longer exists', $kinds);
    }

    /** A query string cannot narrow or widen a platform-wide money figure. */
    public function test_request_input_changes_nothing(): void
    {
        $plan = $this->makePlan(['price' => 100, 'duration' => 'Month']);
        $this->makeMerchant([
            'plan_id' => $plan->id,
            'plan_expire_date' => CarbonImmutable::now()->addMonth()->format('Y-m-d'),
        ]);

        $operator = $this->makePlatformAdmin();

        $plain = $this->plain($this->actingAs($operator->fresh())->get(self::URI)->getContent());
        $probed = $this->plain($this->actingAs($operator->fresh())
            ->get(self::URI . '?store_id=1&plan_id=1&from=2030-01-01')->getContent());

        $this->assertMatchesRegularExpression('/(?<![\d,])100\.00 USD/', $probed,
            'request input changed what the platform revenue screen reported');
        $this->assertSame(
            preg_match_all('/(?<![\d,])100\.00 USD/', $plain),
            preg_match_all('/(?<![\d,])100\.00 USD/', $probed),
            'request input changed how many times the figure appears'
        );
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Both language directions
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * BILINGUAL, DRIVEN THE WAY THE APPLICATION SETS THE LOCALE: through the principal's own
     * `language` column, because `SetLocate` re-resolves the locale inside the request and
     * `app()->setLocale()` in a test does not survive it.
     *
     * AND THE AMOUNTS KEEP THEIR BIDI ISOLATES IN THE PAGE. In an RTL document the Unicode
     * bidirectional algorithm reorders a bare `1,234.56 USD`, and `MoneyText` records a measured
     * case where a minus sign moved to the end of a number and a loss was painted as a gain.
     */
    public function test_the_screen_renders_in_arabic_and_right_to_left(): void
    {
        $plan = $this->makePlan(['price' => 100, 'duration' => 'Month']);
        $this->makeMerchant([
            'plan_id' => $plan->id,
            'plan_expire_date' => CarbonImmutable::now()->addMonth()->format('Y-m-d'),
        ]);

        $operator = $this->makePlatformAdmin();
        $operator->language = 'ar';
        $operator->save();

        $html = $this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200)->getContent();

        $this->assertStringContainsString('dir="rtl"', $html,
            'premise failed: the document is not right-to-left for an Arabic operator');

        /* Scoped to the <h1>: this heading is printed twice (title and breadcrumb), so a
           whole-page assertion would be satisfied by the breadcrumb on the title's behalf. */
        $this->assertStringContainsString('إيرادات المنصة', $this->between($html, 'ss-page-title', '</h1>'),
            'the page heading rendered in English for an Arabic operator');
        $this->assertStringContainsString('الإيرادات المتكررة', $html,
            'the recurring-revenue heading rendered in English for an Arabic operator');
        $this->assertStringContainsString('الاشتراكات مدى الحياة', $html,
            'the subscription-kind table rendered in English for an Arabic operator');

        $this->assertStringContainsString("\u{2066}100.00", $html,
            'the amount reached the page without its bidi isolate, so in this RTL document the '
            . 'currency code and the digits are reordered by the bidirectional algorithm');
    }

    /** The English page is the control for the Arabic one: both directions, same run. */
    public function test_the_screen_renders_in_english_and_left_to_right(): void
    {
        $operator = $this->makePlatformAdmin(['language' => 'en']);

        $html = $this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200)->getContent();

        $this->assertStringContainsString('dir="ltr"', $html,
            'premise failed: the document is not left-to-right for an English operator');
        $this->assertStringContainsString('Platform revenue', $this->between($html, 'ss-page-title', '</h1>'));
        $this->assertStringContainsString('MRR (monthly recurring revenue)', $html);
        $this->assertStringContainsString('Lifetime subscriptions', $html);
    }
}
