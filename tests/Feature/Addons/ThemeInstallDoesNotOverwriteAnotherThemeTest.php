<?php

namespace Tests\Feature\Addons;

use App\Models\Addon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;
use ZipArchive;

/**
 * A THEME ARCHIVE WAS EXTRACTED INTO THE THEMES ROOT AT THE PATHS THE ARCHIVE CHOSE.
 *
 * Phase 06 item 2(C), and the finding is NARROWER than the sweep recorded -- the correction is as
 * much the point of this file as the fix.
 *
 * WHAT THE SWEEP SAID, AND WHAT IS ACTUALLY TRUE. It recorded "theme installation extracts before
 * validating". That is REFUTED: `InstallAddonRequest::archiveRejectionReason()` runs BEFORE any
 * extraction and is substantial -- it normalises Windows separators, refuses absolute paths, drive
 * letters, `..`, `../`, `/../` and `/..`, refuses `.htaccess`, `.htpasswd`, `.user.ini`, `.npmrc`,
 * `.netrc`, `web.config`, `php.ini` and anything starting `.env`, and enforces an extension
 * allowlist. Authorization is asserted twice, in the FormRequest and again in the method. A previous
 * slice (SEC-F5) did that work.
 *
 * The sweep also noted that uploaded PHP renders. True, and NOT a defect:
 * `ALLOWED_ENTRY_EXTENSIONS` contains `php` and `blade.php` because a theme IS Blade code, so
 * installing a theme means installing code by design, and only `manage-platform-addons` may do it.
 *
 * WHAT SURVIVES. `ThemeInstall()` called `$zip->extractTo(base_path('themes/'))` before deriving any
 * name. Traversal was already blocked, so nothing escaped `themes/` -- but nothing checked whether
 * the archive targeted a directory that ALREADY HELD AN INSTALLED THEME. An archive whose entries
 * read `<installed-theme>/...` replaced that theme's files in place, and every store on it rendered
 * the replacement.
 *
 * AND THAT LINE WAS DEAD FOR THE INSTALL, which is why the fix is one deletion. The archive is
 * extracted a SECOND time into `themes/tmp_<uniqid>`, and the temp copy is what everything
 * downstream uses: `scandir($tempPath)` finds the root folder, `moveExtractedFiles($tempPath, ...)`
 * moves the files, and the destination is derived from the uploaded FILENAME through
 * `safeDirectoryName()`. Nothing between the two extractions read a byte the first one wrote.
 *
 * SEVERITY: operator-reachable, not a privilege escalation -- the same disposition as the
 * module-removal door. One operator action should add a theme, not silently replace another.
 *
 * EVERY DIRECTORY THIS TEST TOUCHES IS ONE IT CREATED. `themes/` holds the real shipped themes
 * (stylique, greentic, techzonix), so the fixture uses `zz9`-prefixed names that are not themes, and
 * `tearDown` removes exactly what `setUp` did not see. No assertion names a shipped theme, and the
 * "victim" of the overwrite test is a disposable directory this test makes itself.
 */
class ThemeInstallDoesNotOverwriteAnotherThemeTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private const VICTIM = 'zz9victim';

    private const UPLOAD = 'zz9attacker';

    private string $themesRoot;

    /** @var list<string> the directories that existed before this test ran */
    private array $baseline = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->themesRoot = base_path('themes');
        $this->baseline = array_diff(scandir($this->themesRoot) ?: [], ['.', '..']);
    }

    protected function tearDown(): void
    {
        /*
         * AN EMPTY BASELINE MEANS "I DO NOT KNOW WHAT WAS HERE", NOT "NOTHING WAS HERE".
         *
         * Raised by independent review as speculative, and closed because the blast radius is the
         * three shipped themes. `setUp()` builds the baseline with `scandir(...) ?: []`, so a failed
         * scan -- or a `setUp()` that died before that line -- leaves it empty, and the loop below
         * would then treat `stylique`, `greentic` and `techzonix` as this test's own leftovers and
         * recursively delete all three. Unreachable in practice (same directory, same process), and
         * one line is cheaper than the argument.
         */
        if ($this->baseline === []) {
            parent::tearDown();

            return;
        }

        foreach (array_diff(scandir($this->themesRoot) ?: [], ['.', '..']) as $entry) {
            if (! in_array($entry, $this->baseline, true)) {
                $this->rmrf($this->themesRoot . '/' . $entry);
            }
        }

        parent::tearDown();
    }

    /** Removes only what this test created, never a shipped theme. */
    private function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->rmrf($path . '/' . $entry);
        }

        @rmdir($path);
    }

    /**
     * An archive whose single entry is `$entryPath`, with `$contents`.
     *
     * Built rather than fixtured so the entry path is explicit in the test that depends on it.
     */
    private function archiveNaming(string $entryPath, string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'ss-theme-') . '.zip';

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'premise');
        $zip->addEmptyDir(dirname($entryPath));
        $zip->addFromString($entryPath, $contents);
        $zip->close();

        return $file;
    }

    /**
     * An archive with SEVERAL top-level entries, built in the order given.
     *
     * `scandir()` sorts, so the order here is not what decides the install's choice -- the byte
     * values do. That is the whole point of the two cases below.
     */
    private function archiveWithEntries(array $entries): string
    {
        $file = tempnam(sys_get_temp_dir(), 'ss-theme-multi-') . '.zip';

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'premise');

        foreach ($entries as $path => $contents) {
            if (str_contains($path, '/')) {
                $zip->addEmptyDir(dirname($path));
            }
            $zip->addFromString($path, $contents);
        }

        $zip->close();

        return $file;
    }

    private function install(string $archivePath, string $uploadName): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->makePlatformAdmin())->post('/theme-addon', [
            'file' => new UploadedFile($archivePath, $uploadName . '.zip', 'application/zip', null, true),
        ]);
    }

    /**
     * THE DEFECT. An archive naming an already-installed theme must not touch it.
     */
    #[Test]
    public function test_an_archive_naming_an_installed_theme_does_not_overwrite_it(): void
    {
        // A disposable "installed theme" with a sentinel file. Never a shipped one.
        $victim = $this->themesRoot . '/' . self::VICTIM;
        mkdir($victim . '/front_end/layouts', 0o777, true);
        file_put_contents($victim . '/front_end/layouts/app.blade.php', 'ORIGINAL THEME');

        $archive = $this->archiveNaming(
            self::VICTIM . '/front_end/layouts/app.blade.php',
            'REPLACED BY THE UPLOAD'
        );

        $this->install($archive, self::UPLOAD);

        $this->assertSame(
            'ORIGINAL THEME',
            (string) file_get_contents($victim . '/front_end/layouts/app.blade.php'),
            'An uploaded archive replaced an ALREADY-INSTALLED theme\'s Blade file in place. '
            . 'ThemeInstall() extracted into base_path("themes/") at the paths the archive chose, '
            . 'before deriving any name -- so the archive, not the operator, picked the target.'
        );

        @unlink($archive);
    }

    /**
     * THE CONTROL. A theme must still install, into the directory derived from the UPLOADED FILENAME
     * -- otherwise the fix is an outage rather than a fix.
     */
    #[Test]
    public function test_a_theme_still_installs_under_the_name_of_the_uploaded_file(): void
    {
        $archive = $this->archiveNaming('zz9payload/front_end/layouts/app.blade.php', 'INSTALLED CONTENT');

        $this->install($archive, self::UPLOAD);

        $this->assertDirectoryExists(
            $this->themesRoot . '/' . self::UPLOAD,
            'The theme did not install. The destination is derived from the uploaded filename through '
            . 'safeDirectoryName(), and the temp extraction plus moveExtractedFiles() is what '
            . 'actually installs it.'
        );

        $this->assertNotNull(
            Addon::where('theme_id', self::UPLOAD)->first(),
            'The Addon row was not created, so the install did not complete.'
        );

        @unlink($archive);
    }

    /** No `tmp_*` working directory is left behind in the themes root. */
    #[Test]
    public function test_the_temporary_extraction_directory_is_removed(): void
    {
        $archive = $this->archiveNaming('zz9payload/front_end/layouts/app.blade.php', 'x');

        $this->install($archive, self::UPLOAD);

        $leftovers = array_values(array_filter(
            array_diff(scandir($this->themesRoot) ?: [], ['.', '..']),
            fn (string $e) => str_starts_with($e, 'tmp_')
        ));

        $this->assertSame([], $leftovers, 'A tmp_* working directory was left in themes/.');

        @unlink($archive);
    }

    /**
     * THE STRUCTURAL PIN: exactly ONE extraction, and it is the temp one.
     *
     * Comment-stripped and scoped to the method, because CLAUDE.md records four censuses in one day
     * satisfied by prose about the thing they measured -- and this method's new comment QUOTES the
     * deleted line verbatim, so an unstripped search would match it and pass forever.
     */
    #[Test]
    public function test_the_install_extracts_exactly_once_and_only_into_the_temporary_path(): void
    {
        $code = $this->sourceWithoutComments('app/Http/Controllers/AddonController.php');

        $start = strpos($code, 'public function ThemeInstall(');
        $this->assertNotFalse($start, 'premise: ThemeInstall() must exist');
        $end = strpos($code, 'public function ', $start + 10);
        $body = substr($code, $start, $end - $start);

        $this->assertStringNotContainsString('/*', $body, 'premise: comments must really be stripped');

        $this->assertSame(
            1,
            substr_count($body, '->extractTo('),
            'ThemeInstall() extracts the archive more than once. The second extraction into the '
            . 'themes ROOT is what let an archive pick its own target directory.'
        );

        $this->assertStringContainsString('$zip->extractTo($tempPath)', $body,
            'The single extraction is no longer the one into the unique temporary path.');

        $this->assertStringNotContainsString("extractTo(base_path('themes/'))", $body,
            'The extraction into the themes root is back.');
    }
    // ---------------------------------------------------------------------------------------------
    // found by independent review of THIS fix -- two shapes it did not close, and one it broke
    // ---------------------------------------------------------------------------------------------

    /**
     * AN UPLOAD NAME THAT SANITISES TO NOTHING MADE THE ARCHIVE PICK THE THEMES ROOT AGAIN.
     *
     * Review finding F11. Deleting the first `extractTo` closed the one-request overwrite, but
     * `$extractPath = base_path('themes/' . safeDirectoryName($filename))` has no `=== ''` check,
     * and `safeDirectoryName()` returns the empty string for a name with nothing in
     * `[A-Za-z0-9_-]`. Measured:
     *
     *   '....zip'   -> basename '...'      -> safeDirectoryName ''
     *   '@@@.zip'   -> basename '@@@'      -> safeDirectoryName ''
     *   'العربية.zip' -> basename 'العربية' -> safeDirectoryName ''
     *
     * THE ARABIC CASE IS WHY THIS IS NOT ONLY AN ATTACK. This product is bilingual; a theme zip
     * named in Arabic is an ordinary thing for this operator to upload, and it silently took the
     * themes root as its target.
     *
     * With an empty name `$extractPath` is `themes/`, `is_dir()` is true so the mkdir is skipped,
     * and `moveExtractedFiles()` merges the archive's root folder INTO the themes root -- recursing
     * into existing directories and overwriting files with `rename()`. Both siblings already have
     * this check: `ModuleController::install()` ("The uploaded file name is not a valid module
     * name") and `AddonController::destroy()`.
     *
     * SAFE TO RUN FAILING-FIRST, by construction: the archive's root folder is this test's own
     * disposable theme name, so even the unfixed merge-into-the-root writes only into a directory
     * this test created and `tearDown()` removes. No shipped theme is named in the archive.
     */
    #[Test]
    public function test_an_upload_name_that_sanitises_to_nothing_is_refused(): void
    {
        $victim = $this->themesRoot . '/' . self::VICTIM;
        mkdir($victim . '/front_end/layouts', 0o777, true);
        file_put_contents($victim . '/front_end/layouts/app.blade.php', 'ORIGINAL');

        /*
         * TWO LEVELS, AND THE FIRST DRAFT OF THIS TEST HAD ONE AND PASSED AGAINST THE UNFIXED
         * HANDLER. `moveExtractedFiles()` moves the CONTENTS of `tmp/<rootFolderName>`, so with a
         * single-level archive the root folder IS `zz9victim` and its contents land at
         * `themes/front_end/...` -- missing the victim entirely and proving nothing. The overwrite
         * needs the shape a real theme zip has: a wrapper directory that CONTAINS the theme
         * directory. The review said so in one clause -- "one extra nesting level" -- and it is the
         * difference between a test that fails first and a test that is decoration.
         */
        $archive = $this->archiveWithEntries([
            'wrapper/' . self::VICTIM . '/front_end/layouts/app.blade.php' => 'REPLACED BY THE UPLOAD',
        ]);

        $this->install($archive, '...');

        $this->assertSame(
            'ORIGINAL',
            file_get_contents($victim . '/front_end/layouts/app.blade.php'),
            'an upload whose name sanitises to the empty string still took the themes ROOT as its '
            . 'target, so the archive chose which installed theme to overwrite'
        );
    }

    /**
     * AND THE REFUSAL IS THE RESPONSE, NOT A SIDE EFFECT.
     *
     * Separate from the assertion above, which would also pass if the handler wrote somewhere else
     * entirely. This pins that the operator is told, rather than the upload silently doing nothing.
     */
    #[Test]
    public function test_an_upload_name_that_sanitises_to_nothing_says_so(): void
    {
        $archive = $this->archiveNaming(self::UPLOAD . '/style.css', 'body{}');

        $response = $this->install($archive, '@@@');

        $response->assertOk();
        $this->assertSame('error', $response->json('status'),
            'the handler accepted a name it cannot build a directory from');
    }

    /**
     * A MAC-BUILT ARCHIVE LOST THE THEME -- A REGRESSION THIS SLICE INTRODUCED.
     *
     * Review finding F10, and it is the one case where deleting the first `extractTo` made things
     * worse. The final contents of `themes/` used to be the UNION of that extraction and the move.
     * The move is partial by construction: `$rootFolderName` is
     * `array_values(array_diff(scandir($tempPath), ['.','..']))[0]` -- scandir's ALPHABETICALLY
     * FIRST entry. Measured on a directory holding `__MACOSX`, `greentic` and `.DS_Store`:
     *
     *   [0] => .DS_Store     [1] => __MACOSX     [2] => greentic
     *
     * `.` is 0x2E and `_` is 0x5F, both below lowercase `a` at 0x61 -- and every shipped theme is
     * named in lowercase. So for any Finder-built zip the move takes the sidecar, and the real
     * theme, which used to be on disk anyway because of the deleted extraction, is now left in the
     * temp tree and deleted with it. The result was `themes/<name>` holding the sidecar's contents,
     * an `Addon` row with `status = 1`, and a broken theme.
     *
     * The sibling installer guards the second half of this (`ModuleController::install()` falls
     * back to the temp root when the first entry is not a directory) and not the first.
     */
    #[Test]
    public function test_an_archive_carrying_a_macos_sidecar_still_installs_the_theme(): void
    {
        $archive = $this->archiveWithEntries([
            '__MACOSX/._' . self::UPLOAD => 'mac sidecar',
            self::UPLOAD . '/style.css' => 'body{color:red}',
        ]);

        $this->install($archive, self::UPLOAD);

        $this->assertFileExists(
            $this->themesRoot . '/' . self::UPLOAD . '/style.css',
            'the install moved the macOS sidecar instead of the theme, so the theme is gone'
        );
        $this->assertSame(
            'body{color:red}',
            file_get_contents($this->themesRoot . '/' . self::UPLOAD . '/style.css')
        );
    }

    /**
     * AND AN ARCHIVE WHOSE FIRST ENTRY IS A FILE MUST NOT BE AN HTTP 500.
     *
     * The second shape of the same finding. With a leading `.DS_Store`, `moveExtractedFiles()` sets
     * `$source` to that FILE, and `scandir()` on a file returns `false`, which `array_diff()`
     * rejects as a TypeError on PHP 8.3 -- leaving `themes/tmp_<uniqid>` behind and an empty target
     * directory. This happened before the fix too, so it is pre-existing rather than introduced,
     * but it is the same one-line cause and is closed with it.
     */
    #[Test]
    public function test_an_archive_whose_first_entry_is_a_file_still_installs_the_theme(): void
    {
        $archive = $this->archiveWithEntries([
            '.DS_Store' => 'finder metadata',
            self::UPLOAD . '/style.css' => 'body{color:blue}',
        ]);

        $response = $this->install($archive, self::UPLOAD);

        $response->assertOk();
        $this->assertFileExists(
            $this->themesRoot . '/' . self::UPLOAD . '/style.css',
            'a leading dotfile in the archive broke the install'
        );
    }

    /**
     * A FLAT ARCHIVE -- FILES AT THE ROOT, NO WRAPPER DIRECTORY -- MUST STILL INSTALL.
     *
     * ADDED BECAUSE A MUTATION SURVIVED, and that is the only reason this test exists. The
     * `.`-prefix and `__MACOSX` filter above already removes the sidecar cases, so deleting the
     * `! is_dir(...)` fallback broke nothing and the guard was real but unpinned -- exactly the
     * shape that cost this project a redundant check in the module door.
     *
     * This is the shape that needs it: when every top-level entry is a FILE, there is no directory
     * to descend into, so `$rootFolderName` must become null and the move must take the temp root
     * itself. Without the fallback `$source` is a FILE, `scandir()` on a file returns `false`, and
     * `array_diff(false, ...)` is a TypeError on PHP 8.3 -- an HTTP 500 that leaves
     * `themes/tmp_<uniqid>` behind. The sibling `ModuleController::install()` guards precisely this
     * and the theme installer did not.
     */
    #[Test]
    public function test_a_flat_archive_with_no_wrapper_directory_still_installs(): void
    {
        $archive = $this->archiveWithEntries([
            'layout.blade.php' => '<html></html>',
            'style.css' => 'body{color:green}',
        ]);

        $response = $this->install($archive, self::UPLOAD);

        $response->assertOk();
        $this->assertSame(
            'body{color:green}',
            @file_get_contents($this->themesRoot . '/' . self::UPLOAD . '/style.css'),
            'a flat archive, whose first entry is a file rather than a directory, did not install'
        );
    }
}
