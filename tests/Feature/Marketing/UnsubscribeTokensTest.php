<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingUnsubscribeToken;
use App\Services\Marketing\ConsentLedger;
use App\Services\Marketing\UnsubscribeTokens;
use App\Support\Marketing\Channel;
use App\Support\Marketing\ConsentState;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * THE UNSUBSCRIBE CAPABILITY. D8 / GAP-200 / MKT-008.
 *
 * "A secure unsubscribe mechanism" means four things here, and each is a case: the token cannot be
 * forged (random, not signed), reading the table cannot yield a working link (only the hash is stored),
 * a link can do exactly one thing (withdraw the contact it was issued for), and having a link does not
 * reveal whose it is.
 */
class UnsubscribeTokensTest extends TestCase
{
    use RefreshDatabase;

    private const STORE = 31;
    private const EMAIL = 'reader@example.com';

    private function tokens(): UnsubscribeTokens
    {
        return new UnsubscribeTokens(new ConsentLedger());
    }

    private function ledger(): ConsentLedger
    {
        return new ConsentLedger();
    }

    public function test_a_token_is_43_url_safe_characters_and_never_repeats(): void
    {
        $seen = [];

        for ($i = 0; $i < 25; $i++) {
            $token = $this->tokens()->issue(self::STORE, Channel::Email, self::EMAIL);

            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
            $this->assertArrayNotHasKey($token, $seen);
            $seen[$token] = true;
        }
    }

    public function test_only_the_hash_is_stored_and_the_plain_token_is_nowhere_in_the_table(): void
    {
        $token = $this->tokens()->issue(self::STORE, Channel::Email, self::EMAIL, customerId: 9);

        $row = DB::table('marketing_unsubscribe_tokens')->first();

        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertStringNotContainsString($token, (string) json_encode($row), 'the plain token leaked into a column');
    }

    public function test_the_row_never_holds_the_address_only_a_hash_and_a_masked_hint(): void
    {
        $this->tokens()->issue(self::STORE, Channel::Email, self::EMAIL);

        $row = DB::table('marketing_unsubscribe_tokens')->first();

        $this->assertStringNotContainsString('reader@example.com', strtolower((string) json_encode($row)));
        $this->assertSame('r***@example.com', $row->address_hint);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row->address_hash);
    }

    public function test_the_token_model_hides_its_hash_when_serialised(): void
    {
        $this->tokens()->issue(self::STORE, Channel::Email, self::EMAIL);

        $this->assertArrayNotHasKey('token_hash', MarketingUnsubscribeToken::query()->first()->toArray());
    }

    public function test_redeeming_withdraws_exactly_the_contact_it_was_issued_for(): void
    {
        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'account_settings');
        $this->ledger()->grant(self::STORE, Channel::Email, 'someone.else@example.com', 'account_settings');
        $this->ledger()->grant(self::STORE + 1, Channel::Email, self::EMAIL, 'account_settings');
        $this->ledger()->grant(self::STORE, Channel::Sms, '963912345678', 'account_settings');

        $token = $this->tokens()->issue(self::STORE, Channel::Email, self::EMAIL);
        $event = $this->tokens()->redeem($token, self::STORE, 'unsubscribe_one_click', ['user_agent' => 'Mailer/1.0']);

        $this->assertNotNull($event);
        $this->assertSame('withdrawn', $event->event);
        $this->assertSame('unsubscribe_one_click', $event->source);

        $this->assertSame(ConsentState::Withdrawn, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL));
        $this->assertSame(ConsentState::Granted, $this->ledger()->stateOf(self::STORE, Channel::Email, 'someone.else@example.com'), 'another contact');
        $this->assertSame(ConsentState::Granted, $this->ledger()->stateOf(self::STORE + 1, Channel::Email, self::EMAIL), 'another store');
        $this->assertSame(ConsentState::Granted, $this->ledger()->stateOf(self::STORE, Channel::Sms, '963912345678'), 'another channel');
    }

    public function test_redeeming_even_when_nothing_was_ever_granted_records_the_withdrawal(): void
    {
        $token = $this->tokens()->issue(self::STORE, Channel::Email, self::EMAIL);

        $this->assertNotNull($this->tokens()->redeem($token, self::STORE));
        $this->assertSame(ConsentState::Withdrawn, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL),
            'a person who clicks unsubscribe is opted out whatever the ledger said before');
    }

    public function test_using_a_link_twice_withdraws_twice_records_both_and_keeps_the_first_use_time(): void
    {
        // TIME IS FROZEN AND MOVED. The earlier version of this test compared two timestamps taken a moment apart, and the date cast
        // reads a re-written identical second as unchanged, so deleting the guard did not fail it. An hour between the two uses makes
        // "the first use time is kept" something that can be false.
        Carbon::setTestNow('2026-11-18 10:00:00');
        $token = $this->tokens()->issue(self::STORE, Channel::Email, self::EMAIL);

        $this->tokens()->redeem($token, self::STORE);
        $this->assertSame('2026-11-18 10:00:00', DB::table('marketing_unsubscribe_tokens')->value('used_at'));

        Carbon::setTestNow('2026-11-18 11:00:00');
        $this->tokens()->redeem($token, self::STORE);
        Carbon::setTestNow();

        $this->assertSame('2026-11-18 10:00:00', DB::table('marketing_unsubscribe_tokens')->value('used_at'), 'a later use moved the first-use time');
        $this->assertSame(ConsentState::Withdrawn, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL));
        $this->assertSame(2, DB::table('marketing_consent_events')->count(), 'each use is evidence');
    }

    public function test_a_link_is_reusable_so_an_old_click_after_a_new_opt_in_withdraws_it_again(): void
    {
        // CHARACTERISATION, not endorsement. A link that stops working is a failure to honour an unsubscribe, so it is reusable;
        // the consequence is that a REPLAY after the person has opted in again withdraws the new opt-in. That is why the route
        // that calls this must not redeem on GET (prefetchers and mail scanners follow GETs). Pinned so nobody is surprised.
        $token = $this->tokens()->issue(self::STORE, Channel::Email, self::EMAIL);

        $this->tokens()->redeem($token, self::STORE);
        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'account_settings');
        $this->assertSame(ConsentState::Granted, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL));

        $this->tokens()->redeem($token, self::STORE);

        $this->assertSame(ConsentState::Withdrawn, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL));
    }

    public function test_a_token_opened_under_another_store_is_treated_as_unknown(): void
    {
        $token = $this->tokens()->issue(self::STORE, Channel::Email, self::EMAIL);
        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'account_settings');

        $this->assertNull($this->tokens()->find($token, self::STORE + 1), 'a token for store A was honoured as if opened at store B');
        $this->assertNull($this->tokens()->redeem($token, self::STORE + 1));
        $this->assertSame(ConsentState::Granted, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL), 'the mismatched redemption withdrew anyway');
        $this->assertNull(DB::table('marketing_unsubscribe_tokens')->value('used_at'));

        $this->assertNotNull($this->tokens()->find($token, self::STORE), 'the right store was refused');
        $this->assertNotNull($this->tokens()->redeem($token, self::STORE));
        $this->assertSame(ConsentState::Withdrawn, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL));
    }

    public function test_a_hostile_user_agent_cannot_stop_an_unsubscribe(): void
    {
        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'account_settings');
        $token = $this->tokens()->issue(self::STORE, Channel::Email, self::EMAIL);

        $event = $this->tokens()->redeem($token, self::STORE, 'unsubscribe_one_click', ['ip' => '203.0.113.9', 'user_agent' => "scanner/1.0 \xC3\x28 \xFF"]);

        $this->assertNotNull($event, 'one invalid byte in a header turned an unsubscribe into a failure');
        $this->assertSame(ConsentState::Withdrawn, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL));
        $this->assertNotNull(DB::table('marketing_unsubscribe_tokens')->value('used_at'));
    }

    public function test_issuing_refuses_a_purpose_that_would_not_fit_its_column(): void
    {
        foreach (['Marketing', str_repeat('p', 33), '', 'two words'] as $purpose) {
            try {
                $this->tokens()->issue(self::STORE, Channel::Email, self::EMAIL, purpose: $purpose);
                $this->fail("a token was issued for the purpose '{$purpose}'");
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        $this->assertSame(0, DB::table('marketing_unsubscribe_tokens')->count());
    }

    public function test_a_token_cannot_grant_and_cannot_lift_a_suppression(): void
    {
        $this->ledger()->suppress(self::STORE, Channel::Email, self::EMAIL, 'complaint_feedback');

        $token = $this->tokens()->issue(self::STORE, Channel::Email, self::EMAIL);
        $this->tokens()->redeem($token, self::STORE);

        $this->assertSame(ConsentState::Suppressed, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL),
            'withdrawing is not lifting');

        // THE WHOLE PUBLIC SURFACE, not a blocklist of method NAMES (a granting method called `reinstate()` would have passed the old check).
        // A new public method is a new thing a public page can be made to call, so adding one must change this list on purpose.
        $this->assertEqualsCanonicalizing(
            ['__construct', 'issue', 'find', 'redeem', 'hashOf'],
            get_class_methods(UnsubscribeTokens::class),
            'the token service gained a public method; read what a public page can now make it do'
        );
    }

    #[DataProvider('malformedTokens')]
    public function test_a_malformed_token_is_refused_before_anything_is_queried(mixed $token): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->assertNull($this->tokens()->find($token, self::STORE));
        $this->assertNull($this->tokens()->redeem($token, self::STORE));
        $this->assertSame(0, $queries, 'a value that cannot be a token must not reach the database');
    }

    public static function malformedTokens(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'short' => [str_repeat('a', 42)],
            'long' => [str_repeat('a', 44)],
            'wrong alphabet' => [str_repeat('+', 43)],
            'padding' => [str_repeat('a', 42).'='],
            'sql-ish' => ["' OR '1'='1".str_repeat('a', 30)],
            'array' => [['a']],
        ];
    }

    public function test_a_well_formed_but_unknown_token_changes_nothing(): void
    {
        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'account_settings');

        $this->assertNull($this->tokens()->redeem(str_repeat('A', 43), self::STORE));
        $this->assertSame(ConsentState::Granted, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL));
    }

    public function test_a_token_for_one_store_cannot_be_used_to_reach_another(): void
    {
        $this->ledger()->grant(self::STORE + 1, Channel::Email, self::EMAIL, 'account_settings');

        $token = $this->tokens()->issue(self::STORE, Channel::Email, self::EMAIL);
        $this->tokens()->redeem($token, self::STORE);

        $this->assertSame(ConsentState::Granted, $this->ledger()->stateOf(self::STORE + 1, Channel::Email, self::EMAIL));
    }

    public function test_issuing_needs_a_store_and_a_usable_address(): void
    {
        foreach ([
            fn () => $this->tokens()->issue(0, Channel::Email, self::EMAIL),
            fn () => $this->tokens()->issue(self::STORE, Channel::Email, 'not an email'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('a token was issued for nobody');
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        $this->assertSame(0, DB::table('marketing_unsubscribe_tokens')->count());
    }
}
