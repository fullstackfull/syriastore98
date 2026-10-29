<?php

namespace Tests\Feature\Security;

use App\Models\DeliveryBoy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * TOKEN SECURITY, STAGE 2 — EXPLICIT ABILITIES AT EVERY MINT SITE.
 * =============================================================================================
 *
 * Owner decision, Decision Register row 13: *"APPROVED — staged: observability → **explicit
 * abilities** → 30-day expiry for NEW merchant tokens only"*. The proposal's §Stage 2 gives the
 * reason and one hard instruction:
 *
 *     "Every token carries `['*']`, so a token read out of a log (see S4) is a universal
 *      credential. … **Do not add `CheckAbilities` to routes in the same release.** Mint first,
 *      enforce later, so the two can be rolled back independently and tokens minted before the
 *      change are not stranded."
 *
 * SO THIS STAGE CHANGES NO AUTHORIZATION BEHAVIOUR, DELIBERATELY, and that is worth being blunt
 * about rather than letting the word "abilities" imply otherwise. `CustomAuth` logs a token's owner
 * into whichever guard matches the tokenable class and **never consults abilities**; nothing in the
 * codebase calls `tokenCan()`; `CheckAbilities` is imported in `routes/api.php` and never used. A
 * merchant token after this change can do exactly what it could before. What changes is that the
 * tokens minted from now on carry a scope the enforcement stage can act on — and the enforcement
 * stage is a separate release by instruction, so one of the cases here asserts that it has NOT
 * happened yet.
 *
 * THE VOCABULARY IS THREE NAMES, one per principal, because that is the separation the code already
 * makes: `CustomAuth` routes by tokenable class, and the three classes map one-to-one onto the
 * three route families (the storefront `api/{slug}/…`, the merchant `api/admin/…`, and the driver
 * endpoints). Finer-grained abilities would invent a permission vocabulary nothing enforces, and
 * the enforcement stage would then have to match an invention rather than a fact.
 */
class TokenAbilitiesTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The closed set. A fourth name appearing is a decision, not a detail. */
    private const VOCABULARY = ['storefront', 'merchant', 'delivery'];

    private const MINT_SITES = [
        'app/Http/Controllers/Api/AuthController.php' => 3,
        'app/Http/Controllers/Api/DashboardController.php' => 2,
    ];

    public function test_a_merchant_login_mints_a_merchant_scoped_token(): void
    {
        $merchant = $this->makeMerchant(['password' => bcrypt('password')]);
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();

        $response = $this->postJson('/api/admin/adminlogin', [
            'email' => $merchant->email,
            'password' => 'password',
        ]);

        $this->assertRefusalFree($response);

        $this->assertSame(
            '["merchant"]',
            $this->newestAbilities(),
            'the merchant login still mints a universal credential, so a token read out of a log'
            . ' can do everything a merchant can on every surface'
        );
    }

    public function test_a_driver_login_mints_a_delivery_scoped_token(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);

        DeliveryBoy::forceCreate([
            'name' => 'Driver',
            'email' => 'driver-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ]);

        $email = DB::table('delivery_boys')->orderByDesc('id')->value('email');

        $response = $this->postJson('/api/admin/delivery-login', [
            'email' => $email,
            'password' => 'password',
        ]);

        $this->assertRefusalFree($response);

        $this->assertSame(
            '["delivery"]',
            $this->newestAbilities(),
            'the driver login still mints a universal credential — a driver token is accepted by'
            . ' `custom.auth` for the deliveryboy guard, so its scope should say so'
        );
    }

    /**
     * EVERY MINT SITE, INCLUDING THE UNROUTED ONE.
     *
     * `Api\AuthController::login` is unrouted (customers have no login endpoint), so it cannot be
     * driven — and it is exactly the site a later change would route. Leaving it on `['*']` because
     * nothing calls it today is how the next package inherits the defect.
     */
    /**
     * TREE-WIDE, NOT FILE-KEYED. A review pointed out that the first version keyed the census on
     * two paths and asserted a per-file count: a new `createToken(` in either file failed the
     * count, but a new one in ANY THIRD FILE — another controller, a module, a console command —
     * was never scanned and would silently mint `['*']`. Which is the exact regression the census
     * exists to prevent. So the whole of `app/` is scanned and the two known files carry counts.
     */
    public function test_every_mint_site_passes_an_ability_from_the_closed_set(): void
    {
        $found = [];

        foreach ($this->phpFilesUnder(dirname(__DIR__, 3) . '/app') as $absolute) {
            $relative = ltrim(str_replace(dirname(__DIR__, 3), '', $absolute), '/');
            $code = $this->sourceOf($relative);

            /*
             * A CALL, not a mention. `DPOPayController` carries the literal `createToken` inside an
             * XML body it posts to a gateway (`<Request>createToken</Request>`), which is not a
             * mint site -- so the count, not the substring, decides.
             */
            $calls = preg_match_all('/createToken\s*\(/', $code);

            if ($calls === 0) {
                continue;
            }

            $found[$relative] = $calls;
        }

        ksort($found);

        $this->assertSame(
            self::MINT_SITES,
            $found,
            'the set of files that mint personal access tokens has changed. A new mint site must be'
            . ' named in MINT_SITES with its count and given an ability from the closed set ['
            . implode(', ', self::VOCABULARY) . '], or it mints `["*"]` -- a universal credential.'
            . ' Found: ' . json_encode($found)
        );

        foreach (self::MINT_SITES as $relative => $expected) {
            $code = $this->sourceOf($relative);

            preg_match_all('/createToken\s*\(([^;]*?)\)(?:->|;)/s', $code, $calls, PREG_SET_ORDER);

            $this->assertCount(
                $expected,
                $calls,
                $relative . ': the mint-site census found ' . count($calls) . ' createToken() calls,'
                . ' not ' . $expected . '. A new one must be named here with its ability, or it will'
                . ' quietly mint a universal credential.'
            );

            foreach ($calls as [$whole, $args]) {
                $this->assertStringNotContainsString(
                    "'*'",
                    $args,
                    $relative . ': a mint site still asks for every ability — ' . trim($whole)
                );

                $matched = false;
                foreach (self::VOCABULARY as $ability) {
                    if (str_contains($args, "'" . $ability . "'")) {
                        $matched = true;
                        break;
                    }
                }

                $this->assertTrue(
                    $matched,
                    $relative . ': this mint site passes no ability from the closed set ['
                    . implode(', ', self::VOCABULARY) . '] — ' . trim($whole)
                    . '. A token minted with no abilities argument gets `["*"]`.'
                );
            }
        }
    }

    /**
     * ENFORCEMENT IS A SEPARATE RELEASE, BY INSTRUCTION.
     *
     * The proposal: *"Do not add `CheckAbilities` to routes in the same release. Mint first,
     * enforce later, so the two can be rolled back independently and tokens minted before the
     * change are not stranded."* Wiring enforcement here would strand every `['*']`-era token the
     * moment a route demanded a named ability, with no way to roll back the mint half alone.
     */
    public function test_enforcement_is_not_wired_in_the_same_release(): void
    {
        foreach (['routes/api.php', 'routes/web.php', 'routes/customer.php', 'bootstrap/app.php'] as $relative) {
            $code = $this->sourceOf($relative);

            foreach (['CheckAbilities::class', 'CheckForAnyAbility::class', "'abilities:", "'ability:"] as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $code,
                    $relative . ' wires ability enforcement. Stage 2 mints only; enforcing in the'
                    . ' same release strands every token minted before it and makes the two halves'
                    . ' un-rollback-able independently.'
                );
            }
        }
    }

    /**
     * A TOKEN MINTED BEFORE THIS CHANGE STILL WORKS.
     *
     * The compatibility half of the proposal's instruction, and the one that would bite a live
     * fleet: every token in production today carries `['*']`. Driven against a real authenticated
     * endpoint rather than argued from the fact that nothing reads abilities.
     */
    public function test_a_token_minted_before_this_change_still_authenticates(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $merchant->current_store = $store->id;
        $merchant->save();

        $plain = \Illuminate\Support\Str::random(40);

        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => \App\Models\User::class,
            'tokenable_id' => $merchant->id,
            'name' => 'auth_token',
            'token' => hash('sha256', $plain),
            'abilities' => '["*"]',
            'created_at' => now()->subYear(),
            'updated_at' => now()->subYear(),
        ]);

        $id = DB::table('personal_access_tokens')->orderByDesc('id')->value('id');

        $response = $this->withHeader('Authorization', 'Bearer ' . $id . '|' . $plain)
            ->getJson('/api/admin/notification');

        /*
         * `assertSame(200)` AND A BODY KEY, not `assertNotSame(401)`. A review pointed out that the
         * weaker shape is satisfied by a 404 (the route renamed or moved out of the prefix), a 405
         * or a 500 — and `noti` reads `Auth::user()->current_store` with no null guard, so this
         * very endpoint 500s for a non-`User` principal. This project has already taken two
         * measured false readings from exactly that assertion shape.
         */
        $response->assertOk();

        /*
         * This endpoint does NOT use `ApiResponser`'s envelope: it answers `status: true` with a
         * paginator plus the `count` the Flutter badge reads. So the success signal here is the
         * `count` key, which only an authenticated read produces — noted because the envelope
         * inconsistency is itself part of F181.
         */
        $body = (array) $response->json();

        $this->assertArrayHasKey(
            'count',
            $body,
            'a token minted before stage 2 was refused. Every token in the production fleet carries'
            . ' ["*"], so this would log out every merchant, driver and shopper at once — and the'
            . ' shipped Flutter app forces a logout on any 401. Body: ' . $response->getContent()
        );
        $this->assertStringNotContainsString(
            'Unauthenticated',
            (string) $response->getContent(),
            'the pre-stage-2 token was rejected by the authentication middleware'
        );
    }

    /**
     * THE CUSTOMER PRINCIPAL, DRIVEN THROUGH THE ONE STOREFRONT MINT SITE THAT IS ROUTED.
     *
     * `POST api/{slug}/register` is reachable; `Api\AuthController::login` is not (customers still
     * have no login endpoint) and `forgot_password_verify_otp` needs an OTP already issued. So this
     * is the storefront mint a shopper can actually reach, and it is the one worth driving.
     */
    public function test_a_customer_registration_mints_a_storefront_scoped_token(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);

        $response = $this->postJson('/api/' . $store->slug . '/register', [
            'first_name' => 'Shopper',
            'last_name' => 'One',
            'email' => 'shopper-' . uniqid() . '@example.test',
            'mobile' => '0900000000',
            'password' => 'password123',
        ]);

        /*
         * `assertOk()` is not enough on this API and the first version of this case was fooled by
         * it: `ApiResponser::error()` answers HTTP 200 with `status: 0`, so a validation refusal is
         * indistinguishable from success by status alone. (It was "The mobile field is required.")
         */
        $response->assertOk();
        $this->assertSame(
            1,
            $response->json('status'),
            'the registration was refused, so nothing was minted: ' . (string) $response->json('message')
        );

        $this->assertSame(
            '["storefront"]',
            $this->newestAbilities(),
            'a shopper token is still a universal credential, so one read out of a log reaches the'
            . ' merchant admin API as well as the storefront'
        );
    }

    /**
     * THE OPERATOR GOES THROUGH THE MERCHANT MINT SITE, AND MUST NOT COME OUT DIFFERENT.
     *
     * Operator status in this codebase is a string on `users.type`, not a separate principal or
     * guard — so the platform operator logs in at the same `adminlogin` and is tokenable as `User`.
     * A scope that varied by `type` would be a second authorization system living in the token, and
     * `CustomAuth` would not consult it anyway. One name for the principal, whatever their type.
     */
    public function test_the_operator_gets_the_same_merchant_scope(): void
    {
        $operator = $this->makePlatformAdmin(['password' => bcrypt('password')]);

        $response = $this->postJson('/api/admin/adminlogin', [
            'email' => $operator->email,
            'password' => 'password',
        ]);

        $this->assertRefusalFree($response);

        $this->assertSame(
            'super admin',
            strtolower(trim((string) $operator->fresh()->type)),
            'the premise: this principal really is the platform operator'
        );
        $this->assertSame(
            '["merchant"]',
            $this->newestAbilities(),
            'the operator was minted a different scope from the merchant they share a mint site and'
            . ' a guard with'
        );
    }

    /**
     * THE FLUTTER CONTRACT IS UNCHANGED, which is the compatibility half the owner asked for.
     *
     * The shipped admin app's whole surface is `/api/admin/*` — 92 endpoints under one base path,
     * per `tests/Support/ApiContract/manifests/flutter-endpoints.json`. It reads `token` out of the
     * login response and nothing else about the credential, so an ability list appearing in that
     * response would be a new field on a contract nobody asked to change.
     */
    public function test_the_login_response_shape_is_unchanged_for_the_flutter_app(): void
    {
        $merchant = $this->makeMerchant(['password' => bcrypt('password')]);
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();

        $body = $this->postJson('/api/admin/adminlogin', [
            'email' => $merchant->email,
            'password' => 'password',
        ])->json();

        $data = $body['data'] ?? [];

        $this->assertArrayHasKey('token', $data, 'the app reads data.token; it is gone');
        $this->assertIsString($data['token']);
        $this->assertArrayNotHasKey(
            'abilities',
            $data,
            'the login response now advertises the token scope. Nothing in the app reads it, and a'
            . ' new field on this contract is a change nobody asked for.'
        );
    }

    /**
     * LYNOMIA CHAT IS NOT A SANCTUM CONSUMER AT ALL, so this stage cannot reach it.
     *
     * Measured rather than assumed, because "test Lynomia" is only answerable once you know what
     * it authenticates with: its commerce API carries its own `AuthenticateCommerceApi` middleware
     * and an `lsc_` credential, and none of its routes carry `custom.auth`. So no ability name can
     * affect it, in this stage or the enforcement one.
     */
    public function test_lynomia_chat_authenticates_with_its_own_credential_not_a_sanctum_token(): void
    {
        $routes = $this->sourceOf('packages/workdo/LynomiaChat/src/routes/web.php');

        $this->assertStringContainsString(
            'AuthenticateCommerceApi::class',
            $routes,
            'the premise: the Lynomia commerce API guards itself with its own middleware'
        );
        $this->assertStringNotContainsString(
            'custom.auth',
            $routes,
            'a Lynomia route now authenticates with a Sanctum token, so the ability vocabulary this'
            . ' stage introduces reaches a surface it was never measured against'
        );

        $middleware = $this->sourceOf(
            'packages/workdo/LynomiaChat/src/app/Http/Middleware/AuthenticateCommerceApi.php'
        );

        $this->assertStringNotContainsString(
            'PersonalAccessToken',
            $middleware,
            'the Lynomia credential check now resolves a Sanctum personal access token'
        );
    }

    /**
     * THE CONTRACT TRAP THE ENFORCEMENT STAGE WOULD FALL INTO, pinned here because this is the
     * slice that established the vocabulary.
     *
     * `api/admin/*` hosts BOTH principals — the merchant app and the driver app — so the split is
     * by tokenable class, not by path. That is why the vocabulary is per principal. And five routes
     * NAMED for drivers are merchant surface: `DeliveryBoyApiController` reads `auth()->user()`
     * (the web guard) and scopes by `creatorId()`, i.e. it is a merchant managing their own
     * drivers. Gating `deliveryboys*` on `delivery` would lock merchants out of driver management
     * while looking obviously correct.
     */
    public function test_routes_named_for_drivers_are_merchant_surface(): void
    {
        $merchantSurface = $this->sourceOf('app/Http/Controllers/Api/DeliveryBoyApiController.php');

        $this->assertStringContainsString(
            'auth()->user()',
            $merchantSurface,
            'DeliveryBoyApiController no longer reads the web guard. If it moved to'
            . ' auth(\'deliveryboy\'), the enforcement stage\'s mapping changes and this comment'
            . ' is wrong -- re-derive it.'
        );
        $this->assertStringNotContainsString(
            "auth('deliveryboy')",
            $merchantSurface,
            'DeliveryBoyApiController now reads the driver guard, so the five deliveryboys* routes'
            . ' are no longer merchant surface'
        );

        $driverSurface = $this->sourceOf('app/Http/Controllers/Api/DashboardController.php');

        foreach (['deliveryHome', 'DeliveryBoyOrderList', 'deliveryTransaction'] as $method) {
            $this->assertStringContainsString(
                'function ' . $method . '(',
                $driverSurface,
                'the driver surface method ' . $method . ' is gone; the principal map needs redoing'
            );
        }
    }

    // ---------------------------------------------------------------- fixture

    /**
     * A 200 IS NOT A SUCCESS ON THIS API. `ApiResponser::error()` answers HTTP 200 with
     * `status: 0`, so `assertOk()` alone cannot tell a mint from a refusal. The three driven mint
     * cases relied on `newestAbilities()`'s null check to catch that, which works only while no
     * fixture in those cases mints a token of its own — and a sibling test in this directory does
     * exactly that in a fixture. One copy-paste away from a refused login reading the fixture's
     * abilities and passing.
     */
    private function assertRefusalFree(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertOk();

        $this->assertSame(
            1,
            $response->json('status'),
            'the request was refused, so nothing was minted: ' . (string) $response->json('message')
        );
    }

    private function newestAbilities(): string
    {
        $abilities = DB::table('personal_access_tokens')->orderByDesc('id')->value('abilities');

        $this->assertNotNull($abilities, 'the premise: a token was minted at all');

        return (string) $abilities;
    }

    /** @return list<string> every .php file under a directory, sorted, so a census is total. */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /** Comments stripped, so a census cannot be satisfied by prose about itself. */
    private function sourceOf(string $relative): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents(base_path($relative))) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
