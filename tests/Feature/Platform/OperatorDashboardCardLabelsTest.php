<?php

namespace Tests\Feature\Platform;

use App\Models\Plan;
use App\Models\PlanCoupon;
use App\Models\PlanOrder;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * TWO CARDS ON THE OPERATOR DASHBOARD SAID SOMETHING THEIR OWN FIGURE DOES NOT.
 * =================================================================================================
 *
 * `superadmin/dashboard.blade.php` draws six `<x-dashboard-card>`s. Two of them were mislabelled,
 * and the figures behind two of them were computed without a bound.
 *
 *  1. **"Total Store" showed the MERCHANT count.** `$platformTotals['stores']` is
 *     `User::countCompany()` -- `User::where('type','admin')->where('created_by', creatorId())
 *     ->count()`, a count of type-`admin` USER rows. `stores` is a separate table and
 *     `User::stores()` is a `hasMany`, so one merchant owns N stores and the two numbers differ by
 *     construction.
 *
 *     **THE LABEL IS WHAT IS WRONG, NOT THE FIGURE, AND THAT IS A MEASUREMENT RATHER THAN A
 *     PREFERENCE.** The card links to `stores.index`, whose handler lists type-`admin` USERS
 *     (`User::select(['users.*'])->join('stores',...)->where('users.type','admin')
 *     ->groupBy('users.id')`), and `store/index.blade.php`'s own breadcrumb already reads
 *     `__('Users')`. So the number, the destination screen and its breadcrumb all describe
 *     merchants; only the word "Store" on the card did not. Changing the FIGURE to `Store::count()`
 *     would have made the card disagree with the screen it opens, and would have removed the
 *     merchant count, which nothing else on the dashboard states.
 *
 *  2. **"Total Coupon" printed a coupon NAME.** `:count="$couponName"` under
 *     `:label="__('Total Coupon')"`. The screen already has the idiom for that and labels it
 *     correctly -- "Popular Plan" shows `Plan::most_purchese_plan()`, a name -- so this card is
 *     relabelled to match its sibling rather than having its value replaced.
 *
 * WHY NO FIGURE CHANGES AT ALL. `OperatorDashboardWithoutAStoreTest` builds its expectation as
 * `'stores' => $operator->fresh()->countCompany()` -- by calling the implementation -- and asserts
 * the three totals are distinct so a number under the wrong label would be visible. That test is
 * the recorded contract for the figure. Changing the figure means changing that test in the same
 * breath, which is a product decision about what an operator's dashboard should state; changing the
 * label makes the existing figure honest and decides nothing.
 *
 * ---------------------------------------------------------------------------------------------
 * EVERY LABEL ASSERTION HERE IS SCOPED TO ONE CARD, AND THAT IS NOT TIDINESS.
 * ---------------------------------------------------------------------------------------------
 *
 * The first version of this test asserted `assertStringNotContainsString('Total Store', $body)`
 * over the whole rendered page. **That assertion was unsatisfiable without breaking correct code.**
 * The same view prints `{{ __('Total Store') }} : {{ $topAdmin->stores_count ?? 0 }}` on each
 * top-merchant tile lower down -- a per-merchant count of real `stores` rows, where "Total Store"
 * is the RIGHT label. A whole-body assertion answers "does this string appear anywhere on the
 * page", so the only way to satisfy it was to relabel a card that was already correct.
 *
 * This is CLAUDE.md's recorded census trap in its second form ("scope the search to the block
 * too"), and it cost a round here: five of six cases failed, and one of them could never have
 * passed for the right reason. So the cards are PARSED out of the rendered HTML by identity --
 * each `<x-dashboard-card>` renders `<div class="details-card">` containing an
 * `<a href="{url}" aria-label="{label}">` and `<h2>{label}</h2><h3>{count}</h3>` -- and every
 * assertion names the card by the route it links to. `test_the_six_cards_are_found` is the premise:
 * without it, a parse that matched nothing would make every "the label is not X" case pass
 * vacuously.
 *
 * `test_the_per_merchant_store_badge_still_says_total_store` is the guard for the correct label
 * that the first version would have destroyed.
 */
class OperatorDashboardCardLabelsTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $operator;

    /** Memoised by `settledPlan()`; see `redeem()` for why there is exactly one. */
    private ?Plan $settledPlan = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = $this->makePlatformAdmin();
        $this->makeStore($this->operator, ['default_language' => 'en']);
        (new User)->flushCache();
        Cache::forget('admin_details');
    }

    private function body(): string
    {
        $response = $this->actingAs(User::find($this->operator->id))->get('/dashboard');
        $response->assertOk();

        return (string) $response->getContent();
    }

    /**
     * Parse the six dashboard cards out of the rendered page, by the structure the component emits.
     *
     * @return list<array{url: string, name: string, label: string, count: string}>
     */
    private function cards(?string $body = null): array
    {
        $body ??= $this->body();

        $pattern = '~<div class="details-card">.*?<a\s+href="([^"]*)"[^>]*aria-label="([^"]*)"'
            . '.*?<h2[^>]*>(.*?)</h2>\s*<h3[^>]*>(.*?)</h3>~s';

        preg_match_all($pattern, $body, $matches, PREG_SET_ORDER);

        return array_map(fn (array $m) => [
            'url' => html_entity_decode($m[1], ENT_QUOTES),
            'name' => html_entity_decode($m[2], ENT_QUOTES),
            'label' => trim(html_entity_decode($m[3], ENT_QUOTES)),
            'count' => trim(html_entity_decode($m[4], ENT_QUOTES)),
        ], $matches);
    }

    /**
     * The one card that links to `$route`, failing if the page does not hold exactly one.
     *
     * @return array{url: string, name: string, label: string, count: string}
     */
    private function cardLinkingTo(string $route, ?string $body = null): array
    {
        $url = route($route);
        $found = array_values(array_filter($this->cards($body), fn ($c) => $c['url'] === $url));

        $this->assertCount(1, $found,
            'PREMISE: the operator dashboard does not hold exactly one card linking to '
            . $route . ' (' . $url . '), so an assertion about "the ' . $route . ' card" has no '
            . 'single subject. Found ' . count($found) . '.');

        return $found[0];
    }

    // ---------------------------------------------------------------------------------------
    //  0 — THE PREMISE: THE PARSE FINDS THE CARDS, AND FINDS ONLY THE CARDS
    // ---------------------------------------------------------------------------------------

    public function test_the_six_cards_are_found(): void
    {
        $cards = $this->cards();

        $this->assertCount(6, $cards,
            'PREMISE: the card parse found ' . count($cards) . ' cards, not six. Every "the label '
            . 'is not X" case below would pass vacuously against an empty parse, so they prove '
            . 'nothing until this does.');

        foreach ($cards as $card) {
            $this->assertNotSame('', $card['label'], 'a card rendered with no heading');
            $this->assertSame($card['label'], $card['name'],
                "the card's visible heading and its link's accessible name disagree, so one of the "
                . 'two assertions below is reading a different string from the one a person sees');
        }
    }

    /**
     * THE PARSE EXCLUDES THE PER-MERCHANT TILE, which is what makes card-scoped assertions honest.
     *
     * That tile is a `dashboard-card`, not a `details-card`, and carries no `aria-label`, so the
     * pattern cannot match it. Asserted rather than assumed: if the view's two card shapes ever
     * converge, the scoping argument above silently stops holding.
     */
    public function test_the_parse_does_not_reach_the_per_merchant_tile(): void
    {
        $before = count($this->cards());

        foreach (range(1, 3) as $n) {
            $merchant = $this->makeMerchant(['created_by' => $this->operator->id]);
            $this->makeStore($merchant, ['default_language' => 'en']);
        }
        (new User)->flushCache();

        $body = $this->body();

        $this->assertStringContainsString('Total Store', $body,
            'PREMISE: no per-merchant tile is on the page at all, so this test cannot show that '
            . 'the parse excludes them.');
        $this->assertSame(3, substr_count($body, 'bg-primary">' . __('Total Store')),
            'PREMISE: the three per-merchant tiles did not render, so their exclusion from the '
            . 'parse is not being tested.');

        /*
         * THE COUNT, not the labels. Before the fix the dashboard card is ITSELF labelled "Total
         * Store", so "no parsed card says Total Store" fails identically whether the tiles are
         * being parsed or not -- it cannot tell the two apart. Three tiles appearing without
         * changing the number of parsed cards can only mean the parse does not reach them, and
         * that holds before and after the relabelling.
         */
        $this->assertSame($before, count($this->cards($body)),
            'three per-merchant store tiles changed the number of parsed dashboard cards, so the '
            . 'tiles are being parsed as cards and every card-scoped assertion here is reading '
            . 'them too');
    }

    // ---------------------------------------------------------------------------------------
    //  1 — THE CARD THAT COUNTS MERCHANTS
    // ---------------------------------------------------------------------------------------

    public function test_the_merchant_card_is_not_labelled_as_a_store_count(): void
    {
        // Two merchants, one of whom owns TWO stores, so the merchant count (2) and the store
        // count (4, including the operator's own) cannot be confused for one another.
        $a = $this->makeMerchant(['created_by' => $this->operator->id]);
        $b = $this->makeMerchant(['created_by' => $this->operator->id]);
        $this->makeStore($a, ['default_language' => 'en']);
        $this->makeStore($a, ['default_language' => 'ar']);
        $this->makeStore($b, ['default_language' => 'en']);
        (new User)->flushCache();

        $merchants = (int) User::find($this->operator->id)->countCompany();
        $stores = Store::withoutGlobalScopes()->count();

        $this->assertSame(2, $merchants, 'PREMISE: the merchant fixture did not take.');
        $this->assertNotSame($merchants, $stores,
            'PREMISE: the merchant count and the store count are equal (' . $merchants . '), so a '
            . 'card labelled either way would read correctly and this test could not see the defect.');

        $card = $this->cardLinkingTo('stores.index');

        $this->assertNotSame('Total Store', $card['label'],
            'the card reads "Total Store" while showing ' . $merchants . ' merchants; the platform '
            . 'has ' . $stores . ' stores');
        $this->assertSame((string) $merchants, $card['count'],
            'PREMISE: the card is not showing the merchant count, so this is not the card this '
            . 'test is about.');
    }

    public function test_the_merchant_card_names_merchants_and_keeps_its_figure(): void
    {
        $this->makeMerchant(['created_by' => $this->operator->id]);
        (new User)->flushCache();

        $response = $this->actingAs(User::find($this->operator->id))->get('/dashboard');
        $response->assertOk();

        $card = $this->cardLinkingTo('stores.index', (string) $response->getContent());
        $totals = $response->viewData('platformTotals');

        $this->assertSame('Total Merchants', $card['label'],
            'the card does not name what it counts');
        $this->assertSame(
            (int) User::find($this->operator->id)->countCompany(),
            (int) $totals['stores'],
            'the FIGURE changed. This slice relabels the card and leaves the number alone, because '
            . 'the number is the contract OperatorDashboardWithoutAStoreTest records.');
        $this->assertSame((string) $totals['stores'], $card['count'],
            'the relabelled card no longer prints the figure it was relabelled to describe');
    }

    /**
     * THE CORRECT "Total Store" SURVIVES. The first version of this test would have deleted it.
     *
     * `$topAdmin->stores_count` is a real count of `stores` rows for one merchant, so "Total Store"
     * is the right words there. It is the reason every assertion above names a card instead of
     * searching the page.
     */
    public function test_the_per_merchant_store_badge_still_says_total_store(): void
    {
        $merchant = $this->makeMerchant(['created_by' => $this->operator->id]);
        $this->makeStore($merchant, ['default_language' => 'en']);
        $this->makeStore($merchant, ['default_language' => 'ar']);
        (new User)->flushCache();

        $body = $this->body();

        $this->assertStringContainsString('Total Store', $body,
            'the per-merchant store tile lost its label, which was CORRECT -- it counts real '
            . 'stores. Only the dashboard card at the top of the screen was mislabelled.');
        $this->assertMatchesRegularExpression('~Total Store\s*:\s*2~',
            (string) preg_replace('~\s+~', ' ', $body),
            'the tile no longer prints this merchant\'s two stores beside that label');
    }

    // ---------------------------------------------------------------------------------------
    //  2 — THE CARD THAT PRINTS A NAME
    // ---------------------------------------------------------------------------------------

    public function test_the_coupon_card_is_not_labelled_as_a_total(): void
    {
        $card = $this->cardLinkingTo('plan-coupon.index');

        $this->assertNotSame('Total Coupon', $card['label'],
            'a card headed "Total Coupon" renders a coupon NAME, not a count');
    }

    public function test_the_coupon_card_is_labelled_like_the_sibling_that_also_shows_a_name(): void
    {
        $body = $this->body();

        $this->assertSame('Popular Coupon', $this->cardLinkingTo('plan-coupon.index', $body)['label']);

        /*
         * THE SIBLING IS FOUND BY ITS LABEL, NOT BY ITS ROUTE. THREE of the six cards link to
         * `plan.index` -- "Total Plan Orders", "Total Plans" and "Popular Plan" -- so
         * `cardLinkingTo()` cannot name this one, and it correctly refused to guess. Still scoped
         * to the parsed cards rather than searched for in the page body.
         */
        $labels = array_column($this->cards($body), 'label');

        $this->assertContains('Popular Plan', $labels,
            'PREMISE: the sibling this label was modelled on is gone, so the consistency argument '
            . 'no longer holds and the choice should be revisited. Cards present: '
            . implode(' | ', $labels));
    }

    public function test_the_coupon_card_still_names_the_most_used_coupon(): void
    {
        /*
         * THE COLUMNS ARE THE MIGRATION'S, NOT THE ONES I ASSUMED. The first fixture invented
         * `plan_coupons.created_by`, `plan_user_coupons.order_id` and `plan_user_coupons.plan_id`,
         * and gave `plans` an `image`. None exists: `plan_coupons` is (name, code, discount, limit,
         * description, is_active) and `plan_user_coupons` is (user_id, coupon_id, `order`) with
         * `order` a NOT NULL string. Read from the migrations after the inserts were refused.
         */
        $quiet = PlanCoupon::forceCreate([
            'name' => 'QUIETCOUPON', 'code' => 'Q1', 'discount' => 5, 'limit' => '10',
        ]);
        $busy = PlanCoupon::forceCreate([
            'name' => 'BUSYCOUPON', 'code' => 'B1', 'discount' => 5, 'limit' => '10',
        ]);

        $this->redeem($busy->id, 2);
        $this->redeem($quiet->id, 1);

        $response = $this->actingAs(User::find($this->operator->id))->get('/dashboard');
        $response->assertOk();

        $this->assertSame('BUSYCOUPON', $response->viewData('couponName'),
            'the most-used coupon is no longer the one named, so relabelling the card changed what '
            . 'it reports');
        $this->assertSame('BUSYCOUPON',
            $this->cardLinkingTo('plan-coupon.index', (string) $response->getContent())['count'],
            'the card does not print the coupon the handler chose');
    }

    /** A COUPON NOBODY HAS USED NEVER WINS -- the `$max > $maxValue` semantics of the original. */
    public function test_an_unused_coupon_is_not_named(): void
    {
        PlanCoupon::forceCreate([
            'name' => 'NEVERUSED', 'code' => 'N1', 'discount' => 5, 'limit' => '10',
        ]);

        $response = $this->actingAs(User::find($this->operator->id))->get('/dashboard');
        $response->assertOk();

        $this->assertSame('', $response->viewData('couponName'),
            'a coupon with zero redemptions is being named as the most-used one');
    }

    /** A TIE GOES TO THE FIRST -- also the original's semantics, since `>` never displaces. */
    public function test_a_tie_is_broken_the_way_the_original_broke_it(): void
    {
        $first = PlanCoupon::forceCreate([
            'name' => 'FIRSTCOUPON', 'code' => 'F1', 'discount' => 5, 'limit' => '10',
        ]);
        $second = PlanCoupon::forceCreate([
            'name' => 'SECONDCOUPON', 'code' => 'S1', 'discount' => 5, 'limit' => '10',
        ]);

        $this->assertLessThan($second->id, $first->id, 'PREMISE: the fixture ids are not ordered.');

        $this->redeem($first->id, 3);
        $this->redeem($second->id, 3);

        $response = $this->actingAs(User::find($this->operator->id))->get('/dashboard');
        $response->assertOk();

        $this->assertSame('FIRSTCOUPON', $response->viewData('couponName'),
            'two coupons level on redemptions now resolve to the later one, so the card can swap '
            . 'between requests on identical data');
    }

    // ---------------------------------------------------------------------------------------
    //  2b — BOTH NEW LABELS IN ARABIC, ON THE RENDERED CARD
    // ---------------------------------------------------------------------------------------

    /**
     * THE ARABIC REACHES THE CARD, not just the translation file.
     *
     * `OperatorDashboardCardLabelTranslationTest` asserts `__('Total Merchants')` resolves under
     * the `ar` locale. That is the string; this is the screen. `SetLocate` takes the locale from
     * `$user->language` (after the session and cookie), so an operator whose `language` is `ar`
     * drives the real middleware rather than `app()->setLocale()`, which an HTTP request would
     * discard.
     *
     * Required because CLAUDE.md asks for both directions on any UI change, and because the
     * translation ratchet runs ONE WAY: it fails an Arabic key with no English sibling, and says
     * nothing about an English label with no Arabic one. Without this, both labels could render in
     * English on the Arabic dashboard with the whole suite green.
     */
    public function test_both_relabelled_cards_read_in_arabic(): void
    {
        $operator = $this->makePlatformAdmin(['language' => 'ar']);
        $this->makeStore($operator, ['default_language' => 'ar']);
        (new User)->flushCache();
        Cache::forget('admin_details');

        $response = $this->actingAs(User::find($operator->id))->get('/dashboard');
        $response->assertOk();

        $body = (string) $response->getContent();
        $labels = array_column($this->cards($body), 'label');

        $this->assertNotContains('Total Merchants', $labels,
            'PREMISE: the card still reads English, so the `ar` locale did not take and this test '
            . 'is not measuring the Arabic screen. Cards: ' . implode(' | ', $labels));

        $this->assertContains('إجمالي التجار', $labels,
            'the merchant card does not read in Arabic. Cards: ' . implode(' | ', $labels));
        $this->assertContains('الكوبون الشعبي', $labels,
            'the coupon card does not read in Arabic. Cards: ' . implode(' | ', $labels));
    }

    // ---------------------------------------------------------------------------------------
    //  3 — THE TWO UNBOUNDED FIGURES BEHIND THOSE CARDS
    // ---------------------------------------------------------------------------------------

    /**
     * The coupon figure is bounded: one query for the ranking, not one COUNT per coupon.
     *
     * `handleSuperAdmin()` did `PlanCoupon::get()` and then called `$coupon->used_coupon()` inside
     * a `foreach`, and `used_coupon()` is `$this->hasMany(PlanUserCoupon::class,...)->count()` --
     * one COUNT query per coupon, unbounded by coupon count, on every load of the operator's
     * landing page. MEASURED at exactly 12 queries for 12 coupons before the fix.
     *
     * Asserted as a CEILING on queries touching `plan_user_coupons` rather than an exact total,
     * because the page issues many unrelated queries and pinning the whole number would make this
     * test fail for reasons that have nothing to do with the coupon panel.
     */
    public function test_the_coupon_ranking_does_not_query_once_per_coupon(): void
    {
        for ($i = 0; $i < 12; $i++) {
            PlanCoupon::forceCreate([
                'name' => 'C' . $i, 'code' => 'C' . $i, 'discount' => 1, 'limit' => '1',
            ]);
        }

        $this->assertSame(12, PlanCoupon::withoutGlobalScopes()->count(),
            'PREMISE: the twelve coupons were not created, so an N+1 would not be visible.');

        $touching = 0;
        DB::listen(function ($query) use (&$touching) {
            if (str_contains($query->sql, 'plan_user_coupons')) {
                $touching++;
            }
        });

        $this->actingAs(User::find($this->operator->id))->get('/dashboard')->assertOk();

        $this->assertLessThanOrEqual(2, $touching,
            "the dashboard issued {$touching} queries against plan_user_coupons for 12 coupons; "
            . 'the ranking should be one grouped query');
    }

    /**
     * THE SIBLING CARD'S FIGURE COUNTED IN PHP, which is the class this screen already records
     * as fixed twice.
     *
     * `Plan::most_purchese_plan()` was `PlanOrder::pluck('plan_id','id')->toarray()` followed by
     * `array_count_values()` -- every `plan_orders` row in the platform pulled into memory and
     * counted there, with no predicate and no bound, on every load of this page. The controller
     * already carries that exact account for `popularStores`, which was three lines of the same
     * shape over the visit log. Found while fixing the coupon N+1 on the card beside it.
     *
     * Asserted on the SQL rather than on a query count, because the defect is not the number of
     * queries (it was two) -- it is that the aggregation happened outside the database.
     */
    public function test_the_popular_plan_figure_is_aggregated_in_the_database(): void
    {
        $plan = $this->makePlan('STARTERPLAN');
        $other = $this->makePlan('OTHERPLAN');

        // `plan_orders` has FOUR non-nullable columns besides the keys -- `plan_name`,
        // `price_currency`, `txn_id` and `payment_status` -- read from the migration after the
        // insert was refused for `plan_name`.
        foreach ([$plan, $plan, $other] as $i => $subject) {
            PlanOrder::forceCreate([
                'order_id' => 'PO-' . $i . '-' . bin2hex(random_bytes(3)),
                'plan_id' => (int) $subject->id,
                'plan_name' => (string) $subject->name,
                'user_id' => (int) $this->operator->id,
                'price' => 10,
                'price_currency' => 'USD',
                'txn_id' => 'TXN-' . $i . '-' . bin2hex(random_bytes(3)),
                'payment_type' => 'manually',
                'payment_status' => 'succeeded',
            ]);
        }

        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            if (str_contains($query->sql, 'plan_orders')) {
                $statements[] = strtolower($query->sql);
            }
        });

        $response = $this->actingAs(User::find($this->operator->id))->get('/dashboard');
        $response->assertOk();

        $this->assertSame('STARTERPLAN', $response->viewData('plan_order'),
            'the most-purchased plan changed, so the aggregation is not equivalent');

        $ranking = array_values(array_filter(
            $statements,
            fn (string $sql) => str_contains($sql, 'plan_id') && ! str_contains($sql, 'count(*)')
                || str_contains($sql, 'group by')
        ));

        $this->assertNotSame([], $ranking,
            'PREMISE: no statement against plan_orders mentions plan_id, so this test is not '
            . 'watching the ranking query.');

        foreach ($ranking as $sql) {
            $this->assertStringContainsString('group by', $sql,
                'the plan ranking still reads plan_orders row by row and counts them in PHP: ' . $sql);
        }
    }

    /**
     * TWO PLANS LEVEL ON PURCHASES RESOLVE TO THE ONE SOLD FIRST -- the original's tie-break.
     *
     * `array_count_values()` keys follow first appearance and `array_search()` returns the first
     * match, so the old PHP count resolved a tie to the plan whose earliest `plan_orders` row came
     * first in primary-key order. The grouped query reproduces it with `MIN(id)`; with only
     * `ORDER BY purchases DESC` the database may return either plan and the card would swap
     * between requests on unchanged data.
     *
     * ADDED BECAUSE A DOCBLOCK CITED A TEST THAT DID NOT EXIST. `Plan::most_purchese_plan()` said
     * "`OperatorDashboardPopularPlanTest` pins it"; there is no such class, and nothing pinned the
     * plan tie-break -- `test_a_tie_is_broken_the_way_the_original_broke_it` above covers the
     * COUPON ranking only. The citation now names this method.
     */
    public function test_a_plan_tie_is_broken_by_the_earliest_order(): void
    {
        $first = $this->makePlan('SOLDFIRSTPLAN');
        $second = $this->makePlan('SOLDLATERPLAN');

        // Interleaved, so neither plan owns a contiguous block of ids and "the earliest order"
        // cannot be satisfied by accident through plan id order alone.
        foreach ([$first, $second, $second, $first] as $i => $subject) {
            PlanOrder::forceCreate([
                'order_id' => 'POT-' . $i . '-' . bin2hex(random_bytes(3)),
                'plan_id' => (int) $subject->id,
                'plan_name' => (string) $subject->name,
                'user_id' => (int) $this->operator->id,
                'price' => 10,
                'price_currency' => 'USD',
                'txn_id' => 'TXNT-' . $i . '-' . bin2hex(random_bytes(3)),
                'payment_type' => 'manually',
                'payment_status' => 'succeeded',
            ]);
        }

        $this->assertSame(
            [2, 2],
            [
                PlanOrder::where('plan_id', $first->id)->count(),
                PlanOrder::where('plan_id', $second->id)->count(),
            ],
            'PREMISE: the two plans are not level on purchases, so this is not testing a tie.');

        $response = $this->actingAs(User::find($this->operator->id))->get('/dashboard');
        $response->assertOk();

        $this->assertSame('SOLDFIRSTPLAN', $response->viewData('plan_order'),
            'two plans level on purchases now resolve to the one sold later, so the card can swap '
            . 'between requests on identical data');
    }

    // ---------------------------------------------------------------------------------------

    /** `plans.themes` is TEXT NOT NULL with no default, so a bare `forceCreate` is refused. */
    private function makePlan(string $name): Plan
    {
        return Plan::forceCreate([
            'name' => $name,
            'price' => 10,
            'duration' => 'Lifetime',
            'description' => $name,
            'themes' => '[]',
            'max_stores' => 1,
            'max_products' => 10,
            'max_users' => 1,
            'storage_limit' => 100,
            'enable_domain' => 0,
            'enable_subdomain' => 0,
            'enable_chatgpt' => 0,
            'pwa_store' => 0,
            'shipping_method' => 0,
            'enable_tax' => 0,
        ]);
    }

    /**
     * `plan_user_coupons.order` is a NOT NULL string; there is no `order_id` and no `plan_id`.
     *
     * EACH REDEMPTION NOW NAMES A SETTLED `plan_orders` ROW, AND THAT IS A FIXTURE REPAIR RATHER
     * THAN A CHANGED EXPECTATION.
     * ------------------------------------------------------------------------------------------
     * `GAP-231` (`8cb288150`) made this ranking count SETTLED purchases only:
     * `SettledPlanOrders::redeemedAgainstSettledOrder()` is an `EXISTS` on
     * `plan_orders.order_id = plan_user_coupons.order` with a settled `payment_status`, and its own
     * docblock states the consequence — *"A REDEMPTION NAMING NO ORDER AT ALL IS NOT SETTLED.
     * `EXISTS` is false for it, so it is excluded."*
     *
     * These rows named `PUC-<coupon>-<n>-<hex>`, which is no order at all. So after that change
     * the card was CORRECTLY empty and the two cases below failed on `''` — not because either of
     * them is about settlement, but because neither established a redemption that counts under the
     * rule in force. The premise went stale; the subject did not.
     *
     * WHAT IS NOT TOUCHED, because the owner's instruction is explicit that a test must not be
     * rewritten to match a new rule: every assertion below is the one it always was — BUSYCOUPON
     * outranks QUIETCOUPON two to one, a coupon nobody redeemed is never named, and a tie goes to
     * the lower id. Neither case asserts anything about settlement, and nothing here says an
     * unsettled redemption should or should not count.
     *
     * AND THIS FIXTURE TAKES NO SIDE ON THAT QUESTION, which is what makes it safe to repair while
     * the metric's business meaning is still being reconciled: a redemption backed by a settled
     * order counts under the OLD unfiltered `COUNT(*)` too. If `GAP-231`'s rule is ever revisited,
     * these cases keep measuring what they were written to measure either way.
     */
    private function redeem(int $couponId, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $orderRef = 'PUC-' . $couponId . '-' . $i . '-' . bin2hex(random_bytes(3));

            /* `plan_orders` has FOUR non-nullable columns besides the keys — `plan_name`,
               `price_currency`, `txn_id` and `payment_status` — and `user_id` carries a foreign key
               to `users`, so the operator owns the purchase. `plan_id` has NO foreign key, but a
               real plan is used anyway so the fixture is a purchase somebody could have made.
               `'succeeded'` is in `SaasRevenueFigures::SETTLED_PLAN_STATUSES` as stored. */
            PlanOrder::forceCreate([
                'order_id' => $orderRef,
                'plan_id' => (int) $this->settledPlan()->id,
                'plan_name' => (string) $this->settledPlan()->name,
                'user_id' => (int) $this->operator->id,
                'price' => 10,
                'price_currency' => 'USD',
                'txn_id' => 'TXNC-' . $couponId . '-' . $i . '-' . bin2hex(random_bytes(3)),
                'payment_type' => 'manually',
                'payment_status' => 'succeeded',
            ]);

            DB::table('plan_user_coupons')->insert([
                'coupon_id' => $couponId,
                'user_id' => (int) $this->operator->id,
                'order' => $orderRef,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * One plan for every redemption in a case, so the coupon ranking is the only thing that varies.
     *
     * A SINGLE plan deliberately: the `Popular Plan` card beside the coupon card reads
     * `Plan::most_purchese_plan()`, and giving each redemption its own plan would make that figure
     * depend on the coupon fixture. Neither coupon case asserts it, and this keeps it that way.
     */
    private function settledPlan(): Plan
    {
        return $this->settledPlan ??= $this->makePlan('COUPONFIXTUREPLAN');
    }
}
