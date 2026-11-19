<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\SignInImageDeleteGuard;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Services\StoredPathPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P1-SEC-78 -- A SETTINGS ROW CAN BE WRITTEN UNDER A SPELLING OF A SIGN-IN NAME THAT THE WRITE-SIDE RULE NEVER SEES.
 * ==================================================================================================================
 *
 * `Setting::saving` calls StoredPathPolicy::setting() (P1-SEC-28): the value of a row named `*_sign_in_image` has to be a path under an upload root, because
 * two VENDORED handlers (SignInWithFacebook, SignInWithLinkedIn) `File::delete(base_path($row->value))` on the row they find by NAME. The rule decided "is this one
 * of those rows" with `preg_match('/_sign_in_image$/', strtolower(trim($name)))` -- on the name AS POSTED. The lookups, and the table, know the name as the ENGINE does:
 *
 *   - utf8mb4_unicode_ci (config/database.php, which production does not override) is accent-, case- and PAD-SPACE-insensitive and ignores some code points, so
 *     `facebook_sign_in_imagé`, a trailing U+200B, a trailing no-break space and a full-width `ｉ` all FIND the row of `facebook_sign_in_image` (EXECUTED by the
 *     skeptics of P1-SEC-70 on MariaDB 10.11; the repository's own S46 note records the same fold from MySQL 8.0.46);
 *   - `settings.name` is VARCHAR(255) and production runs `strict => false`, so a longer name is STORED CUT to 255 characters, the spaces that remain are PAD SPACE, and
 *     a name that ENDS AT AN INVALID UTF-8 BYTE is stored up to it (`domains\xFFx` is stored as `domains`: EXECUTED on 8.0.46 by verify-host-claims-on-mysql.php).
 *
 * So the stored name IS the sign-in name, the rule never saw it, and the value -- a `..`, `.env`, a path in another tenant's folder -- was never judged. The Store
 * Setting door (`POST /seo-setting`, `POST /theme-settings`: it writes EVERY posted name into `settings` for the caller's store, and needs no plan and no module) is the
 * writer. P1-SEC-70 contains the DELETE (it judges the row and the place); this is the writer, the same rule at the same hook.
 *
 * THE FIX judges the name as it will be STORED and puts a name PHP cannot judge to the ENGINE, the way HostClaims does it for the host settings (the first form of
 * this change refused every non-ASCII name and broke 23 HostClaimsTest cases that pin a review's decision: "an accented name that is not a host name is still saved").
 * A name that the engine reads as a sign-in name has its VALUE held to the path rule; a name that folds onto nothing here is saved as before.
 *
 * SQLITE COMPARES BYTES, so the fold itself cannot be shown here: the cases that need the engine give the connection a `lower()` that folds the spellings under test
 * (HostClaimsTest's technique), and say so. The real fold, on a real server, before and after, is tests/Support/Security/bin/verify-setting-name-writer-on-mysql.php.
 * The cut cases (an invalid byte, padding past the column) need no engine: the stored name is PLAIN.
 *
 * ORACLE. The table, read raw: `settings` is a Cachable model, and a model read would answer from the cache.
 */
class SettingNameAtTheWriterTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const SIGN_IN = 'facebook_sign_in_image';

    /** the value a planted row would carry: a path that leaves the application */
    private const POISON = '../../.env';

    private User $merchant;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        // a user-defined SQLite function lives as long as the PDO, and the in-memory connection outlives the test (HostClaimsTest installs its own fold and does not
        // take it out): start from a connection that compares bytes, and leave one
        $this->asciiOnlyLower();

        [$this->merchant, $this->store] = $this->makeTenant();
    }

    protected function tearDown(): void
    {
        $this->asciiOnlyLower();

        parent::tearDown();
    }

    /** SQLite's own `lower()`: ASCII letters only. (PHP 8's strtolower is the same function, and locale-insensitive.) */
    private function asciiOnlyLower(): void
    {
        DB::connection()->getPdo()->sqliteCreateFunction('lower', fn ($value) => $value === null ? null : strtolower((string) $value), 1);
    }

    /** rows of this store straight from the table: [name => value] in id order */
    private function rows(): array
    {
        return DB::table('settings')->where('store_id', $this->store->id)->orderBy('id')->pluck('value', 'name')->all();
    }

    private function rowCount(): int
    {
        return DB::table('settings')->where('store_id', $this->store->id)->count();
    }

    private function own(string $leaf = 'sign-in.png'): string
    {
        return 'uploads/' . $this->store->id . '/' . $leaf;
    }

    private function create(string $name, ?string $value): Setting
    {
        return Setting::create(['name' => $name, 'value' => $value, 'store_id' => $this->store->id, 'created_by' => $this->merchant->id]);
    }

    /**
     * What utf8mb4_unicode_ci does to the spellings under test, as a `lower()` for SQLite: accents fold (é è à), a zero-width space and the control bytes it ignores
     * vanish, a full-width letter and the Kelvin sign fold onto their ASCII letters, a TRAILING no-break space is PAD SPACE. Measured on a real server by the MySQL script;
     * this is a stand-in for the connection, so that the HOOK's question to the engine has an engine to answer it.
     */
    private function foldLikeTheProductionCollation(): void
    {
        DB::connection()->getPdo()->sqliteCreateFunction('lower', function ($value) {
            if ($value === null) {
                return null;
            }

            $folded = strtr(mb_strtolower((string) $value), ['é' => 'e', 'è' => 'e', 'à' => 'a', "\u{200B}" => '', "\u{FF49}" => 'i', "\x01" => '', "\x7F" => '']);

            return preg_replace('/\x{00A0}+$/u', '', $folded);
        }, 1);
    }

    /** @return array<string, array{string}> spellings the engine folds onto a sign-in name */
    public static function spellingsTheEngineFolds(): array
    {
        return [
            'an accent (é)'                          => ["facebook_sign_in_imag\u{00E9}"],
            'an accent (è)'                          => ["facebook_sign_in_imag\u{00E8}"],
            'a trailing zero-width space'            => ["facebook_sign_in_image\u{200B}"],
            'a trailing no-break space'              => ["facebook_sign_in_image\u{00A0}"],
            'a full-width i'                         => ["facebook_sign_\u{FF49}n_image"],
            'a capital full-width I'                 => ["facebook_sign_\u{FF29}n_image"],
            'a trailing DEL'                         => ["facebook_sign_in_image\x7F"],
            'case and an accent together'            => ["FACEBOOK_SIGN_IN_IMAG\u{00C9}"],
            'a leading space and an accent'          => [" facebook_sign_in_imag\u{00E9}"],
            'a folded spelling the column cuts'      => ["facebook_sign_in_imag\u{00E9}" . str_repeat(' ', 300) . 'x'],
        ];
    }

    /**
     * @return array<string, array{string}> spellings the engine folds in the PREFIX only: the name still ENDS in `_sign_in_image`, which the pattern has always
     *         seen, so these were never a way in. Controls, and the reason the engine provider above alters the suffix.
     */
    public static function spellingsOnlyThePrefixFolds(): array
    {
        return [
            'an accent on the first letter' => ["f\u{00E0}cebook_sign_in_image"],
            'the Kelvin sign for k'         => ["lin\u{212A}edin_sign_in_image"],
            'an ignorable control byte'     => ["facebook\x01_sign_in_image"],
        ];
    }

    /** @return array<string, array{string}> spellings whose STORED form is a plain sign-in name: no engine is needed to see it */
    public static function spellingsTheColumnCuts(): array
    {
        return [
            'an invalid UTF-8 byte the engine cuts at'  => ["facebook_sign_in_image\xFFx"],
            'a truncated multibyte sequence'            => ["facebook_sign_in_image\xC3"],
            'an encoded surrogate'                      => ["linkedin_sign_in_image\xED\xA0\x80"],
            'padding the column cuts off'               => ['facebook_sign_in_image' . str_repeat(' ', 300) . 'x'],
            'padding that ends the name at 256'         => ['facebook_sign_in_image' . str_repeat(' ', 233) . 'x'],
        ];
    }

    /** @return array<string, array{string}> spellings PHP already saw through, before this change: the controls that must not move */
    public static function spellingsPhpSeesThrough(): array
    {
        return [
            'upper case'          => ['FACEBOOK_SIGN_IN_IMAGE'],
            'surrounding spaces'  => [' facebook_sign_in_image '],
            'a trailing tab'      => ["facebook_sign_in_image\t"],
            'a trailing newline'  => ["facebook_sign_in_image\n"],
            'a trailing NUL'      => ["facebook_sign_in_image\x00"],
            'the other provider'  => ['LinkedIn_Sign_In_Image'],
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // premises -- without these, a refusal below could be the writer never being reached
    // ---------------------------------------------------------------------------------------------

    /** the hook is wired and the unchanged rule judges a PLAIN sign-in name (this is the control the whole class stands next to) */
    public function test_premise_a_plain_sign_in_name_with_a_poisoned_value_is_refused_by_the_existing_rule(): void
    {
        try {
            $this->create(self::SIGN_IN, self::POISON);
            $this->fail('Premise: the P1-SEC-28 rule did not refuse a plain sign-in name with a value that leaves the upload folder, so nothing below can show the writer is what refuses.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('value', $e->errors());
        }

        $this->assertSame(0, $this->rowCount());
    }

    /** the Store Setting door writes an ordinary posted name for an ordinary merchant: this is the door the planting goes through */
    public function test_premise_the_store_setting_door_writes_an_ordinary_posted_name(): void
    {
        $this->actingAs($this->merchant)
            ->post('/seo-setting', ['google_analytic' => 'g', 'fbpixel_code' => 'f', 'metakeyword' => 'k', 'metadesc' => 'd', 'sec78_ordinary' => 'v'])
            ->assertSessionMissing('error');

        $this->assertSame('v', $this->rows()['sec78_ordinary'] ?? null, 'Premise: the door did not write the posted name for this merchant.');
    }

    /** the stand-in folds, SQLite does not on its own, and the two spellings are different strings: so the engine path below is exercised and the control is a control */
    public function test_premise_the_connection_folds_only_when_the_stand_in_is_installed(): void
    {
        $sql = 'select lower(trim(?)) = ? as eq';

        $this->assertSame(0, (int) DB::selectOne($sql, ["facebook_sign_in_imag\u{00E9}", self::SIGN_IN])->eq, 'SQLite compares bytes: nothing folds until the stand-in is installed.');

        $this->foldLikeTheProductionCollation();

        $this->assertSame(1, (int) DB::selectOne($sql, ["facebook_sign_in_imag\u{00E9}", self::SIGN_IN])->eq);
        $this->assertSame(0, (int) DB::selectOne($sql, ["th\u{00E8}me_color", self::SIGN_IN])->eq);
    }

    public function test_premise_the_two_lists_of_sign_in_rows_agree(): void
    {
        $this->assertSame(array_values(SignInImageDeleteGuard::HANDLERS), StoredPathPolicy::SIGN_IN_ROWS, 'A third module of this shape has to be added to the guard AND to the writer rule.');
    }

    // ---------------------------------------------------------------------------------------------
    // the defect, part one: the ENGINE folds the posted spelling onto the sign-in name
    // ---------------------------------------------------------------------------------------------

    #[DataProvider('spellingsTheEngineFolds')]
    public function test_a_poisoned_value_is_not_written_under_a_spelling_the_engine_folds(string $name): void
    {
        $this->foldLikeTheProductionCollation();

        try {
            $this->create($name, self::POISON);
            $this->fail('The writer created a row named ' . json_encode($name, JSON_INVALID_UTF8_SUBSTITUTE) . ' holding a path that leaves the upload folder.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('value', $e->errors(), 'The refusal is the path rule\'s, on the field every other path refusal names.');
        }

        $this->assertSame(0, $this->rowCount(), 'A refused write left a row behind.');
    }

    #[DataProvider('spellingsTheEngineFolds')]
    public function test_the_store_setting_door_does_not_plant_a_poisoned_row_under_a_folded_spelling(string $name): void
    {
        $this->foldLikeTheProductionCollation();

        $this->actingAs($this->merchant)
            ->from('/app-setting')
            ->post('/seo-setting', ['google_analytic' => 'g', 'fbpixel_code' => 'f', 'metakeyword' => 'k', 'metadesc' => 'd', $name => self::POISON])
            ->assertSessionHasErrors('value');

        $this->assertNotContains(self::POISON, array_values($this->rows()), 'The door planted a row whose value leaves the upload folder.');
        $this->assertCount(4, $this->rows(), 'Only the four fields the form itself posts may have been written before the refusal.');
    }

    /** the second way in: an EXISTING row is the one the engine finds by the fold, and the writer's second argument RENAMES it */
    #[DataProvider('spellingsTheEngineFolds')]
    public function test_a_row_is_not_renamed_onto_a_folded_spelling_with_a_poisoned_value(string $name): void
    {
        $this->foldLikeTheProductionCollation();
        $row = $this->create(self::SIGN_IN, $this->own());
        $before = $this->rows();

        $row->name = $name;
        $row->value = self::POISON;

        try {
            $row->save();
            $this->fail('The writer renamed the row onto ' . json_encode($name, JSON_INVALID_UTF8_SUBSTITUTE) . ' and let a poisoned value through.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('value', $e->errors());
        }

        $this->assertSame($before, $this->rows(), 'A refused rename changed the stored row.');
    }

    // ---------------------------------------------------------------------------------------------
    // the defect, part two: the COLUMN cuts the posted name onto the sign-in name (no engine involved: the stored name is plain)
    // ---------------------------------------------------------------------------------------------

    #[DataProvider('spellingsTheColumnCuts')]
    public function test_a_poisoned_value_is_not_written_under_a_name_the_column_cuts_onto_a_sign_in_name(string $name): void
    {
        try {
            $this->create($name, self::POISON);
            $this->fail('The writer created a row named ' . json_encode($name, JSON_INVALID_UTF8_SUBSTITUTE) . ' -- stored as a sign-in name -- holding a path that leaves the upload folder.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('value', $e->errors());
        }

        $this->assertSame(0, $this->rowCount());
    }

    #[DataProvider('spellingsTheColumnCuts')]
    public function test_the_store_setting_door_does_not_plant_one_under_a_cut_name(string $name): void
    {
        $this->actingAs($this->merchant)
            ->from('/app-setting')
            ->post('/seo-setting', ['google_analytic' => 'g', 'fbpixel_code' => 'f', 'metakeyword' => 'k', 'metadesc' => 'd', $name => self::POISON])
            ->assertSessionHasErrors('value');

        $this->assertNotContains(self::POISON, array_values($this->rows()));
        $this->assertCount(4, $this->rows());
    }

    #[DataProvider('spellingsTheColumnCuts')]
    public function test_a_row_is_not_renamed_onto_a_name_the_column_cuts_with_a_poisoned_value(string $name): void
    {
        $row = $this->create(self::SIGN_IN, $this->own());
        $before = $this->rows();

        $row->name = $name;
        $row->value = self::POISON;

        try {
            $row->save();
            $this->fail('The writer renamed the row onto a name the column cuts to a sign-in name and let a poisoned value through.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('value', $e->errors());
        }

        $this->assertSame($before, $this->rows());
    }

    // ---------------------------------------------------------------------------------------------
    // the rule is the VALUE rule, not a refusal of the name: what the owner could always do still works
    // ---------------------------------------------------------------------------------------------

    #[DataProvider('spellingsTheEngineFolds')]
    #[DataProvider('spellingsOnlyThePrefixFolds')]
    public function test_a_folded_spelling_with_a_path_in_an_upload_folder_is_still_written(string $name): void
    {
        $this->foldLikeTheProductionCollation();

        $this->create($name, $this->own());

        $this->assertSame([$name => $this->own()], $this->rows(), 'This change judges the value of a sign-in row; it does not refuse the spelling.');
    }

    #[DataProvider('spellingsPhpSeesThrough')]
    #[DataProvider('spellingsOnlyThePrefixFolds')]
    public function test_the_spellings_php_already_judged_are_still_judged(string $name): void
    {
        $this->foldLikeTheProductionCollation();

        try {
            $this->create($name, self::POISON);
            $this->fail('A spelling PHP can see through was written with a poisoned value: ' . json_encode($name));
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('value', $e->errors());
        }

        $this->assertSame(0, $this->rowCount());
    }

    public function test_a_plain_sign_in_name_with_a_path_in_the_stores_folder_is_still_written(): void
    {
        $this->create(self::SIGN_IN, $this->own());

        $this->assertSame([self::SIGN_IN => $this->own()], $this->rows());
    }

    /** HostClaimsTest pins the same decision for the host names: a name that folds onto nothing is nobody's business */
    public function test_names_that_fold_onto_no_sign_in_name_are_saved_with_any_value(): void
    {
        $this->foldLikeTheProductionCollation();

        foreach (["th\u{00E8}me_color", 'Some Setting Name', str_repeat('n', 300), 'facebook_sign_in_image_extra', 'sign_in_image', "facebook_sign_in_image\u{00A0}x", 'metakeyword'] as $name) {
            $this->create($name, self::POISON);
        }

        $this->assertCount(7, $this->rows());
        $this->assertSame(self::POISON, $this->rows()['facebook_sign_in_image_extra'], 'Only a name that is a sign-in row is held to the path rule.');
    }

    /** a row already in the table under a folded spelling: an unrelated save is not judged, and a new value is -- a legacy row is never made unsaveable by an unrelated edit */
    public function test_a_legacy_row_under_a_folded_spelling_keeps_saving_what_the_rule_accepts(): void
    {
        $this->foldLikeTheProductionCollation();
        $legacy = "facebook_sign_in_imag\u{00E9}";
        $id = DB::table('settings')->insertGetId(['name' => $legacy, 'value' => self::POISON, 'store_id' => $this->store->id, 'created_by' => $this->merchant->id, 'created_at' => now(), 'updated_at' => now()]);

        $row = Setting::withoutGlobalScopes()->findOrFail($id);
        $row->updated_at = now()->addMinute();
        $row->save();   // nothing the rule reads changed
        $this->assertSame(self::POISON, DB::table('settings')->where('id', $id)->value('value'), 'Premise: an unrelated save of the legacy row goes through, as before.');

        $row->value = self::POISON . '/x';
        try {
            $row->save();
            $this->fail('A new poisoned value was written to a legacy row under a folded spelling.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('value', $e->errors());
        }

        $row->value = $this->own('replaced.png');
        $row->save();
        $this->assertSame($this->own('replaced.png'), DB::table('settings')->where('id', $id)->value('value'));
    }

    /** renaming an ordinary row onto a sign-in name judges the value it already has: the NAME changed, the value did not */
    public function test_renaming_an_ordinary_row_onto_a_sign_in_name_judges_its_existing_value(): void
    {
        $row = $this->create('metakeyword', self::POISON);

        $row->name = self::SIGN_IN;
        try {
            $row->save();
            $this->fail('A row holding a path that leaves the upload folder was renamed onto a sign-in name.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('value', $e->errors());
        }

        $this->assertSame(['metakeyword' => self::POISON], $this->rows());
    }

    // ---------------------------------------------------------------------------------------------
    // the predicate and its cost
    // ---------------------------------------------------------------------------------------------

    public function test_which_names_are_sign_in_names(): void
    {
        $this->foldLikeTheProductionCollation();

        foreach (array_merge(self::spellingsTheEngineFolds(), self::spellingsOnlyThePrefixFolds(), self::spellingsTheColumnCuts(), self::spellingsPhpSeesThrough(), ['plain' => [self::SIGN_IN], 'any provider' => ['google_sign_in_image']]) as $label => [$name]) {
            $this->assertTrue(StoredPathPolicy::isSignInName($name), $label . ' is a sign-in name');
        }

        foreach (['', 'metakeyword', 'sign_in_image', 'facebook_sign_in_image_extra', 'x_sign_in_images', "th\u{00E8}me_color", "facebook_sign_in_image\u{00A0}x", 'Some Setting Name', "facebook\xFF_sign_in_image", str_repeat('n', 300)] as $name) {
            $this->assertFalse(StoredPathPolicy::isSignInName($name), json_encode($name, JSON_INVALID_UTF8_SUBSTITUTE) . ' is not a sign-in name');
        }
    }

    /**
     * Only a name that is not plain printable ASCII, and only when the save changes the name or the value, costs the engine a question, and the question is about the name AS
     * STORED. (HostClaimsTest pins its own query the same way.)
     */
    public function test_only_a_changing_name_that_is_not_plain_is_put_to_the_engine(): void
    {
        $asked = [];
        DB::listen(function ($query) use (&$asked) {
            if (str_starts_with($query->sql, 'select lower(trim(?)) in')) {
                $asked[] = $query->bindings;
            }
        });

        $plain = $this->create('theme_color', '#fff');
        $this->create('FACEBOOK_SIGN_IN_IMAGE', $this->own());      // the pattern sees it
        $this->create("facebook_sign_in_image\xFFx", $this->own()); // the stored name is plain
        $plain->updated_at = now()->addMinute();
        $plain->save();                                             // nothing the rule reads changed
        $this->assertSame([], $asked, 'A plain name, a name the pattern sees, a cut name and an unrelated save must cost no query.');

        $this->create("th\u{00E8}me_color", '#fff');
        $this->assertSame([["th\u{00E8}me_color", 'facebook_sign_in_image', 'linkedin_sign_in_image']], $asked, 'The engine is asked about the stored name and both rows the handlers read.');

        $this->create("th\u{00E8}me_" . str_repeat('x', 300), '#fff');
        $this->assertCount(2, $asked);
        $this->assertSame(255, mb_strlen($asked[1][0]), 'The engine is asked about the name as the column keeps it, not as it was posted.');
    }
}
