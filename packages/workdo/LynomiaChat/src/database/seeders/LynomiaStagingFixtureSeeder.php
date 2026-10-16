<?php

namespace Workdo\LynomiaChat\database\seeders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Models\userActiveModule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Workdo\LynomiaChat\app\Support\MerchantContact;

/**
 * THE SYNTHETIC POPULATION PHASE 7 NEEDS, AND NOTHING THAT CAN REACH A REAL PERSON.
 *
 * WHAT IT IS FOR. `PHASE-7-RUNBOOK-TEST.md` assumes merchants exist in every contact state,
 * stores exist with the module entitled, and orders exist across the payment statuses the
 * gateways actually write. Standing those up by hand, in order, during a window that also has
 * GO/NO-GO gates in it, is how a window runs out before §10.
 *
 * IT REFUSES TO RUN IN PRODUCTION, and the refusal is not `--force`-able. Laravel's own
 * production confirmation can be waived by a flag an operator under pressure will reach for;
 * this one cannot, because the thing it protects against is exactly that operator. The two
 * conditions are independent on purpose: `APP_ENV` is a string somebody can mistype, and the
 * explicit opt-in is a second, deliberate act.
 *
 * NOTHING IT CREATES CAN REACH A HUMAN BEING.
 *
 *   phone numbers   country code 999, which ITU has not assigned and no carrier routes. They
 *                   are well-formed by MerchantContact's rule -- which is the point, because a
 *                   fixture made of malformed numbers would exercise the wrong branch -- and
 *                   they cannot ring. A real number appears in staging exactly once: when the
 *                   owner's named GATE 9 recipient types theirs into the verification flow,
 *                   which is itself a test step.
 *   email addresses `.invalid`, reserved by RFC 2606 and guaranteed never to resolve, so a
 *                   stray notification cannot be delivered anywhere.
 *
 * IDEMPOTENT, keyed on those deterministic addresses. Running it twice changes nothing, which
 * matters because §12 of the test runbook deliberately breaks things and an operator will want
 * to re-seed.
 */
class LynomiaStagingFixtureSeeder extends Seeder
{
    /** RFC 2606 guarantees this never resolves. */
    public const DOMAIN = 'lynomia-staging.invalid';

    /**
     * ITU country code 999 is unassigned. Twelve digits, so they pass MerchantContact's 8..15
     * rule and exercise the well-formed branch, and no carrier will route them.
     */
    public const PHONE_PREFIX = '99955501';

    /** The environments this may touch. Anything else, including an unset APP_ENV, is refused. */
    public const ALLOWED_ENVIRONMENTS = ['local', 'staging', 'testing'];

    public function run(): void
    {
        $this->assertNotProduction();

        $operator = $this->operator();
        $merchants = $this->merchants();

        foreach ($merchants as $key => $merchant) {
            $store = $this->storeFor($merchant, $key);
            $this->entitle($merchant);
            $this->commerceFor($store, $key);
        }

        $this->command?->info(sprintf(
            'Seeded %d synthetic merchants (operator #%d). Numbers are unroutable; addresses are %s.',
            count($merchants),
            $operator->id,
            self::DOMAIN
        ));
        $this->command?->warn('No merchant is pre-verified. Verification is runbook step 10.2 and is a test, not a fixture.');
    }

    /**
     * TWO INDEPENDENT CONDITIONS, because one of them is a string somebody can mistype.
     *
     * There is no `--force`. The whole point of this guard is the operator who is in a hurry,
     * and a flag that waives it is a flag that will be used.
     */
    private function assertNotProduction(): void
    {
        $environment = (string) app()->environment();

        if (! in_array($environment, self::ALLOWED_ENVIRONMENTS, true)) {
            throw new \RuntimeException(
                "Refusing to seed staging fixtures in environment '{$environment}'. "
                . 'Allowed: ' . implode(', ', self::ALLOWED_ENVIRONMENTS) . '.'
            );
        }

        if (env('LYNOMIA_ALLOW_STAGING_FIXTURES') !== true) {
            throw new \RuntimeException(
                'Refusing to seed staging fixtures: set LYNOMIA_ALLOW_STAGING_FIXTURES=true to confirm '
                . 'this is a non-production database. There is deliberately no --force.'
            );
        }
    }

    /**
     * ONE MERCHANT PER CONTACT STATE, because the Super Admin panel reports a count per
     * refusal reason and a fixture with one state in it makes four of those rows untestable.
     *
     * NONE IS VERIFIED. `mobile_verified_at` is what `PlatformRecipients` reads to decide a
     * merchant may be messaged at all, and only `MerchantContactVerification::confirm` writes
     * it. A seeder that set it would manufacture the one state the B4 mechanism exists to earn
     * -- and would let a send happen in staging that nobody verified.
     *
     * @return array<string,User>
     */
    private function merchants(): array
    {
        return [
            // MerchantContact::MISSING -- what self-registration leaves behind.
            'missing' => $this->merchant('missing', null),
            // MerchantContact::PLACEHOLDER -- what Super Admin store creation wrote until B4.
            'placeholder' => $this->merchant('placeholder', '7878787878'),
            // The same placeholder with a country code, which normalised into a well-formed
            // twelve-digit number until the classifier learned to match it as a suffix.
            'placeholder_prefixed' => $this->merchant('placeholder-prefixed', '+91 7878787878'),
            // MerchantContact::NOT_USABLE -- present, and not a number.
            'not_usable' => $this->merchant('not-usable', 'call me'),
            // MerchantContact::UNVERIFIED -- well-formed, unroutable, and nobody has proved it.
            // This is the merchant runbook step 10.2 verifies.
            'unverified' => $this->merchant('unverified', self::PHONE_PREFIX . '01'),
        ];
    }

    private function merchant(string $slug, ?string $mobile): User
    {
        $user = User::firstOrNew(['email' => "merchant-{$slug}@" . self::DOMAIN]);

        $user->forceFill([
            'name' => "Staging merchant ({$slug})",
            'type' => 'admin',
            'created_by' => 1,
            'is_active' => 1,
            'email_verified_at' => now(),
            'mobile' => $mobile,
            // Never set by a fixture. See the docblock above.
            'mobile_verified_at' => null,
            'password' => $user->password ?: bcrypt(bin2hex(random_bytes(16))),
        ])->save();

        return $user->fresh();
    }

    private function operator(): User
    {
        $operator = User::firstOrNew(['email' => 'operator@' . self::DOMAIN]);

        $operator->forceFill([
            'name' => 'Staging platform operator',
            'type' => 'super admin',
            'created_by' => null,
            'is_active' => 1,
            'email_verified_at' => now(),
            'password' => $operator->password ?: bcrypt(bin2hex(random_bytes(16))),
        ])->save();

        return $operator->fresh();
    }

    private function storeFor(User $owner, string $key): Store
    {
        $store = Store::firstOrNew(['slug' => "staging-{$key}"]);

        $store->forceFill([
            'name' => "Staging store ({$key})",
            'email' => "store-{$key}@" . self::DOMAIN,
            'theme_id' => '1',
            'created_by' => $owner->id,
            'is_active' => 1,
        ])->save();

        $owner->forceFill(['current_store' => $store->id])->save();

        return $store->fresh();
    }

    /** The PLAN's answer. The role's permissions are the seeders' job and are not duplicated here. */
    private function entitle(User $owner): void
    {
        userActiveModule::updateOrCreate(['user_id' => $owner->id, 'module' => 'LynomiaChat'], []);
    }

    /**
     * A SHOPPER, AN ORDER PER PAYMENT SPELLING, AND AN ABANDONED CART.
     *
     * THE SPELLINGS ARE THE POINT. `Order::payment_status()` offers exactly Paid and Unpaid,
     * and the gateway controllers write `success`, `Succeeded`, `succeeded`, `Pending`,
     * `Approved`, `Rejected` and `On Hold` straight onto the column. The commerce observer is
     * on the MODEL for that reason -- `App\Events\OrderPaymentStatus` is not dispatched by
     * those controllers, which is how the retired Chatwoot integration came to never see an
     * order. A fixture carrying only the two documented values would test the half of the
     * population that does not exist.
     */
    private function commerceFor(Store $store, string $key): void
    {
        $customer = Customer::firstOrNew(['email' => "shopper-{$key}@" . self::DOMAIN]);
        $customer->forceFill([
            'first_name' => 'Staging',
            'last_name' => "Shopper ({$key})",
            'mobile' => self::PHONE_PREFIX . str_pad((string) ($store->id % 100), 2, '0', STR_PAD_LEFT),
            'store_id' => $store->id,
            'status' => 1,
            'created_by' => $store->created_by,
            'password' => $customer->password ?: bcrypt(bin2hex(random_bytes(16))),
        ])->save();
        $customer = $customer->fresh();

        foreach (['Paid', 'Unpaid', 'success', 'Succeeded', 'Pending', 'Rejected', 'On Hold'] as $i => $status) {
            $reference = "STAGING-{$key}-{$i}";

            if (Order::where('product_order_id', $reference)->exists()) {
                continue;
            }

            Order::create([
                'product_order_id' => $reference,
                'order_date' => now()->subDays($i),
                'customer_id' => $customer->id,
                'is_guest' => 0,
                'product_json' => json_encode([]),
                'product_price' => 1000,
                'final_price' => 1000,
                'currency_code' => 'SYP',
                'payment_type' => 'staging-fixture',
                'payment_status' => $status,
                'store_id' => $store->id,
                'created_by' => $store->created_by,
            ]);
        }

        // An abandoned cart is a row with no order behind it; the recovery surface reads carts
        // directly, so nothing else is needed to make one.
        DB::table('carts')->updateOrInsert(
            ['customer_id' => $customer->id, 'store_id' => $store->id, 'product_id' => 1],
            ['qty' => 1, 'price' => 1000, 'updated_at' => now(), 'created_at' => now()->subDay()]
        );
    }
}
