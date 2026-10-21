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
}
