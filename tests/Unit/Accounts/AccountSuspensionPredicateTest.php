<?php

namespace Tests\Unit\Accounts;

use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\User;
use App\Support\Accounts\AccountSuspension;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE SUSPENSION PREDICATE, INCLUDING THE CASE THAT WOULD HAVE TAKEN THE PLATFORM DOWN.
 *
 * `AccountSuspension` is called on whatever model instance a caller happens to hold, which is a
 * wider contract than the login forms it mirrors -- they only ever see a row they just loaded. This
 * file pins the difference. Nothing here touches the database except the one schema assertion at
 * the end; the models are constructed in memory on purpose, because that is where the hazard lives.
 */
class AccountSuspensionPredicateTest extends TestCase
{
    /**
     * A FLAG THAT WAS NEVER LOADED IS NOT A SUSPENDED ACCOUNT.
     *
     * THE MOST IMPORTANT TEST IN THIS SLICE, because the first version of the predicate got it
     * wrong and the consequence was not a wrong answer in a corner -- it was every merchant signed
     * out. `users.is_active` and `users.is_enable_login` are both NOT NULL DEFAULT '1', so the
     * default applies to the stored ROW and not to an in-memory model; `User::factory()` sets
     * neither; and in PHP 8 `null == 0` is TRUE. So an ordinary ACTIVE merchant read as suspended.
     *
     * Measured when the control test failed before any suspension had been applied: an active
     * merchant fixture was redirected to `/login`. Had it reached the suite it would have hit 425
     * Feature files and 3,114 `actingAs(` call sites, and in production any handler holding a
     * partially-selected `User`.
     */
    #[Test]
    public function test_a_user_whose_flags_were_never_loaded_is_not_suspended(): void
    {
        $user = new User(['type' => 'admin']);

        $this->assertNull($user->getAttribute('is_active'), 'premise: the attribute really is absent');
        $this->assertNull($user->getAttribute('is_enable_login'), 'premise');
        $this->assertTrue(null == 0, 'premise: this is why a literal == 0 comparison was wrong'); // phpcs:ignore

        $this->assertNull(
            AccountSuspension::reasonFor($user),
            'A model whose suspension flags were never loaded was treated as suspended. The columns '
            . 'are NOT NULL DEFAULT 1, so a null can only mean "not loaded on this instance" -- never '
            . '"the operator suspended this account".'
        );
    }

    /** The same for a customer: an absent `status` is not a disabled customer. */
    #[Test]
    public function test_a_customer_whose_status_was_never_loaded_is_not_suspended(): void
    {
        $customer = new Customer(['name' => 'Shopper']);

        $this->assertNull($customer->getAttribute('status'), 'premise');

        $this->assertNull(
            AccountSuspension::reasonFor($customer),
            'An unloaded customer `status` was read as disabled. `status == 1` is false for null, '
            . 'which is why the comparison has to test presence first.'
        );
    }

    /** @return list<array{0: mixed, 1: bool}> value, expected-to-be-suspended */
    public static function isActiveValues(): array
    {
        return [
            'string one'   => ['1', false],
            'int one'      => [1, false],
            'string zero'  => ['0', true],
            'int zero'     => [0, true],
            'null'         => [null, false],
            // `'' == 0` is FALSE in PHP 8, so the login form admits it and so must this.
            'empty string' => ['', false],
        ];
    }

    /**
     * THE EQUIVALENCE WITH THE LOGIN FORM, over values rather than by reading the code.
     *
     * `users.is_active` is a VARCHAR, so `== 0` is a loose string comparison and the shipped form's
     * behaviour is not obvious: in PHP 8 `'1' == 0` is false, `'0' == 0` is true, and `'' == 0` is
     * false. A stricter `(int) === 0` would diverge on the empty string, which is why the loose
     * comparison is kept and pinned here instead of tidied.
     */
    #[Test]
    #[DataProvider('isActiveValues')]
    public function test_is_active_matches_the_login_forms_own_comparison(mixed $value, bool $suspended): void
    {
        $user = new User(['type' => 'admin']);
        $user->setAttribute('is_active', $value);

        // The literal predicate from Auth\AuthenticatedSessionController:68, minus the type check.
        $formWouldRefuse = $value !== null && $user->is_active == 0;

        $this->assertSame(
            $formWouldRefuse,
            AccountSuspension::isSuspended($user),
            'The per-request answer diverged from the login form for ' . var_export($value, true)
            . '. The two must agree, or an account is refused at one door and admitted at the other.'
        );
        $this->assertSame($suspended, AccountSuspension::isSuspended($user));
    }

    /** @return list<array{0: string}> */
    public static function operatorSpellings(): array
    {
        return [['super admin'], ['Super Admin'], ['SUPER ADMIN'], ['  super admin  ']];
    }

    /**
     * THE OPERATOR IS EXEMPT IN EVERY SPELLING -- a deliberate divergence from the form, which
     * compares raw. An operator refused on every request cannot sign in to undo it, and CLAUDE.md
     * records a letter-case reconciliation defect (P5-R9) as a real one here.
     */
    #[Test]
    #[DataProvider('operatorSpellings')]
    public function test_the_operator_is_exempt_in_every_spelling(string $type): void
    {
        $operator = new User(['type' => $type]);
        $operator->setAttribute('is_active', '0');
        $operator->setAttribute('is_enable_login', 0);

        $this->assertNull(
            AccountSuspension::reasonFor($operator),
            'The platform operator was suspended by its own flags, spelled ' . var_export($type, true)
        );
    }

    /** A merchant is NOT exempt -- the control against an exemption that matches too much. */
    #[Test]
    public function test_a_merchant_is_not_exempt(): void
    {
        $merchant = new User(['type' => 'admin']);
        $merchant->setAttribute('is_active', '0');

        $this->assertSame('the account is deactivated', AccountSuspension::reasonFor($merchant));
    }

    /** A null principal has no suspension state; it is unauthenticated, a different question. */
    #[Test]
    public function test_a_null_principal_is_not_suspended(): void
    {
        $this->assertNull(AccountSuspension::reasonFor(null));
        $this->assertFalse(AccountSuspension::isSuspended(null));
    }

    /** An unknown principal type FAILS CLOSED, so a fourth tokenable cannot slip through unchecked. */
    #[Test]
    public function test_an_unknown_principal_type_fails_closed(): void
    {
        $this->assertNotNull(
            AccountSuspension::reasonFor(new \stdClass()),
            'An unrecognised principal was admitted. A fourth tokenable means somebody added a '
            . 'principal type without deciding how it is suspended.'
        );
    }

    /**
     * THE CLAIM ABOUT DRIVERS IS MEASURED, NOT ASSUMED.
     *
     * `delivery_boys` carries no suspension column at all, so there is nothing for this class to
     * enforce and a driver cannot be suspended. If a column is ever added, this test fails and the
     * `DeliveryBoy` branch has to be decided rather than silently admitting a suspended driver.
     */
    #[Test]
    public function test_a_delivery_boy_has_no_suspension_column_to_enforce(): void
    {
        foreach (['is_active', 'is_enable_login', 'status', 'suspended_at', 'disabled_at'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('delivery_boys', $column),
                'delivery_boys now has a `' . $column . '` column. A driver can therefore be '
                . 'suspended, and AccountSuspension::reasonFor() must be taught to read it -- it '
                . 'currently returns null for every DeliveryBoy.'
            );
        }

        $this->assertNull(AccountSuspension::reasonFor(new DeliveryBoy(['name' => 'Driver'])));
    }
}
