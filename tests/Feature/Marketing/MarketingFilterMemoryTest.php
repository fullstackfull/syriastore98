<?php

namespace Tests\Feature\Marketing;

use App\Models\Permission;
use App\Models\User;
use App\Services\TableFilterMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * A MARKETING FILTER SURVIVES NAVIGATION, AND SAYS SO. MKT-002 part 2b.
 * =================================================================================================
 *
 * The three-part pattern `OrderController::index` and `ProductController::index` already carry, now
 * on the five Marketing screens. Each part exists because of a named failure:
 *
 *   1. `TableFilterMemory::CLEAR` -> forget, then redirect to the BARE route. Without the redirect
 *      the clearing request still carries the filters it was clearing.
 *   2. `shouldRestore()` -> recall, then redirect WITH the remembered query. THE REDIRECT IS THE
 *      MECHANISM, not a convenience: yajra sets the table's `ajax.url` to `url()->full()` as
 *      evaluated when the page RENDERS, so filling the controls or rewriting the query with
 *      `history.replaceState` would leave the table's second leg requesting the UNFILTERED url --
 *      the page would look filtered and the rows would not be. A 302 makes a restored request
 *      byte-identical to a typed one.
 *   3. `remember()` -> store what was asked for, so the next visit can restore it.
 *
 * ALL THREE INSIDE THE PERMISSION GATE, deliberately: a caller who may not see the list never reads
 * or writes the memory, and never learns from a redirect that one exists.
 *
 * GAP-218 APPLIES TO ALL FIVE SCREENS -- they gate on `isAbleTo()` alone, so they refuse the
 * platform operator and refuse a tenant owner whose role does not carry the name. That is NOT fixed
 * here; these tests grant each permission explicitly, as `MarketingLinksToAnalyticsTest` does, so
 * they measure the memory rather than the gate.
 */
class MarketingFilterMemoryTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * screen => [route name, permission, a filter that narrows it]
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, string>}>
     */
    private function screens(): array
    {
        return [
            'coupon' => ['coupon.index', 'Manage Coupon', ['ss_status' => '1']],
            'newsletter' => ['newsletter.index', 'Manage Newsletter', ['ss_source' => 'guest']],
            'flash-sale' => ['flash-sale.index', 'Manage Flash Sale', ['ss_window' => 'running']],
            'wishlist' => ['wishlist.index', 'Manage Wishlist', ['ss_status' => '1']],
            'abandon-cart' => ['abandon.carts.handled', 'Manage Cart', ['ss_items' => 'several']],
        ];
    }

    /**
     * A request as the filter bar really makes it: the filters PLUS `ss_apply`.
     *
     * `TableFilterMemory::remember()` returns early without `self::APPLY`, and that is the design
     * rather than an obstacle -- the bar's submit button is `name="ss_apply"` and "its PRESENCE is
     * what writes the filter memory". So an arbitrary url carrying filter parameters narrows the
     * list for that one request and is NOT remembered, which is what stops a link someone was sent
     * from becoming their sticky filter. The first version of this test omitted it and read the
     * resulting silence as a missing feature.
     *
     * @param  array<string, string>  $filter
     * @return array<string, string>
     */
    private function applied(array $filter): array
    {
        return $filter + [TableFilterMemory::APPLY => '1'];
    }

    private function merchantWith(string $permission): User
    {
        [$merchant] = $this->makeTenant();

        Permission::firstOrCreate(['name' => $permission], ['display_name' => $permission]);
        $this->grantPermission(User::find($merchant->id), $permission);

        return User::find($merchant->id);
    }

    public function test_every_marketing_screen_remembers_the_filter_it_was_given(): void
    {
        foreach ($this->screens() as $screen => [$route, $permission, $filter]) {
            $this->actingAs($this->merchantWith($permission))
                ->get(route($route, $this->applied($filter)))
                ->assertSuccessful();

            $this->assertSame(
                $filter,
                TableFilterMemory::recall($screen),
                "{$screen} did not remember the filter it was just shown, so the next visit forgets it"
            );
        }
    }

    public function test_every_marketing_screen_restores_a_remembered_filter_with_a_redirect(): void
    {
        foreach ($this->screens() as $screen => [$route, $permission, $filter]) {
            $merchant = $this->merchantWith($permission);

            $this->actingAs($merchant)->get(route($route, $this->applied($filter)))->assertSuccessful();

            /* The bare route on the NEXT visit must 302 to the remembered query -- not render the
               unfiltered list, and not render a filtered page whose table then asks for the
               unfiltered url. */
            $this->actingAs($merchant)
                ->get(route($route))
                ->assertRedirect(route($route, $filter))
                ->assertSessionHas('ss_filters_restored', true);
        }
    }

    public function test_every_marketing_screen_clears_to_the_bare_route(): void
    {
        foreach ($this->screens() as $screen => [$route, $permission, $filter]) {
            $merchant = $this->merchantWith($permission);

            $this->actingAs($merchant)->get(route($route, $this->applied($filter)))->assertSuccessful();

            $this->actingAs($merchant)
                ->get(route($route, [TableFilterMemory::CLEAR => '1']))
                ->assertRedirect(route($route));

            $this->assertSame(
                [],
                TableFilterMemory::recall($screen),
                "{$screen} kept its remembered filter after Clear, so the merchant cannot get out"
            );
        }
    }

    public function test_a_cleared_screen_does_not_immediately_restore_what_was_cleared(): void
    {
        /* The loop this closes: forget, then redirect to the bare route -- whose own handler asks
           `shouldRestore()`. If Clear left anything behind, the very next request would put it back
           and the button would appear to do nothing. */
        foreach ($this->screens() as $screen => [$route, $permission, $filter]) {
            $merchant = $this->merchantWith($permission);

            $this->actingAs($merchant)->get(route($route, $this->applied($filter)))->assertSuccessful();
            $this->actingAs($merchant)->get(route($route, [TableFilterMemory::CLEAR => '1']))->assertRedirect(route($route));

            $this->actingAs($merchant)
                ->get(route($route))
                ->assertSuccessful();
        }
    }

    public function test_the_memory_is_not_written_for_a_caller_who_may_not_see_the_list(): void
    {
        /*
         * INSIDE THE GATE. A principal without the permission is refused, and must not have written
         * the filter it asked for -- otherwise the memory becomes a scratch space any authenticated
         * user can fill, and a later legitimate visit by that same user restores something they were
         * never shown.
         *
         * THIS TEST PASSED BEFORE THE FEATURE EXISTED, because nothing wrote the memory at all. It is
         * kept because it is the right assertion and it discriminates NOW -- the other five were red
         * at the same moment, so the suite as a whole was not fooled -- but on its own it proved
         * nothing, and saying so is cheaper than someone later trusting it in isolation.
         */
        foreach ($this->screens() as $screen => [$route, , $filter]) {
            [$merchant] = $this->makeTenant();      // no permission granted at all

            $this->actingAs(User::find($merchant->id))->get(route($route, $this->applied($filter)));

            $this->assertSame(
                [],
                TableFilterMemory::recall($screen),
                "{$screen} wrote the filter memory for a caller it refused"
            );
        }
    }

    public function test_a_filtered_url_without_apply_narrows_but_is_not_remembered(): void
    {
        /* The rule `remember()`'s early return encodes, asserted rather than assumed: a link a
           colleague sent, or a bookmark, filters THAT request and does not become the recipient's
           sticky filter. Learned by getting it wrong -- the first version of this file treated the
           absence of a write as a missing feature. */
        foreach ($this->screens() as $screen => [$route, $permission, $filter]) {
            $this->actingAs($this->merchantWith($permission))
                ->get(route($route, $filter))
                ->assertSuccessful();

            $this->assertSame(
                [],
                TableFilterMemory::recall($screen),
                "{$screen} remembered a filter that arrived without an explicit Apply"
            );
        }
    }

    public function test_a_parameter_outside_the_vocabulary_is_never_remembered(): void
    {
        /* The vocabulary IS the allowlist -- its own docblock says that is what stops a future
           `?redirect=` or `?page=` becoming part of a merchant's remembered filters. */
        foreach ($this->screens() as $screen => [$route, $permission, $filter]) {
            $this->actingAs($this->merchantWith($permission))
                ->get(route($route, $this->applied($filter) + ['redirect' => 'https://evil.test', 'page' => '7']))
                ->assertSuccessful();

            $this->assertSame(
                $filter,
                TableFilterMemory::recall($screen),
                "{$screen} remembered a parameter that is not one of its filters"
            );
        }
    }
}
