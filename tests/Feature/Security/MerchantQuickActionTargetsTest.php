<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\MerchantQuickActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * D-24a — THE FOUR CREATE-ACTIONS, AND THE PROPERTY THAT MAKES THEM SAFE.
 *
 * The owner's approval carries one condition that is not a code path but an invariant: check the
 * employee's permission and the current store **"على العرض وعلى الطلب"** — on render and on
 * request. Two places decide, so the only honest way to satisfy it is to prove they agree, by
 * driving the real target for every principal the palette would and would not offer it to.
 *
 * THAT IS WHAT THIS FILE IS. `MerchantQuickActions` declares, per action, the permission its target
 * enforces and whether that target admits the platform operator. Every row below drives the target
 * and fails if the declaration and the behaviour disagree. A matrix of `isAbleTo()` assertions
 * against the service alone would prove only that the service agrees with itself.
 *
 * THE OPERATOR IS WHY THIS MATTERS RATHER THAN BEING A FORMALITY. The four targets genuinely
 * disagree about the operator — `order` and `customer` admit them, `coupon` (F157's decision) and
 * `product` (F160) refuse — so there is no single rule to apply, and a wrong declaration would
 * either dangle an action that refuses on click or hide one that works. Only execution settles it.
 */
class MerchantQuickActionTargetsTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function declaredActions(): array
    {
        $cases = [];

        foreach (MerchantQuickActions::declared() as $action) {
            $cases[$action['name']] = [$action];
        }

        return $cases;
    }

    /** The approved list, exactly — no delete, payment or refund action may appear in the palette. */
    public function test_the_palette_offers_the_four_approved_actions_and_nothing_else(): void
    {
        $names = array_column(MerchantQuickActions::declared(), 'name');

        sort($names);

        $this->assertSame(
            ['create-coupon', 'create-customer', 'create-order', 'create-product'],
            $names,
            'the owner approved four create-actions and forbade delete, payment and refund actions '
            . 'in the palette'
        );

        foreach (MerchantQuickActions::declared() as $action) {
            foreach (['destroy', 'delete', 'refund', 'payment', 'pay'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $action['route'],
                    'the palette must not route to a destructive or money-moving action'
                );
            }
        }
    }

    /**
     * THE SAFETY INVARIANT, AND IT IS AN IMPLICATION RATHER THAN AN EQUALITY — which is the one
     * thing in this file I got wrong first and had to measure.
     *
     * "على العرض وعلى الطلب" means the RENDER must not be more permissive than the REQUEST. So:
     * **if the palette offers an action, its target must serve the same principal.** The converse
     * is not a safety property and must not be asserted, because two legitimate reasons make the
     * palette withhold an action a target would serve:
     *
     *  1. THE MENU PERMISSION IS NOT THE ACTION PERMISSION, for two of the four. The `product`
     *     navigation entry requires `Manage Products` and the create form requires
     *     `Create Product`; `coupon` requires `Manage Coupon` and the form `Create Coupon`. An
     *     employee holding only the create right has no product page, so no action — which is the
     *     owner's own condition, "لا تعرض إجراءً إذا كانت الصفحة أو الوحدة غير متوفرة".
     *  2. THE OPERATOR'S NAVIGATION IS A DIFFERENT MENU ENTIRELY. `SuperAdminMenuListener`
     *     registers dashboard, add-on-manager, users, coupon, plan, planrequest, email-templates,
     *     settings, country and currency — and **no product, order or customer page at all**. So
     *     the operator is offered at most `create-coupon`, which is declared `operator => false`
     *     because F157's decision refuses them every coupon write. Net: the operator is offered
     *     nothing, while `order.create` and `customer.create` would in fact serve them.
     *
     * My first version asserted `offered === serves` and failed on exactly those two facts. It was
     * asserting a UX preference as though it were a security property. This asserts the property.
     *
     * @dataProvider principalsAndActions
     */
    public function test_every_offered_action_is_served_by_its_target(string $principal, array $action): void
    {
        [$merchant, $store] = $this->makeTenant();
        $user = $this->principal($principal, $merchant, $store, $action);

        if (! array_key_exists($action['name'], $this->offeredTo($user))) {
            $this->addToAssertionCount(1);

            return;
        }

        $status = $this->actingAs($user)->get($this->uriOf($action))->getStatusCode();

        $this->assertSame(
            200,
            $status,
            $principal . ' / ' . $action['name'] . ': the palette OFFERS this action and the target '
            . "refused the same principal (HTTP {$status}). The render is more permissive than the "
            . 'request, which is exactly what the approval forbids.'
        );
    }

    /**
     * THE CASES THAT ACTUALLY EXERCISE THE PERMISSION CHECK — and this file had none until three
     * deliberate bites all failed to bite, which is worth recording in full because it is the
     * failure mode CLAUDE.md names first.
     *
     * I reverted the service's permission check, then its operator store guard, then flipped a
     * declaration to a knowingly wrong value. All 25 cases stayed green on every one. The reason is
     * that **the page gate dominates**: `MerchantPageDirectory::available()` is built from the real
     * `Menu`, which is permission-filtered, so a principal holding nothing has NO pages and
     * therefore no actions whatever the permission check does. And the operator's navigation
     * (`SuperAdminMenuListener`) contains no product, order or customer page at all, so for the
     * operator both the declaration and the store guard are unreachable.
     *
     * So the only principal for whom the action permission decides anything is one who HAS the
     * menu page and NOT the action permission — and for two of the four those are different
     * permissions (`Manage Products` vs `Create Product`, `Manage Coupon` vs `Create Coupon`).
     * Those are these cases. They fail the moment the permission check is removed.
     *
     * @dataProvider pageWithoutActionPermission
     */
    public function test_the_menu_page_alone_does_not_earn_the_action(string $name, string $pagePermission, string $actionPermission): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);
        $this->grantPermission($staff, $pagePermission);

        $action = collect(MerchantQuickActions::declared())->firstWhere('name', $name);

        // The premise: the page IS visible to them, so the page gate is not what refuses.
        $this->assertArrayHasKey(
            $action['page'],
            $this->pagesFor($staff),
            'premise: ' . $pagePermission . ' must make the ' . $action['page'] . ' page available, '
            . 'or this case is measuring the page gate instead of the permission'
        );

        $this->assertArrayNotHasKey(
            $name,
            $this->offeredTo($staff),
            $name . ' is offered to an employee who holds ' . $pagePermission . ' but not '
            . $actionPermission
        );

        $status = $this->actingAs($staff)->get($this->uriOf($action))->getStatusCode();

        $this->assertNotSame(
            200,
            $status,
            $name . ': the target served an employee holding only ' . $pagePermission
        );
    }

    /**
     * THE OWNER'S OTHER CONDITION, AS ITS OWN TEST — "لا تعرض إجراءً إذا كانت الصفحة أو الوحدة غير
     * متوفرة" — and it needed one, because removing the page gate broke nothing else in this file.
     *
     * That is not a flaw in the invariant above; it is what the two gates are for. Dropping the
     * page gate offers MORE actions, and every one of them is still served by its target (an
     * employee with `Create Product` can open `product/create`), so the safety implication holds
     * perfectly while the owner's condition is violated. A module-availability rule cannot be
     * tested through a security property, so it is tested directly.
     *
     * The premise is the whole case: the principal HOLDS the action permission and the page is NOT
     * available to them. Without both assertions this would be indistinguishable from the
     * permission cases above.
     *
     * @dataProvider pageWithoutActionPermission
     */
    public function test_the_action_permission_alone_does_not_earn_the_action_without_its_page(string $name, string $pagePermission, string $actionPermission): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);
        $this->grantPermission($staff, $actionPermission);

        $action = collect(MerchantQuickActions::declared())->firstWhere('name', $name);

        $this->assertTrue(
            $staff->fresh()->isAbleTo($actionPermission),
            'premise: they really hold ' . $actionPermission
        );
        $this->assertArrayNotHasKey(
            $action['page'],
            $this->pagesFor($staff),
            'premise: the ' . $action['page'] . ' page must NOT be available to them, or this case '
            . 'is measuring the permission gate instead of the page gate'
        );

        $this->assertArrayNotHasKey(
            $name,
            $this->offeredTo($staff),
            $name . ' is offered although the ' . $action['page'] . ' page is not available to this '
            . 'principal, which the approval forbids'
        );
    }

    /**
     * AND THE MIRROR, so the case above cannot be satisfied by refusing everybody: for the two
     * actions whose permission IS the menu permission, that one grant is enough.
     *
     * @dataProvider pageIsTheActionPermission
     */
    public function test_where_the_menu_permission_is_the_action_permission_one_grant_suffices(string $name, string $permission): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);
        $this->grantPermission($staff, $permission);

        $this->assertArrayHasKey(
            $name,
            $this->offeredTo($staff),
            $name . ' is withheld from an employee holding ' . $permission . ', which is both the '
            . 'page permission and the action permission'
        );

        $this->assertSame(
            200,
            $this->actingAs($staff)->get($this->uriOf(
                collect(MerchantQuickActions::declared())->firstWhere('name', $name)
            ))->getStatusCode(),
            $name . ': offered but the target refused'
        );
    }

    /** @return array<string, array{0:string,1:string,2:string}> */
    public static function pageWithoutActionPermission(): array
    {
        return [
            'product' => ['create-product', 'Manage Products', 'Create Product'],
            'coupon' => ['create-coupon', 'Manage Coupon', 'Create Coupon'],
        ];
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function pageIsTheActionPermission(): array
    {
        return [
            'order' => ['create-order', 'Manage Order'],
            'customer' => ['create-customer', 'Manage Customer'],
        ];
    }

    /**
     * AND THE FEATURE IS NOT VACUOUS. Without this, a service that offered nothing to anybody would
     * satisfy the invariant above perfectly.
     */
    public function test_a_merchant_holding_every_relevant_permission_is_offered_all_four(): void
    {
        [$merchant, $store] = $this->makeTenant();

        foreach (['Manage Products', 'Create Product', 'Manage Order', 'Manage Customer',
                  'Manage Coupon', 'Create Coupon'] as $permission) {
            $this->grantPermission($merchant, $permission);
        }

        $this->assertSame(
            ['create-product', 'create-order', 'create-customer', 'create-coupon'],
            array_keys($this->offeredTo($merchant)),
            'a merchant holding every relevant permission must be offered all four, in order'
        );
    }

    /**
     * THE EMPLOYEE WHO HOLDS NOTHING. Offered nothing, and every target refuses — the other end of
     * the invariant, asserted directly because it is the case that matters most.
     *
     * @dataProvider declaredActions
     */
    public function test_an_unpermitted_employee_is_neither_offered_the_action_nor_served_it(array $action): void
    {
        [$merchant, $store] = $this->makeTenant();
        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);

        $this->assertArrayNotHasKey(
            $action['name'],
            $this->offeredTo($staff),
            $action['name'] . ' is offered to an employee holding no permission at all'
        );

        $status = $this->actingAs($staff)->get($this->uriOf($action))->getStatusCode();

        $this->assertNotSame(
            200,
            $status,
            $action['name'] . ': the target served an employee the palette refuses to offer it to — '
            . 'the gate is missing at the request end'
        );
    }

    /**
     * Every (principal, action) pair the invariant is checked over. The operator is included with a
     * store explicitly selected, so the store guard is not what refuses them.
     *
     * @return array<string, array{0:string,1:array<string,mixed>}>
     */
    public static function principalsAndActions(): array
    {
        $cases = [];

        foreach (['merchant-all', 'employee-action-permission-only', 'employee-none', 'operator']
                 as $principal) {
            foreach (MerchantQuickActions::declared() as $action) {
                $cases[$principal . ' / ' . $action['name']] = [$principal, $action];
            }
        }

        return $cases;
    }

    private function principal(string $kind, $merchant, $store, array $action): User
    {
        if ($kind === 'merchant-all') {
            foreach (['Manage Products', 'Create Product', 'Manage Order', 'Manage Customer',
                      'Manage Coupon', 'Create Coupon'] as $permission) {
                $this->grantPermission($merchant, $permission);
            }

            return $merchant->fresh();
        }

        if ($kind === 'operator') {
            $operator = User::factory()->create([
                'type' => 'super admin', 'created_by' => 1, 'is_active' => 1,
                'email_verified_at' => now(), 'current_store' => $store->id,
            ]);

            $this->assertTrue(
                \App\Policies\UserPolicy::isOperator($operator),
                'premise: this fixture really is the platform operator'
            );

            return $operator;
        }

        $staff = $this->makeStaff($merchant, ['current_store' => $store->id]);

        if ($kind === 'employee-action-permission-only') {
            $this->grantPermission($staff, $action['permission']);
        }

        return $staff->fresh();
    }

    /**
     * WHY THE OPERATOR PATHS IN THIS SERVICE ARE DEFENCE-IN-DEPTH AND NOT LIVE CONTROLS — asserted
     * as a premise, because the alternative is three tests that pass for a reason nobody stated.
     *
     * The service carries a per-action `operator` declaration and a guard that refuses an operator
     * with no `current_store`. Reverting either one, or flipping a declaration to a knowingly wrong
     * value, breaks NOTHING — and the measured reason is that the operator's navigation holds
     * **none of the four pages these actions target**. `SuperAdminMenuListener` registers no
     * product, order or customer page at all; it does register a `coupon` entry, but that entry's
     * own permission is not among the four action permissions, so it is absent here too. (My first
     * expectation for this assertion was `['coupon']`, on the strength of reading that listener —
     * the measurement says `[]`, and the measurement wins.) The operator is therefore offered
     * nothing and the declarations are never consulted.
     *
     * They are kept rather than deleted because they are correct and cheap, and because the day
     * someone adds one of these pages to the operator's navigation the declaration becomes live
     * immediately. **This test is what makes that day visible**: it asserts the premise, so adding
     * such a page fails here and whoever adds it has to exercise the declaration path properly.
     *
     * It also pins the store half, which would otherwise be the F157 trap in a new place:
     * `getCurrentStore()` hands a `super admin` with no `current_store` the hardcoded literal 1,
     * and `getStoreById(1)` normally resolves — so a null-store guard passes and the operator would
     * be pointed at a store they never selected.
     */
    public function test_the_operator_is_offered_nothing_and_the_premise_says_why(): void
    {
        [$merchant, $store] = $this->makeTenant();

        // Make store 1 real, so a null-store guard cannot be what refuses below.
        DB::table('stores')->where('id', $store->id)->update(['id' => 1]);
        $this->assertNotNull(DB::table('stores')->where('id', 1)->first(), 'premise: store 1 exists');

        $operator = User::factory()->create([
            'type' => 'super admin', 'created_by' => 1, 'is_active' => 1,
            'email_verified_at' => now(), 'current_store' => null,
        ]);

        foreach (MerchantQuickActions::declared() as $action) {
            $this->grantPermission($operator, $action['permission']);
        }

        $this->actingAs($operator);
        $this->assertSame(1, getCurrentStore(), 'premise: the helper substitutes the literal 1');

        // THE PREMISE: the operator's own navigation holds none of these pages but `coupon`.
        $operatorPages = array_keys($this->pagesFor($operator));
        $actionPages = array_column(MerchantQuickActions::declared(), 'page');

        $this->assertSame(
            [],
            array_values(array_intersect($actionPages, $operatorPages)),
            'The operator\'s navigation now holds a page one of these actions targets. The '
            . '`operator` declarations and the store guard in MerchantQuickActions have been dead '
            . 'code until now and are suddenly live: exercise them with real cases before changing '
            . 'this expectation.'
        );

        $this->assertSame(
            [],
            $this->offeredTo($operator),
            'the operator was offered an action -- either into store 1, which they never selected, '
            . 'or a coupon write that F157 refuses them'
        );
    }

    /**
     * NO STORE, NO ACTIONS. `getCurrentStore()` answers null for a staff member who owns no store,
     * and every one of these four screens reads the store — so an action here would lead to a
     * refusal or a crash.
     */
    public function test_nothing_is_offered_to_a_principal_with_no_resolvable_store(): void
    {
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant, ['current_store' => null]);

        foreach (MerchantQuickActions::declared() as $action) {
            $this->grantPermission($staff, $action['permission']);
        }

        $this->assertSame(
            [],
            $this->offeredTo($staff),
            'a principal with no resolvable store was offered actions into screens that all read '
            . 'the store'
        );
    }

    /**
     * THE STORE SWITCH, which the owner named. The offer must follow `current_store`, not the
     * principal's identity: the same employee with the permission in one store must be offered the
     * action while that store is current.
     */
    public function test_the_offer_follows_the_current_store(): void
    {
        [$merchant, $storeA] = $this->makeTenant();
        $storeB = $this->makeStore($merchant);
        $staff = $this->makeStaff($merchant, ['current_store' => $storeA->id]);
        $this->grantPermission($staff, 'Manage Customer');

        $this->assertArrayHasKey('create-customer', $this->offeredTo($staff));

        $staff->current_store = $storeB->id;
        $staff->save();
        (new User)->flushCache();
        \App\Services\MerchantPageDirectory::forget();

        $this->assertArrayHasKey(
            'create-customer',
            $this->offeredTo($staff->fresh()),
            'switching store must not silently drop an action the employee still holds'
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The rows the palette would render for this principal.
     *
     * `MerchantPageDirectory::forget()` is not optional here: the directory memoises per request
     * on the request attributes, and a test makes several calls inside one. Its own docblock says
     * the production boundary is the request itself; in here the boundary has to be stated.
     */
    private function offeredTo(User $user): array
    {
        $this->actingAs($user);
        \App\Services\MerchantPageDirectory::forget();

        return MerchantQuickActions::available();
    }

    /** The navigation pages this principal really has, for the premise assertions. */
    private function pagesFor(User $user): array
    {
        $this->actingAs($user);
        \App\Services\MerchantPageDirectory::forget();

        return \App\Services\MerchantPageDirectory::available();
    }

    private function uriOf(array $action): string
    {
        return '/' . ltrim(parse_url(route($action['route']), PHP_URL_PATH) ?? '', '/');
    }
}
