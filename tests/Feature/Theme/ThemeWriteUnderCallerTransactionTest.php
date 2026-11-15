<?php

namespace Tests\Feature\Theme;

use App\Models\Store;
use App\Models\ThemeCustomize;
use App\Models\User;
use App\Services\Theme\ThemeConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M3-E — THE SERVICE IS NOT THE ONLY ONE HOLDING A TRANSACTION.
 *
 * `ThemeConfigurationService::writeSettings()` is written as though it owns its transaction: it
 * runs `DB::transaction(...)` and then flushes the model cache through `DB::afterCommit()`, which
 * M3-C justified as "flush after commit, never inside it".
 *
 * ITS ONE PRODUCTION CALLER DOES NOT CALL IT THAT WAY. `Api\DashboardController` opens
 * `\DB::beginTransaction()` at :8922, calls `writeSettings()` at :9054 and commits at :9067, so the
 * service's own `DB::transaction()` is a SAVEPOINT, not a transaction, and "after commit" means
 * something different from what M3-C measured in isolation. That difference is the whole point of
 * this file: the guarantee was verified standalone and then used nested.
 *
 * MEASURED (2026-11-15, SQLite, framework semantics):
 *
 *     inside an outer txn       afterCommit callback does NOT run     level 1
 *     nested DB::transaction()  releases its savepoint, STILL does not run
 *     outer commit              runs, at transaction level 0
 *     outer rollBack            NEVER runs
 *
 * So the nesting is safe, and these tests exist so it STAYS safe: replacing `DB::afterCommit()`
 * with a direct `flushCache()`, or adding a `DB::commit()` inside the service, breaks the caller
 * and nothing else in the suite would notice.
 *
 * WHAT THIS FILE DELIBERATELY DOES NOT ASSERT, because it cannot do so honestly:
 *
 *   - THAT THE FLUSH ITSELF FIRED. Two measured reasons. `flushCache()` is TAG-scoped: an untagged
 *     `Cache::put()` sentinel survives it, so the obvious probe passes whether or not the flush
 *     happened -- a false green. And `RefreshDatabase` wraps every test in a transaction that is
 *     never committed, so transaction level never returns to 0 and an `afterCommit` callback NEVER
 *     RUNS IN THIS SUITE AT ALL. Cache-invalidation evidence therefore lives where it can be taken
 *     across two processes against production's `CACHE_STORE=database`:
 *     `tests/Support/Security/bin/verify-theme-cache-flush-after-commit-on-mysql.php`.
 */
class ThemeWriteUnderCallerTransactionTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::forceCreate([
            'name' => 'Nest M', 'email' => 'nest-m@example.test', 'password' => bcrypt('x'),
            'type' => 'admin', 'created_by' => 1, 'email_verified_at' => now(),
        ]);

        $this->store = Store::create([
            'name' => 'Nest Store', 'email' => 'nest-store@example.test', 'slug' => 'nest-store',
            'theme_id' => 'stylique', 'created_by' => $this->merchant->id,
            'is_active' => 1, 'default_language' => 'en',
        ]);
    }

    /**
     * THE DATABASE, NOT THE MODEL CACHE. `ThemeCustomize` is `Cachable`, and the whole point of the
     * rollback test below is to ask what the TABLE holds -- see that test's note for the
     * measurement where the two disagreed.
     */
    private function rawValue(string $name): ?string
    {
        $value = DB::table('theme_customizes')
            ->where('store_id', $this->store->id)
            ->where('theme_id', 'stylique')
            ->where('name', $name)
            ->value('value');

        return $value === null ? null : (string) $value;
    }

    private function storedValue(string $name): ?string
    {
        return ThemeCustomize::withoutGlobalScopes()
            ->where('store_id', $this->store->id)
            ->where('theme_id', 'stylique')
            ->where('name', $name)
            ->value('value');
    }

    /**
     * The service must not commit the caller's work for it. If `writeSettings()` ever commits
     * independently, the controller's later failure can no longer undo the theme write, and a
     * merchant ends up with a half-applied save that the app reported as successful.
     */
    #[Test]
    public function test_write_settings_does_not_commit_the_callers_transaction(): void
    {
        $before = DB::transactionLevel();

        DB::beginTransaction();

        $written = (new ThemeConfigurationService())->writeSettings(
            $this->store->id, 'stylique',
            ['footer_description' => 'written inside the callers transaction'],
            $this->merchant
        );

        $this->assertNotNull($written, 'The write was refused for the owning merchant.');
        $this->assertSame(
            $before + 1,
            DB::transactionLevel(),
            'writeSettings() changed the caller transaction depth: it committed or rolled back '
            . "work it does not own. The Flutter handler commits at DashboardController:9067 and "
            . 'relies on still holding its own transaction when writeSettings() returns.'
        );

        DB::rollBack();

        $this->assertSame($before, DB::transactionLevel());
    }

    /**
     * The shape the Flutter handler actually produces: a theme write inside a wider transaction
     * that then fails. The theme write must go with it. This is the service's half of the
     * directive's "a failed transaction must preserve the prior published configuration".
     *
     * THE ASSERTION READS THE RAW TABLE, AND THAT IS NOT A CONVENIENCE. Written against Eloquent
     * first, this test FAILED -- and the failure was the probe, not the service. MEASURED at the
     * same instant after the rollback:
     *
     *     RAW TABLE    NULL                        <- the database rolled back correctly
     *     ELOQUENT     'THIS SAVE MUST NOT SURVIVE' <- `Cachable` serving a row that is now gone
     *
     * `ThemeCustomize` is `Cachable`: the in-transaction read re-populated the model cache with
     * uncommitted data, and the rollback did not take the cache with it. So an Eloquent assertion
     * here answers "what does the cache hold", never "did the database roll back".
     *
     * WHETHER THAT STALENESS CAN REACH PRODUCTION DEPENDS ON A SETTING, and the answer is
     * currently no. `MODEL_CACHE_STORE` is unset, so the model cache is the default store,
     * `CACHE_STORE=database` -- same connection, so its rows are inside the transaction and roll
     * back with it. BOTH OPT-IN STORES OFFERED BY D-28a BREAK THAT: `model_cache` can take a
     * separate connection (`MODEL_CACHE_DB_CONNECTION`) and `model_cache_file` is a file store, and
     * neither rolls back. `DB::afterCommit()` does not fire on a rollback, so no flush repairs it
     * and the phantom value is served until something else evicts it. Recorded for
     * `docs/development/phase-2/F194-MODEL-CACHE-ISOLATION-RUNBOOK.md` rather than fixed here: it
     * is a consequence of that decision, not of M3.
     */
    #[Test]
    public function test_a_rolled_back_caller_transaction_discards_the_theme_write(): void
    {
        $original = $this->storedValue('footer_description');

        DB::beginTransaction();

        $written = (new ThemeConfigurationService())->writeSettings(
            $this->store->id, 'stylique',
            ['footer_description' => 'THIS SAVE MUST NOT SURVIVE'],
            $this->merchant
        );

        $this->assertNotNull($written);
        $this->assertSame(
            'THIS SAVE MUST NOT SURVIVE',
            $this->rawValue('footer_description'),
            'The write is not visible inside the transaction that made it.'
        );

        DB::rollBack();

        $this->assertSame(
            $original,
            $this->rawValue('footer_description'),
            'A theme write survived the rollback of the transaction that contained it, so the '
            . 'service opened a transaction of its own rather than joining the caller.'
        );
    }
}
