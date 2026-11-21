<?php

namespace Tests\Feature\Marketing;

use App\Models\Customer;
use App\Models\CustomerSegment;
use App\Models\CustomerSegmentMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * MKT-004 — THE SEGMENT SCREEN: who may open it, whose customers it shows, and what it writes.
 *
 * THE DOOR IS DRIVEN BEFORE THE FORM. A segment lists real people with real e-mail addresses, so it
 * must not be a weaker door than `/customer` — `Manage Customer` is what that screen requires and
 * what this one requires. The ordering is the one this codebase gets wrong by default and all four
 * principals are driven rather than one: `permissions_as_gates => false`, so `@can()` and
 * `$user->can()` are ALWAYS false here (measured), Laratrust has no super-admin bypass so
 * `isAbleTo()` alone refuses the platform operator, and a merchant created before a permission name
 * existed would be locked out of their own customers without the owner limb.
 *
 * NO `store_id` IS A PARAMETER, and one is posted anyway to prove it is ignored. CLAUDE.md names a
 * tenant predicate built from caller-supplied input as this repository's most productive defect
 * class — four separate defects — so the tests send the other tenant's ids in the body and the URL.
 */
final class CustomerSegmentScreenTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private const URI = '/customer-segments';

    private const PERMISSION = 'Manage Customer';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    private function refused($response): bool
    {
        return $response->status() === 403 || ($response->isRedirect() && session()->has('error'));
    }

    private function merchantOn($store, $merchant)
    {
        $merchant->current_store = $store->id;
        $merchant->save();

        return $merchant->fresh();
    }

    private function customer($store, string $email): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Seg',
            'last_name' => 'Fixture',
            'email' => $email,
            'store_id' => $store->id,
            'status' => 1,
        ]);
    }

    private function segment($store, string $name, string $kind = CustomerSegment::KIND_STATIC): CustomerSegment
    {
        $segment = new CustomerSegment();
        $segment->name = $name;
        $segment->kind = $kind;
        $segment->definition = $kind === CustomerSegment::KIND_DYNAMIC ? '{"min_orders":1}' : null;
        $segment->store_id = $store->id;
        $segment->created_by = 1;
        $segment->save();

        return $segment;
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The door
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_signed_out_visitor_cannot_open_it(): void
    {
        $response = $this->get(self::URI);

        $this->assertTrue($response->isRedirect(), 'the segment screen was served to a guest');
        $this->assertNotSame(401, $response->status(),
            'a 401 forces a logout in the shipped Flutter client; this codebase redirects or answers 403');
    }

    public function test_a_staff_member_without_the_permission_is_refused(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);
        $staff->current_store = $store->id;
        $staff->save();

        $this->assertFalse($staff->fresh()->isAbleTo(self::PERMISSION),
            'Premise: the staff member holds no customer permission.');

        $response = $this->actingAs($staff->fresh())->get(self::URI);

        $this->assertTrue($this->refused($response),
            'a staff member who may not read customers read a list of them by segment');
    }

    public function test_the_tenant_owner_is_admitted_without_a_role(): void
    {
        [$merchant, $store] = $this->makeTenant();

        /*
         * THE OWNER LIMB, and it is not decoration. A merchant created before a permission name
         * existed holds no Laratrust role at all, so a permission-only check locks them out of
         * their own customers -- the exact lockout that happened here when six finance names were
         * gated on while none was seeded.
         */
        $this->assertFalse($merchant->fresh()->isAbleTo(self::PERMISSION),
            'Premise: the owner holds no customer role, so this case tests the owner limb.');

        $response = $this->actingAs($this->merchantOn($store, $merchant))->get(self::URI);

        $response->assertOk();
    }

    /**
     * THE WORD "SEEDED" IS NOW TESTED, not just asserted in a docblock.
     *
     * FOUND BY REVIEW: `SecurityTestCase::grantPermission()` does
     * `Permission::firstOrCreate(['name' => $permission])`, so it CREATES the row on demand -- and
     * this case would therefore have passed for a permission name that exists nowhere in
     * `Utility::addNewData()`. That is the exact lockout both controllers' docblocks rest on not
     * happening: a name absent from the seed cannot exist as a row in production and evaluates
     * FALSE for everybody, the tenant owner included. The claim happens to be true, which is why
     * this was a missing premise rather than a defect.
     */
    public function test_the_seeded_permission_admits_a_staff_member(): void
    {
        /*
         * READ FROM `Utility::addNewData()`'s OWN SOURCE, because the list is a literal array inside
         * that method and there is no static to query -- checked, not assumed. Comments stripped,
         * since that file discusses permission names at length.
         */
        $this->assertStringContainsString(
            "'".self::PERMISSION."',",
            $this->sourceWithoutComments(app_path('Models/Utility.php')),
            'premise: `'.self::PERMISSION.'` must be in the application\'s seeded permission list. '
            .'If it is not, this test still passes (grantPermission creates the row on demand) while '
            .'production locks everybody out of the screen -- including the tenant owner.'
        );

        [$merchant, $store] = $this->makeTenant();
        $holder = $this->makeStaff($merchant);
        $this->grantPermission($holder, self::PERMISSION);
        $holder->current_store = $store->id;
        $holder->save();

        $this->actingAs($holder->fresh())->get(self::URI)->assertOk();
    }

    /** GAP-223(b). An operator with nothing selected must not be shown store 1's segments. */
    public function test_an_operator_who_has_selected_no_store_is_refused(): void
    {
        $operator = $this->makePlatformAdmin(['current_store' => null]);

        $response = $this->actingAs($operator)->get(self::URI);

        $this->assertTrue($this->refused($response),
            'the platform operator was shown a store\'s segments with no store selected; '
            .'`getCurrentStore()` answers the hardcoded literal 1 for a super admin');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Whose segments
     * ---------------------------------------------------------------------------------------------
     */

    public function test_another_stores_segments_are_not_listed(): void
    {
        [$merchant, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();

        $this->segment($theirs, 'Their VIPs');
        $this->segment($mine, 'My VIPs');

        $response = $this->actingAs($this->merchantOn($mine, $merchant))->get(self::URI);

        $response->assertOk();
        $response->assertSee('My VIPs', false);
        $response->assertDontSee('Their VIPs', false);
    }

    public function test_another_stores_segment_cannot_be_opened_by_id(): void
    {
        [$merchant, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();

        $foreign = $this->segment($theirs, 'Their VIPs');

        $response = $this->actingAs($this->merchantOn($mine, $merchant))
            ->get(self::URI.'/'.$foreign->id);

        $this->assertTrue($this->refused($response),
            'a merchant opened another tenant\'s segment by guessing its id');
    }

    public function test_another_stores_segment_cannot_be_deleted_by_id(): void
    {
        [$merchant, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();

        $foreign = $this->segment($theirs, 'Their VIPs');

        $this->actingAs($this->merchantOn($mine, $merchant))
            ->delete(self::URI.'/'.$foreign->id);

        $this->assertNotNull(
            CustomerSegment::query()->where('id', $foreign->id)->first(),
            'a merchant deleted another tenant\'s segment'
        );
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Creating
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_merchant_creates_a_static_segment_in_their_own_store(): void
    {
        [$merchant, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();

        $response = $this->actingAs($this->merchantOn($mine, $merchant))->post(self::URI, [
            // Posted and ignored: the store comes from the session, never the body.
            'store_id' => $theirs->id,
            'name' => 'Regulars',
            'kind' => CustomerSegment::KIND_STATIC,
        ]);

        $this->assertFalse($this->refused($response));

        $segment = CustomerSegment::query()->where('name', 'Regulars')->first();

        $this->assertNotNull($segment);
        $this->assertSame((int) $mine->id, (int) $segment->store_id,
            'a `store_id` in the request body placed the segment in another tenant');
        $this->assertSame((int) $merchant->id, (int) $segment->created_by);
        $this->assertNull($segment->definition, 'a static segment was given a rule');
    }

    public function test_a_dynamic_segment_needs_at_least_one_rule(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $response = $this->actingAs($this->merchantOn($store, $merchant))->post(self::URI, [
            'name' => 'Nobody',
            'kind' => CustomerSegment::KIND_DYNAMIC,
            'rules' => [],
        ]);

        /*
         * AN EMPTY RULE MATCHES NOTHING (never everything), so saving it would give the merchant a
         * permanently empty segment and no explanation. Refusing says why.
         */
        $this->assertTrue($this->refused($response));
        $this->assertNull(CustomerSegment::query()->where('name', 'Nobody')->first());
    }

    public function test_an_unknown_rule_alone_is_not_a_rule(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $response = $this->actingAs($this->merchantOn($store, $merchant))->post(self::URI, [
            'name' => 'Spenders',
            'kind' => CustomerSegment::KIND_DYNAMIC,
            // A rule the vocabulary does not have -- the spend rule this slice deliberately omits.
            'rules' => ['spent_over' => 500],
        ]);

        $this->assertTrue($this->refused($response),
            'a segment was created from a rule the vocabulary does not know, so it would match nobody');
    }

    public function test_a_dynamic_segment_stores_only_sanitised_rules(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->actingAs($this->merchantOn($store, $merchant))->post(self::URI, [
            'name' => 'Loyal',
            'kind' => CustomerSegment::KIND_DYNAMIC,
            'rules' => ['min_orders' => '2', 'spent_over' => 500, 'ordered_since' => 'not-a-date'],
        ]);

        $segment = CustomerSegment::query()->where('name', 'Loyal')->first();

        $this->assertNotNull($segment);
        $this->assertSame(['min_orders' => 2], $segment->rule()->rules(),
            'an unknown key or an unparseable value reached the stored definition');
    }

    public function test_re_saving_a_name_updates_rather_than_duplicating(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $signedIn = $this->merchantOn($store, $merchant);

        $this->actingAs($signedIn)->post(self::URI, ['name' => 'Regulars', 'kind' => CustomerSegment::KIND_STATIC]);
        $this->actingAs($signedIn)->post(self::URI, [
            'name' => 'Regulars',
            'kind' => CustomerSegment::KIND_DYNAMIC,
            'rules' => ['min_orders' => 3],
        ]);

        $this->assertSame(1, CustomerSegment::query()->where('name', 'Regulars')->count(),
            're-saving a name created a second segment; the unique index says it cannot');

        $this->assertSame(
            CustomerSegment::KIND_DYNAMIC,
            CustomerSegment::query()->where('name', 'Regulars')->first()->kind
        );
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Membership
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_merchant_adds_their_own_customer_to_a_static_segment(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $segment = $this->segment($store, 'Regulars');
        $customer = $this->customer($store, 'mine'.$this->uniqueFixtureRef().'@example.test');

        $response = $this->actingAs($this->merchantOn($store, $merchant))
            ->post(self::URI.'/'.$segment->id.'/members', ['customer_id' => $customer->id]);

        $this->assertFalse($this->refused($response));

        $this->assertSame(1, CustomerSegmentMember::query()
            ->where('segment_id', $segment->id)->where('customer_id', $customer->id)->count());
    }

    public function test_another_stores_customer_cannot_be_added(): void
    {
        [$merchant, $mine] = $this->makeTenant();
        [, $theirs] = $this->makeTenant();

        $segment = $this->segment($mine, 'Regulars');
        $foreign = $this->customer($theirs, 'theirs'.$this->uniqueFixtureRef().'@example.test');

        $response = $this->actingAs($this->merchantOn($mine, $merchant))
            ->post(self::URI.'/'.$segment->id.'/members', ['customer_id' => $foreign->id]);

        $this->assertTrue($this->refused($response),
            'another tenant\'s customer was added to this store\'s segment -- `customers.id` values '
            .'are reused across tenants, so the id alone proves nothing');

        $this->assertSame(0, CustomerSegmentMember::query()->where('segment_id', $segment->id)->count());
    }

    public function test_adding_the_same_customer_twice_is_idempotent(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $signedIn = $this->merchantOn($store, $merchant);
        $segment = $this->segment($store, 'Regulars');
        $customer = $this->customer($store, 'dup'.$this->uniqueFixtureRef().'@example.test');

        foreach ([1, 2, 3] as $ignored) {
            $this->actingAs($signedIn)
                ->post(self::URI.'/'.$segment->id.'/members', ['customer_id' => $customer->id]);
        }

        /*
         * A DOUBLE-SUBMIT MUST NOT DOUBLE A MEMBERSHIP. Every count over the segment would be wrong,
         * and anything that later sends to a segment would send twice -- a cost the merchant did not
         * ask for. The handler checks and the unique index backs it.
         */
        $this->assertSame(1, CustomerSegmentMember::query()
            ->where('segment_id', $segment->id)->where('customer_id', $customer->id)->count());
    }

    public function test_a_dynamic_segments_members_cannot_be_edited_by_hand(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $segment = $this->segment($store, 'Loyal', CustomerSegment::KIND_DYNAMIC);
        $customer = $this->customer($store, 'rule'.$this->uniqueFixtureRef().'@example.test');

        $response = $this->actingAs($this->merchantOn($store, $merchant))
            ->post(self::URI.'/'.$segment->id.'/members', ['customer_id' => $customer->id]);

        /*
         * TWO SOURCES OF TRUTH FOR ONE SEGMENT IS THE DEFECT. A hand-added row would be invisible on
         * the screen, which reads the rule, while sitting in the table for whatever later reads
         * memberships.
         */
        $this->assertTrue($this->refused($response));
        $this->assertSame(0, CustomerSegmentMember::query()->where('segment_id', $segment->id)->count());
    }

    public function test_deleting_a_segment_takes_its_memberships_with_it(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $signedIn = $this->merchantOn($store, $merchant);
        $segment = $this->segment($store, 'Regulars');
        $customer = $this->customer($store, 'bye'.$this->uniqueFixtureRef().'@example.test');

        $this->actingAs($signedIn)->post(self::URI.'/'.$segment->id.'/members', ['customer_id' => $customer->id]);
        $this->actingAs($signedIn)->delete(self::URI.'/'.$segment->id);

        $this->assertNull(CustomerSegment::query()->where('id', $segment->id)->first());

        /*
         * NO FOREIGN KEY EXISTS ON THIS TABLE -- the migration declares none, deliberately, because
         * the vendored schema has almost none -- so nothing in the database would remove these. An
         * orphaned row carrying a `customer_id` is exactly what a later feature would read as a live
         * membership.
         */
        $this->assertSame(0, CustomerSegmentMember::query()->where('segment_id', $segment->id)->count(),
            'the memberships were orphaned rather than removed');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * Reading one segment
     * ---------------------------------------------------------------------------------------------
     */

    public function test_the_segment_page_lists_its_own_members_only(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $signedIn = $this->merchantOn($store, $merchant);
        $segment = $this->segment($store, 'Regulars');

        $inside = $this->customer($store, 'inside'.$this->uniqueFixtureRef().'@example.test');
        $outside = $this->customer($store, 'outside'.$this->uniqueFixtureRef().'@example.test');

        $this->actingAs($signedIn)->post(self::URI.'/'.$segment->id.'/members', ['customer_id' => $inside->id]);

        $response = $this->actingAs($signedIn)->get(self::URI.'/'.$segment->id);

        $response->assertOk();
        $response->assertSee($inside->email, false);
        $response->assertDontSee($outside->email, false);
    }

    public function test_the_screen_does_not_offer_to_send_anything(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->segment($store, 'Regulars');

        $response = $this->actingAs($this->merchantOn($store, $merchant))->get(self::URI);

        $response->assertOk();

        /*
         * A SEGMENT IS NOT A SENDER, and this is the ratchet on that. Who may be messaged, how often
         * and on what consent is MKT-007 and the D8 consent work, whose enforcement is OFF by owner
         * decision. A "send to segment" button added here would route around all of it, and it would
         * look like a feature rather than a bypass.
         *
         * ASSERTED OVER THE VIEWS' OWN SOURCE, NOT THE RENDERED PAGE, and review is why. A
         * page-wide `assertDontSee` covers the layout and the sidebar too, so it would (a) break
         * spuriously the day the admin chrome gains a menu entry containing one of these words, and
         * (b) pass for a "chase these" control posting to a route named anything else. The source of
         * the two views is the feature's own markup and nothing else -- the scoping failure
         * CLAUDE.md names, avoided rather than described.
         */
        foreach (['customer_segments/index.blade.php', 'customer_segments/show.blade.php'] as $view) {
            $markup = $this->sourceWithoutComments('resources/views/'.$view);

            foreach (\Tests\Unit\Marketing\ConsentLedgerCensusTest::SENDER_MARKERS as $sender) {
                $this->assertStringNotContainsString($sender, $markup,
                    $view.' references the sender `'.$sender.'`. A segment is a named set of '
                    .'customers, not a send target.');
            }

            foreach (['emailsend', 'message-send', 'route(\'campaign', 'recovery-queue'] as $route) {
                $this->assertStringNotContainsString($route, $markup,
                    $view.' links to `'.$route.'`, which would make a segment a send target.');
            }
        }
    }
}
