<?php

namespace Workdo\LynomiaChat\database\seeders;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Models\userActiveModule;
use Illuminate\Database\Seeder;
use App\Models\Plan;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Support\LinkState;
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
 * THE LINKS IT WRITES POINT AT NOTHING, AND THAT IS DELIBERATE. The Super Admin contact panel
 * iterates `lynomia_store_links`, so a merchant with no link is INVISIBLE to it -- which would
 * make the whole contact matrix this fixture exists for unreachable. So each fixture merchant
 * gets a link, with a `lynomia_account_id` in an obviously fake range.
 *
 * Nothing ever dials it. `PlatformRecipients::resolve` checks the link state, then
 * provisioning, then ownership, then the store, and only THEN the contact -- so for the four
 * merchants whose contact is missing, placeholder or unusable, the refusal happens before any
 * remote call. The fifth is `unverified`, which is refused for the same reason until somebody
 * verifies it.
 *
 * SO THE FIXTURE MERCHANTS ARE FOR THE SUPER ADMIN MATRIX, NOT FOR SENDING. Test runbook §10.3
 * sends to a merchant provisioned for REAL in §4.1 against the real staging Lynomia. Verifying
 * a fixture merchant and then sending to them would address an account that does not exist.
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
        $plan = $this->plan();
        $merchants = $this->merchants();

        foreach ($merchants as $key => $merchant) {
            $store = $this->storeFor($merchant, $key);
            $this->entitle($merchant, $plan);
            $this->linkFor($store, $merchant, $key);
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

    /**
     * A PLAN, because §11 cannot run without one. `MarketingAllowanceSync::planFor` reads
     * `users.plan_id` and answers null when it is unset, which makes every allowance and seat
     * assertion vacuous rather than failing.
     *
     * The numbers are deliberately SMALL. §11 asks an operator to send until the limit is
     * refused, and a realistic ceiling would make that step take an afternoon.
     */
    private function plan(): Plan
    {
        $plan = Plan::firstOrNew(['name' => 'Staging fixture plan']);

        $plan->forceFill([
            'price' => 0,
            'duration' => 'Monthly',
            'max_stores' => 5,
            'max_users' => 5,
            'lynomia_agent_seats' => 2,
            'lynomia_campaign_sends' => 5,
            'description' => 'Phase 7 staging fixtures. Not sellable; do not use in production.',
            // NOT NULL in the schema with no default, both of them. Discovered by the seeder
            // failing rather than by reading the model, because `$fillable` says nothing about
            // nullability.
            'themes' => json_encode([]),
            'storage_limit' => '1024',
            'trial' => 0,
        ])->save();

        return $plan->fresh();
    }

    /**
     * The PLAN's answer, and the tenant's entitlement row.
     *
     * The add-on REGISTRY (`add_on_managers`) is a different thing and is not this seeder's:
     * it is seeded once per installation by LynomiaChatDatabaseSeeder, which the deployment
     * runbook runs at §2.5a. Without it every merchant route 403s before any fixture matters.
     */
    private function entitle(User $owner, Plan $plan): void
    {
        userActiveModule::updateOrCreate(['user_id' => $owner->id, 'module' => 'LynomiaChat'], []);
        $owner->forceFill(['plan_id' => $plan->id])->save();
    }

    /**
     * A LINK, because without one the merchant is invisible to the Super Admin contact panel
     * -- it iterates `lynomia_store_links` -- and the contact matrix is the thing this fixture
     * exists to populate.
     *
     * The account id is fake and is never dialled: see the class docblock.
     */
    private function linkFor(Store $store, User $owner, string $key): void
    {
        LynomiaStoreLink::updateOrCreate(
            ['store_id' => (int) $store->id],
            [
                'owner_user_id' => (int) $owner->id,
                // 9_900_000 upward: no real Lynomia account will collide, and a number in this
                // range appearing in a log is immediately recognisable as a fixture.
                'lynomia_account_id' => 9_900_000 + crc32($key) % 1000,
                'lynomia_account_name' => "Staging fixture ({$key})",
                'state' => LinkState::ACTIVE,
                'link_mode' => LynomiaStoreLink::MODE_PROVISION,
                'provisioning_stage' => null,
            ]
        );
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

        // §6.4 IS ABOUT A TRANSITION, NOT A CREATION. CommerceEventObserver has both a
        // `created` and an `updated` branch, and the one the runbook asks about is
        // `wasChanged('payment_status')` -- which an order born with its status already set
        // never exercises. So one order is created Unpaid and then moved, the way a gateway
        // controller moves it.
        $transitioned = Order::where('product_order_id', "STAGING-{$key}-transition")->first();

        if ($transitioned === null) {
            $transitioned = Order::create([
                'product_order_id' => "STAGING-{$key}-transition",
                'order_date' => now(),
                'customer_id' => $customer->id,
                'is_guest' => 0,
                'product_json' => json_encode([]),
                'product_price' => 2500,
                'final_price' => 2500,
                'currency_code' => 'SYP',
                'payment_type' => 'staging-fixture',
                'payment_status' => 'Unpaid',
                'store_id' => $store->id,
                'created_by' => $store->created_by,
            ]);

            $transitioned->forceFill(['payment_status' => 'Paid'])->save();
        }

        // A SECOND SHOPPER WITH NO ORDER AND NO CONSENT, for §7.2's first-time case. Reusing
        // the shopper above would contaminate it: they already exist in the commerce data that
        // §7.1 puts a consent decision against.
        $newcomer = Customer::firstOrNew(['email' => "newcomer-{$key}@" . self::DOMAIN]);
        $newcomer->forceFill([
            'first_name' => 'First-time',
            'last_name' => "Shopper ({$key})",
            'mobile' => self::PHONE_PREFIX . '9' . str_pad((string) ($store->id % 10), 1, '0', STR_PAD_LEFT),
            'store_id' => $store->id,
            'status' => 1,
            'created_by' => $store->created_by,
            'password' => $newcomer->password ?: bcrypt(bin2hex(random_bytes(16))),
        ])->save();

        // An abandoned cart is a row with no order behind it; the recovery surface reads carts
        // directly, so nothing else is needed to make one.
        //
        // THROUGH THE MODEL, NOT `DB::table`. The first version of this used the query builder
        // and tripped tests/Feature/Tenancy/RawQueryTenancyInventoryTest: a raw entry point is
        // invisible to every Eloquent global scope, so the census counts them and refuses to
        // let one appear unremarked. It was right to. Eloquent carries the scopes and the
        // census stays where it was.
        $cart = Cart::firstOrNew([
            'customer_id' => $customer->id,
            'store_id' => $store->id,
            'product_id' => 1,
        ]);
        $cart->forceFill(['qty' => 1, 'price' => 1000])->save();
    }
}
