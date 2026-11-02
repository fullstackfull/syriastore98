<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Models\Setting;
use App\Models\Utility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * F188 — AN ANONYMOUS LANGUAGE SWITCH MUST NOT EMPTY THE PAYMENT CACHE.
 * =============================================================================================
 *
 * `LanguageController`'s three switch handlers each opened with `Artisan::call('cache:clear')`,
 * and two of the three routes take no authentication:
 *
 *   GET change-language-store/{lang}   `web` only
 *   GET change-languages/{lang}        `web` + `setlocate`
 *   GET change-language/{lang}         inside `['auth','xss','setlocate','verified']`
 *
 * `web` carries no throttle, and a GET needs no CSRF token, so one unauthenticated request —
 * repeatable — emptied the default cache store for every tenant. That store holds
 * `gateway-return-claim:*`, the replay control for EVERY gateway return: it is `Cache::add()`, so
 * a forgotten key is an un-taken claim and one capture can be replayed into a second paid order.
 * It also holds `paymera_pending_*` (losing it gives a buyer mid-checkout a captured payment and
 * no order — F186's outcome, reached by a stranger) and `paymera_claim_*`/`paymera_lock_*` (F96's
 * duplicate-order window).
 *
 * The F188 ledger row described this as an operator enabling a module. These cases are what
 * measuring it instead produced.
 *
 * WHAT THESE CASES CANNOT PROVE, AND IT IS MEASURED RATHER THAN CONCEDED. `phpunit.xml` sets
 * `CACHE_STORE=array`, and `Illuminate\Cache\ArrayStore` EXTENDS `TaggableStore` while production's
 * `DatabaseStore` does not. `App\Models\Setting` and `App\Models\User` are both `Cachable`, and
 * laravel-model-caching tags its flush only when the store is taggable (`Caching.php:95`) -- so on
 * the array store a model save flushes the model's tags, and on the database store it flushes
 * EVERYTHING. `tests/Support/Security/bin/verify-model-cache-flush-scope-on-mysql.php` measures it
 * on MariaDB 10.11: one `Setting::updateOrCreate()` emptied **4 of 4** of the keys seeded below,
 * and the same write on the array store left all four.
 *
 * THEREFORE the authenticated case below holds **only on a taggable store**, and it is named and
 * scoped accordingly rather than left asserting something false about production. The two
 * ANONYMOUS cases are the real evidence for this slice, and they are engine-independent for a
 * reason the review established: neither anonymous path writes a `Cachable` model at all --
 * `changeLanquageStore` writes only a cookie and the session, and every write in `changelanguage`
 * is inside `Auth::check()`. The anonymous wipe is closed; the authenticated one is a separate,
 * pre-existing, platform-wide defect and is recorded as such.
 *
 * THE SURVIVING FORGET IS CORRECT BUT NOT LOAD-BEARING, and an earlier version of this note had
 * that wrong. `Utility::GetValByName()` caches each store's settings under
 * `settings_store_<id>` with `rememberForever()` and nothing else forgets that key -- so the
 * forget is right, and ordering it after the writes (the flush ran before them) is a real
 * improvement. But review traced every caller: NOTHING reads `SITE_RTL` or `defult_language`
 * through that method. Direction and language come from the uncached `GetValueByName()`,
 * `Utility::Setting()` and direct `Setting::where(...)` queries. So the flush was not "the only
 * way a freshly written setting became visible", and the last case below asserts the
 * invalidation works -- not that a merchant's layout depends on it.
 */
class LanguageSwitchCacheScopeTest extends TestCase
{
    use RefreshDatabase;

    /** Keys that stand between one capture and a second paid order. */
    private const PAYMENT_KEYS = [
        'gateway-return-claim:checkout:0000000000000000000000000000000000000000',
        'gateway-return-claim:payment:khalti:1:1111111111111111111111111111111111111111',
        'paymera_pending_paymera_abc123',
        'paymera_claim_paymera_abc123',
    ];

    public function test_the_anonymous_storefront_switch_leaves_every_payment_claim_in_place(): void
    {
        $this->seedPaymentKeys();

        $response = $this->get(route('change.languagestore', 'ar'));

        /*
         * THE PREMISE IS THE SESSION WRITE, NOT THE STATUS CODE. Review's point: a 302
         * is also what a middleware refusal answers, so accepting [200, 302] as proof
         * the handler ran is the same weakness the authenticated case had to abandon
         * for `assertHandlerRan()`. These two handlers write no `Setting` row, so
         * there is nothing to assert on there -- but both do
         * `Session::put(['LANGUAGE' => $lang])`, which is the anonymous analogue.
         */
        $this->assertTrue(
            in_array($response->getStatusCode(), [200, 302], true),
            'the route answered something unexpected. Status ' . $response->getStatusCode()
        );
        $response->assertSessionHas('LANGUAGE', 'ar');
        $this->assertPaymentKeysSurvived('the anonymous storefront language switch');
    }

    public function test_the_other_anonymous_switch_leaves_every_payment_claim_in_place(): void
    {
        $this->seedPaymentKeys();

        $response = $this->get(route('changelanguage', 'ar'));

        /*
         * THE PREMISE IS THE SESSION WRITE, NOT THE STATUS CODE. Review's point: a 302
         * is also what a middleware refusal answers, so accepting [200, 302] as proof
         * the handler ran is the same weakness the authenticated case had to abandon
         * for `assertHandlerRan()`. These two handlers write no `Setting` row, so
         * there is nothing to assert on there -- but both do
         * `Session::put(['LANGUAGE' => $lang])`, which is the anonymous analogue.
         */
        $this->assertTrue(
            in_array($response->getStatusCode(), [200, 302], true),
            'the route answered something unexpected. Status ' . $response->getStatusCode()
        );
        $response->assertSessionHas('LANGUAGE', 'ar');
        $this->assertPaymentKeysSurvived('the anonymous change-languages switch');
    }

    /**
     * THE AUTHENTICATED SWITCH, ON A TAGGABLE STORE ONLY — and the name says so deliberately.
     *
     * An earlier version of this case was called
     * `test_the_authenticated_switch_leaves_every_payment_claim_in_place`, which asserts something
     * FALSE about production. `Setting` and `User` are `Cachable`; this handler saves both; and on
     * production's non-taggable `database` store that flush is `Cache::flush()` over the whole
     * store. Measured: 4 of 4 of these keys gone
     * (`tests/Support/Security/bin/verify-model-cache-flush-scope-on-mysql.php`).
     *
     * What this case still buys, which is why it is kept rather than deleted: it proves the handler
     * does not reach for a whole-store flush OF ITS OWN. If `cache:clear` or
     * `sideMenuCacheForget('all')` came back here, the keys would vanish on the array store too and
     * this would fail. The model-cache flush is a different mechanism with its own record.
     */
    public function test_the_authenticated_switch_takes_no_whole_store_flush_of_its_own(): void
    {
        $this->seedPaymentKeys();

        $response = $this->actingAs($this->merchant())->get(route('change.language', 'ar'));

        $this->assertTrue(
            in_array($response->getStatusCode(), [200, 302], true),
            'the premise: the route was reached. Status ' . $response->getStatusCode()
        );
        $this->assertHandlerRan('ar');
        $this->assertPaymentKeysSurvived('the authenticated merchant language switch (on the array store)');
    }

    /**
     * THE HALF THAT HAD TO KEEP WORKING. Without this the fix is a regression: the merchant
     * changes language, `SITE_RTL` is written, and a `rememberForever` entry nothing else forgets
     * keeps serving the old direction.
     */
    /**
     * THE HALF THAT HAD TO KEEP WORKING, ASSERTED END TO END RATHER THAN BY KEY SPELLING.
     *
     * Without this the fix is a regression: the merchant changes language, `SITE_RTL` and
     * `defult_language` are written, and `settings_store_<id>` — `rememberForever`, forgotten
     * nowhere else in the codebase — keeps serving the old value.
     *
     * TWO EARLIER CUTS OF THIS CASE WERE WRONG, and both ways are worth recording because each
     * would have read as a verdict on the code:
     *
     *   1. It seeded `settings_store_<id from the merchant's store row>` and asserted that key was
     *      gone. The request resolved a different id, so it failed while the fix worked.
     *   2. It listened for `KeyForgotten` without seeding anything. `Repository::forget()` wraps
     *      the event in `if ($result)` and the array store returns false for an absent key, so it
     *      read "nothing was forgotten" off a forget that ran correctly.
     *
     * With every candidate key seeded, the listener showed the handler forgets
     * `settings_store_` — an EMPTY store id, because `getCurrentStore()` resolves to nothing in
     * this environment. That is not a defect in the fix: the handler's `updateOrCreate` writes
     * `'store_id' => getCurrentStore()` and `GetValByName()` reads under the same value, so the
     * write, the forget and the read agree on whatever that resolves to. Asserting a particular
     * id would be asserting the fixture, which is why this case asks the question a merchant
     * would: after the switch, does the setting read back as the new language?
     */
    public function test_the_authenticated_switch_still_invalidates_its_own_stores_settings(): void
    {
        $merchant = $this->merchant();

        /*
         * THE STALE ENTRY IS SEEDED EXPLICITLY, AND THE PREMISE THAT IT SHADOWS IS ASSERTED.
         *
         * Letting `GetValByName()` populate itself does NOT produce a shadowing entry, and that
         * cut of this case passed its bite — removing the handler's forget left it green. The
         * reason is a defect in `GetValByName()` itself: it caches under the store-wide key
         * `settings_store_<id>` but its closure queries `where('name', $name)` only, so the entry
         * holds at most the ONE name first asked for. With no `defult_language` row yet, that
         * entry is `[]`, the `!empty($settings) && isset($settings[$name])` test fails, and the
         * read falls through to `Utility::Setting()` — an uncached path. So nothing shadowed, and
         * the case could not tell the forget from its absence.
         *
         * (That one-name-per-store-key behaviour is a live defect in its own right — a second
         * setting read for the same store misses and silently takes the utility default. Recorded
         * in the ledger under F188; it is not this slice's to fix.)
         */
        Cache::forever('settings_store_' . getCurrentStore(), ['defult_language' => 'en']);

        $this->assertSame(
            'en',
            Utility::GetValByName('defult_language'),
            'the premise failed: the seeded entry is not being served, so this case cannot tell'
            . ' whether the handler invalidates anything. The reader\'s key has moved away from'
            . ' `settings_store_<id>`.'
        );

        $this->actingAs($merchant)->get(route('change.language', 'ar'));

        $this->assertHandlerRan('ar');

        $this->assertSame(
            'ar',
            Utility::GetValByName('defult_language'),
            'the switch wrote `defult_language = ar` and the settings cache still serves "en".'
            . ' `settings_store_<id>` is `rememberForever` and nothing else in the codebase forgets'
            . ' it, so a reader going through `GetValByName()` would keep the old value forever.'
            . ' (No renderer of direction or labels does — see the class docblock — so this is a'
            . ' correctness assertion about the invalidation, not about a broken layout.)'
        );
    }

    /**
     * THE RATCHET. A whole-cache flush must not come back to this controller by any spelling.
     */
    public function test_the_language_controller_never_empties_the_whole_cache(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(\App\Http\Controllers\LanguageController::class))->getFileName()
        );

        // Comments mention the old call by name; strip them before asserting on executable text.
        $executable = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

        foreach (["Artisan::call('cache:clear')", 'Cache::flush(', "sideMenuCacheForget('all')"] as $banned) {
            $this->assertStringNotContainsString(
                $banned,
                $executable,
                'a language switch empties the whole cache again. Two of these three routes are'
                . ' anonymous and unthrottled, so this hands any visitor a way to clear every'
                . " tenant's gateway replay claims. Found: " . $banned
            );
        }
    }

    private function seedPaymentKeys(): void
    {
        foreach (self::PAYMENT_KEYS as $key) {
            Cache::forever($key, 'storefront-return');
        }

        foreach (self::PAYMENT_KEYS as $key) {
            $this->assertSame('storefront-return', Cache::get($key), 'the premise: ' . $key . ' was seeded');
        }
    }

    private function assertPaymentKeysSurvived(string $what): void
    {
        foreach (self::PAYMENT_KEYS as $key) {
            $this->assertSame(
                'storefront-return',
                Cache::get($key),
                $what . ' cleared `' . $key . '`. For a gateway-return claim that means the next'
                . ' replay of a captured payment is no longer refused and writes a second paid'
                . ' order; for a paymera record it means a buyer mid-checkout loses the order'
                . ' their capture paid for.'
            );
        }
    }

    /**
     * CORRECTED AFTER REVIEW: `email_verified_at` IS **NOT** LOAD-BEARING, and the explanation this
     * replaces was wrong twice over.
     *
     * It said a factory user with a null `email_verified_at` is redirected by `EmailIsVerified`,
     * and that this caused a false reading. Neither half reproduces: `UserFactory` already sets
     * `'email_verified_at' => now()` by default, and `EmailIsVerified` only redirects when
     * `getSuperAdminAllSetting()['email_verification'] == 'on'`, which nothing here seeds. The
     * override below is therefore redundant and is kept only because it makes the fixture's
     * intent explicit.
     *
     * THE REAL CAUSE OF THAT FALSE READING was the second diagnosis, not the first: a
     * `KeyForgotten` listener reports nothing when the key did not exist, because
     * `Repository::forget()` wraps the event in `if ($result)` and the array store returns false
     * for an absent key. `assertHandlerRan()` is still the right guard — a status code cannot
     * carry "the handler executed", since the success redirect and a middleware refusal are both
     * 302 — but it is not what closed that particular reading.
     */
    private function merchant(): User
    {
        return User::factory()->create([
            'type' => 'admin',
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Proof the switch handler really executed: it writes `defult_language` for the acting store.
     *
     * A status code cannot carry this. Both the success redirect and the middleware's refusal are
     * 302, and the whole point of these cases is what the handler did to the cache.
     */
    private function assertHandlerRan(string $lang): void
    {
        $this->assertTrue(
            Setting::where('name', 'defult_language')->where('value', $lang)->exists(),
            'the premise failed: the language-switch handler never ran, so every cache assertion'
            . ' in this case is vacuous. Most likely the acting user does not clear the `verified`'
            . ' middleware — see merchant().'
        );
    }
}
