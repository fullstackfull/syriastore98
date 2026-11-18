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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * WHAT THE INDEPENDENT REVIEW OF THE CONSENT LEDGER FOUND, PINNED. D8-1 review, findings F1, F2, F5, F6 and the audit
 * that F4 said was missing.
 *
 * THE RACES THEMSELVES CANNOT BE SEEN HERE. This suite runs on SQLite `:memory:`, where `lockForUpdate()` compiles to
 * nothing and there is one connection. They were measured on a real server by
 * `tests/Support/Security/bin/verify-consent-ledger-concurrency-on-mysql.php` (before the fix: log `1:granted 2:granted
 * 3:withdrawn`, projection `granted`; a `1213 Deadlock found` that lost a request). What CAN be pinned here is the
 * property those races depend on -- the ORDER of the statements -- and the two code paths a race would take.
 */
class ConsentLedgerOrderingAndEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private const STORE = 31;
    private const OTHER_STORE = 32;
    private const EMAIL = 'ordering@example.com';

    private function ledger(): ConsentLedger
    {
        return new ConsentLedger();
    }

    private function hash(int $store = self::STORE, string $address = self::EMAIL): string
    {
        return ContactAddress::hash($store, Channel::Email, ContactAddress::normalise(Channel::Email, $address));
    }

    // ------------------------------------------------------------------ F1: lock first

    public function test_the_contact_is_locked_before_its_event_is_inserted(): void
    {
        DB::enableQueryLog();
        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'checkout_checkbox');

        $statements = array_map(fn (array $q) => strtolower($q['query']), DB::getQueryLog());

        $create = $this->firstMatching($statements, '/^insert into ["`]?marketing_contact_states["`]?/');
        $lockRead = $this->firstMatching($statements, '/^select .*from ["`]?marketing_contact_states["`]?/');
        $eventInsert = $this->firstMatching($statements, '/^insert into ["`]?marketing_consent_events["`]?/');
        $fold = $this->firstMatching($statements, '/^update ["`]?marketing_contact_states["`]?/');

        foreach (['row creation' => $create, 'lock read' => $lockRead, 'event insert' => $eventInsert, 'fold' => $fold] as $what => $at) {
            $this->assertNotNull($at, "the {$what} never happened: ".implode(' | ', $statements));
        }

        // THE ORDER IS THE CORRECTNESS ARGUMENT, and there are two orderings in it. An event gets its id at INSERT and a replay folds in id
        // order, so the lock that serialises the contact must be held before the id exists and until the fold is done (locking after the
        // insert let a later-id withdrawal be folded first and overwritten by an earlier-id grant: measured on MariaDB). And the row is
        // CREATED before it is READ for update, because a locking read of an absent key takes a gap lock and two writers then deadlock.
        $this->assertLessThan($lockRead, $create, 'the row was read for update before it was created: that is the gap-lock deadlock');
        $this->assertLessThan($eventInsert, $lockRead, 'the event was inserted before the contact was locked: the log and the projection can now disagree');
        $this->assertLessThan($fold, $eventInsert, 'the projection was updated before the event existed');
    }

    /** @param  list<string>  $statements */
    private function firstMatching(array $statements, string $pattern): ?int
    {
        foreach ($statements as $i => $sql) {
            if (preg_match($pattern, $sql) === 1) {
                return $i;
            }
        }

        return null;
    }

    public function test_the_row_creation_is_an_upsert_that_cannot_reset_a_decision(): void
    {
        // The creation statement runs for EVERY event, not only the first, so what it does to an existing row matters. It must be a no-op:
        // an update list that included `status` would reset a racing writer's withdrawal to `none` between its commit and our read.
        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'checkout_checkbox');
        $this->ledger()->suppress(self::STORE, Channel::Email, self::EMAIL, 'complaint_feedback');

        DB::enableQueryLog();
        $event = $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'account_settings');

        $creates = array_values(array_filter(DB::getQueryLog(), fn (array $q) => (bool) preg_match('/^insert into ["`]?marketing_contact_states["`]?/i', $q['query'])));
        $this->assertCount(1, $creates, 'the creation statement did not run for an existing contact');
        $this->assertDoesNotMatchRegularExpression('/"?`?status"?`?\s*=\s*["`]?excluded|values\(["`]?status/i', $creates[0]['query'], 'the upsert updates `status` on a duplicate');

        // Behaviourally: a suppressed contact stays suppressed after the grant, and the row names the new event.
        $this->assertSame(ConsentState::Suppressed, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL));
        $this->assertSame((int) $event->id, (int) MarketingContactState::query()->where('store_id', self::STORE)->value('last_event_id'));
        $this->assertSame(1, MarketingContactState::query()->where('store_id', self::STORE)->count());
    }

    public function test_the_lock_the_insert_and_the_fold_happen_inside_one_transaction_the_ledger_opened(): void
    {
        // SQLite compiles `lockForUpdate()` to nothing, so the LOCK cannot be seen here; what can be seen is that the three statements share a
        // transaction deeper than the caller's, which is what makes the lock last until the fold is committed (and the event roll back with it).
        $baseline = DB::transactionLevel();
        $levels = [];

        DB::listen(function ($query) use (&$levels) {
            $sql = strtolower($query->sql);

            if (preg_match('/^select .*from ["`]?marketing_contact_states["`]?/', $sql)) {
                $levels['lock'] = DB::transactionLevel();
            } elseif (preg_match('/^insert into ["`]?marketing_consent_events["`]?/', $sql)) {
                $levels['event'] = DB::transactionLevel();
            } elseif (preg_match('/^update ["`]?marketing_contact_states["`]?/', $sql)) {
                $levels['fold'] = DB::transactionLevel();
            }
        });

        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'checkout_checkbox');

        $this->assertSame(['lock', 'event', 'fold'], array_keys($levels));
        foreach ($levels as $statement => $level) {
            $this->assertGreaterThan($baseline, $level, "the {$statement} statement ran outside the transaction the ledger opened");
        }
        $this->assertSame(1, count(array_unique($levels)), 'the three statements did not share one transaction level');
    }

    public function test_the_projection_read_asks_for_a_lock(): void
    {
        // The statements above cannot show a lock on SQLite, so this pins it at the source: the method that fetches the row must request one.
        $method = new \ReflectionMethod(ConsentLedger::class, 'lockProjection');
        $lines = file((string) $method->getFileName());
        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        $this->assertStringContainsString('lockForUpdate()', $body, 'the projection row is read without a lock, so two writers can fold out of order');
        $this->assertStringContainsString('upsert(', $body, 'the row is no longer created by an upsert, which is what avoids the gap lock');
    }

    // ------------------------------------------------------------------ the event and its fold are one unit

    public function test_a_failure_after_the_event_is_inserted_rolls_the_event_back(): void
    {
        // The failure has to land AFTER the event insert, on the fold. (The earlier version dropped the projection table, which since the
        // lock-first order fails on the very first statement, before any event exists -- so it no longer tested rollback at all.)
        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'checkout_checkbox');
        $before = MarketingConsentEvent::query()->count();

        MarketingContactState::updating(function () {
            throw new \RuntimeException('the fold failed');
        });

        try {
            $this->ledger()->withdraw(self::STORE, Channel::Email, self::EMAIL, 'unsubscribe_link');
            $this->fail('the fold should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('the fold failed', $e->getMessage());
        } finally {
            MarketingContactState::flushEventListeners();
        }

        $this->assertSame($before, MarketingConsentEvent::query()->count(), 'an event survived the failure of its fold');
        $this->assertSame(ConsentState::Granted, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL), 'the projection moved without its event');
    }

    // ------------------------------------------------------------------ F5: evidence cannot lose a withdrawal

    public function test_evidence_that_is_not_valid_utf8_does_not_stop_a_withdrawal(): void
    {
        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'checkout_checkbox');

        // A scanner or a hostile client controls the User-Agent. One bad byte must not turn an unsubscribe into a 500.
        $event = $this->ledger()->withdraw(
            self::STORE,
            Channel::Email,
            self::EMAIL,
            'unsubscribe_link',
            ['user_agent' => "Mozilla/5.0 \xB1\x31 probe", 'ip' => '203.0.113.9'],
        );

        $this->assertSame(ConsentState::Withdrawn, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL));

        $stored = json_decode((string) DB::table('marketing_consent_events')->where('id', $event->id)->value('evidence'), true);
        $this->assertSame('203.0.113.9', $stored['ip'], 'the rest of the evidence was lost along with the bad byte');
        $this->assertStringContainsString("\u{FFFD}", $stored['user_agent'], 'the invalid byte was not substituted');
        $this->assertStringStartsWith('Mozilla/5.0 ', $stored['user_agent']);
    }

    public function test_evidence_that_cannot_be_encoded_is_replaced_by_a_marker_not_an_exception(): void
    {
        $deep = 'leaf';
        for ($i = 0; $i < 40; $i++) { // far deeper than any request metadata is
            $deep = [$deep];
        }

        $event = $this->ledger()->withdraw(self::STORE, Channel::Email, self::EMAIL, 'unsubscribe_link', ['ip' => '203.0.113.9', 'too_deep' => $deep]);

        $this->assertSame(ConsentState::Withdrawn, $this->ledger()->stateOf(self::STORE, Channel::Email, self::EMAIL));

        $stored = json_decode((string) DB::table('marketing_consent_events')->where('id', $event->id)->value('evidence'), true);
        $this->assertIsArray($stored, 'unencodable evidence was dropped without a trace');
        $this->assertArrayHasKey('_note', $stored, 'unencodable evidence was altered without saying so');
        $this->assertSame('203.0.113.9', $stored['ip'], 'the part that WAS encodable was lost with the part that was not');
    }

    public function test_evidence_with_a_value_that_cannot_be_encoded_at_all_is_kept_apart_from_it(): void
    {
        $event = $this->ledger()->withdraw(self::STORE, Channel::Email, self::EMAIL, 'unsubscribe_link', ['ip' => '203.0.113.9', 'weight' => NAN]);

        $stored = json_decode((string) DB::table('marketing_consent_events')->where('id', $event->id)->value('evidence'), true);
        $this->assertSame('203.0.113.9', $stored['ip']);
        $this->assertArrayHasKey('_note', $stored);
    }

    public function test_oversized_evidence_is_replaced_by_a_marker_that_says_how_big_it_was(): void
    {
        $event = $this->ledger()->withdraw(self::STORE, Channel::Email, self::EMAIL, 'unsubscribe_link', ['user_agent' => str_repeat('A', 10000)]);

        $stored = json_decode((string) DB::table('marketing_consent_events')->where('id', $event->id)->value('evidence'), true);

        $this->assertStringContainsString('exceeded', $stored['_note']);
        $this->assertGreaterThan(10000, $stored['_bytes']);
        $this->assertArrayNotHasKey('user_agent', $stored);
    }

    public function test_ordinary_evidence_round_trips_and_empty_evidence_is_null(): void
    {
        $with = $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'checkout_checkbox', ['consent_text_version' => 'v3', 'locale' => 'ar']);
        $without = $this->ledger()->grant(self::STORE, Channel::Email, 'other@example.com', 'checkout_checkbox');

        $this->assertSame(['consent_text_version' => 'v3', 'locale' => 'ar'], json_decode((string) DB::table('marketing_consent_events')->where('id', $with->id)->value('evidence'), true));
        $this->assertNull(DB::table('marketing_consent_events')->where('id', $without->id)->value('evidence'));
    }

    // ------------------------------------------------------------------ F6: nothing is silently truncated

    /** @return array<string, array{0: callable(ConsentLedger): mixed}> */
    public static function valuesThatWouldNotFit(): array
    {
        return [
            'purpose in capitals' => [fn (ConsentLedger $l) => $l->grant(self::STORE, Channel::Email, self::EMAIL, 'x', purpose: 'Marketing')],
            'purpose longer than its column' => [fn (ConsentLedger $l) => $l->grant(self::STORE, Channel::Email, self::EMAIL, 'x', purpose: str_repeat('a', 33))],
            'empty purpose' => [fn (ConsentLedger $l) => $l->grant(self::STORE, Channel::Email, self::EMAIL, 'x', purpose: '')],
            'purpose with a space' => [fn (ConsentLedger $l) => $l->grant(self::STORE, Channel::Email, self::EMAIL, 'x', purpose: 'news letter')],
            'source longer than its column' => [fn (ConsentLedger $l) => $l->grant(self::STORE, Channel::Email, self::EMAIL, str_repeat('s', 49))],
            'actor type longer than its column' => [fn (ConsentLedger $l) => $l->grant(self::STORE, Channel::Email, self::EMAIL, 'x', actorType: str_repeat('t', 17))],
        ];
    }

    #[DataProvider('valuesThatWouldNotFit')]
    public function test_a_value_that_would_not_fit_its_column_is_refused_not_truncated(callable $attempt): void
    {
        try {
            $attempt($this->ledger());
            $this->fail('a value that does not fit its column was accepted (MySQL non-strict would have truncated it silently)');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(0, MarketingConsentEvent::query()->count());
        $this->assertSame(0, MarketingContactState::query()->count());
    }

    public function test_values_at_the_limit_are_accepted(): void
    {
        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, str_repeat('s', 48), actorType: str_repeat('t', 16), purpose: str_repeat('a', 32));

        $this->assertSame(1, MarketingConsentEvent::query()->count());
    }

    public function test_a_long_hint_is_cut_to_the_column_instead_of_being_truncated_by_the_server(): void
    {
        $event = $this->ledger()->withdrawByHash(self::STORE, Channel::Email, $this->hash(), str_repeat('h', 400), 'unsubscribe_link');

        $this->assertSame(191, mb_strlen((string) DB::table('marketing_consent_events')->where('id', $event->id)->value('address_hint')));
    }

    // ------------------------------------------------------------------ F4: something that can tell the two disagree

    public function test_divergences_is_empty_when_every_projection_matches_its_log(): void
    {
        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'checkout_checkbox');
        $this->ledger()->suppress(self::STORE, Channel::Email, 'complaint@example.com', 'webhook');
        $this->ledger()->liftSuppression(self::STORE, Channel::Email, 'complaint@example.com', 'admin');

        $this->assertSame([], $this->ledger()->divergences(self::STORE));
    }

    public function test_a_projection_that_disagrees_with_its_log_is_named_without_naming_the_person(): void
    {
        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'checkout_checkbox');
        $this->ledger()->withdraw(self::STORE, Channel::Email, self::EMAIL, 'unsubscribe_link');

        // The failure the ordering fix prevents, reproduced by hand: the log says withdrawn, the row the gate reads says granted.
        DB::table('marketing_contact_states')->where('store_id', self::STORE)->update(['status' => 'granted']);

        $found = $this->ledger()->divergences(self::STORE);

        $this->assertCount(1, $found);
        $this->assertSame('granted', $found[0]['projected']);
        $this->assertSame('withdrawn', $found[0]['replayed']);
        $this->assertSame('email', $found[0]['channel']);
        $this->assertSame(substr($this->hash(), 0, 12), $found[0]['contact']);
        $this->assertStringNotContainsString('ordering@example.com', json_encode($found));
        $this->assertStringNotContainsString($this->hash(), json_encode($found), 'the full hash was reported, not a prefix');
    }

    public function test_a_contact_with_a_log_and_no_projection_row_is_named(): void
    {
        $this->ledger()->grant(self::STORE, Channel::Email, self::EMAIL, 'checkout_checkbox');
        DB::table('marketing_contact_states')->where('store_id', self::STORE)->delete();

        $found = $this->ledger()->divergences(self::STORE);

        $this->assertCount(1, $found);
        $this->assertSame('none', $found[0]['projected'], 'the gate reads an absent row as none');
        $this->assertSame('granted', $found[0]['replayed']);
    }

    public function test_divergences_looks_at_one_store_only(): void
    {
        $this->ledger()->grant(self::OTHER_STORE, Channel::Email, self::EMAIL, 'checkout_checkbox');
        DB::table('marketing_contact_states')->where('store_id', self::OTHER_STORE)->update(['status' => 'withdrawn']);

        $this->assertSame([], $this->ledger()->divergences(self::STORE), 'another store\'s divergence was reported to this one');
        $this->assertCount(1, $this->ledger()->divergences(self::OTHER_STORE));
        $this->assertSame([], $this->ledger()->divergences(0));
    }
}
