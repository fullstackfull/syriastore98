<?php

namespace Tests\Feature\Lynomia;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Workdo\LynomiaChat\app\Support\MerchantContact;
use Workdo\LynomiaChat\database\seeders\LynomiaStagingFixtureSeeder;

/**
 * THE FIXTURE THAT STANDS PHASE 7 UP, AND THE TWO THINGS IT MUST NEVER DO.
 *
 * A seeder is usually not worth a test. This one is, for two reasons that are not about
 * convenience: it writes merchant records and phone numbers, and it will be run by an operator
 * on a host they have just provisioned, under time pressure, next to a production database.
 */
class LynomiaStagingFixtureTest extends TestCase
{
    use RefreshDatabase;

    private function seedFixtures(): void
    {
        $this->withEnv(true, fn () => (new LynomiaStagingFixtureSeeder)->run());
    }

    /** `env()` reads $_ENV/$_SERVER, so the opt-in is set there rather than through config. */
    private function withEnv(?bool $optIn, callable $body): void
    {
        $previous = $_SERVER['LYNOMIA_ALLOW_STAGING_FIXTURES'] ?? null;

        if ($optIn === null) {
            unset($_SERVER['LYNOMIA_ALLOW_STAGING_FIXTURES'], $_ENV['LYNOMIA_ALLOW_STAGING_FIXTURES']);
        } else {
            $_SERVER['LYNOMIA_ALLOW_STAGING_FIXTURES'] = $optIn;
            $_ENV['LYNOMIA_ALLOW_STAGING_FIXTURES'] = $optIn;
        }

        try {
            $body();
        } finally {
            unset($_SERVER['LYNOMIA_ALLOW_STAGING_FIXTURES'], $_ENV['LYNOMIA_ALLOW_STAGING_FIXTURES']);

            if ($previous !== null) {
                $_SERVER['LYNOMIA_ALLOW_STAGING_FIXTURES'] = $previous;
            }
        }
    }

    // ===================================================== it must not run where it would hurt

    public function test_it_refuses_to_run_in_production_whatever_the_opt_in_says(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $this->withEnv(true, function () {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage("environment 'production'");
            (new LynomiaStagingFixtureSeeder)->run();
        });

        $this->assertSame(0, Store::where('slug', 'like', 'staging-%')->count());
    }

    /**
     * THE SECOND CONDITION IS INDEPENDENT ON PURPOSE. APP_ENV is a string somebody can
     * mistype, and a staging host restored from a production snapshot can carry a production
     * database behind a staging APP_ENV.
     */
    public function test_it_refuses_without_the_explicit_opt_in_even_outside_production(): void
    {
        $this->withEnv(null, function () {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('LYNOMIA_ALLOW_STAGING_FIXTURES');
            (new LynomiaStagingFixtureSeeder)->run();
        });

        $this->assertSame(0, Store::where('slug', 'like', 'staging-%')->count());
    }

    // ===================================================== nothing it writes can reach a person

    public function test_no_phone_number_it_writes_can_be_routed_to_a_person(): void
    {
        $this->seedFixtures();

        $dialable = User::whereNotNull('mobile')->pluck('mobile')
            ->map(fn ($m) => MerchantContact::digitsOf((string) $m))
            ->filter(fn ($digits) => MerchantContact::classify($digits) === MerchantContact::UNVERIFIED);

        $this->assertNotEmpty($dialable, 'a fixture of only malformed numbers exercises the wrong branch');

        foreach ($dialable as $digits) {
            $this->assertStringStartsWith(
                '999',
                $digits,
                "{$digits} is dialable and not in the unassigned 999 country code"
            );
        }
    }

    public function test_every_address_it_writes_is_in_a_domain_that_cannot_receive_mail(): void
    {
        $this->seedFixtures();

        foreach (User::pluck('email') as $email) {
            if (! str_contains((string) $email, 'lynomia-staging')) {
                continue;
            }

            $this->assertStringEndsWith('.invalid', (string) $email);
        }
    }

    /**
     * NO MERCHANT IS PRE-VERIFIED. `mobile_verified_at` is what decides a merchant may be
     * messaged at all, and only the verification flow writes it. A fixture that set it would
     * manufacture the one state B4 exists to make merchants earn, and would allow a send in
     * staging that nobody verified.
     */
    public function test_it_never_marks_a_merchant_as_verified(): void
    {
        $this->seedFixtures();

        $this->assertSame(0, User::whereNotNull('mobile_verified_at')->count());
    }

    // ===================================================== it produces the population §10 needs

    public function test_it_produces_a_merchant_in_every_unreachable_contact_state(): void
    {
        $this->seedFixtures();

        $states = User::where('email', 'like', 'merchant-%@lynomia-staging.invalid')
            ->get()
            ->map(fn (User $u) => MerchantContact::of($u)->state)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            [MerchantContact::MISSING, MerchantContact::NOT_USABLE,
                MerchantContact::PLACEHOLDER, MerchantContact::UNVERIFIED],
            $states
        );
    }

    /** The placeholder with a country code normalises into a well-formed number and must still be caught. */
    public function test_the_prefixed_placeholder_is_still_classified_as_a_placeholder(): void
    {
        $this->seedFixtures();

        $merchant = User::where('email', 'merchant-placeholder-prefixed@lynomia-staging.invalid')->sole();

        $this->assertSame(MerchantContact::PLACEHOLDER, MerchantContact::of($merchant)->state);
    }

    /**
     * THE SPELLINGS ARE THE POINT. Order::payment_status() offers Paid and Unpaid; the gateway
     * controllers write five more straight onto the column, and the commerce observer is on the
     * model because of it.
     */
    public function test_it_covers_the_payment_spellings_the_gateways_actually_write(): void
    {
        $this->seedFixtures();

        $written = Order::where('product_order_id', 'like', 'STAGING-%')
            ->pluck('payment_status')->unique()->sort()->values()->all();

        foreach (['Paid', 'Unpaid', 'success', 'Succeeded', 'Pending', 'Rejected', 'On Hold'] as $spelling) {
            $this->assertContains($spelling, $written);
        }
    }

    /**
     * WITHOUT A LINK THE MERCHANT IS INVISIBLE to the Super Admin contact panel, which
     * iterates lynomia_store_links -- so the whole contact matrix this fixture exists to
     * populate would be unreachable.
     */
    public function test_every_fixture_merchant_has_a_link_so_the_contact_panel_can_see_them(): void
    {
        $this->seedFixtures();

        $merchants = \App\Models\User::where('email', 'like', 'merchant-%@lynomia-staging.invalid')->count();

        $this->assertSame($merchants, \Workdo\LynomiaChat\app\Models\LynomiaStoreLink::count());
    }

    /** And no fixture link may point at an account that could really exist. */
    public function test_no_fixture_link_points_at_a_plausible_real_account(): void
    {
        $this->seedFixtures();

        foreach (\Workdo\LynomiaChat\app\Models\LynomiaStoreLink::pluck('lynomia_account_id') as $id) {
            $this->assertGreaterThanOrEqual(9_900_000, (int) $id, 'a fixture link must be recognisable as one');
        }
    }

    /** §11 reads users.plan_id and answers null without one, which makes its assertions vacuous. */
    public function test_every_merchant_is_on_a_plan_with_a_small_deliberate_ceiling(): void
    {
        $this->seedFixtures();

        $plans = \App\Models\User::where('email', 'like', 'merchant-%@lynomia-staging.invalid')
            ->pluck('plan_id')->unique();

        $this->assertCount(1, $plans);
        $this->assertNotNull($plans->first());

        $plan = \App\Models\Plan::find($plans->first());
        $this->assertSame(5, (int) $plan->lynomia_campaign_sends, 'a realistic ceiling makes step 11.2 take an afternoon');
    }

    /**
     * §6.4 IS ABOUT A TRANSITION. CommerceEventObserver's `updated` branch keys on
     * wasChanged('payment_status'), which an order born with its status set never exercises.
     */
    public function test_one_order_per_store_actually_transitions_its_payment_status(): void
    {
        $this->seedFixtures();

        $moved = Order::where('product_order_id', 'like', '%-transition')->get();

        $this->assertNotEmpty($moved);
        foreach ($moved as $order) {
            $this->assertSame('Paid', $order->payment_status);
        }
    }

    /** §7.2's first-time shopper must not be the shopper §7.1 already put a decision against. */
    public function test_there_is_a_shopper_with_no_order_for_the_first_time_case(): void
    {
        $this->seedFixtures();

        $newcomer = \App\Models\Customer::where('email', 'like', 'newcomer-%@lynomia-staging.invalid')->first();

        $this->assertNotNull($newcomer);
        $this->assertSame(0, Order::where('customer_id', $newcomer->id)->count());
    }

    public function test_running_it_twice_changes_nothing(): void
    {
        $this->seedFixtures();

        $before = [User::count(), Store::count(), Order::count()];

        $this->seedFixtures();

        $this->assertSame($before, [User::count(), Store::count(), Order::count()]);
    }
}
