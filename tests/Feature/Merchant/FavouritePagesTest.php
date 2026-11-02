<?php

namespace Tests\Feature\Merchant;

use App\Models\Store;
use App\Models\User;
use App\Services\FavouritePages;
use App\Services\MerchantPageDirectory;
use App\Services\RecentPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-06-7 — favourite pages and recent pages, both permission-aware.
 * =================================================================================================
 *
 * TWO REQUIREMENTS WITH OPPOSITE LIFETIMES, which is why they do not share a store. The owner's
 * decision of 2026-10-27 settles both readings: favourites are "تفضيلات دائمة لكل موظف تُستعاد بعد
 * تسجيل الدخول ومن أجهزة مختلفة" — permanent, per employee, restored after login AND from a
 * different device — while recent pages are "قصيرة الأجل داخل جلسة العمل، وليست سجل تصفح دائماً".
 *
 * So favourites CANNOT live where P2-06-5's filter memory lives. The session dies at logout and is
 * per-browser, which is exactly right for a working context and exactly wrong for a preference
 * restored on another device. And they cannot live in `settings` either: `SettingOwnership`
 * rewrites `created_by` to `$user->creatorId()`, so two employees of one merchant collapse onto one
 * row — measured, and the same reason P2-06-5 gave for not using it. Nothing else in this schema
 * isolates employee AND store: every `user_id` + `store_id` table is domain-specific
 * (`store_notifications`, `store_devices`, `audit_logs`, `notify_users`, …) and `users` has no
 * preferences column. Hence the owner's conditional authorisation of a small migration, and hence
 * `merchant_favourite_pages`.
 *
 * WHAT IS STORED IS A MENU `name`, NOT A URL. The owner's condition is explicit: "احفظ معرّفات
 * صفحات أو Routes معروفة، لا روابط عشوائية أو Query Strings قد تحتوي بيانات عميل." A menu name
 * (`product`, `order`, `customer`) is a known identifier declared in
 * `app/Listeners/CompanyMenuListener.php`; it has no tenant data in it and cannot acquire any. A
 * stored URL could — `/order?ss_status=6&customer=jane@example.com` is a URL — so the column
 * refuses anything that is not a known name.
 *
 * AND THE PERMISSION IS RE-CHECKED ON EVERY RENDER, NOT ONLY ON WRITE. "أعد فحص الصلاحيات عند كل
 * عرض أو انتقال، ولا تعرض مفضلة لم يعد الموظف مخولاً بزيارتها." A favourite is a row that outlives
 * the grant that justified it: an employee favourites Orders, the merchant removes `Manage Order`,
 * and the row is still there. Checking only on write would leave a live link to a screen they may
 * no longer open. So availability is DERIVED from the navigation gate rather than copied from it —
 * `App\Classes\Menu::permits()` is the one decision point, and `MerchantPageDirectory` asks it
 * again on every render. A second spelling of the permission rule is the defect this avoids.
 */
class FavouritePagesTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);

        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        $this->grantPermission($this->merchant, 'Manage Products');
        $this->merchant = User::find($this->merchant->id);
    }

    /**
     * PREMISE, and without it every assertion below is vacuous. CLAUDE.md records that no WorkDo
     * module loads in a test run unless the test inserts an `add_on_managers` row, so a design
     * that reads the navigation could easily be testing an EMPTY menu and reporting that nothing
     * is permitted — which looks identical to a working permission check.
     *
     * It is safe here for a specific, checked reason: the host's own
     * `app/Listeners/CompanyMenuListener.php` declares its 69 entries with `$module = 'Base'`
     * (line 16) and `Menu::__construct` appends `'Base'` to `$this->modules` unconditionally, so
     * the module half of the gate always passes for host pages and only the Laratrust half
     * decides. This asserts both halves of that: the page is there WITH the permission and gone
     * WITHOUT it.
     */
    public function test_premise_the_navigation_gate_is_live_in_this_environment(): void
    {
        $this->actingAs($this->merchant);

        $this->assertArrayHasKey('product', MerchantPageDirectory::available(),
            'premise: the host menu did not load, so nothing below is testing a real gate');

        $stranger = $this->makeMerchant();
        $stranger->current_store = $this->store->id;
        $stranger->save();

        $this->actingAs(User::find($stranger->id));

        $this->assertArrayNotHasKey('product', MerchantPageDirectory::available(),
            'the gate permits a page to a user without the permission, so it is not gating');
    }

    /** THE ONE. A favourite is set, and it comes back. */
    public function test_a_favourite_is_stored_and_returned(): void
    {
        $this->actingAs($this->merchant);

        FavouritePages::toggle('product');

        $this->assertSame(['product'], array_keys(FavouritePages::all()));
    }

    /** And off again. */
    public function test_a_favourite_toggles_off(): void
    {
        $this->actingAs($this->merchant);

        FavouritePages::toggle('product');
        FavouritePages::toggle('product');

        $this->assertSame([], array_keys(FavouritePages::all()));
    }

    /**
     * A PAGE THE EMPLOYEE MAY NOT OPEN CANNOT BE FAVOURITED. The identifier is a known name, so
     * naming one is trivial; the gate is what stops it becoming a working link.
     */
    public function test_a_page_the_employee_may_not_open_cannot_be_favourited(): void
    {
        $this->actingAs($this->merchant);

        $this->assertSame(FavouritePages::REFUSED, FavouritePages::toggle('order'),
            'a page outside the employee\'s permissions was accepted as a favourite');

        $this->assertSame([], array_keys(FavouritePages::all()));
    }

    /**
     * THE OWNER'S CONDITION, AND THE ONE A WRITE-TIME CHECK WOULD MISS. The row outlives the
     * grant: favourite Orders, lose `Manage Order`, and the favourite must stop being shown.
     */
    public function test_a_favourite_disappears_when_the_permission_is_revoked(): void
    {
        $this->grantPermission($this->merchant, 'Manage Order');
        $m = User::find($this->merchant->id);

        $this->actingAs($m);
        $this->assertSame(FavouritePages::ADDED, FavouritePages::toggle('order'));
        $this->assertArrayHasKey('order', FavouritePages::all());

        // The grant is withdrawn. The ROW is deliberately untouched.
        $this->revokePermission($m, 'Manage Order');
        $m = User::find($m->id);

        $this->actingAs($m);

        /* The directory is cached FOR THE REQUEST, so a test that revokes a permission and asks
           again inside one test method has to cross that boundary the way a new request would.
           The authoritative proof is `test_the_palette_never_renders_a_revoked_favourite`, which
           makes real requests and so cannot be fooled by this cache. */
        MerchantPageDirectory::forget();

        $this->assertArrayNotHasKey('order', FavouritePages::all(),
            'a favourite is still offered for a screen the employee may no longer open');

        /* AND THE ROW IS STILL THERE, which is the design and not an oversight: the filter is at
           RENDER time, so restoring the permission restores the favourite. Deleting rows on a
           permission change would silently destroy a merchant's preferences every time a role was
           edited, and would need a hook on every permission write to do it at all. */
        $this->assertSame(1, DB::table('merchant_favourite_pages')->count(),
            'the row was deleted rather than filtered, so the preference is destroyed by a '
            . 'permission change instead of being hidden by it');
    }

    /**
     * Laratrust keeps its OWN cache beside the `Cachable` model cache, and this project has twice
     * measured a fixture that granted nothing while reporting success. `grantPermission()` in the
     * base class handles the ordering for a grant; there is no revoke helper, so this one flushes
     * both caches rather than guessing which `flushCache()` wins the name collision on `User`.
     */
    private function revokePermission(User $user, string $permission): void
    {
        $p = \App\Models\Permission::where('name', $permission)->firstOrFail();

        \App\Models\Role::where('name', 'granted-role-' . $user->id)
            ->firstOrFail()
            ->removePermission($p);

        Cache::flush();
        $user->flushCache();
    }

    /**
     * PER EMPLOYEE, which is the thing `settings` structurally cannot do: two employees of one
     * merchant share a `creatorId()`, so a `created_by`-keyed store collapses them onto one row.
     */
    public function test_two_employees_of_one_merchant_have_separate_favourites(): void
    {
        $colleague = $this->makeStaff($this->merchant);
        $colleague->current_store = $this->store->id;
        $colleague->save();
        $this->grantPermission($colleague, 'Manage Products');
        $colleague = User::find($colleague->id);

        $this->actingAs($this->merchant);
        FavouritePages::toggle('product');

        $this->actingAs($colleague);

        $this->assertSame([], array_keys(FavouritePages::all()),
            'one employee\'s favourite is showing for another');
    }

    /**
     * TWO STORES, BECAUSE ONE PROVES NOTHING. CLAUDE.md records this exact false reading: a
     * tenant assertion written against a single store passes whether or not the predicate is
     * there. Here the SAME employee switches `current_store`, so the only thing that can separate
     * the two lists is the `store_id` column -- a user-id predicate alone would show store A's
     * favourite inside store B.
     */
    public function test_a_favourite_does_not_cross_between_stores(): void
    {
        $second = $this->makeStore($this->merchant, ['default_language' => 'en']);

        $this->actingAs($this->merchant);
        FavouritePages::toggle('product');
        $this->assertArrayHasKey('product', FavouritePages::all(), 'premise: the favourite was not set');

        $this->merchant->current_store = $second->id;
        $this->merchant->save();
        $this->actingAs(User::find($this->merchant->id));
        MerchantPageDirectory::forget();

        /* PREMISE RE-ASSERTED AFTER THE SWITCH, so the emptiness below cannot come from the page
           having left the directory. It currently cannot -- `Role` is absent from
           `scoped_models`, so `isAbleTo` survives a store change -- but if it were ever
           allowlisted the directory would empty in store B and the real assertion would pass
           while testing nothing. */
        $this->assertArrayHasKey('product', MerchantPageDirectory::available(),
            'premise: the page left the directory when the store changed, so the assertion '
            . 'below would pass whether or not store_id is filtered');

        $this->assertSame([], array_keys(FavouritePages::all()),
            'a favourite set in one store is showing in another');
    }

    /** And the other direction: a second merchant's store cannot see the first one's row. */
    public function test_a_favourite_is_not_visible_to_another_merchant(): void
    {
        $this->actingAs($this->merchant);
        FavouritePages::toggle('product');

        $other = $this->makeMerchant();
        $otherStore = $this->makeStore($other, ['default_language' => 'en']);
        $other->current_store = $otherStore->id;
        $other->save();
        $this->grantPermission($other, 'Manage Products');

        $this->actingAs(User::find($other->id));

        $this->assertSame([], array_keys(FavouritePages::all()),
            'one merchant can see another merchant employee\'s favourites');
    }

    /**
     * "تُستعاد بعد تسجيل الدخول ومن أجهزة مختلفة" -- restored after login AND from a different
     * device. A different device is a different session and a different browser store, so the
     * assertion that stands in for it is that flushing the session changes nothing. This is the
     * test that would fail if favourites were ever moved into the session or localStorage to save
     * a migration, which is the tempting shortcut this slice exists to avoid.
     */
    public function test_a_favourite_survives_a_new_session(): void
    {
        $this->actingAs($this->merchant);
        FavouritePages::toggle('product');

        session()->flush();
        $this->actingAs(User::find($this->merchant->id));

        $this->assertArrayHasKey('product', FavouritePages::all(),
            'the favourite did not survive a new session, so it is not a durable preference');
    }

    /** A shortcut list, not a second navigation: the cap is enforced rather than documented. */
    public function test_favourites_are_capped(): void
    {
        /* Enough permissions that the cap is reachable. With only `Manage Products` the
           directory holds THREE pages, which the premise assertion below caught -- a cap test
           that cannot reach the cap would have passed while asserting nothing. */
        foreach ([
            'Manage Order', 'Manage Customer', 'Manage Coupon', 'Manage Product Category',
            'Manage Product Brand', 'Manage Product Label', 'Manage Attributes', 'Manage Blog',
            'Manage Page', 'Manage Faqs', 'Manage Newsletter', 'Manage Deliveryboy',
            'Manage Reports', 'Manage Setting', 'Manage Role',
        ] as $permission) {
            $this->grantPermission($this->merchant, $permission);
        }

        $this->actingAs(User::find($this->merchant->id));

        $available = array_keys(MerchantPageDirectory::available());
        $this->assertGreaterThan(FavouritePages::LIMIT, count($available),
            'premise: not enough permitted pages to reach the cap');

        foreach ($available as $page) {
            FavouritePages::toggle($page);
        }

        $this->assertCount(FavouritePages::LIMIT, FavouritePages::all());
    }

    /**
     * ONLY KNOWN IDENTIFIERS. A URL or a query string is refused, so nothing a merchant stores
     * here can carry a customer's data — the owner's condition, asserted rather than asserted of.
     *
     * @dataProvider notAPageIdentifier
     */
    public function test_only_a_known_page_identifier_is_accepted(string $candidate): void
    {
        $this->actingAs($this->merchant);

        $this->assertSame(FavouritePages::REFUSED, FavouritePages::toggle($candidate),
            'an arbitrary string was accepted as a page identifier: ' . $candidate);

        $this->assertSame([], array_keys(FavouritePages::all()));
    }

    public static function notAPageIdentifier(): array
    {
        return [
            'a url' => ['/product'],
            'a url with a query string' => ['/order?ss_status=6'],
            'a customer address in a query string' => ['/order?customer=jane@example.com'],
            'an absolute url' => ['https://storesyria.com/order'],
            'a route name rather than a menu name' => ['product.index'],
            'an unknown name' => ['not-a-page'],
            'empty' => [''],
        ];
    }

    /**
     * RECENT PAGES ARE SESSION-SCOPED, and the assertion is that they leave NO ROW behind. The
     * owner's reading: "قصيرة الأجل داخل جلسة العمل، وليست سجل تصفح دائماً."
     */
    public function test_recent_pages_are_not_persisted(): void
    {
        $this->actingAs($this->merchant);

        RecentPages::remember('product');

        $this->assertArrayHasKey('product', RecentPages::all());
        $this->assertSame(0, DB::table('merchant_favourite_pages')->count(),
            'a recent page was written to the favourites table, so it is a browsing history');
    }

    /** And permission-filtered on render, exactly like a favourite. */
    public function test_recent_pages_are_permission_filtered(): void
    {
        $this->grantPermission($this->merchant, 'Manage Order');
        $m = User::find($this->merchant->id);

        $this->actingAs($m);
        RecentPages::remember('order');
        $this->assertArrayHasKey('order', RecentPages::all());

        $this->revokePermission($m, 'Manage Order');
        $this->actingAs(User::find($m->id));
        MerchantPageDirectory::forget();

        $this->assertArrayNotHasKey('order', RecentPages::all(),
            'a recent page is shown for a screen the employee may no longer open');
    }

    /**
     * THE PLATFORM OPERATOR, because CLAUDE.md's standing rule makes this the likeliest way for
     * this design to be quietly broken: `isAbleTo()` alone REFUSES a `super admin`, and
     * `Menu::permits()` calls exactly that with no operator-first branch. If the operator's own
     * navigation items carried permissions, their directory would come back EMPTY and the feature
     * would silently not exist for them -- which looks identical to "the operator has no
     * favourites yet".
     *
     * It works for a different reason, and the test pins the reason rather than the symptom:
     * `getMenu()` branches on `$user->type` and fires `SuperAdminMenuEvent`, a different set of
     * items, and `MerchantPageDirectory` reproduces that branch. So this asserts the operator gets
     * a NON-EMPTY directory and that it is NOT the merchant's one -- if someone ever "simplifies"
     * the branch away, the operator's list empties and this fails.
     */
    public function test_the_operator_gets_the_operator_navigation_not_an_empty_one(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator);
        $operatorPages = MerchantPageDirectory::available();

        $this->assertNotSame([], $operatorPages,
            'the platform operator has an EMPTY page directory, so favourites do not exist for '
            . 'them -- almost certainly isAbleTo() refusing the operator, which CLAUDE.md warns of');

        /* NAMED PAGES, because "non-empty and different" was not enough and the bite proved it.
           With the branch removed the operator received `CompanyMenuEvent`'s two
           permission-less entries -- `finance` and `mobilescreensetting` -- which is non-empty
           AND differs from a permissioned merchant's list, so BOTH of the weaker assertions
           passed against broken code. `add-on-manager` is declared only by the Super Admin
           listener, so asserting it is present for the operator and absent for the merchant is a
           claim only the real branch can satisfy. */
        $this->assertArrayHasKey('add-on-manager', $operatorPages,
            'the operator did not get a Super-Admin-only page, so SuperAdminMenuEvent was not '
            . 'the event that built their directory');

        $this->actingAs($this->merchant);
        $merchantPages = MerchantPageDirectory::available();

        $this->assertArrayNotHasKey('add-on-manager', $merchantPages,
            'a merchant was offered a Super-Admin-only page');
    }

    /** And the operator can actually use it, filed under whatever store getCurrentStore() gives. */
    public function test_the_operator_can_favourite_an_operator_page(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator);

        $page = array_key_first(MerchantPageDirectory::available());
        $this->assertNotNull($page, 'premise: the operator directory is empty');

        $this->assertSame(FavouritePages::ADDED, FavouritePages::toggle($page),
            'the platform operator cannot favourite a page from their own navigation');
        $this->assertArrayHasKey($page, FavouritePages::all());
    }

    /**
     * A SHOPPER MUST NOT REACH THE NAVIGATION GATE, and this is the one defect in this slice that
     * a reviewer reasoned past and the Feature suite caught.
     *
     * `Menu::permits()` calls `$user->isAbleTo()`. `App\Models\Customer` has no such method, so
     * a Customer reaching `available()` is a FATAL, not a refusal -- and because
     * `RecordRecentMerchantPage` runs on every web request, that fatal lands on the STOREFRONT.
     * Measured: eight security tests in `OrderDocumentAccessTest`,
     * `StorefrontAccountOwnershipTest` and `StorefrontOrderRefundOwnershipTest` went from 200 to
     * 500 with `Call to undefined method App\Models\Customer::isAbleTo()`.
     *
     * The reasoning that missed it was "`auth()` with no argument is the `web` guard, and a
     * customer leaves it empty". True of `CustomAuth`, which logs a customer into `customers` --
     * and false here, because `actingAs($customer)` with no guard argument puts the Customer on
     * `web` and Laravel never type-checks the model against the provider's class.
     */
    public function test_a_shopper_principal_does_not_reach_the_navigation_gate(): void
    {
        $customer = \App\Models\Customer::forceCreate([
            'first_name' => 'Shopper',
            'last_name' => 'Test',
            'email' => 'shopper' . uniqid() . '@example.test',
            'password' => bcrypt('secret-not-a-real-password'),
            'store_id' => $this->store->id,
            'status' => 1,
        ]);

        // No guard argument -- exactly how the storefront security tests sign a shopper in.
        $this->actingAs($customer);

        $this->assertSame([], MerchantPageDirectory::available(),
            'a Customer reached the navigation gate, which calls isAbleTo() and fatals');

        $this->assertFalse(MerchantPageDirectory::permits('product'));

        /* REFUSED, NOT NO_STORE -- and the difference says which control is doing the work.
           `getCurrentStore()` reads `$user->current_store` (a Customer has no such column, so
           null), skips the operator branch, and falls back to
           `Store::where('created_by', $user->id)` -- the CUSTOMER's id against a column holding
           USER ids. Those two sequences collide trivially, so a shopper can resolve a real
           store id. Nothing is written because the DIRECTORY GATE refuses the page, which is
           the control of record here; the store resolution is not. Asserted as it measures
           rather than as I first guessed. */
        $this->assertSame(FavouritePages::REFUSED, FavouritePages::toggle('product'),
            'a shopper was refused for the wrong reason, or not refused at all');

        $this->assertSame(0, DB::table('merchant_favourite_pages')->count(),
            'a shopper principal caused a favourite row to be written');

        // And the middleware's own path, which is where the 500s actually surfaced.
        RecentPages::remember('product');
        $this->assertSame([], RecentPages::all());
    }

    // ---------------------------------------------------------------------------------------
    // The HTTP surface. The services above are only reachable through these.
    // ---------------------------------------------------------------------------------------

    /** The endpoint is not anonymous. */
    public function test_the_toggle_endpoint_refuses_a_guest(): void
    {
        $response = $this->post(route('merchant.pages.favourite'), ['page' => 'product']);

        $this->assertContains($response->getStatusCode(), [302, 401, 403],
            'the favourite endpoint answered an unauthenticated caller with '
            . $response->getStatusCode());

        $this->assertSame(0, DB::table('merchant_favourite_pages')->count());
    }

    /** And refuses a page outside the caller's own navigation, with a status that says so. */
    public function test_the_toggle_endpoint_refuses_a_page_the_caller_may_not_open(): void
    {
        $this->actingAs($this->merchant)
            ->post(route('merchant.pages.favourite'), ['page' => 'order'])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('merchant_favourite_pages')->count());
    }

    /**
     * THE PATTERN CLAUDE.md CALLS THE MOST PRODUCTIVE DEFECT IN THIS CODEBASE: a handler that
     * takes an identifier from request input instead of from the authenticated principal. The
     * request here carries a `user_id` and a `store_id` belonging to someone else, and the row
     * must still land on the caller and their own store.
     */
    public function test_the_toggle_endpoint_ignores_caller_supplied_identifiers(): void
    {
        $victim = $this->makeStaff($this->merchant);
        $victimStore = $this->makeStore($victim, ['default_language' => 'en']);

        $this->actingAs($this->merchant)
            ->post(route('merchant.pages.favourite'), [
                'page'     => 'product',
                'user_id'  => $victim->id,
                'store_id' => $victimStore->id,
            ])
            ->assertOk();

        $row = DB::table('merchant_favourite_pages')->first();

        $this->assertNotNull($row, 'premise: nothing was stored');
        $this->assertSame((int) $this->merchant->id, (int) $row->user_id,
            'the favourite was stored against a user id taken from request input');
        $this->assertSame((int) $this->store->id, (int) $row->store_id,
            'the favourite was stored against a store id taken from request input');
    }

    /**
     * A FULL LIST IS REPORTED AS FULL, NOT AS A REMOVAL. `toggle()` returned a bare `false` for
     * "removed", "the list is full" and "no store resolved" alike, and the controller mapped
     * `false` to "Removed from your favourites." -- so the thirteenth favourite was silently
     * discarded while the merchant was told something had been removed.
     */
    public function test_a_full_list_is_reported_as_full_and_not_as_a_removal(): void
    {
        foreach ([
            'Manage Order', 'Manage Customer', 'Manage Coupon', 'Manage Product Category',
            'Manage Product Brand', 'Manage Product Label', 'Manage Attributes', 'Manage Blog',
            'Manage Page', 'Manage Faqs', 'Manage Newsletter', 'Manage Deliveryboy',
            'Manage Reports', 'Manage Setting', 'Manage Role',
        ] as $permission) {
            $this->grantPermission($this->merchant, $permission);
        }

        $m = User::find($this->merchant->id);
        $this->actingAs($m);

        $pages = array_keys(MerchantPageDirectory::available());
        $this->assertGreaterThan(FavouritePages::LIMIT, count($pages), 'premise: cap unreachable');

        $filled = array_slice($pages, 0, FavouritePages::LIMIT);
        foreach ($filled as $page) {
            $this->assertSame(FavouritePages::ADDED, FavouritePages::toggle($page));
        }

        $overflow = $pages[FavouritePages::LIMIT];

        $this->assertSame(FavouritePages::FULL, FavouritePages::toggle($overflow),
            'a thirteenth favourite was not reported as a full list');

        $response = $this->actingAs($m)
            ->post(route('merchant.pages.favourite'), ['page' => $overflow]);

        $response->assertStatus(422);
        $this->assertSame(0, $response->json('status'));
        $this->assertStringNotContainsString('Removed', (string) $response->json('message'),
            'a refused favourite was announced to the merchant as a removal');
        $this->assertSame(FavouritePages::LIMIT,
            DB::table('merchant_favourite_pages')->count());
    }

    /**
     * AND A STAFF MEMBER WITH NO STORE IS TOLD SO, rather than told their favourite was removed
     * on every click while nothing is ever stored. `config/tenancy.php` documents that state as
     * real: `getCurrentStore()`'s fallback looks for a store the user CREATED, which a staff
     * member never has.
     */
    public function test_a_staff_member_with_no_resolvable_store_is_told_rather_than_misinformed(): void
    {
        $staff = $this->makeStaff($this->merchant, ['current_store' => null]);
        $this->grantPermission($staff, 'Manage Products');
        $staff = User::find($staff->id);

        $this->assertSame(FavouritePages::NO_STORE, FavouritePages::toggle('product'),
            'premise: a store resolved for a staff member who has none');

        $this->actingAs($staff)
            ->post(route('merchant.pages.favourite'), ['page' => 'product'])
            ->assertStatus(409);

        $this->assertSame(0, DB::table('merchant_favourite_pages')->count());
    }

    /**
     * THE CURRENT PAGE IS OFFERED ONCE, NOT TWICE. The middleware records on the way out, so from
     * the second visit onward the page being viewed is already in the recent list -- and the
     * "add this page" control is rendered as well. That gave one page two live star controls, and
     * because the script disables only the button that was clicked, it was also the one way to
     * post the same page twice at once and hit the unique index.
     */
    public function test_the_current_page_is_not_offered_twice(): void
    {
        $this->actingAs($this->merchant)->get('/product')->assertOk();
        $html = (string) $this->actingAs($this->merchant)->get('/product')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-ss-favourite="product"'),
            'the page being viewed is offered more than once, so one page has two star controls');
    }

    /**
     * THE CREATE IS IDEMPOTENT, ASSERTED ON THE SOURCE -- and this test is deliberately honest
     * about what it does and does not prove.
     *
     * The defect is a race: two in-flight posts for the same page both see no row, and the second
     * `create()` violates `merchant_favourite_pages_unique` -- a 500, not a duplicate. A
     * single-threaded test cannot open that window, and my first attempt at this test called
     * `firstOrCreate()` DIRECTLY, so it asserted that Eloquent works and passed with `toggle()`
     * reverted to `create()`. The bite caught it.
     *
     * So this asserts the structure instead, which is what actually prevents the race, and says
     * so rather than dressing it up as a behavioural proof. Comments are stripped first: the
     * docblock on the implementation explains the fix using the very word being searched for, and
     * a source assertion that matches its own prose is a test that cannot fail -- measured on
     * `ColumnReorderContractTest` earlier in this phase.
     */
    public function test_the_favourite_create_is_idempotent(): void
    {
        $source = (string) file_get_contents(base_path('app/Services/FavouritePages.php'));

        $code = (string) preg_replace('~/\*.*?\*/~s', '', $source);
        $code = (string) preg_replace('~^[ \t]*//.*$~m', '', $code);

        $this->assertStringContainsString('MerchantFavouritePage::firstOrCreate(', $code,
            'the favourite create is not idempotent, so two concurrent posts for one page raise '
            . 'a unique-constraint violation (a 500) instead of settling on one row');

        $this->assertStringNotContainsString('MerchantFavouritePage::create(', $code,
            'a plain create() remains in the service, which is the racing form');
    }

    /**
     * THE NAVIGATION'S OWN EXCLUSION IS HONOURED. `Menu::add()` permits `mobilescreensetting`,
     * but `menuTree()` drops it unless `platform.is_mobile` is 'yes'. Without mirroring that, the
     * directory offers a page that is absent from the sidebar it claims to derive from -- and it
     * was one of exactly two entries a merchant with no permissions received, so it would have
     * been the first favourite many merchants could set.
     */
    public function test_a_page_the_navigation_hides_is_not_offered(): void
    {
        $this->assertNotSame('yes', config('platform.is_mobile'),
            'premise: this environment enables mobile screens, so the exclusion does not apply');

        $this->actingAs($this->merchant);

        $this->assertArrayNotHasKey('mobilescreensetting', MerchantPageDirectory::available(),
            'the directory offers a page the navigation hides');

        $this->assertSame(FavouritePages::REFUSED, FavouritePages::toggle('mobilescreensetting'),
            'a page the navigation hides can still be favourited');
    }

    /** The recent list belongs to the employee, not to the browser session. */
    public function test_the_recent_list_is_keyed_to_the_employee(): void
    {
        $this->actingAs($this->merchant);
        RecentPages::remember('product');

        $this->assertNotNull(session()->get('ss_recent_pages.' . $this->merchant->id),
            'the recent list is not stored under the employee id');

        $colleague = $this->makeStaff($this->merchant);
        $this->grantPermission($colleague, 'Manage Products');

        $this->assertSame([], (array) session()->get('ss_recent_pages.' . $colleague->id),
            'a second employee shares the first one\'s recent-pages slot in the same session');
    }

    /**
     * THE MIDDLEWARE RECORDS A NAME, NOT THE URL IT SAW. The query string here is the owner's own
     * example of what must never be stored, and the assertion is that the session holds the
     * navigation name and nothing resembling the address.
     */
    public function test_a_visit_is_recorded_as_a_name_and_never_as_a_url(): void
    {
        $this->actingAs($this->merchant)
            ->get('/product?ss_stock=out&customer=jane%40example.com')
            ->assertOk();

        $this->assertSame(['product'], array_keys(RecentPages::all()),
            'the visit was not recorded as a navigation name');

        /* SCOPED TO WHAT THIS FEATURE STORES, and the first version of this assertion was not
           -- it searched the WHOLE session and failed, which is how I learned that Laravel keeps
           `_previous.url` there: the full address of the last request, query string and all. That
           is the framework's redirect-back mechanism, present on every Laravel application and
           untouched by this change, so it is out of this slice's scope; it is recorded here
           because it is the sort of thing a later reader of this test would otherwise mistake for
           a regression in the feature.

           It also exposed a NEAR-MISS in my own assertion: `_previous.url` holds the address
           percent-encoded, so `jane@example.com` appears there as `jane%40example.com` and the
           customer-address check passed for that reason rather than because nothing leaked. An
           address without an `@` would have failed it. Asserting against the feature's own key
           removes both problems. */
        $recent = json_encode(session()->get('ss_recent_pages.' . $this->merchant->id));

        $this->assertSame('["product"]', (string) $recent,
            'the recent-pages store holds something other than the bare navigation name');

        $this->assertStringNotContainsString('jane', (string) $recent,
            'a customer address from the query string reached the recent-pages store');
        $this->assertStringNotContainsString('ss_stock', (string) $recent,
            'the query string was recorded, so a stored shortcut can carry request data');
        $this->assertStringNotContainsString('/', (string) $recent,
            'the recent-pages store holds a path rather than a navigation name');
    }

    /**
     * THE WRITE GATE, ISOLATED -- and the first version of this test could not fail, for TWO
     * independent reasons that review found. It drove `GET /order` and asserted `all()` was
     * empty: but `all()` filters on READ, so deleting the write-time gate left it green; and
     * `/order` is refused for this merchant, so the middleware's `isSuccessful()` check returned
     * early and nothing would have been recorded whatever the gate did. Two controls, neither
     * exercised, one green test.
     *
     * Driving `remember()` directly and asserting on the SESSION is the only way to see the write
     * gate on its own, because every unpermitted page also returns a non-2xx response.
     */
    public function test_an_unpermitted_page_is_never_written_to_the_session(): void
    {
        $this->actingAs($this->merchant);

        RecentPages::remember('order');

        $this->assertSame([], (array) session()->get('ss_recent_pages.' . $this->merchant->id),
            'a page the employee may not open was written to the recent-pages session store');
    }

    /** And the read side, which is a separate control and needs its own test. */
    public function test_a_refused_visit_is_not_offered(): void
    {
        $this->actingAs($this->merchant)->get('/order');

        $this->assertSame([], array_keys(RecentPages::all()),
            'a page the employee may not open was offered as a recent page');
    }

    /**
     * THE RENDER, NOT THE SERVICE. Everything above tests the decision; this tests that the
     * decision reaches the page -- a favourite appears in the palette's markup.
     */
    public function test_the_palette_renders_a_favourite(): void
    {
        $this->actingAs($this->merchant);
        FavouritePages::toggle('product');

        $html = $this->actingAs($this->merchant)->get('/product')->assertOk()->getContent();

        $this->assertStringContainsString('data-ss-command-shortcuts', (string) $html,
            'the shortcuts block did not render at all');
        $this->assertStringContainsString('data-ss-favourite="product"', (string) $html,
            'the favourite did not reach the palette markup');
    }

    /**
     * AND THE OWNER'S CONDITION AT THE DOM LEVEL: "لا تعرض مفضلة لم يعد الموظف مخولاً بزيارتها."
     * A revoked favourite must NEVER REACH THE MARKUP -- not be rendered and hidden, which is
     * what a client-side filter would do and what "do not show" does not mean.
     */
    public function test_the_palette_never_renders_a_revoked_favourite(): void
    {
        $this->grantPermission($this->merchant, 'Manage Order');
        $m = User::find($this->merchant->id);

        $this->actingAs($m);
        $this->assertSame(FavouritePages::ADDED, FavouritePages::toggle('order'),
            'premise: the favourite was not set');

        $html = $this->actingAs($m)->get('/product')->assertOk()->getContent();
        $this->assertStringContainsString('data-ss-favourite="order"', (string) $html,
            'premise: the favourite was not rendered while the permission was held');

        $this->revokePermission($m, 'Manage Order');
        $m = User::find($m->id);

        $html = $this->actingAs($m)->get('/product')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-ss-favourite="order"', (string) $html,
            'a favourite for a screen the employee may no longer open is still in the markup');
    }

    /** Most recent first, and capped so it stays a shortcut rather than a log. */
    public function test_recent_pages_are_most_recent_first_and_capped(): void
    {
        $this->actingAs($this->merchant);

        RecentPages::remember('product');
        RecentPages::remember('category');
        RecentPages::remember('product');

        $this->assertSame('product', array_key_first(RecentPages::all()),
            'the most recently visited page is not first');
    }

    /**
     * THE CAP, WHICH THE TEST ABOVE NAMES AND NEVER ASSERTED. `RecentPages::LIMIT` is applied in
     * `remember()`; nothing exercised it, so half that test's name was decoration.
     */
    public function test_recent_pages_stop_at_the_limit(): void
    {
        foreach ([
            'Manage Order', 'Manage Customer', 'Manage Coupon', 'Manage Product Category',
            'Manage Product Brand', 'Manage Product Label', 'Manage Blog', 'Manage Page',
        ] as $permission) {
            $this->grantPermission($this->merchant, $permission);
        }

        $this->actingAs(User::find($this->merchant->id));

        $pages = array_keys(MerchantPageDirectory::available());
        $this->assertGreaterThan(RecentPages::LIMIT, count($pages),
            'premise: not enough permitted pages to exceed the recent-pages limit');

        foreach ($pages as $page) {
            RecentPages::remember($page);
        }

        $this->assertCount(RecentPages::LIMIT, RecentPages::all());
    }

    /**
     * THE KNOWN-IDENTIFIER INVARIANT, MADE PERMANENT RATHER THAN TRUE-TODAY. Everything in this
     * file rests on navigation `name`s being a fixed vocabulary of literals -- that is what makes
     * "a stored page identifier cannot carry customer data" a structural claim instead of a
     * hopeful one. If a listener ever built a name from a variable, the vocabulary would become
     * caller-influenced and the owner's condition would quietly stop holding.
     */
    public function test_every_navigation_name_is_a_literal(): void
    {
        $listeners = glob(base_path('app/Listeners/*MenuListener.php')) ?: [];

        $this->assertNotEmpty($listeners, 'premise: no menu listener was found to scan');

        foreach ($listeners as $file) {
            $source = (string) file_get_contents($file);

            /* `'name' =>` NOT followed by an opening quote. The lookahead has to sit INSIDE the
               optional whitespace -- `\s*=>\s*(?![\'"])` matched every literal, because `\s*`
               backtracks to zero and the lookahead then tests the space rather than the value.
               Verified against the real listeners both ways before being trusted. */
            $this->assertDoesNotMatchRegularExpression(
                '/[\'"]name[\'"]\s*=>(?!\s*[\'"])/',
                $source,
                basename($file) . ' builds a navigation name from something other than a literal, '
                . 'so the stored page vocabulary is no longer a fixed set'
            );
        }
    }
}
