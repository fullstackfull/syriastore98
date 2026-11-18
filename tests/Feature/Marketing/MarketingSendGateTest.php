<?php

namespace Tests\Feature\Marketing;

use App\Models\Customer;
use App\Services\Marketing\ConsentLedger;
use App\Services\Marketing\MarketingSendGate;
use App\Support\Marketing\Channel;
use App\Support\Marketing\MessageClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * MAY THIS MESSAGE BE SENT? D8 / GAP-200 / MKT-008.
 *
 * The owner's conditions, each as a case:
 *   - enforcement is OFF by default and then inert, touching no table (production has none);
 *   - transactional and operational messages are never held;
 *   - a marketing message needs an explicit, current permission, and NOTHING ELSE substitutes for one --
 *     not an order, not a customer record, not another store's grant, not another channel's;
 *   - report mode says what would have happened without doing it, and never logs an address.
 */
class MarketingSendGateTest extends TestCase
{
    use RefreshDatabase;

    private const STORE = 21;
    private const OTHER_STORE = 22;
    private const EMAIL = 'buyer@example.com';

    private function gate(): MarketingSendGate
    {
        return new MarketingSendGate(new ConsentLedger());
    }

    private function mode(string $mode): void
    {
        config(['marketing_consent.enforcement' => $mode]);
    }

    /** The gate's per-channel logic, tested as if phone identity were canonical. The guard that says it is not has its own tests below. */
    private function phoneIdentityCanonical(): void
    {
        config(['marketing_consent.phone_identity_canonical' => true]);
    }

    private function grant(int $store = self::STORE, Channel $channel = Channel::Email, string $address = self::EMAIL): void
    {
        (new ConsentLedger())->grant($store, $channel, $address, 'account_settings');
    }

    public function test_the_shipped_default_is_off(): void
    {
        $this->assertSame('off', config('marketing_consent.enforcement'));
        $this->assertSame('off', $this->gate()->mode());
    }

    public function test_off_allows_everything_and_issues_no_query_at_all(): void
    {
        $this->mode('off');

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });

        foreach ([self::EMAIL, null, 'garbage'] as $address) {
            $decision = $this->gate()->decide(self::STORE, Channel::Email, MessageClass::Marketing, $address);

            $this->assertTrue($decision->send);
            $this->assertFalse($decision->wouldHold);
            $this->assertSame('enforcement_off', $decision->reason);
        }

        $this->assertSame([], $queries,
            'an `off` gate must be inert: production has no consent tables, so touching one would be an outage');
    }

    public function test_an_unrecognised_mode_is_report_not_off_and_not_enforce(): void
    {
        foreach (['on', 'ENFORCED', 'true', '1', 'yes', 'reporting'] as $typo) {
            $this->mode($typo);

            $this->assertSame('report', $this->gate()->mode(), 'mode '.var_export($typo, true));
        }

        $this->mode('  ENFORCE ');
        $this->assertSame('enforce', $this->gate()->mode(), 'case and whitespace are forgiven for a recognised value');
    }

    public function test_the_spellings_that_can_only_mean_not_on_are_off_and_issue_no_query(): void
    {
        // Laravel's env() turns the literal `false` into an EMPTY STRING, which used to select `report` -- and report reads the consent tables
        // an environment may not have. Someone who writes MARKETING_CONSENT_ENFORCEMENT=false means off.
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });

        foreach (['', 'off', 'OFF', ' false ', '0', 'no', 'disabled', 'none', 'null', false, null] as $spelling) {
            config(['marketing_consent.enforcement' => $spelling]);

            $this->assertSame('off', $this->gate()->mode(), 'mode '.var_export($spelling, true));
            $this->assertSame('enforcement_off', $this->gate()->decide(self::STORE, Channel::Email, MessageClass::Marketing, self::EMAIL)->reason);
        }

        $this->assertSame([], $queries);
    }

    public function test_transactional_and_operational_messages_are_never_held(): void
    {
        $this->mode('enforce');

        // The worst case for them: a suppressed contact on a channel with an unusable address.
        (new ConsentLedger())->suppress(self::STORE, Channel::Email, self::EMAIL, 'complaint_feedback');

        foreach ([MessageClass::Transactional, MessageClass::Operational] as $class) {
            foreach ([self::EMAIL, 'not an address', null] as $address) {
                $decision = $this->gate()->decide(self::STORE, Channel::Email, $class, $address);

                $this->assertTrue($decision->send, $class->value.' to '.var_export($address, true).' was held');
                $this->assertSame('not_marketing', $decision->reason);
            }

            $this->assertTrue($this->gate()->decide(0, Channel::Sms, $class, null)->send, $class->value.' with no store was held');
        }
    }

    public function test_marketing_with_no_recorded_permission_is_held_when_enforced(): void
    {
        $this->mode('enforce');

        $decision = $this->gate()->decide(self::STORE, Channel::Email, MessageClass::Marketing, self::EMAIL);

        $this->assertFalse($decision->send);
        $this->assertTrue($decision->wouldHold);
        $this->assertSame('no_consent_recorded', $decision->reason);
    }

    public function test_marketing_with_a_current_grant_goes(): void
    {
        $this->mode('enforce');
        $this->grant();

        $decision = $this->gate()->decide(self::STORE, Channel::Email, MessageClass::Marketing, '  BUYER@example.com ');

        $this->assertTrue($decision->send);
        $this->assertFalse($decision->wouldHold);
        $this->assertSame('consent_granted', $decision->reason);
    }

    public function test_withdrawn_and_suppressed_contacts_are_held_with_their_own_reason(): void
    {
        $this->mode('enforce');

        $this->grant();
        (new ConsentLedger())->withdraw(self::STORE, Channel::Email, self::EMAIL, 'unsubscribe_link');
        $this->assertSame('withdrawn', $this->gate()->decide(self::STORE, Channel::Email, MessageClass::Marketing, self::EMAIL)->reason);

        (new ConsentLedger())->suppress(self::STORE, Channel::Email, self::EMAIL, 'complaint_feedback');
        $this->assertSame('suppressed', $this->gate()->decide(self::STORE, Channel::Email, MessageClass::Marketing, self::EMAIL)->reason);
    }

    public function test_no_store_and_no_usable_address_are_held(): void
    {
        $this->mode('enforce');

        $this->assertSame('no_store', $this->gate()->decide(0, Channel::Email, MessageClass::Marketing, self::EMAIL)->reason);
        $this->assertSame('address_unusable', $this->gate()->decide(self::STORE, Channel::Email, MessageClass::Marketing, null)->reason);
        $this->assertSame('address_unusable', $this->gate()->decide(self::STORE, Channel::Email, MessageClass::Marketing, 'nope')->reason);
    }

    public function test_one_stores_grant_does_not_permit_another_store_or_another_channel(): void
    {
        $this->mode('enforce');
        $this->phoneIdentityCanonical();
        $this->grant(self::STORE, Channel::Sms, '963912345678');

        $this->assertTrue($this->gate()->decide(self::STORE, Channel::Sms, MessageClass::Marketing, '+963 912 345 678')->send);
        $this->assertFalse($this->gate()->decide(self::OTHER_STORE, Channel::Sms, MessageClass::Marketing, '963912345678')->send,
            'another store');
        $this->assertFalse($this->gate()->decide(self::STORE, Channel::Whatsapp, MessageClass::Marketing, '963912345678')->send,
            'another channel');
    }

    public function test_being_a_customer_record_is_not_consent(): void
    {
        $this->mode('enforce');
        $this->phoneIdentityCanonical();

        // The tempting inference: this person is our customer, so they must be fine to market to.
        Customer::forceCreate([
            'first_name' => 'Existing', 'last_name' => 'Customer', 'email' => self::EMAIL,
            'mobile' => '963912345678', 'password' => bcrypt('x'), 'store_id' => self::STORE,
            'status' => 1, 'register_type' => 'email',
        ]);

        $this->assertFalse($this->gate()->decide(self::STORE, Channel::Email, MessageClass::Marketing, self::EMAIL)->send,
            'a customer record is not a permission to be marketed to');
        $this->assertFalse($this->gate()->decide(self::STORE, Channel::Sms, MessageClass::Marketing, '963912345678')->send);
    }

    public function test_report_mode_sends_but_says_what_enforcement_would_have_done(): void
    {
        $this->mode('report');

        Log::spy();

        $decision = $this->gate()->decide(self::STORE, Channel::Email, MessageClass::Marketing, self::EMAIL);

        $this->assertTrue($decision->send, 'report mode must not hold anything');
        $this->assertTrue($decision->wouldHold);
        $this->assertSame('no_consent_recorded', $decision->reason);

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) {
            $this->assertSame(self::STORE, $context['store_id']);
            $this->assertSame('no_consent_recorded', $context['reason']);
            $this->assertSame(12, strlen($context['contact']), 'a hash PREFIX, enough to correlate');
            $this->assertStringNotContainsString('buyer', json_encode($context), 'the log must never carry an address');
            $this->assertStringNotContainsString('example.com', json_encode($context));
            $this->assertStringNotContainsString('buyer', $message, 'nor may the MESSAGE carry one');
            $this->assertStringNotContainsString('example.com', $message);

            return true;
        });
    }

    public function test_report_mode_stays_quiet_when_nothing_would_be_held(): void
    {
        $this->mode('report');
        $this->grant();

        Log::spy();

        $decision = $this->gate()->decide(self::STORE, Channel::Email, MessageClass::Marketing, self::EMAIL);

        $this->assertTrue($decision->send);
        $this->assertFalse($decision->wouldHold);
        Log::shouldNotHaveReceived('warning');
    }

    // ------------------------------------------------------------------ the store cannot be read

    private function breakTheConsentStore(): void
    {
        DB::statement('DROP TABLE marketing_contact_states');
    }

    public function test_report_mode_never_stops_a_send_because_the_store_is_unreadable(): void
    {
        $this->mode('report');
        $this->breakTheConsentStore();
        Log::spy();

        $decision = $this->gate()->decide(self::STORE, Channel::Email, MessageClass::Marketing, self::EMAIL);

        $this->assertTrue($decision->send, 'report mode stopped a send');
        $this->assertTrue($decision->wouldHold);
        $this->assertSame('consent_store_unavailable', $decision->reason);

        // The log names the exception CLASS and nothing else: a QueryException message is the failing statement with its values substituted.
        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
            if (! str_contains($message, 'could not be read')) {
                return false; // the second line the gate writes ("would be held"), asserted separately by the report-mode tests
            }

            $this->assertSame(\Illuminate\Database\QueryException::class, $context['exception']);
            $this->assertArrayNotHasKey('message', $context);
            $this->assertStringNotContainsString(hash('sha256', self::STORE.'|email|'.self::EMAIL), json_encode($context));

            return true;
        })->once();
    }

    public function test_enforce_mode_holds_marketing_when_consent_cannot_be_verified(): void
    {
        $this->mode('enforce');
        $this->breakTheConsentStore();

        $decision = $this->gate()->decide(self::STORE, Channel::Email, MessageClass::Marketing, self::EMAIL);

        $this->assertFalse($decision->send, 'consent could not be verified and the message went anyway');
        $this->assertSame('consent_store_unavailable', $decision->reason);
    }

    public function test_an_unreadable_store_never_touches_transactional_or_operational_messages(): void
    {
        $this->mode('enforce');
        $this->breakTheConsentStore();

        foreach ([MessageClass::Transactional, MessageClass::Operational] as $class) {
            $decision = $this->gate()->decide(self::STORE, Channel::Email, $class, self::EMAIL);

            $this->assertTrue($decision->send, $class->value.' was held because the consent store is down');
            $this->assertSame('not_marketing', $decision->reason);
        }
    }

    // ------------------------------------------------------------------ phone identity is not canonical

    public function test_enforce_is_read_as_report_for_the_phone_channels_until_phone_identity_is_canonical(): void
    {
        $this->mode('enforce');

        foreach ([Channel::Sms, Channel::Whatsapp] as $channel) {
            $this->assertSame('report', $this->gate()->mode($channel), $channel->value);

            $decision = $this->gate()->decide(self::STORE, $channel, MessageClass::Marketing, '963912345678');
            $this->assertTrue($decision->send, $channel->value.' was held although its identity is not canonical');
            $this->assertTrue($decision->wouldHold, 'report mode must still say what would have happened');
            $this->assertSame('report', $decision->mode);
        }

        foreach ([Channel::Email, Channel::Push] as $channel) {
            $this->assertSame('enforce', $this->gate()->mode($channel), $channel->value.' is not phone-addressed and must enforce');
        }

        $this->assertFalse($this->gate()->decide(self::STORE, Channel::Email, MessageClass::Marketing, self::EMAIL)->send);
        $this->assertSame('enforce', $this->gate()->mode(), 'with no channel named the configured mode is reported unchanged');
    }

    public function test_the_phone_channels_enforce_once_the_code_says_identity_is_canonical(): void
    {
        $this->mode('enforce');
        $this->phoneIdentityCanonical();

        $this->assertSame('enforce', $this->gate()->mode(Channel::Sms));
        $this->assertFalse($this->gate()->decide(self::STORE, Channel::Sms, MessageClass::Marketing, '963912345678')->send);
    }

    public function test_the_canonical_flag_ships_false_and_is_not_an_environment_variable(): void
    {
        $this->assertFalse(config('marketing_consent.phone_identity_canonical'));
        $this->assertDoesNotMatchRegularExpression(
            "/'phone_identity_canonical'\\s*=>\\s*env\\(/",
            (string) file_get_contents(config_path('marketing_consent.php')),
            'a statement about what the CODE can do must not be switchable by a configuration typo'
        );
    }

    // ------------------------------------------------------------------ the gate leaves a trace of what it holds

    public function test_enforce_mode_logs_what_it_holds_without_an_address(): void
    {
        $this->mode('enforce');
        Log::spy();

        $this->gate()->decide(self::STORE, Channel::Email, MessageClass::Marketing, self::EMAIL);

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) {
            $this->assertStringContainsString('held by consent enforcement', $message);
            $this->assertSame('enforce', $context['mode']);
            $this->assertSame(12, strlen($context['contact']));
            $this->assertStringNotContainsString('buyer', json_encode($context));

            return true;
        })->once();
    }
}
