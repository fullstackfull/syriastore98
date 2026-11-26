<?php

namespace Tests\Unit\Modules;

use App\Support\Modules\PackageDeclaration;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MARKET-002 — the review inputs, read from a package WITHOUT RUNNING IT.
 *
 * Every reader here is a static text read, which is the whole constraint: the facts a reviewer needs
 * are inside code that MARKET-004 forbids executing. `ModuleController::enable()` obtains the same
 * permission names by `Artisan::call('package:seed <Module>')`, which runs the package — that is the
 * thing this exists to avoid.
 *
 * TWO CASES READ A REAL VENDORED MODULE, so the recogniser is pinned against the shape all 70
 * actually use rather than against a fixture written to match it. The rest use fixtures, because the
 * interesting cases (a string version, a missing version, no seeder at all) do not occur among the
 * 70 and a test that could not reach them would be asserting nothing.
 */
class PackageDeclarationTest extends TestCase
{
    private string $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = sys_get_temp_dir() . '/p6-market-decl-' . uniqid();
        mkdir($this->fixtures, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->fixtures);

        parent::tearDown();
    }

    private function rmrf(string $path): void
    {
        if (is_file($path) || is_link($path)) {
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

    private function package(string $name, ?array $manifest, ?string $seeder = null): string
    {
        $dir = $this->fixtures . '/' . $name;
        mkdir($dir . '/src/database/seeders', 0777, true);

        if ($manifest !== null) {
            file_put_contents($dir . '/module.json', json_encode($manifest));
        }

        if ($seeder !== null) {
            file_put_contents($dir . '/src/database/seeders/PermissionTableSeeder.php', $seeder);
        } else {
            $this->rmrf($dir . '/src/database/seeders');
        }

        return $dir;
    }

    /**
     * A NUMBER COMES BACK AS A LABEL. All 70 vendored manifests write `version` as a JSON NUMBER
     * (`"version":1.6`), so this is the real case, and the column is a string because
     * `module/index.blade.php` prints the value through `sprintf('%.1f')` -- a semantic version
     * would be rounded to nonsense by that view, and keeping the label verbatim costs nothing.
     */
    #[Test]
    public function test_a_numeric_version_is_read_as_its_label(): void
    {
        $dir = $this->package('Numeric', ['name' => 'Numeric', 'version' => 1.6]);

        $this->assertSame('1.6', PackageDeclaration::declaredVersion($dir));
    }

    #[Test]
    public function test_a_string_version_survives_verbatim(): void
    {
        $dir = $this->package('Semver', ['name' => 'Semver', 'version' => '2.0.1-beta']);

        $this->assertSame('2.0.1-beta', PackageDeclaration::declaredVersion($dir));
    }

    /**
     * NULL AND '1.0' ARE DIFFERENT ANSWERS. `App\Classes\Module::find()` substitutes `1.0` for a
     * missing version, which makes an undeclared version indistinguishable from a declared 1.0 on
     * the module screen. A reviewer must be able to tell them apart, so nothing is substituted here.
     */
    #[Test]
    public function test_a_missing_version_is_null_and_not_a_default(): void
    {
        $dir = $this->package('NoVersion', ['name' => 'NoVersion']);

        $this->assertNull(PackageDeclaration::declaredVersion($dir));
    }

    #[Test]
    public function test_a_package_with_no_manifest_declares_nothing(): void
    {
        $dir = $this->package('Bare', null);

        $this->assertSame([], PackageDeclaration::manifest($dir));
        $this->assertNull(PackageDeclaration::declaredVersion($dir));
        $this->assertSame([], PackageDeclaration::declaredDependencies($dir));
    }

    #[Test]
    public function test_dependencies_come_from_the_parent_module_list_and_are_deduplicated(): void
    {
        $dir = $this->package('Dep', [
            'name' => 'Dep',
            'parent_module' => ['Api', ' ProductReview ', 'Api', '', 123],
        ]);

        $this->assertSame(
            ['Api', 'ProductReview'],
            PackageDeclaration::declaredDependencies($dir),
            'Whitespace is trimmed, duplicates collapse, and a non-string entry is dropped rather than coerced.'
        );
    }

    #[Test]
    public function test_permission_names_are_read_out_of_the_seeder_source(): void
    {
        $dir = $this->package('Perms', ['name' => 'Perms'], <<<'SEEDER'
<?php
class PermissionTableSeeder
{
    public function run()
    {
        $module = 'Perms';
        $permissions  = [
            'Manage Perms',
            'Create Perms',
            'Delete Perms',
        ];
    }
}
SEEDER);

        $this->assertSame(
            ['Create Perms', 'Delete Perms', 'Manage Perms'],
            PackageDeclaration::declaredPermissions($dir),
            'Sorted, so a reviewer reads the same order every time.'
        );
    }

    /**
     * NULL MEANS "NOTHING COULD BE READ"; `[]` MEANS "A SEEDER WAS READ AND DECLARED NOTHING".
     * A reviewer must be able to tell those apart -- the first is a gap in the measurement, the
     * second is a fact about the package.
     */
    #[Test]
    public function test_no_seeder_at_all_is_null_rather_than_an_empty_list(): void
    {
        $dir = $this->package('NoSeeder', ['name' => 'NoSeeder']);

        $this->assertNull(PackageDeclaration::declaredPermissions($dir));
    }

    #[Test]
    public function test_a_seeder_that_declares_no_permissions_is_an_empty_list(): void
    {
        $dir = $this->package('Empty', ['name' => 'Empty'], "<?php\nclass PermissionTableSeeder { public function run() {} }\n");

        $this->assertSame([], PackageDeclaration::declaredPermissions($dir));
    }

    /**
     * THE RECOGNISER AGAINST A REAL VENDORED MODULE, so it is pinned to the shape that actually
     * ships rather than to a fixture written to match it.
     */
    #[Test]
    public function test_a_real_vendored_module_is_read_correctly(): void
    {
        $dir = base_path('packages/workdo/ProductReview');

        $this->assertDirectoryExists($dir, 'premise');
        $this->assertSame('1.6', PackageDeclaration::declaredVersion($dir));
        $this->assertSame([], PackageDeclaration::declaredDependencies($dir));
        $this->assertSame(
            ['Create Product Review', 'Delete Product Review', 'Edit Product Review', 'Manage Product Review'],
            PackageDeclaration::declaredPermissions($dir)
        );
    }

    /**
     * NO VENDORED MODULE DECLARES A DEPENDENCY, which is a MARKET-002 finding and not an aside.
     *
     * MEASURED over all 70 manifests: **zero** carry a non-empty `parent_module`. So
     * `ModuleController::Check_Parent_Module()` -- the ONLY compatibility check the platform has,
     * and the one that refuses to enable a module whose prerequisites are inactive -- never fires
     * for any module that ships. The dependency half of MARKET-002's "compatibility" is therefore
     * structurally present and untested by the product itself, which is why
     * `PackageDeclaration::declaredDependencies()` records the list at upload time: a third-party
     * package is the first one that could ever declare one.
     *
     * THIS IS A RATCHET, NOT A PROHIBITION. If an upstream module update introduces a
     * `parent_module`, this fails -- correctly, because the enable-time check becomes live for the
     * first time and somebody should look at it before it ships.
     */
    #[Test]
    public function test_no_vendored_module_declares_a_dependency_today(): void
    {
        $manifests = glob(base_path('packages/workdo/*/module.json')) ?: [];

        $this->assertGreaterThan(60, count($manifests), 'premise: the vendored catalogue is really being read.');

        $declaring = [];

        foreach ($manifests as $manifest) {
            if (PackageDeclaration::declaredDependencies(dirname($manifest)) !== []) {
                $declaring[] = basename(dirname($manifest));
            }
        }

        $this->assertSame(
            [],
            $declaring,
            'A vendored module now declares parent_module, so ModuleController::Check_Parent_Module() '
            . 'is live for the first time. Read it before this ships: it recurses, and its failure '
            . 'branch refuses the enable with the parent list in the message.'
        );
    }
}
