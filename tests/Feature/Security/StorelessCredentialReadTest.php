<?php

namespace Tests\Feature\Security;

use App\Models\Utility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * P1-SEC-18 F4 -- a read that names no store read EVERY tenant's settings, last row wins.
 * =============================================================================================
 *
 * `Utility::GetValueByName($name)` with no store id used `getCurrentStore()`, which is `auth()->user()->current_store`
 * -- the `web` guard -- and so NULL for every shopper (guest or `customers` guard). With a null store id the query had no
 * `store_id` predicate at all: `Setting::query()->pluck('value', 'name')` over all tenants, and a name that more than one
 * tenant has kept the LAST row (no ORDER BY). There are 84 one-argument calls in app/ (a single-line grep); 14 of those lines are in PaymentService
 * alone, among them the Ozow and Easebuzz merchant credentials, the currency every gateway charges in, and the currency
 * strings written into an order's coupon record.
 *
 * MEASURED before the fix, three tenants with the same Ozow site key setting: a shopper at store A and a shopper at store B
 * both read store C's key -- their payment request would be created in C's gateway account.
 * `getAdminAllSetting()` with no arguments has the same shape one level up: it falls through to the lowest-id merchant on
 * the platform (34 call sites; the WhatsApp template, P1-SEC-21, was one).
 *
 * THE FIX IS CENTRAL: when no store is authenticated, a read uses the store the REQUEST is for -- the route's `storeSlug`
 * or `slug` parameter, the same source `getStore()` already uses. An explicit store id still wins; a request with no
 * resolvable store keeps the old behaviour (jobs, webhooks and console have no storefront to name).
 *
 * The routes below are registered by the test, with the real `web` group, so the resolution is exercised through a real
 * request rather than by faking `request()->route()`.
 */
class StorelessCredentialReadTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @var array<string, \App\Models\Store> */
    private array $stores = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['A', 'B', 'C'] as $label) {
            [$merchant, $store] = $this->makeTenant();
            foreach (['ozow_pay_Site_key' => "FIXTURE-OZOW-SITE-$label", 'CURRENCY_NAME' => "CUR-$label"] as $name => $value) {
                DB::table('settings')->insert([
                    'name' => $name, 'value' => $value, 'created_by' => $merchant->id, 'store_id' => $store->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->stores[$label] = $store;
        }

        Route::middleware('web')->group(function () {
            Route::get('{storeSlug}/zz-storeless-probe', fn () => response()->json([
                'value' => Utility::GetValueByName('ozow_pay_Site_key'),
                'admin' => getAdminAllSetting()['ozow_pay_Site_key'] ?? null,
            ]));
            // The real storefront API shape: routes/api.php declares `{slug}/...` and the framework adds the `api` prefix.
            Route::get('api/{slug}/zz-storeless-probe', fn () => response()->json(['value' => Utility::GetValueByName('ozow_pay_Site_key')]));
            // A `{slug}` that is NOT the first segment names a page, a product, a marketplace entry -- never a store.
            Route::get('zz-market/{slug}/storeless-probe', fn () => response()->json(['value' => Utility::GetValueByName('ozow_pay_Site_key')]));
            Route::get('zz-no-store/storeless-probe', fn () => response()->json(['value' => Utility::GetValueByName('ozow_pay_Site_key')]));
        });
    }

    public function test_a_shopper_reads_their_own_stores_credential_not_the_last_tenants(): void
    {
        foreach (['A', 'B', 'C'] as $label) {
            $response = $this->get('/' . $this->stores[$label]->slug . '/zz-storeless-probe');

            $response->assertOk();
            $this->assertSame("FIXTURE-OZOW-SITE-$label", $response->json('value'),
                "A shopper at store {$label} read another tenant's gateway credential.");
        }
    }

    public function test_the_slug_parameter_form_is_resolved_too(): void
    {
        foreach (['A', 'B'] as $label) {
            $this->get('/api/' . $this->stores[$label]->slug . '/zz-storeless-probe')
                ->assertOk()
                ->assertJsonPath('value', "FIXTURE-OZOW-SITE-$label");
        }
    }

    public function test_getadminallsetting_with_no_arguments_serves_the_requested_store_not_the_first_merchant(): void
    {
        foreach (['A', 'B', 'C'] as $label) {
            $this->get('/' . $this->stores[$label]->slug . '/zz-storeless-probe')
                ->assertJsonPath('admin', "FIXTURE-OZOW-SITE-$label");
        }
    }

    /** An explicit store id always wins, whatever the route says. */
    public function test_an_explicit_store_id_still_wins(): void
    {
        Route::middleware('web')->get('{storeSlug}/zz-explicit-probe', fn () => response()->json([
            'value' => Utility::GetValueByName('ozow_pay_Site_key', $this->stores['B']->id),
        ]));

        $this->get('/' . $this->stores['A']->slug . '/zz-explicit-probe')->assertJsonPath('value', 'FIXTURE-OZOW-SITE-B');
    }

    /**
     * HomeController re-points `storeSlug` with route()->setParameter() AFTER the route matched (custom-domain and subdomain
     * landing). A memo that ignored the slug would keep answering for the store the request was first read as.
     */
    public function test_a_route_parameter_repointed_mid_request_is_followed(): void
    {
        Route::middleware('web')->get('{storeSlug}/zz-repoint-probe', function () {
            $first = Utility::GetValueByName('ozow_pay_Site_key');
            request()->route()->setParameter('storeSlug', $this->stores['B']->slug);

            return response()->json(['first' => $first, 'second' => Utility::GetValueByName('ozow_pay_Site_key')]);
        });

        $this->get('/' . $this->stores['A']->slug . '/zz-repoint-probe')
            ->assertJsonPath('first', 'FIXTURE-OZOW-SITE-A')
            ->assertJsonPath('second', 'FIXTURE-OZOW-SITE-B');
    }

    /** A request that names no store (webhook, job, console) keeps the previous behaviour: it is not made to fail. */
    public function test_a_request_naming_no_store_is_not_broken(): void
    {
        $response = $this->get('/zz-no-store/storeless-probe');

        $response->assertOk();
        // PINNED RESIDUAL: with no store to name, the read is still the all-tenant one (some tenant's row). That is the
        // previous behaviour, kept on purpose -- webhooks, jobs and console have no storefront to name.
        $this->assertStringStartsWith('FIXTURE-OZOW-SITE-', (string) $response->json('value'), 'A storeless, route-less read stopped answering.');
    }

    /** A slug that is not a store (e.g. a page or product slug on a `{slug}` route) must not be treated as one. */
    public function test_a_slug_that_is_not_a_store_falls_back_to_the_previous_behaviour(): void
    {
        $response = $this->get('/api/not-a-store-slug/zz-storeless-probe');

        $response->assertOk();
        // PINNED RESIDUAL: an unknown slug leaves the all-tenant read in place (see getRouteStoreId()).
        $this->assertStringStartsWith('FIXTURE-OZOW-SITE-', (string) $response->json('value'));
    }

    /**
     * `{slug}` is a store only as the FIRST segment (`{slug}/...`, `api/{slug}/...`): `marketplace/{slug}`, `landing-pages/{slug}`,
     * `add-on/details/{slug}` and `{storeSlug}/article/{slug}` use the same parameter name for something else. A page slug that
     * happens to equal a store's slug must not select that store's credentials.
     */
    public function test_a_slug_parameter_that_is_not_the_first_segment_does_not_select_a_store(): void
    {
        $response = $this->get('/zz-market/' . $this->stores['A']->slug . '/storeless-probe');

        $response->assertOk();
        $this->assertNotSame('FIXTURE-OZOW-SITE-A', $response->json('value'),
            "A non-storefront {slug} route selected a store's credentials.");
    }

    /**
     * The two gateway initiators that take the store from the BODY (`$request->slug`) read its credentials by that store's id,
     * not by whatever the route says: the route fallback above is the safety net, this is the consistent answer. Reverting
     * either to the one-argument form makes the credential come from the route store while the currency (already by id) comes
     * from the body's -- two tenants in one request.
     *
     * @return array<string, list<int>> function => the argument count of every GetValueByName() call in its body
     */
    private function gatewayReadArgumentCounts(): array
    {
        $tokens = token_get_all(file_get_contents(base_path('app/Services/PaymentService.php')));
        $counts = [];

        foreach (['processOzow', 'processEasebuzz'] as $function) {
            $start = null;
            foreach ($tokens as $i => $t) {
                if (is_array($t) && $t[0] === T_FUNCTION) {
                    $j = $i + 1;
                    while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                        $j++;
                    }
                    if (is_array($tokens[$j]) && $tokens[$j][1] === $function) {
                        $start = $j;
                        break;
                    }
                }
            }
            $this->assertNotNull($start, "{$function}() was not found in PaymentService.");

            $depth = 0;
            $entered = false;
            $counts[$function] = [];
            for ($i = $start, $n = count($tokens); $i < $n; $i++) {
                $t = $tokens[$i];
                $text = is_array($t) ? $t[1] : $t;
                if ($text === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $depth++;
                    $entered = true;
                } elseif ($text === '}') {
                    $depth--;
                    if ($entered && $depth === 0) {
                        break;
                    }
                }
                if ($entered && is_array($t) && $t[0] === T_STRING && $t[1] === 'GetValueByName') {
                    $k = $i + 1;
                    while (is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                        $k++;
                    }
                    if ($tokens[$k] !== '(') {
                        continue;
                    }
                    $paren = 0;
                    $args = 1;
                    for (; $k < $n; $k++) {
                        $c = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                        if (in_array($c, ['(', '[', '{'], true)) {
                            $paren++;
                        } elseif (in_array($c, [')', ']', '}'], true)) {
                            $paren--;
                            if ($paren === 0) {
                                break;
                            }
                        } elseif ($c === ',' && $paren === 1) {
                            $args++;
                        }
                    }
                    $counts[$function][] = $args;
                }
            }
        }

        return $counts;
    }

    public function test_the_ozow_and_easebuzz_initiators_read_their_credentials_by_the_stores_id(): void
    {
        $counts = $this->gatewayReadArgumentCounts();

        foreach ($counts as $function => $argumentCounts) {
            $this->assertNotEmpty($argumentCounts, "{$function}() reads no setting -- this guard is looking at the wrong function.");
            $this->assertSame([], array_values(array_filter($argumentCounts, fn ($n) => $n < 2)),
                "{$function}() has a GetValueByName() call that names no store.");
        }
        $this->assertGreaterThanOrEqual(5, count($counts['processOzow']));
        $this->assertGreaterThanOrEqual(4, count($counts['processEasebuzz']));
    }

    /**
     * `custom.auth` logs a Sanctum token's owner into the guard that matches its CLASS: a Customer into `customers`, a
     * DeliveryBoy into `deliveryboy`, a User into `web`. Only the `web` user is `auth()->user()`. So a customer's or a
     * driver's token reaches a storefront API route with NO web-guard store -- the F4 trap -- and must be served the store
     * the route names; a merchant's token keeps the merchant's own store. (A customer-token probe alone proves nothing: it
     * would pass for the wrong reason, so all three principals are driven.)
     */
    public function test_each_token_class_on_a_storefront_api_route_gets_the_right_store(): void
    {
        Route::middleware(['web', 'custom.auth'])->get('api/{slug}/zz-token-probe', fn () => response()->json([
            'value' => Utility::GetValueByName('ozow_pay_Site_key'),
        ]));

        $storeA = $this->stores['A'];
        $merchantB = \App\Models\User::find($this->stores['B']->created_by);
        $customer = \App\Models\Customer::forceCreate([
            'first_name' => 'Tok', 'last_name' => 'Cust', 'email' => 'tok-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('secret123'),
            'store_id' => $storeA->id, 'status' => 1, 'register_type' => 'email',
        ]);
        $driver = \App\Models\DeliveryBoy::forceCreate([
            'name' => 'Driver ' . uniqid(), 'email' => 'driver' . uniqid() . '@example.test', 'password' => bcrypt('x'),
            'contact' => '0000000', 'created_by' => $storeA->created_by, 'store_id' => $storeA->id,
        ]);

        $read = fn ($principal) => $this->get('/api/' . $storeA->slug . '/zz-token-probe',
            ['Authorization' => 'Bearer ' . $principal->createToken('t')->plainTextToken]);

        $read($customer)->assertOk()->assertJsonPath('value', 'FIXTURE-OZOW-SITE-A');
        $read($driver)->assertOk()->assertJsonPath('value', 'FIXTURE-OZOW-SITE-A');
        // The merchant of store B, on store A's route: their own store wins, exactly as before this change.
        $read($merchantB)->assertOk()->assertJsonPath('value', 'FIXTURE-OZOW-SITE-B');
    }

    public function test_an_authenticated_merchant_still_reads_their_own_store(): void
    {
        [$merchant, $store] = $this->makeTenant();
        DB::table('settings')->insert(['name' => 'ozow_pay_Site_key', 'value' => 'FIXTURE-OZOW-SITE-MINE', 'created_by' => $merchant->id, 'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now()]);

        // A storefront route of ANOTHER store while signed in: the web-guard store wins, as before.
        $response = $this->actingAs($merchant)->get('/' . $this->stores['A']->slug . '/zz-storeless-probe');

        $this->assertSame('FIXTURE-OZOW-SITE-MINE', $response->json('value'));
    }

    // =============================================================================================
    //  P1-SEC-26 -- THE SITES P1-SEC-18 F4's CENTRAL FALLBACK CANNOT REACH
    // =============================================================================================

    /**
     * THE MECHANISM, pinned. The fallback above answers for a caller with no `web` session. It does NOT answer for one WITH a web
     * session, because getAdminAllSetting()'s first branch wins: `!empty($user)` -> Setting::where('created_by',$user->id)
     * ->where('store_id', $user->current_store). The storefront routes {storeSlug}/process-order and the stock screens admit exactly
     * that principal, so the fix at those call sites is to name the store explicitly. This proves the two forms differ for that caller,
     * and that the explicit form answers with the ROUTE's store.
     */
    public function test_a_web_session_makes_the_no_argument_form_answer_with_its_own_store_and_the_named_form_with_the_routes(): void
    {
        Route::middleware('web')->get('{storeSlug}/zz-sec26-probe', function () {
            $storeId = getRouteStoreId();
            $owner = \App\Models\Store::find($storeId)?->created_by;

            return response()->json([
                'no_args' => getAdminAllSetting()['ozow_pay_Site_key'] ?? null,
                // store id ALONE is not enough: the first branch still filters created_by = the signed-in user
                'store_only' => getAdminAllSetting(null, $storeId)['ozow_pay_Site_key'] ?? null,
                // the store's OWNER plus the store id -- the pair the rows are actually written with
                'owner_and_store' => getAdminAllSetting($owner, $storeId)['ozow_pay_Site_key'] ?? null,
                // the central per-name accessor P1-SEC-25 established, which carries no created_by predicate at all
                'by_name' => Utility::GetValueByName('ozow_pay_Site_key', $storeId),
                'route_store' => $storeId,
            ]);
        });

        // a merchant of store B, signed in on the `web` guard, visiting store A's storefront
        $merchantOfB = \App\Models\User::where('current_store', $this->stores['B']->id)->firstOrFail();

        $response = $this->actingAs($merchantOfB)->get('/' . $this->stores['A']->slug . '/zz-sec26-probe');

        $response->assertOk();
        $this->assertSame($this->stores['A']->id, $response->json('route_store'), 'Premise: the route resolves store A.');
        $this->assertSame('FIXTURE-OZOW-SITE-B', $response->json('no_args'),
            'Premise of the defect: with a web session the no-argument form answers with the CALLER\'s store, not the route\'s.');
        // THE TRAP, pinned so the next reader does not repeat it: naming the store ALONE does not fix this call. With a web session
        // getAdminAllSetting()'s first branch still filters `created_by = $user->id`, so a foreign store id matches NO rows and the
        // caller silently gets nothing -- for SSLCommerz that would mean EMPTY gateway credentials rather than the right ones.
        $this->assertNull($response->json('store_only'),
            'If this stops being null the helper changed; re-read which forms the payment initiators may use.');

        // The two forms that DO answer correctly.
        $this->assertSame('FIXTURE-OZOW-SITE-A', $response->json('owner_and_store'),
            "The store's owner plus the store id is the pair the settings rows are written with.");
        $this->assertSame('FIXTURE-OZOW-SITE-A', $response->json('by_name'),
            'Utility::GetValueByName with an explicit store id carries no created_by predicate, which is why P1-SEC-25 used it for the '
            . 'Ozow and Easebuzz initiators.');
    }

    /**
     * THE THREE STOREFRONT PAYMENT INITIATORS, at source and with comments stripped.
     *
     * A functional test would have to drive a whole gateway handshake; what matters and what regresses is that these three reads name a
     * store at all. Comment lines are stripped first, because this file's own docblocks and the guards' own comments quote the exact
     * string being grepped for -- a ratchet a docblock can satisfy is not a ratchet (it has happened in this project).
     */
    public function test_the_three_storefront_payment_initiators_name_the_routes_store(): void
    {
        $code = $this->codeOnly(base_path('app/Services/PaymentService.php'));

        foreach (['processNepalste', 'processAuthorizeNet', 'processSSLCommerz'] as $method) {
            $i = strpos($code, 'function ' . $method . '(');
            $this->assertNotFalse($i, "{$method}() is gone from PaymentService -- re-read P1-SEC-26 before deleting this.");

            $next = strpos($code, ' function ', $i + 10);
            $slice = substr($code, $i, ($next === false ? strlen($code) : $next) - $i);

            // The no-argument form may appear ONLY as the ternary's no-route fallback (a job or console run, where there is no
            // storefront to name and the previous behaviour is the right answer). Counting rather than forbidding keeps the ratchet
            // meaningful: a bare primary read is still caught, and the documented fallback is still allowed.
            $this->assertSame(
                substr_count($slice, ': getAdminAllSetting();'),
                substr_count($slice, 'getAdminAllSetting()'),
                "{$method}() reads settings with NO store named outside the no-route fallback. {storeSlug}/process-order carries no "
                . '`auth` and processOrder() treats a web principal as a guest, so that form answers with the signed-in merchant\'s own '
                . 'store instead of the store being paid.'
            );
            $this->assertStringContainsString("Setting::where('store_id', \$sec26Store->id)", $slice,
                "{$method}() no longer reads the settings of the store being paid. It must be a STORE-ONLY read: `settings.created_by` is "
                . 'whoever saved last (PaymentSettingController keys updateOrCreate on name+store_id and rewrites created_by, and staff '
                . 'can save), so ANY created_by predicate here -- including the store owner\'s -- can match no rows and hand the gateway '
                . 'empty credentials. See the forms compared in '
                . 'test_a_web_session_makes_the_no_argument_form_answer_with_its_own_store_and_the_named_form_with_the_routes().');
            $this->assertStringContainsString('Store::find(getRouteStoreId())', $slice,
                "{$method}() must resolve the store from the ROUTE. Its own \$slug parameter is overwritten on the next line with the "
                . 'request body\'s `slug`, which the checkout form does not post.');
        }
    }

    /** The two stock screens must judge stock by the threshold of the store whose rows they list. */
    public function test_the_stock_screens_read_the_threshold_of_the_store_they_list(): void
    {
        foreach ([
            'app/DataTables/ProductDataTable.php' => 'dataTable',
            'app/Http/Controllers/ReportController.php' => 'getOutOfStockProducts',
        ] as $file => $method) {
            $code = $this->codeOnly(base_path($file));
            $i = strpos($code, 'function ' . $method . '(');
            $this->assertNotFalse($i, "{$method}() is gone from {$file}.");
            $slice = substr($code, $i, 2600);

            $this->assertStringContainsString('getAdminAllSetting(null, getCurrentStore())', $slice,
                "{$file}::{$method}() must read out_of_stock_threshold from the same store its own query filters on. The no-argument "
                . 'form resolves it from the caller, and for a STAFF member getAdminAllSetting() walks up to created_by and then reads '
                . "the OWNER's current_store -- a different store's threshold.");
        }
    }

    /**
     * The out-of-stock screen, driven for real: the threshold that decides the list is the LISTED store's, even when the caller's own
     * resolution would have produced another store's.
     */
    /**
     * The out-of-stock screen, driven for real. THE PRINCIPAL IS THE POINT, and the first version of this test got it wrong: it drove the
     * OWNER of the listed store, for whom the reverted form and the fixed form resolve the SAME store, so it passed either way and only
     * the source ratchet was carrying site 4. It must drive a STAFF member whose OWNER is currently in a different store, because that is
     * the principal the defect exists for -- getAdminAllSetting() walks $user up to created_by and then reads the OWNER's current_store.
     */
    public function test_the_out_of_stock_screen_uses_the_listed_stores_threshold(): void
    {
        $owner = \App\Models\User::where('current_store', $this->stores['A']->id)->firstOrFail();

        // the owner is currently in store B; the staff member is working in store A
        $owner->current_store = $this->stores['B']->id;
        $owner->save();
        $staff = $this->makeStaff($owner, ['current_store' => $this->stores['A']->id]);

        // PRECONDITION, and assertOk() below is what caught its absence: P1-SEC-09 put every report handler behind
        // App\Http\Middleware\ReportPermission, which wants 'Manage Stock Reports' for this one. Without it the staff member is
        // redirected and the negative assertion below would have passed having tested nothing.
        $this->grantPermission($staff, 'Manage Stock Reports');

        // A says 5, B says 500 -- both written by the OWNER, which is the normal shape. A product with 100 in stock is out of stock
        // only under B's threshold, so the listed row answers which store's threshold was used.
        foreach ([['A', '5'], ['B', '500']] as [$label, $value]) {
            DB::table('settings')->insert([
                'name' => 'out_of_stock_threshold', 'value' => $value, 'created_by' => $owner->id,
                'store_id' => $this->stores[$label]->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $category = (int) DB::table('categories')->insertGetId([
            'name' => 'c-' . uniqid(), 'slug' => 'c-' . uniqid(), 'store_id' => $this->stores['A']->id,
        ]);
        DB::table('products')->insert([
            'name' => 'SEC26 PLENTY IN STOCK', 'slug' => 'sec26-' . uniqid(), 'store_id' => $this->stores['A']->id,
            'created_by' => $owner->id, 'product_stock' => 100, 'track_stock' => 1, 'variant_product' => 0,
            'category_id' => $category, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($staff->fresh())->get('/stock-report/out-stock');

        // assertOk and not just "not 404": this route's group carries `verified`, and a 302 off it would satisfy the negative
        // assertion below while proving nothing.
        $response->assertOk();
        $this->assertSame((int) $this->stores['A']->id, (int) getCurrentStore($staff->id),
            "Premise: the staff member's current store is A, while their owner's is B.");
        $this->assertStringNotContainsString('SEC26 PLENTY IN STOCK', (string) $response->getContent(),
            'A product with 100 in stock was reported out of stock, so the threshold came from the OWNER\'s current store (B, 500) '
            . 'rather than the store being listed (A, 5).');
    }
    /** The DPO plan screen is a PLATFORM context -- its own sibling planPayWithDPOPay() already reads it as one. */
    public function test_the_dpo_plan_screen_reads_the_platform_settings_not_a_merchants(): void
    {
        // getSuperAdminAllSetting() reads (created_by = the super admin, store_id = THAT USER'S current_store), behind a
        // `super_admin_details` cache -- so the fixture must use that exact pair and must flush the cache, or the accessor answers from a
        // User captured before this row existed (the `admin_details` trap CLAUDE.md records).
        $operator = $this->makePlatformAdmin(['current_store' => $this->stores['C']->id]);
        DB::table('settings')->insert([
            'name' => 'CURRENCY_NAME', 'value' => 'PLATFORM-CUR',
            'created_by' => $operator->id, 'store_id' => $operator->current_store,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\Cache::forget('super_admin_details');
        $this->assertSame('PLATFORM-CUR', getSuperAdminAllSetting()['CURRENCY_NAME'] ?? null,
            'Premise: the platform fixture is what getSuperAdminAllSetting() actually reads.');

        $merchantOfB = \App\Models\User::where('current_store', $this->stores['B']->id)->firstOrFail();

        $response = $this->actingAs($merchantOfB)->get('/plan-pay-with/DPO/create?total_price=10');

        $this->assertNotSame(404, $response->status(), 'Premise: plan.dpo.view exists.');

        // ASSERT ON THE ONE ELEMENT THIS SETTING FEEDS, not on the whole page: DPO/pay.blade.php:285 renders it inside
        // <div class="Payment-amount">, and the surrounding admin layout prints the signed-in merchant's own currency from other
        // sources entirely -- a whole-page search would pass or fail for reasons that have nothing to do with this read.
        // Locate the ELEMENT, not the stylesheet: the page's own CSS declares a `.Payment-amount span{...}` rule earlier in the document,
        // and a bare strpos('Payment-amount') lands there and reads nothing.
        $content = (string) $response->getContent();
        $at = strpos($content, '<div class="Payment-amount">');
        $this->assertNotFalse($at, 'Premise: the DPO amount block rendered, so the setting was actually read.');
        $block = substr($content, $at, 400);

        $this->assertStringNotContainsString('CUR-B', $block,
            "The plan screen labelled the amount in the signed-in merchant's OWN store currency. It is a PLATFORM purchase -- its own "
            . 'sibling planPayWithDPOPay() already reads getSuperAdminAllSetting().');
        $this->assertStringContainsString('PLATFORM-CUR', $block,
            'The plan screen must label the amount in the platform currency.');
    }

    /** Source with every comment line removed, so a docblock cannot satisfy a ratchet that greps for a code string. */
    private function codeOnly(string $path): string
    {
        $out = [];

        foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
            $t = ltrim($line);

            if ($t === '' || str_starts_with($t, '*') || str_starts_with($t, '//') || str_starts_with($t, '/*') || str_starts_with($t, '#')) {
                continue;
            }

            $out[] = $line;
        }

        return implode("\n", $out);
    }
}
