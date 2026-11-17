<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConsentEvent;
use App\Models\MarketingContactState;
use App\Services\Marketing\ConsentLedger;
use App\Support\Marketing\Channel;
use App\Support\Marketing\ConsentState;
use App\Support\Marketing\ContactAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * THE CONSENT LEDGER. D8 / GAP-200 / MKT-008.
 *
 * What these cases defend, in the order a regulator or a merchant would ask:
 *
 *   * a recorded opt-in is a recorded opt-in, with evidence, and nothing else is;
 *   * the evidence cannot be edited or deleted;
 *   * one store's decision is never another store's, and one channel's is never another's;
 *   * a complaint is not undone by a checkbox;
 *   * the current-state table agrees with the evidence it is projected from.
 */
class ConsentLedgerTest extends TestCase
{
    use RefreshDatabase;

    private const STORE = 11;
    private const OTHER_STORE = 12;
    private const EMAIL = 'Shopper@Example.com';

    private function ledger(): ConsentLedger
    {
        return new ConsentLedger();
    }

    public function test_nothing_recorded_is_not_consent(): void
    {
        $this->assertSame(ConsentState::None, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL));
        $this->assertFalse($this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL)->permitsMarketing());
    }

    public function test_a_grant_is_recorded_with_its_evidence_and_projected(): void
    {
        $event = $this->ledger()->grant(
            self::STORE,
            Channel::Email,
            self::EMAIL,
            'checkout_checkbox',
            ['consent_text_version' => 'v3', 'locale' => 'ar', 'ip' => '203.0.113.9'],
            customerId: 77,
            actorType: 'customer',
        );

        $this->assertSame(ConsentState::Granted, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL));

        $row = DB::table('marketing_consent_events')->where('id', $event->id)->first();
        $this->assertSame('granted', $row->event);
        $this->assertSame('checkout_checkbox', $row->source);
        $this->assertSame(77, (int) $row->customer_id);
        $this->assertSame('customer', $row->actor_type);
        $this->assertSame('v3', json_decode($row->evidence, true)['consent_text_version']);
        $this->assertSame('s***@example.com', $row->address_hint, 'the log holds a masked hint');
        $this->assertNotNull($row->occurred_at);
    }

    public function test_the_raw_address_is_stored_nowhere(): void
    {
        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'account_settings');

        foreach (['marketing_consent_events', 'marketing_contact_states'] as $table) {
            $dump = json_encode(DB::table($table)->get()->all());

            $this->assertStringNotContainsString('shopper@example.com', strtolower((string) $dump), $table.' holds the raw address');
        }
    }

    public function test_a_different_spelling_of_the_same_address_is_the_same_contact(): void
    {
        $this->ledger()->grant(self::STORE, Channel::Email, 'shopper@example.com', 'account_settings');

        $this->assertSame(ConsentState::Granted, $this->ledger()->stateOf(self::STORE, Channel::Email, '  SHOPPER@EXAMPLE.COM '));
        $this->assertSame(1, MarketingContactState::query()->count());
    }

    public function test_withdrawal_and_a_later_opt_in(): void
    {
        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'account_settings');
        $this->ledger()->withdraw(self::STORE, Channel::Email, self::EMAIL, 'unsubscribe_link');
        $this->assertSame(ConsentState::Withdrawn, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL));

        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'account_settings');
        $this->assertSame(ConsentState::Granted, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL));

        $this->assertSame(3, MarketingConsentEvent::query()->count(), 'every decision is its own event');
        $this->assertSame(1, MarketingContactState::query()->count(), 'and there is still one current answer');
    }

    public function test_a_checkbox_cannot_undo_a_suppression_and_a_lift_does_not_grant(): void
    {
        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'account_settings');
        $this->ledger()->suppress(self::STORE, Channel::Email, self::EMAIL, 'complaint_feedback', actorType: 'system');
        $this->assertSame(ConsentState::Suppressed, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL));

        $recorded = $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'checkout_checkbox');
        $this->assertNotNull($recorded->id, 'the attempted opt-in is still recorded as evidence');
        $this->assertSame(ConsentState::Suppressed, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL),
            'but it does not lift the suppression');

        $this->ledger()->liftSuppression(self::STORE, Channel::Email, self::EMAIL, 'admin', actorType: 'merchant', actorId: 5);
        $this->assertSame(ConsentState::Withdrawn, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL),
            'lifting a suppression returns the person to withdrawn, NOT granted');

        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'account_settings');
        $this->assertSame(ConsentState::Granted, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL));
    }

    public function test_one_stores_decision_is_never_another_stores(): void
    {
        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'account_settings');

        $this->assertSame(ConsentState::Granted, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL));
        $this->assertSame(ConsentState::None, $this->ledger()->stateOf(self::OTHER_STORE, Channel::Email, self::EMAIL),
            'the same person at another merchant has NOT consented there');
    }

    public function test_one_channels_decision_is_never_another_channels(): void
    {
        $this->ledger()->grant(self::STORE, Channel::Sms, '+963 912 345 678', 'account_settings');

        $this->assertSame(ConsentState::Granted, $this->ledger()->stateOf(self::STORE, Channel::Sms, '963912345678'));
        $this->assertSame(ConsentState::None, $this->ledger()->stateOf(self::STORE, Channel::Whatsapp, '963912345678'),
            'agreeing to SMS is not agreeing to WhatsApp');
    }

    public function test_the_store_predicate_holds_even_when_a_row_carries_another_stores_hash(): void
    {
        // DEFENCE IN DEPTH, and the case a mutation run found missing. The hash already namespaces by store,
        // so removing `where store_id` from the state read changed no answer -- which means the column
        // predicate was untested and, if someone "simplified" it away, nothing would notice. This builds the
        // one situation where the two differ: a row whose hash belongs to store 11 but whose column says 12.
        $normalised = ContactAddress::normalise(Channel::Email, self::EMAIL);

        MarketingContactState::create([
            'store_id' => self::OTHER_STORE,
            'channel' => 'email',
            'purpose' => 'marketing',
            'address_hash' => ContactAddress::hash(self::STORE, Channel::Email, $normalised),
            'status' => 'granted',
            'last_event_id' => 1,
        ]);

        $this->assertSame(ConsentState::None, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL),
            'a row that belongs to another store answered for this one');
    }

    public function test_one_purposes_decision_is_never_another_purposes(): void
    {
        // The purpose is NOT part of the address hash, so this isolation rests entirely on the predicate.
        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'account_settings', purpose: 'newsletter');

        $this->assertSame(ConsentState::Granted, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL, 'newsletter'));
        $this->assertSame(ConsentState::None, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL),
            'agreeing to a newsletter is not agreeing to marketing');
    }

    public function test_a_withdrawal_at_one_store_leaves_another_stores_grant_alone(): void
    {
        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'account_settings');
        $this->ledger()->grant(self::OTHER_STORE, Channel::Email, self::EMAIL, 'account_settings');

        $this->ledger()->withdraw(self::STORE, Channel::Email, self::EMAIL, 'unsubscribe_link');

        $this->assertSame(ConsentState::Withdrawn, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL));
        $this->assertSame(ConsentState::Granted, $this->ledger()->stateOf(self::OTHER_STORE, Channel::Email, self::EMAIL));
    }

    public function test_the_projection_always_equals_the_state_rebuilt_from_the_evidence(): void
    {
        $sequences = [
            ['grant'],
            ['grant', 'withdraw', 'grant'],
            ['withdraw'],
            ['grant', 'suppress', 'grant', 'withdraw'],
            ['suppress', 'liftSuppression', 'grant', 'suppress', 'liftSuppression'],
            ['grant', 'liftSuppression', 'withdraw', 'withdraw', 'grant'],
        ];

        foreach ($sequences as $i => $steps) {
            $address = 'seq'.$i.'@example.com';

            foreach ($steps as $step) {
                $this->ledger()->{$step}(self::STORE, Channel::Email, $address, 'test');
            }

            $this->assertSame(
                $this->ledger()->rebuildState(self::STORE, Channel::Email, $address),
                $this->ledger()->stateOf(self::STORE, Channel::Email, $address),
                'projection drifted from the evidence for ['.implode(', ', $steps).']'
            );
        }
    }

    public function test_an_existing_projection_row_is_updated_not_duplicated(): void
    {
        $normalised = ContactAddress::normalise(Channel::Email, self::EMAIL);
        $hash = ContactAddress::hash(self::STORE, Channel::Email, $normalised);

        MarketingContactState::create([
            'store_id' => self::STORE, 'channel' => 'email', 'purpose' => 'marketing',
            'address_hash' => $hash, 'status' => 'withdrawn', 'last_event_id' => 0,
        ]);

        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'account_settings');

        $this->assertSame(1, MarketingContactState::query()->count());
        $this->assertSame('granted', MarketingContactState::query()->first()->status);
    }

    public function test_an_unusable_address_or_store_records_nothing(): void
    {
        foreach ([
            fn () => $this->ledger()->grant(self::STORE, Channel::Email, 'not an email', 'x'),
            fn () => $this->ledger()->grant(0, Channel::Email, self::EMAIL, 'x'),
            fn () => $this->ledger()->grant(-3, Channel::Email, self::EMAIL, 'x'),
            fn () => $this->ledger()->grant(self::STORE, Channel::Sms, 'abc', 'x'),
            fn () => $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, '   '),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('an invalid consent decision was accepted');
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        $this->assertSame(0, MarketingConsentEvent::query()->count());
        $this->assertSame(0, MarketingContactState::query()->count());
    }

    public function test_a_failed_projection_rolls_the_evidence_back_too(): void
    {
        // The event and its projection are one unit: if the second cannot be written, the first must not remain.
        $ledger = $this->ledger();
        $before = MarketingConsentEvent::query()->count();

        DB::statement('DROP TABLE marketing_contact_states');

        try {
            $ledger->grant(self::STORE, Channel::Email, self::EMAIL, 'account_settings');
            $this->fail('the projection write should have failed');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame($before, MarketingConsentEvent::query()->count(), 'an event survived its failed projection');
    }

    // ---------------------------------------------------------------- append-only

    public function test_an_event_instance_cannot_be_edited_or_deleted(): void
    {
        $event = $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'account_settings');

        /*
         * THE MESSAGE IS PART OF THE ASSERTION. The query builder this model returns ALSO refuses `update()` and
         * `delete()`, and an instance `save()`/`delete()` goes through it, so without this the two model-event
         * guards were redundant to the point of being untestable: a mutation run deleted each and no test
         * noticed. The event guard fires BEFORE the builder is reached and says "never edited" / "never
         * deleted"; the builder's own refusal says "is not available". Asserting the former pins that the
         * INSTANCE path is refused by the model itself, which is what survives if the builder is ever swapped.
         */
        foreach ([
            ['is never edited', fn () => $event->update(['event' => 'withdrawn'])],
            ['is never edited', fn () => $event->forceFill(['source' => 'tampered'])->save()],
            ['is never deleted', fn () => $event->delete()],
        ] as [$expected, $attempt]) {
            try {
                $attempt();
                $this->fail('an append-only event was modified');
            } catch (LogicException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
                $this->assertStringContainsString($expected, $e->getMessage(),
                    'the refusal came from the builder, not from the model: '.$e->getMessage());
            }
        }

        $stored = DB::table('marketing_consent_events')->where('id', $event->id)->first();
        $this->assertSame('granted', $stored->event);
        $this->assertSame('account_settings', $stored->source);
    }

    public function test_the_query_builder_path_is_closed_too(): void
    {
        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'account_settings');

        foreach ([
            fn () => MarketingConsentEvent::query()->update(['event' => 'withdrawn']),
            fn () => MarketingConsentEvent::query()->where('store_id', self::STORE)->delete(),
            fn () => MarketingConsentEvent::query()->forceDelete(),
            fn () => MarketingConsentEvent::query()->truncate(),
            fn () => MarketingConsentEvent::query()->increment('actor_id'),
            fn () => MarketingConsentEvent::query()->upsert([['id' => 1]], ['id']),
        ] as $i => $attempt) {
            try {
                $attempt();
                $this->fail('mass statement #'.$i.' reached the append-only table');
            } catch (LogicException) {
                // expected
            }
        }

        $this->assertSame(1, DB::table('marketing_consent_events')->count());
        $this->assertSame('granted', DB::table('marketing_consent_events')->value('event'));
    }
}
