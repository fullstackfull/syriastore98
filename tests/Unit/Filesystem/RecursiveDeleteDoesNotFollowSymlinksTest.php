<?php

namespace Tests\Unit\Filesystem;

use App\Models\Utility;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `Utility::delete_directory()` MUST UNLINK A SYMLINK, NEVER RECURSE THROUGH IT.
 *
 * The second of the two recursive-delete helpers in first-party code (the other is
 * `ModuleController::deleteDirectory()`, pinned in `tests/Unit/Modules/RemovableModuleTest.php`).
 * Both decided with `is_dir($path)`, which FOLLOWS links -- so a symlink to a directory anywhere
 * under the tree being removed had every file beneath its TARGET unlinked through it, and the link
 * itself survived, because `rmdir` on a symlink fails ENOTDIR.
 *
 * THIS ONE MATTERS MORE THAN THE MODULE ONE because its only caller that takes a caller-named path
 * is the language-delete door (`LanguageController::destroyLang`), which P06-MOD-2 confines -- and
 * confinement proves the TOP of the tree is inside the language root while saying nothing about
 * what is nested below it. The two halves are separate and both are needed.
 *
 * `is_link` IS CHECKED BEFORE `file_exists`, AND THAT ORDERING IS ITS OWN ASSERTION BELOW:
 * `file_exists()` follows the link too, so for a BROKEN link it answers false and the old code
 * returned true while leaving the dangling link in place.
 *
 * Every path here is a throwaway under the system temp directory. Nothing in this file names a path
 * inside the repository.
 */
class RecursiveDeleteDoesNotFollowSymlinksTest extends TestCase
{
    private string $root;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->root = sys_get_temp_dir() . '/ss-rdel-' . $suffix;
        $this->outside = sys_get_temp_dir() . '/ss-rdel-out-' . $suffix;

        mkdir($this->root, 0o777, true);
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

    #[Test]
    public function test_a_nested_symlink_is_unlinked_rather_than_followed(): void
    {
        $outside = realpath($this->outside);
        file_put_contents($outside . '/evidence.txt', 'must survive');
        mkdir($outside . '/nested', 0o777, true);
        file_put_contents($outside . '/nested/deep.txt', 'must survive too');

        $doomed = $this->root . '/doomed';
        mkdir($doomed . '/inner', 0o777, true);
        file_put_contents($doomed . '/inner/real.php', '<?php // goes with the tree');
        symlink($outside, $doomed . '/inner/escape');

        $this->assertTrue(is_link($doomed . '/inner/escape'), 'premise: the link exists');
        $this->assertDirectoryExists($doomed . '/inner/escape', 'premise: it resolves to a directory');

        $this->assertTrue(
            Utility::delete_directory($doomed),
            'the guard must not break ordinary removal -- the tree itself still has to go'
        );

        $this->assertDirectoryDoesNotExist($doomed, 'the disposable tree must really be gone');

        $this->assertFileExists(
            $outside . '/evidence.txt',
            'The recursion followed a symlink out of the tree and deleted the target\'s contents. '
            . 'is_dir() follows links, so a nested link escapes whatever confinement was proved for '
            . 'the top of the tree.'
        );
        $this->assertFileExists($outside . '/nested/deep.txt', 'the escape recursed further than one level');
        $this->assertDirectoryExists($outside, 'the linked directory itself must survive');
    }

    #[Test]
    public function test_a_top_level_symlink_is_unlinked_rather_than_walked(): void
    {
        $outside = realpath($this->outside);
        file_put_contents($outside . '/evidence.txt', 'must survive');

        $swapped = $this->root . '/swapped';
        symlink($outside, $swapped);

        Utility::delete_directory($swapped);

        $this->assertFileExists($outside . '/evidence.txt',
            'A path that was a symlink was walked into instead of unlinked.');
        $this->assertFalse(is_link($swapped), 'the link itself should be gone');
    }

    /**
     * THE ORDERING ASSERTION. A broken link exists as a link but `file_exists()` -- which follows it
     * -- answers false, so an `is_link` check placed AFTER the existence check never runs and the
     * dangling link is left behind.
     */
    #[Test]
    public function test_a_broken_symlink_is_removed_rather_than_reported_as_absent(): void
    {
        $dangling = $this->root . '/dangling';
        symlink($this->root . '/never-existed', $dangling);

        $this->assertTrue(is_link($dangling), 'premise: the link exists');
        $this->assertFalse(file_exists($dangling), 'premise: but file_exists() follows it and says no');

        Utility::delete_directory($dangling);

        $this->assertFalse(
            is_link($dangling),
            'A dangling symlink survived. The is_link check must come BEFORE the file_exists check, '
            . 'which follows the link and reports a broken one as absent.'
        );
    }

    /** The control: an ordinary tree with no links is still removed completely. */
    #[Test]
    public function test_an_ordinary_tree_is_still_removed_completely(): void
    {
        $tree = $this->root . '/ordinary';
        mkdir($tree . '/a/b', 0o777, true);
        file_put_contents($tree . '/top.txt', 'x');
        file_put_contents($tree . '/a/mid.txt', 'x');
        file_put_contents($tree . '/a/b/deep.txt', 'x');

        $this->assertTrue(Utility::delete_directory($tree));
        $this->assertDirectoryDoesNotExist($tree);
    }

    /** And a missing path is still a no-op that reports success. */
    #[Test]
    public function test_a_missing_path_is_still_a_successful_no_op(): void
    {
        $this->assertTrue(Utility::delete_directory($this->root . '/never-created'));
    }
}
