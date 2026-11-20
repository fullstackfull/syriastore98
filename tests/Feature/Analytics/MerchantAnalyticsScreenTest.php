<?php

namespace Tests\Feature\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Shetabit\Visitor\Models\Visit;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P5-4b — THE MERCHANT ANALYTICS SCREEN, at its door and on the page.
 *
 * The arithmetic is MerchantAnalyticsFiguresTest's. This file asks the three questions a screen has
 * that an aggregator does not: who may open it, whose data it shows, and whether what it prints is
 * still honest once a Blade has been between the figure and the reader.
 *
 * THE AUTHORIZATION ORDERING IS THE PART THIS CODEBASE GETS WRONG BY DEFAULT, so all four principals
 * are driven rather than one: Laratrust has no super-admin bypass (so `isAbleTo()` alone refuses the
 * platform operator), and a merchant created before a permission name existed would be locked out of
 * their own reports without the owner limb. `ReportPermission` already encodes both; this asserts
 * the screen really inherits them rather than trusting that it does.
 *
 * `config(['app.debug' => false])` before any body is read, the same as ReportAuthorizationTest: an
 * APP_DEBUG query log was once nearly mis-filed as a disclosure in this repository.
 */
final class MerchantAnalyticsScreenTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const URI = '/analytics';

    private const PERMISSION = 'Manage Reports';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    private function refused($response): bool
    {
        return $response->status() === 403 || ($response->isRedirect() && session()->has('error'));
    }

    private function logVisit(int $storeId): void
    {
        $visit = new Visit();
        $visit->timestamps = false;
        $visit->store_id = $storeId;
        $visit->created_at = CarbonImmutable::now()->subDay();
        $visit->updated_at = CarbonImmutable::now()->subDay();
        $visit->save();
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The door
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_signed_out_visitor_cannot_open_it(): void
    {
        $response = $this->get(self::URI);

        $this->assertTrue($response->isRedirect(), 'the analytics screen was served to a guest');
        $this->assertNotSame(401, $response->status(),
            'a 401 forces a logout in the shipped Flutter client; this codebase redirects or answers 403');
    }

    public function test_a_staff_member_with_no_report_permission_is_refused(): void
    {
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);

        // Premise: without this the next assertion could pass because the permission is held.
        $this->assertFalse($staff->isAbleTo(self::PERMISSION), 'Premise: the staff member holds no report permission.');

        $response = $this->actingAs($staff->fresh())->get(self::URI);

        $this->assertTrue($this->refused($response),
            'a staff member with no report permission read the store\'s figures (status '.$response->status().')');
    }

    public function test_the_seeded_report_permission_opens_it(): void
    {
        [$merchant] = $this->makeTenant();
        $holder = $this->makeStaff($merchant);
        $this->grantPermission($holder, self::PERMISSION);

        $response = $this->actingAs($holder->fresh())->get(self::URI);

        $this->assertFalse($this->refused($response),
            'a staff member holding '.self::PERMISSION.' was refused (status '.$response->status().')');
        $response->assertStatus(200);
    }

    /**
     * The owner limb. A merchant whose seeded role predates the permission name holds no row for it,
     * and refusing them their own analytics is the failure mode this limb exists to prevent.
     */
    public function test_the_store_owner_is_admitted_without_holding_the_permission_row(): void
    {
        [$merchant] = $this->makeTenant();

        $response = $this->actingAs($merchant->fresh())->get(self::URI);

        $this->assertFalse($this->refused($response), 'the store owner was refused their own analytics');
    }

    /** The operator limb: `isAbleTo()` alone would refuse the one principal who reads every store. */
    public function test_the_platform_operator_with_a_chosen_store_is_admitted(): void
    {
        [, $store] = $this->makeTenant();
        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);

        $response = $this->actingAs($operator)->get(self::URI);

        $this->assertFalse($this->refused($response), 'the platform operator was refused');
    }

    /**
     * `getCurrentStore()` returns the HARDCODED LITERAL 1 for a `super admin`, so an operator who has
     * chosen nothing would otherwise be shown one particular merchant's figures as though they were
     * the platform's. The same refusal StoreFinanceController makes, for the same reason.
     */
    public function test_an_operator_who_has_chosen_no_store_is_refused_rather_than_shown_store_one(): void
    {
        $this->makeTenant();
        $operator = $this->makePlatformAdmin(['current_store' => null]);

        $response = $this->actingAs($operator)->get(self::URI);

        $this->assertTrue($this->refused($response),
            'an operator with no store chosen was shown a store\'s analytics (status '.$response->status().')');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Whose data
     * ---------------------------------------------------------------------------------------------
     */

    public function test_the_screen_shows_this_stores_figures_and_not_another_stores(): void
    {
        [$mine, $myStore] = $this->makeTenant();
        [, $theirStore] = $this->makeTenant();

        $this->makeOrder($myStore);
        foreach (range(1, 4) as $ignored) {
            $this->makeOrder($theirStore);
        }
        $this->logVisit((int) $myStore->id);
        foreach (range(1, 7) as $ignored) {
            $this->logVisit((int) $theirStore->id);
        }

        $response = $this->actingAs($mine->fresh())->get(self::URI);

        $response->assertStatus(200);

        /*
         * The oracle is the OTHER store's counts, which are distinct from this one's (1 and 1 against
         * 4 and 7) so a leak shows up as a NUMBER rather than being inferred. Matched inside the tile
         * markup rather than anywhere on the page, because "4" also appears in dates, asset
         * fingerprints and the menu.
         *
         * TWO THINGS SIT BETWEEN THE FIGURE AND A NAIVE MATCH, and both would have made all three of
         * these assertions vacuous in the SAME direction — the negatives included, which is the
         * dangerous way for an oracle to fail:
         *
         *   - every tile here passes a hint, so the component emits
         *     `class="ss-stat__value" aria-describedby="ss-stat-hint-…">`, and `value">` finds nothing;
         *   - MerchantAnalyticsFigures wraps each number in U+2066/U+2069 bidi isolates so it lays
         *     out left-to-right inside an Arabic paragraph, so the text node is `⁦1⁩` and not `1`.
         *
         * Hence `[^>]*` for the attribute and a strip of the isolates before matching. The pattern is
         * anchored on the closing `<` so a figure of 14 does not satisfy a search for 1.
         */
        $body = preg_replace('/[\x{2066}-\x{2069}]/u', '', (string) $response->getContent());

        $tileShows = static fn (string $figure): bool => (bool) preg_match(
            '/ss-stat__value"[^>]*>\s*' . preg_quote($figure, '/') . '\s*</',
            $body
        );

        $this->assertTrue($tileShows('1'), 'no tile shows this store\'s single order or single page view');
        $this->assertFalse($tileShows('4'), 'another store\'s order count appeared on this store\'s analytics');
        $this->assertFalse($tileShows('7'), 'another store\'s page views appeared on this store\'s analytics');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * What it prints
     * ---------------------------------------------------------------------------------------------
     */

    /** The whole point of the component: a figure that cannot be produced must not look like a zero. */
    public function test_a_figure_that_cannot_be_produced_reads_not_available_rather_than_zero(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->makeOrder($store);     // an order, and deliberately no visit at all

        $response = $this->actingAs($merchant->fresh())->get(self::URI);

        $response->assertStatus(200);
        $response->assertSee(__('Not available'));
        $response->assertSee(__('Conversion'));
    }

    public function test_every_tile_states_a_period(): void
    {
        [$merchant] = $this->makeTenant();

        $response = $this->actingAs($merchant->fresh())->get(self::URI);
        $body = $response->getContent();

        $values = preg_match_all('/ss-stat__value/', $body);
        $periods = preg_match_all('/ss-stat__period/', $body);

        $this->assertGreaterThanOrEqual(6, $values, 'Premise: the six tiles did not render.');
        $this->assertSame($values, $periods,
            'a tile rendered a figure with no period, which is the commonest way a dashboard misleads');
    }

    /**
     * THE VIEW MUST NOT BE WRITTEN IN THE `<x-*-layout>` SLOT SHAPE. `layouts/app.blade.php` is the
     * vendored WorkDo layout and holds zero `$slot` references, so such a view renders perfect
     * chrome, HTTP 200, and an EMPTY content area — which no status code reveals. SlotLayoutViewsRenderTest
     * is the general ratchet; this asserts it for this screen, where the symptom would be a blank
     * analytics page that still looked fine.
     */
    public function test_the_content_really_rendered_inside_the_layout(): void
    {
        [$merchant] = $this->makeTenant();

        $response = $this->actingAs($merchant->fresh())->get(self::URI);

        $response->assertStatus(200);
        $response->assertSee(__('Orders'));
        $response->assertSee(__('Page views'));
        $response->assertSee(__('Payments received'));
        $response->assertSee('ss-stats', false);
    }

    /**
     * Bilingual: Arabic is not a translation pass added later, so it is driven here.
     *
     * THE LOCALE IS SET THE WAY THE APPLICATION SETS IT — on the principal's `language` column, which
     * is what `SetLocate` reads (session LANGUAGE, else cookie, else `auth()->user()->language`).
     * `app()->setLocale('ar')` in the test does NOT survive the request: SetLocate runs inside it and
     * resolves the locale again, so the page came back in English and a `__()` call AFTER the response
     * read the English catalogue too — a premise assertion caught exactly that.
     *
     * THE EXPECTED STRINGS ARE THE ARABIC LITERALS, not `__()` output. Asserting `__('Conversion')`
     * compares the page against whatever catalogue the TEST process happens to be on, which is how a
     * bilingual test passes while rendering English on both sides.
     */
    public function test_it_renders_in_arabic(): void
    {
        [$merchant] = $this->makeTenant();
        $merchant->language = 'ar';
        $merchant->save();

        $response = $this->actingAs($merchant->fresh())->get(self::URI);

        $response->assertStatus(200);
        $response->assertSee('التحليلات', false);     // Analytics
        $response->assertSee('معدل التحويل', false);  // Conversion
        $response->assertSee('المشترون', false);      // Buyers
    }
}
