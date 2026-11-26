<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * GAP-142 / P4-F112 — `POST api/admin/searchdata` DUMPS WHOLE ORDER ROWS WITH NO PERMISSION CHECK.
 * =================================================================================================
 *
 * `SearchData()` takes a `type` and a `name` and, for `type == 'order'`, answers
 * `Order::where('store_id', ...)->where('product_order_id', 'like', '%name%')->get()->toArray()` —
 * every column of every matching order. `final_price`, `payment_status`, `payment_type`,
 * `customer_id`, `deliveryboy_id`, the whole `product_json`.
 *
 * **IT IS THE SAME MONEY THE ORDER LIST GATES ON, ONE ROUTE OVER.** `OrderList` resolves the same
 * store the same way and then calls `refuseWithoutPermission(['Manage Order', 'Show Order'], ...)`
 * with the comment *"`final_price` is set on every row of the paginator below"*. This route had
 * nothing, so a staff member refused by the list could read the same figures here by searching for
 * a substring of an order reference — and `'like' '%%'` with an empty `name` matches EVERY order in
 * the store, which `test_an_empty_search_term_dumps_the_whole_store` drives on purpose.
 *
 * -------------------------------------------------------------------------------------------------
 * THE NAMES ARE THE ORDER LIST'S OWN, BECAUSE IT IS THE SAME DATA.
 *
 * `['Manage Order', 'Show Order']` — identical to `OrderList` (`:1639`), to `Top_orders` and to
 * `ViewOrder`'s GAP-142 gate. Taking a narrower set here would mean a staff member could list
 * orders and not search them, which is a product change nobody asked for; taking a wider one would
 * admit somebody the list already refuses.
 *
 * -------------------------------------------------------------------------------------------------
 * THE GATE IS ON THE `order` BRANCH ONLY, AND THAT IS THE WHOLE POINT OF ITS PLACEMENT.
 *
 * This handler has a second branch, `type == 'category'`, which answers category rows. Gating the
 * whole handler on the ORDER permissions would refuse a category search to a staff member who holds
 * category rights and no order rights — a NEW refusal of a working capability, and an
 * over-admission in reverse. So the gate sits inside the `order` branch, and the category branch is
 * left exactly as it is: it is not one of the rows GAP-142 assigned here, and
 * `test_the_category_branch_is_untouched` pins that it still answers for a principal the order
 * branch refuses, so a later reader cannot mistake the omission for an oversight.
 *
 * -------------------------------------------------------------------------------------------------
 * THE REFUSAL IS AN EXPLICIT 403. Every refusal in this handler is `$this->error()`, HTTP **200**
 * with `{status: 0}` — including its "not found" branch, which is what an ungated caller sees when
 * a search misses. A permission refusal in that shape would be indistinguishable from an empty
 * result, so it uses the 403 helper. 401 is never used: the shipped Flutter app force-clears its
 * session on one.
 */
class AdminApiSearchDataPermissionTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The order's money, distinctive enough that a disclosure assertion cannot pass by accident. */
    private const FINAL_PRICE = 4242.42;

    /** @return array{0:User,1:Store,2:Order,3:string} merchant, store, order, its reference */
    private function tenantWithAnOrder(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $order = $this->makeOrder($store, [
            'final_price' => self::FINAL_PRICE,
            'payment_status' => 'Paid',
            'payment_type' => 'bank_transfer',
        ]);

        Category::create([
            'name' => 'GAP142-SEARCHABLE-CATEGORY',
            'slug' => 'gap142-searchable-category',
            'store_id' => $store->id,
        ]);

        return [User::find($merchant->id), $store, $order, (string) $order->product_order_id];
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

    private function search(User $user, Store $store, string $type, string $name)
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $user->createToken('auth_token')->plainTextToken
        )->postJson('/api/admin/searchdata', [
            'store_id' => $store->id,
            'type' => $type,
            'name' => $name,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════════════ THE PREMISE

    /**
     * THE ROUTE REALLY DOES SERVE THE MONEY, or every "the money is absent" assertion below is a
     * statement about a route that never served it.
     */
    public function test_premise_the_order_branch_serves_final_price_and_payment_status(): void
    {
        [$merchant, $store, , $reference] = $this->tenantWithAnOrder();

        $response = $this->search($merchant, $store, 'order', $reference);

        $this->assertSame(200, $response->status());
        $this->assertSame(1, $response->json('status'),
            'the owner\'s own search was refused, so this fixture proves nothing: '
            . substr((string) $response->getContent(), 0, 300));

        $body = (string) $response->getContent();
        $this->assertStringContainsString('final_price', $body,
            'the order branch does not serve final_price, so the disclosure assertions below are '
            . 'vacuous -- check whether the handler has been given a column list');
        $this->assertStringContainsString('payment_status', $body);
        $this->assertStringContainsString((string) self::FINAL_PRICE, $body,
            'the order branch does not serve this order\'s actual figure');
    }

    // ════════════════════════════════════════════════════════════════════════════════ THE DEFECT

    public function test_a_staff_member_without_an_order_permission_cannot_search_orders(): void
    {
        [$merchant, $store, , $reference] = $this->tenantWithAnOrder();
        $staff = $this->staffIn($merchant, $store);

        $response = $this->search($staff, $store, 'order', $reference);

        $this->assertSame(403, $response->status(),
            'a staff member holding no order permission read whole order rows through searchdata -- '
            . 'the same money the order LIST refuses them one route over. Body: '
            . substr((string) $response->getContent(), 0, 300));

        /* AND THE MONEY IS ABSENT FROM THE BODY, asserted separately: a refusal that still
           serialised the rows would be a 403 with the disclosure intact, and a status-only
           assertion could not tell the two apart. */
        $response->assertDontSee((string) self::FINAL_PRICE, false);
        $response->assertDontSee('payment_status', false);
    }

    /**
     * `'like' '%%'` MATCHES EVERY ORDER IN THE STORE. `name` is not required by the validator — only
     * `type` is — so an ungated caller did not even need to know a reference.
     */
    public function test_an_empty_search_term_dumps_the_whole_store(): void
    {
        [$merchant, $store] = $this->tenantWithAnOrder();
        $this->makeOrder($store, ['final_price' => 777.77]);
        $staff = $this->staffIn($merchant, $store);

        $response = $this->search($staff, $store, 'order', '');

        $this->assertSame(403, $response->status(),
            'an empty search term is `like \'%%\'`, which matches every order in the store, and an '
            . 'unpermitted staff member got all of them');
        $response->assertDontSee('777.77', false);
        $response->assertDontSee((string) self::FINAL_PRICE, false);
    }

    /** 403, NEVER 401 — and never the HTTP-200 `{status: 0}` envelope a missed search returns. */
    public function test_the_refusal_is_403_and_distinguishable_from_an_empty_result(): void
    {
        [$merchant, $store, , $reference] = $this->tenantWithAnOrder();
        $staff = $this->staffIn($merchant, $store);

        $response = $this->search($staff, $store, 'order', $reference);

        $this->assertNotSame(401, $response->status(),
            'a 401 here logs the merchant out of the whole app');
        $this->assertSame(403, $response->status(),
            'the refusal is not an explicit 403. Every other refusal in this handler -- including '
            . 'its "not found" branch -- is ApiResponser::error() at HTTP 200 with {status: 0}, so a '
            . 'refusal in that shape is indistinguishable from a search that simply matched nothing');
    }

    // ═══════════════════════════════════════════════════════════════════════════════ THE CONTROLS

    /** The store's own owner still searches their own orders, holding no order permission row. */
    public function test_the_store_owner_still_searches_their_own_orders(): void
    {
        [$merchant, $store, , $reference] = $this->tenantWithAnOrder();

        $response = $this->search($merchant, $store, 'order', $reference);

        $this->assertSame(200, $response->status(),
            'the gate refused the store\'s OWN owner, which is the exact regression this family '
            . 'has already produced once');
        $this->assertSame(1, $response->json('status'));
        $response->assertSee((string) self::FINAL_PRICE, false);
    }

    /** Both of the order list's names admit, because it is the order list's data. */
    public function test_staff_holding_either_order_name_still_search(): void
    {
        [$merchant, $store, , $reference] = $this->tenantWithAnOrder();

        foreach (['Show Order', 'Manage Order'] as $name) {
            $staff = $this->staffIn($merchant, $store, $name);
            $response = $this->search($staff, $store, 'order', $reference);

            $this->assertSame(200, $response->status(),
                '`' . $name . '` was refused on searchdata, although OrderList admits it. A staff '
                . 'member who can list orders must be able to search them.');
            $this->assertSame(1, $response->json('status'));
            $response->assertSee((string) self::FINAL_PRICE, false);
        }
    }

    /** The platform operator, whom `isAbleTo()` alone refuses, still searches. */
    public function test_the_platform_operator_still_searches(): void
    {
        [, $store, , $reference] = $this->tenantWithAnOrder();

        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);

        $response = $this->search($operator, $store, 'order', $reference);

        $this->assertSame(200, $response->status(),
            'the platform operator was refused. `isAbleTo()` has no super-admin bypass, which is '
            . 'why the gate goes through MerchantPermissions -- it answers WILDCARD for them.');
        $this->assertSame(1, $response->json('status'));
    }

    /**
     * THE CATEGORY BRANCH IS UNTOUCHED, AND THIS IS A DELIBERATE SCOPE LINE, NOT AN OVERSIGHT.
     *
     * The gate is inside the `order` branch. Gating the whole handler on the ORDER permissions
     * would refuse a category search to a staff member holding category rights and no order rights
     * — a new refusal of a working capability. Gating the category branch on category permissions
     * is a separate decision this row was not given, so it is left as it is and pinned here: the
     * SAME principal who is refused on `type=order` still gets an answer on `type=category`.
     *
     * If the category branch is gated later, this test is what changes — which is the point of
     * writing it down rather than leaving the asymmetry implicit.
     */
    public function test_the_category_branch_is_untouched(): void
    {
        [$merchant, $store, , $reference] = $this->tenantWithAnOrder();
        $staff = $this->staffIn($merchant, $store);

        $this->assertSame(403, $this->search($staff, $store, 'order', $reference)->status(),
            'premise: this principal is supposed to be refused on the order branch');

        $categorySearch = $this->search($staff, $store, 'category', 'GAP142-SEARCHABLE');

        $this->assertSame(200, $categorySearch->status(),
            'the gate reached the CATEGORY branch, which it must not: that would refuse a staff '
            . 'member who holds category rights and no order rights, which is a new refusal of a '
            . 'working capability rather than the hole GAP-142 asked to close');
        $this->assertSame(1, $categorySearch->json('status'));
        $categorySearch->assertSee('GAP142-SEARCHABLE-CATEGORY', false);
    }

    // ══════════════════════════════════════════════════════════════════════ TENANCY IS UNCHANGED

    /**
     * A member of another tenant holding `Manage Order` in their OWN store is still refused, by
     * `resolveOwnedStore()` and long before any permission question.
     *
     * ASSERTED ON `status` IN THE ENVELOPE, NOT ON A NON-200 HTTP STATUS: this handler's tenancy
     * refusal is `$this->error(['message' => 'Store not found!'])`, which is HTTP **200** with
     * `{status: 0}`, so `assertNotSame(200, ...)` would fail against a working control.
     */
    public function test_another_tenants_member_is_still_refused_and_sees_no_money(): void
    {
        [, $store, , $reference] = $this->tenantWithAnOrder();

        $outsiderMerchant = $this->makeMerchant();
        $outsiderStore = $this->makeStore($outsiderMerchant);
        $outsiderMerchant->current_store = $outsiderStore->id;
        $outsiderMerchant->save();
        $outsider = $this->staffIn(User::find($outsiderMerchant->id), $outsiderStore, 'Manage Order');

        $response = $this->search($outsider, $store, 'order', $reference);

        $this->assertSame(0, $response->json('status'),
            'a member of another tenant was answered with a success envelope for this store\'s '
            . 'orders while holding `Manage Order` in their OWN store');
        $response->assertDontSee((string) self::FINAL_PRICE, false);
    }
}
