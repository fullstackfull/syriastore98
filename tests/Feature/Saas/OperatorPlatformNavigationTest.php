<?php

namespace Tests\Feature\Saas;

use App\Classes\Menu;
use App\Events\CompanyMenuEvent;
use App\Events\SuperAdminMenuEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ADM-001 / GAP-231 — EVERY PLATFORM-WIDE OPERATOR SCREEN IS REACHABLE FROM THE NAVIGATION.
 * =================================================================================================
 *
 * The recovered decision is `لوحة قيادة شاملة للمنصة` — a COMPREHENSIVE platform command
 * dashboard. Whether the dashboard that exists is the one the plan describes is a product
 * judgement this repository cannot settle, and `GAP-231` puts that question to the owner. What is
 * NOT a judgement, and is what this file asserts, is the structural half: *comprehensive* cannot
 * be true of a command surface whose instruments are not on it.
 *
 * AND THEY WERE NOT. Measured before this file existed: `SuperAdminMenuListener` declared TEN
 * entries — dashboard, add-on-manager, users, coupons, plan, plan request, email templates,
 * settings, country, currency — and NOT ONE of the operator's five cross-tenant `platform/*`
 * screens. Analytics, revenue, resources, documentation and site-content were reachable only from
 * a link panel inside the dashboard body, which `OperatorDashboardPlatformLinksTest` added for
 * exactly this reason and recorded as a CROSS-AGENT DEPENDENCY in its own docblock:
 *
 *   > WHY THE DASHBOARD AND NOT THE SIDEBAR. The operator's navigation is built by
 *   > `SuperAdminMenuListener` and `MerchantPageDirectory`, neither of which belongs to this
 *   > workstream in this phase. [...] a sidebar entry is recorded as a cross-agent dependency
 *   > rather than smuggled into somebody else's file.
 *
 * ADM-001 is the requirement that dependency was deferred against, so this is where it is paid.
 *
 * ============================================================================================
 *  THE CENSUS ENUMERATES THE SCREENS FROM THE ROUTE TABLE, NOT FROM A LIST TYPED HERE.
 * ============================================================================================
 *
 * CLAUDE.md: *WHEN A CENSUS HUNTS FOR SOMETHING DANGEROUS, ENUMERATE THE SAFE SET INSTEAD. A list
 * of dangerous spellings is always short by the one nobody thought of.* A hard-coded list of six
 * screen names would be satisfied forever by whatever was true the day it was written, and the
 * NEXT operator screen would ship unreachable exactly as these five did.
 *
 * AND IT DID EXACTLY THAT, which is the only evidence that the mechanism works. On 2026-11-27 this
 * file was red on FOUR screens at once -- `kyc.queue`, `platform.approvals.index`,
 * `platform.incidents.index` and `platform.operations` -- each built, gated and tested by a
 * different workstream, and not one of them had a navigation entry. No list typed into this file
 * would have named them. NAV-004 added the four entries; nothing in this file changed to make it
 * pass, and the counted nouns in the prose below were corrected to say "every entry" instead,
 * because a number in a comment beside a computed set is the next thing to go stale.
 *
 * So the subject set is computed: every GET route that carries `PlatformOperatorOnly`, whose uri
 * is `platform/<one segment>` with no route parameter. That shape is a top-level operator screen.
 * It deliberately excludes `platform/analytics/export` (three segments — a download reached from
 * the screen it belongs to), `platform/documentation/{category}/{article}` and
 * `platform/stores/{store}` (parameterised sub-pages, which have no standalone address to put in
 * a sidebar), and the `POST` writer on `platform/site-content/{page}`.
 *
 * A NEW SCREEN OF THAT SHAPE JOINS THE CENSUS BY ITSELF AND REDDENS THIS FILE until somebody puts
 * it in the navigation. That is the whole mechanism.
 */
final class OperatorPlatformNavigationTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * Every `platform/<x>` GET screen behind the operator gate, computed from the booted route
     * table.
     *
     * @return array<string, string> route name => uri
     */
    private function operatorScreens(): array
    {
        $screens = [];

        foreach (RouteFacade::getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $carries = false;

            foreach (array_filter($route->gatherMiddleware(), 'is_string') as $middleware) {
                if (str_contains($middleware, 'PlatformOperatorOnly')) {
                    $carries = true;
                }
            }

            if (! $carries) {
                continue;
            }

            $uri = $route->uri();

            if (! str_starts_with($uri, 'platform/') || str_contains($uri, '{')) {
                continue;
            }

            if (count(explode('/', $uri)) !== 2) {
                continue;
            }

            $name = (string) $route->getName();

            if ($name === '') {
                continue;
            }

            $screens[$name] = $uri;
        }

        ksort($screens);

        return $screens;
    }

    /**
     * The operator's navigation, as `Menu::add()` really filtered it.
     *
     * THE LISTENER IS INVOKED THROUGH THE EVENT, not called directly, so whatever other provider
     * also listens for `SuperAdminMenuEvent` is in the answer too — the operator's real sidebar is
     * the union, and a census that read one listener could be satisfied while the rendered sidebar
     * differed.
     *
     * @return list<array<string, mixed>>
     */
    private function operatorMenu(User $operator): array
    {
        $this->actingAs(User::find($operator->id));

        $menu = new Menu(User::find($operator->id));
        event(new SuperAdminMenuEvent($menu));

        return $menu->menu;
    }

    /**
     * EVERY ENTRY THE OPERATOR'S LISTENERS *OFFER*, admitted or refused.
     *
     * WHY THIS EXISTS, AND IT IS A DEFECT FOUND IN THIS FILE'S OWN FIRST DRAFT BY MUTATION RATHER
     * THAN BY READING. `test_the_platform_entries_carry_no_permission_name` originally read
     * `$menu->menu` — the entries that SURVIVED `Menu::permits()`. Adding
     * `'permission' => 'Manage Store Analytics'` to the Store 360 entry was then invisible to it:
     * `isAbleTo()` refuses the platform operator for a name they have not been granted, so the
     * entry was DROPPED and an assertion that iterates what is present cannot see what is absent.
     * Measured — that mutant left the permission case GREEN and was caught only by the
     * reachability case next door.
     *
     * The pair therefore composed fail-closed, which is lucky rather than designed: in THIS
     * harness the operator holds no permission at all (`Utility::addNewData()` grants the
     * `super admin` role its 28 names on LOGIN, and no test logs in), so ANY permission name drops
     * the entry and the permission case could never bite. A test that cannot fail is not evidence.
     *
     * So the declarations are recorded at the seam instead: a `Menu` whose `add()` keeps every
     * entry it is handed. `SuperAdminMenuEvent::$menu` is untyped and the listeners only call
     * `add()`, so a subclass is a drop-in and the union across listeners is preserved.
     *
     * @return list<array<string, mixed>>
     */
    private function declaredOperatorMenu(User $operator): array
    {
        $this->actingAs(User::find($operator->id));

        $recorder = new class(User::find($operator->id)) extends Menu {
            /** @var list<array<string, mixed>> */
            public array $offered = [];

            public function add(array $array): void
            {
                $this->offered[] = $array;

                parent::add($array);
            }
        };

        event(new SuperAdminMenuEvent($recorder));

        return $recorder->offered;
    }

    /**
     * PREMISE: the census really found the screens. Without this every assertion below is
     * vacuously green on an empty set — the failure mode CLAUDE.md names first.
     */
    #[Test]
    public function test_premise_the_census_finds_the_operators_platform_screens(): void
    {
        $screens = $this->operatorScreens();

        $this->assertGreaterThanOrEqual(6, count($screens),
            'the census found almost no operator screens, so every assertion in this file is '
            . 'vacuous. Found: ' . implode(', ', array_keys($screens)));

        foreach ([
            'platform.analytics',
            'platform.resources',
            'platform.revenue',
            'platform.documentation.index',
            'platform.site_content.index',
            'platform.stores.index',
        ] as $expected) {
            $this->assertArrayHasKey($expected, $screens,
                $expected . ' is no longer a parameterless `platform/<x>` GET screen behind the '
                . 'operator gate. If it moved, the census shape below needs re-deriving.');
        }

        /* The exclusions are asserted too, so the rule cannot quietly widen and start demanding a
           sidebar entry for a download or a sub-page. */
        foreach (['platform.analytics.export', 'platform.stores.show'] as $excluded) {
            $this->assertArrayNotHasKey($excluded, $screens,
                $excluded . ' entered the census. A download and a parameterised sub-page have no '
                . 'standalone address to put in a sidebar.');
        }
    }

    #[Test]
    public function test_every_platform_wide_operator_screen_is_in_the_operators_navigation(): void
    {
        $operator = $this->makePlatformAdmin();
        $routes = array_column($this->operatorMenu($operator), 'route');

        $missing = [];

        foreach ($this->operatorScreens() as $name => $uri) {
            if (! in_array($name, $routes, true)) {
                $missing[] = $name . ' (/' . $uri . ')';
            }
        }

        $this->assertSame([], $missing,
            "These platform-wide operator screens exist and the operator's navigation does not "
            . "name them, so the only way to reach them is to type the uri:\n  "
            . implode("\n  ", $missing)
            . "\n\nADM-001 asks for a COMPREHENSIVE platform command surface. A built screen "
            . 'nobody can find is not delivered. Add an entry to SuperAdminMenuListener.');
    }

    /**
     * THE ENTRIES CARRY NO PERMISSION NAME, AND THAT IS THE CORRECT GATE RATHER THAN A SHORTCUT.
     *
     * `Menu::permits()` admits an entry when its module is active AND
     * (`$item['permission']` is empty OR `$user->isAbleTo($item['permission'])`). The screens
     * themselves are gated by IDENTITY (`PlatformOperatorOnly`), not by a permission — and there
     * is no super-admin bypass in this application's Laratrust setup, so `isAbleTo()` refuses the
     * platform operator for any name they have not been granted. An entry carrying a permission
     * name would therefore be capable of HIDING a screen the operator may certainly open, which is
     * the defect this file exists to prevent, in a subtler form.
     *
     * It is safe because the event itself is the gate: `getMenu()` fires `SuperAdminMenuEvent` only
     * for `type == 'super admin'`, and `MerchantPageDirectory` only for
     * `UserPolicy::isOperator()`. `add-on-manager` and `lynomia-integration` already ship this
     * way. The next case asserts the consequence rather than trusting the argument.
     *
     * READ FROM THE DECLARATIONS AND NOT FROM THE FILTERED MENU, which is the whole reason
     * `declaredOperatorMenu()` exists -- a mutant that added a permission name survived this case
     * until it did.
     */
    #[Test]
    public function test_the_platform_entries_carry_no_permission_name(): void
    {
        $operator = $this->makePlatformAdmin();
        $screens = array_keys($this->operatorScreens());

        $withPermission = [];

        /* THE DECLARATIONS, NOT THE SURVIVORS -- see `declaredOperatorMenu()` for the mutant that
           proved the difference. An entry gated on a permission the operator lacks is absent from
           `$menu->menu` entirely, so reading that array cannot see the thing this case is about. */
        foreach ($this->declaredOperatorMenu($operator) as $item) {
            if (! in_array((string) ($item['route'] ?? ''), $screens, true)) {
                continue;
            }

            if (! empty($item['permission'])) {
                $withPermission[] = $item['name'] . ' => ' . $item['permission'];
            }
        }

        $this->assertSame([], $withPermission,
            'a platform-screen navigation entry is gated on a permission name. isAbleTo() refuses '
            . 'the platform operator unless the name has been granted, so this entry can hide a '
            . "screen the operator may certainly open:\n  " . implode("\n  ", $withPermission));
    }

    /**
     * AND THEY DO NOT LEAK INTO A MERCHANT'S NAVIGATION. The permission-less entries above are
     * safe only because the EVENT is operator-only, so that is the thing asserted: a merchant
     * receives `CompanyMenuEvent`, and none of these routes may appear in what it builds.
     */
    #[Test]
    public function test_no_platform_screen_leaks_into_a_merchant_navigation(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);
        $merchant->current_store = $store->id;
        $merchant->save();
        (new User)->flushCache();

        $this->actingAs(User::find($merchant->id));

        $menu = new Menu(User::find($merchant->id));
        event(new CompanyMenuEvent($menu));

        $routes = array_column($menu->menu, 'route');
        $screens = array_keys($this->operatorScreens());

        $this->assertSame([], array_values(array_intersect($routes, $screens)),
            'a platform-operator screen appeared in a merchant navigation. Those entries carry no '
            . 'permission name, so the event branch is the only thing keeping them operator-only.');
    }

    /**
     * EVERY NAVIGATION ENTRY'S ROUTE RESOLVES. `menuTree()` calls `route($item['route'])` while
     * building the sidebar, so a name the router does not know throws inside the layout — on every
     * operator screen at once, not just the one that is wrong.
     */
    #[Test]
    public function test_every_operator_navigation_entry_names_a_registered_route(): void
    {
        $operator = $this->makePlatformAdmin();

        $unknown = [];

        foreach ($this->operatorMenu($operator) as $item) {
            $route = (string) ($item['route'] ?? '');

            if ($route === '') {
                continue;
            }

            if (! RouteFacade::has($route)) {
                $unknown[] = $item['name'] . ' => ' . $route;
            }
        }

        $this->assertSame([], $unknown,
            "An operator navigation entry names a route the router does not know. `menuTree()` "
            . "calls route() on it, so this throws inside the layout on every operator screen:\n  "
            . implode("\n  ", $unknown));
    }

    /**
     * AND IT IS REALLY IN THE RENDERED SIDEBAR, not only in the `Menu` object.
     *
     * This is the end-to-end arm, and it is a different claim from every case above: `getMenu()`
     * builds the menu, `menuTree()` reshapes it and `partision/menu-tree.blade.php` renders it, and
     * the renderer DROPS entries of its own accord — `mobilescreensetting` is skipped unless
     * `config('platform.is_mobile')` is `'yes'`, so "in the menu" and "on the screen" are not the
     * same statement. A group could be in `$menu->menu` and absent from the page.
     *
     * `dash-submenu` IS ASSERTED TOO, because every entry in the group is a CHILD: if the parent lost its
     * children they would each render as a flat top-level item and the group would be gone while
     * every other assertion in this file stayed green.
     */
    #[Test]
    public function test_the_group_is_in_the_rendered_sidebar_and_not_only_in_the_menu_object(): void
    {
        config(['app.debug' => false]);

        $operator = $this->makePlatformAdmin();
        $this->makeStore($operator, ['default_language' => 'en']);
        (new User)->flushCache();
        \Illuminate\Support\Facades\Cache::forget('admin_details');

        $body = (string) $this->actingAs(User::find($operator->id))->get('/dashboard')->getContent();

        $this->assertStringContainsString('Across the platform', $body,
            'the platform group is not in the rendered operator sidebar.');

        /*
         * SCOPED TO THE GROUP'S OWN BLOCK, AND THIS IS THE SECOND DEFECT MUTATION FOUND IN THIS
         * FILE RATHER THAN READING. The first version asserted `dash-submenu` appeared ANYWHERE in
         * the page. Re-pointing every child to `'parent' => null` — which flattens the group
         * out of existence — left that assertion GREEN, because three other sidebar groups already
         * render a `dash-submenu` and the string merely existed somewhere. CLAUDE.md's measured
         * example of exactly this: a mutation dropping one component's 44px target survived
         * because another component had the same rule.
         *
         * `menuTree()` renders a TOP-LEVEL item as `dash-item dash-hasmenu` and a child as
         * `dash-item`, so the next top-level item is the end of this group's block. Everything the
         * group must contain is asserted INSIDE that slice.
         */
        $start = strpos($body, 'Across the platform');
        $this->assertNotFalse($start);

        $next = strpos($body, 'dash-item dash-hasmenu', $start);
        $block = $next === false ? substr($body, $start) : substr($body, $start, $next - $start);

        $this->assertStringContainsString('dash-submenu', $block,
            'the platform group rendered without a submenu of its own, so its entries are no '
            . 'longer its children and the sidebar is that many flat items instead.');

        foreach ($this->operatorScreens() as $name => $uri) {
            $this->assertStringContainsString('href="' . route($name) . '"', $block,
                $name . ' is not inside the platform group in the rendered sidebar.');
        }
    }

    /**
     * THE OPERATOR CAN REALLY OPEN EVERY SCREEN THE NAVIGATION OFFERS THEM. A sidebar that offers
     * what the request refuses is the failure `MerchantQuickActionTargetsTest` names: the render
     * being more permissive than the handler. Here the two are checked against each other.
     */
    #[Test]
    public function test_the_operator_can_open_every_platform_screen_the_navigation_offers(): void
    {
        $operator = $this->makePlatformAdmin();
        $this->makeStore($operator);
        (new User)->flushCache();
        \Illuminate\Support\Facades\Cache::forget('admin_details');

        foreach ($this->operatorScreens() as $name => $uri) {
            $status = $this->actingAs(User::find($operator->id))->get('/' . $uri)->getStatusCode();

            $this->assertSame(200, $status,
                $name . ' is offered in the operator navigation and answered HTTP ' . $status
                . ' for the operator.');
        }
    }
}
