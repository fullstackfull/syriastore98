<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE OPERATOR HAS TWO WAYS INTO A MERCHANT'S TENANT AND ONLY ONE OF THEM LEFT A TRACE.
 * =================================================================================================
 *
 * `SUPPORT-004` of the recovered Master Product Plan asks for *"temporary, scoped support access with
 * merchant consent and an audit log"*. Of those four, only the audit log exists at all --
 * `App\Support\Audit\RecordImpersonation` writes `impersonation_started` / `impersonation_ended` for
 * `StoreController::LoginWithAdmin`, the "log in as this merchant" door -- and it covered one door of
 * two.
 *
 * `StoreController::changeStore()` (`GET store/{id}/change` -> `users.current_store`) is the other.
 * For a merchant it is confined: the lookup carries `where('created_by', merchantTenantRoot($user))`,
 * so they can only select a store their own tenant owns (P1-SEC-66/R4-03 -- `current_store` IS the
 * tenant discriminator and is caller-writable, so without that predicate the switch nullified every
 * `where('store_id', getCurrentStore())` in the application). For the platform operator the predicate
 * is SKIPPED by design, so one request points the discriminator at any merchant's store and the whole
 * panel then answers for that tenant.
 *
 * That is support access by any reading of the words, and it wrote no audit row. The gap is not
 * symmetrical with impersonation either: impersonation at least changes the signed-in user, so a
 * reader of `audit_logs` sees the merchant's id on the subsequent rows; a store switch leaves the
 * OPERATOR's id on them, with nothing saying whose data was open on the screen.
 *
 * WHAT THIS DOES NOT DO, and each omission is deliberate:
 *
 *   - It changes NO PERMISSION. Who may switch stores is untouched, and the operator keeps the
 *     deliberate bypass. Narrowing it is an owner decision, not an audit change.
 *   - It does NOT record a MERCHANT's or a staff member's switch. Those are confined to their own
 *     tenant by the predicate above and are ordinary product use; a trail that records them records
 *     mostly noise, and a noisy trail is not read. The rule is "the operator entered a tenant that is
 *     not theirs", which is exactly the action a tenant can neither take nor see.
 *   - It is NOT `SUPPORT-004`. The temporary, scoped and consented halves all need storage this
 *     container may not migrate and decisions only the owner can take; they stay open on `GAP-176`.
 *
 * STORE ATTRIBUTION IS `null`, as for impersonation and for the same reason: `AuditAttribution`
 * reads the SUBJECT's own `store_id`, a `Store` row has no such column, and an operator actor
 * resolves to null rather than to the fabricated store 1. WHICH store was entered is recorded
 * exactly, in `subject_type`/`subject_id`, and the previous selection in `old_values`.
 *
 * ONE MUTATION SURVIVES THIS FILE AND IT IS SAID RATHER THAN HIDDEN. Replacing the explicit `$actor`
 * argument with `null` changes nothing observable: `AuditLog::record()` then reads `auth('web')`,
 * which on this route IS the acting operator, so the row is identical. The honest reading is
 * "belt-and-braces, not covered" rather than "tested". It is kept because the handler takes its
 * DECISION from `auth()->user()` -- the DEFAULT guard, which `CustomAuth` can leave set to
 * `customers` or `deliveryboy` -- while `record()` reads `auth('web')`, so passing the same principal
 * to both is what keeps the decision and the row naming one person by construction. There is no
 * request on this route for which the two disagree, so no case here can separate them.
 */
class OperatorStoreSwitchIsAuditedTest extends SecurityTestCase
{
    use RefreshDatabase;

    public const ACTION = 'operator_store_switched';

    private User $operator;

    private Store $operatorStore;

    private User $merchant;

    private Store $merchantStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = $this->makePlatformAdmin();
        $this->operatorStore = $this->makeStore($this->operator, ['default_language' => 'en']);

        [$merchant, $merchantStore] = $this->makeTenant();
        $this->merchant = $merchant;
        $this->merchantStore = $merchantStore;

        (new User)->flushCache();
    }

    /**
     * `POST /change-store/{id}`, not GET: P2-SEC-F12 moved it because a GET that writes the tenant
     * pointer fired from a third-party `<img src>` and from link prefetching. A bookmarked GET now
     * answers 405, which `TenantSwitchTest` asserts.
     */
    private function switchTo(User $actor, Store $store)
    {
        return $this->actingAs(User::find($actor->id))->post('/change-store/' . $store->id);
    }

    /** @return list<\App\Models\AuditLog> */
    private function rows(): array
    {
        return AuditLog::where('action', self::ACTION)->orderBy('id')->get()->all();
    }

    // -------------------------------------------------------------------------------------------
    //  THE DOOR
    // -------------------------------------------------------------------------------------------

    public function test_the_operator_entering_a_merchants_tenant_is_recorded(): void
    {
        $operator = User::find($this->operator->id);
        $operator->current_store = $this->operatorStore->id;
        $operator->save();
        (new User)->flushCache();

        $this->switchTo($this->operator, $this->merchantStore);

        // PREMISE: the switch actually happened, so a missing row is a missing row and not a refusal.
        $this->assertSame((int) $this->merchantStore->id,
            (int) DB::table('users')->where('id', $this->operator->id)->value('current_store'),
            'PREMISE: the switch was refused, so this case says nothing about the audit trail.');

        $rows = $this->rows();

        $this->assertCount(1, $rows,
            'the platform operator pointed the tenant discriminator at a merchant\'s store and '
            . 'nothing recorded it');

        $row = $rows[0];

        $this->assertSame((int) $this->operator->id, (int) $row->user_id,
            'the row does not name the operator who acted');
        $this->assertSame(Store::class, $row->subject_type);
        $this->assertSame((int) $this->merchantStore->id, (int) $row->subject_id,
            'the row does not name the store that was entered');
        $this->assertSame((int) $this->operatorStore->id, (int) ($row->old_values['current_store'] ?? null),
            'the row does not record which selection was left');
        $this->assertSame((int) $this->merchantStore->id, (int) ($row->new_values['current_store'] ?? null));
    }

    /**
     * STORE ATTRIBUTION IS NULL, NOT THE FABRICATED 1 -- asserted because `AuditLog::record()` used to
     * take `store_id` from `getCurrentStore()`, which returns a hardcoded `1` for the operator, and a
     * trail that files every operator action under store 1 is worse than one with a null.
     */
    public function test_the_row_does_not_fabricate_a_store_attribution(): void
    {
        $this->switchTo($this->operator, $this->merchantStore);

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->store_id,
            'the row attributes an operator action to a tenant: AuditAttribution answers null for '
            . 'an operator with no store-bearing subject, and a Store row carries no store_id');
    }

    // -------------------------------------------------------------------------------------------
    //  WHAT IS DELIBERATELY NOT RECORDED
    // -------------------------------------------------------------------------------------------

    /**
     * A MERCHANT MOVING BETWEEN THEIR OWN STORES IS NOT SUPPORT ACCESS.
     *
     * This is the case that keeps the trail readable, and it is also the one that would catch a
     * "record every switch" implementation: the predicate in `changeStore()` already confines a
     * merchant to their own tenant, so such a row records nothing a tenant cannot see for itself.
     */
    public function test_a_merchant_switching_between_their_own_stores_is_not_recorded(): void
    {
        $second = $this->makeStore($this->merchant, ['default_language' => 'en']);
        (new User)->flushCache();

        $this->switchTo($this->merchant, $second);

        $this->assertSame((int) $second->id,
            (int) DB::table('users')->where('id', $this->merchant->id)->value('current_store'),
            'PREMISE: the merchant\'s own switch was refused, so the absence below is the refusal '
            . 'and not the rule.');
        $this->assertSame([], $this->rows(),
            'an ordinary merchant store switch was written to the support-access trail');
    }

    /**
     * NOR IS A STAFF MEMBER MOVING BETWEEN THEIR OWN TENANT'S STORES -- ADDED BECAUSE A MUTATION
     * SURVIVED.
     *
     * Dropping the `isOperator()` limb and keeping only "the store is not the actor's own" broke
     * nothing: a tenant OWNER's store carries `created_by = their own id`, so the two readings agree
     * for them, and the case above could not see the difference. A STAFF member is where they
     * disagree -- their tenant's stores carry the OWNER's `created_by`, never the staff member's --
     * so without this case the trail would fill with ordinary staff switches and the rule would have
     * stopped being about support access. CLAUDE.md trap 9: find the input under which the two
     * behaviours differ rather than weakening the mutant.
     */
    public function test_a_staff_member_switching_inside_their_own_tenant_is_not_recorded(): void
    {
        $second = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $staff = $this->makeStaff($this->merchant, ['current_store' => $this->merchantStore->id]);
        (new User)->flushCache();

        $this->assertNotSame((int) $second->created_by, (int) $staff->id,
            'PREMISE: the fixture store was created BY the staff member, so "not my own store" and '
            . '"I am the operator" agree and this case cannot separate them.');

        $this->switchTo($staff, $second);

        $this->assertSame((int) $second->id,
            (int) DB::table('users')->where('id', $staff->id)->value('current_store'),
            'PREMISE: the staff switch was refused, so the absence below is the refusal and not '
            . 'the rule.');
        $this->assertSame([], $this->rows(),
            "an ordinary staff store switch inside the staff member's own tenant was written to "
            . 'the support-access trail');
    }

    /** Nor is the operator selecting a store of their own. */
    public function test_the_operator_selecting_their_own_store_is_not_recorded(): void
    {
        $this->switchTo($this->operator, $this->operatorStore);

        $this->assertSame((int) $this->operatorStore->id,
            (int) DB::table('users')->where('id', $this->operator->id)->value('current_store'),
            'PREMISE: the operator\'s own switch was refused.');
        $this->assertSame([], $this->rows(),
            "the operator selecting a store they own was recorded as entering someone's tenant");
    }

    /**
     * A REFUSED SWITCH WRITES NOTHING -- the row follows the write, not the attempt.
     *
     * A merchant naming another tenant's store id is refused with 403 by `changeStore()`'s predicate.
     * If the recording sat before the write, the trail would say a tenant was entered when it was
     * not, which is a worse failure than silence.
     */
    public function test_a_refused_switch_writes_no_row(): void
    {
        $before = (int) DB::table('users')->where('id', $this->merchant->id)->value('current_store');

        $response = $this->switchTo($this->merchant, $this->operatorStore);

        $response->assertForbidden();
        $this->assertSame($before,
            (int) DB::table('users')->where('id', $this->merchant->id)->value('current_store'),
            'PREMISE: the refusal did not hold, which is a tenancy defect and not an audit one.');
        $this->assertSame([], $this->rows(), 'a refused switch was recorded as a tenant entry');
    }
}
