<?php

namespace Tests\Feature\Language;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * `DELETE /lang/{lang}` BUILT THREE THINGS FROM A RAW ROUTE SEGMENT, ONE OF THEM A RECURSIVE DELETE.
 *
 * Found by independent review of the module-removal fix (`c3010288`) as "the same shape, a different
 * target", and it is the ONLY remaining user-input-reachable recursive delete in first-party code --
 * censused: `AddonController:137` takes a temp tree it just created, `AddonController:65` already
 * uses a sanitised id, and `helper.php:2325` is a third copy of the helper that nothing but itself
 * calls.
 *
 * WHAT THE HANDLER DID WITH `$lang` (`routes/web.php:1615`, `Route::delete('/lang/{lang}')`):
 *
 *   :607  Utility::delete_directory($langDir . $lang)   RECURSIVE, no basename, no confinement
 *   :626  unlink($langDir . $lang . '.json')            single file, same
 *   :628  User::where('language', 'LIKE', $lang)        a LIKE PATTERN
 *
 * The only guard was a refusal of the default and fallback language CODES, so none of `.`, `..`,
 * `%2e%2e` or `%` was covered. `resources/lang/.` is the language directory itself;
 * `resources/lang/..` is `resources/`; `../../composer` reaches `composer.json`; and `%` in a `LIKE`
 * reassigns the language of more users than were named.
 *
 * SEVERITY, STATED HONESTLY: gated by `isAbleTo('Delete Language')`, so operator-reachable, not an
 * escalation -- the same disposition as the module door. And LIVE REACHABILITY OF THE TRAVERSAL
 * FORMS IS NOT CLAIMED: nginx and Apache both normalise `.`, `..` and `%2e` out of the URI before
 * Laravel sees it, so they may be unreachable through a production web server while being reachable
 * from a test client or `php artisan serve`. The `LIKE` wildcard needs no traversal at all.
 *
 * WHY THE TRAVERSAL IS **NOT** DRIVEN THROUGH THE ROUTE HERE -- and this is not caution, it is the
 * only safe ordering. A failing-first test for `DELETE /lang/..` has to run it against the UNFIXED
 * handler, which deletes `resources/`. A mutation that removes the confinement does the same thing
 * to a route test on FIXED code. There is no order in which those two runs are both safe, so the
 * escapes are proved in `tests/Unit/Filesystem/ConfinedDirectoryTest.php` against directories that
 * test creates and destroys, the wiring is pinned structurally below, and the ACCEPT path is driven
 * here against a disposable language this test creates and removes itself.
 */
class LanguageDeletionIsConfinedTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    /** A language code that is not, and must never be, a real one. */
    private const DISPOSABLE = 'zz9test';

    private string $langRoot;

    private ?string $manifestBackup = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->langRoot = base_path('resources/lang');

        /*
         * `language.json` IS A TRACKED FILE AND THE HANDLER REWRITES IT. Backed up verbatim and
         * restored in tearDown, because a crashed test must not leave the repository modified.
         */
        $manifest = $this->langRoot . '/language.json';
        $this->manifestBackup = is_file($manifest) ? (string) file_get_contents($manifest) : null;
    }

    protected function tearDown(): void
    {
        $manifest = $this->langRoot . '/language.json';

        if ($this->manifestBackup !== null) {
            file_put_contents($manifest, $this->manifestBackup);
        }

        $dir = $this->langRoot . '/' . self::DISPOSABLE;

        if (is_dir($dir)) {
            foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
                $child = $dir . '/' . $entry;
                is_dir($child) && ! is_link($child) ? null : @unlink($child);
            }
            @rmdir($dir);
        }

        @unlink($this->langRoot . '/' . self::DISPOSABLE . '.json');

        parent::tearDown();
    }

    /** A complete, disposable language: a directory, a file in it, a sidecar json, a manifest entry. */
    private function createDisposableLanguage(): void
    {
        $dir = $this->langRoot . '/' . self::DISPOSABLE;
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/messages.php', "<?php\n\nreturn ['ss_probe' => 'probe'];\n");
        file_put_contents($this->langRoot . '/' . self::DISPOSABLE . '.json', '{"ss_probe":"probe"}');

        $manifest = $this->langRoot . '/language.json';
        $data = json_decode((string) file_get_contents($manifest), true) ?: [];
        $data[self::DISPOSABLE] = 'Disposable Test Language';
        file_put_contents($manifest, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->assertDirectoryExists($dir, 'premise: the disposable language exists');
    }

    /**
     * AN OPERATOR WHO CAN ACTUALLY REACH THE HANDLER.
     *
     * The same trap as the module door: CLAUDE.md records that Laratrust's `permissions_as_gates` is
     * false and there is NO super-admin bypass, so `isAbleTo()` alone REFUSES the platform operator.
     * Without the Laratrust role the handler answers 'Permission denied.' and every assertion below
     * would be satisfied by the gate rather than by the fix. The premise assertion is what makes
     * that visible.
     */
    private function operatorWhoMayDeleteLanguages(): User
    {
        $operator = $this->makePlatformAdmin();

        $permission = Permission::create([
            'name' => 'Delete Language',
            'display_name' => 'Delete Language',
            'description' => 'fixture',
        ]);
        $permission->module = 'LanguageAdmin';
        $permission->save();

        $role = Role::create([
            'name' => 'super admin',
            'display_name' => 'Super Admin',
            'description' => 'the seeded operator role',
            'created_by' => $operator->id,
        ]);

        $role->givePermission('Delete Language');
        $operator->addRole($role);
        $operator->refresh();

        $this->assertTrue(
            $operator->isAbleTo('Delete Language'),
            'premise: this operator must really hold the permission, or the test proves only that '
            . 'the gate refused it.'
        );

        return $operator;
    }

    // ---------------------------------------------------------------------------------------------
    // the control: deletion must still work
    // ---------------------------------------------------------------------------------------------

    /**
     * THE CONTROL AGAINST AN OVER-BROAD FIX. A real, removable language must still be removable, or
     * "confine everything" would pass every refusal test while breaking the feature.
     */
    #[Test]
    public function test_a_disposable_language_is_still_deleted(): void
    {
        $this->createDisposableLanguage();

        $operator = $this->operatorWhoMayDeleteLanguages();

        $this->actingAs($operator)->delete('/lang/' . self::DISPOSABLE);

        $this->assertDirectoryDoesNotExist(
            $this->langRoot . '/' . self::DISPOSABLE,
            'The fix refuses a legitimate language, so it is not a fix -- it is an outage.'
        );
        $this->assertFileDoesNotExist($this->langRoot . '/' . self::DISPOSABLE . '.json');

        $manifest = json_decode((string) file_get_contents($this->langRoot . '/language.json'), true);
        $this->assertArrayNotHasKey(self::DISPOSABLE, $manifest,
            'the manifest entry must go with the directory');
    }

    /**
     * AND A LANGUAGE WITH NO DIRECTORY IS STILL CLEANED OUT OF THE MANIFEST.
     *
     * This is the case the confinement would have broken if it had been applied unconditionally:
     * `within()` throws for a path that does not exist, so refusing the whole request would make an
     * orphaned manifest entry impossible to remove -- which the old code COULD do, because
     * `delete_directory()` returns true for a missing path. The name is validated unconditionally;
     * the confinement governs only the filesystem step.
     */
    #[Test]
    public function test_a_manifest_entry_with_no_directory_is_still_removable(): void
    {
        $manifest = $this->langRoot . '/language.json';
        $data = json_decode((string) file_get_contents($manifest), true) ?: [];
        $data[self::DISPOSABLE] = 'Orphaned Test Language';
        file_put_contents($manifest, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->assertDirectoryDoesNotExist($this->langRoot . '/' . self::DISPOSABLE, 'premise');

        $operator = $this->operatorWhoMayDeleteLanguages();

        $this->actingAs($operator)->delete('/lang/' . self::DISPOSABLE);

        $after = json_decode((string) file_get_contents($manifest), true);
        $this->assertArrayNotHasKey(
            self::DISPOSABLE,
            $after,
            'An orphaned manifest entry became unremovable. The confinement must not be applied to a '
            . 'directory that does not exist -- only the NAME check is unconditional.'
        );
    }

    /**
     * A code naming nothing at all must change nothing and must not throw.
     *
     * Safe to drive unfixed: `resources/lang/NotALanguage` does not exist, so the recursive delete
     * returns at its own existence check and only the manifest half runs.
     */
    #[Test]
    public function test_an_unknown_language_code_is_handled_without_error(): void
    {
        $operator = $this->operatorWhoMayDeleteLanguages();

        $before = (string) file_get_contents($this->langRoot . '/language.json');

        $response = $this->actingAs($operator)->delete('/lang/NotALanguage');

        $response->assertStatus(302);
        $this->assertSame(
            json_decode($before, true),
            json_decode((string) file_get_contents($this->langRoot . '/language.json'), true),
            'An unknown code must leave the manifest exactly as it was.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // the LIKE wildcard, which the first version of this fix only half closed
    // ---------------------------------------------------------------------------------------------

    /**
     * `_` IS A SQL LIKE WILDCARD AND IT SURVIVES THE ALLOWLIST.
     *
     * FOUND BY INDEPENDENT ADVERSARIAL REVIEW OF `9a486500`, AFTER THAT COMMIT WAS ALREADY PUSHED,
     * and it survived three refuters at "certain" confidence. The first version of this fix claimed
     * -- in its own commit message, in `PHASE-06-RECURSIVE-DELETE-CONFINEMENT.md` and in the
     * handler's comment -- that validating the segment "closes the LIKE wildcard incidentally". That
     * is HALF TRUE and therefore false: `safeSegment()` accepts `^[A-Za-z0-9_-]+$`, which refuses `%`
     * and ADMITS `_`.
     *
     * MEASURED, not inferred: `safeSegment('__')` returns `'__'` unchanged, `safeSegment('e_')`
     * returns `'e_'`, and only `%` throws. The query builds as `where "language" LIKE ?` with the
     * binding `__` -- parameterisation binds the VALUE and does not neutralise LIKE metacharacters,
     * so the engine still expands it.
     *
     * WHAT THAT COSTS. 15 of the 16 shipped `resources/lang` directories are exactly two characters
     * (ar, da, de, en, es, fr, he, it, ja, nl, pl, pt, ru, th, zh), so `LIKE '__'` matches every one
     * of them. `User` has ZERO global scopes and is absent from `config/tenancy.php`'s
     * `scoped_models` (both measured), so the write crosses EVERY TENANT. And the update sits outside
     * the `is_dir($langDir . $lang)` block, so for `__` nothing is deleted, no confinement check ever
     * runs, and the handler still reassigns every matching user and answers
     * "Language Deleted Successfully."
     *
     * `e_` is the sharper variant: it matches `en`, so the default-and-fallback refusal does not
     * protect the default language's users either.
     *
     * THE FIX IS THE OPERATOR, NOT THE VALUE. `=` is what the intent always was, and nothing is lost:
     * MySQL's default collation is already case-insensitive. This test pins the OPERATOR and the
     * OUTCOME, because the only assertion that previously touched this line was a source-position
     * census that encoded neither -- a `'LIKE'` to `'='` mutation would not have been caught, which
     * is exactly why the gap survived 11 of 11 mutations.
     */
    #[Test]
    public function test_a_single_character_wildcard_cannot_reassign_every_users_language(): void
    {
        $speakers = [];

        foreach (['ar', 'de', 'fr', 'zh'] as $language) {
            $speakers[$language] = $this->makeMerchant(['language' => $language]);
        }

        $operator = $this->operatorWhoMayDeleteLanguages();

        // `__` matches every two-character code under LIKE, and names no real language.
        $this->actingAs($operator)->delete('/lang/__');

        foreach ($speakers as $language => $user) {
            $this->assertSame(
                $language,
                User::withoutGlobalScopes()->find($user->id)->language,
                'DELETE /lang/__ reassigned a user whose language was ' . $language . '. `_` is a SQL '
                . 'LIKE wildcard and survives the identifier allowlist, so the mass update matched '
                . 'every two-character language code -- across every tenant, with nothing deleted.'
            );
        }
    }

    /**
     * THE SHARPER VARIANT, AND THE FIRST VERSION OF THIS TEST WAS VACUOUS.
     *
     * It asserted that `DELETE /lang/e_` left an `en` user on `en`. That PASSED before the fix, and
     * proved nothing: the handler reassigns matched users TO the default language, which IS `en`, so
     * `en` to `en` is a no-op and the assertion held whether the wildcard expanded or not. Probed
     * rather than adjusted when it passed on unfixed code, which is this project's rule.
     *
     * `es` is the value that discriminates. It is a SHIPPED language (`resources/lang/es` exists), it
     * LIKE-matches `e_`, and it is not the default -- so if the wildcard expands, an `es` user is
     * moved to `en` and the assertion fails. The `en` assertion is kept alongside it because the
     * interesting fact is that `e_` reaches the PROTECTED language's own bucket at all: the
     * default-and-fallback refusal compares the segment to `['en']` with `in_array(..., true)`, and
     * `'e_' !== 'en'`, so nothing stops it being accepted.
     */
    #[Test]
    public function test_a_wildcard_cannot_reach_the_users_of_a_language_it_did_not_name(): void
    {
        $this->assertDirectoryExists(base_path('resources/lang/es'),
            'premise: `es` is a shipped language, so a user may legitimately be on it');

        $spanishSpeaker = $this->makeMerchant(['language' => 'es']);
        $arabicSpeaker = $this->makeMerchant(['language' => 'ar']);

        $operator = $this->operatorWhoMayDeleteLanguages();

        // `e_` names no language. Under LIKE it matches `en` AND `es`.
        $this->actingAs($operator)->delete('/lang/e_');

        $this->assertSame(
            'es',
            User::withoutGlobalScopes()->find($spanishSpeaker->id)->language,
            '`e_` LIKE-matched `es` and moved a Spanish-speaking user to the default language. The '
            . 'code named no language at all, and `es` was never mentioned.'
        );
        $this->assertSame('ar', User::withoutGlobalScopes()->find($arabicSpeaker->id)->language);
    }

    /**
     * THE CONTROL: deleting a REAL language must still move its users to the default, or the fix is
     * an outage. This is the behaviour the `LIKE` was there to provide, and `=` provides it.
     */
    #[Test]
    public function test_deleting_a_real_language_still_reassigns_exactly_its_own_users(): void
    {
        $this->createDisposableLanguage();

        $affected = $this->makeMerchant(['language' => self::DISPOSABLE]);
        $untouched = $this->makeMerchant(['language' => 'ar']);

        $operator = $this->operatorWhoMayDeleteLanguages();

        $this->actingAs($operator)->delete('/lang/' . self::DISPOSABLE);

        $default = config('platform.default_language') ?? 'en';

        $this->assertSame(
            $default,
            User::withoutGlobalScopes()->find($affected->id)->language,
            'A user of the deleted language was not moved to the default, so the fix broke the '
            . 'behaviour the LIKE was there to provide.'
        );
        $this->assertSame('ar', User::withoutGlobalScopes()->find($untouched->id)->language,
            'A user of a DIFFERENT language was moved. The predicate must match exactly one code.');
    }

    // ---------------------------------------------------------------------------------------------
    // the wiring, pinned structurally
    // ---------------------------------------------------------------------------------------------

    /** The body of `destroyLang()` only, with every comment already removed. */
    private function destroyLangBody(): string
    {
        $code = $this->sourceWithoutComments('app/Http/Controllers/LanguageController.php');

        $start = strpos($code, 'public function destroyLang(');
        $this->assertNotFalse($start, 'premise: destroyLang() must exist');

        $end = strpos($code, 'public function ', $start + 10);
        $body = $end === false ? substr($code, $start) : substr($code, $start, $end - $start);

        $this->assertStringNotContainsString('/*', $body, 'premise: comments must really be stripped');
        $this->assertGreaterThan(200, strlen($body), 'premise: the extracted body is not empty');

        return $body;
    }

    /**
     * THE NAME IS VALIDATED BEFORE ANYTHING IS COMPUTED FROM IT.
     *
     * Asserted as POSITIONS, not presence, because presence is what the broken version already had:
     * the default-and-fallback refusal was there, above the deletes, and it was not a path check.
     *
     * Scoped to the method and comment-stripped, because CLAUDE.md records four censuses in one day
     * that were satisfied by prose about the thing they measured -- and this method is now commented
     * heavily enough to satisfy every assertion here on its explanatory text alone.
     */
    #[Test]
    public function test_the_language_code_is_validated_before_any_path_is_built_from_it(): void
    {
        $body = $this->destroyLangBody();

        $validated = strpos($body, 'ConfinedDirectory::safeSegment(');
        $this->assertNotFalse($validated, 'destroyLang() no longer validates the language code.');

        $after = [
            'the recursive directory delete' => 'delete_directory(',
            'the sidecar json delete'        => 'unlink(',
            'the user language reassignment' => "User::where('language'",
        ];

        foreach ($after as $what => $needle) {
            $at = strpos($body, $needle);
            $this->assertNotFalse($at, 'premise: ' . $what . ' must still be in destroyLang()');
            $this->assertGreaterThan(
                $validated,
                $at,
                $what . ' now runs BEFORE the language code is validated. Every path in this method '
                . 'is built from that segment, so the check has to come first or a refused code has '
                . 'already deleted something by the time it is refused.'
            );
        }
    }

    /** The recursive delete takes the confined, canonical path -- never a string built inline. */
    #[Test]
    public function test_the_recursive_delete_takes_the_confined_path(): void
    {
        $body = $this->destroyLangBody();

        $this->assertStringContainsString(
            'ConfinedDirectory::within(',
            $body,
            'The recursive delete no longer confines its target to the language root, so a symlinked '
            . 'language directory would be followed out of the tree.'
        );

        $this->assertStringContainsString('Utility::delete_directory($confined)', $body,
            'The recursive delete does not receive the confined path.');

        $this->assertStringNotContainsString(
            'Utility::delete_directory($langDir . $lang)',
            $body,
            'destroyLang() passes a concatenated path to the recursive delete again.'
        );
    }

    /**
     * And the refusal RETURNS -- a catch that fell through would leave every delete reachable.
     *
     * CORRECTED AFTER REVIEW, and the correction is the point. This test used to bound its search
     * with `substr($body, $firstCatch, strpos($body, 'delete_directory(') - $firstCatch)`: a span
     * running from the FIRST `catch (PathNotConfined` all the way to the recursive delete. That
     * span contains THREE `return redirect()` statements -- this catch, the default/fallback
     * refusal, and the `within()` catch -- so `assertStringContainsString()` passed with the
     * return deleted from the one branch the test exists to pin, and no other test covered it.
     * The reviewer's words: "the assertion is satisfied by two unrelated returns further down the
     * method".
     *
     * It now brace-matches the catch BLOCK and requires exactly one return inside it, so deleting
     * that return fails the test and adding a second one is visible too.
     */
    #[Test]
    public function test_a_refused_code_returns_instead_of_continuing(): void
    {
        $body = $this->destroyLangBody();

        $firstCatch = strpos($body, 'catch (PathNotConfined');
        $this->assertNotFalse($firstCatch, 'destroyLang() no longer catches the refusal.');

        $block = $this->blockAfter($body, $firstCatch);

        $this->assertSame(
            1,
            substr_count($block, 'return redirect()'),
            'The refusal branch does not return exactly once. A caught refusal that falls through '
            . "leaves the recursive delete reachable with an unvalidated code. The block read:\n"
            . $block
        );
    }

    /**
     * The brace-matched `{ ... }` block that starts at the first `{` at or after `$from`.
     *
     * Deliberately simple and deliberately asserted: the catch blocks it is pointed at contain no
     * braces in strings and no nested closures, and `assertNotFalse` below fails loudly rather
     * than silently returning a wrong span if that ever stops being true.
     */
    private function blockAfter(string $source, int $from): string
    {
        $open = strpos($source, '{', $from);
        $this->assertNotFalse($open, 'no block follows the refusal.');

        $depth = 0;

        for ($i = $open, $n = strlen($source); $i < $n; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }

        $this->fail('the refusal block is not brace-balanced, so this assertion cannot be trusted.');
    }
}
