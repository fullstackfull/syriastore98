<?php

namespace Tests\Feature\Lynomia;

use App\Classes\Menu;
use App\Events\CompanyMenuEvent;
use App\Events\SuperAdminMenuEvent;
use App\Facades\ModuleFacade as Module;
use App\Models\AddOnManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Listeners\CompanyMenuListener;
use Workdo\LynomiaChat\app\Listeners\SuperAdminMenuListener;
use Workdo\LynomiaChat\app\Providers\EventServiceProvider;

/**
 * The Super Admin "Lynomia Chat Integration" entry must actually appear.
 *
 * THE BUG THIS PINS. App\Classes\Menu::add() keeps an item only when its `module` is in the
 * signed-in principal's module list. For a super admin that list is
 * ActivatedModule() -> Module::getByStatus(1): the add-ons registered AND enabled in
 * `add_on_managers`. Lynomia Chat is a bundled platform integration, not a purchasable add-on,
 * so production has no enabled row for it -- and the listener's old `module => 'LynomiaChat'`
 * was therefore filtered out and the Super Admin never saw the page. The fix is
 * `module => 'Base'`, the core module Menu appends to every list unconditionally.
 *
 * NO bootLynomiaHarness() HERE, AND THAT IS THE POINT. That harness writes an ENABLED
 * add_on_managers row for 'LynomiaChat', which is exactly the condition production does NOT have;
 * using it would make 'LynomiaChat' resolve and hide the bug. These tests stand the module list
 * up the way production really has it, so they fail on the old value and pass on the fix.
 */
class LynomiaSuperAdminMenuTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create([
            'type' => 'super admin',
            'created_by' => null,
            'is_active' => 1,
            'email_verified_at' => now(),
        ]);
    }

    private function merchant(): User
    {
        return User::factory()->create([
            'type' => 'admin',
            'created_by' => 1,
            'is_active' => 1,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * The entries the SuperAdminMenuListener produces for a signed-in super admin, through the
     * real Menu class so Menu::add()'s module gate is exercised. actingAs first, because Menu
     * reads ActivatedModule(), which resolves the SIGNED-IN user rather than taking an argument.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function superAdminMenu(User $user): \Illuminate\Support\Collection
    {
        $this->actingAs($user->fresh());
        $menu = new Menu($user->fresh());
        (new SuperAdminMenuListener())->handle(new SuperAdminMenuEvent($menu));

        return collect($menu->menu);
    }

    public function test_the_integration_entry_appears_for_a_super_admin_even_with_no_lynomia_add_on_enabled(): void
    {
        // Production's real state: Lynomia Chat is not an enabled add-on, and some OTHER add-on
        // is, so the module list is non-empty but does not contain 'LynomiaChat'. The old
        // 'LynomiaChat' value is filtered out here; 'Base' survives.
        AddOnManager::updateOrCreate(['module' => 'LandingPage'], [
            'name' => 'Landing Page', 'monthly_price' => 0, 'yearly_price' => 0,
            'is_enable' => 1, 'package_name' => 'landing-page',
        ]);
        $this->assertNotContains(
            'LynomiaChat',
            Module::getByStatus(1),
            'the test must reproduce production, where Lynomia Chat is NOT an enabled add-on'
        );

        $entry = $this->superAdminMenu($this->superAdmin())->firstWhere('name', 'lynomia-integration');

        $this->assertNotNull($entry, 'the Super Admin has no Lynomia Chat Integration entry');
        $this->assertSame('lynomia.admin.index', $entry['route']);
        $this->assertSame('Base', $entry['module']);
    }

    public function test_the_integration_entry_appears_for_a_super_admin_with_an_empty_add_on_registry(): void
    {
        // The most minimal install: no add-ons at all. The module list is just ['Base'], and the
        // entry still has to be reachable -- the integration admin page is centralised and
        // always available to an operator.
        $this->assertSame([], Module::getByStatus(1));

        $this->assertNotNull($this->superAdminMenu($this->superAdmin())->firstWhere('name', 'lynomia-integration'));
    }

    public function test_the_integration_entry_is_not_duplicated(): void
    {
        $this->assertCount(
            1,
            $this->superAdminMenu($this->superAdmin())->where('name', 'lynomia-integration'),
            'the Lynomia Chat Integration entry is listed more than once'
        );
    }

    public function test_the_super_admin_entry_never_leaks_into_a_merchant_menu(): void
    {
        // The company side is a different event handled by a different listener; the Super Admin
        // entry must not appear for a merchant, and its 'Base' module must not change that.
        $merchant = $this->merchant();
        $this->actingAs($merchant->fresh());
        $menu = new Menu($merchant->fresh());
        (new CompanyMenuListener())->handle(new CompanyMenuEvent($menu));

        $this->assertNull(
            collect($menu->menu)->firstWhere('name', 'lynomia-integration'),
            'the Super Admin integration page leaked into a merchant menu'
        );
    }

    public function test_the_super_admin_listener_is_wired_only_to_the_super_admin_event(): void
    {
        // The guarantee above holds only because this listener answers the Super-Admin-only
        // event and nothing else. getMenu()/getSideMenu() dispatch SuperAdminMenuEvent solely
        // for a super admin, so 'Base' can never carry this entry into a merchant's navigation.
        $listen = (new EventServiceProvider(app()))->listens();

        $this->assertContains(SuperAdminMenuListener::class, $listen[SuperAdminMenuEvent::class] ?? []);
        $this->assertArrayNotHasKey(
            SuperAdminMenuListener::class,
            array_flip($listen[CompanyMenuEvent::class] ?? []),
            'the Super Admin menu listener must not handle the merchant menu event'
        );
    }
}
