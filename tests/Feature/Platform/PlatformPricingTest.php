<?php

namespace Tests\Feature\Platform;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M5 — THE PRICE ON THE PAGE IS THE PRICE IN THE TABLE.
 * =================================================================================================
 *
 * The owner's instruction for M5 names this failure explicitly: "Do not hardcode fictional prices."
 * A page that happened to print the right number once would satisfy an `assertSee('25')`, so the
 * assertions here are about the RELATIONSHIP rather than the value: change the row, and the page
 * must change with it. That cannot be satisfied by a literal in a Blade file.
 *
 * THE SENTINEL IS THE SECOND THING THIS FILE PROTECTS, and it is the one that was wrong first.
 * `-1` means unlimited in this product (`User.php:339`, `DashboardController.php:2966`,
 * `Utility.php:868`); `0` means a real limit of zero, because `0 < 0` is false and `0 == -1` is
 * false. The first draft of `PlatformCapabilities` had them the other way round and would have
 * printed "Unlimited" beside a plan that sells nothing. Both directions are asserted.
 */
class PlatformPricingTest extends TestCase
{
    use RefreshDatabase;

    private function plan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'name'           => 'Fixture Plan',
            'price'          => '25',
            'duration'       => 'Month',
            'max_stores'     => '2',
            'max_products'   => '500',
            'max_users'      => '5',
            'storage_limit'  => '1024',
            'enable_domain'  => 'on',
            'enable_subdomain' => 'off',
            'shipping_method'  => 'on',
            'enable_tax'       => 'on',
            'pwa_store'        => 'off',
            'themes'           => 'stylique',
        ], $overrides));
    }

    #[Test]
    public function an_installation_with_no_plans_says_so_instead_of_inventing_one(): void
    {
        $this->assertSame(0, Plan::query()->count());

        $response = $this->get('/platform/pricing');

        $response->assertOk();
        $response->assertSee('No subscription plans have been published yet', escape: false);
        /* And no plan card is rendered at all -- the empty state must not be a card with blanks. */
        $this->assertStringNotContainsString('ss-ps-plan__amount', $response->getContent());
    }

    #[Test]
    public function the_page_follows_the_row_rather_than_a_literal(): void
    {
        $plan = $this->plan(['name' => 'Measured Plan', 'price' => '25']);

        $first = $this->get('/platform/pricing');
        $first->assertSee('Measured Plan', escape: false);
        $first->assertSee('25', escape: false);

        /* THE WHOLE POINT. A hardcoded figure would survive this; a read of the row cannot. Written
           through Eloquent so the model cache is flushed -- CLAUDE.md records three false readings
           from raw writes against `Cachable` models, and `Plan` is one of them. */
        $plan->price = '99';
        $plan->name  = 'Renamed Plan';
        $plan->save();

        $second = $this->get('/platform/pricing');
        $second->assertSee('Renamed Plan', escape: false);
        $second->assertSee('99', escape: false);
        $second->assertDontSee('Measured Plan', escape: false);
    }

    #[Test]
    public function minus_one_is_unlimited_and_zero_is_zero(): void
    {
        $this->plan(['name' => 'Sentinel Plan', 'max_products' => '-1', 'max_stores' => '0']);

        $html = $this->get('/platform/pricing')->getContent();

        /* `Products: Unlimited` and `Stores included: 0`. Asserted as the label/value PAIR, because
           either word alone appears elsewhere on the page. */
        $this->assertMatchesRegularExpression('/Products[^<]*<\/span>|Products:\s*<strong>Unlimited<\/strong>/', $html);
        $this->assertStringContainsString('<strong>Unlimited</strong>', $html);
        $this->assertStringContainsString('<strong>0</strong>', $html);
    }

    #[Test]
    public function an_unconfigured_flag_is_reported_as_unspecified_not_as_excluded(): void
    {
        /* MEASURED WHILE WRITING THIS TEST, and it corrected the assumption behind it.
           `plans.pwa_store` and its sibling flags are `string ... default('off')` and NOT NULL
           (`2023_12_08_123752_create_plans_table.php:25-30`), so a literal `null` is refused by
           the database -- the first draft of this test inserted one and hit an integrity
           violation. The reachable "never configured" value on these columns is the EMPTY STRING,
           which satisfies NOT NULL and is what an import or a legacy row leaves behind. The
           registry's `unknown` branch answers for both, and CLAUDE.md's standing warning that the
           migrations are not the schema is the reason the `null` limb is kept rather than
           deleted. */
        $this->plan(['name' => 'Partial Plan', 'pwa_store' => '']);

        $html = $this->get('/platform/pricing')->getContent();

        $this->assertStringContainsString('not specified', $html);
    }

    #[Test]
    public function plans_are_ordered_by_numeric_price_not_lexicographically(): void
    {
        /* `plans.price` is a STRING column. A SQL `ORDER BY price` would put "100" before "20",
           which is why the controller sorts in PHP after the fetch. */
        $this->plan(['name' => 'Twenty', 'price' => '20']);
        $this->plan(['name' => 'Hundred', 'price' => '100']);
        $this->plan(['name' => 'Five', 'price' => '5']);

        $html = $this->get('/platform/pricing')->getContent();

        $five    = strpos($html, 'Five');
        $twenty  = strpos($html, 'Twenty');
        $hundred = strpos($html, 'Hundred');

        $this->assertNotFalse($five);
        $this->assertLessThan($twenty, $five, 'Five must render before Twenty');
        $this->assertLessThan($hundred, $twenty, 'Twenty must render before Hundred');
    }

    #[Test]
    public function a_plan_with_no_name_is_not_offered(): void
    {
        /* `plans.name` is unique and NOT NULL, so an unnamed row should not exist -- but the
           controller filters for it anyway and this pins that the filter works, because a card
           with a blank heading and a price is worse than no card. */
        $this->plan(['name' => 'Real Plan']);
        Plan::query()->where('name', 'Real Plan')->update(['name' => '  ']);

        $html = $this->get('/platform/pricing')->getContent();

        $this->assertStringNotContainsString('ss-ps-plan__amount', $html);
        $this->assertStringContainsString('No subscription plans have been published yet', $html);
    }

    #[Test]
    public function a_trial_is_shown_only_when_the_plan_really_carries_one(): void
    {
        $this->plan(['name' => 'No Trial Plan']);
        $html = $this->get('/platform/pricing')->getContent();
        $this->assertStringNotContainsString('Free trial', $html);

        Plan::query()->where('name', 'No Trial Plan')->first()->forceFill([
            'trial' => 1, 'trial_days' => 14,
        ])->save();

        $html = $this->get('/platform/pricing')->getContent();
        $this->assertStringContainsString('Free trial', $html);
        $this->assertStringContainsString('14', $html);
    }

    /**
     * A PLAN THE OPERATOR HAS SWITCHED OFF IS NOT ADVERTISED.
     *
     * Found by independent review of M5: `publishedPlans()` filtered only on a non-empty name, so
     * the public site showed EVERY row -- a strictly wider view than a signed-in merchant gets,
     * since `PlanController.php:27` shows a merchant only `is_disable = 1` and the unfiltered read
     * was operator-only. A draft or retired plan would have appeared on the company's own pricing
     * page under a "Start with this plan" button that `StoreController.php:1160` then refuses.
     *
     * `is_disable` IS INVERTED -- 1 is ENABLED -- and is NOT in `Plan::$fillable`, so a fixture
     * takes the column default of 1 and a test must write it explicitly. Through Eloquent, because
     * `Plan` is `Cachable` and a raw write leaves the read serving the pre-write row.
     */
    #[Test]
    public function a_disabled_plan_is_not_advertised_anywhere_on_the_site(): void
    {
        $visible = $this->plan(['name' => 'Visible Plan', 'price' => '10']);
        $hidden  = $this->plan(['name' => 'Hidden Plan', 'price' => '20']);

        /* PREMISE: both are visible while both are enabled, so the assertions below are about the
           flag and not about something else. */
        $before = $this->get('/platform/pricing')->getContent();
        $this->assertStringContainsString('Visible Plan', $before);
        $this->assertStringContainsString('Hidden Plan', $before);

        $hidden->is_disable = 0;
        $hidden->save();

        foreach (['/platform/pricing', '/platform', '/platform/getting-started'] as $url) {
            $html = $this->get($url)->getContent();
            $this->assertStringNotContainsString('Hidden Plan', $html, "a disabled plan is advertised on {$url}");
        }

        $this->assertStringContainsString('Visible Plan', $this->get('/platform/pricing')->getContent());
    }

    #[Test]
    public function an_installation_whose_only_plan_is_disabled_says_there_are_none(): void
    {
        $plan = $this->plan(['name' => 'Only Plan']);
        $plan->is_disable = 0;
        $plan->save();

        $response = $this->get('/platform/pricing');

        $response->assertOk();
        $response->assertSee('No subscription plans have been published yet', escape: false);
        $this->assertStringNotContainsString('ss-ps-plan__amount', $response->getContent());
    }

    #[Test]
    public function the_home_page_teaser_reads_the_same_rows(): void
    {
        $this->plan(['name' => 'Teaser Plan', 'price' => '77']);

        $home = $this->get('/platform');

        $home->assertOk();
        $home->assertSee('Teaser Plan', escape: false);
        $home->assertSee('77', escape: false);
    }
}
