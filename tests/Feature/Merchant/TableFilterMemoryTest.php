<?php

namespace Tests\Feature\Merchant;

use App\Models\Category;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-06-5 — a merchant's filter selection survives navigation and reload, per employee.
 * =================================================================================================
 *
 * THE MEMORY IS A WORKING CONTEXT, NOT A PREFERENCE, and that distinction is the whole design.
 * P2-06-4 already saves page length, sort and column visibility, and DELIBERATELY STRIPS the
 * search term, for a reason written out at `public/js/store-syria-ds.js:718-728`: a merchant who
 * searched "refund" last Thursday comes back to four rows out of nine hundred and concludes their
 * orders are gone. That defect is TIME-SHAPED. So this memory lives in the Laravel server
 * session, which expires with the working day and dies at logout, rather than in the localStorage
 * that holds the device preferences.
 *
 * THE SESSION ALSO SETTLES "PER EMPLOYEE" FOR FREE, which no other store in this codebase does:
 *
 *   - `settings` cannot: `SettingOwnership` rewrites `created_by` to `$user->creatorId()`, so two
 *     employees of one merchant collapse onto one row.
 *   - localStorage cannot without putting a user id in the DOM, and then P2-06-4's disclosure
 *     argument — which rests on the stored material carrying "no identifiers" — would have to be
 *     re-argued rather than re-quoted.
 *   - A cookie cannot: a cookie name is a browser-global slot, so the second employee at a shared
 *     terminal overwrites the first.
 *   - A new column or table could, and is a persistent migration, which CLAUDE.md reserves to the
 *     owner. Spending that decision on the cheapest of four requirements would be the wrong call.
 *
 * And `ss_category` is a store-owned row id whose option list is deliberately store-filtered
 * against cross-tenant name disclosure. Keeping it server-side means it never sits in a browser
 * profile for the next person at that terminal.
 *
 * THE RESTORE CONDITION IS ONE BLUNT RULE — the query string is COMPLETELY EMPTY — and it is
 * blunt on purpose, because it absorbs five cases that would otherwise each need their own
 * exception and would have to be kept in agreement with each other:
 *
 *   1. the DataTables AJAX leg always carries `draw`, `columns`, `start` and `length`, so a 302
 *      can never be served to a request expecting JSON;
 *   2. `/product?id=1` is the post-create redirect — a merchant who just created a product must
 *      never land on a list that hides it;
 *   3. "Apply with every select on Any" submits `?ss_status=&ss_stock=&…`, which is present and
 *      empty, and is an explicit instruction to show everything;
 *   4. the clear signal;
 *   5. every parameter anyone adds later.
 */
class TableFilterMemoryTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;

    /**
     * `SecurityTestCase`'s own helpers, not a local reimplementation.
     *
     * WRITING MY OWN COST AN HOUR AND IS WORTH RECORDING. It created the role, attached the
     * permission and called `$user->flushCache()` — the same three steps — and `isAbleTo()`
     * still answered FALSE, so every request got the controller's `redirect()->back()`, a 302
     * that reads exactly like the feature under test misfiring rather than like a broken
     * fixture. `flushCache()` is the `Cachable` MODEL cache; Laratrust keeps its own, and the
     * base class's `grantPermission()` already handles the ordering that makes the grant
     * visible. A fixture that silently grants nothing is this suite's most productive false
     * reading, and the lesson is the cheap one: use the helper the repository already has.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);

        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        $this->grantPermission($this->merchant, 'Manage Products');
        $this->merchant = User::find($this->merchant->id);
    }

    /** THE ONE. A filter applied, then a bare visit, and the filter comes back. */
    public function test_a_bare_product_list_restores_the_last_applied_filters(): void
    {
        $this->actingAs($this->merchant)->get('/product?ss_stock=out&ss_apply=1')->assertOk();

        $this->actingAs($this->merchant)->get('/product')
            ->assertRedirect('/product?ss_stock=out');
    }

    /**
     * A FILTER IS REMEMBERED ONLY WHEN THE BAR IS SUBMITTED. Without this, every link that
     * happens to carry an `ss_` parameter — a shared url, a bookmark, a link in an email —
     * would silently become the merchant's new default.
     */
    public function test_a_filter_is_not_remembered_unless_the_bar_was_submitted(): void
    {
        $this->actingAs($this->merchant)->get('/product?ss_stock=out')->assertOk();

        $this->actingAs($this->merchant)->get('/product')->assertOk();
    }

    /**
     * THE POST-CREATE REDIRECT MUST NEVER BE INTERCEPTED. `/product?id=1` is where a merchant
     * lands after creating a product, and a filtered list that hides the thing they just made is
     * the worst version of this feature's failure mode.
     */
    public function test_the_post_create_redirect_is_never_restored_over(): void
    {
        $this->actingAs($this->merchant)->get('/product?ss_stock=out&ss_apply=1')->assertOk();

        $this->actingAs($this->merchant)->get('/product?id=1')->assertOk();
    }

    /**
     * APPLY WITH EVERYTHING ON "ANY" IS AN INSTRUCTION TO SHOW EVERYTHING, and a GET form sends
     * every control, so it arrives as present-and-empty. It must clear the memory, not be
     * ignored as "no filters to remember" and leave yesterday's narrowing in place.
     */
    public function test_applying_with_every_control_on_any_forgets_the_memory(): void
    {
        $this->actingAs($this->merchant)->get('/product?ss_stock=out&ss_apply=1')->assertOk();

        $this->actingAs($this->merchant)
            ->get('/product?ss_status=&ss_stock=&ss_category=&ss_variant=&ss_apply=1')
            ->assertOk();

        $this->actingAs($this->merchant)->get('/product')->assertOk();
    }

    /**
     * The explicit clear signal forgets, and LANDS ON A CLEAN URL.
     *
     * It has to redirect rather than render in place: without the hop the merchant would be left
     * looking at `/product?ss_clear=1`, which they would then bookmark, share, or reload — and a
     * url carrying a one-shot control signal is not a url anyone should keep. The hop is also
     * what makes Clear idempotent: the bare url it lands on finds an empty memory and renders.
     */
    public function test_clearing_forgets_the_memory_and_lands_on_a_clean_url(): void
    {
        $this->actingAs($this->merchant)->get('/product?ss_stock=out&ss_apply=1')->assertOk();

        $this->actingAs($this->merchant)->get('/product?ss_clear=1')
            ->assertRedirect(route('product.index'));

        /* And the bare visit after it is NOT re-decorated — which is the whole point. Without
           the forget, Clear would hop to `/product`, the restore would fire, and the merchant
           would be returned to the filters they just asked to leave. A loop, not a feature. */
        $this->actingAs($this->merchant)->get('/product')->assertOk();
    }

    /**
     * THE DATATABLES AJAX LEG MUST NEVER BE REDIRECTED. It expects JSON; a 302 to an HTML page
     * would blank the table. It always carries `draw`, so the empty-query rule already excludes
     * it — this pins that rather than trusting it.
     */
    public function test_the_datatables_ajax_leg_is_never_redirected(): void
    {
        $this->actingAs($this->merchant)->get('/product?ss_stock=out&ss_apply=1')->assertOk();

        $this->actingAs($this->merchant)
            ->get('/product?draw=1&start=0&length=10', ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();
    }

    /**
     * ONE EMPLOYEE'S MEMORY IS NOT ANOTHER'S. This is the acceptance's "per employee", and the
     * session is what delivers it: a second login is a second session.
     */
    public function test_one_employees_filters_are_not_restored_for_another(): void
    {
        $this->actingAs($this->merchant)->get('/product?ss_stock=out&ss_apply=1')->assertOk();

        $colleague = $this->makeStaff($this->merchant);
        $this->grantPermission($colleague, 'Manage Products');
        $colleague = User::find($colleague->id);

        $this->actingAs($colleague)->get('/product')->assertOk();
    }

    /**
     * A MEMORY FROM ANOTHER STORE IS NOT RESTORED, PROVED ON A STORE-NEUTRAL FILTER.
     *
     * THE OBVIOUS VERSION OF THIS TEST PASSES FOR THE WRONG REASON, and the bite test is what
     * caught it: written with `ss_category`, it still passed when the store was REMOVED from the
     * session key, because a category id from store A fails `categoryIsOwned()` under store B
     * and is dropped on the way out. Two independent guards, one test, and the test could not
     * tell which one was holding — so deleting the store axis would have looked safe.
     *
     * `ss_stock` has no store dimension at all, so nothing but the key can prevent the restore.
     * The `ss_category` case below still earns its place: it pins the revalidation.
     */
    public function test_a_store_neutral_memory_is_not_restored_in_another_store(): void
    {
        $this->actingAs($this->merchant)->get('/product?ss_stock=out&ss_apply=1')->assertOk();

        $other = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $other->id;
        $this->merchant->save();
        $this->merchant = User::find($this->merchant->id);

        $this->actingAs($this->merchant)->get('/product')->assertOk();
    }

    /**
     * A REMEMBERED CATEGORY THAT IS NOT THE CALLER'S IS DROPPED. Separate from the key: this is
     * the guard that stops a stale or foreign id narrowing the list to zero rows — the "my
     * products are gone" screen — rather than the one that stops the memory crossing stores.
     */
    public function test_a_memory_from_another_store_is_not_restored(): void
    {
        /* `forceCreate`: `categories.slug` is NOT NULL and is not in `$fillable`, so `create()`
           drops it and the insert fails on the constraint. */
        /* `forceCreate` and NO `created_by`: `categories.slug` is NOT NULL and not fillable, so
           `create()` drops it and the insert fails the constraint — and the table has no
           `created_by` column at all, so passing one fails differently. Both measured against
           the real schema rather than inferred from the sibling tables that do have it. */
        $category = Category::forceCreate([
            'name' => 'Memory Cat', 'slug' => 'memory-cat-' . uniqid(),
            'store_id' => $this->store->id,
        ]);

        $this->actingAs($this->merchant)
            ->get('/product?ss_category=' . $category->id . '&ss_apply=1')->assertOk();

        $other = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $other->id;
        $this->merchant->save();

        $this->actingAs($this->merchant)->get('/product')->assertOk();
    }

    // ---------------------------------------------------------------------------------------
    // The receipt, and the Clear button it replaced
    // ---------------------------------------------------------------------------------------

    /**
     * A FILTERED LIST SAYS SO, IN WORDS. This is the condition that makes restoring safe at all:
     * P2-06-4 refused to restore a search term because the merchant could not tell the list was
     * narrowed, and a restored filter is that same screen with a dropdown instead of a box.
     */
    public function test_a_filtered_list_names_its_filters(): void
    {
        $html = $this->actingAs($this->merchant)->get('/product?ss_stock=out')
            ->assertOk()->getContent();

        $this->assertStringContainsString('ss-filterbar__summary', $html,
            'a filtered list renders no summary, so the narrowing is silent');
        $this->assertStringContainsString('Out of stock', $html,
            'the summary does not name the filter that is in force');
    }

    /** And an UNFILTERED list says nothing, or the receipt is noise rather than a signal. */
    public function test_an_unfiltered_list_shows_no_summary(): void
    {
        $html = $this->actingAs($this->merchant)->get('/product?ss_status=&ss_stock=')
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('ss-filterbar__summary', $html);
    }

    /**
     * THE RECEIPT NAMES A FILTER ONLY IF IT IS REALLY IN FORCE. `?ss_stock=banana` passes the
     * shape checks — a short non-empty string — but `applyFilters()` ignores it, so nothing is
     * narrowed. A summary reading "Stock: banana" would describe a filter that does not exist,
     * which is worse than no summary at all.
     */
    public function test_an_unrecognised_value_is_not_announced(): void
    {
        $html = $this->actingAs($this->merchant)->get('/product?ss_stock=banana')
            ->assertOk()->getContent();

        /* Scoped to the SUMMARY, and the first version of this assertion was wrong in a way
           worth keeping: it asserted `banana` appears nowhere in the page, and it does appear —
           in the DataTable's `ajax.url`, which is `url()->full()` and therefore carries the
           whole query string by design. Asserting its absence from the page would have been a
           test demanding that a correct mechanism stop working. */
        $this->assertStringNotContainsString('ss-filterbar__summary', $html);
        $this->assertStringNotContainsString('Stock: banana', $html);
    }

    /**
     * THE CLEAR BUTTON WAS ON WHEN NOTHING WAS FILTERED, and that is a defect this slice fixed
     * rather than inherited. Its render condition was `query('ss_status') !== null`, and a GET
     * form submits every control, so Apply-with-everything-on-Any produced
     * `?ss_status=&ss_stock=&…` — present, empty, and `!== null`. The one control whose job is
     * to signal "something is filtered" signalled it on an unfiltered list.
     */
    public function test_clear_filters_is_not_offered_on_an_unfiltered_list(): void
    {
        $html = $this->actingAs($this->merchant)
            ->get('/product?ss_status=&ss_stock=&ss_category=&ss_variant=')
            ->assertOk()->getContent();

        $this->assertStringNotContainsString('Clear filters', $html,
            'an unfiltered list offers to clear filters that are not on');
    }

    public function test_clear_filters_is_offered_on_a_filtered_list(): void
    {
        $html = $this->actingAs($this->merchant)->get('/product?ss_stock=out')
            ->assertOk()->getContent();

        $this->assertStringContainsString('Clear filters', $html,
            'a filtered list does not offer a way out of the filter');
    }

    // ---------------------------------------------------------------------------------------
    // THE ORDER SCREEN, which this slice shipped UNTESTED and which independent review is what
    // caught. Both halves use the same service, and that is exactly why one set of tests over
    // one screen was not enough: the service's only per-screen behaviour — the vocabulary — was
    // the part that was wrong, and the product screen could not see it.
    // ---------------------------------------------------------------------------------------

    private function merchantWhoMaySeeOrders(): User
    {
        $this->grantPermission($this->merchant, 'Manage Order');

        return User::find($this->merchant->id);
    }

    public function test_a_bare_order_list_restores_the_last_applied_filters(): void
    {
        $m = $this->merchantWhoMaySeeOrders();

        $this->actingAs($m)->get('/order?ss_status=6&ss_apply=1')->assertOk();

        $this->actingAs($m)->get('/order')->assertRedirect('/order?ss_status=6');
    }

    /**
     * THE RECEIPT MUST NAME THE ORDER STATUS CORRECTLY, for every one of the nine.
     *
     * THE BUG THIS PINS: the vocabulary was the PRODUCT one, so `?ss_status=1` — which lists
     * DELIVERED orders — was announced as "Status: Active", and statuses 2 to 8 were announced
     * as nothing at all. The second half is the dangerous one, because the Clear button's
     * render condition is the same predicate: no label meant no receipt AND no way out of a
     * filter that now survives navigation.
     *
     * @dataProvider orderStatusLabels
     */
    public function test_the_receipt_names_each_order_status(string $value, string $expected): void
    {
        $m = $this->merchantWhoMaySeeOrders();

        $html = $this->actingAs($m)->get('/order?ss_status=' . $value)->assertOk()->getContent();

        $this->assertStringContainsString('ss-filterbar__summary', $html,
            "a list filtered to status {$value} renders no receipt, so the narrowing is silent "
            . 'and the Clear button is suppressed with it');
        $this->assertStringContainsString('Status: ' . $expected, $html,
            "the receipt does not name status {$value} as \"{$expected}\"");
        $this->assertStringContainsString('Clear filters', $html,
            "a list filtered to status {$value} offers no way out");
    }

    /** The nine values of `orders.delivered_status`, from `HomeController::ORDER_STATUS_*`. */
    public static function orderStatusLabels(): array
    {
        return [
            'pending' => ['0', 'Pending'],
            'delivered' => ['1', 'Delivered'],
            'cancel' => ['2', 'Cancel'],
            'return' => ['3', 'Return'],
            'confirmed' => ['4', 'Confirmed'],
            'picked up' => ['5', 'Picked Up'],
            'shipped' => ['6', 'Shipped'],
            'partially paid' => ['7', 'Partially Paid'],
            'pre order' => ['8', 'Pre Order'],
        ];
    }

    /** The product screen's own vocabulary must not have been disturbed by making it per-screen. */
    public function test_the_product_receipt_still_names_the_product_statuses(): void
    {
        $html = $this->actingAs($this->merchant)->get('/product?ss_status=1')
            ->assertOk()->getContent();

        $this->assertStringContainsString('Status: Active', $html);
    }

    /**
     * THE RECEIPT USES THE WORDS THE SELECT USES. "With variants" beside a select reading "Has
     * variants" is two vocabularies for one fact, which is the same class of defect as the
     * wrong status map, just quieter.
     */
    public function test_the_receipt_uses_the_same_words_as_the_control(): void
    {
        $html = $this->actingAs($this->merchant)->get('/product?ss_variant=1')
            ->assertOk()->getContent();

        $this->assertStringContainsString('Variants: Has variants', $html);
        $this->assertStringNotContainsString('With variants', $html);
    }

    /**
     * THE DATATABLES JSON LEG, driven the way the house drives it.
     *
     * The first version of this sent `X-Requested-With` and no `Accept`, so `wantsJson()` was
     * false and yajra rendered the Blade view — it proved a query-bearing request is not
     * redirected, which is true but is not the JSON path. A change that redirected only
     * `wantsJson()` requests would have passed it.
     */
    public function test_the_json_leg_of_the_order_table_is_never_redirected(): void
    {
        $m = $this->merchantWhoMaySeeOrders();

        $this->actingAs($m)->get('/order?ss_status=6&ss_apply=1')->assertOk();

        $this->actingAs($m)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])
            ->getJson('/order?draw=1&start=0&length=10')
            ->assertOk();
    }
}
