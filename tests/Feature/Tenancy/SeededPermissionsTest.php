<?php

namespace Tests\Feature\Tenancy;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Utility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * THE SIX FINANCE PERMISSIONS MUST EXIST IN A FROM-SOURCE DATABASE.
 *
 * ==========================================================================================
 *  THE DEFECT. Six finance permission names are GATED ON but were never SEEDED:
 *
 *    routes/web.php:164                middleware 'permission:View Finance Dashboard'
 *    StoreFinanceController.php:19     isAbleTo('Edit Finance Payment Status')
 *    StoreFinanceController.php:71     isAbleTo('View Finance Dashboard')
 *    StoreFinanceController.php:315    isAbleTo('Add Finance Balance')
 *    StoreFinanceController.php:396    isAbleTo('Deduct Finance Balance')
 *    StoreFinanceController.php:494    isAbleTo('Request Finance Withdrawal')
 *    StoreFinanceController.php:624    isAbleTo('View Finance Withdrawal Requests')
 *
 *  Before the fix, `grep -c Finance app/Models/Utility.php` returned 0.
 *
 *  Utility::addNewData() is the ONLY permission-creation mechanism in this application. A
 *  permission absent from it cannot exist in a from-source database, so it can never be granted
 *  to any role through the role editor, and every route above is a permanent 403.
 * ==========================================================================================
 *
 * WHY THIS TEST USES THE REAL MECHANISM AND NOT firstOrCreate().
 *
 * A previous round had a suite mint a Permission row with firstOrCreate() that production never
 * creates. 125 tests went green while the underlying regression was untouched, because the test
 * had manufactured its own precondition. The lesson: a test must assert that the APPLICATION
 * produces the row, not that the test can produce it.
 *
 * So this class calls `Utility::addNewData()` -- unmodified production code, reached in
 * production from AuthenticatedSessionController.php:83 on every login and from
 * PermissionTableSeeder.php:119 during install -- and then asserts the rows exist. Nothing here
 * creates a Permission directly.
 *
 * THE TWO ROLES ARE ALSO CREATED THE WAY PRODUCTION CREATES THEM. addNewData() dereferences
 * `Role::where('name','LIKE','admin')->first()` and the super admin role without null checks, so
 * both must pre-exist. PermissionTableSeeder creates them with `Role::create(['name' => ...])` at
 * :58-64 and :110-115, and that is exactly the call used below -- not a fixture invention.
 *
 * WHY NOT RUN PermissionTableSeeder ITSELF, which would be stronger: it cannot complete on a
 * from-zero database. `stores.theme_id` is NOT NULL with no default
 * (2023_12_14_090259_create_stores_table.php:18) and PermissionTableSeeder.php:41 does
 * `Store::create([...])` without it:
 *
 *   SQLSTATE[23000]: NOT NULL constraint failed: stores.theme_id
 *
 * That is a separate, pre-existing bootstrap defect in database/seeders/, which is outside this
 * workstream's file ownership and is reported rather than worked around. It is the reason this
 * class invokes addNewData() directly instead of through the seeder, and that limitation is
 * stated here so the verification is not read as broader than it is.
 */
class SeededPermissionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The six names, exactly as the gates spell them. A mismatch in case or spacing is itself
     * the bug, because Laratrust matches on the literal string.
     *
     * @return array<string, array{0: string}>
     */
    public static function financePermissionNames(): array
    {
        return [
            'View Finance Dashboard' => ['View Finance Dashboard'],
            'Add Finance Balance' => ['Add Finance Balance'],
            'Deduct Finance Balance' => ['Deduct Finance Balance'],
            'Request Finance Withdrawal' => ['Request Finance Withdrawal'],
            'View Finance Withdrawal Requests' => ['View Finance Withdrawal Requests'],
            'Edit Finance Payment Status' => ['Edit Finance Payment Status'],
        ];
    }

    /**
     * Create the two roles addNewData() requires, using the same call PermissionTableSeeder uses,
     * then run the real mechanism.
     */
    private function runProductionSeedMechanism(): void
    {
        // PermissionTableSeeder.php:58-64 and :110-115.
        Role::create(['name' => 'super admin']);
        Role::create(['name' => 'admin']);

        Utility::addNewData();
    }

    /**
     * The control. If this fails, the mechanism did not run and every other assertion in this
     * class is vacuous.
     */
    public function test_the_seed_mechanism_runs_and_creates_permissions(): void
    {
        $this->assertSame(0, Permission::count(), 'Fixture precondition: a fresh database.');

        $this->runProductionSeedMechanism();

        $this->assertGreaterThan(
            100,
            Permission::count(),
            'Utility::addNewData() did not populate the permission table, so this class proves '
            . 'nothing about the finance names.'
        );
    }

    /**
     * THE ASSERTION AGENT 1 ASKED FOR: the row exists after the production mechanism runs.
     */
    #[DataProvider('financePermissionNames')]
    public function test_finance_permission_row_exists_after_the_real_mechanism_runs(string $name): void
    {
        $this->runProductionSeedMechanism();

        $this->assertDatabaseHas('permissions', [
            'name' => $name,
            'guard_name' => 'web',
        ]);
    }

    /**
     * The gate reads the name through Laratrust, so prove the row is reachable the way the
     * controller finds it rather than only that a matching string sits in the table.
     */
    #[DataProvider('financePermissionNames')]
    public function test_finance_permission_is_resolvable_the_way_the_gates_resolve_it(string $name): void
    {
        $this->runProductionSeedMechanism();

        $this->assertNotNull(
            Permission::where('name', $name)->where('guard_name', 'web')->first(),
            'Permission `' . $name . '` is not resolvable, so the gate on it can never be granted.'
        );
    }

    /**
     * IDEMPOTENCE. addNewData() runs on EVERY login, so a second run must not duplicate rows.
     * This is what makes the change safe to apply to the live database without a migration.
     */
    public function test_running_the_mechanism_twice_creates_no_duplicates(): void
    {
        $this->runProductionSeedMechanism();
        $afterFirst = Permission::count();

        Utility::addNewData();

        $this->assertSame(
            $afterFirst,
            Permission::count(),
            'A second run of addNewData() changed the permission count, so the mechanism is not '
            . 'idempotent and running it on the live database would duplicate rows.'
        );

        foreach (self::financePermissionNames() as [$name]) {
            $this->assertSame(
                1,
                Permission::where('name', $name)->count(),
                'Permission `' . $name . '` was duplicated by a second seed run.'
            );
        }
    }

    /**
     * The six are created but DELIBERATELY NOT granted to any role.
     *
     * Four of them move real money: Add and Deduct Finance Balance credit and debit the merchant
     * balance of record, and View Finance Withdrawal Requests plus Edit Finance Payment Status
     * drive payouts. Which role holds those by default is a privilege decision for the owner, not
     * something to infer from a route file, which is why they were added to $arrPermissions
     * (row creation only) rather than to $companyNewPermission or $superadminNewPermission (which
     * also call givePermission()).
     *
     * This test pins that decision so a later edit cannot quietly hand a money power to every
     * store admin. If the owner decides the grant SHOULD happen, this test is the thing that
     * should be updated, in the same change, on purpose.
     */
    public function test_finance_permissions_are_not_auto_granted_to_any_role(): void
    {
        $this->runProductionSeedMechanism();

        foreach (self::financePermissionNames() as [$name]) {
            foreach (Role::all() as $role) {
                $this->assertFalse(
                    $role->hasPermission($name),
                    'Role `' . $role->name . '` was auto-granted `' . $name . '`. Four of the six '
                    . 'finance permissions move real money; granting them by default is an owner '
                    . 'decision. Rows are created so they can be ticked in the role editor.'
                );
            }
        }
    }
}
