<?php

namespace Tests\Feature\Marketplace;

use App\Models\Addon;
use App\Models\ModuleReviewRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;
use ZipArchive;

/**
 * MARKET-004 — THE INSTALL DOORS WRITE THE RECORD, AND AN UPLOADED THEME DOES NOT GO LIVE.
 *
 * `UploadedModuleDoesNotExecuteUntilReviewedTest` proves the LOADER refuses an unreviewed package.
 * That is worth nothing unless the install door actually writes a record, and nothing in the suite
 * drove a SUCCESSFUL install before this file: `AddonInstallTest` exercises the validator and the
 * refusals, `ThemeInstallDoesNotOverwriteAnotherThemeTest` drives the theme door but reads no
 * column off the row it asserts exists. So the two halves of the boundary were separately
 * assertable and the JOIN between them was not. This file is the join.
 *
 * THE THEME HALF IS A SEPARATE EXECUTION PATH and needs its own choke point. A theme is Blade —
 * `InstallAddonRequest::ALLOWED_ENTRY_EXTENSIONS` admits `php` and `blade.php` on purpose because a
 * theme IS code — and `App\Support\Theme\ThemeFieldCatalog::readableKeys()` does a literal
 * `include` of `themes/<theme>/default_data/settings.php`. The loader never sees a theme, so the
 * gate is where a theme becomes selectable: `Addon.status`, which `ThemeCustomizeController::index()`
 * and `PlanController` both read as `where('status','1')`.
 */
class UploadedPackageIsRecordedAndNotLiveTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const MODULE_UPLOAD = 'zz9probemod';
    private const THEME_UPLOAD = 'zz9probetheme';

    private array $themeBaseline = [];
    private array $packageBaseline = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->themeBaseline = array_diff(scandir(base_path('themes')) ?: [], ['.', '..']);
        $this->packageBaseline = array_diff(scandir(base_path('packages/workdo')) ?: [], ['.', '..']);
    }

    /**
     * Removes only what this test created. An EMPTY baseline means "I do not know what was here",
     * not "nothing was here", so it deletes nothing — the same guard, and the same reasoning, as
     * `ThemeInstallDoesNotOverwriteAnotherThemeTest::tearDown()`, where the blast radius would be
     * the three shipped themes and here it would be all 70 vendored modules.
     */
    protected function tearDown(): void
    {
        foreach ([[base_path('themes'), $this->themeBaseline], [base_path('packages/workdo'), $this->packageBaseline]] as [$root, $baseline]) {
            if ($baseline === []) {
                continue;
            }

            foreach (array_diff(scandir($root) ?: [], ['.', '..']) as $entry) {
                if (! in_array($entry, $baseline, true)) {
                    $this->rmrf($root . '/' . $entry);
                }
            }
        }

        parent::tearDown();
    }

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

    /** @param array<string,string> $entries */
    private function archive(array $entries): string
    {
        $file = tempnam(sys_get_temp_dir(), 'ss-market-') . '.zip';

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

    private function upload(string $route, string $archive, string $uploadName): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->makePlatformAdmin())->post($route, [
            'file' => new UploadedFile($archive, $uploadName . '.zip', 'application/zip', null, true),
        ]);
    }

    private function record(string $type, string $subject): ?ModuleReviewRecord
    {
        return ModuleReviewRecord::where('subject_type', $type)->where('subject', $subject)->first();
    }

    #[Test]
    public function test_installing_a_module_records_it_as_an_unreviewed_upload(): void
    {
        $archive = $this->archive([
            self::MODULE_UPLOAD . '/module.json' => json_encode([
                'name' => self::MODULE_UPLOAD,
                'alias' => 'Probe',
                'version' => 3.4,
                'parent_module' => ['ProductReview', 'Api'],
                'package_name' => 'probe',
            ]),
            self::MODULE_UPLOAD . '/src/database/seeders/PermissionTableSeeder.php' =>
                "<?php\n\$permissions = [\n 'Manage Probe',\n 'Delete Probe',\n];\n",
        ]);

        $response = $this->upload('/install-modules', $archive, self::MODULE_UPLOAD);

        $this->assertSame('success', $response->json('status'), 'premise: the install must have succeeded.');

        $record = $this->record(ModuleReviewRecord::TYPE_MODULE, self::MODULE_UPLOAD);

        $this->assertNotNull($record, 'The install door must write a review record, or the loader trusts the upload.');
        $this->assertSame(ModuleReviewRecord::PROVENANCE_UPLOADED, $record->provenance);
        $this->assertSame(ModuleReviewRecord::STATE_PENDING, $record->review_state);
    }

    /**
     * MARKET-002's review INPUTS, captured from the package by static read.
     *
     * `3.4` arrives in the manifest as a JSON number, which is why the column is a string label: all
     * 70 vendored manifests do the same and `module/index.blade.php` prints the value through
     * `sprintf('%.1f')`.
     */
    #[Test]
    public function test_the_record_carries_what_the_package_declares_about_itself(): void
    {
        $archive = $this->archive([
            self::MODULE_UPLOAD . '/module.json' => json_encode([
                'name' => self::MODULE_UPLOAD,
                'version' => 3.4,
                'parent_module' => ['ProductReview', 'Api'],
            ]),
            self::MODULE_UPLOAD . '/src/database/seeders/PermissionTableSeeder.php' =>
                "<?php\n\$permissions = [\n 'Manage Probe',\n 'Delete Probe',\n];\n",
        ]);

        $this->upload('/install-modules', $archive, self::MODULE_UPLOAD);

        $record = $this->record(ModuleReviewRecord::TYPE_MODULE, self::MODULE_UPLOAD);

        $this->assertNotNull($record, 'premise');
        $this->assertSame('3.4', $record->declared_version);
        $this->assertSame('ProductReview,Api', $record->declared_dependencies);
        $this->assertSame(
            ['Delete Probe', 'Manage Probe'],
            json_decode((string) $record->declared_permissions, true),
            'The permission names a package would create are read from the seeder SOURCE, never by running it.'
        );
    }

    /**
     * AN UPLOAD NAMED AFTER AN INSTALLED MODULE MUST NOT REPLACE IT. MARKET-002.
     *
     * `$fileName` comes from the uploaded FILENAME and the install recursively deleted whatever was
     * at `packages/workdo/<that name>`. So `LandingPage.zip` deleted the shipped LandingPage module
     * in one request. The theme door was already fixed for this exact shape
     * (`ThemeInstallDoesNotOverwriteAnotherThemeTest`); the module door was not.
     *
     * The victim here is a probe module this test installs first, not a vendored one -- a test that
     * aimed a real overwrite at `packages/workdo/LandingPage` would destroy vendored source if the
     * guard were ever reverted, which is the opposite of what a regression test should do.
     */
    #[Test]
    public function test_an_upload_cannot_replace_a_different_installed_module(): void
    {
        $victim = $this->archive([
            self::MODULE_UPLOAD . '/module.json' => json_encode(['name' => self::MODULE_UPLOAD, 'alias' => 'The victim']),
            self::MODULE_UPLOAD . '/marker.txt' => 'the original module',
        ]);

        $this->assertSame('success', $this->upload('/install-modules', $victim, self::MODULE_UPLOAD)->json('status'), 'premise');
        $this->assertSame(
            'the original module',
            file_get_contents(base_path('packages/workdo/' . self::MODULE_UPLOAD . '/marker.txt')),
            'premise: the victim is really installed.'
        );

        $attacker = $this->archive([
            'payload/module.json' => json_encode(['name' => 'SomethingElse', 'alias' => 'Not the victim']),
            'payload/marker.txt' => 'the replacement',
        ]);

        $response = $this->upload('/install-modules', $attacker, self::MODULE_UPLOAD);

        $this->assertSame('error', $response->json('status'));
        $this->assertSame(
            'the original module',
            file_get_contents(base_path('packages/workdo/' . self::MODULE_UPLOAD . '/marker.txt')),
            'The installed module must be untouched.'
        );
    }

    /** Updating a module WITH THAT MODULE is still allowed; the guard compares manifest names. */
    #[Test]
    public function test_an_upload_may_still_update_the_same_module(): void
    {
        $first = $this->archive([
            self::MODULE_UPLOAD . '/module.json' => json_encode(['name' => self::MODULE_UPLOAD, 'version' => 1.0]),
            self::MODULE_UPLOAD . '/marker.txt' => 'version one',
        ]);
        $this->assertSame('success', $this->upload('/install-modules', $first, self::MODULE_UPLOAD)->json('status'), 'premise');

        $second = $this->archive([
            self::MODULE_UPLOAD . '/module.json' => json_encode(['name' => self::MODULE_UPLOAD, 'version' => 2.0]),
            self::MODULE_UPLOAD . '/marker.txt' => 'version two',
        ]);

        $this->assertSame('success', $this->upload('/install-modules', $second, self::MODULE_UPLOAD)->json('status'));
        $this->assertSame(
            'version two',
            file_get_contents(base_path('packages/workdo/' . self::MODULE_UPLOAD . '/marker.txt')),
            'premise for the refusal above: a same-name update really does go through, so the refusal measures the NAME.'
        );
        $this->assertSame(
            '2',
            $this->record(ModuleReviewRecord::TYPE_MODULE, self::MODULE_UPLOAD)->declared_version,
            'and the re-upload reopened the review with the new declared version'
        );
    }

    /** A re-upload reopens the review, so one approval is not a standing permission for that name. */
    #[Test]
    public function test_a_re_upload_reopens_a_closed_review(): void
    {
        $archive = fn (float $v) => $this->archive([
            self::MODULE_UPLOAD . '/module.json' => json_encode(['name' => self::MODULE_UPLOAD, 'version' => $v]),
        ]);

        $this->upload('/install-modules', $archive(1.0), self::MODULE_UPLOAD);

        $record = $this->record(ModuleReviewRecord::TYPE_MODULE, self::MODULE_UPLOAD);
        $this->assertNotNull($record, 'premise');
        $record->update(['review_state' => ModuleReviewRecord::STATE_APPROVED, 'reviewed_by' => 1, 'reviewed_at' => now()]);

        $this->upload('/install-modules', $archive(2.0), self::MODULE_UPLOAD);

        $fresh = $this->record(ModuleReviewRecord::TYPE_MODULE, self::MODULE_UPLOAD);

        $this->assertSame(
            ModuleReviewRecord::STATE_PENDING,
            $fresh->review_state,
            'The approval described bytes that are no longer on disk; inheriting it would make one approval a standing permission to ship anything under that name.'
        );
        $this->assertNull($fresh->reviewed_by, 'the previous decision is cleared, not left attributing the new bytes to an old reviewer');
        $this->assertNull($fresh->reviewed_at);
    }

    /**
     * A NAME THE REGISTER CANNOT KEY IS REFUSED, BECAUSE NO RECORD MEANS TRUSTED.
     *
     * Found by reviewing this change rather than by a failing test, and it was a bypass of the whole
     * MARKET-004 boundary by nothing more than a long name: `add_on_managers.module` is VARCHAR(255)
     * and takes `$data['name']` straight from the ARCHIVE'S OWN MANIFEST, while
     * `module_review_records.subject` is VARCHAR(191). A 200-character name therefore installed
     * fine, failed the register's insert, and `ModuleTrust::recordUpload()` cannot undo an install
     * whose files are already written -- so the package would have loaded with nothing recorded
     * against it.
     *
     * The assertion that matters is the LAST one: no `add_on_managers` row, which is what the loader
     * plucks. Refusing before that row exists is what makes the refusal safe rather than merely
     * tidy.
     */
    #[Test]
    public function test_a_module_name_too_long_for_the_review_register_is_refused(): void
    {
        $longName = str_repeat('a', 200);

        $archive = $this->archive([
            self::MODULE_UPLOAD . '/module.json' => json_encode(['name' => $longName, 'alias' => 'Too long']),
        ]);

        $response = $this->upload('/install-modules', $archive, self::MODULE_UPLOAD);

        $this->assertSame('error', $response->json('status'));
        $this->assertNull($this->record(ModuleReviewRecord::TYPE_MODULE, $longName));
        $this->assertDatabaseMissing('add_on_managers', ['module' => $longName]);
        $this->assertDatabaseMissing('add_on_managers', ['module' => self::MODULE_UPLOAD]);
        $this->assertDirectoryDoesNotExist(
            base_path('packages/workdo/' . self::MODULE_UPLOAD),
            'The extracted files are removed, so nothing is left for somebody to wonder about.'
        );
    }

    /** The same refusal on the theme door; `addons.theme_id` is VARCHAR(255) for the same reason. */
    #[Test]
    public function test_a_theme_name_too_long_for_the_review_register_is_refused(): void
    {
        $longName = str_repeat('b', 200);

        $response = $this->upload(
            '/theme-addon',
            $this->archive([self::THEME_UPLOAD . '/index.blade.php' => 'hello']),
            $longName
        );

        $this->assertSame('error', $response->json('status'));
        $this->assertNull($this->record(ModuleReviewRecord::TYPE_THEME, $longName));
        $this->assertDatabaseMissing('addons', ['theme_id' => $longName]);
    }

    /** A name AT the limit still installs, so the refusal above measures the limit and not the door. */
    #[Test]
    public function test_a_module_name_at_the_register_limit_still_installs(): void
    {
        $atLimit = str_repeat('c', 191);

        $archive = $this->archive([
            self::MODULE_UPLOAD . '/module.json' => json_encode(['name' => $atLimit, 'alias' => 'At the limit']),
        ]);

        $this->assertSame('success', $this->upload('/install-modules', $archive, self::MODULE_UPLOAD)->json('status'));

        $record = $this->record(ModuleReviewRecord::TYPE_MODULE, $atLimit);

        $this->assertNotNull($record, 'premise for the refusal above.');
        $this->assertSame(ModuleReviewRecord::STATE_PENDING, $record->review_state);
    }

    #[Test]
    public function test_installing_a_theme_records_it_and_leaves_it_switched_off(): void
    {
        $archive = $this->archive([
            self::THEME_UPLOAD . '/index.blade.php' => 'hello',
        ]);

        $response = $this->upload('/theme-addon', $archive, self::THEME_UPLOAD);

        $this->assertSame('success', $response->json('status'), 'premise: the install must have succeeded.');

        $theme = Addon::where('theme_id', self::THEME_UPLOAD)->first();

        $this->assertNotNull($theme, 'premise: the row the product reads must exist.');
        $this->assertSame(
            '0',
            (string) $theme->status,
            'An uploaded theme used to be written with status 1, which made it immediately selectable by every merchant.'
        );

        $record = $this->record(ModuleReviewRecord::TYPE_THEME, self::THEME_UPLOAD);

        $this->assertNotNull($record);
        $this->assertSame(ModuleReviewRecord::STATE_PENDING, $record->review_state);
    }

    #[Test]
    public function test_an_unreviewed_theme_cannot_be_switched_on(): void
    {
        $this->upload('/theme-addon', $this->archive([self::THEME_UPLOAD . '/index.blade.php' => 'hello']), self::THEME_UPLOAD);

        $this->actingAs($this->makePlatformAdmin())
            ->post('/theme-enable', ['name' => self::THEME_UPLOAD]);

        $this->assertSame(
            '0',
            (string) Addon::where('theme_id', self::THEME_UPLOAD)->value('status'),
            'An uploaded theme with no approved review decision must not become selectable.'
        );
    }

    #[Test]
    public function test_an_approved_theme_can_be_switched_on(): void
    {
        $this->upload('/theme-addon', $this->archive([self::THEME_UPLOAD . '/index.blade.php' => 'hello']), self::THEME_UPLOAD);

        $record = $this->record(ModuleReviewRecord::TYPE_THEME, self::THEME_UPLOAD);
        $this->assertNotNull($record, 'premise');
        $record->update(['review_state' => ModuleReviewRecord::STATE_APPROVED]);

        $this->actingAs($this->makePlatformAdmin())
            ->post('/theme-enable', ['name' => self::THEME_UPLOAD]);

        $this->assertSame(
            '1',
            (string) Addon::where('theme_id', self::THEME_UPLOAD)->value('status'),
            'premise for the refusal above: the enable path works once a decision exists, so the refusal measures the decision.'
        );
    }

    /**
     * A SHIPPED THEME IS NOT AFFECTED. It has no review record, so the limb never fires — the same
     * "an absent row is not an unreviewed package" rule the module loader follows. Without this
     * assertion, `return false` from `mayExecute()` would pass every refusal in this file and make
     * the three shipped themes unselectable.
     */
    #[Test]
    public function test_a_shipped_theme_can_still_be_switched_on(): void
    {
        Addon::create(['theme_id' => 'stylique', 'status' => '0']);

        $this->actingAs($this->makePlatformAdmin())
            ->post('/theme-enable', ['name' => 'stylique']);

        $this->assertSame('1', (string) Addon::where('theme_id', 'stylique')->value('status'));
    }

    /**
     * MARKET-005. SWITCHING A THEME OFF IS NEVER REFUSED, whatever its review state. A guard that
     * could block a disable would be a guard that traps a bad theme in production, and the decision
     * text is explicit that suspension must always be available.
     */
    #[Test]
    public function test_an_unreviewed_theme_can_still_be_switched_off(): void
    {
        $this->upload('/theme-addon', $this->archive([self::THEME_UPLOAD . '/index.blade.php' => 'hello']), self::THEME_UPLOAD);

        Addon::where('theme_id', self::THEME_UPLOAD)->update(['status' => '1']);
        (new Addon)->flushCache();

        $this->actingAs($this->makePlatformAdmin())
            ->post('/theme-enable', ['name' => self::THEME_UPLOAD]);

        $this->assertSame(
            '0',
            (string) Addon::where('theme_id', self::THEME_UPLOAD)->value('status'),
            'Withdrawing a theme must work even while its review is pending.'
        );
    }
}
