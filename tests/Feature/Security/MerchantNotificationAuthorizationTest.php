<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\MerchantNotificationController;
use App\Models\StoreNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2-06-3 -- the notification bell is not a way around a permission or a tenant.
 * =============================================================================================
 *
 * WHY A NOTIFICATION IS NOT A NEUTRAL ENVELOPE. `StoreNotificationService::orderCreated()`
 * writes a `message` naming the order reference and a `data` payload carrying `final_price`,
 * `payment_type` and `payment_status`. So a bell that showed every row to every signed-in
 * principal would route around `Manage Order` -- the same data, a different door.
 *
 * AND `StoreNotification` CARRIES NO SCOPE. It is not in `config/tenancy.php`'s `scoped_models`,
 * so `StoreScope::isActiveFor()` short-circuits and the model adds no predicate for anyone. The
 * controller's own `where('store_id', ...)` is the ONLY control, which is why the cross-tenant
 * cases below fail on a single removal rather than being covered by a scope -- measured, and
 * recorded with the other bite tests in PHASE-02-TEST-EVIDENCE.md.
 *
 * FOUR HANDLERS, AND TWO OF THEM WRITE. The two marks are driven as well as the two reads,
 * because a write gated more loosely than the read it accompanies is the shape that authorises
 * one row while touching another.
 */
class MerchantNotificationAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function makeNotification(int $storeId, array $attributes = []): StoreNotification
    {
        return StoreNotification::create(array_merge([
            'store_id' => $storeId,
            'user_id' => null,
            'type' => 'order_created',
            'title' => 'New order',
            'message' => 'Order #ORD-SENTINEL received.',
            'reference_type' => 'order',
            'reference_id' => 1,
            'data' => ['final_price' => 100],
            'is_read' => false,
            'push_sent' => false,
        ], $attributes));
    }

    /** Every title and message the feed served, so a leak anywhere is caught. */
    private function feedText($user): string
    {
        $response = $this->actingAs($user)->getJson(route('merchant.notifications.feed'));

        $this->assertNotSame(404, $response->getStatusCode(), 'the feed route must exist');
        $this->assertNotSame(500, $response->getStatusCode(), 'the feed must not 500');

        return (string) json_encode($response->json());
    }

    // =========================================================================================
    // The premise: the route really is this controller's
    // =========================================================================================

    /**
     * Asserted on the ACTION NAME rather than on the uri's spelling, which is the mistake four
     * censuses in this project have already made.
     *
     * WHAT IT CANNOT CHECK, stated because the first version of this docblock claimed it could.
     * Module route files load from `app->booted()` -- after `routes/web.php` -- and
     * `RouteCollection` keys on method+domain+uri, so a module declaring the same uri would win
     * the slot silently. **But no WorkDo module loads in any test run** unless the test inserts
     * an `add_on_managers` row, so this assertion is structurally blind to exactly that case.
     * It catches a takeover by host code, and a rename. Nothing collides today: of the 70
     * packages the nearest miss is `SMS`'s `notification-template`, a different uri.
     */
    public function test_the_notification_routes_resolve_to_this_controller(): void
    {
        $expected = [
            'merchant.notifications' => 'index',
            'merchant.notifications.feed' => 'feed',
            'merchant.notifications.read' => 'markRead',
            'merchant.notifications.read-all' => 'markAllRead',
        ];

        foreach ($expected as $name => $method) {
            $route = \Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "the route {$name} does not exist");
            $this->assertSame(
                MerchantNotificationController::class . '@' . $method,
                $route->getActionName(),
                "the route {$name} was taken over by another declaration"
            );
        }
    }

    /** The marks are POST. A state-changing GET is F12 and F14 on this phase's ledger. */
    public function test_marking_read_is_not_reachable_by_get(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $notification = $this->makeNotification($store->id);

        $response = $this->actingAs($merchant)
            ->get(route('merchant.notifications.read', $notification->id));

        $this->assertSame(405, $response->getStatusCode(),
            'a GET marked a notification read -- reachable from a third-party <img>');

        // AND IT MOVED NOTHING. Asserted raw, not through Eloquent.
        $this->assertSame(0, (int) \DB::table('store_notifications')
            ->where('id', $notification->id)->value('is_read'));
    }

    // =========================================================================================
    // Cross-tenant
    // =========================================================================================

    public function test_a_merchant_does_not_see_another_tenants_notifications(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Manage Order');

        $this->makeNotification($storeA->id, ['message' => 'Own ORD-ALPHA received.']);
        $this->makeNotification($storeB->id, ['message' => 'Foreign ORD-BETA received.']);

        // PREMISE: both rows exist, so the predicate is what excludes one and not the fixture.
        $this->assertSame(2, \DB::table('store_notifications')->count(),
            'premise: both notifications are in the table');

        $text = $this->feedText($merchantA);

        $this->assertStringContainsString('ORD-ALPHA', $text);
        $this->assertStringNotContainsString('ORD-BETA', $text,
            "merchant A was served merchant B's notification");
    }

    public function test_the_full_page_list_is_also_tenant_scoped(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Manage Order');

        $this->makeNotification($storeA->id, ['message' => 'Own ORD-ALPHA received.']);
        $this->makeNotification($storeB->id, ['message' => 'Foreign ORD-BETA received.']);

        $response = $this->actingAs($merchantA)->get(route('merchant.notifications'));

        $this->assertNotSame(404, $response->getStatusCode(), 'the list route must exist');
        $this->assertNotSame(500, $response->getStatusCode(), 'the list must not 500');
        $response->assertOk();

        $body = (string) $response->getContent();

        $this->assertStringContainsString('ORD-ALPHA', $body);
        $this->assertStringNotContainsString('ORD-BETA', $body,
            "the full-page list served merchant B's notification");
    }

    /**
     * THE WRITE IS SCOPED TOO, and this is the case that matters most of the four: a mark-read
     * that found rows by id alone would let a merchant flip another tenant's row.
     */
    public function test_a_merchant_cannot_mark_another_tenants_notification_read(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Manage Order');

        $foreign = $this->makeNotification($storeB->id);

        $response = $this->actingAs($merchantA)
            ->postJson(route('merchant.notifications.read', $foreign->id));

        $this->assertSame(404, $response->getStatusCode(),
            "merchant A was allowed to act on merchant B's notification");

        // AND THE ROW DID NOT MOVE. Raw, because an Eloquent read can serve a mutated instance.
        $this->assertSame(0, (int) \DB::table('store_notifications')
            ->where('id', $foreign->id)->value('is_read'),
            "merchant A marked merchant B's notification read");
    }

    public function test_mark_all_read_does_not_reach_another_tenants_rows(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Manage Order');

        $own = $this->makeNotification($storeA->id);
        $foreign = $this->makeNotification($storeB->id);

        $response = $this->actingAs($merchantA)
            ->postJson(route('merchant.notifications.read-all'));

        $response->assertOk();

        // The caller's own row moved -- liveness, without which the next assertion is free.
        $this->assertSame(1, (int) \DB::table('store_notifications')
            ->where('id', $own->id)->value('is_read'),
            "mark-all did not mark the caller's own notification");

        $this->assertSame(0, (int) \DB::table('store_notifications')
            ->where('id', $foreign->id)->value('is_read'),
            "mark-all reached another tenant's notification");
    }

    // =========================================================================================
    // Permission, by type
    // =========================================================================================

    public function test_a_staff_member_without_manage_order_sees_no_order_notification(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);

        // Deliberately granted something ELSE, so this measures the order permission rather
        // than "a staff member with no permissions at all sees nothing".
        $this->grantPermission($staff, 'Manage Products');

        $this->makeNotification($store->id, ['message' => 'Own ORD-SENSITIVE received.']);

        $text = $this->feedText($staff);

        $this->assertStringNotContainsString('ORD-SENSITIVE', $text,
            'the bell served an order notification to a staff member who may not read orders -- '
            . 'the message names the order and its data payload carries the price');
    }

    /**
     * F181 RESIDUAL — THE SAME CROSS-STORE UNION, ON THE WEB BELL.
     *
     * `readableTypes()` asked `$user->isAbleTo($permission)`, and Laratrust's `allPermissions()`
     * carries no store predicate, so a grant in ANY store answered for the store being served. One
     * merchant, two stores, a staff member granted `Manage Order` in store A only: switching
     * `current_store` to store B — which they may legitimately do, they belong to the tenant that
     * owns both — handed them store B's order notifications, whose message names the order and
     * whose `data` payload carries `final_price`.
     *
     * This is the SAME defect as the Admin API's, and it is covered in both places deliberately:
     * the two gates share the `TYPE_PERMISSIONS` registry but not the code that applies it, so one
     * test cannot hold both true. `MerchantPermissions::names($user, $storeId)` is now the reader
     * on each.
     */
    public function test_a_grant_in_one_store_does_not_read_another_stores_notifications(): void
    {
        [$owner, $storeA] = $this->makeTenant();
        $storeB = $this->makeStore($owner);

        $staff = $this->makeStaff($owner);               // current_store = store A
        $this->grantPermission($staff, 'Manage Order');  // role.store_id = store A

        $this->makeNotification($storeB->id, ['message' => 'Store-B ORD-CROSSSTORE received.']);
        $staff->forceFill(['current_store' => $storeB->id])->save();

        $text = $this->feedText($staff->fresh());

        $this->assertStringNotContainsString('ORD-CROSSSTORE', $text,
            'the bell served store B\'s order notification to a staff member granted `Manage Order` '
            . 'only in store A. `isAbleTo()` is a union across every store; the gate must ask '
            . '`MerchantPermissions::names($user, $storeId)` (F181 residual).');
    }

    /**
     * The premise of the case above: the grant resolves, so the refusal is the store predicate and
     * not a fixture that silently failed to attach the role.
     */
    public function test_the_same_grant_still_reads_the_store_it_was_granted_in(): void
    {
        [$owner, $storeA] = $this->makeTenant();
        $this->makeStore($owner);

        $staff = $this->makeStaff($owner);
        $this->grantPermission($staff, 'Manage Order');

        $this->makeNotification($storeA->id, ['message' => 'Own ORD-GRANTED received.']);

        $this->assertStringContainsString('ORD-GRANTED', $this->feedText($staff->fresh()),
            'the store-scoped gate refuses the store the role WAS granted in, which is a lockout '
            . 'rather than a tightening — and would make the cross-store case above vacuous');
    }

    public function test_the_same_staff_member_sees_it_once_granted(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);

        $this->makeNotification($store->id, ['message' => 'Own ORD-SENSITIVE received.']);

        $this->assertStringNotContainsString('ORD-SENSITIVE', $this->feedText($staff),
            'premise: without the permission the row must not be served');

        $this->grantPermission($staff, 'Manage Order');

        $this->assertStringContainsString('ORD-SENSITIVE', $this->feedText($staff),
            'the permission does not admit the row, so the test above measures nothing');
    }

    /**
     * AND THE WRITE IS GATED BY THE SAME QUESTION AS THE READ. A mark-read that only checked the
     * store would let the ungated staff member flip a row they cannot see -- a small thing on
     * its own, and the exact shape of a guard that authorises one question and answers another.
     */
    public function test_a_staff_member_without_manage_order_cannot_mark_one_read(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        $this->grantPermission($staff, 'Manage Products');

        $notification = $this->makeNotification($store->id);

        $response = $this->actingAs($staff)
            ->postJson(route('merchant.notifications.read', $notification->id));

        $this->assertSame(404, $response->getStatusCode());

        $this->assertSame(0, (int) \DB::table('store_notifications')
            ->where('id', $notification->id)->value('is_read'));
    }

    /**
     * THE REGISTRY IS A RATCHET. `StoreNotificationService` writes two types today and an
     * unregistered type is refused, so adding a third to the service without naming its
     * permission here fails THIS test rather than silently publishing an unguarded row.
     *
     * The service is read for its `'type' => '…'` literals rather than the table being queried,
     * because the table is empty in a fresh test database -- a query would assert nothing.
     */
    public function test_every_type_the_service_writes_names_a_permission(): void
    {
        $source = (string) file_get_contents(base_path('app/Services/StoreNotificationService.php'));

        // Comment-proofed: the file's own docblocks mention these names, and a ratchet a comment
        // can satisfy is not a ratchet. (Measured in this project: a docblock quoting the string
        // a grep looked for made the grep satisfiable by prose.)
        $source = (string) preg_replace('~/\*.*?\*/~s', '', $source);
        $source = (string) preg_replace('~//[^\n]*~', '', $source);

        // `[A-Za-z0-9_]`, not `[a-z_]`. The narrower class would miss a future `order_refund_v2`
        // or `OrderPaid`, and the ratchet would pass while the type went unregistered -- which
        // fails closed on the web (a notification nobody ever sees) but silently. Raised by the
        // independent review.
        preg_match_all("~'type'\\s*=>\\s*'([A-Za-z0-9_]+)'~", $source, $matches);

        $written = array_values(array_unique($matches[1]));

        // PREMISE: the scan found something. A renamed key would otherwise make this pass
        // vacuously, which is how four censuses in this project missed what they claimed.
        $this->assertNotEmpty($written,
            'the scan found no notification type in StoreNotificationService -- the pattern it '
            . 'looks for has changed, so this ratchet is measuring nothing');

        foreach ($written as $type) {
            $this->assertArrayHasKey(
                $type,
                MerchantNotificationController::TYPE_PERMISSIONS,
                "StoreNotificationService writes the notification type '{$type}', which names no "
                . 'permission in MerchantNotificationController::TYPE_PERMISSIONS. An '
                . 'unregistered type is REFUSED on the web, so this is not a security hole -- it '
                . 'is a notification merchants will never see. Add it to the map with the '
                . 'permission its content requires.'
            );
        }
    }

    /**
     * AN UNREGISTERED TYPE IS NOT SERVED, EVEN TO A MERCHANT WHO HOLDS EVERYTHING.
     *
     * This case exists because the bite test said so. Removing `whereIn('type', $types)` from
     * the query left all fifteen other cases green: with both current types naming the SAME
     * permission, a staff member either reads both or neither, and the empty-types
     * short-circuit in `context()` already serves them nothing -- so the `whereIn` was
     * redundant with it and nothing measured it. A row of an unregistered type is what the
     * `whereIn` alone excludes, and a merchant holding every permission is who proves it is the
     * TYPE being refused rather than the permission.
     *
     * It also pins the design decision: an unregistered type is refused rather than waved
     * through, which is the safe direction for a row that may carry a price or a customer's
     * name.
     */
    public function test_an_unregistered_type_is_not_served_even_to_a_fully_permissioned_merchant(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');
        $this->grantPermission($merchant, 'Manage Customer');
        $this->grantPermission($merchant, 'Manage Products');

        $this->makeNotification($store->id, ['message' => 'Known ORD-REGISTERED received.']);
        $this->makeNotification($store->id, [
            'type' => 'payout_failed_not_registered',
            'message' => 'Unknown ORD-UNREGISTERED received.',
        ]);

        // PREMISE: both rows are in this store's table, so the type filter is what excludes one.
        $this->assertSame(2, \DB::table('store_notifications')
            ->where('store_id', $store->id)->count());

        // PREMISE: the type really is absent from the registry, or this measures nothing.
        $this->assertArrayNotHasKey('payout_failed_not_registered',
            MerchantNotificationController::TYPE_PERMISSIONS);

        $text = $this->feedText($merchant);

        $this->assertStringContainsString('ORD-REGISTERED', $text,
            'premise: a registered type is served to this caller');
        $this->assertStringNotContainsString('ORD-UNREGISTERED', $text,
            'a notification type naming no permission was served anyway');
    }

    // =========================================================================================
    // The platform operator -- Laratrust has no super-admin bypass
    // =========================================================================================

    /**
     * THE OPERATOR IS NOT GATED OUT, and this case exists because the bite test said so too.
     *
     * Deleting the operator limb from `readableTypes()` left all fifteen other cases green,
     * because none of them drove an operator. `isAbleTo()` alone REFUSES the platform operator
     * -- operator status is a string comparison on `users.type`, not a role -- so without that
     * limb an operator resolves no readable type, `context()` returns null, and the bell is
     * empty for the one principal whose contract is to read across stores. That is a lockout,
     * not a tightening, and `App\Http\Middleware\FinancePermission` records it happening here
     * once already.
     *
     * The operator holds NO Laratrust permission in this fixture, deliberately: granting one
     * would make the test pass through the ordinary limb and prove nothing about the operator's.
     */
    public function test_the_platform_operator_is_not_gated_out_of_the_bell(): void
    {
        $operator = $this->makePlatformAdmin();
        [, $store] = $this->makeTenant();

        $this->makeNotification($store->id, ['message' => 'Tenant ORD-OPVISIBLE received.']);

        // PREMISE: the operator holds the permission through no role, so only the operator limb
        // can admit them.
        $this->assertFalse($operator->isAbleTo('Manage Order'),
            'premise: the operator must NOT hold this permission, or the operator limb is not '
            . 'what this test measures');

        $text = $this->feedText($operator);

        $this->assertStringContainsString('ORD-OPVISIBLE', $text,
            'the platform operator was served nothing -- isAbleTo() refuses them, so the '
            . 'operator limb must come FIRST');
    }

    // =========================================================================================
    // The bell is REACHABLE by the principals the authorisation was written for
    // =========================================================================================

    /**
     * A STAFF MEMBER WITH `Manage Order` CAN GET TO THE SCREEN.
     *
     * Found by the independent review, and it is the defect that made the whole per-type
     * authorisation decorative: the bell `<li>` was first placed inside
     * `@if (auth()->user()->type == 'admin')` in the header. Staff `users.type` is their ROLE
     * NAME (`UserController::store`: `$user->type = $role_r->name`), never the literal
     * `'admin'`, so that conditional is owner-only -- and `grep` finds no other link to
     * `/notifications` anywhere in the application. A staff member holding exactly the
     * permission this surface gates on had no way to reach it.
     *
     * DRIVEN OVER HTTP against the real rendered header, not asserted against the Blade source:
     * the question is whether the link reaches the principal, and a source grep cannot answer
     * that -- it would pass with the `<li>` inside any conditional at all.
     */
    public function test_the_bell_reaches_a_staff_member_who_may_read_notifications(): void
    {
        [$owner] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        $this->grantPermission($staff, 'Manage Order');

        // PREMISE: this staff member is NOT type 'admin', which is what the old conditional
        // required. Without this the test could pass because the fixture made an owner.
        $this->assertNotSame('admin', strtolower(trim((string) $staff->type)),
            'premise: staff must not carry the owner type, or this measures nothing');

        $response = $this->actingAs($staff)->get(route('merchant.notifications'));
        $response->assertOk();

        // The header the staff member actually receives carries the bell.
        $response->assertSee('data-ss-notification-bell', false);
        $response->assertSee(route('merchant.notifications.feed'), false);
    }

    /** And the owner still has it, so moving it did not take it away from anyone. */
    public function test_the_bell_still_reaches_the_tenant_owner(): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $response = $this->actingAs($merchant)->get(route('merchant.notifications'));

        $response->assertOk();
        $response->assertSee('data-ss-notification-bell', false);
    }

    /**
     * THE EMPTY-STATE BRANCH IS RENDERED, which no case reached before.
     *
     * A staff member holding no readable type takes `$readableTypes === []` in the view, and
     * nothing drove it: the three staff cases read the feed JSON and the one page case had rows.
     * A 500 in that branch would have shipped. It also checks the COPY, because that branch is
     * reached for two different reasons and only one of them is a permissions problem -- see the
     * controller's note on `context()`.
     */
    public function test_a_staff_member_with_no_readable_type_gets_the_rendered_empty_state(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        $this->grantPermission($staff, 'Manage Products');

        $this->makeNotification($store->id, ['message' => 'Own ORD-HIDDEN received.']);

        $response = $this->actingAs($staff)->get(route('merchant.notifications'));

        $this->assertNotSame(500, $response->getStatusCode(),
            'the empty-state branch crashes, and nothing rendered it before this test');
        $response->assertOk();

        $body = (string) $response->getContent();

        $this->assertStringNotContainsString('ORD-HIDDEN', $body,
            'the empty state still leaked the row it exists to withhold');
        $this->assertStringContainsString('ss-empty', $body,
            'the empty state did not render');
    }

    /**
     * THE "VIEW ORDER" BUTTON FOLLOWS `Show Order`, NOT `Manage Order`.
     *
     * Two different permissions, which an earlier docblock here claimed were one. The
     * notification gates on `Manage Order`; `OrderController::order_preview` gates on
     * `Show Order` through `mayUseOrderScreen`. A staff member can hold the first and not the
     * second, and the button then answered 403.
     *
     * NOT A SECURITY CASE -- the preview fails closed on its own, which is asserted here too so
     * the claim is measured rather than assumed. It is a usability one, and it has no other test
     * that would catch it regressing.
     */
    public function test_the_view_order_button_follows_show_order_not_manage_order(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        $this->grantPermission($staff, 'Manage Order');

        $order = $this->makeOrder($store, ['product_order_id' => 'ORD-BTN-1']);
        $this->makeNotification($store->id, [
            'reference_id' => $order->id,
            'message' => 'Own ORD-BTN-1 received.',
        ]);

        $response = $this->actingAs($staff)->get(route('merchant.notifications'));
        $response->assertOk();

        $body = (string) $response->getContent();

        // PREMISE: the row IS served -- `Manage Order` admits the notification itself, so what
        // follows is about the button and not about the row being withheld.
        $this->assertStringContainsString('ORD-BTN-1', $body,
            'premise: Manage Order admits the notification');

        $this->assertStringNotContainsString(route('order.preview', $order->id), $body,
            'the feed offered a "View order" button to a caller without Show Order, which the '
            . 'preview refuses');

        // AND THE PREVIEW REALLY DOES REFUSE THEM, so "fails closed" is measured.
        $this->assertSame(403, $this->actingAs($staff)
            ->get(route('order.preview', $order->id))->getStatusCode(),
            'premise: the preview refuses a caller without Show Order');

        // Granted, the button appears.
        $this->grantPermission($staff, 'Show Order');

        $this->assertStringContainsString(
            route('order.preview', $order->id),
            (string) $this->actingAs($staff)->get(route('merchant.notifications'))->getContent(),
            'the button does not appear once Show Order is held, so the test above measures '
            . 'nothing'
        );
    }

    // =========================================================================================
    // THE OPERATOR'S WRITE -- reading across stores is their contract; writing across them is not
    // =========================================================================================

    /**
     * MARK-ALL IS NEVER A PLATFORM-WIDE WRITE, not even for the operator.
     *
     * Found by the independent review, and it was my own comment that was wrong: `scope()` adds
     * the store predicate only for a non-operator, so `markAllRead`'s mass `update()` carried
     * just `type IN (…) AND is_read = 0` for the operator -- one POST flipping `is_read` on
     * EVERY tenant's rows, clearing every merchant's badge, with no mark-unread anywhere to undo
     * it.
     *
     * The operator's READ across stores is the house pattern and is unchanged. A write is a
     * different contract, and nothing else in this slice crosses a tenant to write.
     *
     * THE STORE COMES FROM `current_store` DIRECTLY, not from `getCurrentStore()`. That helper
     * returns the **literal 1** for a `super admin` whose `current_store` is empty
     * (`app/Helper/helper.php:636-637`), so confining the write by way of it would silently
     * mass-mark STORE 1 for an operator who is not looking at any store. That trap is why this
     * test drives an operator with no `current_store` as well as one with it set.
     */
    public function test_the_operator_cannot_mark_every_tenants_notifications_read(): void
    {
        $operator = $this->makePlatformAdmin();
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $a = $this->makeNotification($storeA->id);
        $b = $this->makeNotification($storeB->id);

        // The operator is looking at store A.
        $operator->forceFill(['current_store' => $storeA->id])->save();
        (new \App\Models\User)->flushCache();

        // PREMISE: the operator can SEE both stores' rows. Without this the write being
        // confined would be indistinguishable from the operator seeing nothing at all.
        $text = $this->feedText($operator);
        $this->assertStringContainsString('ORD-SENTINEL', $text,
            'premise: the operator reads across stores');
        $this->assertSame(2, (int) $this->actingAs($operator)
            ->getJson(route('merchant.notifications.feed'))->json('unread'),
            'premise: the operator sees BOTH stores unread rows');

        $response = $this->actingAs($operator)->postJson(route('merchant.notifications.read-all'));
        $response->assertOk();

        // The store they are looking at is marked -- liveness, or "it did not touch store B"
        // would hold for a handler that writes nothing at all.
        $this->assertSame(1, (int) \DB::table('store_notifications')
            ->where('id', $a->id)->value('is_read'),
            'mark-all did not mark the store the operator is actually looking at');

        $this->assertSame(0, (int) \DB::table('store_notifications')
            ->where('id', $b->id)->value('is_read'),
            'one POST by the platform operator marked ANOTHER tenant\'s notifications read, '
            . 'across the whole platform, with nothing to undo it');
    }

    /**
     * AND AN OPERATOR WITH NO CURRENT STORE WRITES NOTHING, rather than writing to store 1.
     *
     * This is the trap in the obvious fix. `getCurrentStore()` answers the literal 1 for a
     * `super admin` with an empty `current_store`, so confining the mass write through that
     * helper would mass-mark whichever tenant happens to be id 1 -- a different wrong answer,
     * and a quieter one.
     */
    public function test_an_operator_with_no_current_store_marks_nothing(): void
    {
        $operator = $this->makePlatformAdmin(['current_store' => null]);
        [, $storeA] = $this->makeTenant();

        // PREMISE: store A really is id 1, so this test is standing where the trap is. If the
        // fixture ever stops producing id 1 first, this assertion says so rather than the test
        // quietly measuring nothing.
        $this->assertSame(1, (int) $storeA->id,
            'premise: the first tenant must be store 1, which is what getCurrentStore() '
            . 'returns for an operator with no current store');

        $row = $this->makeNotification($storeA->id);

        $response = $this->actingAs($operator)->postJson(route('merchant.notifications.read-all'));
        $response->assertOk();

        $this->assertSame(0, (int) \DB::table('store_notifications')
            ->where('id', $row->id)->value('is_read'),
            'an operator who is not looking at any store mass-marked store 1');
    }

    // =========================================================================================
    // Anonymous, and the unresolved tenant
    // =========================================================================================

    public function test_an_anonymous_caller_is_refused(): void
    {
        [, $store] = $this->makeTenant();
        $this->makeNotification($store->id, ['message' => 'Anon ORD-SECRET received.']);

        $feed = $this->getJson(route('merchant.notifications.feed'));
        $this->assertContains($feed->getStatusCode(), [302, 401, 403]);
        $this->assertStringNotContainsString('ORD-SECRET', (string) $feed->getContent());

        $list = $this->get(route('merchant.notifications'));
        $this->assertContains($list->getStatusCode(), [302, 401, 403]);
        $this->assertStringNotContainsString('ORD-SECRET', (string) $list->getContent());
    }

    /**
     * A PRINCIPAL WITH NO CURRENT STORE IS NOT QUERIED FOR AT ALL.
     *
     * THE ROW ASSERTION ALONE WAS VACUOUS, and the independent review caught it. Deleting the
     * guard this case exists for left the whole file green: `where('store_id', null)` is
     * rewritten by Laravel to `store_id IS NULL`, and `store_notifications.store_id` is declared
     * NOT NULL, so no row can ever match whether or not the guard is there. The earlier docblock
     * half-admitted this and the test name claimed a control anyway.
     *
     * SO IT COUNTS THE QUERIES INSTEAD. The claim is that the handler does not go to the table
     * for a principal who resolves no tenant, and that is a fact about the queries rather than
     * about the rows. With the guard: 0 queries against `store_notifications`. Without it: 2.
     * The row assertion is kept as well -- it is the consequence anyone actually cares about.
     */
    public function test_a_principal_with_no_current_store_is_served_nothing(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Manage Order');

        $this->makeNotification($storeB->id, ['message' => 'Foreign ORD-BETA received.']);

        /*
         * Strand the principal. `stores.created_by` carries a FOREIGN KEY to users, so
         * reassigning it fails the constraint -- the store is DELETED instead, which is what
         * "owns no store" actually means. Through Eloquent so the Cachable User's cache is
         * flushed: a raw update would leave the handler's own read serving the pre-write row.
         */
        $merchantA->forceFill(['current_store' => null])->save();
        \DB::table('store_notifications')->where('store_id', $storeA->id)->delete();
        \DB::table('stores')->where('created_by', $merchantA->id)->delete();
        (new \App\Models\User)->flushCache();

        // PREMISE: the principal really resolves no tenant now.
        $this->assertEmpty(getCurrentStore($merchantA->id), 'premise: no tenant may resolve');

        $queries = 0;
        \DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'store_notifications')) {
                $queries++;
            }
        });

        $text = $this->feedText($merchantA);

        $this->assertSame(0, $queries,
            'the handler queried store_notifications for a principal who resolves no tenant. '
            . 'That happens to return nothing today only because store_id is NOT NULL, so the '
            . 'row assertion below cannot see the difference -- this is the one that can');

        $this->assertStringNotContainsString('ORD-BETA', $text,
            'a principal who resolves no tenant was served another store\'s notifications');
    }

    /**
     * THE PREMISE FOR THE CASE ABOVE: a caller who DOES resolve a tenant is queried for.
     *
     * Without this, "0 queries" could be satisfied by a handler that never queries at all, by a
     * `DB::listen` registered too late, or by a changed table name the `str_contains` no longer
     * matches. Measured: 2 queries, which is the feed's rows plus its unread count.
     */
    public function test_a_resolved_principal_really_does_reach_the_table(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');
        $this->makeNotification($store->id);

        $queries = 0;
        \DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'store_notifications')) {
                $queries++;
            }
        });

        $this->feedText($merchant);

        $this->assertGreaterThan(0, $queries,
            'the query counter saw nothing for a caller who certainly reads the table, so the '
            . 'no-tenant case above is counting nothing');
    }

    // =========================================================================================
    // Ordering
    // =========================================================================================

    /**
     * NEWEST FIRST, AND THE FIXTURE MAKES ID ORDER DISAGREE WITH TIME ORDER ON PURPOSE.
     *
     * `orderByDesc('id')` is reverse-chronological only while ids and timestamps ascend
     * together, which they normally do -- so a fixture built the normal way cannot tell the two
     * apart, and the case would pass against either. Here row 1 is the NEWEST and row 3 the
     * oldest, which is the shape that exposes the difference.
     *
     * It is also the shape that exposed it for real: the P2-06-3 screenshots were taken against
     * a fixture that happened to be built this way, and the rendered feed showed the oldest
     * notification at the top. Found by looking at the picture.
     */
    public function test_the_feed_is_ordered_newest_first_by_time_not_by_id(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $newest = $this->makeNotification($store->id, ['message' => 'Row NEWEST received.']);
        $middle = $this->makeNotification($store->id, ['message' => 'Row MIDDLE received.']);
        $oldest = $this->makeNotification($store->id, ['message' => 'Row OLDEST received.']);

        // Ids ascend 1,2,3 while the timestamps DESCEND -- raw, so `created_at` is written
        // exactly as given rather than being refreshed by the model's own timestamps.
        \DB::table('store_notifications')->where('id', $newest->id)
            ->update(['created_at' => now()->subHour()]);
        \DB::table('store_notifications')->where('id', $middle->id)
            ->update(['created_at' => now()->subHours(5)]);
        \DB::table('store_notifications')->where('id', $oldest->id)
            ->update(['created_at' => now()->subHours(9)]);

        // PREMISE: id order and time order really do disagree, or this measures nothing.
        $byId = \DB::table('store_notifications')->orderBy('id')->pluck('created_at')->all();
        $this->assertGreaterThan((string) $byId[1], (string) $byId[0],
            'premise: the fixture must make the lowest id the NEWEST row');

        $items = $this->actingAs($merchant)
            ->getJson(route('merchant.notifications.feed'))
            ->json('items');

        $this->assertCount(3, $items, 'premise: all three rows are served');

        $this->assertStringContainsString('NEWEST', $items[0]['message'],
            'the feed is ordered by id rather than by time, so the newest notification is not '
            . 'at the top when the two disagree');
        $this->assertStringContainsString('MIDDLE', $items[1]['message']);
        $this->assertStringContainsString('OLDEST', $items[2]['message']);
    }

    // =========================================================================================
    // The unread count, which is what the bell shows
    // =========================================================================================

    public function test_the_unread_count_counts_only_this_stores_unread_readable_rows(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Manage Order');

        $this->makeNotification($storeA->id);                        // unread, own
        $this->makeNotification($storeA->id, ['is_read' => true]);   // read, own
        $this->makeNotification($storeB->id);                        // unread, foreign

        $response = $this->actingAs($merchantA)->getJson(route('merchant.notifications.feed'));

        $response->assertOk();
        $this->assertSame(1, (int) $response->json('unread'),
            'the bell counted a read row, or another tenant\'s');
    }

    /** And marking one read decrements it, so the badge can actually clear. */
    public function test_marking_read_decrements_the_count(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $notification = $this->makeNotification($store->id);

        $this->assertSame(1, (int) $this->actingAs($merchant)
            ->getJson(route('merchant.notifications.feed'))->json('unread'),
            'premise: the row starts unread');

        $response = $this->actingAs($merchant)
            ->postJson(route('merchant.notifications.read', $notification->id));

        $response->assertOk();
        $this->assertSame(0, (int) $response->json('unread'));

        $this->assertSame(1, (int) \DB::table('store_notifications')
            ->where('id', $notification->id)->value('is_read'),
            'the response said 0 unread but the row was never written');
    }

    /**
     * A non-integer id is refused rather than coerced.
     *
     * THROUGH `route()`, NOT A HAND-WRITTEN PATH. The first version posted
     * a hand-built `'/notifications/' . $id . 'abc/read'` and asserted 404 -- which a NONEXISTENT URI also
     * returns, so a renamed route would have satisfied it. That is defect shape #2 in this
     * project's own list, and the independent review found it here.
     *
     * AND THE HAZARD IT NAMES CANNOT BE REPRODUCED ON THIS ENGINE, which is stated rather than
     * implied. The risk is MySQL coercing `'5abc'` to 5 in `WHERE id = ?`; the suite runs SQLite
     * (`phpunit.xml`), where that comparison matches nothing, so removing `filter_var` from the
     * handler leaves this green. The control is correct and needed in production -- SQLite's
     * affinity hiding a real MySQL defect is exactly how P01-SEC-13 survived -- and what this
     * case genuinely pins is that the route is reachable, that a malformed id is refused rather
     * than 500ing, and that no row moves.
     */
    public function test_a_non_integer_id_is_refused(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Order');

        $notification = $this->makeNotification($store->id);

        $response = $this->actingAs($merchant)
            ->postJson(route('merchant.notifications.read', $notification->id . 'abc'));

        $this->assertSame(404, $response->getStatusCode());

        $this->assertSame(0, (int) \DB::table('store_notifications')
            ->where('id', $notification->id)->value('is_read'),
            'a coerced id marked a real row read');

        /*
         * THE PREMISE GOES LAST, because driving it is a WRITE. Checking that the well-formed id
         * is accepted proves the 404 above was the handler refusing a malformed id rather than
         * the router failing to find a uri -- but it also marks the row read, so it has to come
         * after the row assertion. (Measured: putting it first failed the row assertion, which
         * is the right failure for the wrong reason.)
         */
        $this->assertSame(200, $this->actingAs($merchant)
            ->postJson(route('merchant.notifications.read', $notification->id))
            ->getStatusCode(),
            'premise: the well-formed id IS accepted on this exact route, so the 404 above was '
            . 'the handler refusing the id and not the router missing the uri');
    }
}
