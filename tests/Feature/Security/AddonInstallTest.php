<?php

namespace Tests\Feature\Security;

use App\Http\Requests\InstallAddonRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * SEC-F5 -- authenticated zip-upload remote code execution.
 *
 * ThemeInstall and ModuleController::install unpacked an attacker-supplied
 * archive straight into the running application with NO authorization, while
 * the methods that merely LIST themes were already 'super admin'-only. Public
 * self-registration grants type=admin, so the path was
 * anonymous-visitor-to-RCE in two HTTP requests.
 */
class AddonInstallTest extends SecurityTestCase
{
    use RefreshDatabase;

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir() . '/addon-install-test-' . uniqid();
        @mkdir($this->workDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workDir)) {
            File::deleteDirectory($this->workDir);
        }

        parent::tearDown();
    }


    /**
     * App\Http\Middleware\XSS (:27-49) redirects ANY 'super admin' to the
     * LaravelUpdater welcome page while migrations are pending, which is always
     * the case on a from-zero test schema. It is a migration-nag, not an
     * authorization control, so it is disabled for the platform-admin cases
     * that need to reach validation. `auth` and the Gate stay on.
     */
    private function actingAsPlatformAdmin($admin): self
    {
        return $this->withoutMiddleware(\App\Http\Middleware\XSS::class)
            ->actingAs($admin);
    }

    private function makeZip(string $name, array $entries): UploadedFile
    {
        $path = $this->workDir . '/' . $name;

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();

        return new UploadedFile($path, $name, 'application/zip', null, true);
    }

    // ---------------------------------------------------------------
    // Authorization
    // ---------------------------------------------------------------

    public function test_merchant_cannot_install_a_module(): void
    {
        [$merchant] = $this->makeTenant();

        $zip = $this->makeZip('evil.zip', ['evil/module.json' => '{}']);

        $this->actingAs($merchant)
            ->post(route('module.install'), ['file' => $zip])
            ->assertForbidden();

        $this->assertFalse(
            File::exists(base_path('packages/workdo/evil')),
            'No directory may appear under packages/workdo for a denied install.'
        );
    }

    public function test_merchant_cannot_install_a_theme(): void
    {
        [$merchant] = $this->makeTenant();

        $zip = $this->makeZip('eviltheme.zip', ['eviltheme/theme.json' => '{}']);

        $this->actingAs($merchant)
            ->post(route('addon.theme'), ['file' => $zip])
            ->assertForbidden();

        $this->assertFalse(File::exists(base_path('themes/eviltheme')));
    }

    public function test_anonymous_cannot_install_a_module(): void
    {
        $zip = $this->makeZip('evil.zip', ['evil/module.json' => '{}']);

        $this->post(route('module.install'), ['file' => $zip]);

        $this->assertFalse(File::exists(base_path('packages/workdo/evil')));
    }

    public function test_merchant_cannot_enable_a_theme(): void
    {
        [$merchant] = $this->makeTenant();

        $this->actingAs($merchant)
            ->post(route('theme.enable'), ['name' => 'stylique'])
            ->assertForbidden();
    }

    // ---------------------------------------------------------------
    // Archive validation -- unit-level, because these are the RCE barrier
    // ---------------------------------------------------------------

    public function test_traversal_entry_is_rejected(): void
    {
        $path = $this->workDir . '/traversal.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('../../public/shell.php', '<?php system($_GET["c"]);');
        $zip->close();

        $this->assertNotNull(
            InstallAddonRequest::archiveRejectionReason($path),
            'An archive containing a traversal entry must be rejected.'
        );
    }

    public function test_nested_traversal_entry_is_rejected(): void
    {
        $path = $this->workDir . '/nested.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('ok/../../shell.php', '<?php');
        $zip->close();

        $this->assertNotNull(InstallAddonRequest::archiveRejectionReason($path));
    }

    public function test_dotfile_entry_is_rejected(): void
    {
        $path = $this->workDir . '/dotfile.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('mod/.env', 'APP_KEY=stolen');
        $zip->close();

        $this->assertNotNull(InstallAddonRequest::archiveRejectionReason($path));
    }

    public function test_shell_script_entry_is_rejected(): void
    {
        $path = $this->workDir . '/sh.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('mod/run.sh', '#!/bin/sh');
        $zip->close();

        $this->assertNotNull(InstallAddonRequest::archiveRejectionReason($path));
    }

    /**
     * THE POSITIVE CASE for the validator: an ordinary module archive must
     * still be accepted, or no one can install anything.
     */
    public function test_a_valid_module_archive_is_accepted(): void
    {
        $path = $this->workDir . '/good.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('MyMod/module.json', '{"name":"MyMod"}');
        $zip->addFromString('MyMod/src/Provider.php', '<?php');
        $zip->addFromString('MyMod/src/resources/views/index.blade.php', 'hello');
        $zip->addFromString('MyMod/src/resources/assets/app.css', 'body{}');
        $zip->close();

        $this->assertNull(
            InstallAddonRequest::archiveRejectionReason($path),
            'A legitimate module archive must not be rejected.'
        );
    }

    /**
     * ModuleController took getClientOriginalName() and did
     * str_replace('.zip','',...) -- not a suffix match -- then interpolated it
     * into a path with no basename().
     */
    public function test_traversal_filename_cannot_escape_the_install_directory(): void
    {
        $this->assertSame('x', InstallAddonRequest::safeDirectoryName('../../public/x.zip'));
        $this->assertSame('passwd', InstallAddonRequest::safeDirectoryName('/etc/passwd'));

        foreach (['../../public/x.zip', '/etc/passwd', 'a/b/c.zip', '..', '.'] as $candidate) {
            $safe = InstallAddonRequest::safeDirectoryName($candidate);

            $this->assertStringNotContainsString('/', $safe);
            $this->assertStringNotContainsString('\\', $safe);
            $this->assertStringNotContainsString('.', $safe);
        }
    }

    public function test_non_zip_upload_is_rejected(): void
    {
        $platformAdmin = $this->makePlatformAdmin();

        $notAZip = UploadedFile::fake()->create('payload.php', 10, 'application/x-php');

        $this->actingAsPlatformAdmin($platformAdmin)
            ->post(route('module.install'), ['file' => $notAZip])
            ->assertStatus(302)
            ->assertSessionHasErrors('file');
    }

    public function test_missing_upload_is_rejected(): void
    {
        $platformAdmin = $this->makePlatformAdmin();

        $this->actingAsPlatformAdmin($platformAdmin)
            ->post(route('module.install'), [])
            ->assertSessionHasErrors('file');
    }
}
