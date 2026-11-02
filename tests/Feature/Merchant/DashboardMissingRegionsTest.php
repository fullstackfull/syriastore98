<?php

namespace Tests\Feature\Merchant;

use App\DataTables\ProductDataTable;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * WS C — the three dashboard regions the master prompt asks for and the page did not have.
 * =================================================================================================
 *
 * Workstream C lists eight things a merchant home dashboard should answer. Five were already
 * built. These are the three that were not, and each is here for a different reason:
 *
 * 1. **INVENTORY ALERTS** — the region reported what had already RUN OUT and nothing about what
 *    was about to. The product list has had an `?ss_stock=low` filter since P2-03-9; the
 *    dashboard had no low-stock figure at all. The count uses
 *    `ProductDataTable::scopeToLowStock()`, the list's OWN predicate, because this class's
 *    docblock records what happened the last time that expression existed twice: the stock badge
 *    and the stock filter disagreed at the boundary. A count that disagrees with the list it
 *    links to is worse than no count, because the merchant follows it.
 *
 * 2. **STORE OVERVIEW** — the page never said WHICH STORE its figures were about. A merchant with
 *    two stores read them without being told whose they were. It is a page-header subtitle rather
 *    than a card, because the store's name already appears twice further down and this slice has
 *    just deleted a duplicate order list for being a duplicate.
 *
 * 3. **IMPORTANT STORE NOTIFICATIONS** — present on this page only as a bell in the chrome, which
 *    answers "is there anything?" and not "what have I not seen?". The region is filled by the
 *    SAME single fetch the bell already makes, so there is one request and one server-side
 *    authorization decision rather than two.
 *
 * WHAT THIS FILE CAN AND CANNOT MEASURE. The digest is filled by JavaScript from
 * `merchant.notifications.feed`, so a Feature test can only assert that the markup, its hooks and
 * its translated strings reach the page, and that the region ships HIDDEN so it never renders as
 * an empty panel. Whether it fills correctly from a real feed is a browser question, measured by
 * `docs/development/phase-2/tools/shoot-wsc-dashboard-live.cjs`. Said here rather than implied,
 * because a source-level assertion about a JS region is exactly the kind that reads like proof
 * and is not.
 */
class DashboardMissingRegionsTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        $this->grantPermission(User::find($this->merchant->id), 'Manage Order');
        $this->grantPermission(User::find($this->merchant->id), 'Manage Products');

        $this->merchant = User::find($this->merchant->id);
    }

    private function dashboard(): string
    {
        $response = $this->actingAs($this->merchant)->get('/dashboard');
        $response->assertStatus(200);

        return (string) $response->getContent();
    }

    /** `forceCreate`, because `created_by` is NOT NULL and not in `$fillable` — the same
     *  fixture shape `ProductListFilterTest` uses, so the two measure the same rows. */
    private function makeProduct(array $attributes = []): Product
    {
        return Product::forceCreate(array_merge([
            'name' => 'WS C product ' . uniqid(),
            'slug' => 'wsc-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'trending' => 0,
            'product_type' => null,
            'store_id' => $this->store->id,
            'created_by' => $this->merchant->id,
            'status' => 1,
            'variant_product' => 0,
            'track_stock' => 1,
            'product_stock' => 50,
            'low_stock_threshold' => 0,
            'stock_status' => 'in_stock',
        ], $attributes));
    }

    // ---------------------------------------------------------------------------------------
    //  1 — INVENTORY ALERTS
    // ---------------------------------------------------------------------------------------

    public function test_a_product_at_or_below_its_own_threshold_is_reported_as_running_low(): void
    {
        $this->makeProduct(['product_stock' => 3, 'low_stock_threshold' => 5]);
        (new Product)->flushCache();

        $body = $this->dashboard();

        $this->assertStringContainsString('ss-attention', $body,
            'the attention block did not render, so the low-stock row could not be measured');
        $this->assertStringContainsString('product is running low', $body);
        $this->assertStringContainsString('ss_stock=low', $body,
            'the low-stock count does not link to the low-stock list, so a merchant told '
            . '"1 product is running low" lands on their entire catalogue');
    }

    public function test_a_healthy_product_is_not_reported_as_running_low(): void
    {
        $this->makeProduct(['product_stock' => 50, 'low_stock_threshold' => 5]);
        (new Product)->flushCache();

        $this->assertStringNotContainsString('product is running low', $this->dashboard());
    }

    /**
     * `track_stock = 0` MEANS THE NUMBER IS NOT COUNTED, so such a product is in no stock state at
     * all — the same reason `trackedStockState()` is named for tracked products. A zero on an
     * untracked product is "not counted", not "none left".
     */
    public function test_an_untracked_product_is_in_no_stock_state_and_is_not_reported(): void
    {
        $this->makeProduct(['track_stock' => 0, 'product_stock' => 1, 'low_stock_threshold' => 5]);
        (new Product)->flushCache();

        $this->assertStringNotContainsString('product is running low', $this->dashboard());
    }

    /**
     * ZERO IS "OUT", NOT "LOW", and the two rows must not both claim the same product. This is the
     * boundary the badge and the filter disagreed about before P2-03-9 shared their rule.
     */
    public function test_a_product_at_zero_is_reported_as_out_and_not_as_running_low(): void
    {
        $this->makeProduct(['product_stock' => 0, 'low_stock_threshold' => 5]);
        (new Product)->flushCache();

        $body = $this->dashboard();

        $this->assertStringContainsString('product is out of stock', $body);
        $this->assertStringNotContainsString('product is running low', $body);
    }

    /**
     * THE COUNT AND THE LIST ARE THE SAME QUESTION, asked of the same predicate. Driven rather
     * than asserted from the shared method's existence: a fixture spanning every boundary is
     * counted by the dashboard's own query and by `scopeToLowStock()` directly, and the two
     * numbers must agree.
     */
    public function test_the_dashboard_count_and_the_product_list_predicate_agree(): void
    {
        $this->makeProduct(['product_stock' => 3, 'low_stock_threshold' => 5]);   // low
        $this->makeProduct(['product_stock' => 5, 'low_stock_threshold' => 5]);   // low, at it
        $this->makeProduct(['product_stock' => 6, 'low_stock_threshold' => 5]);   // in
        $this->makeProduct(['product_stock' => 0, 'low_stock_threshold' => 5]);   // out
        $this->makeProduct(['track_stock' => 0, 'product_stock' => 1]);           // no state
        (new Product)->flushCache();

        $query = Product::withoutGlobalScopes()->where('store_id', $this->store->id)
            ->where(function ($q) {
                $q->where('variant_product', '!=', 1)->orWhereNull('variant_product');
            });
        $query->where(fn ($q) => ProductDataTable::scopeToLowStock($q));

        $this->assertSame(2, $query->count(),
            'the shared predicate does not count the two products this fixture made low, so the '
            . 'comparison below would compare two wrong numbers and pass');

        $body = $this->dashboard();

        /* The rendered count sits in the attention row's own element, so it is read from there
           rather than from the page at large — "2" appears in a dozen unrelated places. */
        $this->assertMatchesRegularExpression(
            '/<span class="ss-attention__count ss-num">\s*2\s*<\/span>\s*<span class="ss-attention__text">[^<]*running low/',
            $body,
            'the dashboard reports a different number of low-stock products than the predicate '
            . 'the product list filters on'
        );
    }

    public function test_the_low_stock_link_is_withheld_from_a_principal_without_manage_products(): void
    {
        $this->makeProduct(['product_stock' => 3, 'low_stock_threshold' => 5]);
        (new Product)->flushCache();

        $staff = $this->makeStaff($this->merchant, ['current_store' => $this->store->id]);
        $this->grantPermission(User::find($staff->id), 'Manage Blog');

        $body = (string) $this->actingAs(User::find($staff->id))->get('/dashboard')->getContent();

        $this->assertStringNotContainsString('ss_stock=low', $body,
            'a staff member without Manage Products was given a link into the product list');
    }

    // ---------------------------------------------------------------------------------------
    //  2 — STORE OVERVIEW
    // ---------------------------------------------------------------------------------------

    public function test_the_page_header_names_the_store_these_figures_belong_to(): void
    {
        $body = $this->dashboard();

        $this->assertStringContainsString('ss-page-header__subtitle', $body,
            'the header renders no subtitle, so the page still does not say which store it is '
            . 'reporting on');
        $this->assertStringContainsString(e($this->store->name), $body);
        $this->assertStringContainsString('View storefront', $body);
    }

    /**
     * THE SUBTITLE IS OPT-IN. 334 views set `page-title`; exactly one rendered
     * `x-ss.page-header`. If the layout emitted an empty subtitle element for every screen that
     * defines none, the spacing of 333 screens would change — which is a thing to measure, not a
     * thing to do by accident.
     */
    public function test_a_screen_that_defines_no_subtitle_renders_no_subtitle_element(): void
    {
        $body = (string) $this->actingAs($this->merchant)->get(route('product.index'))->getContent();

        $this->assertStringNotContainsString('ss-page-header__subtitle', $body,
            'the layout is emitting a subtitle element on a screen that defines no subtitle');
    }

    /**
     * `target="_blank"` WITHOUT `rel="noopener"` hands the opened page a handle on this one. The
     * storefront is a different origin's content in every practical sense.
     */
    public function test_the_storefront_link_does_not_hand_over_a_window_handle(): void
    {
        $body = $this->dashboard();

        $this->assertMatchesRegularExpression(
            '/<a[^>]*target="_blank"[^>]*rel="noopener noreferrer"[^>]*>\s*View storefront/',
            $body,
            'the storefront link opens a new tab without rel="noopener"'
        );
    }

    // ---------------------------------------------------------------------------------------
    //  3 — IMPORTANT STORE NOTIFICATIONS
    // ---------------------------------------------------------------------------------------

    public function test_the_notification_digest_ships_hidden_so_it_is_never_an_empty_panel(): void
    {
        $body = $this->dashboard();

        $this->assertMatchesRegularExpression(
            '/<section[^>]*data-ss-notification-digest[^>]*\shidden/',
            $body,
            'the notification region renders visible, so a merchant with nothing unread is shown '
            . 'an empty panel — which is how a region stops being read at all'
        );
    }

    public function test_the_digest_carries_the_hooks_and_the_translated_strings_it_needs(): void
    {
        $body = $this->dashboard();

        foreach ([
            'data-ss-digest-list',
            'data-ss-digest-status',
            'data-ss-digest-template',
            'data-ss-digest-open',
        ] as $hook) {
            $this->assertStringContainsString($hook, $body, "the digest is missing {$hook}");
        }

        $this->assertStringContainsString('Unread notifications', $body);
        $this->assertStringContainsString('View all notifications', $body);
        $this->assertStringContainsString(route('merchant.notifications'), $body);
    }

    /**
     * THE STATUS LINE MUST BE A SENTENCE, not a number. A `role="status"` region announcing "3"
     * tells a screen-reader user nothing at all; the template is server-rendered because the
     * JavaScript has no translator.
     */
    public function test_the_live_region_announces_a_phrase_rather_than_a_bare_count(): void
    {
        $body = $this->dashboard();

        $this->assertMatchesRegularExpression(
            '/role="status"[^>]*aria-atomic="true"/',
            $body,
            'the digest has no atomic status region, so a count change is announced piecemeal'
        );
        $this->assertStringContainsString('notifications you have not read', $body,
            'the live region has no sentence to announce, only a number');
    }

    /**
     * ONE FETCH, NOT TWO. The digest is filled from the bell's own request; a second fetch would
     * also have been a second place deciding which notification types this principal may read.
     */
    public function test_the_digest_adds_no_second_feed_request(): void
    {
        $body = $this->dashboard();

        $this->assertSame(1, substr_count($body, route('merchant.notifications.feed')),
            'the feed URL appears more than once on the page, so something is fetching it twice');
    }
}
