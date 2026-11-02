<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\MerchantNotificationController;
use App\Models\StoreNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * F181 — `GET api/admin/notification` GATES EACH TYPE ON THE SAME PERMISSION THE WEB BELL USES.
 * =============================================================================================
 *
 * `Api\DashboardController::noti` was `StoreNotification::where('store_id', $user->current_store)`
 * with no type filter and no permission test, behind `['custom.auth','AdminApiLog']` — and
 * `AdminApiLog` only logs. So **any `User` Sanctum token** whose `current_store` is that store — a
 * staff member holding nothing but `Manage Products` — read every notification's title and message,
 * including `order_created` and `order_paid`, which carry `final_price`, `payment_type` and
 * `payment_status`. The merchant WEB bell gates each type on a permission
 * (`MerchantNotificationController::TYPE_PERMISSIONS`); the API did not, so the registry was not the
 * only door and the API was the open one.
 *
 * THE OWNER'S CONDITION WAS "DO NOT FIX THE LEAK BY BREAKING THE APP'S COUNTER", and it is met by
 * measurement rather than by hope — two independent reasons:
 *
 *   1. THE REGISTRY IS COMPLETE. `StoreNotificationService` writes exactly three types, and all
 *      three are in `TYPE_PERMISSIONS`. So the filter hides nothing that exists, and a caller
 *      holding `Manage Order` sees an IDENTICAL list and an IDENTICAL count to before — for the
 *      ordinary merchant this change is a no-op. The last case here is the ratchet that keeps it
 *      true: a fourth type written without a permission entry would silently vanish from this
 *      endpoint, which is the one way this fix could break the app.
 *   2. A COUNT THAT DROPS IS HANDLED BY THE APP ITSELF. For a staff member who may read none of the
 *      three, the count goes to 0. `notification_controller.dart`'s `_calculateUnreadCount()`
 *      computes `currentCount - lastSavedCount`, shows it only when positive, and when
 *      `currentCount < lastCount` it RE-BASELINES — overwrites the stored total, persists it, shows
 *      0. So a drop is a one-time re-baseline, not a stuck or negative badge.
 *
 * `count` KEEPS ITS MEANING: "every notification in this store this caller may read", the same
 * arithmetic over the permitted set. It is deliberately NOT narrowed to unread — the app diffs
 * totals and never reads `is_read`, so counting unread would change the arithmetic rather than the
 * set.
 */
class AdminApiNotificationScopeTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** A merchant with `Manage Order` sees exactly what they saw before: all three types. */
    public function test_a_caller_with_the_permission_sees_every_type_and_the_full_count(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Manage Order');
        $this->seedOneOfEachType((int) $store->id);
        $owner->forceFill(['current_store' => $store->id])->save();

        $payload = $this->noti($owner);

        $this->assertSame(3, $payload['count'], 'the merchant\'s own count changed, which is the'
            . ' regression the owner forbade — the Flutter badge arithmetic runs on this number');
        $this->assertCount(3, $payload['data']['data'], 'the merchant lost rows they could read');
    }

    /**
     * THE LEAK ITSELF. A staff member with only `Manage Products` must read none of the three.
     *
     * All three carry money: `order_created` and `order_paid` name `final_price`, `payment_type`
     * and `payment_status`, and `payment_needs_attention` names a gateway and an amount for a
     * capture with no order row — so there is no order for `Show Order` or
     * `OrderDocumentAccessGuard` to gate, and this endpoint was the only door.
     */
    public function test_a_staff_member_without_the_permission_reads_nothing(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        $this->grantPermission($staff, 'Manage Products');
        $this->seedOneOfEachType((int) $store->id);
        $staff->forceFill(['current_store' => $store->id])->save();

        $payload = $this->noti($staff);

        $this->assertSame([], $payload['data']['data'],
            'a staff member holding only `Manage Products` still reads every order notification\'s'
            . ' title and message, including the money fields — which is F181');
        $this->assertSame(0, $payload['count'],
            'the count still reports rows this caller may not read. The list and the count must'
            . ' agree, or the Flutter badge counts notifications the app will never show.');
    }

    /** Store isolation is unchanged: another tenant's rows are not readable or counted. */
    public function test_another_stores_notifications_are_neither_listed_nor_counted(): void
    {
        [$owner, $store] = $this->makeTenant();
        [, $other] = $this->makeTenant();
        $this->grantPermission($owner, 'Manage Order');
        $this->seedOneOfEachType((int) $other->id);
        $owner->forceFill(['current_store' => $store->id])->save();

        $payload = $this->noti($owner);

        $this->assertSame([], $payload['data']['data'], 'another tenant\'s notifications are listed');
        $this->assertSame(0, $payload['count'], 'another tenant\'s notifications are counted');
    }

    /**
     * THE OPERATOR IS TESTED FIRST, because `isAbleTo()` alone refuses them.
     *
     * This codebase has no super-admin bypass — operator status is a string comparison on
     * `users.type` — so a permission-only check locks the platform operator out of their own
     * surface. Reversing the two limbs is a lockout rather than a tightening, and this case is
     * what catches it.
     */
    public function test_the_platform_operator_is_not_locked_out(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->seedOneOfEachType((int) $store->id);

        $operator = $this->makeStaff($owner);
        $operator->forceFill(['type' => 'super admin', 'current_store' => $store->id])->save();

        $payload = $this->noti($operator);

        $this->assertSame(3, $payload['count'],
            'the platform operator reads nothing, which is the `isAbleTo()`-before-operator ordering'
            . ' trap this codebase records: it refuses the operator outright');
    }

    /**
     * A NON-`User` TOKEN IS REFUSED RATHER THAN 500-ING.
     *
     * `custom.auth` logs a token's owner into the guard matching the TOKENABLE CLASS, so a
     * `Customer` or `DeliveryBoy` token passes the middleware while `Auth::user()` — the `web`
     * guard — stays null. Before this change that was a null dereference on
     * `$user->current_store`.
     */
    public function test_a_caller_the_web_guard_does_not_recognise_is_refused(): void
    {
        $payload = json_decode($this->controller()->noti(request())->getContent(), true);

        $this->assertSame(0, $payload['status'] ?? null,
            'a caller the `web` guard does not recognise is no longer refused — before this change'
            . ' it was a 500 on a null dereference, and it must not become a disclosure');
    }

    /**
     * THE RATCHET THAT MAKES THE FILTER SAFE, and the one way this fix could break the app.
     *
     * `whereIn('type', $readable)` hides any type absent from the registry. Today that is nothing:
     * `StoreNotificationService` writes exactly the three the registry gates. A fourth type added
     * without a permission entry would vanish from this endpoint and from the web bell — silently,
     * and for every caller including the operator. This asserts the two sets agree, so adding a
     * type forces the decision about who may read it.
     */
    public function test_the_registry_covers_every_type_the_service_writes(): void
    {
        $source = file_get_contents(app_path('Services/StoreNotificationService.php'));

        preg_match_all("/'type'\s*=>\s*'([a-z_]+)'/", $source, $matches);
        $written = array_values(array_unique($matches[1]));

        $this->assertNotEmpty($written, 'the census found no written types, so the comparison below'
            . ' would be vacuous — the regex, not the codebase, is the likely cause');

        $gated = array_keys(MerchantNotificationController::TYPE_PERMISSIONS);

        $this->assertSame(
            [],
            array_values(array_diff($written, $gated)),
            'a notification type is written but has no permission entry, so `whereIn(\'type\', …)`'
            . ' hides it from the admin API and the web bell for EVERY caller, operator included.'
            . ' Add it to MerchantNotificationController::TYPE_PERMISSIONS with the permission that'
            . ' should gate it. Written: ' . implode(', ', $written)
        );
    }

    /**
     * F181 RESIDUAL — `isAbleTo()` IS A UNION ACROSS EVERY STORE, SO A GRANT IN ONE STORE READ
     * ANOTHER STORE'S MONEY FIELDS.
     *
     * The F181 fix gated each type on `TYPE_PERMISSIONS`, which closed the open door. It asked the
     * question with `$user->isAbleTo($permission)` — and Laratrust's `allPermissions()` carries **no
     * store predicate**, so that is the union of every grant the person holds anywhere. This
     * repository already has the store-scoped reader for exactly this reason
     * (`MerchantPermissions::names()`, whose own docblock says *"an employee permitted in A would
     * read B"*), and this endpoint was not using it.
     *
     * MULTI-STORE IS A FIRST-CLASS FEATURE HERE, so the path is ordinary rather than contrived: one
     * merchant, two stores, a staff member granted `Manage Order` in store A only. Switching
     * `current_store` to store B is a legitimate action for that staff member — they belong to the
     * tenant that owns both — and it is the switch, not an injected id, that moves the data. What
     * they must not gain by it is a permission they were never granted in B.
     *
     * WHAT LEAKS IS MONEY. `order_paid` and `order_created` carry `final_price`, `payment_type` and
     * `payment_status` in their title and message; `payment_needs_attention` names a gateway and an
     * amount for a capture with no order row.
     */
    public function test_a_grant_in_one_store_does_not_read_another_stores_notifications(): void
    {
        [$owner, $storeA] = $this->makeTenant();
        $storeB = $this->makeStore($owner);

        $staff = $this->makeStaff($owner);                       // current_store = store A
        $this->grantPermission($staff, 'Manage Order');          // role.store_id = store A

        $this->seedOneOfEachType((int) $storeB->id);
        $staff->forceFill(['current_store' => $storeB->id])->save();

        $payload = $this->noti($staff->fresh());

        $this->assertSame([], $payload['data']['data'],
            'a staff member granted `Manage Order` in store A reads store B\'s order notifications '
            . 'after switching stores, including `final_price`, `payment_type` and `payment_status`. '
            . '`isAbleTo()` is a union across every store the person holds a role in; the gate must '
            . 'ask `MerchantPermissions::allowsAny($user, [...], $storeId)` instead (F181 residual).');

        $this->assertSame(0, $payload['count'],
            'the count still reports another store\'s rows for a caller with no grant there');
    }

    /**
     * THE PREMISE OF THE CASE ABOVE, and it is not optional.
     *
     * If the grant did not resolve at all — a fixture that silently failed to attach the role —
     * the case above would pass while proving nothing, which is this codebase's most frequent way
     * for a tenancy test to be vacuous. This asserts the SAME staff member, with the SAME role,
     * does read the store the grant is actually for.
     */
    public function test_the_same_grant_still_reads_the_store_it_was_granted_in(): void
    {
        [$owner, $storeA] = $this->makeTenant();
        $this->makeStore($owner);

        $staff = $this->makeStaff($owner);
        $this->grantPermission($staff, 'Manage Order');

        $this->seedOneOfEachType((int) $storeA->id);

        $payload = $this->noti($staff->fresh());

        $this->assertSame(3, $payload['count'],
            'the store-scoped gate refuses the store the role WAS granted in, which is a lockout '
            . 'rather than a tightening — and it would make the cross-store case above vacuous');
    }

    private function seedOneOfEachType(int $storeId): void
    {
        foreach (array_keys(MerchantNotificationController::TYPE_PERMISSIONS) as $type) {
            StoreNotification::forceCreate([
                'store_id' => $storeId,
                'type' => $type,
                'title' => 'SS-F181 ' . $type,
                'message' => 'SS-F181 message for ' . $type,
                'is_read' => false,
            ]);
        }
    }

    private function noti(\App\Models\User $user): array
    {
        $this->actingAs($user);

        return json_decode($this->controller()->noti(request())->getContent(), true);
    }

    private function controller(): \App\Http\Controllers\Api\DashboardController
    {
        return app(\App\Http\Controllers\Api\DashboardController::class);
    }
}
