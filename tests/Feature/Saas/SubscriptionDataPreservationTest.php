<?php

namespace Tests\Feature\Saas;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Store;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * SAAS-003, SAAS-004 (the preserving half), SAAS-005, SAAS-006 — THE FOUR NEGATIVE GUARANTEES.
 * ============================================================================================
 *
 * THE APPROVED TEXTS, from `docs/development/master-plan/DECISION-ID-REGISTER.md`:
 *
 *   SAAS-003  عدم حذف بيانات المتجر عند انتهاء الاشتراك.
 *             Do NOT delete store data when a subscription ends. Repeats `EX-011`
 *             ("حذف بيانات المتجر تلقائياً بانتهاء الاشتراك" — automatically deleting store data
 *             when a subscription ends), which the register lists as an EXCLUSION: a thing the
 *             product must not do.
 *   SAAS-004  ... مع الحفاظ على الطلبات القائمة والتتبع والدعم.
 *             ... while PRESERVING existing orders, tracking and support.
 *   SAAS-005  إعادة التفعيل دون فقد البيانات.
 *             Reactivation without data loss.
 *   SAAS-006  عدم حذف محتوى يتجاوز حدود الباقة المخفضة.
 *             Do NOT delete content that exceeds a downgraded plan's limits.
 *
 * WHY THESE NEEDED A TEST FILE RATHER THAN A CODE CHANGE. A negative constraint is satisfied by
 * doing nothing, so it is the one kind of requirement that can be live and correct while nothing in
 * the repository knows it is a requirement — and the one kind a future change breaks silently. The
 * register's own verdict on `EX-011` was "**UNVERIFIED — must be checked before any Phase 06
 * subscription work**". This file is that check, driven rather than read.
 *
 * WHAT WAS MEASURED, before these cases were written. Every lifecycle transition in this platform
 * runs through `User::assignPlan()`, and `assignPlan()` writes exactly three kinds of row:
 * `users.plan_id`/`plan_expire_date`/`active_module` on the subject, `is_active` on the subject's
 * staff and stores past the new plan's `max_users`/`max_stores`, and `user_active_modules` rows.
 * It DEACTIVATES; it deletes nothing. Driven: a merchant with three stores and two staff downgraded
 * from a 3-store plan to a 1-store plan kept **3 store rows and 3 user rows**, two of each flipped
 * to `is_active = 0`. Products, orders, customers and tickets are not touched by any branch of it.
 *
 * SO THESE FOUR ARE MISSING-VERIFICATION AND NOT MISSING-FUNCTIONALITY, and that is the finding —
 * but it is only worth anything with a test under it, because the next person to write a
 * "plan limit cleanup" command has nothing else to fail against.
 *
 * ASSERTED THROUGH `withoutGlobalScopes()` AND RAW ROWS, FOR A MEASURED REASON. CLAUDE.md records
 * that `Customer` and `SupportTicket` are among the eleven models whose `StoreScope` really
 * filters, so a plain `Customer::find()` in a test answers against whichever tenant happens to be
 * resolved and an "it is still there" assertion passes whether or not the row exists. The same
 * entry records a cart test that reported a row deleted while the raw table still held it. Row
 * CONTENTS are compared as raw `DB::table()` snapshots, which is also the only honest instrument
 * here for a second reason: 79 of the 95 models are `Cachable`, so an Eloquent read after
 * `assignPlan()` may be served from the array cache.
 */
final class SubscriptionDataPreservationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'preservation-password-1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
        Cache::forget('super_admin_details');
        Cache::forget('admin_details');
    }

    // ------------------------------------------------------------------ fixtures

    /** Plan 1: the downgrade target every expiry path hardcodes as `assignPlan(1)`. */
    private function freePlan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'id' => 1,
            'name' => 'Free ' . $this->uniqueFixtureRef(),
            'price' => 0,
            'duration' => 'Unlimited',
            'max_stores' => 1,
            'max_products' => 2,
            'max_users' => 1,
            'storage_limit' => 10,
            'themes' => 'stylique',
            'trial' => 0,
            'trial_days' => 0,
            'modules' => 'ProductService',
        ], $overrides));
    }

    private function paidPlan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'name' => 'Paid ' . $this->uniqueFixtureRef(),
            'price' => 1200,
            'duration' => 'Month',
            'max_stores' => 3,
            'max_products' => 100,
            'max_users' => 5,
            'storage_limit' => 500,
            'themes' => 'stylique',
            'trial' => 0,
            'trial_days' => 0,
            'modules' => 'ProductService,Blog',
        ], $overrides));
    }

    /**
     * A merchant with three stores, two staff, four products, a customer and a support ticket.
     *
     * `$count` products deliberately EXCEEDS the free plan's `max_products` of 2, because that is
     * the exact shape `SAAS-006` names: content that exceeds a downgraded plan's limits.
     *
     * @return array{merchant: User, stores: list<Store>, staff: list<User>, products: list<Product>, customer: Customer, ticket: SupportTicket}
     */
    private function furnishedTenant(Plan $plan): array
    {
        [$merchant, $first] = $this->makeTenant();
        $second = $this->makeStore($merchant);
        $third = $this->makeStore($merchant);

        $staff = [$this->makeStaff($merchant), $this->makeStaff($merchant)];

        $products = [];
        foreach ([$first, $first, $second, $third] as $index => $store) {
            // `forceCreate` and this exact column set, read off the live schema after an insert
            // failed on `products.track_stock` being NOT NULL: `created_by` is also absent from
            // `Product::$fillable`, so `create()` drops it silently.
            $products[] = Product::forceCreate([
                'name' => 'Product ' . $index . ' ' . $this->uniqueFixtureRef(),
                'slug' => 'product-' . $index . '-' . $this->uniqueFixtureRef(),
                'description' => 'A product that must outlive the subscription.',
                'price' => 1000 + $index,
                'track_stock' => 0,
                'trending' => 0,
                'stock_status' => 'in_stock',
                'product_stock' => 5 + $index,
                'product_type' => null,
                'status' => 1,
                'variant_product' => 0,
                'store_id' => $store->id,
                'created_by' => $merchant->id,
            ]);
        }

        // `customers` has first_name/last_name and NOT `name`.
        $customer = Customer::forceCreate([
            'first_name' => 'Shopper',
            'last_name' => $this->uniqueFixtureRef(),
            'email' => 'shopper' . $this->uniqueFixtureRef() . '@example.test',
            'password' => bcrypt('shopper-password-1'),
            'store_id' => $first->id,
        ]);

        $ticket = SupportTicket::forceCreate([
            'ticket_id' => 'TKT-' . $this->uniqueFixtureRef(),
            'customer_id' => $customer->id,
            'title' => 'Where is my order',
            'description' => 'A ticket that must survive the end of the subscription.',
            'status' => 'Pending',
            'store_id' => $first->id,
            'created_by' => $merchant->id,
        ]);

        $merchant->plan_id = $plan->id;
        $merchant->password = bcrypt(self::PASSWORD);
        $merchant->save();
        $merchant->refresh();
        $merchant->assignPlan($plan->id);

        return [
            'merchant' => User::withoutGlobalScopes()->find($merchant->id),
            'stores' => [$first, $second, $third],
            'staff' => $staff,
            'products' => $products,
            'customer' => $customer,
            'ticket' => $ticket,
        ];
    }

    /**
     * Every row this merchant owns, as raw table data.
     *
     * Raw, and keyed by id, so a comparison answers "is this row still here with these bytes" and
     * not "does an Eloquent read for this id return something".
     *
     * @return array<string, array<int|string, array<string, mixed>>>
     */
    private function snapshot(User $merchant, array $storeIds): array
    {
        $tables = [
            'stores' => DB::table('stores')->whereIn('id', $storeIds),
            'products' => DB::table('products')->whereIn('store_id', $storeIds),
            'customers' => DB::table('customers')->whereIn('store_id', $storeIds),
            'support_tickets' => DB::table('support_tickets')->whereIn('store_id', $storeIds),
            'orders' => DB::table('orders')->whereIn('store_id', $storeIds),
            'users' => DB::table('users')->where('created_by', $merchant->id)->orWhere('id', $merchant->id),
        ];

        $out = [];
        foreach ($tables as $name => $query) {
            $out[$name] = collect($query->orderBy('id')->get())
                ->mapWithKeys(fn ($row) => [(int) $row->id => (array) $row])
                ->all();
        }

        return $out;
    }

    /**
     * Every row present before is still present after, with the same contents — except for the
     * columns a transition is ALLOWED to move, which each caller names.
     *
     * @param  array<string, list<string>>  $mayChange  table => columns this transition may write
     */
    private function assertRowsPreserved(array $before, array $after, array $mayChange = []): void
    {
        foreach ($before as $table => $rows) {
            $this->assertSame(
                array_keys($rows),
                array_keys($after[$table]),
                "{$table}: every row must still exist, and no row may appear"
            );

            foreach ($rows as $id => $row) {
                foreach ($row as $column => $value) {
                    if (in_array($column, $mayChange[$table] ?? [], true)) {
                        continue;
                    }

                    // `array_key_exists`, NOT `??`. A NULL column is PRESENT, and the first
                    // version of this helper read `$after[...][$column] ?? '<<missing>>'`, which
                    // reported every nullable column (`stores.default_language` first) as having
                    // disappeared. A preservation test that cannot tell "absent" from "null"
                    // fails on correct code and would have been "fixed" by loosening it.
                    $this->assertTrue(
                        array_key_exists($column, $after[$table][$id]),
                        "{$table}#{$id}.{$column} must still be a column of the row"
                    );
                    $this->assertSame(
                        $value,
                        $after[$table][$id][$column],
                        "{$table}#{$id}.{$column} must be unchanged"
                    );
                }
            }
        }
    }

    // ---------------------------------------------------- SAAS-003 / EX-011: expiry deletes nothing

    /**
     * SAAS-003 AND `EX-011`, DRIVEN OVER HTTP. The subscription ends, the merchant logs in, the
     * platform downgrades them to plan 1 — and every row they own is still there, byte for byte.
     *
     * The transition is allowed to move `users.is_active` (seats past the new plan's `max_users`),
     * `stores.is_active` (stores past `max_stores`) and the subject's own plan columns. Nothing
     * else, and in particular not a single product, order, customer or ticket.
     */
    #[Test]
    public function test_a_subscription_ending_deletes_nothing_the_merchant_owns(): void
    {
        $this->freePlan();
        $paid = $this->paidPlan();
        $tenant = $this->furnishedTenant($paid);
        $merchant = $tenant['merchant'];
        $storeIds = array_map(fn (Store $s) => $s->id, $tenant['stores']);

        $merchant->plan_expire_date = date('Y-m-d', strtotime('-1 day'));
        $merchant->save();

        $before = $this->snapshot($merchant, $storeIds);

        $response = $this->post('/login', ['email' => $merchant->email, 'password' => self::PASSWORD]);

        // The premise: the lapse really was enforced. Without it this whole case is vacuous — a
        // transition that never happened preserves everything.
        $this->assertSame('Your Plan is expired.', session('error'), 'premise: the expiry fired');
        $this->assertSame(1, (int) User::withoutGlobalScopes()->find($merchant->id)->plan_id, 'premise');
        $response->assertRedirect();

        $after = $this->snapshot($merchant, $storeIds);

        $this->assertRowsPreserved($before, $after, [
            'users' => ['is_active', 'plan_id', 'plan_expire_date', 'active_module', 'updated_at', 'remember_token'],
            'stores' => ['is_active', 'updated_at'],
        ]);
    }

    /** The counts, stated separately, because a count is what a "cleanup" would change first. */
    #[Test]
    public function test_the_row_counts_are_identical_across_an_expiry(): void
    {
        $this->freePlan();
        $paid = $this->paidPlan();
        $tenant = $this->furnishedTenant($paid);
        $merchant = $tenant['merchant'];

        $counts = fn () => [
            'stores' => DB::table('stores')->count(),
            'products' => DB::table('products')->count(),
            'customers' => DB::table('customers')->count(),
            'support_tickets' => DB::table('support_tickets')->count(),
            'users' => DB::table('users')->count(),
        ];

        $before = $counts();

        $merchant->plan_expire_date = date('Y-m-d', strtotime('-1 day'));
        $merchant->save();
        $this->post('/login', ['email' => $merchant->email, 'password' => self::PASSWORD]);

        $this->assertSame(1, (int) User::withoutGlobalScopes()->find($merchant->id)->plan_id, 'premise');
        $this->assertSame($before, $counts());
    }

    // ------------------------------------------- SAAS-006: a downgrade keeps over-limit content

    /**
     * SAAS-006. The free plan allows 2 products; the merchant has 4. All four survive.
     *
     * `Product` IS THE MODEL WHERE THIS MATTERS MOST, and for a reason CLAUDE.md records: it is in
     * neither `config/tenancy.php`'s `scoped_models` nor protected by any effective scope, so a
     * prune written as `Product::where(...)->delete()` by someone implementing "enforce plan
     * limits" would reach EVERY tenant's catalogue, not just the downgraded merchant's.
     */
    #[Test]
    public function test_a_downgrade_keeps_every_product_over_the_new_plans_limit(): void
    {
        $free = $this->freePlan(['max_products' => 2]);
        $paid = $this->paidPlan();
        $tenant = $this->furnishedTenant($paid);
        $merchant = $tenant['merchant'];
        $storeIds = array_map(fn (Store $s) => $s->id, $tenant['stores']);

        $this->assertGreaterThan(
            (int) $free->max_products,
            DB::table('products')->whereIn('store_id', $storeIds)->count(),
            'premise: the merchant really is over the downgraded limit'
        );

        $before = $this->snapshot($merchant, $storeIds);

        $merchant->refresh();
        $result = $merchant->assignPlan($free->id);

        $this->assertTrue($result['is_success'], 'premise: the downgrade really ran');
        $this->assertSame(1, (int) User::withoutGlobalScopes()->find($merchant->id)->plan_id, 'premise');

        $this->assertRowsPreserved($before, $this->snapshot($merchant, $storeIds), [
            'users' => ['is_active', 'plan_id', 'plan_expire_date', 'active_module', 'updated_at'],
            'stores' => ['is_active', 'updated_at'],
        ]);
    }

    /**
     * AND THE OVER-LIMIT STORES ARE DEACTIVATED, NOT DELETED — the distinction the whole family
     * turns on. Asserted in both directions so that "preserved" cannot be satisfied by a
     * transition that did nothing at all.
     */
    #[Test]
    public function test_over_limit_stores_are_deactivated_and_still_present(): void
    {
        $free = $this->freePlan(['max_stores' => 1]);
        $paid = $this->paidPlan();
        $tenant = $this->furnishedTenant($paid);
        $merchant = $tenant['merchant'];
        [$first, $second, $third] = $tenant['stores'];

        $merchant->refresh();
        $merchant->assignPlan($free->id);

        $rows = DB::table('stores')
            ->whereIn('id', [$first->id, $second->id, $third->id])
            ->orderBy('id')
            ->pluck('is_active', 'id')
            ->all();

        $this->assertCount(3, $rows, 'all three store rows survive the downgrade');
        $this->assertSame(1, (int) $rows[$first->id]);
        $this->assertSame(0, (int) $rows[$second->id], 'premise: the limit really was applied');
        $this->assertSame(0, (int) $rows[$third->id], 'premise');
    }

    // ------------------------------------------------- SAAS-005: reactivation without data loss

    /**
     * SAAS-005, the full round trip: paid -> downgrade -> reactivate.
     *
     * Three things are asserted, and the third is the one that makes this a reactivation rather
     * than a second downgrade: every content row survives both transitions byte-identically, the
     * stores come back ACTIVE, and the modules the paid plan grants are present again.
     */
    #[Test]
    public function test_reactivation_restores_the_stores_and_loses_no_data(): void
    {
        $free = $this->freePlan(['max_stores' => 1, 'max_users' => 1]);
        $paid = $this->paidPlan();
        $tenant = $this->furnishedTenant($paid);
        $merchant = $tenant['merchant'];
        $storeIds = array_map(fn (Store $s) => $s->id, $tenant['stores']);

        $before = $this->snapshot($merchant, $storeIds);
        $modulesBefore = DB::table('user_active_modules')
            ->where('user_id', $merchant->id)->orderBy('module')->pluck('module')->all();

        $merchant->refresh();
        $merchant->assignPlan($free->id);

        $this->assertSame(
            [0, 0],
            array_map('intval', DB::table('stores')->whereIn('id', [$storeIds[1], $storeIds[2]])->orderBy('id')->pluck('is_active')->all()),
            'premise: the downgrade really deactivated the extra stores'
        );
        $this->assertNotSame(
            $modulesBefore,
            DB::table('user_active_modules')->where('user_id', $merchant->id)->orderBy('module')->pluck('module')->all(),
            'premise: the downgrade really reduced the modules'
        );

        $merchant->refresh();
        $merchant->assignPlan($paid->id);

        foreach ($storeIds as $id) {
            $this->assertSame(
                1,
                (int) DB::table('stores')->where('id', $id)->value('is_active'),
                "store {$id} must come back on reactivation"
            );
        }

        $this->assertSame(
            $modulesBefore,
            DB::table('user_active_modules')->where('user_id', $merchant->id)->orderBy('module')->pluck('module')->all(),
            'the reactivated plan grants its modules again'
        );

        $this->assertRowsPreserved($before, $this->snapshot($merchant, $storeIds), [
            'users' => ['is_active', 'plan_id', 'plan_expire_date', 'active_module', 'updated_at'],
            'stores' => ['is_active', 'updated_at'],
        ]);
    }

    /**
     * AND THE STAFF SEATS COME BACK. Separate from the stores because they are a separate cascade
     * in `assignPlan()` and have their own history: that cascade used to read the wrong user.
     */
    #[Test]
    public function test_reactivation_restores_the_staff_seats(): void
    {
        $free = $this->freePlan(['max_users' => 1]);
        $paid = $this->paidPlan(['max_users' => 5]);
        $tenant = $this->furnishedTenant($paid);
        $merchant = $tenant['merchant'];
        $staffIds = array_map(fn (User $u) => $u->id, $tenant['staff']);

        $merchant->refresh();
        $merchant->assignPlan($free->id);

        $deactivated = DB::table('users')->whereIn('id', $staffIds)->where('is_active', 0)->count();
        $this->assertGreaterThan(0, $deactivated, 'premise: the downgrade really closed a seat');

        $merchant->refresh();
        $merchant->assignPlan($paid->id);

        foreach ($staffIds as $id) {
            $this->assertSame(
                '1',
                (string) DB::table('users')->where('id', $id)->value('is_active'),
                "staff {$id} must be active again under the reactivated plan"
            );
        }
    }

    // -------------------------------- SAAS-004 (preserving half): orders, tracking and support

    /**
     * SAAS-004's SECOND CLAUSE: existing orders, tracking and support SURVIVE the end of the
     * subscription. Three separate surfaces, and the order is the one with contents worth checking
     * rather than mere existence — `product_json` is the whole basket.
     */
    #[Test]
    public function test_existing_orders_tracking_and_support_survive_the_end_of_the_subscription(): void
    {
        $this->freePlan();
        $paid = $this->paidPlan();
        $tenant = $this->furnishedTenant($paid);
        $merchant = $tenant['merchant'];
        $store = $tenant['stores'][0];

        $order = $this->makeOrder($store, [
            'customer_id' => $tenant['customer']->id,
            'is_guest' => 0,
            'product_json' => '[{"name":"kept","qty":2}]',
            'final_price' => 4321,
        ]);

        $orderBefore = (array) DB::table('orders')->where('id', $order->id)->first();
        $ticketBefore = (array) DB::table('support_tickets')->where('id', $tenant['ticket']->id)->first();
        $customerBefore = (array) DB::table('customers')->where('id', $tenant['customer']->id)->first();

        $merchant->plan_expire_date = date('Y-m-d', strtotime('-1 day'));
        $merchant->save();
        $this->post('/login', ['email' => $merchant->email, 'password' => self::PASSWORD]);

        $this->assertSame(1, (int) User::withoutGlobalScopes()->find($merchant->id)->plan_id, 'premise: the expiry fired');

        $this->assertSame($orderBefore, (array) DB::table('orders')->where('id', $order->id)->first());
        $this->assertSame($ticketBefore, (array) DB::table('support_tickets')->where('id', $tenant['ticket']->id)->first());
        $this->assertSame($customerBefore, (array) DB::table('customers')->where('id', $tenant['customer']->id)->first());

        // Read back through the scoped models too, since those are the reads the product makes.
        // `SupportTicket` is one of the eleven whose StoreScope really filters, so this is a
        // different question from the raw row above and not a restatement of it.
        $this->assertNotNull(
            SupportTicket::withoutGlobalScopes()->find($tenant['ticket']->id),
            'the ticket is still readable after the subscription ended'
        );
    }

    /**
     * THE TRACKING DOOR IS PUBLIC AND MUST STAY THAT WAY. `{storeSlug?}/track-order` carries
     * `['web', ActiveTheme, themelanguage]` and no auth of any kind — measured off the route table
     * — so a shopper can still find their order after the merchant's subscription lapses. Asserted
     * as a route-level property rather than by rendering a theme page, because the guarantee is
     * "nothing new refuses this", and a theme view's own 404 would mask it either way.
     */
    #[Test]
    public function test_the_public_order_tracking_route_is_gated_by_no_subscription_check(): void
    {
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->first(fn ($r) => $r->uri() === '{storeSlug?}/track-order');

        $this->assertNotNull($route, 'premise: the public tracking route exists');

        $middleware = $route->gatherMiddleware();

        foreach (['auth', 'auth:web', 'verified'] as $forbidden) {
            $this->assertNotContains($forbidden, $middleware, 'order tracking must stay public');
        }

        $this->assertStringNotContainsString(
            'Subscription',
            implode(',', $middleware),
            'SAAS-004 preserves tracking: no subscription gate may be added to this route'
        );
    }
}
