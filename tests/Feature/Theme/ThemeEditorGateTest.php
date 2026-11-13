<?php

namespace Tests\Feature\Theme;

use App\Models\Store;
use App\Models\User;
use App\Support\Theme\ThemeEditorGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M3-C — WHO MAY TOUCH A STORE'S THEME CONFIGURATION.
 *
 * THE DEFECT THIS FILE PINS AGAINST, verbatim from `app/Helper/helper.php`:
 *
 *     if ($user->type == 'super admin') { return 1; }   // "Default store for super admin"
 *
 * `getCurrentStore()` hands a platform operator with an empty `current_store` the literal store 1 —
 * and store 1 belongs to a MERCHANT. Every database constraint M3-B added is satisfied by that
 * write, because store 1 is a real store; a publish performed that way would re-point a tenant's
 * live storefront while appearing to act on the operator's own. No schema can catch it. This gate
 * is the thing that does, so these assertions are the control of record.
 *
 * THE OTHER HALF IS AS IMPORTANT AND EASIER TO GET WRONG. Laratrust runs with
 * `permissions_as_gates => false`, so `@can()` and `$user->can()` are ALWAYS false and `isAbleTo()`
 * alone REFUSES the operator — a bare permission check is dead on arrival for the one principal most
 * likely to need a support rollback. And measured at M3-A, `Manage Themes` is held by NO role and NO
 * user, while `Edit Theme` does not exist as a permission at all. A gate narrowed to those would
 * lock every merchant out of their own editor, which is a worse failure than the one being fixed.
 * So ownership of the store entitles, permissions gate staff, and the operator is tested first.
 */
class ThemeEditorGateTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;
    private User $merchantA;
    private User $merchantB;
    private User $staffA;
    private Store $storeA;
    private Store $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::forceCreate([
            'name' => 'Platform Operator', 'email' => 'gate-op@example.test',
            'password' => bcrypt('x'), 'type' => 'super admin',
            'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $this->merchantA = User::forceCreate([
            'name' => 'Merchant A', 'email' => 'gate-a@example.test',
            'password' => bcrypt('x'), 'type' => 'admin',
            'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $this->merchantB = User::forceCreate([
            'name' => 'Merchant B', 'email' => 'gate-b@example.test',
            'password' => bcrypt('x'), 'type' => 'admin',
            'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $this->storeA = Store::create([
            'name' => 'Gate A', 'email' => 'gate-store-a@example.test', 'slug' => 'gate-store-a',
            'theme_id' => 'stylique', 'created_by' => $this->merchantA->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);

        $this->storeB = Store::create([
            'name' => 'Gate B', 'email' => 'gate-store-b@example.test', 'slug' => 'gate-store-b',
            'theme_id' => 'greentic', 'created_by' => $this->merchantB->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);

        /* Staff of merchant A: `creatorId()` resolves to A for a non-admin row. */
        $this->staffA = User::forceCreate([
            'name' => 'Staff A', 'email' => 'gate-staff-a@example.test',
            'password' => bcrypt('x'), 'type' => 'staff',
            'created_by' => $this->merchantA->id, 'email_verified_at' => now(),
        ]);
    }

    private function gate(): ThemeEditorGate
    {
        return new ThemeEditorGate();
    }

    /* ======================================================= THE OPERATOR MUST NAME A STORE */

    #[Test]
    public function test_an_operator_with_no_store_named_is_refused_and_does_not_get_store_one(): void
    {
        $gate = $this->gate();

        $this->assertNull($gate->resolveStore(null, $this->operator),
            'An operator naming no store was handed one. getCurrentStore() returns the literal 1 '
            . 'here, which belongs to a merchant.');
        $this->assertFalse($gate->allows(null, $this->operator));
        $this->assertStringContainsString('never infer', (string) $gate->reason());
    }

    #[Test]
    public function test_the_self_service_resolver_refuses_an_operator_without_a_current_store(): void
    {
        $gate = $this->gate();
        $this->operator->current_store = null;

        $this->assertNull($gate->storeIdForSelfService($this->operator),
            'storeIdForSelfService() is getCurrentStore() WITHOUT the operator branch. Returning '
            . 'anything here reinstates the literal store 1.');
        $this->assertStringContainsString('must name the store explicitly', (string) $gate->reason());
    }

    #[Test]
    public function test_an_operator_who_names_a_store_may_act_on_it(): void
    {
        $gate = $this->gate();

        $this->assertNotNull($gate->resolveStore($this->storeA->id, $this->operator));
        $this->assertTrue($gate->allows($this->storeA->id, $this->operator),
            'The operator must be able to act on a named store -- isAbleTo() alone would refuse '
            . 'them, which is why the operator is tested FIRST.');
    }

    #[Test]
    public function test_an_operator_with_a_current_store_still_resolves_it(): void
    {
        $gate = $this->gate();
        $this->operator->current_store = $this->storeB->id;

        $this->assertSame($this->storeB->id, $gate->storeIdForSelfService($this->operator),
            'An explicitly selected store is still honoured; only the DEFAULT is removed.');
    }

    /* ============================================================== CROSS-TENANT IS REFUSED */

    #[Test]
    public function test_a_merchant_cannot_resolve_another_merchants_store(): void
    {
        $gate = $this->gate();

        $this->assertNull($gate->resolveStore($this->storeB->id, $this->merchantA),
            "Merchant A resolved merchant B's store.");
        $this->assertFalse($gate->allows($this->storeB->id, $this->merchantA));
        $this->assertStringContainsString('does not own it', (string) $gate->reason());
    }

    #[Test]
    public function test_a_merchant_can_act_on_their_own_store(): void
    {
        $gate = $this->gate();

        $this->assertSame($this->storeA->id, $gate->resolveStore($this->storeA->id, $this->merchantA)?->id);
        $this->assertTrue($gate->allows($this->storeA->id, $this->merchantA),
            'A merchant must be able to edit their own storefront. Narrowing this to a permission '
            . 'that no role holds would lock every merchant out.');
    }

    #[Test]
    public function test_staff_of_a_merchant_resolve_to_the_same_tenant_root(): void
    {
        $gate = $this->gate();

        $this->assertSame($this->storeA->id, $gate->resolveStore($this->storeA->id, $this->staffA)?->id,
            "Staff of merchant A could not resolve A's store; creatorId() should resolve to A.");
        $this->assertNull($gate->resolveStore($this->storeB->id, $this->staffA),
            "Staff of merchant A resolved merchant B's store.");
    }

    /* ======================================================= NULL AND MALFORMED STORE IDS */

    #[Test]
    public function test_an_unauthenticated_caller_is_refused(): void
    {
        $gate = $this->gate();

        $this->assertNull($gate->resolveStore($this->storeA->id, null));
        $this->assertFalse($gate->allows($this->storeA->id, null));
        $this->assertSame('no authenticated user', $gate->reason());
    }

    #[Test]
    public function test_a_zero_or_empty_store_id_is_refused(): void
    {
        foreach ([null, '', '0', 0] as $bad) {
            $gate = $this->gate();

            $this->assertNull($gate->resolveStore($bad, $this->merchantA),
                'Store id ' . var_export($bad, true) . ' was accepted. `empty("0")` is true in PHP '
                . 'and store 0 exists in no table -- the same falsy trap that made a store with '
                . 'slug "0" unreachable in M0.');
        }
    }

    #[Test]
    public function test_a_non_numeric_store_id_is_refused_before_it_reaches_the_query(): void
    {
        $gate = $this->gate();

        $this->assertNull($gate->resolveStore((string) $this->storeA->id . 'abc', $this->merchantA),
            "WHERE id = '5abc' returns row 5 on MySQL and no row on SQLite (P1-INV-14), so a "
            . 'non-numeric id must be refused before the query, not by it.');
        $this->assertStringContainsString('not numeric', (string) $gate->reason());
    }

    #[Test]
    public function test_a_store_that_does_not_exist_is_refused(): void
    {
        $gate = $this->gate();

        $this->assertNull($gate->resolveStore(987654321, $this->operator));
        $this->assertFalse($gate->allows(987654321, $this->operator));
    }

    /* ======================================================= THE OPERATOR STRING IS NORMALISED */

    #[Test]
    public function test_an_operator_recorded_with_odd_casing_is_still_the_operator(): void
    {
        foreach (['Super Admin', 'SUPER ADMIN', ' super admin '] as $spelling) {
            $odd = User::forceCreate([
                'name' => 'Odd ' . $spelling, 'email' => 'odd-' . md5($spelling) . '@example.test',
                'password' => bcrypt('x'), 'type' => $spelling,
                'created_by' => 1, 'email_verified_at' => now(),
            ]);

            $gate = $this->gate();

            $this->assertNotNull($gate->resolveStore($this->storeA->id, $odd),
                "An operator recorded as '{$spelling}' was treated as a merchant. Every type "
                . 'predicate in this phase normalises with strtolower(trim()); helper.php uses a '
                . 'bare `==` and is the odd one out.');
        }
    }

    /* ====================================== A DEMOTED MERCHANT DOES NOT BECOME EVERY TENANT */

    /**
     * `creatorId()` returns `created_by` for a non-admin row, and every merchant has
     * `created_by = 1`. A merchant who demotes their own `type` through the product therefore drops
     * to a root of 1 -- which was a real account-takeover here
     * (`docs/security/P0-USER-DELETION-CASCADE.md` §7). This asserts the demotion buys nothing: a
     * root of 1 matches store 1 only, not every store.
     */
    #[Test]
    public function test_a_merchant_demoted_to_a_root_of_one_does_not_gain_every_store(): void
    {
        $this->merchantA->type = 'staff';          // creatorId() now returns created_by = 1
        $this->merchantA->save();

        $gate = $this->gate();

        $this->assertNull($gate->resolveStore($this->storeB->id, $this->merchantA->fresh()),
            "A demoted merchant resolved another tenant's store. A root of 1 is store 1's owner at "
            . 'most, never a universal tenant.');
    }
}
