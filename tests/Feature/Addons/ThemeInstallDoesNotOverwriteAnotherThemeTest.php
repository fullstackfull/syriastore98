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
}
