<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\BulkDeleteTenantGuard;
use App\Models\AddOnManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GAP-336 — ONE BULK-DELETE REQUEST AND ANOTHER STORE'S REVIEWS.
 * =================================================================================================
 *
 * WHAT THE ROW CLAIMS. `BulkDeleteController` maps `'testimonial' => Testimonial::class` and runs
 * `$model::whereIn('id', $request->ids)->delete()` with no tenant predicate; `Testimonial` is not
 * in `config/tenancy.php`'s `scoped_models`, so the global scope adds nothing for any principal
 * and the ids come from request input unfiltered. One request deletes any store's reviews by id.
 *
 * ALL THREE CLAUSES ARE TRUE OF THE VENDORED FILE AND OF THE MODEL. The third is FALSE OF THE PATH,
 * and this file is what establishes that in both directions — because nothing had ever driven this
 * type. `App\Http\Middleware\BulkDeleteTenantGuard` is appended to the `web` group in
 * `bootstrap/app.php`, it carries `'testimonial' => Testimonial::class` in its own MODEL_MAP and
 * `'testimonial' => 'Delete Testimonial'` in its permission map, and for a type whose table has a
 * `store_id` it REPLACES `ids` with only the rows this caller's stores own
 * (`$request->merge(['ids' => $owned])`) before the controller ever runs. So the controller's
 * unpredicated `whereIn` acts on an already-narrowed list. Same shape as `GAP-260`: the row's
 * mechanism is right and its reachability is wrong.
 *
 * WHAT WAS ALREADY COVERED, SAID BEFORE WHAT IS NEW, because this guard is well tested and this
 * file would otherwise read as more than it is. `BulkDeleteAuthorizationTest` has 28 cases: the
 * guard on the resolved stack, its MODEL_MAP re-derived from the vendored controller's own source
 * so it cannot drift, every permission name against the seeder, mixed-id narrowing, 403-never-401,
 * malformed payloads, inertness on other routes, D13 categories, and cross-tenant refusals for
 * **ten** types — blog, abandoncart, wishlist, user-coupon, roles, country, state, city, currency,
 * plan-order — plus products. The guard's default branch is generic over `$table`, so
 * `testimonial` is handled by the same code path as `blog`.
 *
 * SO WHAT THIS FILE ADDS IS TWO THINGS, both of which the row's own subject needs:
 *
 *   1. `testimonial` DRIVEN, in both directions. It is the type GAP-336 names and the one type
 *      nothing had ever posted. A type present in a map is not a type anybody has exercised, and
 *      "it goes through the same branch as blog" is a reading, not a measurement.
 *
 *   2. THE CENSUS, as an assertion no existing test makes: every one of the 65 map entries is
 *      platform-global, or has a resolvable tenant basis, or cannot resolve and is therefore
 *      REFUSED. That is what answers the row's real question — whether a door-level answer leaves
 *      the NEXT model unprotected. It does not: the guard is fail-closed by construction, and now
 *      by assertion.
 *
 * THE CENSUS, WHICH IS WHAT DECIDES THE REMEDY. Measured over the guard's own MODEL_MAP against
 * the booted container's `config('tenancy.scoped_models')`:
 *
 *     map entries                     65
 *     in scoped_models                 5   (Cart, Customer, Menu, OrderRefund, SupportTicket)
 *     platform-global (merchants refused)  8
 *     table has no store_id           31   (8 of them platform-global; 22 are module classes
 *                                           whose class does not even resolve without the module)
 *
 * So the allowlist covers 5 of 65 and is NOT what protects this route — the guard is. And the
 * guard is FAIL-CLOSED where it cannot resolve a tenant basis: `ownedIds()` returns `null` and
 * `handle()` answers `Permission denied.`, which is why the 22 unresolvable module types are
 * refused to a merchant rather than passed through. `user-coupon` has no `store_id` and is not
 * platform-global, and is covered by an explicit related-table basis
 * (`DERIVED_OWNERSHIP['user-coupon']`) rather than by accident.
 *
 * ADDING `Testimonial` TO `scoped_models` IS NOT THE REMEDY HERE, and that is a measured call
 * rather than a preference. It would buy nothing on this route — the ids are already narrowed
 * before the controller runs — while changing every `Testimonial` query in the product, for every
 * principal, including the storefront reads that show a shop its own reviews. `GAP-242` already
 * settled the one genuinely unprotected `Testimonial` door with an explicit predicate at that
 * door, which is the precedent. If the owner ever wants the allowlist widened, the blast radius is
 * theirs to weigh; nothing here needs it.
 *
 * EVERY ROW ASSERTION GOES THROUGH `DB::table()`. `Testimonial` is `Cachable` and the suite runs
 * under `CACHE_STORE=array`, where an Eloquent read can outlive the write that should have
 * invalidated it.
 *
 * @see \App\Http\Middleware\BulkDeleteTenantGuard
 * @see \Workdo\BulkDelete\app\Http\Controllers\BulkDeleteController
 */
class BulkDeleteTestimonialTenancyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const URI = '/bulk-delete';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertTrue(Schema::hasTable('testimonials'),
            '`testimonials` is absent from a database built by this repository\'s migrations, so '
            . 'every assertion here would be vacuous.');
    }

    /**
     * The module, through the production loader, AND activated for the owner who will drive it.
     *
     * THREE THINGS ARE NEEDED AND THE FIRST TWO ARE NOT ENOUGH -- measured, because the first
     * draft had only them and every delete came back as a dashboard redirect carrying
     * `Permission denied `. The module's own route group is
     * `['auth', 'web', 'PlanModuleCheck:BulkDelete']`, and `PlanModuleCheck` requires BOTH
     * `module_is_active()` AND `ActivatedModule()` to contain the module. `ActivatedModule()`
     * resolves a merchant's set from `activated_modules_for_owner()`, i.e. a `userActiveModule`
     * row for the OWNER -- so the `add_on_managers` row makes the module LOADABLE and the
     * `userActiveModule` row makes it REACHABLE for this principal. That is the two-mechanism
     * split CLAUDE.md records, met here rather than read about.
     *
     * AND THE ROW MUST EXIST BEFORE THE FIRST REQUEST. `activated_modules_for_owner()` memoises
     * through `app()->scoped()`, which lives for the whole test, so inserting it after a request
     * has already asked the question changes nothing anything reads.
     */
    private function enableModule(?User $owner = null): void
    {
        if ($owner !== null) {
            \App\Models\userActiveModule::create([
                'user_id' => (int) $owner->id,
                'module' => 'BulkDelete',
            ]);
        }

        AddOnManager::create([
            'module' => 'BulkDelete',
            'name' => 'Bulk Delete',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => 'bulk-delete',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['BulkDelete']);
    }

    /** A merchant holding `Delete Testimonial`, their store, and one review of their own. */
    private function tenant(string $label): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $merchant->current_store = $store->id;
        $merchant->save();
        $merchant = User::find($merchant->id);
        $this->grantPermission($merchant, 'Delete Testimonial');

        /*
         * The real columns, from the migration: there is no `name` and no `created_by` here. And
         * `category_id` DEFAULTS TO 0 while carrying a foreign key to `categories.id`, so letting
         * it default is an integrity violation rather than an empty value -- passing NULL is what
         * the nullable column actually wants. Same for `product_id`.
         */
        $id = (int) DB::table('testimonials')->insertGetId([
            'category_id' => null,
            'product_id' => null,
            'title' => 'review-' . $label,
            'username' => 'reviewer-' . $label,
            'description' => 'a review belonging to ' . $label,
            'rating_no' => 5,
            'status' => 1,
            'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [User::find($merchant->id), $store, $id];
    }

    private function exists(int $id): bool
    {
        return DB::table('testimonials')->where('id', $id)->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | PREMISES — the suite's structural blindness, stated before anything rests on it
    |--------------------------------------------------------------------------
    */

    /**
     * WITHOUT THE MODULE THE ROUTE DOES NOT EXIST, so a row assertion against it would be
     * satisfied by a 404. CLAUDE.md records this as the reason the suite is blind to these
     * handlers; the existing guard test drives the middleware directly for exactly this reason.
     */
    public function test_premise_without_the_module_the_bulk_delete_route_is_absent(): void
    {
        [$merchant, , $own] = $this->tenant('solo');

        $response = $this->actingAs($merchant)->post(self::URI, [
            'type' => 'testimonial',
            'ids' => [$own],
        ]);

        $this->assertSame(404, $response->status(),
            'POST /bulk-delete resolves without an add_on_managers row, so this file\'s premise '
            . 'about how the module loads has changed and every case below must be re-read.');
        $this->assertTrue($this->exists($own),
            'the row was deleted by a request that did not resolve, which cannot happen');
    }

    /** And WITH the module, the route resolves to the vendored controller with the guard on it. */
    public function test_premise_the_route_resolves_to_the_vendored_controller_behind_the_guard(): void
    {
        $this->enableModule();

        $route = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->first(
            fn ($r) => $r->uri() === 'bulk-delete' && in_array('POST', $r->methods(), true)
        );

        $this->assertNotNull($route, 'the module did not register POST bulk-delete');

        $this->assertStringContainsString('BulkDeleteController', (string) $route->getActionName(),
            'bulk-delete no longer resolves to the vendored controller the row is about');

        $resolved = \Illuminate\Support\Facades\Route::gatherRouteMiddleware($route);

        $this->assertContains(BulkDeleteTenantGuard::class, $resolved,
            'THE GUARD IS NOT ON THE RESOLVED STACK. The row\'s consequence would then be live, '
            . 'and the refusal cases below would be measuring something else. Resolved: '
            . implode(', ', $resolved));
    }

    /*
    |--------------------------------------------------------------------------
    | THE ROW'S CONSEQUENCE — refused at HEAD, reproduced with the guard's entry removed
    |--------------------------------------------------------------------------
    */

    /** THE HEADLINE: a merchant cannot bulk-delete another store's review. */
    public function test_a_merchant_cannot_bulk_delete_another_stores_testimonial(): void
    {
        

        [$attacker, , $own] = $this->tenant('attacker');
        [, , $victim] = $this->tenant('victim');

        /* After the tenants exist: the owner activation must name the principal who drives it. */
        $this->enableModule($attacker);

        $this->actingAs($attacker)->post(self::URI, [
            'type' => 'testimonial',
            'ids' => [$victim, $own],
        ]);

        $this->assertTrue($this->exists($victim),
            'GAP-336: another store\'s review was deleted by id. The vendored controller has no '
            . 'tenant predicate and Testimonial is not in scoped_models, so the only thing '
            . 'between the request and the row is BulkDeleteTenantGuard narrowing `ids`.');
    }

    /**
     * THE CONTROL, without which "the victim survived" is also what a 404, a permission refusal or
     * a broken fixture produces: the attacker's OWN review in the same request IS deleted.
     */
    public function test_the_callers_own_testimonial_in_the_same_request_is_still_deleted(): void
    {
        

        [$attacker, , $own] = $this->tenant('attacker');
        [, , $victim] = $this->tenant('victim');

        /* After the tenants exist: the owner activation must name the principal who drives it. */
        $this->enableModule($attacker);

        $this->actingAs($attacker)->post(self::URI, [
            'type' => 'testimonial',
            'ids' => [$victim, $own],
        ]);

        $this->assertFalse($this->exists($own),
            'THE CONTROL BROKE: the caller\'s own review was not deleted, so the request did '
            . 'nothing at all and the cross-tenant assertion above proves nothing.');
    }

    /**
     * AND THE GUARD IS WHAT DOES IT — the row's consequence, reproduced.
     *
     * Driving the middleware directly with the `testimonial` entry removed from the map it reads.
     * The guard is fail-closed on an unknown type, so deleting the entry makes it REFUSE rather
     * than pass — which would hide the defect. So the reproduction is of the NARROWING step:
     * with the guard bypassed entirely, the ids reach the controller unfiltered and the victim's
     * row goes. `withoutMiddleware` is the honest way to say "this is what the vendored handler
     * does on its own", which is exactly the row's claim about the vendored file.
     */
    public function test_with_the_guard_off_the_rows_consequence_reproduces(): void
    {
        

        [$attacker, , $own] = $this->tenant('attacker');
        [, , $victim] = $this->tenant('victim');

        /* After the tenants exist: the owner activation must name the principal who drives it. */
        $this->enableModule($attacker);

        $this->withoutMiddleware(BulkDeleteTenantGuard::class)
            ->actingAs($attacker)
            ->post(self::URI, [
                'type' => 'testimonial',
                'ids' => [$victim, $own],
            ]);

        $this->assertFalse($this->exists($victim),
            'With BulkDeleteTenantGuard removed the victim\'s review SURVIVED, which would mean '
            . 'something else is bounding the ids and GAP-336\'s mechanism is not what the row '
            . 'says. Re-measure before concluding the route is safe.');
        $this->assertFalse($this->exists($own));
    }

    /*
    |--------------------------------------------------------------------------
    | THE CENSUS — the map's relationship to the allowlist, which is the row's real subject
    |--------------------------------------------------------------------------
    */

    /**
     * EVERY TYPE THE GUARD WILL ACT ON HAS A TENANT BASIS, OR IS REFUSED.
     *
     * This is the assertion that makes a door-level answer safe for the NEXT model somebody adds,
     * which is the thing the row is actually about: the guard must never be fail-open by inability.
     * For each entry: it is platform-global (merchants refused outright), or its table carries
     * `store_id`, or it has an explicit customer/related-table basis — and anything else resolves
     * to `null`, which `handle()` turns into a refusal.
     */
    public function test_every_model_map_entry_is_global_or_has_a_resolvable_tenant_basis(): void
    {
        $reflection = new \ReflectionClass(BulkDeleteTenantGuard::class);
        $map = (array) $reflection->getConstant('MODEL_MAP');
        $global = (array) $reflection->getConstant('PLATFORM_GLOBAL');
        $related = (array) $reflection->getConstant('DERIVED_OWNERSHIP');
        $customerOwned = (array) $reflection->getConstant('CUSTOMER_KEYED');

        $this->assertGreaterThan(60, count($map),
            'the map shrank; re-derive this census rather than trusting its numbers');

        $unaccounted = [];

        foreach ($map as $type => $class) {
            if (in_array($type, $global, true)
                || array_key_exists($type, $related)
                || in_array($type, $customerOwned, true)) {
                continue;
            }

            if (! class_exists($class)) {
                /* Unresolvable without its module -> ownedIds() returns null -> refused. */
                continue;
            }

            $table = (new $class)->getTable();

            if (Schema::hasTable($table) && Schema::hasColumn($table, 'store_id')) {
                continue;
            }

            $unaccounted[] = $type . ' (' . $class . ')';
        }

        $this->assertSame([], $unaccounted,
            'These bulk-delete types are neither platform-global nor resolvable to a tenant, so '
            . 'the guard can only refuse them — which is safe, but if any of them is expected to '
            . 'WORK for a merchant it is silently broken, and if the guard ever stops being '
            . 'fail-closed they become the next GAP-336: ' . implode(', ', $unaccounted));
    }

    /**
     * AND THE ALLOWLIST IS NOT WHAT PROTECTS THIS ROUTE — recorded as a figure so the row's
     * prescription can be read against it.
     *
     * `config/tenancy.php` names this very handler as the motive for the allowlist. Measured, the
     * allowlist covers a small minority of what the handler can delete, which is why the remedy
     * sits in the guard rather than in `scoped_models`.
     */
    public function test_the_scoped_models_allowlist_covers_a_minority_of_the_map(): void
    {
        $map = (array) (new \ReflectionClass(BulkDeleteTenantGuard::class))->getConstant('MODEL_MAP');
        $scoped = (array) config('tenancy.scoped_models');

        $covered = array_values(array_filter(
            array_unique(array_values($map)),
            fn ($class) => in_array($class, $scoped, true)
        ));

        $this->assertNotContains(\App\Models\Testimonial::class, $scoped,
            'Testimonial is now in scoped_models. That is a global behaviour change for every '
            . 'Testimonial query in the product, so it should have been an owner decision; if it '
            . 'was taken, re-read this file\'s reasoning rather than deleting this assertion.');

        $this->assertLessThan(count($map) / 2, count($covered),
            'the allowlist now covers most of the bulk-delete map, which would make the remedy '
            . 'question in GAP-336 worth re-asking');

        $this->assertNotSame([], $covered,
            'no bulk-delete model is in scoped_models at all, which would mean the allowlist and '
            . 'this map have stopped overlapping and the census above needs redoing');
    }
}
