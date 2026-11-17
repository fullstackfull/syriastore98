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
        foreach (['', 'on', 'ENFORCED', 'true', '1'] as $typo) {
            $this->mode($typo);

            $this->assertSame('report', $this->gate()->mode(), 'mode '.var_export($typo, true));
        }

        $this->mode('  ENFORCE ');
        $this->assertSame('enforce', $this->gate()->mode(), 'case and whitespace are forgiven for a recognised value');
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
        $this->grant(self::STORE, Channel::Sms, '963912345678');

        $this->assertTrue($this->gate()->decide(self::STORE, Channel::Sms, MessageClass::Marketing, '+963 912 345 678')->send);
        $this->assertFalse($this->gate()->decide(self::OTHER_STORE, Channel::Sms, MessageClass::Marketing, '963912345678')->send,
            'another store');
        $this->assertFalse($this->gate()->decide(self::STORE, Channel::Whatsapp, MessageClass::Marketing, '963912345678')->send,
            'another channel');
    }

    public function test_having_ordered_or_being_a_contact_is_not_consent(): void
    {
        $this->mode('enforce');

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
}
