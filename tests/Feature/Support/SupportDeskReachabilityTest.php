<?php

namespace Tests\Feature\Support;

use App\Models\Store;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Security\SecurityTestCase;

/**
 * `SUPPORT-003` — THE PREMISE BEHIND "KEEP BOTH DESKS AND FIX IN PLACE".
 * =================================================================================================
 *
 * The owner's decision is an instruction about ORDER: "مراجعة Support Ticket وHelpdesk الحاليين قبل
 * استبدالهما" — review the existing Support Ticket and Helpdesk modules BEFORE replacing them. That
 * review was done on 2026-11-25 and its verdict was KEEP BOTH AND FIX IN PLACE, resting on one
 * load-bearing measurement: *the vendored Helpdesk contributes 0 runtime routes, so there was
 * nothing to rewrite.*
 *
 * THAT MEASUREMENT HAD NO TEST, AND IT IS THE KIND THAT ROTS SILENTLY. Adding `Helpdesk` to
 * `PackageServiceProvider::register()`'s `$staticAddons` is a one-word change that would register
 * 27 web routes and an API route, and the disposition "there was nothing to rewrite" would become
 * false with nothing saying so. This file pins it.
 *
 * -------------------------------------------------------------------------------------------------
 *  "LOADED" AND "REACHABLE" ARE TWO DIFFERENT CLAIMS, AND BOTH ARE MEASURED
 * -------------------------------------------------------------------------------------------------
 *
 * CLAUDE.md: a module is loaded and reachable by two different mechanisms. `LynomiaChat` and
 * `LandingPage` are in `$staticAddons`, so their providers, routes and views resolve with
 * `add_on_managers` EMPTY; every other module loads only if a row exists. What the row additionally
 * controls is REACHABILITY: `ActivatedModule()` intersects the owner's activations with
 * `Module::getByStatus(1)`, so a merchant with that table empty resolves nothing and every gate
 * answers `redirect()->route('dashboard')`.
 *
 * AND THAT REDIRECT IS WHY THIS FILE ASSERTS THE LANDED PATH AND NEVER THE STATUS. A refused
 * module gate lands on the dashboard with **HTTP 200**, which a status-code assertion photographs
 * as the screen — so `assertOk()` on a Helpdesk URL would "pass" against a module that is not
 * there at all. Each case below asserts either the route's EXISTENCE in the router or the
 * destination of the redirect.
 */
class SupportDeskReachabilityTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        (new User)->flushCache();
    }

    /** Route names the vendored Helpdesk declares in its own `routes/web.php`. */
    private const HELPDESK_ROUTE_NAMES = [
        'helpdesk.category',
        'helpdesk.category.create',
        'helpdesk.status',
    ];

    // =============================================================================================
    //  THE VENDORED HELPDESK — DECLARED, NOT LOADED
    // =============================================================================================

    /**
     * THE MODULE'S FILES EXIST. Half one of the two claims, asserted so that "the Helpdesk
     * contributes nothing" is never mistaken for "there is no Helpdesk" — the module is real, it has
     * four tables, four models and three controllers, and a future decision to activate it has
     * something to activate. Deleting it is a different decision from not loading it.
     */
    public function test_the_vendored_helpdesk_module_is_present_on_disk(): void
    {
        $root = base_path('packages/workdo/Helpdesk');

        $this->assertDirectoryExists($root, 'the vendored Helpdesk module is gone from the tree');
        $this->assertFileExists($root . '/src/routes/web.php');
        $this->assertFileExists($root . '/src/app/Providers/HelpdeskServiceProvider.php');

        // It declares routes in its own file. The next case is that none of them is registered.
        $this->assertStringContainsString(
            'helpdesk/category',
            (string) file_get_contents($root . '/src/routes/web.php'),
            'the Helpdesk route file no longer declares the routes this file reasons about'
        );
    }

    /**
     * THE LOAD-BEARING MEASUREMENT: NONE OF THOSE ROUTES IS REGISTERED.
     *
     * `Helpdesk` is not in `PackageServiceProvider::register()`'s `$staticAddons` (which holds only
     * `LandingPage` and `LynomiaChat`), and `add_on_managers` is empty under `RefreshDatabase`, so
     * `loadDynamicAddons()` registers nothing — the provider never boots and its `RouteServiceProvider`
     * never maps the file. So the merchant-to-operator desk is not a surface any principal can reach,
     * whatever its files contain, and `SUPPORT-003`'s "there was nothing to rewrite" holds.
     *
     * ASSERTED THROUGH THE ROUTER, not through an HTTP request. A GET to `helpdesk/category` would
     * answer 404 both when the route is absent AND when it exists behind a refused gate that
     * aborts — and a refused module gate answers 200 on the dashboard, so neither status
     * distinguishes the two. `Route::has()` asks the question directly.
     */
    public function test_the_vendored_helpdesk_registers_no_routes_with_the_module_table_empty(): void
    {
        $this->assertSame(
            0,
            \DB::table('add_on_managers')->count(),
            'PREMISE: add_on_managers is not empty, so this case is not measuring the default state'
        );

        foreach (self::HELPDESK_ROUTE_NAMES as $name) {
            $this->assertFalse(
                Route::has($name),
                "the vendored Helpdesk route {$name} is registered. SUPPORT-003's disposition -- "
                . 'KEEP BOTH AND FIX IN PLACE, because the vendored desk contributes 0 runtime '
                . 'routes and there was nothing to rewrite -- rests on it contributing none. If '
                . 'Helpdesk has been added to $staticAddons or activated by default, that '
                . 'disposition needs re-taking: its tables carry no store_id at all '
                . '(helpdesk_tickets has category_id/status_id and no tenant column), so every '
                . 'route it registers is an unscoped cross-tenant surface.'
            );
        }

        // And the premise for the claim itself: the static list does not name it.
        $provider = (string) file_get_contents(app_path('Providers/PackageServiceProvider.php'));
        $this->assertStringContainsString("\$staticAddons = ['LandingPage', 'LynomiaChat']", $provider,
            'the $staticAddons list has changed; re-measure which modules load with the table empty');
    }

    /**
     * THE TENANCY FACT THAT MAKES THE ABOVE MATTER, measured from the migration rather than argued.
     *
     * `helpdesk_tickets` has no `store_id` column, so the vendored desk could not be tenant-scoped
     * even if it were activated — there is no discriminator to scope on. That is the single
     * strongest reason `SUPPORT-003`'s review did not adopt it as the platform desk, and it is
     * asserted here so a future "just turn Helpdesk on" is confronted with it.
     */
    public function test_the_vendored_helpdesk_schema_carries_no_tenant_column(): void
    {
        $migration = (string) file_get_contents(base_path(
            'packages/workdo/Helpdesk/src/database/migrations/2024_09_19_091732_create_helpdesk_tickets_table.php'
        ));

        $this->assertStringNotContainsString("'store_id'", $migration,
            'helpdesk_tickets now declares a store_id; the vendored desk may be scopable after all '
            . 'and SUPPORT-003\'s disposition can be revisited');
        $this->assertStringNotContainsString('store_id', $migration,
            'helpdesk_tickets now mentions store_id');
    }

    // =============================================================================================
    //  THE FIRST-PARTY DESK — LOADED AND REACHABLE
    // =============================================================================================

    /**
     * THE OTHER HALF OF `SUPPORT-003`: the first-party customer-to-merchant desk IS the live one.
     *
     * Its routes are declared in `routes/web.php` and `routes/customer.php`, not in a module, so
     * they do not depend on `add_on_managers` at all. That is why the review's verdict was to fix
     * this desk in place rather than replace either.
     */
    public function test_the_first_party_support_desk_routes_are_registered(): void
    {
        foreach (['support_ticket.index', 'support_ticket.edit', 'support_ticket.destroy',
                  'support_ticket.status.change'] as $name) {
            $this->assertTrue(
                Route::has($name),
                "the first-party support desk route {$name} is gone. SUPPORT-003's verdict was KEEP "
                . 'BOTH AND FIX IN PLACE; if this desk has been replaced, the decision requires the '
                . 'replacement to have been reviewed against it first.'
            );
        }

        // The shopper's side of the same desk.
        foreach (['support.ticket', 'add.support.ticket', 'ticket.reply'] as $name) {
            $this->assertTrue(Route::has($name), "the shopper support route {$name} is gone");
        }
    }

    /**
     * AND IT IS REACHABLE BY A MERCHANT WHO HOLDS THE PERMISSION — asserted by the LANDED PATH.
     *
     * This is the case CLAUDE.md's module trap would make meaningless if it asserted a status: a
     * merchant refused by a module gate lands on the dashboard with HTTP 200, so `assertOk()` here
     * would pass against a desk nobody can open. The permitted case asserts the response is NOT a
     * redirect away, and the refused case asserts the error reason — so the two are distinguishable.
     */
    public function test_a_merchant_holding_the_permission_reaches_the_ticket_list(): void
    {
        $this->grantPermission($this->merchant, 'Manage Support Ticket');
        (new User)->flushCache();

        $response = $this->actingAs($this->merchant)->get(route('support_ticket.index'));

        $this->assertFalse(
            $response->isRedirect(),
            'a merchant holding Manage Support Ticket was redirected away from the ticket list. '
            . 'Landed at: ' . ($response->headers->get('Location') ?? '(no location)')
        );
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * THE REFUSAL IS KEYED ON ITS REASON. A merchant WITHOUT the permission is sent back with
     * "Permission denied." — which is `SupportTicketController::index`'s own branch, not a module
     * gate's dashboard redirect. Keying on the message is what distinguishes them.
     */
    public function test_a_merchant_without_the_permission_is_refused_by_the_desks_own_branch(): void
    {
        $response = $this->actingAs($this->merchant)->get(route('support_ticket.index'));

        $response->assertRedirect();
        $this->assertSame(
            'Permission denied.',
            (string) session('error'),
            'the refusal did not come from SupportTicketController::index\'s own permission branch; '
            . 'a module gate\'s dashboard redirect and this refusal are both 302 and opposite facts'
        );
    }

    /**
     * THE SCOPED-MODEL REMINDER, ASSERTED. `SupportTicket` and `SupportConversion` are in
     * `config/tenancy.php`'s `scoped_models` and really filter, so a bare `SupportTicket::find()`
     * in a test answers against whichever tenant happens to be resolved rather than against the
     * table — and an "it is gone" assertion passes whether or not a fix works. CLAUDE.md records
     * that this cost a real false reading on a cart test.
     *
     * Pinned here because every future test on this desk depends on it, and because the claim is
     * exactly the kind CLAUDE.md records being wrong in both directions.
     */
    public function test_the_support_desk_models_really_do_filter_by_tenant(): void
    {
        $scoped = (array) config('tenancy.scoped_models', []);

        $this->assertContains(SupportTicket::class, $scoped,
            'SupportTicket left scoped_models -- StoreScope::isActiveFor() short-circuits for a '
            . 'model that is not listed, so it now adds NO predicate for ANY principal, and every '
            . 'bare find() on this desk reaches every tenant');
        $this->assertContains(\App\Models\SupportConversion::class, $scoped);

        $this->assertArrayHasKey(
            \App\Scopes\StoreScope::class,
            (new SupportTicket)->getGlobalScopes(),
            'SupportTicket no longer registers StoreScope; being in scoped_models is not enough on '
            . 'its own -- CLAUDE.md records that both checks are needed'
        );
    }
}
