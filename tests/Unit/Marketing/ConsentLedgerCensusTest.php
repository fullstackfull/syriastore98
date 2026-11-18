<?php

namespace Tests\Unit\Marketing;

use PHPUnit\Framework\TestCase;

/**
 * WHO MAY WRITE CONSENT, AND WHO MAY NOT. D8 / MKT-008.
 *
 * Three structural facts that no behavioural test can establish, because each is about code that does
 * NOT exist yet:
 *
 *   1. NOTHING GRANTS CONSENT FROM AN ORDER, A REGISTRATION OR AN IMPORT. The owner's direction is that
 *      consent is never inferred from historical orders or existing contacts. `GRANT_CALLERS` is the
 *      exhaustive list of files allowed to call a granting method, and it is EMPTY in this change: no
 *      opt-in surface has been built. The day one is, it is added here deliberately and a reviewer sees
 *      which file now records permission and from what form.
 *   2. ONLY THE LEDGER (AND THE TOKEN SERVICE) TOUCH THE CONSENT TABLES. A second writer could put a row in `granted`
 *      that no event supports, or append an event the projection never hears about, and the send gate reads the
 *      projection. The first version of this census matched a handful of write verbs -- `::create`, `::query()->update`,
 *      `DB::table('...')` -- and an independent review showed it missed `::where()->update()`, `::forceCreate`,
 *      `::updateOrInsert`, `::truncate`, `::destroy`, `app(Model::class)`, `DB::connection('x')->table(...)` and
 *      `DELETE FROM`, and did not look at the evidence model's Eloquent INSERTs at all. A list of verbs cannot be
 *      exhaustive, so this census inverts the question: it does not ask "does this file WRITE", it asks "does this file
 *      NAME the model or the table", and the answer must be on an explicit list per class (MAY_NAME_THE_MODEL). A file
 *      that only reads consent through `ConsentLedger::stateOf()` or `MarketingSendGate` never names a model and needs
 *      nothing; a file that wants to must be added here, on purpose, where a reviewer sees it.
 *   3. THE TABLE NAMES APPEAR ONLY IN THEIR OWN MODEL AND THEIR MIGRATIONS, which is what keeps raw SQL
 *      (`DB::table`, `DB::connection()->table`, `DELETE FROM`, `TRUNCATE`) out. A table name assembled at run time cannot
 *      be seen by any text census, and that limit is stated here instead of implied.
 *
 * Comments are stripped before searching, so prose that names these things cannot satisfy or trip a check.
 */
class ConsentLedgerCensusTest extends TestCase
{
    /** @var list<string> repo-relative files allowed to call grant()/liftSuppression() on a ConsentLedger. EMPTY on purpose. */
    private const GRANT_CALLERS = [];

    /**
     * Who may call `withdrawByHash()`. The hash of (store, channel, address) is computable by anyone who knows a store id and can guess an
     * address -- by design, the sender must be able to recompute it -- so a bare hash is NOT a credential. The only thing that turns one
     * into permission to withdraw is a TOKEN, and `UnsubscribeTokens` is the one class that holds the token-to-hash binding. A route
     * that forwarded a request-supplied hash here would be an unauthenticated "withdraw anyone" endpoint, so a new caller is added on purpose.
     *
     * @var list<string>
     */
    private const WITHDRAW_BY_HASH_CALLERS = ['app/Services/Marketing/UnsubscribeTokens.php'];

    /**
     * Who may NAME each consent model (import it, type-hint it, `::` it, `app()` it). Anything not listed is a finding.
     *
     * @var array<string, list<string>>
     */
    private const MAY_NAME_THE_MODEL = [
        'MarketingConsentEvent' => [
            'app/Models/MarketingConsentEvent.php',
            'app/Services/Marketing/ConsentLedger.php',
            'app/Services/Marketing/UnsubscribeTokens.php', // return type of redeem()
        ],
        'MarketingContactState' => [
            'app/Models/MarketingContactState.php',
            'app/Services/Marketing/ConsentLedger.php',
        ],
        'MarketingUnsubscribeToken' => [
            'app/Models/MarketingUnsubscribeToken.php',
            'app/Services/Marketing/UnsubscribeTokens.php',
        ],
    ];

    /**
     * The three migrations that create the consent tables, by EXACT name. An earlier version exempted every file whose name began with
     * the date they were written, which would also have exempted a backfill migration written that day -- and D8 forbids a backfill.
     *
     * @var list<string>
     */
    private const MIGRATIONS = [
        'database/migrations/2026_11_18_000100_create_marketing_consent_events_table.php',
        'database/migrations/2026_11_18_000200_create_marketing_contact_states_table.php',
        'database/migrations/2026_11_18_000300_create_marketing_unsubscribe_tokens_table.php',
    ];

    /** @var array<string, string> table => the one non-migration file that may name it */
    private const TABLE_OWNER = [
        'marketing_consent_events' => 'app/Models/MarketingConsentEvent.php',
        'marketing_contact_states' => 'app/Models/MarketingContactState.php',
        'marketing_unsubscribe_tokens' => 'app/Models/MarketingUnsubscribeToken.php',
    ];

    /** @return array<string, string> repo-relative path => comment-stripped source */
    private function sources(): array
    {
        $root = dirname(__DIR__, 3);
        $out = [];

        foreach (['app', 'packages/workdo', 'routes', 'bootstrap', 'config', 'database', 'resources'] as $dir) {
            $path = $root.'/'.$dir;

            if (! is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen($root) + 1);
                $code = '';

                foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                    if (is_array($token)) {
                        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                            continue;
                        }
                        $code .= $token[1];
                    } else {
                        $code .= $token;
                    }
                }

                $out[$relative] = $code;
            }
        }

        return $out;
    }

    public function test_the_premise_the_ledger_and_its_models_exist(): void
    {
        $sources = $this->sources();

        foreach (['app/Services/Marketing/ConsentLedger.php', 'app/Models/MarketingContactState.php', 'app/Models/MarketingConsentEvent.php'] as $file) {
            $this->assertArrayHasKey($file, $sources, $file.' has moved: this census is reading the wrong tree');
        }
    }

    /**
     * @param  array<string, string>  $sources
     * @return list<string>
     */
    private function grantOffenders(array $sources): array
    {
        $offenders = [];

        foreach ($sources as $file => $code) {
            if ($file === 'app/Services/Marketing/ConsentLedger.php' || ! str_contains($code, 'ConsentLedger')) {
                continue;
            }

            // A direct call, the verb as a string (`[$ledger, 'grant']`, `call_user_func`), or a dynamic method name (`$ledger->{$verb}(...)`).
            // Only files that name the ledger are examined, so the dynamic-call test is not a ban on `->{` anywhere else.
            $grants = preg_match('/->\s*(grant|liftSuppression)\s*\(/', $code)
                || preg_match('/[\'"](grant|liftSuppression)[\'"]/', $code)
                || preg_match('/->\s*\{/', $code);

            if ($grants && ! in_array($file, self::GRANT_CALLERS, true)) {
                $offenders[] = $file;
            }
        }

        return $offenders;
    }

    public function test_only_the_listed_files_call_a_granting_method(): void
    {
        $this->assertSame([], $this->grantOffenders($this->sources()),
            "These files call a granting method on the consent ledger and are not on the allow-list. Consent is never inferred "
            ."from an order, a registration or an import; a new opt-in surface must be added to GRANT_CALLERS on purpose.");
    }

    /**
     * @param  array<string, string>  $sources  repo-relative path => comment-stripped source
     * @return list<string>
     */
    private function modelNameOffenders(array $sources): array
    {
        $offenders = [];

        foreach ($sources as $file => $code) {
            foreach (self::MAY_NAME_THE_MODEL as $model => $allowed) {
                if (preg_match('/\b'.$model.'\b/', $code) && ! in_array($file, $allowed, true)) {
                    $offenders[] = $file.' names '.$model;
                }
            }
        }

        return $offenders;
    }

    /**
     * @param  array<string, string>  $sources
     * @return list<string>
     */
    private function tableNameOffenders(array $sources): array
    {
        $offenders = [];

        foreach ($sources as $file => $code) {
            foreach (self::TABLE_OWNER as $table => $owner) {
                if (! str_contains($code, $table)) {
                    continue;
                }

                if ($file === $owner || in_array($file, self::MIGRATIONS, true)) {
                    continue;
                }

                $offenders[] = $file.' names '.$table;
            }
        }

        return $offenders;
    }

    /**
     * @param  array<string, string>  $sources
     * @return list<string>
     */
    private function bareHashWithdrawalOffenders(array $sources): array
    {
        $offenders = [];

        foreach ($sources as $file => $code) {
            if ($file === 'app/Services/Marketing/ConsentLedger.php' || in_array($file, self::WITHDRAW_BY_HASH_CALLERS, true)) {
                continue;
            }

            if (preg_match('/\bwithdrawByHash\b/', $code)) {
                $offenders[] = $file;
            }
        }

        return $offenders;
    }

    public function test_only_the_token_service_withdraws_from_a_bare_hash(): void
    {
        $this->assertSame([], $this->bareHashWithdrawalOffenders($this->sources()),
            'withdrawByHash() accepts a hash, and a hash is not a credential. Only UnsubscribeTokens may call it, because only a token proves the holder was sent the link.');
    }

    public function test_only_the_listed_files_name_a_consent_model(): void
    {
        $this->assertSame([], $this->modelNameOffenders($this->sources()),
            "These files name a consent model and are not on its list. The projection is what the send gate reads, so a file that can write it "
            ."can grant consent no event supports; a file that can write the evidence can append what the projection never hears about. "
            ."Add a file to MAY_NAME_THE_MODEL on purpose, after reading what it does with the model.");
    }

    public function test_the_consent_table_names_appear_only_in_their_model_and_their_migration(): void
    {
        $this->assertSame([], $this->tableNameOffenders($this->sources()),
            'A consent table name outside its own model and its migration is raw SQL or a second model, and either bypasses the append-only guards.');
    }

    public function test_the_census_catches_the_shapes_the_first_version_missed_and_spares_the_listed_files(): void
    {
        // A census that cannot fail is a trap. The rules are run here over SYNTHETIC sources, so each shape an independent review
        // showed the verb list let through must be an offence, and a listed file must not be.
        $shapes = [
            'MarketingContactState::where("store_id", 1)->update(["status" => "granted"]);',
            'MarketingContactState::forceCreate([]);',
            'MarketingContactState::query()->where("a", 1)->update([]);',
            'MarketingContactState::truncate();',
            'MarketingContactState::destroy(1);',
            'app(MarketingContactState::class)->newQuery();',
            'MarketingConsentEvent::insert($rows);',
            'MarketingUnsubscribeToken::query()->delete();',
        ];

        foreach ($shapes as $i => $code) {
            $this->assertCount(1, $this->modelNameOffenders(['app/Http/Controllers/ImportController.php' => $code]), "not seen: {$code}");
        }

        foreach ([
            'DB::connection("x")->table("marketing_consent_events")->delete();',
            'DELETE FROM marketing_consent_events',
            'TRUNCATE TABLE marketing_contact_states',
            'DB::table($t = "marketing_unsubscribe_tokens")->delete();',
        ] as $code) {
            $this->assertCount(1, $this->tableNameOffenders(['app/Console/Commands/Purge.php' => $code]), "not seen: {$code}");
        }

        foreach ([
            '$ledger = app(ConsentLedger::class); $ledger->grant(1, $c, $a, "x");',
            '$ledger = app(ConsentLedger::class); $ledger->{$verb}(1, $c, $a, "x");',
            'use App\\Services\\Marketing\\ConsentLedger; call_user_func([$ledger, "grant"], 1, $c, $a, "x");',
            'ConsentLedger $l; [$l, \'liftSuppression\'](1, $c, $a, "x");',
        ] as $code) {
            $this->assertCount(1, $this->grantOffenders(['app/Http/Controllers/Import.php' => $code]), "not seen: {$code}");
        }
        $this->assertSame([], $this->grantOffenders(['app/Http/Controllers/Reader.php' => '$x->{$y}(); // no ledger here']), 'a dynamic call in a file that never names the ledger is not this census\'s business');

        $this->assertCount(1, $this->bareHashWithdrawalOffenders(['app/Http/Controllers/QuickUnsub.php' => '$ledger->withdrawByHash($s, $c, $request->hash, null, "x");']));
        $this->assertSame([], $this->bareHashWithdrawalOffenders(['app/Services/Marketing/UnsubscribeTokens.php' => '$this->ledger->withdrawByHash($a, $b, $c, $d, $e);']));

        // The listed files are not offenders, and neither is a file that never names a model.
        $this->assertSame([], $this->modelNameOffenders([
            'app/Services/Marketing/ConsentLedger.php' => 'MarketingContactState::query(); MarketingConsentEvent::create([]);',
            'app/Http/Controllers/Unrelated.php' => '$ledger->stateOf(1, $channel, $address);',
        ]));
        $this->assertSame([], $this->tableNameOffenders([
            'app/Models/MarketingConsentEvent.php' => "protected \$table = 'marketing_consent_events';",
            self::MIGRATIONS[0] => "Schema::create('marketing_consent_events');",
        ]));

        // And the census reads the real tree, so the same rules over it are not vacuous.
        $this->assertGreaterThan(1000, count($this->sources()), 'the census read almost nothing, so passing proves nothing');
    }

    public function test_the_gate_is_not_wired_into_any_sender_yet_and_that_is_deliberate(): void
    {
        // Enforcement is per channel and each seam needs its own classification of the senders behind it.
        // Until a seam is wired on purpose, an unwired gate must read as unwired, not as protection.
        $callers = [];

        foreach ($this->sources() as $file => $code) {
            if (str_starts_with($file, 'app/Services/Marketing/')) {
                continue;
            }

            if (str_contains($code, 'MarketingSendGate')) {
                $callers[] = $file;
            }
        }

        $this->assertSame([], $callers,
            'MarketingSendGate gained a caller. If that is the first wired seam, update this census AND the sender-classification '
            .'census together, so "wired" is a statement about named files and not an assumption.');
    }
}
