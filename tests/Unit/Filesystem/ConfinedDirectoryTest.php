<?php

namespace Tests\Unit\Filesystem;

use App\Support\Filesystem\ConfinedDirectory;
use App\Support\Filesystem\PathNotConfined;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE SHARED CONFINEMENT CONTRACT, PROVED AGAINST DIRECTORIES THIS TEST CREATES AND DESTROYS.
 *
 * `ConfinedDirectory` is the security-critical half of two destructive doors -- module removal and
 * language deletion -- so it is tested once, here, rather than twice through two controllers. Every
 * path below is a throwaway under the system temp directory. NOTHING in this file names a path
 * inside the repository, and that is not stylistic: against an unconfined delete,
 * `resources/lang/..` is `resources/` and `packages/workdo/..` is all 70 vendored modules.
 *
 * WHY THE ESCAPES ARE PROVED HERE AND NOT THROUGH EITHER ROUTE. A failing-first test for a
 * traversal has to run the traversal against the UNFIXED handler, and both handlers end in a
 * recursive delete of whatever the traversal resolved to. A mutation that removes the confinement
 * would do the same thing to a route test on FIXED code. There is no ordering of those two runs that
 * is safe, so the property is proved at the function that decides it, and the controllers' wiring is
 * pinned structurally instead.
 */
class ConfinedDirectoryTest extends TestCase
{
    private string $root;

    /** A disposable directory OUTSIDE the root, for the escape tests. */
    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $this->root = sys_get_temp_dir() . '/ss-confined-' . $suffix;
        $this->outside = sys_get_temp_dir() . '/ss-confined-out-' . $suffix;

        mkdir($this->root . '/Member', 0o777, true);
        mkdir($this->outside, 0o777, true);
    }

    protected function tearDown(): void
    {
        /*
         * BOTH PATHS ARE HELD AS PROPERTIES, not re-derived. The module version of this fixture
         * derived the outside path from `$this->root` at teardown, and by then the root was already
         * gone -- so `is_dir()` on a path whose parent no longer exists was false and the directory
         * survived every run. 137 of them had accumulated before an independent review noticed.
         */
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

    // ---------------------------------------------------------------- safeSegment

    /** @return list<array{0: string}> */
    public static function pathAlteringNames(): array
    {
        return [
            'parent'               => ['..'],
            'grandparent'          => ['../..'],
            'current'              => ['.'],
            'percent encoded'      => ['%2e%2e'],
            'forward slash'        => ['en/other'],
            'back slash'           => ['en\\other'],
            'trailing dot segment' => ['en/..'],
            'null byte'            => ["en\0"],
            'leading slash'        => ['/etc'],
            'padded'               => [' en '],
            'newline'              => ["en\n"],
            'dot file'             => ['.hidden'],
        ];
    }

    #[Test]
    #[DataProvider('pathAlteringNames')]
    public function test_a_name_that_could_alter_a_path_is_refused(string $candidate): void
    {
        $this->expectException(PathNotConfined::class);
        $this->expectExceptionMessageMatches('/characters that could alter a path/');

        ConfinedDirectory::safeSegment($candidate);
    }

    /**
     * `.` AND `..` ARE REFUSED BY NAME, WHICH IS THE WHOLE POINT, because they are the two values a
     * confinement check alone would wave through: both resolve to a real directory, and `.` resolves
     * to the ROOT -- which is the collection, not a member of it.
     */
    #[Test]
    public function test_the_two_traversal_names_are_refused_before_any_filesystem_call(): void
    {
        foreach (['.', '..'] as $traversal) {
            try {
                ConfinedDirectory::safeSegment($traversal);
                $this->fail('expected a refusal for ' . var_export($traversal, true));
            } catch (PathNotConfined $e) {
                $this->assertStringContainsString('alter a path', $e->getMessage());
            }
        }
    }

    #[Test]
    public function test_a_sanitisable_name_is_refused_rather_than_rewritten(): void
    {
        try {
            ConfinedDirectory::safeSegment('en/../ar');
            $this->fail('expected a refusal');
        } catch (PathNotConfined $e) {
            $this->assertStringNotContainsString('enar', $e->getMessage(),
                'the refusal must not report a rewritten name as though it were the request');
        }
    }

    #[Test]
    public function test_an_empty_or_non_string_name_is_refused(): void
    {
        try {
            ConfinedDirectory::safeSegment('');
            $this->fail('expected a refusal for the empty string');
        } catch (PathNotConfined $e) {
            $this->assertStringContainsString('empty', $e->getMessage());
        }

        foreach ([null, 42, ['en'], new \stdClass()] as $notAString) {
            try {
                ConfinedDirectory::safeSegment($notAString);
                $this->fail('expected a refusal for a non-string');
            } catch (PathNotConfined $e) {
                $this->assertStringContainsString('not a string', $e->getMessage());
            }
        }
    }

    /** THE CONTROL: real names must pass, including the hyphen and underscore forms locales use. */
    #[Test]
    public function test_real_names_are_accepted(): void
    {
        foreach (['en', 'ar', 'pt_BR', 'zh-CN', 'Api', 'Product_Review-2'] as $name) {
            $this->assertSame($name, ConfinedDirectory::safeSegment($name));
        }
    }

    /** The noun travels into the message, so each door's refusal reads in its own vocabulary. */
    #[Test]
    public function test_the_noun_is_used_in_the_refusal(): void
    {
        try {
            ConfinedDirectory::safeSegment('..', 'language code');
            $this->fail('expected a refusal');
        } catch (PathNotConfined $e) {
            $this->assertStringContainsString('language code', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- within

    #[Test]
    public function test_confinement_returns_the_canonical_directory(): void
    {
        $this->assertSame(
            realpath($this->root . '/Member'),
            ConfinedDirectory::within($this->root, 'Member')
        );
    }

    /**
     * A SYMLINK POINTING OUTSIDE THE ROOT MUST BE REFUSED, NOT FOLLOWED -- the reason this uses
     * `realpath()` rather than string arithmetic. A `str_starts_with` on the UNRESOLVED path would
     * have passed, and the recursive delete would have walked into someone else's directory.
     */
    #[Test]
    public function test_a_target_symlinked_outside_the_root_is_refused(): void
    {
        $outside = realpath($this->outside);
        file_put_contents($outside . '/evidence.txt', 'must survive');
        symlink($outside, $this->root . '/Escapee');

        try {
            ConfinedDirectory::within($this->root, 'Escapee');
            $this->fail('a symlink escaping the root was accepted');
        } catch (PathNotConfined $e) {
            $this->assertStringContainsString('outside the', $e->getMessage());
        }

        $this->assertFileExists($outside . '/evidence.txt',
            'the guard must refuse before anything touches the linked directory');
    }

    /**
     * THE ROOT ITSELF IS THE DANGEROUS ONE and gets its own message: handed to a recursive delete it
     * takes the whole collection -- every module, or every language -- rather than one member.
     */
    #[Test]
    public function test_a_traversal_resolving_to_the_root_itself_is_refused(): void
    {
        mkdir($this->root . '/Nested/Inner', 0o777, true);

        try {
            ConfinedDirectory::within($this->root, 'Nested/Inner/../..');
            $this->fail('a traversal back to the root was accepted');
        } catch (PathNotConfined $e) {
            $this->assertStringContainsString('itself', $e->getMessage());
        }

        // And the plain form, which is what a route segment would actually carry.
        try {
            ConfinedDirectory::within($this->root, '.');
            $this->fail('"." was accepted');
        } catch (PathNotConfined $e) {
            $this->assertStringContainsString('itself', $e->getMessage());
        }
    }

    #[Test]
    public function test_a_traversal_above_the_root_is_refused(): void
    {
        $this->expectException(PathNotConfined::class);
        $this->expectExceptionMessageMatches('/outside the/');

        ConfinedDirectory::within($this->root, '..');
    }

    #[Test]
    public function test_a_target_that_does_not_exist_is_refused(): void
    {
        $this->expectException(PathNotConfined::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        ConfinedDirectory::within($this->root, 'NeverCreated');
    }

    #[Test]
    public function test_a_file_where_a_directory_should_be_is_refused(): void
    {
        file_put_contents($this->root . '/NotADir', 'x');

        $this->expectException(PathNotConfined::class);
        $this->expectExceptionMessageMatches('/not a directory/');

        ConfinedDirectory::within($this->root, 'NotADir');
    }

    #[Test]
    public function test_a_missing_root_is_refused_rather_than_treated_as_empty(): void
    {
        $this->expectException(PathNotConfined::class);
        $this->expectExceptionMessageMatches('/does not exist/');

        ConfinedDirectory::within($this->root . '/no-such-root', 'Member');
    }

    /** A root that is a FILE is not a root. */
    #[Test]
    public function test_a_root_that_is_a_file_refuses_everything_under_it(): void
    {
        file_put_contents($this->root . '/afile', 'x');

        $this->expectException(PathNotConfined::class);

        ConfinedDirectory::within($this->root . '/afile', 'Member');
    }
}
