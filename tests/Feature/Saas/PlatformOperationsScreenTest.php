<?php

namespace Tests\Feature\Saas;

use App\Services\SaasOperationsProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * OPS-001 — THE DATABASE, QUEUE AND INTEGRATION MEASUREMENTS, AND ITS DOOR.
 * =================================================================================================
 *
 * The figures span every tenant, so there is no row-level fallback behind this door: the gate is
 * the whole control, exactly as on `/platform/resources` and `/platform/analytics`, and it is
 * `PlatformOperatorOnly` for the same reason those screens give — a merchant creates their own
 * roles (`RoleController::store`, reachable with the `Create Role` permission the shared `admin`
 * role carries for every self-registered merchant), so "holds permission X" is substantially
 * self-assignable and is the wrong shape of gate. Identity is not.
 *
 * AND THE SUBJECT MAKES THE GATE MATTER MORE HERE THAN ON THE MACHINE SCREEN. `/platform/resources`
 * discloses load, memory and disk. THIS page discloses, per payment gateway, how many stores have
 * it switched on and how many of those are misconfigured — commercial information about third
 * parties, and a list of which merchants' checkouts are currently broken. No credential VALUE is
 * read or printed anywhere, and `PlatformOperationsMeasurementTest` holds that against the SQL the
 * producer really issues; the enablement itself is still not a merchant's business.
 *
 * THE DIFFERENTIATING TEST IS `test_a_staff_member_holding_manage_reports_is_still_refused`, and it
 * asserts the premise first: the same principal really can open `/analytics`. Without that premise
 * the refusal would also pass if the permission had never been granted, which is failure mode 3 in
 * the project skill's list of ways a test passes for the wrong reason.
 *
 * `config(['app.debug' => false])` before any body is read: an APP_DEBUG query log was once nearly
 * mis-filed as a disclosure in this repository, and this screen prints SQL fragments as the
 * `source` of each figure, so a debug bar's own query list would be easy to mistake for them.
 */
final class PlatformOperationsScreenTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const URI = '/platform/operations';

    private const PERMISSION = 'Manage Reports';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * The door
     * ---------------------------------------------------------------------------------------------
     */

    public function test_a_signed_out_visitor_cannot_open_it(): void
    {
        $response = $this->get(self::URI);

        $this->assertTrue($response->isRedirect(),
            'the cross-tenant operations screen was served to a guest (status ' . $response->status() . ')');
        $this->assertNotSame(401, $response->status(),
            'a 401 forces a logout in the shipped Flutter client; `auth` runs first and redirects');
    }

    public function test_a_merchant_cannot_open_it(): void
    {
        [$merchant] = $this->makeTenant();

        $this->actingAs($merchant->fresh())->get(self::URI)->assertStatus(403);
    }

    public function test_a_staff_member_cannot_open_it(): void
    {
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);

        $this->actingAs($staff->fresh())->get(self::URI)->assertStatus(403);
    }

    /** The test this file exists for: a self-assignable permission must not open the platform. */
    public function test_a_staff_member_holding_manage_reports_is_still_refused(): void
    {
        [$merchant] = $this->makeTenant();
        $holder = $this->makeStaff($merchant);
        $this->grantPermission($holder, self::PERMISSION);

        $holder = $holder->fresh();

        $this->assertTrue($holder->isAbleTo(self::PERMISSION),
            'Premise: this principal really does hold ' . self::PERMISSION . '.');
        $this->actingAs($holder)->get('/analytics')->assertStatus(200);

        $this->actingAs($holder)->get(self::URI)->assertStatus(403);
    }

    public function test_the_platform_operator_can_open_it(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200);
    }

    /**
     * No store needs to be selected. `getCurrentStore()` answers the hardcoded literal 1 for a
     * `super admin`, so a screen that asked would present one tenant's situation as the
     * platform's; this one measures the infrastructure and never asks.
     */
    public function test_the_operator_needs_no_store_selected(): void
    {
        $operator = $this->makePlatformAdmin(['current_store' => null]);

        $this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200);
    }

    /**
     * THE HANDLER REFUSES ON ITS OWN, WITH THE ROUTE GATE REMOVED.
     *
     * `CLAUDE.md`: a handler-level check behind an `abort()`ing middleware is INVISIBLE to an HTTP
     * test — the middleware answers 403 and the handler is never reached, so every case above
     * passes whether or not the handler checks anything. `PlanController::planDisable` admitted a
     * `Customer` typed `super admin` behind a gate whose HTTP tests were green (`GAP-307`). So the
     * handler is called directly, with no middleware in the way at all.
     *
     * THE `Customer` CASE IS THE ONE THAT NEEDS THE `instanceof`. `customers` has a `type` column
     * with `'type'` in `Customer::$fillable`, and `UserPolicy::isOperator()` takes an untyped
     * actor and reads `->type` — so a Customer row typed `super admin` satisfies it. EXACTLY 403
     * is asserted, because without the class clause such a Customer reaches the view and raises a
     * 500, and a 500 is not a refusal.
     */
    public function test_the_handler_refuses_a_non_operator_with_the_route_gate_removed(): void
    {
        [$merchant] = $this->makeTenant();

        $controller = new \App\Http\Controllers\SuperAdminOperationsController();
        $request = \Illuminate\Http\Request::create(self::URI, 'GET');

        foreach ([
            'a merchant' => $merchant->fresh(),
            'a customer typed super admin' => $this->customerTypedSuperAdmin(),
            'nobody' => null,
        ] as $who => $principal) {
            if ($principal === null) {
                auth()->logout();
            } else {
                auth()->setUser($principal);
            }

            try {
                $controller->index($request);

                $this->fail('the handler served the operations screen to ' . $who
                    . ' with the route gate removed');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(403, $e->getStatusCode(),
                    'the handler answered ' . $e->getStatusCode() . ' rather than 403 for ' . $who);
            }
        }
    }

    /**
     * A `Customer` row typed `super admin`, which is a state the product can really be put into:
     * `type` is in `Customer::$fillable`.
     */
    private function customerTypedSuperAdmin(): \App\Models\Customer
    {
        [$merchant, $store] = $this->makeTenant();

        $customer = new \App\Models\Customer();
        $customer->forceFill([
            'first_name' => 'Impostor',
            'last_name' => 'Impostor',
            'email' => 'impostor-' . uniqid() . '@example.test',
            'password' => bcrypt('x'),
            'type' => 'super admin',
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ])->save();

        return $customer;
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * What the page says
     * ---------------------------------------------------------------------------------------------
     */

    /**
     * THE FIGURES ON THE PAGE ARE THE SYSTEM'S, asserted against a second reading taken here.
     *
     * The engine, its version and its name are re-derived from the live connection in the test,
     * so a page printing a plausible constant — or a row count dressed as an infrastructure
     * figure, which is what every "system" number in this product was before this slice — fails.
     */
    public function test_the_page_prints_the_live_connections_own_engine(): void
    {
        $operator = $this->makePlatformAdmin();

        $html = $this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200)->getContent();

        $this->assertStringContainsString(DB::connection()->getDriverName(), $html,
            'the engine the queries go to is not named on the page, so whatever is on it was not '
            . 'read from the connection');
        $this->assertStringContainsString(
            (string) DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION),
            $html,
            'the engine version on the page is not the one the driver reports'
        );
    }

    /**
     * THE QUEUE DEPTH ON THE PAGE MOVES WITH THE QUEUE.
     *
     * Two renders with three jobs inserted between them, and the FIGURE is asserted — not merely
     * that some number is present. A page printing a constant passes the first render and fails
     * the second.
     */
    public function test_the_page_prints_the_queue_depth_there_really_is(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.table' => 'jobs',
        ]);

        $operator = $this->makePlatformAdmin();

        $before = $this->measurementBlock($this->actingAs($operator->fresh())->get(self::URI)->getContent());

        $this->assertSame('0', $this->figureCell($before, __('Jobs waiting')),
            'premise: the queue is not empty at the start of this case');

        for ($i = 0; $i < 3; $i++) {
            DB::table('jobs')->insert([
                'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
                'reserved_at' => null, 'available_at' => time(), 'created_at' => time(),
            ]);
        }

        $after = $this->measurementBlock($this->actingAs($operator->fresh())->get(self::URI)->getContent());

        $this->assertSame('3', $this->figureCell($after, __('Jobs waiting')),
            'three jobs were queued and the figure on the page did not follow them');
    }

    /** Every measurement states its own source, which is what makes it checkable by hand. */
    public function test_the_page_names_the_query_behind_each_figure(): void
    {
        $operator = $this->makePlatformAdmin();

        $html = $this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200)->getContent();

        foreach ([
            'config(\'queue.default\')',
            'config(\'database.default\')',
            'Connection::getDriverName()',
            'PaymentSettingController::getPaymentGateways()',
        ] as $source) {
            $this->assertStringContainsString(e($source), $html,
                "the page prints figures without naming '{$source}' as a source");
        }
    }

    /**
     * NO CREDENTIAL REACHES THE PAGE, asserted with a real one in the table.
     *
     * The gateway rows are taken off `settings`, which is also where every merchant's live secret
     * key lives. A distinctive value is stored and the whole rendered body is searched for it.
     * This is the assertion that would catch a future "show me which key is missing" convenience.
     */
    public function test_no_gateway_credential_reaches_the_page(): void
    {
        $secret = 'sk_live_OPSPROBE_' . strtoupper(bin2hex(random_bytes(6)));

        foreach ([
            'is_stripe_enabled' => 'on',
            'stripe_publishable_key' => 'pk_live_OPSPROBE_PUBLIC',
            'stripe_secret_key' => $secret,
        ] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value, 'created_by' => '1',
                'store_id' => 909, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $operator = $this->makePlatformAdmin();

        $html = $this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200)->getContent();

        $this->assertStringContainsString('stripe', $html,
            'premise: the gateway the secret belongs to is not even on the page, so this case '
            . 'proved nothing about what the page prints');
        $this->assertStringNotContainsString($secret, $html,
            'a merchant\'s live gateway secret was printed on the operator operations screen');
        $this->assertStringNotContainsString('pk_live_OPSPROBE_PUBLIC', $html,
            'a merchant\'s stored gateway key reached the page. Only the PRESENCE of a credential '
            . 'is a measurement here; its value is never one.');
    }

    /**
     * REQUEST INPUT IS IGNORED, asserted rather than read off the current code.
     *
     * The handler reads nothing from the request today, which is the design. A probe makes that an
     * asserted property, so a later edit that starts honouring a `store_id` reddens instead of
     * quietly turning a platform-wide measurement into a caller-chosen one — and on a screen whose
     * figures span every tenant, a caller-supplied `store_id` is a cross-tenant selector wearing a
     * filter's clothes.
     */
    public function test_a_query_string_changes_nothing(): void
    {
        $operator = $this->makePlatformAdmin();

        $plain = $this->measurementBlock($this->actingAs($operator->fresh())->get(self::URI)->getContent());
        $probed = $this->measurementBlock(
            $this->actingAs($operator->fresh())->get(self::URI . '?store_id=7&table=users&hours=99999')->getContent()
        );

        $this->assertNotSame('', $plain, 'premise: the measurement block was not found on the page');
        $this->assertStringNotContainsString('99999', $probed,
            'a window supplied in the query string reached the page');
        $this->assertStringContainsString(
            __('the last :hours hours', ['hours' => \App\Http\Controllers\SuperAdminOperationsController::FAILURE_WINDOW_HOURS]),
            $probed,
            'the failure window changed when one was supplied in the query string'
        );
    }

    /**
     * NO VERDICT ON THE PAGE EITHER. The producer is pinned against carrying one; this is the
     * other half, because a threshold could equally be invented in the Blade.
     */
    public function test_the_page_passes_no_judgement_on_the_figures(): void
    {
        $operator = $this->makePlatformAdmin();

        $html = $this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200)->getContent();

        $section = $this->measurementBlock($html);

        $this->assertNotSame('', $section, 'premise: the measurement block was not found on the page');

        foreach (['badge bg-danger', 'badge bg-warning', 'badge bg-success', 'text-danger', 'text-success'] as $verdict) {
            $this->assertStringNotContainsString($verdict, $section,
                "the measurement block carries '{$verdict}', which states a judgement about a "
                . 'figure. What counts as too deep a queue has been set by nobody, and OPS-003 — '
                . 'alerts and thresholds — is where that decision belongs.');
        }
    }

    /**
     * THE SCREEN SAYS WHAT IT CANNOT SEE, which on this page is the load-bearing honesty.
     *
     * Three limits, each of them something an operator would otherwise reasonably assume: nothing
     * sends an alert, nothing is contacted to produce the page, and only QUEUED work records its
     * own failures — so the failure figures are not a complete list of integration failures.
     */
    public function test_the_page_states_the_three_things_it_cannot_see(): void
    {
        $operator = $this->makePlatformAdmin();

        $html = $this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200)->getContent();

        foreach ([
            'sends an alert' => 'the page does not say that nothing on this platform alerts anyone',
            'No integration is contacted' => 'the page does not say that no integration was contacted to produce it',
            'not a complete list of integration failures' => 'the page does not say that the failure figures cover queued work only',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $html, $why);
        }
    }

    /**
     * BILINGUAL, DRIVEN THE WAY THE APPLICATION SETS THE LOCALE.
     *
     * `app()->setLocale('ar')` does not survive the request: `SetLocate` runs inside it and
     * resolves the locale again from session, cookie, then `auth()->user()->language`, so the page
     * comes back in English and a `__()` call after the response reads the English catalogue too —
     * which makes a bilingual test pass while rendering English on both sides. The locale therefore
     * goes on the operator's own `language` column and the expected strings are Arabic literals.
     */
    public function test_the_screen_renders_in_arabic_and_right_to_left(): void
    {
        $operator = $this->makePlatformAdmin();
        $operator->language = 'ar';
        $operator->save();

        $html = $this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200)->getContent();

        $this->assertStringContainsString('dir="rtl"', $html,
            'premise failed: the document is not right-to-left for an Arabic operator');

        /*
         * SCOPED TO THE `<h1>`, and that is not fussiness: the heading string is printed TWICE on
         * this page, once into `page-title` and once into the breadcrumb, so a whole-page
         * assertion for the Arabic heading is satisfied by the breadcrumb on the title's behalf.
         * CLAUDE.md's rule is to scope the search to the block.
         */
        $this->assertStringContainsString('تشغيل المنصة', $this->between($html, 'ss-page-title', '</h1>'),
            'the page heading rendered in English for an Arabic operator');
        $this->assertStringContainsString('المهامّ المنتظِرة', $html,
            'the queue-depth label rendered in English for an Arabic operator');
        $this->assertStringContainsString('بوّابات الدفع', $html,
            'the payment-gateway section rendered in English for an Arabic operator');
    }

    /** The English page is the control for the Arabic one: both directions, same run. */
    public function test_the_screen_renders_in_english_and_left_to_right(): void
    {
        $operator = $this->makePlatformAdmin(['language' => 'en']);

        $html = $this->actingAs($operator->fresh())->get(self::URI)->assertStatus(200)->getContent();

        $this->assertStringContainsString('dir="ltr"', $html,
            'premise failed: the document is not left-to-right for an English operator');
        $this->assertStringContainsString('Platform operations', $this->between($html, 'ss-page-title', '</h1>'));
        $this->assertStringContainsString('Jobs waiting', $html);
        $this->assertStringContainsString('Payment gateways', $html);
    }

    /** The screen is reachable from the operator dashboard, not only by typing its URL. */
    public function test_the_screen_is_linked_from_the_operator_dashboard(): void
    {
        $operator = $this->makePlatformAdmin();
        $this->makeStore($operator, ['default_language' => 'en']);
        (new \App\Models\User)->flushCache();
        \Illuminate\Support\Facades\Cache::forget('admin_details');

        $html = $this->actingAs(\App\Models\User::find($operator->id))->get('/dashboard')
            ->assertOk()->getContent();

        $this->assertStringContainsString('href="' . route('platform.operations') . '"', $html,
            'a built screen nobody can find is not delivered: the operator dashboard does not link here');
    }

    /** And the machine screen no longer claims these three things are measured nowhere. */
    public function test_the_machine_screen_no_longer_says_the_queue_is_unmeasured(): void
    {
        $operator = $this->makePlatformAdmin();

        $html = $this->actingAs($operator->fresh())->get('/platform/resources')->assertOk()->getContent();

        $this->assertStringNotContainsString('Queue depth, database size and integration reachability are not measured here', $html,
            'the machine screen still tells an operator that the queue, the database size and the '
            . 'integrations are measured nowhere. They are now measured on /platform/operations, '
            . 'and a stated gap that has been filled and still says it is a gap makes a reader '
            . 'stop looking.');
        $this->assertStringContainsString('href="' . route('platform.operations') . '"', $html,
            'the machine screen does not send the reader to the screen that measures the rest');
    }

    /*
     * ---------------------------------------------------------------------------------------------
     * helpers
     * ---------------------------------------------------------------------------------------------
     */

    private function measurementBlock(string $html): string
    {
        return $this->between($html, 'ss-operations-measurements', 'ss-operations-measurements-end');
    }

    /**
     * THE FIGURE PRINTED AGAINST ONE LABEL, and nothing else on the page.
     *
     * NOT A WHOLE-PAGE SEARCH FOR `>3<`, which this page would satisfy from any of twenty figures.
     * `CLAUDE.md` records a mutation surviving for exactly that reason — "an assertion over a whole
     * 7,000-line stylesheet answers 'does this string exist anywhere'". So the search is scoped to
     * the label's own row and the figure is returned EXACTLY, trimmed of the Blade's indentation,
     * which lets the caller `assertSame` rather than `assertStringContainsString`: `3` contains
     * `3`, and so does `43`.
     */
    private function figureCell(string $html, string $label): string
    {
        $from = strpos($html, '>' . $label . '<');

        if ($from === false) {
            return '';
        }

        $to = strpos($html, '</tr>', $from);

        if ($to === false) {
            return '';
        }

        $row = substr($html, $from, $to - $from);

        if (preg_match('#<td>(.*?)</td>#s', $row, $matches) !== 1) {
            return '';
        }

        return trim($matches[1]);
    }

    /** The slice of `$html` between two markers, so an assertion cannot be satisfied elsewhere. */
    private function between(string $html, string $open, string $close): string
    {
        $from = strpos($html, $open);

        if ($from === false) {
            return '';
        }

        $to = strpos($html, $close, $from);

        if ($to === false) {
            return '';
        }

        return substr($html, $from, $to - $from);
    }
}
