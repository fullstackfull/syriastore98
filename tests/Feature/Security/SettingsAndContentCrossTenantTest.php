<?php

namespace Tests\Feature\Security;

use App\Models\Page;
use App\Models\PixelFields;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2.5-PKG-B — THE F-SEC-01 SHAPE BEYOND BRANDS AND LABELS.
 * ================================================================================================
 *
 * F-SEC-01 was a merchant reading, renaming, TAKING and deleting another merchant's brands and
 * labels. Fixing it raised the obvious question -- how many more of these are there? -- and the
 * honest answer needed a census rather than a hunch, because the existing coverage had already
 * looked like coverage and wasn't: `BrandLabelStatusAuthorizationTest` has fifteen tests and every
 * one drives the two methods that WERE hardened.
 *
 * `docs/development/phase-2.5/tools/census-unowned-model-resolution.php` walks PHP's own token
 * stream over all 132 controllers in `app/Http/Controllers/` and reports single-record methods
 * that resolve a model from caller-supplied id where the model is absent from
 * `config/tenancy.php`'s `scoped_models` -- so `StoreScope` adds no predicate for any principal.
 * It found 40 that also WRITE.
 *
 * THE CENSUS IS A SHAPE DETECTOR AND ITS OUTPUT IS NOT EVIDENCE. It passed four oracles before
 * being believed -- it surfaces all seven proved-holed pre-fix methods, it reports the fixed tree
 * as fixed, and it agrees with an independent human review on `CategoryController` and with the
 * registered policy on `SavedViewController` -- and it STILL only says "look here". Three of its
 * first readings were wrong: an ownership ASSIGNMENT read as a check (hiding the takeover
 * primitive itself), `\Auth::user()->creatorId()` billing a storage quota read as an ownership
 * check, and the detector matching its own comment describing the line it had deleted.
 *
 * So this file DRIVES them with real requests. Whatever it reports is a measurement.
 *
 * SCOPE, kept current: nine cross-tenant write/read paths across PixelFields, Tax and Page; two
 * `show()` surfaces; and `POST update-page-status`, which the census never examined and a human
 * review found. The defects below are described in the PAST tense on purpose -- they are repaired
 * in this same change. An earlier draft of this docblock described them in the present tense while
 * the fix sat beside it, and this project's own tooling has now mistaken prose for code eight
 * times, twice inside this very work package.
 *
 * WHY THESE THREE, out of fifteen tenant-data models:
 *
 *   PixelFieldsController   HAD no permission check on edit() or update() at all, and
 *                           `destroy()` gated only on `$user->type == 'admin'` -- the type string
 *                           every merchant owner carries. `update()` ALSO reassigned store_id,
 *                           the takeover primitive.
 *   TaxController           `Edit Tax` / `Delete Tax` were checked, the owner never; `update()`
 *                           reassigned store_id.
 *   PageController          `Edit Menu` / `Delete Page` were checked, the owner never; `update()`
 *                           reassigned store_id. And `updateStatus()` checked NOTHING.
 *
 * SEVERITY, measured rather than guessed, because it was understated once on the sibling brand
 * fix. `Utility::addNewData()` runs on EVERY login (AuthenticatedSessionController) and grants the
 * shared global `admin` role -- the role every self-registered merchant is attached at
 * registration -- `Edit Menu`, `Manage/Create/Edit/Delete Page` and
 * `Manage/Create/Show/Edit/Delete Tax`. So no admin intervention and no role editing was needed
 * for any of this: it was live for every merchant by default. PixelFields was broader still,
 * reachable by any authenticated principal holding zero permissions.
 *
 * Every one of these routes is registered on the `web` + `auth` middleware stack -- verified from
 * `route:list`, not assumed -- so an authenticated merchant reaches all of them.
 *
 * EVERY GROUP CARRIES A POSITIVE CONTROL, and that is not ceremony. A controller that refused
 * everything would satisfy every cross-tenant assertion here; and in the brand work a fixture that
 * invented a column killed four tests inside the fixture, three of them failing in a way that
 * looked exactly like the hole they were written to prove. The control failing alongside them is
 * the only reason that was caught.
 */
class SettingsAndContentCrossTenantTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * Raw reads throughout. These models are `Cachable`, and CLAUDE.md records three measured
     * false readings where an Eloquent read served the pre-write row under `CACHE_STORE=array`.
     */
    private function raw(string $table, int $id): ?object
    {
        return \DB::table($table)->where('id', $id)->first();
    }

    // =============================================================================================
    // PIXEL FIELDS -- the sharpest row in the census: no permission check anywhere on the read or
    // the write path. A pixel row carries a third-party tracking id that the storefront renders.
    // =============================================================================================

    private function makePixel(int $storeId, string $pixelId): PixelFields
    {
        return PixelFields::forceCreate([
            'platform' => 'facebook',
            'pixel_id' => $pixelId,
            'store_id' => $storeId,
        ]);
    }

    public function test_a_merchant_cannot_open_another_tenants_pixel_edit_form(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();

        $foreign = $this->makePixel($victimStore->id, 'VICTIM-PIXEL-1');

        $response = $this->actingAs($attacker)->get('/pixel-setting/' . $foreign->id . '/edit');

        // 404, not merely "not 200". The guard returns exactly 404, and asserting the status the
        // guard actually produces is what stops this test decaying: with `assertNotSame(200, ...)`
        // it would stay green off a 302 from some future permission limb and silently stop
        // testing ownership at all. Raised by the tenant-authz review.
        $response->assertStatus(404);
        $response->assertDontSee('VICTIM-PIXEL-1', false);
    }

    public function test_a_merchant_cannot_update_or_take_another_tenants_pixel(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker, $attackerStore] = $this->makeTenant();

        $foreign = $this->makePixel($victimStore->id, 'VICTIM-PIXEL-1');

        $this->actingAs($attacker)->put('/pixel-setting/' . $foreign->id, [
            'platform' => 'facebook',
            'pixel_id' => 'ATTACKER-PIXEL',
        ]);

        $after = $this->raw('pixel_fields', $foreign->id);

        $this->assertSame(
            'VICTIM-PIXEL-1',
            $after->pixel_id,
            "A merchant rewrote another tenant's tracking pixel id. update() checks NO permission "
            . 'and NO owner.'
        );
        $this->assertSame(
            (int) $victimStore->id,
            (int) $after->store_id,
            'A pixel row changed hands: update() assigns `store_id = $store->id` from the '
            . "CALLER's current store, so a foreign row reaching that line is moved."
        );
        $this->assertNotSame((int) $attackerStore->id, (int) $after->store_id);
    }

    public function test_a_merchant_cannot_delete_another_tenants_pixel(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();

        $foreign = $this->makePixel($victimStore->id, 'VICTIM-PIXEL-1');

        $this->actingAs($attacker)->delete('/pixel-setting/' . $foreign->id);

        $this->assertNotNull(
            $this->raw('pixel_fields', $foreign->id),
            "A merchant deleted another tenant's pixel row. destroy() gates on "
            . "`\$user->type == 'admin'`, which is the type string every merchant carries."
        );
    }

    public function test_the_pixel_owner_can_still_do_all_of_it(): void
    {
        [$owner, $store] = $this->makeTenant();
        $own = $this->makePixel($store->id, 'OWN-PIXEL');

        $this->actingAs($owner)->get('/pixel-setting/' . $own->id . '/edit')->assertStatus(200);

        $this->actingAs($owner)->put('/pixel-setting/' . $own->id, [
            'platform' => 'facebook',
            'pixel_id' => 'OWN-PIXEL-UPDATED',
        ]);
        $this->assertSame('OWN-PIXEL-UPDATED', $this->raw('pixel_fields', $own->id)->pixel_id,
            'The owner can no longer update their own pixel row.');
        $this->assertSame((int) $store->id, (int) $this->raw('pixel_fields', $own->id)->store_id);

        $this->actingAs($owner)->delete('/pixel-setting/' . $own->id);
        $this->assertNull($this->raw('pixel_fields', $own->id),
            'The owner can no longer delete their own pixel row.');
    }

    // =============================================================================================
    // TAX -- `taxes` carries only `name` and `store_id`, so the damage is the row's IDENTITY and
    // its OWNERSHIP, not a rate. Moving one out of the victim's store removes their tax entity.
    // =============================================================================================

    private function makeTax(int $storeId, string $name): Tax
    {
        return Tax::forceCreate(['name' => $name, 'store_id' => $storeId]);
    }

    public function test_a_merchant_cannot_open_another_tenants_tax_edit_form(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Tax');

        $foreign = $this->makeTax($victimStore->id, 'VictimTax');

        $response = $this->actingAs($attacker)->get('/taxes/' . $foreign->id . '/edit');

        // 404 exactly -- see the note on the pixel edit test. The attacker is GRANTED `Edit Tax`
        // above precisely so the permission limb cannot be what refuses them.
        $response->assertStatus(404);
        $response->assertDontSee('VictimTax', false);
    }

    public function test_a_merchant_cannot_update_or_take_another_tenants_tax(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker, $attackerStore] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Tax');

        $foreign = $this->makeTax($victimStore->id, 'VictimTax');

        $this->actingAs($attacker)->put('/taxes/' . $foreign->id, ['name' => 'TakenOver']);

        $after = $this->raw('taxes', $foreign->id);
        $this->assertSame('VictimTax', $after->name, "A merchant renamed another tenant's tax.");
        $this->assertSame((int) $victimStore->id, (int) $after->store_id,
            'A tax row changed hands: update() assigns store_id rather than checking it.');
        $this->assertNotSame((int) $attackerStore->id, (int) $after->store_id);
    }

    public function test_a_merchant_cannot_delete_another_tenants_tax(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Delete Tax');

        $foreign = $this->makeTax($victimStore->id, 'VictimTax');

        $this->actingAs($attacker)->delete('/taxes/' . $foreign->id);

        $this->assertNotNull($this->raw('taxes', $foreign->id),
            "A merchant deleted another tenant's tax.");
    }

    public function test_the_tax_owner_can_still_do_all_of_it(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Edit Tax');
        $this->grantPermission($owner, 'Delete Tax');

        $own = $this->makeTax($store->id, 'OwnTax');

        $this->actingAs($owner)->get('/taxes/' . $own->id . '/edit')->assertStatus(200);

        $this->actingAs($owner)->put('/taxes/' . $own->id, ['name' => 'Renamed']);
        $this->assertSame('Renamed', $this->raw('taxes', $own->id)->name,
            'The owner can no longer rename their own tax.');
        $this->assertSame((int) $store->id, (int) $this->raw('taxes', $own->id)->store_id);

        $this->actingAs($owner)->delete('/taxes/' . $own->id);
        $this->assertNull($this->raw('taxes', $own->id),
            'The owner can no longer delete their own tax.');
    }

    // =============================================================================================
    // PAGE -- a CMS page rendered on the storefront. `update()` is gated on `Edit Menu`, which is
    // a separate oddity worth noting but not this test's subject.
    // =============================================================================================

    private function makePage(int $storeId, string $name): Page
    {
        return Page::forceCreate([
            'page_name' => $name,
            'page_slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'page_content' => 'victim content',
            'page_status' => 1,
            'is_default' => 0,
            'store_id' => $storeId,
        ]);
    }

    public function test_a_merchant_cannot_update_or_take_another_tenants_page(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker, $attackerStore] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Menu');

        $foreign = $this->makePage($victimStore->id, 'VictimPage');

        $this->actingAs($attacker)->put('/pages/' . $foreign->id, [
            'page_name' => 'TakenOver',
            'page_slug' => 'taken-over',
        ]);

        $after = $this->raw('pages', $foreign->id);
        $this->assertSame('VictimPage', $after->page_name,
            "A merchant rewrote another tenant's storefront page.");
        $this->assertSame((int) $victimStore->id, (int) $after->store_id,
            'A page changed hands: update() assigns store_id rather than checking it.');
        $this->assertNotSame((int) $attackerStore->id, (int) $after->store_id);
    }

    public function test_a_merchant_cannot_open_another_tenants_page_edit_form(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Menu');

        $foreign = $this->makePage($victimStore->id, 'VictimPage');

        $response = $this->actingAs($attacker)->get('/pages/' . $foreign->id . '/edit');

        $response->assertStatus(404);
        $response->assertDontSee('victim content', false);
    }

    public function test_a_merchant_cannot_delete_another_tenants_page(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Delete Page');

        $foreign = $this->makePage($victimStore->id, 'VictimPage');

        $this->actingAs($attacker)->delete('/pages/' . $foreign->id);

        $this->assertNotNull($this->raw('pages', $foreign->id),
            "A merchant deleted another tenant's storefront page.");
    }

    /**
     * `POST update-page-status` -- THE ONE THE CENSUS DID NOT EVEN LOOK AT.
     *
     * Found by the tenant-authz review of the fix above, not by the census, and the reason is
     * worth recording because it is a lesson about the instrument rather than about this route:
     * the census filters to a list of method names, and that list held `changeStatus` but not
     * `updateStatus`. `resolvedModels()` would have matched `Page::findOrFail(` perfectly well. A
     * name list can only find the spellings its author thought of.
     *
     * `PageController::updateStatus()` has NO permission check, NO ownership check and no type
     * check of any kind. It reads `pageId` straight from the request body, and `Page` is absent
     * from `scoped_models`. So any authenticated, verified web principal -- a merchant of any
     * tenant, or a staff member holding ZERO Laratrust permissions -- can take another store's
     * live storefront page offline. It is not a dead route: `PageDataTable:26-34` renders the
     * toggle for every row with no permission wrapper.
     */
    public function test_a_merchant_cannot_publish_or_unpublish_another_tenants_page(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        // NO permission granted to the attacker at all -- that is the point of this one.

        $foreign = $this->makePage($victimStore->id, 'VictimPage');
        $this->assertSame(1, (int) $this->raw('pages', $foreign->id)->page_status,
            'Fixture premise: the victim page starts published.');

        $this->actingAs($attacker)->post('/update-page-status', [
            'pageId' => $foreign->id,
            'isActivated' => 'false',
        ]);

        $this->assertSame(
            1,
            (int) $this->raw('pages', $foreign->id)->page_status,
            "A merchant took another tenant's storefront page offline through "
            . 'POST update-page-status, holding no permissions whatsoever.'
        );
    }

    public function test_the_page_owner_can_still_toggle_their_own_page_status(): void
    {
        // The positive control. Note it grants NO permission either, because the endpoint has
        // never required one -- so this pins the pre-existing behaviour for the owner and proves
        // the new guard refuses on OWNERSHIP rather than by refusing everyone.
        [$owner, $store] = $this->makeTenant();
        $own = $this->makePage($store->id, 'OwnPage');

        $this->actingAs($owner)->post('/update-page-status', [
            'pageId' => $own->id,
            'isActivated' => 'false',
        ])->assertStatus(200);

        $this->assertSame(0, (int) $this->raw('pages', $own->id)->page_status,
            'The owner can no longer toggle their own page status.');
    }

    // =============================================================================================
    // show() -- FIVE CONTROLLERS, ONE SHAPE, TWO DIFFERENT SEVERITIES.
    //
    // Page, PixelFields, ProductBrand and Category all have an EMPTY `show()` (`//`). Route model
    // binding still resolves the row first, so the response was an empty 200 for a row existing in
    // ANY store and 404 only for one existing nowhere -- an existence oracle over every id.
    //
    // `TaxController::show()` is not empty: it renders `taxes.show`, whose breadcrumb at line 7
    // prints `$tax_option->name`. That is an actual cross-tenant READ, not an oracle, and the
    // census is the reason it was looked at -- it was the one row of the five whose body did
    // something.
    // =============================================================================================

    public function test_the_empty_show_routes_are_not_cross_tenant_existence_oracles(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();

        $foreignPage = $this->makePage($victimStore->id, 'VictimPage');
        $foreignPixel = $this->makePixel($victimStore->id, 'VICTIM-PIXEL-1');

        // A row that exists in the victim's store, and an id that exists nowhere, must be
        // INDISTINGUISHABLE. Asserting 404 on each separately would pass for a method that 404s
        // everything; comparing the two is what makes this an oracle test.
        // THIS IS THE ONE TEST IN THE FILE WITH NO POSITIVE CONTROL, and the review was right to
        // name it. It compares two refusals, so a show() that 404'd the OWNER too would stay
        // green. Tolerated for two specific reasons, written here rather than left implicit:
        // show() has an empty body, so there is nothing it could withhold from the owner; and
        // `callerOwnsPage()` / `callerOwnsPixel()` -- the very helpers this calls -- are
        // positively controlled by the edit/update/destroy owner tests in this same class.
        $cases = [
            'pages' => ['/pages/' . $foreignPage->id, '/pages/99999901'],
            'pixel-setting' => ['/pixel-setting/' . $foreignPixel->id, '/pixel-setting/99999902'],
        ];

        foreach ($cases as $label => [$foreignUrl, $absentUrl]) {
            $foreignStatus = $this->actingAs($attacker)->get($foreignUrl)->status();
            $absentStatus = $this->actingAs($attacker)->get($absentUrl)->status();

            $this->assertSame(
                $absentStatus,
                $foreignStatus,
                "{$label}: show() answers differently for another tenant's row ({$foreignStatus}) "
                . "than for an id that exists nowhere ({$absentStatus}), so it reports whether any "
                . 'store owns that id.'
            );
        }
    }

    public function test_a_merchant_cannot_read_another_tenants_tax_through_show(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Show Tax');

        $foreign = $this->makeTax($victimStore->id, 'VictimTaxName');

        $response = $this->actingAs($attacker)->get('/taxes/' . $foreign->id);

        $response->assertStatus(404);
        $response->assertDontSee('VictimTaxName', false);
    }

    public function test_the_tax_owner_can_still_open_their_own_show_screen(): void
    {
        // The positive control for the show() guard specifically: a guard that refused everyone
        // would satisfy both assertions above.
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Show Tax');

        $own = $this->makeTax($store->id, 'OwnTaxName');

        $this->actingAs($owner)->get('/taxes/' . $own->id)
            ->assertStatus(200)
            ->assertSee('OwnTaxName', false);
    }

    public function test_the_page_owner_can_still_do_all_of_it(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Edit Menu');
        $this->grantPermission($owner, 'Delete Page');

        $own = $this->makePage($store->id, 'OwnPage');

        $this->actingAs($owner)->get('/pages/' . $own->id . '/edit')->assertStatus(200);

        $this->actingAs($owner)->put('/pages/' . $own->id, [
            'page_name' => 'Renamed',
            'page_slug' => 'renamed-own-page',
        ]);
        $this->assertSame('Renamed', $this->raw('pages', $own->id)->page_name,
            'The owner can no longer rename their own page.');
        $this->assertSame((int) $store->id, (int) $this->raw('pages', $own->id)->store_id);

        $this->actingAs($owner)->delete('/pages/' . $own->id);
        $this->assertNull($this->raw('pages', $own->id),
            'The owner can no longer delete their own page.');
    }
}
