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
 * THE CONFINEMENT WENT ON THE DELETE DOOR AND NOT ON THE CREATE DOOR THAT FEEDS IT.
 *
 * Found by independent adversarial review of `9a486500` (the fix that confined
 * `DELETE /lang/{lang}`), at "certain" confidence and surviving three refutation rounds. The
 * reviewer's words: the helper "was applied to the delete door and not to the create door, which
 * writes `resources/lang/<raw POST value>` with no validation at all".
 *
 * MEASURED, NOT INFERRED. `LanguageController` contains ZERO `validate(` or `Validator` calls.
 * `storeLanguage()` takes `$request->code` straight from the POST BODY -- and a body field, unlike
 * the `{lang}` route segment, is NOT even constrained to `[^/]+`, so it may contain slashes. It is
 * lowercased and concatenated into three filesystem sinks:
 *
 *   :531  \File::copy($langDir . 'en.json', $jsonFile)        a FILE WRITE at an arbitrary path
 *   :535  mkdir($dir); chmod($dir, 0777)                      a DIRECTORY CREATE, world-writable
 *   :538  $Filesystem->copyDirectory($langDir . 'en', $dir)   a RECURSIVE TREE WRITE
 *
 * Where that lands, measured by resolving the handler's own string arithmetic against this
 * repository's `base_path()`:
 *
 *   code = 'ar'              ->  <base>/resources/lang/ar          (intended)
 *   code = '../../public/x'  ->  <base>/public/x                   THE WEBROOT
 *
 * and `resources/lang/en` holds SIX `.php` files, so the recursive copy places executable PHP in
 * whatever directory the code names. That is the whole finding: not a disclosure, a WRITE.
 *
 * SEVERITY, STATED HONESTLY AND NOT INFLATED. The route is `POST store-language`
 * (`routes/web.php:1614`) behind `isAbleTo('Create Language')`, seeded only to the platform
 * operator -- so this is operator-reachable, exactly like the module-removal door (`c3010288`) and
 * the language-delete door (`9a486500`), and NOT a privilege escalation. The content copied is the
 * shipped `en` tree, not caller-supplied bytes. What the operator gains is the ability to write a
 * directory of `.php` files, and a `.json` file, to any path the process can write -- including
 * under the document root.
 *
 * A SECOND DEFECT IN THE SAME HANDLER, found while measuring the first and not in the review: the
 * directory name is `strtolower($request->code)` (:521) but the manifest key is the RAW
 * `$request->code` (:548). So `code=AR` creates the directory `ar` and the `language.json` key
 * `AR`, and nothing afterwards can match them up: `manage.language`, the switcher and the delete
 * door all take ONE code and use it for both. The shipped tree already shows what that asymmetry
 * costs -- `tr` is a manifest key with no directory and `th` a directory with no manifest key.
 *
 * WHY NO TRAVERSAL IS DRIVEN THROUGH THE ROUTE HERE, which is the same reasoning as the delete
 * door's and for the same reason. A failing-first test for `code=../../public/x` has to run against
 * the UNFIXED handler, which would then really write `<base>/public/x.json` and copy six PHP files
 * into `<base>/public/x` -- in the working tree of a real repository. There is no run order in
 * which that is safe. So: the escape is proved by resolving the path arithmetic against a
 * DISPOSABLE root in `tests/Unit/Filesystem/LanguageCreationTargetEscapesTest`, the wiring is
 * pinned structurally below, and the codes driven through the route here are ones whose pre-fix
 * writes land INSIDE `resources/lang` and are removed again by `tearDown()`.
 */
class LanguageCreationIsConfinedTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    /** Accepted, and the control that the feature still works. */
    private const VALID = 'zz9test';

    /** Refused by the fix. Pre-fix it creates `resources/lang/zz9 test{,.json}` -- inside the tree. */
    private const SPACED = 'zz9 test';

    /** Accepted, but pre-fix the directory is `zz9mixed` while the manifest key is `ZZ9Mixed`. */
    private const MIXED = 'ZZ9Mixed';

    private string $langRoot;

    private ?string $manifestBackup = null;

    /** Every name any test in this class could cause to exist, pre-fix or post-fix. */
    private function disposableNames(): array
    {
        return [
            self::VALID,
            self::SPACED,
            self::MIXED,
            strtolower(self::MIXED),
            strtolower(self::SPACED),
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->langRoot = base_path('resources/lang');

        // `language.json` is a TRACKED file and the handler rewrites it: back it up verbatim.
        $manifest = $this->langRoot . '/language.json';
        $this->manifestBackup = is_file($manifest) ? (string) file_get_contents($manifest) : null;

        $this->cleanDisposables();
    }

    protected function tearDown(): void
    {
        $this->cleanDisposables();

        if ($this->manifestBackup !== null) {
            file_put_contents($this->langRoot . '/language.json', $this->manifestBackup);
        }

        parent::tearDown();
    }

    /**
     * Remove every disposable name, recursively, CONFINED to the language root.
     *
     * The confinement here is not decoration. `copyDirectory()` reproduces the whole `en` tree, so
     * this has to recurse -- and a recursive delete in a test fixture is the same hazard as the one
     * being fixed. Each target is resolved and proved to sit under `resources/lang` before anything
     * is unlinked, and a symlink is unlinked rather than followed.
     */
    private function cleanDisposables(): void
    {
        $root = realpath($this->langRoot);

        if ($root === false) {
            return;
        }

        foreach ($this->disposableNames() as $name) {
            $this->removeConfined($root, $root . DIRECTORY_SEPARATOR . $name);
            $this->removeConfined($root, $root . DIRECTORY_SEPARATOR . $name . '.json');
        }
    }

    private function removeConfined(string $root, string $target): void
    {
        if (is_link($target)) {
            @unlink($target);

            return;
        }

        if (! file_exists($target)) {
            return;
        }

        $real = realpath($target);

        if ($real === false || $real === $root || ! str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            return;
        }

        if (is_dir($real)) {
            foreach (array_diff(scandir($real) ?: [], ['.', '..']) as $entry) {
                $this->removeConfined($root, $real . DIRECTORY_SEPARATOR . $entry);
            }

            @rmdir($real);

            return;
        }

        @unlink($real);
    }

    /**
     * An operator who can actually reach the handler.
     *
     * The same premise as the delete door's: CLAUDE.md records that Laratrust's
     * `permissions_as_gates` is false and there is NO super-admin bypass, so `isAbleTo()` alone
     * refuses the platform operator. Without the role the handler answers 'Permission denied.' and
     * every assertion below would be satisfied by the gate instead of by the fix.
     */
    private function operatorWhoMayCreateLanguages(): User
    {
        $operator = $this->makePlatformAdmin();

        $permission = Permission::create([
            'name' => 'Create Language',
            'display_name' => 'Create Language',
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

        $role->givePermission('Create Language');
        $operator->addRole($role);
        $operator->refresh();

        $this->assertTrue(
            $operator->isAbleTo('Create Language'),
            'premise: this operator must really hold the permission, or the test proves only that '
            . 'the gate refused it.'
        );

        return $operator;
    }

    private function manifest(): array
    {
        return json_decode((string) file_get_contents($this->langRoot . '/language.json'), true) ?: [];
    }

    // ---------------------------------------------------------------------------------------------
    // the control: creation must still work
    // ---------------------------------------------------------------------------------------------

    /**
     * THE CONTROL AGAINST AN OVER-BROAD FIX. A conforming code must still create a language, or
     * "refuse everything" would pass every test below while removing the feature.
     */
    #[Test]
    public function test_a_valid_code_still_creates_a_language(): void
    {
        $operator = $this->operatorWhoMayCreateLanguages();

        $this->actingAs($operator)->post('/store-language', [
            'code' => self::VALID,
            'name' => 'Disposable Test Language',
        ]);

        $this->assertDirectoryExists(
            $this->langRoot . '/' . self::VALID,
            'The fix refuses a legitimate language code, so it is not a fix -- it is an outage.'
        );
        $this->assertFileExists($this->langRoot . '/' . self::VALID . '.json');
        $this->assertArrayHasKey(self::VALID, $this->manifest());
    }

    // ---------------------------------------------------------------------------------------------
    // the refusals
    // ---------------------------------------------------------------------------------------------

    /**
     * A NON-CONFORMING CODE IS REFUSED AND WRITES NOTHING.
     *
     * A space is the safe proxy for the whole class: it fails the same allowlist a traversal fails,
     * and its pre-fix write lands inside `resources/lang` instead of outside the repository. Pre-fix
     * this request SUCCEEDS and creates `resources/lang/zz9 test` plus the sidecar -- which is what
     * makes this test fail first.
     */
    #[Test]
    public function test_a_code_the_allowlist_refuses_creates_nothing(): void
    {
        $operator = $this->operatorWhoMayCreateLanguages();

        $this->actingAs($operator)->post('/store-language', [
            'code' => self::SPACED,
            'name' => 'Should Not Exist',
        ]);

        $this->assertDirectoryDoesNotExist(
            $this->langRoot . '/' . self::SPACED,
            'a code outside the allowlist still reached mkdir()'
        );
        $this->assertFileDoesNotExist(
            $this->langRoot . '/' . self::SPACED . '.json',
            'a code outside the allowlist still reached \\File::copy()'
        );
        $this->assertArrayNotHasKey(
            self::SPACED,
            $this->manifest(),
            'a refused code still reached the manifest rewrite'
        );
    }

    /**
     * AND THE REFUSAL HAPPENS BEFORE THE FIRST WRITE, NOT AFTER IT.
     *
     * Distinct from the test above, which would also pass if the handler wrote everything and then
     * rolled back. The contract the owner stated is that the check runs BEFORE any filesystem
     * operation; a handler that creates the directory and then deletes it has already chmod'd
     * something to 0777 at a path it never validated.
     */
    #[Test]
    public function test_the_code_is_validated_before_any_path_is_built_from_it(): void
    {
        $body = $this->methodBody('storeLanguage');

        $validation = strpos($body, 'safeSegment');
        $this->assertNotFalse($validation, 'storeLanguage() does not validate the code at all.');

        foreach ([
            'the lowercase normalisation' => 'strtolower(',
            'the sidecar copy' => 'File::copy(',
            'the directory create' => 'mkdir(',
            'the recursive tree copy' => 'copyDirectory(',
            'the manifest write' => 'File::put(',
        ] as $what => $needle) {
            $at = strpos($body, $needle);

            $this->assertNotFalse($at, "storeLanguage() no longer contains {$what} ({$needle}).");
            $this->assertGreaterThan(
                $validation,
                $at,
                "{$what} is built before the code is validated, so an unvalidated value reaches it."
            );
        }
    }

    /**
     * THE MANIFEST KEY AND THE DIRECTORY NAME MUST BE THE SAME STRING.
     *
     * The second defect, found by measurement rather than by the review. Pre-fix the directory is
     * `strtolower($request->code)` and the key is the raw `$request->code`, so `ZZ9Mixed` creates
     * the directory `zz9mixed` and the key `ZZ9Mixed`, and every later door -- the switcher,
     * `manage.language`, `DELETE /lang/{lang}` -- takes a single code and uses it for both. This
     * asserts the invariant, not a particular casing, so it holds whichever way the fix normalises.
     */
    #[Test]
    public function test_the_manifest_key_names_the_directory_that_was_created(): void
    {
        $operator = $this->operatorWhoMayCreateLanguages();

        $this->actingAs($operator)->post('/store-language', [
            'code' => self::MIXED,
            'name' => 'Mixed Case Test Language',
        ]);

        $keys = array_values(array_intersect(
            array_keys($this->manifest()),
            [self::MIXED, strtolower(self::MIXED)]
        ));

        $this->assertCount(
            1,
            $keys,
            'the request registered neither code in the manifest, so the premise is gone'
        );

        $this->assertDirectoryExists(
            $this->langRoot . '/' . $keys[0],
            'the manifest key "' . $keys[0] . '" names no directory: the key and the directory '
            . 'disagree, so this language can be selected and will resolve to nothing'
        );
    }

    /**
     * The handler's own source, comments stripped and scoped to the one method.
     *
     * CLAUDE.md records this failing four times in one day: a census that reads comments can be
     * satisfied -- or broken -- by prose ABOUT the thing it measures, and this file's own docblock
     * names every one of the needles above. Scoping to the method matters for the same reason:
     * `destroyLang()` in the same controller already calls `safeSegment`, so an unscoped search
     * would answer "yes" for a `storeLanguage()` that validates nothing.
     */
    private function methodBody(string $method): string
    {
        $source = $this->sourceWithoutComments('app/Http/Controllers/LanguageController.php');

        $start = strpos($source, 'function ' . $method . '(');
        $this->assertNotFalse($start, "LanguageController::{$method}() no longer exists.");

        $next = strpos($source, "\n    public function ", $start + 1);

        return $next === false
            ? substr($source, $start)
            : substr($source, $start, $next - $start);
    }
}
