<?php

namespace Tests\Feature\Security;

use App\Models\SavedView;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P2-04-9 / WS E3 — SAVED VIEWS: isolation and permissions, written BEFORE the interface.
 * ============================================================================================
 *
 * Owner instruction, 2026-10-28: *"عزل البيانات لكل موظف ومتجر … صلاحيات الإنشاء والقراءة والتعديل
 * والحذف … اختبارات العزل والصلاحيات **قبل بناء الواجهة**."* This file is that, and no Blade was
 * written until it passed.
 *
 * A SAVED VIEW IS NOT A COMMERCIAL RECORD — it is one employee's arrangement of one screen — and
 * that shapes two decisions this file pins:
 *
 *  1. OWNERSHIP IS `(store_id, created_by)` TOGETHER. `created_by` is the EMPLOYEE's own id, never
 *     `creatorId()`: that helper returns the OWNER's id for staff, so using it would let every
 *     employee of a merchant read every colleague's views. That is precisely the collapse that
 *     ruled the `settings` table out as a home for this data, and it would be easy to reintroduce.
 *  2. **THE PLATFORM OPERATOR IS NOT EXEMPT.** Unlike `OrderNotePolicy`, where a platform
 *     administrator legitimately writes in any store, nobody has business reading a merchant
 *     employee's private filters. "The operator can see everything" is how a personal preference
 *     becomes a disclosure.
 *
 * AND THERE IS NO SHARING MECHANISM TO AUTHORISE. The owner's condition — *"sharing a filter
 * combination stays a safe LINK and must not become unrestricted sharing of tenant data"* — is met
 * by P2-04-8's existing URL, which carries filter VALUES only. `test_a_shared_link_grants_nothing`
 * drives that directly: the recipient gets THEIR OWN store's rows narrowed the same way.
 */
class SavedViewIsolationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $colleague;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = $this->makeMerchant();
        $this->store = $this->makeStore($this->owner, ['default_language' => 'en']);
        $this->owner->current_store = $this->store->id;
        $this->owner->save();
        $this->grantPermission(User::find($this->owner->id), 'Manage Order');
        $this->owner = User::find($this->owner->id);

        $this->colleague = $this->makeStaff($this->owner, ['name' => 'Colleague']);
        $this->colleague->current_store = $this->store->id;
        $this->colleague->save();
        $this->grantPermission(User::find($this->colleague->id), 'Manage Order');
        $this->colleague = User::find($this->colleague->id);
    }

    private function saveView(User $as, array $overrides = [])
    {
        return $this->actingAs($as)->post('saved-views', array_merge([
            'screen' => 'order',
            'name' => 'Unpaid this week',
            'filters' => ['ss_payment' => 'Unpaid', 'ss_status' => '0'],
        ], $overrides));
    }

    private function viewsOf(User $as, string $screen = 'order')
    {
        return SavedView::withoutGlobalScopes()
            ->where('created_by', $as->id)
            ->where('screen', $screen)
            ->get();
    }

    // =======================================================================================
    //  CREATE — ownership comes from the principal, never from input
    // =======================================================================================

    public function test_a_merchant_can_save_a_named_view(): void
    {
        $response = $this->saveView($this->owner);

        $response->assertRedirect();

        $views = $this->viewsOf($this->owner);

        $this->assertCount(1, $views, 'premise: the view was stored');
        $this->assertSame('Unpaid this week', $views->first()->name);
        $this->assertSame((int) $this->store->id, (int) $views->first()->store_id);
        $this->assertSame(
            ['ss_status' => '0', 'ss_payment' => 'Unpaid'],
            $views->first()->appliedFilters(),
            'the filters are stored in the vocabulary\'s own order, values intact'
        );
    }

    /**
     * A posted `store_id` or `created_by` must be ignored by the HANDLER.
     *
     * READ THIS TEST FOR WHAT IT PINS. A bite test established that it passes even with both
     * columns in `$fillable`, because the controller never mass-assigns — it sets them as
     * properties from the principal. So this pins the CONTROLLER, and
     * `test_ownership_cannot_be_mass_assigned` below is what pins `$fillable`. Two mechanisms,
     * two tests; before this they were one test believed to cover both.
     */
    public function test_a_posted_store_id_and_author_are_ignored(): void
    {
        $otherOwner = $this->makeMerchant();
        $otherStore = $this->makeStore($otherOwner);

        $this->saveView($this->owner, [
            'store_id' => $otherStore->id,
            'created_by' => $otherOwner->id,
        ]);

        $view = $this->viewsOf($this->owner)->first();

        $this->assertNotNull($view, 'premise: a view was created');
        $this->assertSame((int) $this->store->id, (int) $view->store_id,
            'THE POSTED STORE WAS HONOURED. Ownership must come from `getCurrentStore()`, not '
            . 'from request input — the defect class CLAUDE.md calls the most productive here.');
        $this->assertSame((int) $this->owner->id, (int) $view->created_by);
    }

    /**
     * AND OWNERSHIP CANNOT BE MASS-ASSIGNED EITHER, which is a different mechanism from the test
     * above and was not covered by it. `store_id` and `created_by` are absent from `$fillable`,
     * so a future `SavedView::create($request->all())` — the shape that would make this a real
     * hole — cannot place a row in another store or under another employee.
     */
    public function test_ownership_cannot_be_mass_assigned(): void
    {
        $otherOwner = $this->makeMerchant();
        $otherStore = $this->makeStore($otherOwner);

        $before = DB::table('saved_views')->count();

        /*
         * THE OUTCOME IS BETTER THAN "THE KEYS ARE IGNORED", and measuring it is how that was
         * found. Laravel drops both unfillable keys, so the INSERT never names `store_id` or
         * `created_by` -- and because both columns are NOT NULL, the database then REFUSES the
         * row outright. A mass-assigned saved view cannot be created at all, mis-owned or
         * otherwise. The first version of this test asserted the row was created with NULL
         * ownership, which is not what happens.
         */
        try {
            SavedView::create([
                'screen' => 'order',
                'name' => 'Mass assigned',
                'filters' => ['ss_payment' => 'Unpaid'],
                'store_id' => $otherStore->id,
                'created_by' => $otherOwner->id,
            ]);

            $this->fail('mass assignment created a saved view; ownership that can be '
                . 'mass-assigned is not ownership');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringNotContainsString('store_id', $e->getSql(),
                'the INSERT must not name `store_id`: it is unfillable, so Laravel drops it');
            $this->assertStringNotContainsString('created_by', $e->getSql());
        }

        $this->assertSame($before, DB::table('saved_views')->count(),
            'and no row landed');
    }

    /**
     * THE TWO SCREEN LISTS MUST AGREE, and this is the test the "unknown screen" case above
     * turned out NOT to be. A bite test showed that removing the controller's allowlist changes
     * nothing today, because `SavedViewPolicy::permissionFor()` already returns null for an
     * unknown screen and the refusal happens there. The controller's check is real defence in
     * depth, but it is not what that test measures.
     *
     * What IS worth pinning is that the two lists cannot drift apart: a screen with a permission
     * but no filter vocabulary would store views whose filters are always empty, and a screen
     * with a vocabulary but no permission would be silently unreachable.
     */
    public function test_every_screen_with_a_vocabulary_has_a_permission_and_the_reverse(): void
    {
        $withVocabulary = \App\Services\TableFilterMemory::screens();

        $this->assertNotEmpty($withVocabulary, 'premise: the vocabulary is not empty');

        foreach ($withVocabulary as $screen) {
            $this->assertNotNull(\App\Policies\SavedViewPolicy::permissionFor($screen),
                "screen `{$screen}` has filters but no permission, so its views are unreachable");
        }

        foreach (['order', 'product'] as $screen) {
            $this->assertNotSame([], \App\Services\TableFilterMemory::vocabularyFor($screen),
                "screen `{$screen}` has a permission but no filter vocabulary, so every view "
                . 'saved for it would store an empty filter set');
        }
    }

    public function test_a_filter_outside_the_screens_vocabulary_is_dropped(): void
    {
        $this->saveView($this->owner, [
            'filters' => [
                'ss_payment' => 'Unpaid',
                'redirect' => 'https://evil.test',
                'page' => '5',
                'ss_stock' => 'out',           // a PRODUCT filter, not an order one
            ],
        ]);

        $filters = $this->viewsOf($this->owner)->first()->appliedFilters();

        $this->assertSame(['ss_payment' => 'Unpaid'], $filters,
            'only the screen\'s own vocabulary survives. A saved view that could store an '
            . 'arbitrary key would be a way to smuggle a parameter past the allowlist and have a '
            . 'later restore replay it into the URL.');
    }

    public function test_an_unknown_screen_is_refused(): void
    {
        $this->saveView($this->owner, ['screen' => 'invoices']);
        $this->saveView($this->owner, ['screen' => '../order']);

        $this->assertCount(0, SavedView::withoutGlobalScopes()->get(),
            'a screen outside the vocabulary has no views at all');
    }

    public function test_saving_without_the_screen_permission_is_refused(): void
    {
        $outsider = $this->makeStaff($this->owner, ['name' => 'No Orders']);
        $outsider->current_store = $this->store->id;
        $outsider->save();
        $outsider = User::find($outsider->id);

        $this->saveView($outsider);

        $this->assertCount(0, $this->viewsOf($outsider),
            'a principal who may not use the order screen may not save views of it');
    }

    /** F149's rule, applied here: no tenant means no ownership, so nothing may be created. */
    public function test_a_principal_with_no_store_cannot_create_a_view(): void
    {
        $staff = $this->makeStaff($this->owner, ['name' => 'No Store']);
        $staff->current_store = null;
        $staff->save();
        $staff = User::find($staff->id);
        $this->grantPermission($staff, 'Manage Order');
        $staff = User::find($staff->id);

        $this->assertNull(Store::where('created_by', $staff->id)->value('id'),
            'premise: they resolve no store at all');

        $this->saveView($staff);

        $this->assertCount(0, $this->viewsOf($staff));
    }

    // =======================================================================================
    //  ISOLATION
    // =======================================================================================

    public function test_two_employees_of_one_merchant_do_not_share_views(): void
    {
        $this->saveView($this->owner, ['name' => 'Mine']);
        $this->saveView($this->colleague, ['name' => 'Theirs']);

        $mine = SavedView::forCurrentPrincipal('order');

        $this->actingAs($this->owner);

        $names = SavedView::forCurrentPrincipal('order')->pluck('name')->all();

        $this->assertSame(['Mine'], $names,
            'EACH EMPLOYEE SEES ONLY THEIR OWN. `created_by` is the employee, not `creatorId()` '
            . '— using the latter would collapse a tenant\'s staff onto the owner, which is the '
            . 'exact defect that ruled the settings table out as a home for this.');

        $this->actingAs($this->colleague);

        $this->assertSame(['Theirs'], SavedView::forCurrentPrincipal('order')->pluck('name')->all());
    }

    public function test_views_do_not_follow_a_merchant_across_stores(): void
    {
        $this->saveView($this->owner, ['name' => 'Store A view']);

        $second = $this->makeStore($this->owner, ['name' => 'Second']);
        $this->owner->current_store = $second->id;
        $this->owner->save();
        $this->owner = User::find($this->owner->id);

        $this->actingAs($this->owner);

        $this->assertSame([], SavedView::forCurrentPrincipal('order')->pluck('name')->all(),
            'a view belongs to one store as well as one person');
    }

    public function test_another_tenants_view_cannot_be_applied_or_deleted(): void
    {
        $this->saveView($this->owner, ['name' => 'Private']);
        $foreign = $this->viewsOf($this->owner)->first();

        $intruder = $this->makeMerchant();
        $intruderStore = $this->makeStore($intruder);
        $intruder->current_store = $intruderStore->id;
        $intruder->save();
        $this->grantPermission(User::find($intruder->id), 'Manage Order');
        $intruder = User::find($intruder->id);

        $this->actingAs($intruder)->post('saved-views/' . $foreign->id . '/apply')->assertRedirect();
        $this->actingAs($intruder)->delete('saved-views/' . $foreign->id)->assertRedirect();

        $this->assertNotNull(SavedView::withoutGlobalScopes()->find($foreign->id),
            'ANOTHER TENANT DELETED IT. Asserted through `withoutGlobalScopes()` because a '
            . 'scoped read answers against whichever tenant is resolved, not against the table.');
    }

    public function test_a_colleague_cannot_delete_your_view(): void
    {
        $this->saveView($this->owner, ['name' => 'Mine alone']);
        $mine = $this->viewsOf($this->owner)->first();

        $this->actingAs($this->colleague)->delete('saved-views/' . $mine->id);

        $this->assertNotNull(SavedView::withoutGlobalScopes()->find($mine->id),
            'same store, same merchant, different person — and still not theirs');
    }

    /**
     * THE OPERATOR IS NOT EXEMPT, and this is the deliberate difference from `OrderNotePolicy`.
     * An order note is a record about a merchant's business; a saved view is a person's own screen
     * arrangement, and the operator reading one would serve no purpose at all.
     */
    public function test_the_platform_operator_cannot_read_a_merchants_private_view(): void
    {
        $this->saveView($this->owner, ['name' => 'Operator must not see this']);
        $view = $this->viewsOf($this->owner)->first();

        $operator = $this->makePlatformAdmin();
        $operator->current_store = $this->store->id;
        $operator->save();
        $operator = User::find($operator->id);

        $this->assertTrue((new \App\Policies\SavedViewPolicy())->useScreen($operator, 'order'),
            'premise: the operator CAN use the order screen — so the refusal below is about '
            . 'ownership and not about permission, which is the distinction under test');

        $this->assertFalse($operator->can('view', $view));
        $this->assertFalse($operator->can('delete', $view));

        $this->actingAs($operator)->delete('saved-views/' . $view->id);

        $this->assertNotNull(SavedView::withoutGlobalScopes()->find($view->id));
    }

    /** A probe for someone else's id must be indistinguishable from a probe for a missing one. */
    public function test_a_foreign_id_and_a_missing_id_answer_the_same(): void
    {
        $this->saveView($this->owner, ['name' => 'Private']);
        $foreign = $this->viewsOf($this->owner)->first();

        $intruder = $this->makeMerchant();
        $intruderStore = $this->makeStore($intruder);
        $intruder->current_store = $intruderStore->id;
        $intruder->save();
        $this->grantPermission(User::find($intruder->id), 'Manage Order');
        $intruder = User::find($intruder->id);

        $onForeign = $this->actingAs($intruder)->post('saved-views/' . $foreign->id . '/apply');
        $onMissing = $this->actingAs($intruder)->post('saved-views/999999/apply');

        $this->assertSame($onMissing->status(), $onForeign->status(),
            'a different status for "not yours" than for "does not exist" turns the endpoint '
            . 'into an id oracle');
    }

    // =======================================================================================
    //  APPLY — and why a shared link is safe
    // =======================================================================================

    public function test_applying_a_view_carries_its_filters_to_the_list(): void
    {
        $this->saveView($this->owner, ['name' => 'Unpaid', 'filters' => ['ss_payment' => 'Unpaid']]);
        $view = $this->viewsOf($this->owner)->first();

        $response = $this->actingAs($this->owner)->post('saved-views/' . $view->id . '/apply');

        $response->assertRedirect();

        $target = $response->headers->get('Location');

        $this->assertStringContainsString('ss_payment=Unpaid', $target);
        $this->assertStringNotContainsString('store_id', $target,
            'THE LINK CARRIES VALUES, NOT TENANCY. A url that named a store would be the '
            . '"unrestricted sharing of tenant data" the approval forbids.');
        $this->assertStringNotContainsString('saved_view', $target);
    }

    /**
     * THE SHARE CLAUSE, DRIVEN RATHER THAN ARGUED. The recipient of a shared link sees THEIR OWN
     * store's orders narrowed the same way — never the sender's.
     */
    public function test_a_shared_link_grants_nothing(): void
    {
        $mine = $this->makeOrder($this->store, [
            'product_order_id' => 'MINE-1', 'payment_status' => 'Unpaid',
        ]);

        $other = $this->makeMerchant();
        $otherStore = $this->makeStore($other);
        $other->current_store = $otherStore->id;
        $other->save();
        $this->grantPermission(User::find($other->id), 'Manage Order');
        $other = User::find($other->id);

        $theirs = $this->makeOrder($otherStore, [
            'product_order_id' => 'THEIRS-1', 'payment_status' => 'Unpaid',
        ]);

        // The sender's link, pasted by someone in another tenant.
        $link = route('order.index', ['ss_payment' => 'Unpaid', 'ss_apply' => 1]);

        $response = $this->actingAs($other)->get($link . '&draw=1&start=0&length=100',
            ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']);

        $this->assertSame(200, $response->status(), 'premise: the recipient reached the list');

        $payload = json_decode((string) $response->getContent(), true);
        $ids = array_map(static fn ($r) => (int) ($r['id'] ?? 0), $payload['data'] ?? []);

        $this->assertContains($theirs->id, $ids, 'they see their own matching order');
        $this->assertNotContains($mine->id, $ids,
            'and NOT the sender\'s. The store predicate comes from the authenticated principal; '
            . 'the link only ever supplied filter values.');
    }

    // =======================================================================================
    //  UPDATE / DELETE
    // =======================================================================================

    public function test_resaving_a_name_updates_the_owners_own_view_only(): void
    {
        $this->saveView($this->owner, ['name' => 'Queue', 'filters' => ['ss_status' => '0']]);
        $this->saveView($this->colleague, ['name' => 'Queue', 'filters' => ['ss_status' => '2']]);

        $this->saveView($this->owner, ['name' => 'Queue', 'filters' => ['ss_payment' => 'Paid']]);

        $this->assertCount(1, $this->viewsOf($this->owner), 'the owner still has one "Queue"');
        $this->assertSame(['ss_payment' => 'Paid'],
            $this->viewsOf($this->owner)->first()->appliedFilters());

        $this->assertSame(['ss_status' => '2'],
            $this->viewsOf($this->colleague)->first()->appliedFilters(),
            'THE COLLEAGUE\'S IDENTICALLY NAMED VIEW IS UNTOUCHED. The uniqueness is per '
            . '(store, employee, screen, name), so a shared name is not a shared row.');
    }

    public function test_an_owner_can_delete_their_own_view(): void
    {
        $this->saveView($this->owner, ['name' => 'Temporary']);
        $view = $this->viewsOf($this->owner)->first();

        $this->actingAs($this->owner)->delete('saved-views/' . $view->id);

        $this->assertNull(SavedView::withoutGlobalScopes()->find($view->id),
            'asserted through withoutGlobalScopes(), or "it is gone" passes whether or not it is');
    }

    public function test_the_unique_index_really_exists(): void
    {
        $this->saveView($this->owner, ['name' => 'Dup']);
        $row = $this->viewsOf($this->owner)->first();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('saved_views')->insert([
            'store_id' => $row->store_id,
            'created_by' => $row->created_by,
            'screen' => 'order',
            'name' => 'Dup',
            'filters' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
