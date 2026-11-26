<?php

namespace Tests\Feature\Security;

use App\Models\Order;
use App\Models\OrderNote;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * GAP-142 / P4-F110 — THE TWO ORDER-NOTE ROUTES IN THE ADMIN API HAD NO PERMISSION CHECK.
 * =================================================================================================
 *
 * `AddOrderNote` (`POST api/admin/orders/notes/create`) and `DeleteOrderNote`
 * (`DELETE api/admin/orders/notes/delete`). Both were repaired once already — for
 * NEW-order-note-cross-tenant-write and its delete sibling — and that repair was about TENANCY: the
 * store had come from a request field, so the three-way note/order/store filter only ever
 * re-asserted the attacker's own parameter. `resolveOwnedStore()` closed it.
 *
 * What neither repair added is the other axis. Any authenticated member of the tenant, holding no
 * order-note permission of any kind, could write a note onto any of that tenant's orders or delete
 * any of its notes.
 *
 * **AND A NOTE IS NOT ALWAYS INTERNAL.** `note_type` is validated `in:private_note,to_customer`, so
 * this route writes MERCHANT-TO-SHOPPER text: an unpermitted staff member could put words in the
 * merchant's mouth to a named customer, and delete the record of having done it. That is why the
 * refusal cases below read `order_notes` back rather than only the status.
 *
 * -------------------------------------------------------------------------------------------------
 * THE NAMES ARE EXACTLY THE WEB TWIN'S, AND THIS ROW IS DELIBERATELY NARROWER THAN THE COUPON ROW.
 *
 * `OrderNoteController::store()` authorises through `OrderNotePolicy::create()`, which is
 * `isAbleTo('Create Order Note')` (`:65`), and `destroy()` through `OrderNotePolicy::delete()`,
 * `isAbleTo('Delete Order Note')` (`:92`). Both are seeded by `Utility.php` in the merchant block
 * and both are granted to the shared global `admin` role, so no merchant is locked out in
 * production.
 *
 * `Manage Order Note` IS SEEDED AND IS DELIBERATELY NOT ACCEPTED, which is the opposite of the
 * choice the coupon and tax rows made — so the difference needs its reason. On the web it is a READ
 * right and nothing else: `resources/views/order/view.blade.php:603` wraps the notes SECTION in
 * `@permission('Manage Order Note')` and then wraps the buttons inside it in
 * `@permission('Create Order Note')` (`:670`) and `@permission('Delete Order Note')` (`:649`). The
 * controller gate never accepts it. The coupon argument — "the screen's own name already grants
 * this read, so admitting it to the write adds nobody who cannot already see the data" — does not
 * transfer, because here the screen's name grants a read of notes and the write is a separate,
 * narrower grant the merchant ticks on purpose. `Manage Order` is not accepted either, for the same
 * reason: it is the ORDER right, and the web order screen does not let it write notes.
 *
 * -------------------------------------------------------------------------------------------------
 * THE OWNER LIMB IS NOT OPTIONAL HERE, AND AN EXISTING GREEN TEST IS THE PROOF.
 * `OrderNoteAuthorizationTest::test_api_note_create_and_delete_still_work_in_the_callers_own_store`
 * drives both routes as a merchant built by `makeTenant()`, which grants nothing. A
 * permission-only gate turns that test red. `callerMayActInStore()` carries the limb.
 *
 * THE OPERATOR IS ADMITTED, pinned by that same suite's
 * `test_a_platform_admin_may_write_a_note_in_any_store`. `MerchantPermissions` answers `WILDCARD`
 * for them, which is necessary because `isAbleTo()` alone refuses the operator — and the web twin's
 * policy admits a platform admin too, though AFTER its permission check rather than before, which
 * is the ordering CLAUDE.md warns about.
 *
 * THE REFUSAL IS AN EXPLICIT 403. These two handlers refuse a foreign store with
 * `storeAccessDenied()` (a real 403) and everything else with `$this->error()` (HTTP **200** and
 * `{status: 0}`). The permission refusal uses the 403 shape, so a client can tell it from success.
 */
class AdminApiOrderNotePermissionTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** Distinctive, so an "it was not written" assertion cannot pass on a coincidence. */
    private const SHOPPER_TEXT = 'GAP142-WORDS-PUT-IN-THE-MERCHANTS-MOUTH';

    /** @return array{0:User,1:Store,2:Order,3:int} merchant, store, order, an existing note id */
    private function tenantWithANote(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $order = $this->makeOrder($store);

        $note = new OrderNote();
        $note->forceFill([
            'order_id' => $order->id,
            'store_id' => $store->id,
            'notes' => 'GAP142-EXISTING-NOTE',
            'note_type' => 'private_note',
            'status' => null,
        ])->save();

        return [User::find($merchant->id), $store, $order, (int) $note->id];
    }

    private function staffIn(User $owner, Store $store, ?string $permission = null): User
    {
        $staff = $this->makeStaff($owner);
        $staff->current_store = $store->id;
        $staff->save();

        if ($permission !== null) {
            $this->grantPermission(User::find($staff->id), $permission);
        }

        return User::find($staff->id);
    }

    private function as(User $user)
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $user->createToken('auth_token')->plainTextToken
        );
    }

    private function addNote(User $u, Store $s, Order $o, string $text = self::SHOPPER_TEXT)
    {
        return $this->as($u)->postJson('/api/admin/orders/notes/create', [
            'store_id' => $s->id,
            'order_id' => $o->id,
            'note' => $text,
            // `to_customer`, deliberately: this is the merchant-to-shopper branch, which is what
            // makes an unpermitted write more than an internal annotation.
            'note_type' => 'to_customer',
        ]);
    }

    private function deleteNote(User $u, Store $s, Order $o, int $noteId)
    {
        return $this->as($u)->deleteJson('/api/admin/orders/notes/delete', [
            'store_id' => $s->id,
            'order_id' => $o->id,
            'note_id' => $noteId,
        ]);
    }

    /**
     * Notes read through `withoutGlobalScopes()`.
     *
     * `OrderNote` is one of the ELEVEN models that genuinely filter — it IS in
     * `config/tenancy.php`'s `scoped_models` — so a plain `OrderNote::find()` here answers against
     * whichever tenant happens to be resolved rather than against the table, and an "it is gone" or
     * "it was never written" assertion would pass whether or not the fix works. CLAUDE.md records
     * that costing a real false reading on a sibling table.
     */
    private function noteExists(int $noteId): bool
    {
        return OrderNote::withoutGlobalScopes()->where('id', $noteId)->exists();
    }

    private function notesSaying(string $text): int
    {
        return OrderNote::withoutGlobalScopes()->where('notes', $text)->count();
    }

    // ════════════════════════════════════════════════════════════════════════════════ THE DEFECT

    public function test_a_staff_member_without_create_order_note_cannot_write_a_note(): void
    {
        [$merchant, $store, $order] = $this->tenantWithANote();
        $staff = $this->staffIn($merchant, $store);

        $response = $this->addNote($staff, $store, $order);

        $this->assertSame(403, $response->status(),
            'a staff member holding no order-note permission wrote a `to_customer` note -- '
            . 'merchant-to-shopper text -- through the admin API. Body: '
            . substr((string) $response->getContent(), 0, 300));

        $this->assertSame(0, $this->notesSaying(self::SHOPPER_TEXT),
            'the refused call still inserted the note, so text the caller was not permitted to '
            . 'write is on the order and addressed to the customer');
    }

    public function test_a_staff_member_without_delete_order_note_cannot_delete_a_note(): void
    {
        [$merchant, $store, $order, $noteId] = $this->tenantWithANote();
        $staff = $this->staffIn($merchant, $store);

        $response = $this->deleteNote($staff, $store, $order, $noteId);

        $this->assertSame(403, $response->status(),
            'a staff member holding no order-note permission deleted an order note. Body: '
            . substr((string) $response->getContent(), 0, 300));

        $this->assertTrue($this->noteExists($noteId),
            'the refused call still deleted the note. order_notes has no soft delete, so the '
            . 'record of what the merchant told this customer is simply gone.');
    }

    /** 403, NEVER 401, on both. */
    public function test_neither_refusal_is_a_401(): void
    {
        [$merchant, $store, $order, $noteId] = $this->tenantWithANote();
        $staff = $this->staffIn($merchant, $store);

        foreach ([
            'notes/create' => $this->addNote($staff, $store, $order, 'NOT-A-401-NOTE'),
            'notes/delete' => $this->deleteNote($staff, $store, $order, $noteId),
        ] as $route => $response) {
            $this->assertNotSame(401, $response->status(),
                $route . ': a 401 logs the merchant out of the whole app');
            $this->assertSame(403, $response->status(),
                $route . ': the refusal is not an explicit 403. These handlers refuse everything '
                . 'else with ApiResponser::error(), which is HTTP 200 with {status: 0}');
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════════ THE CONTROLS

    /**
     * The store's own owner still writes and deletes notes, holding nothing.
     *
     * `OrderNoteAuthorizationTest::test_api_note_create_and_delete_still_work_in_the_callers_own
     * _store` drives exactly this principal and was green before this change, so a permission-only
     * gate would have turned an existing test red.
     */
    public function test_the_store_owner_still_writes_and_deletes_notes(): void
    {
        [$merchant, $store, $order, $noteId] = $this->tenantWithANote();

        $create = $this->addNote($merchant, $store, $order, 'OWNER-WROTE-THIS');
        $this->assertSame(200, $create->status(),
            'the gate refused the store\'s OWN owner on notes/create: '
            . substr((string) $create->getContent(), 0, 300));
        $this->assertSame(1, $create->json('status'));
        $this->assertSame(1, $this->notesSaying('OWNER-WROTE-THIS'),
            'the owner got a 200 but the note was not written');

        $this->assertSame(200, $this->deleteNote($merchant, $store, $order, $noteId)->status(),
            'the gate refused the store\'s OWN owner on notes/delete');
        $this->assertFalse($this->noteExists($noteId));
    }

    /** Staff holding the web twin's own names still write and delete. */
    public function test_staff_holding_the_web_twins_names_still_write_and_delete(): void
    {
        [$merchant, $store, $order, $noteId] = $this->tenantWithANote();

        $writer = $this->staffIn($merchant, $store, 'Create Order Note');
        $this->assertSame(200, $this->addNote($writer, $store, $order, 'CREATE-NOTE-STAFF')->status(),
            '`Create Order Note` is what OrderNotePolicy::create() names, and it was refused');
        $this->assertSame(1, $this->notesSaying('CREATE-NOTE-STAFF'),
            'the permitted writer got a 200 but the note was not written, so the gate is '
            . 'short-circuiting the handler rather than admitting it');

        $deleter = $this->staffIn($merchant, $store, 'Delete Order Note');
        $this->assertSame(200, $this->deleteNote($deleter, $store, $order, $noteId)->status(),
            '`Delete Order Note` is what OrderNotePolicy::delete() names, and it was refused');
        $this->assertFalse($this->noteExists($noteId));
    }

    /**
     * THE TWO NAMES DO NOT CROSS. A writer may not delete and a deleter may not write — which is
     * what makes this a per-handler gate rather than one broad name for the pair.
     */
    public function test_the_create_and_delete_grants_are_not_interchangeable(): void
    {
        [$merchant, $store, $order, $noteId] = $this->tenantWithANote();

        $writer = $this->staffIn($merchant, $store, 'Create Order Note');
        $this->assertSame(403, $this->deleteNote($writer, $store, $order, $noteId)->status(),
            '`Create Order Note` admitted a DELETE, so the two handlers are sharing one grant');
        $this->assertTrue($this->noteExists($noteId));

        $deleter = $this->staffIn($merchant, $store, 'Delete Order Note');
        $this->assertSame(403, $this->addNote($deleter, $store, $order, 'DELETER-WROTE-THIS')->status(),
            '`Delete Order Note` admitted a WRITE');
        $this->assertSame(0, $this->notesSaying('DELETER-WROTE-THIS'));
    }

    /**
     * `Manage Order Note` alone does NOT admit either write — see the class docblock. On the web it
     * is the right to SEE the notes section, and the controller gate never accepts it. `Manage
     * Order` does not admit either.
     */
    public function test_the_read_only_and_order_wide_names_do_not_admit_a_note_write(): void
    {
        [$merchant, $store, $order, $noteId] = $this->tenantWithANote();

        foreach (['Manage Order Note', 'Manage Order'] as $name) {
            $staff = $this->staffIn($merchant, $store, $name);

            $this->assertSame(403, $this->addNote($staff, $store, $order, 'WRITTEN-WITH-' . $name)->status(),
                '`' . $name . '` admitted a note WRITE. On the web it does not: order/view.blade.php '
                . 'wraps the notes section in @permission(\'Manage Order Note\') and the buttons '
                . 'inside it in Create/Delete Order Note, and OrderNotePolicy accepts neither of '
                . 'these names.');
            $this->assertSame(0, $this->notesSaying('WRITTEN-WITH-' . $name));

            $this->assertSame(403, $this->deleteNote($staff, $store, $order, $noteId)->status(),
                '`' . $name . '` admitted a note DELETE');
            $this->assertTrue($this->noteExists($noteId));
        }
    }

    /** The platform operator, whom `isAbleTo()` alone refuses, still writes and deletes. */
    public function test_the_platform_operator_still_writes_and_deletes_notes(): void
    {
        [, $store, $order, $noteId] = $this->tenantWithANote();

        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);

        $this->assertSame(200, $this->addNote($operator, $store, $order, 'OPERATOR-WROTE-THIS')->status(),
            'the platform operator was refused on notes/create. `isAbleTo()` has no super-admin '
            . 'bypass, which is why the gate goes through MerchantPermissions. '
            . 'OrderNoteAuthorizationTest::test_a_platform_admin_may_write_a_note_in_any_store '
            . 'already pins this.');
        /* THE ROW, because a 200 here is also what `error()` answers -- the review's point. */
        $this->assertSame(1, $this->notesSaying('OPERATOR-WROTE-THIS'),
            'the operator got a 200 on notes/create and no note was written, so the gate is '
            . 'short-circuiting the handler rather than admitting it');

        $this->assertSame(200, $this->deleteNote($operator, $store, $order, $noteId)->status(),
            'the platform operator was refused on notes/delete');
        $this->assertSame(0, OrderNote::withoutGlobalScopes()->where('id', $noteId)->count(),
            'the operator got a 200 on notes/delete and the note survived');
    }

    // ══════════════════════════════════════════════════════════════════════ TENANCY IS UNCHANGED

    /**
     * A member of another tenant holding both names in their OWN store is still refused, by
     * `resolveOwnedStore()` and long before any permission question.
     *
     * These two handlers answer a foreign store with `storeAccessDenied()`, a real 403, so the
     * status is the right field here — unlike the coupon and tax routes, whose tenancy refusal is
     * an HTTP-200 `{status: 0}` envelope. The row reads are what would survive a change of shape.
     */
    public function test_another_tenants_member_is_still_refused_and_writes_nothing(): void
    {
        [, $store, $order, $noteId] = $this->tenantWithANote();

        $outsiderMerchant = $this->makeMerchant();
        $outsiderStore = $this->makeStore($outsiderMerchant);
        $outsiderMerchant->current_store = $outsiderStore->id;
        $outsiderMerchant->save();
        $outsider = $this->staffIn(User::find($outsiderMerchant->id), $outsiderStore, 'Create Order Note');
        $this->grantPermission($outsider, 'Delete Order Note');
        $outsider = User::find($outsider->id);

        $this->assertSame(403, $this->addNote($outsider, $store, $order, 'CROSS-TENANT-NOTE')->status(),
            'a member of another tenant wrote a note onto this store\'s order while holding '
            . '`Create Order Note` in their OWN store');
        $this->assertSame(0, $this->notesSaying('CROSS-TENANT-NOTE'));

        $this->assertSame(403, $this->deleteNote($outsider, $store, $order, $noteId)->status(),
            'a member of another tenant deleted this store\'s order note');
        $this->assertTrue($this->noteExists($noteId));
    }
}
