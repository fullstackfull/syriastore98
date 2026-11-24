<?php

namespace Tests\Unit\Modules;

use App\Support\Modules\ModuleNotRemovable;
use App\Support\Modules\RemovableModule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE REMOVAL CONTRACT, PROVED AGAINST DISPOSABLE DIRECTORIES.
 *
 * `ModuleRemovalRefusesWhatItCannotRemoveTest` drives the route for the two refusals that are safe
 * to drive, and deliberately does NOT drive the traversal or the accept path, because against the
 * unfixed handler the first deletes `packages/` (all 70 vendored modules) and the second deletes a
 * real module. Those cases live here instead, where every filesystem target is a throwaway
 * directory this test creates and removes itself.
 *
 * The confinement method takes its root as a parameter for exactly that reason: it is the only way
 * to exercise a recursive-delete guard, including a symlink escaping the root, without pointing one
 * at the real tree.
 */
class RemovableModuleTest extends TestCase
{
    private string $root;

    /**
     * A disposable directory OUTSIDE the root, for the two escape tests.
     *
     * HELD AS A PROPERTY BECAUSE DERIVING IT TWICE LEAKED. Found by independent review: tearDown
     * used to recompute it as `$this->root . '/../ss-outside-' . basename($this->root)`, and by
     * then `$this->root` had already been removed -- so a path whose parent component no longer
     * exists failed `is_dir()` and the directory, `evidence.txt` and all, survived every run.
     */
    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->root = sys_get_temp_dir() . '/ss-removable-' . $suffix;
        $this->outside = sys_get_temp_dir() . '/ss-outside-' . $suffix;

        mkdir($this->root . '/RealModule', 0o777, true);
        mkdir($this->outside, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->root, $this->outside] as $path) {
            if (is_dir($path)) {
                $this->rmrf($path);
            }
        }

        parent::tearDown();
    }

    /** Disposable-fixture teardown only. Never pointed at the repository. */
    private function rmrf(string $path): void
    {
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            is_dir($child) && ! is_link($child) ? $this->rmrf($child) : unlink($child);
        }

        rmdir($path);
    }

    /** @return list<array{0: string, 1: string}> */
    public static function pathAlteringIdentifiers(): array
    {
        return [
            'parent'              => ['..', 'characters that could alter a path'],
            'grandparent'         => ['../..', 'characters that could alter a path'],
            'percent encoded'     => ['%2e%2e', 'characters that could alter a path'],
            'forward slash'       => ['Api/Other', 'characters that could alter a path'],
            'back slash'          => ['Api\\Other', 'characters that could alter a path'],
            'trailing dot segment' => ['Api/..', 'characters that could alter a path'],
            'null byte'           => ["Api\0", 'characters that could alter a path'],
            'leading slash'       => ['/etc', 'characters that could alter a path'],
        ];
    }

    #[Test]
    #[DataProvider('pathAlteringIdentifiers')]
    public function test_an_identifier_that_could_alter_a_path_is_refused(string $candidate, string $expected): void
    {
        $this->expectException(ModuleNotRemovable::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expected, '/') . '/');

        RemovableModule::verifiedName($candidate);
    }

    /**
     * SANITISED-AND-CONTINUED WOULD BE WORSE THAN REFUSED, which is why the check compares.
     *
     * `safeDirectoryName('Api/../Other')` returns `ApiOther`. Had the resolver acted on that, a
     * caller naming one path would have destroyed a differently-named module. The contract is that
     * only an identifier surviving sanitising UNCHANGED is accepted.
     */
    #[Test]
    public function test_a_sanitisable_identifier_is_refused_rather_than_rewritten(): void
    {
        try {
            RemovableModule::verifiedName('Api/../Other');
            $this->fail('expected a refusal');
        } catch (ModuleNotRemovable $e) {
            $this->assertStringContainsString('alter a path', $e->getMessage());
            $this->assertStringNotContainsString('ApiOther', $e->getMessage(),
                'the refusal must not report a rewritten name as though it were the request');
        }
    }

    /** @return list<array{0: string}> */
    public static function protectedSpellings(): array
    {
        return [['Base'], ['base'], ['BASE'], ['bAsE']];
    }

    #[Test]
    #[DataProvider('protectedSpellings')]
    public function test_the_core_permission_set_is_protected_in_every_spelling(string $candidate): void
    {
        $this->expectException(ModuleNotRemovable::class);
        $this->expectExceptionMessageMatches('/protected/');

        RemovableModule::verifiedName($candidate);
    }

    #[Test]
    public function test_an_empty_or_non_string_identifier_is_refused(): void
    {
        try {
            RemovableModule::verifiedName('');
            $this->fail('expected a refusal for the empty string');
        } catch (ModuleNotRemovable $e) {
            $this->assertStringContainsString('empty', $e->getMessage());
        }

        /*
         * Whitespace-only, and whitespace-padded, are refused by the path check rather than the
         * empty check -- because the raw string is compared, so nothing is trimmed into validity.
         */
        foreach (['   ', ' Api ', "Api\n"] as $padded) {
            try {
                RemovableModule::verifiedName($padded);
                $this->fail('expected a refusal for ' . var_export($padded, true));
            } catch (ModuleNotRemovable $e) {
                $this->assertStringContainsString('alter a path', $e->getMessage());
            }
        }

        foreach ([null, 42, ['Api'], new \stdClass()] as $notAString) {
            try {
                RemovableModule::verifiedName($notAString);
                $this->fail('expected a refusal for a non-string');
            } catch (ModuleNotRemovable $e) {
                $this->assertStringContainsString('not a string', $e->getMessage());
            }
        }
    }

    #[Test]
    public function test_an_identifier_naming_no_installed_module_is_refused(): void
    {
        $this->expectException(ModuleNotRemovable::class);
        $this->expectExceptionMessageMatches('/no installed module/');

        RemovableModule::verifiedName('NotAModuleAtAll');
    }

    /** THE CONTROL: a really installed module must pass every identity check. */
    #[Test]
    public function test_a_really_installed_module_passes(): void
    {
        $this->assertDirectoryExists(base_path('packages/workdo/Api'), 'premise');

        $this->assertSame('Api', RemovableModule::verifiedName('Api'),
            'the default root must still resolve the real tree');
    }

    /**
     * A DIRECTORY WITH NO MANIFEST IS NOT AN INSTALLED MODULE.
     *
     * THIS TEST EXISTS BECAUSE A MUTATION PROVED IT WAS MISSING. Reverting the existence check to
     * `Module::find()` truthiness -- the original defect -- SURVIVED both route tests, because
     * `Base` is stopped by the protected list and an unknown name is stopped by the confinement
     * check. The existence check was real and nothing pinned it. This is the case only it catches:
     * path-safe, not protected, directory present, no `module.json`.
     */
    #[Test]
    public function test_a_directory_without_a_manifest_is_not_an_installed_module(): void
    {
        mkdir($this->root . '/HalfExtracted', 0o777, true);

        try {
            RemovableModule::verifiedName('HalfExtracted', $this->root);
            $this->fail('a directory with no module.json was accepted as an installed module');
        } catch (ModuleNotRemovable $e) {
            $this->assertStringContainsString('no installed module', $e->getMessage());
        }
    }

    /** The same name, with a manifest, is accepted -- so the check tests the manifest, not the name. */
    #[Test]
    public function test_the_same_directory_with_a_manifest_is_accepted(): void
    {
        mkdir($this->root . '/HalfExtracted', 0o777, true);
        file_put_contents($this->root . '/HalfExtracted/module.json', '{"name":"HalfExtracted"}');

        $this->assertSame('HalfExtracted', RemovableModule::verifiedName('HalfExtracted', $this->root));
    }

    #[Test]
    public function test_confinement_returns_the_canonical_directory_inside_the_root(): void
    {
        $this->assertSame(
            realpath($this->root . '/RealModule'),
            RemovableModule::confineWithin($this->root, 'RealModule')
        );
    }

    /**
     * A SYMLINK POINTING OUTSIDE THE ROOT MUST BE REFUSED, NOT FOLLOWED.
     *
     * This is the "unsafe symlink resolution" half, and it is the reason the check uses `realpath()`
     * rather than string arithmetic on the path: `realpath()` resolves the link and the result then
     * fails the prefix test. A `str_starts_with` on the UNRESOLVED path would have passed, and the
     * recursive delete would have walked into someone else's directory.
     */
    #[Test]
    public function test_a_module_directory_symlinked_outside_the_root_is_refused(): void
    {
        $outside = realpath($this->outside);
        $this->assertNotFalse($outside, 'premise: the disposable outside directory exists');

        file_put_contents($outside . '/evidence.txt', 'must survive');
        symlink($outside, $this->root . '/Escapee');

        try {
            RemovableModule::confineWithin($this->root, 'Escapee');
            $this->fail('a symlink escaping the root was accepted');
        } catch (ModuleNotRemovable $e) {
            $this->assertStringContainsString('outside the modules root', $e->getMessage());
        }

        $this->assertFileExists($outside . '/evidence.txt',
            'the guard must refuse before anything touches the linked directory');
    }

    #[Test]
    public function test_a_traversal_that_resolves_to_the_root_itself_is_refused(): void
    {
        mkdir($this->root . '/Nested/Inner', 0o777, true);

        // '..' from Nested/Inner resolves back to Nested; '../..' to the root itself.
        $this->expectException(ModuleNotRemovable::class);
        $this->expectExceptionMessageMatches('/the modules root itself/');

        RemovableModule::confineWithin($this->root, 'Nested/Inner/../..');
    }

    #[Test]
    public function test_a_directory_that_does_not_exist_is_refused(): void
    {
        $this->expectException(ModuleNotRemovable::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        RemovableModule::confineWithin($this->root, 'NeverCreated');
    }

    #[Test]
    public function test_a_file_where_a_module_directory_should_be_is_refused(): void
    {
        file_put_contents($this->root . '/NotADir', 'x');

        $this->expectException(ModuleNotRemovable::class);
        $this->expectExceptionMessageMatches('/not a directory/');

        RemovableModule::confineWithin($this->root, 'NotADir');
    }

    #[Test]
    public function test_a_missing_root_is_refused_rather_than_treated_as_empty(): void
    {
        $this->expectException(ModuleNotRemovable::class);
        $this->expectExceptionMessageMatches('/modules root does not exist/');

        RemovableModule::confineWithin($this->root . '/no-such-root', 'RealModule');
    }

    /**
     * A SYMLINK **INSIDE** AN ACCEPTED MODULE MUST BE UNLINKED, NOT FOLLOWED.
     *
     * THE ONE HOLE THE FIRST VERSION OF THIS SLICE LEFT OPEN, found by independent review and not
     * by me. `confineWithin()` canonicalises the module directory and proves it sits inside the
     * root -- but it says nothing about what is nested below, and the recursive delete decided with
     * `is_dir($path)`, which FOLLOWS links. So a link inside an otherwise perfectly legitimate
     * module had every file under its target unlinked THROUGH it.
     *
     * It is latent rather than live on this checkout -- `find packages/workdo -type l` returns zero
     * across all 70 modules, nothing first-party calls `symlink()`, and neither `extractTo()` nor
     * `rename()` can plant one -- so this test is the thing that keeps it closed if a future
     * vendored tree ships one.
     *
     * DRIVEN THROUGH THE REAL PRIVATE METHOD, against directories this test creates and destroys
     * itself. Reflection is used deliberately: `deleteDirectory()` is where the guard has to live
     * (it also covers the three install-path callers), and the alternative -- driving the route --
     * would delete a real vendored module. Nothing here names a path under `packages/`.
     */
    #[Test]
    public function test_a_symlink_nested_inside_a_module_is_unlinked_rather_than_followed(): void
    {
        $outside = realpath($this->outside);
        $this->assertNotFalse($outside, 'premise');
        file_put_contents($outside . '/evidence.txt', 'must survive');
        mkdir($outside . '/nested', 0o777, true);
        file_put_contents($outside . '/nested/deep.txt', 'must survive too');

        // A complete, disposable module: manifest, a real subdirectory, a real file, and the link.
        $module = $this->root . '/Doomed';
        mkdir($module . '/src', 0o777, true);
        file_put_contents($module . '/module.json', '{"name":"Doomed"}');
        file_put_contents($module . '/src/real.php', '<?php // removed with the module');
        symlink($outside, $module . '/src/escape');

        $this->assertTrue(is_link($module . '/src/escape'), 'premise: the link was created');
        $this->assertDirectoryExists($module . '/src/escape', 'premise: it resolves to a directory');

        $controller = (new \ReflectionClass(\App\Http\Controllers\ModuleController::class))
            ->newInstanceWithoutConstructor();
        $deleteDirectory = new \ReflectionMethod(
            \App\Http\Controllers\ModuleController::class,
            'deleteDirectory'
        );
        $deleteDirectory->setAccessible(true);

        $this->assertTrue(
            $deleteDirectory->invoke($controller, $module),
            'the module directory itself must still be removed -- the guard must not break removal'
        );

        $this->assertDirectoryDoesNotExist($module, 'the disposable module must really be gone');

        $this->assertFileExists(
            $outside . '/evidence.txt',
            'The recursion followed a symlink out of the module and deleted the target\'s contents. '
            . 'is_dir() follows links, so a link nested inside an accepted module escaped the '
            . 'confinement that confineWithin() had proved for the module directory.'
        );
        $this->assertFileExists($outside . '/nested/deep.txt',
            'the escape recursed further than one level');
        $this->assertDirectoryExists($outside, 'the linked directory itself must survive');
    }

    /**
     * And the TOCTOU at the top: the module directory is swapped for a link AFTER confineWithin()
     * has proved it real. The delete must unlink it, not walk into it.
     */
    #[Test]
    public function test_a_module_directory_that_became_a_symlink_is_unlinked_not_walked(): void
    {
        $outside = realpath($this->outside);
        file_put_contents($outside . '/evidence.txt', 'must survive');

        $swapped = $this->root . '/Swapped';
        symlink($outside, $swapped);

        $controller = (new \ReflectionClass(\App\Http\Controllers\ModuleController::class))
            ->newInstanceWithoutConstructor();
        $deleteDirectory = new \ReflectionMethod(
            \App\Http\Controllers\ModuleController::class,
            'deleteDirectory'
        );
        $deleteDirectory->setAccessible(true);

        $deleteDirectory->invoke($controller, $swapped);

        $this->assertFileExists($outside . '/evidence.txt',
            'A module directory replaced by a symlink after validation was walked into.');
        $this->assertFalse(is_link($swapped), 'the link itself should be gone');
    }

    /**
     * `resolve()` must compose the two halves, so a fix to one cannot be bypassed via the other.
     */
    #[Test]
    public function test_resolve_composes_identity_and_confinement(): void
    {
        $module = RemovableModule::resolve('Api');

        $this->assertSame('Api', $module->name);
        $this->assertSame(realpath(base_path('packages/workdo/Api')), $module->directory);
        $this->assertSame(realpath(base_path('packages/workdo')), realpath(RemovableModule::root()));

        $this->expectException(ModuleNotRemovable::class);
        RemovableModule::resolve('Base');
    }
}
