<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\ModuleAdministrativeRouteGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P1-SEC-04 -- the guard's two primitives, driven directly.
 *
 * `sameTenant()` and `usableId()` decide every ownership rule, and the inputs that matter most -- a user with NO tenant, an id that is only
 * a numeric PREFIX, an id that names no user -- are not reachable through a route without an unrelated layer (a plan check, a handler error)
 * refusing first. Driving them directly is the only way to show each refusal is THIS code's.
 */
class ModuleAdministrativeRouteGuardRulesTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function invoke(string $method, ...$args): mixed
    {
        $m = new \ReflectionMethod(ModuleAdministrativeRouteGuard::class, $method);
        $m->setAccessible(true);

        return $m->invoke(new ModuleAdministrativeRouteGuard(), ...$args);
    }

    public function test_same_tenant_is_true_for_the_owner_and_their_staff_and_false_for_another_tenant(): void
    {
        [$owner] = $this->makeTenant();
        $staff = $this->makeStaff($owner);
        [$stranger] = $this->makeTenant();

        $this->assertTrue($this->invoke('sameTenant', $staff, $owner->id), 'staff -> owner');
        $this->assertTrue($this->invoke('sameTenant', $owner, (string) $staff->id), 'owner -> staff, id as a digit string');
        $this->assertTrue($this->invoke('sameTenant', $owner, $owner->id), 'a user is in their own tenant');
        $this->assertFalse($this->invoke('sameTenant', $owner, $stranger->id), 'owner -> stranger');
        $this->assertFalse($this->invoke('sameTenant', $staff, $stranger->id), 'staff -> stranger');
    }

    public function test_a_user_with_no_tenant_is_nobodys_colleague_not_even_another_tenantless_user(): void
    {
        $a = \App\Models\User::factory()->create(['type' => 'staff', 'created_by' => null, 'is_active' => 1]);
        $b = \App\Models\User::factory()->create(['type' => 'staff', 'created_by' => null, 'is_active' => 1]);
        $this->assertSame(0, (int) $a->creatorId(), 'Premise: the fixture user really has no tenant.');
        $this->assertSame(0, (int) $b->creatorId());

        $this->assertFalse($this->invoke('sameTenant', $a, $b->id), 'two tenantless users compared equal as (int) 0 === (int) 0');
        $this->assertFalse($this->invoke('sameTenant', $a, $a->id), 'a tenantless user is not even in their own tenant');
    }

    public function test_same_tenant_refuses_ids_that_name_nobody_or_only_look_like_a_user(): void
    {
        [$owner] = $this->makeTenant();

        foreach ([null, 0, '0', -1, '-1', '', 'abc', 'x' . $owner->id, $owner->id . 'abc', ' ' . $owner->id, $owner->id . ' ', 987654321, '987654321', [$owner->id], true, 1.0] as $bad) {
            $this->assertFalse($this->invoke('sameTenant', $owner, $bad), 'accepted ' . var_export($bad, true));
        }
    }

    public function test_usable_id_accepts_only_a_positive_decimal_integer(): void
    {
        $this->assertSame(5, $this->invoke('usableId', 5));
        $this->assertSame(5, $this->invoke('usableId', '5'));
        $this->assertSame(123456789012345678, $this->invoke('usableId', '123456789012345678'));

        foreach ([0, '0', -5, '-5', '', ' ', '5abc', ' 5', '5 ', '5.0', '0x5', '1e3', '+5', 5.0, 5.5, true, false, null, [5], '1234567890123456789'] as $bad) {
            $this->assertNull($this->invoke('usableId', $bad), 'accepted ' . var_export($bad, true));
        }
    }
}
