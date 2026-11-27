<?php

namespace Tests\Feature\Control;

use App\Http\Controllers\PlatformFeatureControlController;
use App\Http\Middleware\PlatformOperatorOnly;
use App\Models\Customer;
use App\Models\PlatformFeatureFlagTarget;
use App\Models\User;
use App\Support\Control\FeatureControl;
use App\Support\Control\FeatureDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * `CTRL-001` / `CTRL-002` / `CTRL-003` — THE OPERATOR CAN ACTUALLY DO IT.
 * =================================================================================================
 *
 * A CONTROL PLANE NOBODY CAN REACH IS NOT DELIVERED. `FeatureControl` is covered by three files of
 * its own; what this one asserts is that the capability exists for the PLATFORM OPERATOR — which is
 * whose capability `CTRL-001` and `CTRL-002` are — and that it exists for nobody else.
 *
 * `OperatorDashboardPlatformLinksTest`'s own docblock records the failure this avoids: three
 * platform screens shipped reachable only by typing their URLs, because `grep -rn
 * 'platform.analytics' resources/views/` found exactly one hit and it was inside the screen itself.
 *
 * -------------------------------------------------------------------------------------------------
 * THREE THINGS THIS FILE IS CAREFUL ABOUT, each one a trap CLAUDE.md records.
 *
 *  1. **THE LANDED PATH, NOT THE STATUS.** A merchant with an empty `add_on_managers` is redirected
 *     to the dashboard with HTTP 200, "which a status-code assertion photographs as the screen". So
 *     the operator's read asserts a marker the view itself emits, and the writes assert the STORED
 *     ROW, never the redirect.
 *
 *  2. **A HANDLER CHECK BEHIND AN `abort()`ING MIDDLEWARE IS INVISIBLE TO AN HTTP TEST.** The route
 *     answers 403 whether or not the handler checks anything. So the handler's own check is driven
 *     with `withoutMiddleware(PlatformOperatorOnly::class)` — the same way
 *     `PlatformAdminSurfaceDefenceInDepthTest` drives its two screens — and there is a PREMISE case
 *     proving the gate is real and that disabling it really disables it.
 *
 *  3. **A ROUTE CAN BE SHADOWED.** `RouteCollection::addToCollections()` keys on method+domain+uri,
 *     and the public group earlier in `routes/web.php` already owns `platform/features`. All four
 *     names are therefore resolved through the real router and asserted to reach THIS controller,
 *     which is the only check that can tell a shadowed route from a working one.
 */
class PlatformFeatureControlScreenTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const FEATURE = 'premium-insights';

    /** Every route the screen owns, as `name => [verb, uri]`. */
    private const ROUTES = [
        'platform.feature_control.index' => ['GET', '/platform/feature-control'],
        'platform.feature_control.state' => ['POST', '/platform/feature-control/state'],
        'platform.feature_control.target' => ['POST', '/platform/feature-control/target'],
        'platform.feature_control.pilot' => ['POST', '/platform/feature-control/pilot'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * `APP_DEBUG` OFF. CLAUDE.md: with it on, the debug bar's own badges are part of the
         * rendered page -- a contrast harness measures `span.phpdebugbar-badge` and reports
         * findings that are not in the product, and the COUNT varies per surface. This file asserts
         * on markup, so a bar in the page is a source of false positives for the same reason.
         */
        config(['app.debug' => false]);

        FeatureControl::forget();
    }

    private function operator(): User
    {
        $operator = $this->makePlatformAdmin(['name' => 'Platform Operator']);
        $this->makeStore($operator, ['default_language' => 'en']);

        (new User)->flushCache();
        Cache::forget('admin_details');

        return User::find($operator->id);
    }

    /** The screen's own markup, bounded by the markers the view emits. */
    private function screen(User $operator): string
    {
        $response = $this->actingAs($operator)->get(route('platform.feature_control.index'));

        $response->assertOk();

        $body = (string) $response->getContent();

        $from = strpos($body, 'ss-feature-control');
        $to = $from === false ? false : strpos($body, 'ss-feature-control-end', $from);

        $this->assertNotFalse($from,
            'The feature-control markers are absent from the response. CLAUDE.md records that a '
            . 'gate\'s redirect to the dashboard is HTTP 200, so `assertOk()` alone does not '
            . 'establish that THIS screen rendered -- the marker does.');
        $this->assertNotFalse($to, 'The closing marker is absent, so the slice would run to the end of the page.');

        return substr($body, $from, $to - $from);
    }

    /**
     * JUST THE DECLARED-FEATURES TABLE, bounded by the markers the view emits around it.
     *
     * A SURVIVING MUTATION PUT THIS HERE. Blanking the visible key in that table was invisible to
     * an assertion over the whole screen, because the change log at the foot of the SAME page also
     * prints the feature's key in a `.ss-code` — so the page did contain the string and the test
     * was satisfied by a different section. CLAUDE.md's rule, verbatim: "Scope the search to the
     * block too", after a mutation dropping one component's 44px target survived because another
     * component had the same rule.
     */
    private function featuresSection(User $operator): string
    {
        $screen = $this->screen($operator);

        $to = strpos($screen, 'ss-feature-control-features-end');

        $this->assertNotFalse($to,
            'The declared-features section marker is absent, so this slice would run to the end of '
            . 'the page and could be satisfied by the change log.');

        return substr($screen, 0, $to);
    }

    // ---------------------------------------------------------------------------------------------
    // the routes exist, reach this controller, and are not shadowed
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function test_all_four_routes_resolve_to_this_controller_and_carry_the_operator_gate(): void
    {
        foreach (self::ROUTES as $name => [$verb, $uri]) {
            $route = Route::getRoutes()->match(\Illuminate\Http\Request::create($uri, $verb));

            $this->assertSame(
                $name,
                $route->getName(),
                $verb . ' ' . $uri . ' resolves to `' . (string) $route->getName() . '`, not to '
                . $name . '. The public group earlier in routes/web.php owns `platform/{literal}` '
                . 'for seven page names, and Laravel matches in declaration order -- so a shadowed '
                . 'operator route answers with a marketing page.'
            );

            $this->assertStringContainsString(
                PlatformFeatureControlController::class,
                (string) $route->getActionName(),
                $verb . ' ' . $uri . ' does not reach PlatformFeatureControlController.'
            );

            $carries = false;

            foreach (array_filter($route->middleware(), 'is_string') as $middleware) {
                if (str_contains($middleware, 'PlatformOperatorOnly')) {
                    $carries = true;
                }
            }

            $this->assertTrue(
                $carries,
                $verb . ' ' . $uri . ' does not carry `PlatformOperatorOnly`. Every refusal '
                . 'assertion in this file would then be measuring something else.'
            );
        }
    }

    /**
     * THE WRITES ARE POSTs AND NOTHING ELSE.
     *
     * A GET that switched a platform feature off would be thrown by a crawler, a link prefetch or
     * a stale bookmark. 405 and not 403: the method is wrong, not the principal.
     */
    #[Test]
    public function test_the_three_writes_cannot_be_driven_with_a_get(): void
    {
        $operator = $this->operator();

        foreach (['state', 'target', 'pilot'] as $action) {
            $response = $this->actingAs($operator)->get('/platform/feature-control/' . $action);

            $this->assertSame(
                405,
                $response->getStatusCode(),
                "GET /platform/feature-control/{$action} answered "
                . $response->getStatusCode() . '. A platform feature switch reachable by GET is '
                . 'reachable by a crawler.'
            );
        }
    }

    // ---------------------------------------------------------------------------------------------
    // who may reach it
    // ---------------------------------------------------------------------------------------------

    /**
     * PREMISE FOR EVERY REFUSAL BELOW: the gate is real, and the operator is admitted — so a 403
     * is a refusal and not a screen that is broken for everybody.
     */
    #[Test]
    public function test_premise_the_gate_refuses_a_merchant_and_admits_the_operator(): void
    {
        $merchant = $this->makeMerchant();
        $this->makeStore($merchant);

        $this->assertSame(
            403,
            $this->actingAs($merchant)->get(route('platform.feature_control.index'))->getStatusCode(),
            'PREMISE: the screen did not refuse a merchant, so the gate is not doing what this '
            . 'file assumes.'
        );

        $this->assertSame(
            200,
            $this->actingAs($this->operator())->get(route('platform.feature_control.index'))->getStatusCode(),
            'PREMISE: the platform operator cannot reach the screen, so this file is measuring a '
            . 'surface that is broken for everybody.'
        );
    }

    #[DataProvider('writeRoutes')]
    #[Test]
    public function test_a_merchant_is_refused_on_every_route(string $verb, string $uri): void
    {
        $merchant = $this->makeMerchant();
        $this->makeStore($merchant);

        $response = $this->actingAs($merchant)->call($verb, $uri, ['action' => 'disable', 'key' => self::FEATURE]);

        $this->assertSame(
            403,
            $response->getStatusCode(),
            $verb . ' ' . $uri . ' answered ' . $response->getStatusCode() . ' to an ordinary '
            . 'merchant. A merchant creates their own roles through the product, so the gate here '
            . 'is identity and must refuse every merchant outright.'
        );
    }

    public static function writeRoutes(): array
    {
        return [
            'index' => ['GET', '/platform/feature-control'],
            'state' => ['POST', '/platform/feature-control/state'],
            'target' => ['POST', '/platform/feature-control/target'],
            'pilot' => ['POST', '/platform/feature-control/pilot'],
        ];
    }

    /**
     * THE HANDLER'S OWN CHECK, DRIVEN WITH THE ROUTE GATE REMOVED.
     *
     * With the gate in place the route answers 403 whether or not the handler checks anything, so
     * this is the only way to see `refuseAnyoneButTheOperator()` at all. CLAUDE.md records two
     * independent instances of a handler-level check that was invisible for exactly this reason,
     * one of which ADMITTED a `Customer` typed `super admin` and disabled a plan — 200, with the
     * handler's own JSON.
     *
     * EXACTLY 403, not `assertNotSame(200, ...)`: without the `instanceof User` clause the Customer
     * reaches the handler and raises a 500, and a 500 is not a refusal.
     */
    #[DataProvider('writeRoutes')]
    #[Test]
    public function test_a_customer_typed_super_admin_is_refused_by_the_handler(string $verb, string $uri): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);

        /*
         * `first_name`/`last_name`, NOT `name`: `customers` has no `name` column -- the model's
         * `name` is an appended accessor -- and `name` is absent from `Customer::$fillable`, so a
         * `create(['name' => ...])` silently drops it. Measured, after a draft using `forceFill()`
         * failed with "table customers has no column named name".
         */
        $customer = Customer::create([
            'first_name' => 'Imp',
            'last_name' => 'Ostor',
            'email' => 'impostor' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => $store->id,
            'status' => 1,
            'type' => 'super admin',
        ]);

        $this->assertSame(
            'super admin',
            (string) $customer->fresh()->getAttribute('type'),
            'premise: `type` is fillable on Customer and the value persisted. If that stops being '
            . 'true the shape is unreachable -- but delete this case on that evidence, not on the '
            . 'assumption.'
        );

        $response = $this->withoutMiddleware(PlatformOperatorOnly::class)
            ->actingAs($customer, 'customers')
            ->call($verb, $uri, ['action' => 'disable', 'key' => self::FEATURE]);

        $this->assertSame(
            403,
            $response->getStatusCode(),
            $verb . ' ' . $uri . ' answered ' . $response->getStatusCode() . ' to a Customer row '
            . 'typed `super admin` once the route gate was removed. The handler must check the '
            . 'principal\'s CLASS before its `type` means anything -- `customers` HAS a `type` '
            . 'column with `type` in Customer::$fillable. A 500 means it got past the check and '
            . 'broke the handler, which is not a refusal.'
        );
    }

    /** CONTROL: the operator is still admitted without the route gate, so the handler is not refusing everybody. */
    #[Test]
    public function test_control_the_operator_is_still_admitted_without_the_route_gate(): void
    {
        $this->assertSame(
            200,
            $this->withoutMiddleware(PlatformOperatorOnly::class)
                ->actingAs($this->operator())
                ->get(route('platform.feature_control.index'))
                ->getStatusCode(),
            'The handler\'s own check refuses the platform operator, so the two cases above are '
            . 'satisfied by a dead screen rather than by a control.'
        );
    }

    /** An anonymous caller is sent to sign in, not told 403 — `auth` runs before the gate. */
    #[Test]
    public function test_an_anonymous_caller_is_sent_to_sign_in(): void
    {
        $this->get(route('platform.feature_control.index'))->assertRedirect(route('login'));
    }

    // ---------------------------------------------------------------------------------------------
    // the capability, end to end
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function test_the_operator_can_declare_a_feature_and_it_starts_switched_off(): void
    {
        $operator = $this->operator();
        $store = $this->makeStore($this->makeMerchant());

        $this->actingAs($operator)->post(route('platform.feature_control.state'), [
            'action' => 'declare',
            'key' => self::FEATURE,
            'label' => 'Premium insights',
            'description' => 'The deeper analytics tiles.',
        ]);

        $row = DB::table('platform_feature_flags')->where('key', self::FEATURE)->first();

        $this->assertNotNull($row, 'The feature was not declared. The redirect is a 302 either way, '
            . 'so the stored row is the only thing that says which branch ran.');
        $this->assertSame('off', (string) $row->state,
            'A newly declared feature must be switched off: declaring one must not change anything '
            . 'for any store.');
        $this->assertSame('Premium insights', (string) $row->label);

        FeatureControl::forget();

        $this->assertSame(
            FeatureDecision::Denied,
            FeatureControl::decide(self::FEATURE, $store->id),
            'A freshly declared feature reached a store.'
        );
    }

    /**
     * RE-DECLARING A LIVE FEATURE DOES NOT TELL THE OPERATOR IT IS SWITCHED OFF.
     *
     * `FeatureControl::define()` is idempotent and deliberately leaves a live feature's state
     * alone — a deploy step that re-declares every flag must not switch the platform off. The
     * screen's message therefore has to differ, or it states that a feature which is on for every
     * store right now is "switched off until it is released". Found by reading the handler; a small
     * lie on an operator screen is how somebody comes to believe a kill switch is armed.
     */
    #[Test]
    public function test_redeclaring_a_live_feature_does_not_claim_it_is_switched_off(): void
    {
        $operator = $this->operator();
        $store = $this->makeStore($this->makeMerchant());

        FeatureControl::define(self::FEATURE, 'Premium insights', null, $operator);
        FeatureControl::releaseFully(self::FEATURE, $operator);

        $response = $this->actingAs($operator)->post(route('platform.feature_control.state'), [
            'action' => 'declare', 'key' => self::FEATURE, 'label' => 'Premium insights (renamed)',
        ]);

        $response->assertSessionHas(
            'success',
            __('That feature was already declared, so its name and description are updated and its availability is left exactly as it was.')
        );

        $this->assertSame(
            'Premium insights (renamed)',
            (string) DB::table('platform_feature_flags')->where('key', self::FEATURE)->value('label'),
            'The re-declaration did not refresh the label, which is the one thing it is for.'
        );

        FeatureControl::forget();

        $this->assertSame(
            FeatureDecision::Allowed,
            FeatureControl::decide(self::FEATURE, $store->id),
            'Re-declaring through the screen switched a live feature off, which is the outcome the '
            . 'message was going to describe either way.'
        );
    }

    /** The label falls back to the key rather than to a placeholder nobody can identify. */
    #[Test]
    public function test_declaring_without_a_label_uses_the_key(): void
    {
        $this->actingAs($this->operator())->post(route('platform.feature_control.state'), [
            'action' => 'declare',
            'key' => self::FEATURE,
        ]);

        $this->assertSame(
            self::FEATURE,
            (string) DB::table('platform_feature_flags')->where('key', self::FEATURE)->value('label'),
            'An unlabelled feature was given something other than its key. A readable key is '
            . 'usable; "Untitled" is a row somebody has to open to identify.'
        );
    }

    #[Test]
    public function test_the_operator_can_run_a_whole_rollout_and_switch_it_off(): void
    {
        $operator = $this->operator();
        $store = $this->makeStore($this->makeMerchant());

        $this->actingAs($operator)->post(route('platform.feature_control.state'), [
            'action' => 'declare', 'key' => self::FEATURE, 'label' => 'Premium insights',
        ]);

        // CTRL-001, by store.
        $this->actingAs($operator)->post(route('platform.feature_control.state'), [
            'action' => 'release', 'key' => self::FEATURE, 'percentage' => 0,
        ]);
        $this->actingAs($operator)->post(route('platform.feature_control.target'), [
            'action' => 'add',
            'key' => self::FEATURE,
            'axis' => PlatformFeatureFlagTarget::TYPE_STORE,
            'effect' => PlatformFeatureFlagTarget::EFFECT_ALLOW,
            'value' => (string) $store->id,
        ]);

        FeatureControl::forget();
        $this->assertSame(
            FeatureDecision::Allowed,
            FeatureControl::decide(self::FEATURE, $store->id),
            'The operator named a store through the screen and the store did not get the feature.'
        );

        // CTRL-002, gradual rollout: the share really is stored.
        $this->actingAs($operator)->post(route('platform.feature_control.state'), [
            'action' => 'release', 'key' => self::FEATURE, 'percentage' => 25,
        ]);

        $this->assertSame(
            25,
            (int) DB::table('platform_feature_flags')->where('key', self::FEATURE)->value('rollout_percentage'),
            'The rollout share posted through the screen was not stored.'
        );

        // CTRL-002, safe disable: total, and nothing is lost.
        $this->actingAs($operator)->post(route('platform.feature_control.state'), [
            'action' => 'disable', 'key' => self::FEATURE,
        ]);

        FeatureControl::forget();
        $this->assertSame(
            FeatureDecision::Denied,
            FeatureControl::decide(self::FEATURE, $store->id),
            'The feature was switched off through the screen and still reached a store a target '
            . 'names. The off state must be read before any target.'
        );

        $this->assertSame(
            25,
            (int) DB::table('platform_feature_flags')->where('key', self::FEATURE)->value('rollout_percentage'),
            'Switching off through the screen cleared the rollout share, so the operator has to '
            . 'remember a number to resume.'
        );

        $this->assertSame(
            1,
            (int) DB::table('platform_feature_flag_targets')->count(),
            'Switching off through the screen deleted the named exception.'
        );

        // And the operator can withdraw a target.
        $this->actingAs($operator)->post(route('platform.feature_control.target'), [
            'action' => 'withdraw',
            'key' => self::FEATURE,
            'axis' => PlatformFeatureFlagTarget::TYPE_STORE,
            'effect' => PlatformFeatureFlagTarget::EFFECT_ALLOW,
            'value' => (string) $store->id,
        ]);

        $this->assertSame(
            0,
            (int) DB::table('platform_feature_flag_targets')->count(),
            'The withdraw form did not remove the named exception.'
        );
    }

    /** `CTRL-001`, the third axis: pilot membership, both ways, through the screen. */
    #[Test]
    public function test_the_operator_can_put_a_store_in_a_pilot_group_and_take_it_out(): void
    {
        $operator = $this->operator();
        $store = $this->makeStore($this->makeMerchant());

        $this->actingAs($operator)->post(route('platform.feature_control.pilot'), [
            'action' => 'add', 'group' => 'early-access', 'store_id' => $store->id,
        ]);

        $this->assertSame(
            [$store->id],
            FeatureControl::pilotGroupMembers('early-access'),
            'The store was not put in the pilot group.'
        );

        $this->actingAs($operator)->post(route('platform.feature_control.pilot'), [
            'action' => 'remove', 'group' => 'early-access', 'store_id' => $store->id,
        ]);

        $this->assertSame(
            [],
            FeatureControl::pilotGroupMembers('early-access'),
            'The store was not taken out of the pilot group.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // what the screen refuses
    // ---------------------------------------------------------------------------------------------

    /**
     * A TRANSITION ON A KEY NOBODY DECLARED IS REFUSED, AND DOES NOT INVENT THE FLAG.
     *
     * `FeatureControl`'s writers declare a missing flag on purpose — a console command or a deploy
     * step must be able to arm a kill switch for a feature nobody has declared yet. A SCREEN is
     * different: an operator who mistypes a key would otherwise accumulate dead flags that look
     * configured and reach nobody, and the mistyped one would silently replace the real one in
     * their mental model.
     */
    #[Test]
    public function test_releasing_a_key_nobody_declared_is_refused_and_creates_nothing(): void
    {
        $response = $this->actingAs($this->operator())->post(route('platform.feature_control.state'), [
            'action' => 'release', 'key' => 'never-declared', 'percentage' => 50,
        ]);

        $response->assertSessionHas('error');

        $this->assertSame(
            0,
            (int) DB::table('platform_feature_flags')->count(),
            'A release on an undeclared key invented the flag. The screen must refuse a mistyped '
            . 'key rather than create one that reaches nobody while looking configured.'
        );
    }

    /**
     * A KEY A DEVELOPER COULD NOT TYPE IS REFUSED, AND REFUSED BY VALIDATION.
     *
     * Not for storage safety -- Eloquent binds and the column is a plain varchar. The key is an
     * IDENTIFIER that first-party code types as a literal, so a key with a space in it is one
     * nothing can ever ask for: it would sit on this screen looking configured and reach nobody.
     */
    #[Test]
    public function test_a_key_with_a_space_is_refused(): void
    {
        $response = $this->actingAs($this->operator())->post(route('platform.feature_control.state'), [
            'action' => 'declare', 'key' => 'premium insights', 'label' => 'Premium insights',
        ]);

        $response->assertSessionHasErrors('key');

        $this->assertSame(0, (int) DB::table('platform_feature_flags')->count());
    }

    #[Test]
    public function test_a_percentage_outside_the_range_is_refused_by_validation(): void
    {
        $operator = $this->operator();

        $this->actingAs($operator)->post(route('platform.feature_control.state'), [
            'action' => 'declare', 'key' => self::FEATURE, 'label' => 'Premium insights',
        ]);

        $this->actingAs($operator)->post(route('platform.feature_control.state'), [
            'action' => 'release', 'key' => self::FEATURE, 'percentage' => 150,
        ])->assertSessionHasErrors('percentage');

        $this->assertSame(
            'off',
            (string) DB::table('platform_feature_flags')->where('key', self::FEATURE)->value('state'),
            'A refused request still moved the feature\'s availability.'
        );
    }

    /** An axis the decision does not name is refused by validation rather than stored inert. */
    #[Test]
    public function test_an_axis_the_decision_does_not_name_is_refused(): void
    {
        $operator = $this->operator();

        $this->actingAs($operator)->post(route('platform.feature_control.state'), [
            'action' => 'declare', 'key' => self::FEATURE, 'label' => 'Premium insights',
        ]);

        $this->actingAs($operator)->post(route('platform.feature_control.target'), [
            'action' => 'add', 'key' => self::FEATURE,
            'axis' => 'country', 'effect' => 'allow', 'value' => 'SY',
        ])->assertSessionHasErrors('axis');

        $this->assertSame(
            0,
            (int) DB::table('platform_feature_flag_targets')->count(),
            'A target on an unknown axis was stored. It would match nothing, which is safe -- and '
            . 'invisible, which is not: the operator sees a rule they believe is working.'
        );
    }

    /**
     * A KEY CONTAINING `alert` SURVIVES THE REQUEST, AND IT DID NOT BEFORE.
     *
     * FOUND BY SELF-REVIEW OF THIS CHANGE, after its own route comment claimed the opposite.
     * `App\Http\Middleware\XSS` deletes `['&lt;', '&gt;', 'javascript', 'alert']` from EVERY string
     * in EVERY request it handles, case-sensitively, with an exemption list covering
     * credential-shaped keys only. So with `xss` on these routes:
     *
     *     str_replace(XSS::STRIPPED_SUBSTRINGS, '', 'stock-alerts')   ===   'stock-s'
     *
     * `alert` is not an exotic word in a feature key — `stock-alerts`, `price-alert`,
     * `alert-banner` are names somebody really types. The operator would declare a flag, be told
     * it worked, and the code asking for the key they typed would get `NotControlled` for ever.
     * Silently, because the stored key reads perfectly well on the screen.
     *
     * The three write routes therefore carry `->withoutMiddleware('xss')`, and this is the test
     * that says why. It reads the STORED key, not the response: both branches redirect.
     */
    #[Test]
    public function test_a_key_containing_a_stripped_substring_is_stored_as_typed(): void
    {
        $operator = $this->operator();

        /*
         * `XSS::STRIPPED_SUBSTRINGS` is READ, not retyped. `CredentialSanitisationTest`'s own
         * docblock records the trap: a premise that re-declares those four strings stays green
         * while the thing it guards silently stops being guarded, because there is nothing left to
         * mangle. Found there by independent review; the same applies here.
         */
        $this->assertContains(
            'alert',
            \App\Http\Middleware\XSS::STRIPPED_SUBSTRINGS,
            'The XSS sweep no longer strips `alert`, so this test no longer measures anything. '
            . 'Check whether the `withoutMiddleware(\'xss\')` on the three write routes is still '
            . 'needed at all before deleting this case.'
        );

        foreach (['stock-alerts', 'price-alert', 'javascript-console'] as $key) {
            $this->actingAs($operator)->post(route('platform.feature_control.state'), [
                'action' => 'declare', 'key' => $key, 'label' => 'A feature',
            ]);

            $this->assertSame(
                1,
                (int) DB::table('platform_feature_flags')->where('key', $key)->count(),
                "The key `{$key}` is not in the table as typed. The XSS sweep mangled it on the "
                . 'way in, so the operator declared one flag and a different one was stored -- and '
                . 'the code asking for the key they typed will answer NotControlled for ever.'
            );
        }
    }

    /**
     * AND DROPPING `xss` DID NOT OPEN AN OUTPUT HOLE, which is the other half of that decision.
     *
     * `XSS` is an INPUT sanitiser, not an output encoder: what stops markup reaching a reader is
     * Blade's `{{ }}`. With the sweep gone from these routes, a label really can arrive carrying
     * `<` and `>`, so the escaping has to be PROVED rather than assumed. The mutation that
     * replaces that print with `{!! !!}` reddens this.
     */
    #[Test]
    public function test_markup_in_a_label_is_escaped_rather_than_rendered(): void
    {
        $operator = $this->operator();

        $this->actingAs($operator)->post(route('platform.feature_control.state'), [
            'action' => 'declare',
            'key' => 'hostile-label',
            'label' => '<script>alert(1)</script>',
            'description' => '<img src=x onerror="alert(1)">',
        ]);

        /* THE PREMISE: the sweep really is off, so the label arrived with its angle brackets. */
        $this->assertSame(
            '<script>alert(1)</script>',
            (string) DB::table('platform_feature_flags')->where('key', 'hostile-label')->value('label'),
            'The label did not arrive intact, so the escaping assertion below is being satisfied '
            . 'by the input sweep rather than by the view. That is not the control this test is '
            . 'about: the sweep is deliberately off on this route.'
        );

        $screen = $this->screen($operator);

        $this->assertStringNotContainsString(
            '<script>alert(1)</script>',
            $screen,
            'An operator-supplied label was rendered as markup. With `xss` off on the write route, '
            . 'Blade\'s `{{ }}` is the only thing standing between a stored label and the browser.'
        );

        $this->assertStringContainsString(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            $screen,
            'The label is neither rendered nor escaped on the page, so it has gone missing '
            . 'somewhere -- which would make the assertion above pass for the wrong reason.'
        );

        $this->assertStringNotContainsString(
            'onerror="alert(1)"',
            $screen,
            'The description was rendered as markup.'
        );
    }

    /** Store 0 is refused: the resolver treats a non-positive store as "no subject", so the row would be inert. */
    #[Test]
    public function test_a_pilot_membership_for_store_zero_is_refused(): void
    {
        $this->actingAs($this->operator())->post(route('platform.feature_control.pilot'), [
            'action' => 'add', 'group' => 'early-access', 'store_id' => 0,
        ])->assertSessionHasErrors('store_id');

        $this->assertSame(0, (int) DB::table('platform_pilot_group_members')->count());
    }

    // ---------------------------------------------------------------------------------------------
    // what the screen shows
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function test_the_screen_shows_the_feature_its_reach_and_the_change_log(): void
    {
        $operator = $this->operator();
        $store = $this->makeStore($this->makeMerchant());

        FeatureControl::define(self::FEATURE, 'Premium insights', 'The deeper analytics tiles.', $operator);
        FeatureControl::release(self::FEATURE, 25, $operator);
        FeatureControl::addToPilotGroup('early-access', $store->id, $operator);
        FeatureControl::allow(self::FEATURE, PlatformFeatureFlagTarget::TYPE_PILOT_GROUP, 'early-access', $operator);

        $screen = $this->screen($operator);

        foreach ([
            'Premium insights',                 // the label
            'The deeper analytics tiles.',      // the description
            'early-access',                     // the pilot group, in both tables
            (string) $store->id,                // its member
            'Platform Operator',                // who made the changes
        ] as $expected) {
            $this->assertStringContainsString(
                $expected,
                $screen,
                'The screen does not show `' . $expected . '`. An operator cannot act on a control '
                . 'plane whose current state is not on the page.'
            );
        }

        /*
         * THE KEY IS ASSERTED INSIDE ITS OWN ELEMENT, AND A SURVIVING MUTATION IS WHY.
         *
         * Blanking the visible `<code class="ss-code">{{ $feature['key'] }}</code>` SURVIVED a
         * plain `assertStringContainsString(self::FEATURE, $screen)` -- because the key is also in
         * the `value` of a dozen hidden inputs, one per form on the row. So the loose assertion was
         * satisfied by markup nobody can read, while the only place an operator could SEE the key
         * was gone. The key is printed because it is what makes a row checkable by hand and what a
         * developer types at a call site, so what is asserted is that it is VISIBLE.
         */
        $this->assertStringContainsString(
            '<code class="ss-code">' . self::FEATURE . '</code>',
            $this->featuresSection($operator),
            'The feature\'s key is not printed in a visible element in the declared-features table. '
            . 'It may still be in the hidden inputs of the forms on its row, and it is certainly '
            . 'in the change log further down the page -- neither is the same thing: an operator '
            . 'cannot read a hidden input, and the row they are acting on is the one that has to '
            . 'carry the key.'
        );

        /*
         * THE REACH SENTENCE, not just the numbers. `CTRL-002`'s whole point is that an operator
         * can tell how far a release has got; a table of states and percentages with no sentence
         * makes them work it out, and a rollout share is exactly the figure people misread.
         */
        $this->assertStringContainsString(
            'A share of stores has this feature',
            $screen,
            'The screen states no reach sentence for a limited feature with a share AND a named '
            . 'exception, so the one fact the operator needs is the one they have to infer.'
        );

        /* And the change log is on the page, which is `CTRL-003`'s third limb. */
        $this->assertStringContainsString(
            'Rollout share changed',
            $screen,
            'The change log is not rendered. CTRL-003 asks for a change log, and one nobody can '
            . 'read is a table.'
        );
    }

    /**
     * THE STORED STATE IS SHOWN WHEN IT IS NOT A VALUE THE CODE RECOGNISES.
     *
     * `resolvedState()` reports anything unexpected as `off`, which is the right answer for the
     * RESOLVER and a confident lie on a screen: an operator debugging a feature that refuses
     * everybody needs to see that the column holds something unexpected. Production runs non-strict
     * `sql_mode`, so a truncated value is the real shape this takes.
     */
    #[Test]
    public function test_an_unrecognised_stored_state_is_shown_rather_than_reported_as_off(): void
    {
        $operator = $this->operator();

        FeatureControl::define(self::FEATURE, 'Premium insights', null, $operator);
        DB::table('platform_feature_flags')->where('key', self::FEATURE)->update(['state' => 'limite']);

        $screen = $this->screen($operator);

        $this->assertStringContainsString(
            'The stored availability is not a value this platform recognises',
            $screen,
            'A truncated availability value was presented as a confident "Switched off". The '
            . 'operator then has nothing on the page to explain why the feature reaches nobody.'
        );

        $this->assertStringContainsString('limite', $screen,
            'The raw stored value is not printed, so there is nothing to act on.');
    }

    /** An empty control plane says so rather than rendering an empty table. */
    #[Test]
    public function test_an_empty_control_plane_says_so(): void
    {
        $screen = $this->screen($this->operator());

        $this->assertStringContainsString('No feature has been declared yet', $screen);
        $this->assertStringContainsString('No pilot group has any store in it yet', $screen);
        $this->assertStringContainsString('Nothing has been changed yet', $screen);
    }

    // ---------------------------------------------------------------------------------------------
    // bilingual
    // ---------------------------------------------------------------------------------------------

    /**
     * THE SCREEN RENDERS IN ARABIC, AND IN ARABIC — not in English on an Arabic page.
     *
     * `__()` returns its own KEY when nothing is declared, so an undeclared sentence reads correctly
     * in English by accident and reads IN ENGLISH for an Arabic operator. Asserting an Arabic
     * string from `ar.json` is the only check that can tell those apart.
     */
    #[Test]
    public function test_the_screen_renders_in_arabic(): void
    {
        $operator = $this->makePlatformAdmin(['name' => 'المشغّل', 'language' => 'ar']);
        $this->makeStore($operator, ['default_language' => 'ar']);
        (new User)->flushCache();
        Cache::forget('admin_details');

        FeatureControl::define(self::FEATURE, 'Premium insights', null, $operator);

        $screen = $this->screen(User::find($operator->id));

        $this->assertStringContainsString(
            'التحكم بالميزات',
            $screen,
            'The Arabic operator sees the English heading. Check that every key this view prints '
            . 'is declared in resources/lang/ar.json -- `__()` falls back to its own key silently.'
        );

        $this->assertStringContainsString('المجموعات التجريبية', $screen,
            'The pilot groups section is not translated.');
        $this->assertStringContainsString('سجل التغييرات', $screen,
            'The change log section is not translated.');
    }

    #[Test]
    public function test_the_screen_renders_in_english(): void
    {
        $operator = $this->makePlatformAdmin(['name' => 'Platform Operator', 'language' => 'en']);
        $this->makeStore($operator, ['default_language' => 'en']);
        (new User)->flushCache();
        Cache::forget('admin_details');

        FeatureControl::define(self::FEATURE, 'Premium insights', null, $operator);

        $screen = $this->screen(User::find($operator->id));

        $this->assertStringContainsString('Feature control', $screen);
        $this->assertStringContainsString('Pilot groups', $screen);
        $this->assertStringContainsString('Change log', $screen);
    }

    /**
     * THE VIEW IS NOT WRITTEN IN THE SLOT SHAPE, AND IS NOT WRITTEN IN TAILWIND.
     *
     * Both failures are invisible in a rendered page: CLAUDE.md records that a `<x-*-layout>` view
     * renders perfect chrome, HTTP 200 and an EMPTY content area in all four language/theme
     * combinations, and that the Tailwind build toolchain is installed and never loaded in the
     * admin, so a utility class simply would not paint. A census over the source is the only check
     * that sees either one.
     */
    #[Test]
    public function test_the_view_uses_the_admins_own_layout_and_stylesheet(): void
    {
        $source = (string) file_get_contents(base_path('resources/views/platform/feature_control.blade.php'));

        // Comments stripped: this view's own docblock explains BOTH traps by name.
        $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);

        $this->assertStringContainsString("@extends('layouts.app')", $code,
            'The view does not extend the admin layout.');

        $this->assertStringNotContainsString('$slot', $code,
            'The view references $slot. `layouts/app.blade.php` contains zero $slot references, so '
            . 'this renders perfect chrome and an empty content area -- HTTP 200, nothing on the '
            . 'page.');

        foreach (['x-admin-layout', 'x-app-layout', 'x-guest-layout'] as $component) {
            $this->assertStringNotContainsString($component, $code,
                'The view uses the component-layout shape, which prints nothing in this admin.');
        }

        $this->assertStringContainsString('ss-btn', $code,
            'The view uses no design-system button class. The host theme\'s `.btn btn-primary` is '
            . 'a measured contrast failure in this admin.');
    }

    /**
     * THE VIEW INTRODUCES NO COLOUR OF ITS OWN, WHICH IS WHY ITS CONTRAST IS THE DESIGN SYSTEM'S.
     *
     * This is a census and not a measurement, and the difference is stated plainly. A pixel
     * contrast reading needs a running server and the project's live harness
     * (`tests/Support/bin/measure-*-live.cjs`), and CLAUDE.md records how easily such a reading
     * goes wrong: `APP_DEBUG` must be off or the harness measures `span.phpdebugbar-badge`, the
     * badge COUNT varies per surface so a constant subtraction is wrong on the next one, and a
     * quick colour probe is not the instrument — it reported FAIL twice where the real harness read
     * clean. So what is asserted here is the thing that MAKES the contrast correct in both themes
     * and both directions: every colour-bearing class on this page is a design-system one, which
     * re-themes by token, and the view declares no colour at all.
     *
     * COMMENTS ARE STRIPPED FIRST, and this view is exactly why: its own docblock quotes `#e83e8c`
     * and `.btn btn-primary` while explaining that neither is used. CLAUDE.md records this census
     * being satisfied — or broken — by prose about the thing it measures eight times.
     */
    #[Test]
    public function test_the_view_declares_no_colour_and_uses_no_host_colour_class(): void
    {
        $source = (string) file_get_contents(base_path('resources/views/platform/feature_control.blade.php'));
        $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);

        $this->assertStringNotContainsString('style="', $code,
            'The view carries an inline style. A colour written here does not re-theme, so it is '
            . 'correct in at most one of the light and dark admins.');

        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $code,
            'The view contains a hex colour. Every colour on this page must come from a '
            . 'store-syria-ds.css token, or dark mode is a separate measurement nobody will take.');

        $this->assertDoesNotMatchRegularExpression('/\b(rgb|rgba|hsl|hsla)\(/', $code,
            'The view declares a colour function. See above.');

        /*
         * THE HOST'S OWN COLOUR CLASSES, ENUMERATED. Each of these is a measured failure in this
         * admin or a theme-unaware class: `.btn btn-primary` and an unclassed `<code>` are recorded
         * in the design system's own comments, and `.text-danger`/`.bg-warning` state an
         * operational judgement in a hue -- which `platform_resources.blade.php` is explicitly
         * forbidden from doing for the same reason.
         */
        foreach ([
            'btn-primary', 'btn-secondary', 'btn-danger', 'btn-success', 'btn-warning', 'btn-info',
            'text-danger', 'text-success', 'text-warning', 'text-info', 'text-primary', 'text-muted',
            'bg-danger', 'bg-success', 'bg-warning', 'bg-info', 'bg-primary',
            'badge-danger', 'badge-success', 'badge-warning',
        ] as $hostClass) {
            $this->assertStringNotContainsString($hostClass, $code, sprintf(
                'The view uses the host theme\'s `%s`. The design system has a theme-aware '
                . 'equivalent for each of these (ss-btn--*, ss-badge--*, ss-muted); the host '
                . 'classes are not token-driven, so one of the two admin themes is wrong and '
                . 'nobody measured which.',
                $hostClass
            ));
        }

        /*
         * AND COLOUR IS NEVER THE ONLY SIGNAL. `x-ss.status-badge`'s own docblock records that its
         * dot is not decoration -- WCAG 1.4.1 -- and the badge takes a `label`, so the text is
         * always present. The reach SENTENCE beside it repeats the same fact in words, which is
         * what a reader who cannot distinguish the hues actually uses.
         */
        $this->assertStringContainsString('x-ss.status-badge', $code,
            'The availability is not rendered with the design system\'s status badge, whose dot '
            . 'plus TEXT is the signal. A bare coloured span would carry meaning in colour alone.');

        $this->assertStringContainsString(':label="$availabilityLabels', $code,
            'The availability badge is not given a text label, so its colour would be the only '
            . 'signal -- which WCAG 1.4.1 forbids and which seven order statuses already proved '
            . 'indistinguishable by hue in this admin\'s dark theme.');

        $this->assertStringContainsString('$reachSentences[', $code,
            'The screen states no reach sentence, so the availability badge is the only thing '
            . 'carrying how far a release has got.');
    }

    /**
     * THE VIEW DECLARES NO DIRECTION, SO IT MIRRORS WITH THE PAGE.
     *
     * CLAUDE.md requires every UI change to be checked in both directions, and the layout already
     * forces `dir="rtl"` for an `ar` or `he` principal. What breaks that is a view that pins a side
     * itself: a `ml-`/`mr-` margin, a `text-left`, a `float-right`, a `dir=` attribute or an inline
     * `direction:`. Each of those is correct in exactly one of the two languages, and the rendered
     * Arabic test above cannot see it — the page still renders, in Arabic, with the padding on the
     * wrong side.
     *
     * `mb-*` is block-direction and is fine. Flex utilities mirror with `dir` by themselves, which
     * is why the action rows use `d-flex` and `gap-3` rather than a margin.
     */
    #[Test]
    public function test_the_view_pins_no_direction_of_its_own(): void
    {
        $source = (string) file_get_contents(base_path('resources/views/platform/feature_control.blade.php'));
        $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);

        /*
         * THE SPACING UTILITIES ARE MATCHED ON THEIR CLASS-ATTRIBUTE BOUNDARY, NOT AS A BARE
         * SUBSTRING, and the difference matters in both directions. A leading space misses
         * `class="ml-2"` entirely -- the first draft of this test did, and the mutation written for
         * it SURVIVED. No boundary at all is worse: `ms-` occurs inside `items-`, `terms-` and
         * `forms-`, and `pe-` inside `type-`, so the test would fail on innocent markup. Bootstrap's
         * directional spacing utilities are always `{m|p}{s|e|l|r}-{0..5|auto}`, which is exactly
         * what this matches.
         */
        $this->assertDoesNotMatchRegularExpression(
            '/(?:"|\s)(?:m|p)(?:s|e|l|r)-(?:[0-5]|auto)\b/',
            $code,
            'The view pins a side with a directional spacing utility (ml-, mr-, ms-, me-, pl-, pr-, '
            . 'ps- or pe-). The admin layout already forces dir="rtl" for an Arabic principal, so a '
            . 'side pinned here is correct in exactly one of the two languages -- and the '
            . 'rendered-Arabic test cannot see it, because the page still renders, in Arabic, with '
            . 'the spacing on the wrong side. Use `mb-*` (block direction) or a flex gap.'
        );

        foreach ([
            'text-start', 'text-end', 'text-left', 'text-right',
            'float-start', 'float-end', 'float-left', 'float-right',
            'dir=', 'direction:',
        ] as $sided) {
            $this->assertStringNotContainsString($sided, $code, sprintf(
                'The view pins a side or a direction with `%s`. See above: the layout owns `dir`, '
                . 'and a view that pins one is wrong in one of the two languages.',
                $sided
            ));
        }

        /*
         * THE PREMISE. The regular expression above is the instrument, and an instrument that
         * matches nothing passes every absence assertion for ever. Checked on a string that must
         * match and one that must not -- the second being the false positive the boundary exists to
         * avoid.
         */
        $this->assertMatchesRegularExpression('/(?:"|\s)(?:m|p)(?:s|e|l|r)-(?:[0-5]|auto)\b/', '<br class="ml-2">',
            'The directional-spacing probe does not match `class="ml-2"`, so it would pass over a '
            . 'view that really pinned a side. A leading-space pattern has this exact fault.');
        $this->assertDoesNotMatchRegularExpression('/(?:"|\s)(?:m|p)(?:s|e|l|r)-(?:[0-5]|auto)\b/', '<ul class="list-unstyled mb-0">',
            'The directional-spacing probe matches `mb-0`, which is block direction and mirrors '
            . 'correctly. It would fail on innocent markup.');
    }
}
