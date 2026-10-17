<?php

namespace Tests\Unit\Packages;

use PHPUnit\Framework\TestCase;

/**
 * NEW-ADDON-SCHEMA-02, AS A RATCHET OVER ALL 69 ADDON MODULES.
 * =============================================================================================
 *
 * THE DEFECT CLASS. Each addon module registers its own migrations from its service provider:
 *
 *     $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
 *
 * loadMigrationsFrom() does NOT validate that the directory exists -- it appends the string to
 * the migrator's path list, and a path that resolves to nothing contributes no migrations and no
 * warning. So a wrong path is SILENT: the module's tables are simply never created, and the
 * failure surfaces much later as `no such table` from a view.
 *
 * WHY A UNIT TEST AND NOT A FEATURE TEST. This needs no database, no application boot and no
 * vendor/ beyond PHPUnit itself, so it runs in the Unit suite -- which has a zero-failure
 * baseline and is the blocking half of the CI test gate. It extends PHPUnit's TestCase directly
 * rather than Tests\TestCase for the same reason: there is nothing here that wants a framework.
 *
 * WHAT IT PINS, AND WHY IT IS A LEDGER RATHER THAN A PLAIN ASSERTION. A naive "every declared
 * path must exist" assertion fails for 19 of the 69 modules, and 18 of those 19 are NOT defects:
 * the module simply ships no migrations at all, so its declared directory legitimately does not
 * exist. Distinguishing the two cases is the whole point, and it is the distinction a name-based
 * scan gets wrong -- an earlier pass of this measurement reported "1 broken" (glob too narrow,
 * it only saw 5 of the 69 providers) and then "19 broken" (every absent path counted as a bug).
 * The real figure is ONE, and the test asserts the classification, not a count:
 *
 *     a declared path that does not exist is a DEFECT  if and only if
 *     the module nevertheless contains migration-shaped files somewhere.
 *
 * MEASURED ON THIS BRANCH:
 *     loadMigrationsFrom(__DIR__ . '...') calls found : 69
 *       declared path exists                          : 50
 *       absent, module ships no migrations (benign)   : 18
 *       absent, migrations PRESENT on disk (DEFECT)   :  1   <- LandingPage
 *
 * THE ONE DEFECT.
 *     packages/workdo/LandingPage/src/Providers/LandingPageServiceProvider.php:28
 *         declares  src/database/Migrations      (capital M)
 *         on disk   src/database/migrations      (6 migration files)
 *     Six tables are stranded, one of which -- owner_menu_settings -- is read by
 *     resources/views/layouts/guest.blade.php:96, which every guest screen extends. That is why
 *     six of the Feature suite's eight failures are page renders. See
 *     tests/Feature/Platform/AddonMigrationStrandingTest.php, which proves the six screens render
 *     as soon as the real path is used.
 *
 * THIS IS AN OWNER DECISION, NOT A FIX TO APPLY HERE. The file is inside packages/workdo/, which
 * .github/scripts/guard-no-workdo-changes.sh refuses to let a pull request modify: an in-place
 * edit to a vendored commercial module is reverted by the next upstream update and leaves no
 * patch record. The remedy is one character; where it is applied is the owner's call.
 *
 * WHEN THE OWNER FIXES IT: this test goes red, saying the defect list shrank. Remove the
 * LandingPage entry from EXPECTED_STRANDED and the test goes green again -- in the same commit,
 * so the record and the code cannot disagree.
 */
class AddonMigrationRegistrationTest extends TestCase
{
    /**
     * Modules whose declared migration path does not exist AND which nevertheless ship
     * migrations. Exactly one today. The value is the stranded directory, relative to the repo
     * root, so a fix that moves the directory rather than fixing the string also reddens this.
     */
    private const EXPECTED_STRANDED = [
        'LandingPage' => 'packages/workdo/LandingPage/src/database/migrations',
    ];

    /**
     * Modules that declare a path which does not exist and ship NO migrations. Benign today, but
     * named rather than counted: if one of these ever gains a migration, its tables would be
     * stranded by the SAME bug and this list is what notices.
     */
    private const EXPECTED_PATHLESS_AND_EMPTY = [
        'Api', 'BoostSales', 'BulkDelete', 'CouponList', 'FreeShippingPopup', 'FrequentlyProduct',
        'ImportExport', 'ProductCSV', 'ProductImageZoom', 'ProductQuickView', 'ProductsPDF',
        'PurchaseNotification', 'QuickCheckout', 'RecentOrders', 'SignInWithOutlook', 'SkipCart',
        'Subscribe', 'Wizzchat',
    ];

    /**
     * 70 since LynomiaChat was added (Store Syria x Lynomia Chat, phase 1).
     *
     * Raised because a module was genuinely added, which is the update this tripwire's own
     * failure message sanctions -- NOT by relaxing the scanner's pattern, which the message
     * forbids and which would blind every classification below. LynomiaChat registers
     * loadMigrationsFrom(__DIR__ . '/../../database/migrations') in the same __DIR__-relative
     * shape as the other 69, ships one real migration at that real path, and so appears in
     * neither EXPECTED_STRANDED nor EXPECTED_PATHLESS_AND_EMPTY. Verified: this count was the
     * only assertion in this file that moved.
     */
    private const EXPECTED_CALL_COUNT = 70;

    private function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * Every loadMigrationsFrom(__DIR__ . '<literal>') in packages/workdo/, as
     * [module, provider-relative-path, declared-literal, resolved-absolute-path].
     *
     * Only the __DIR__-relative form is matched, deliberately: it is the form all 69 modules use
     * and the only one whose target can be resolved statically. If a module ever switches to
     * base_path() or a variable, the call count assertion below reddens and this scanner has to
     * be taught the new shape rather than silently skipping it.
     */
    private function declaredMigrationPaths(): array
    {
        $root = $this->repoRoot();
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $root . '/packages/workdo',
                \FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            $matched = preg_match_all(
                '/loadMigrationsFrom\s*\(\s*__DIR__\s*\.\s*([\'"])(.+?)\1\s*\)/',
                $source,
                $matches,
                PREG_SET_ORDER
            );

            if (! $matched) {
                continue;
            }

            $relative = str_replace($root . '/', '', $file->getPathname());

            preg_match('#^packages/workdo/([^/]+)/#', $relative, $moduleMatch);

            foreach ($matches as $match) {
                $found[] = [
                    'module'   => $moduleMatch[1] ?? '?',
                    'provider' => $relative,
                    'literal'  => $match[2],
                    'resolved' => dirname($file->getPathname()) . $match[2],
                ];
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Directories inside a module that hold files named like a Laravel migration. Used to answer
     * "does this module ship migrations at all?" independently of where the provider claims they
     * are -- which is exactly the question that separates the one defect from the 18 benign.
     */
    private function migrationDirectoriesIn(string $module): array
    {
        $root = $this->repoRoot();
        $base = $root . '/packages/workdo/' . $module;

        if (! is_dir($base)) {
            return [];
        }

        $directories = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_.+\.php$/', $file->getFilename())) {
                $directories[str_replace($root . '/', '', $file->getPath())] = true;
            }
        }

        ksort($directories);

        return array_keys($directories);
    }

    public function test_the_scan_still_sees_every_module_registering_migrations(): void
    {
        $calls = $this->declaredMigrationPaths();

        // Refuse to pass vacuously. If the scan stopped matching -- a moved tree, a changed call
        // shape -- every classification below would be trivially empty and this file would report
        // "no defects" while checking nothing. The same failure mode the GATE F shell guard
        // defends against with its 200-migration floor.
        $this->assertSame(
            self::EXPECTED_CALL_COUNT,
            count($calls),
            'Expected ' . self::EXPECTED_CALL_COUNT . ' loadMigrationsFrom(__DIR__ . ...) calls '
            . 'across packages/workdo/, found ' . count($calls) . '. Either modules were added or '
            . 'removed, or one switched to a call shape this scanner does not resolve (base_path(), '
            . 'a variable). Do not relax the pattern to make this pass: teach the scanner the new '
            . 'shape, or the classification below goes blind.'
        );
    }

    public function test_exactly_one_addon_module_strands_migrations_it_actually_ships(): void
    {
        $stranded = [];
        $pathlessAndEmpty = [];

        foreach ($this->declaredMigrationPaths() as $call) {
            if (is_dir($call['resolved'])) {
                continue;
            }

            $present = $this->migrationDirectoriesIn($call['module']);

            if ($present === []) {
                $pathlessAndEmpty[$call['module']] = true;

                continue;
            }

            $stranded[$call['module']] = $present[0];
        }

        ksort($stranded);

        $this->assertSame(
            self::EXPECTED_STRANDED,
            $stranded,
            "The set of addon modules that STRAND migrations they ship has changed.\n\n"
            . "Each entry means: the module's service provider calls loadMigrationsFrom() on a\n"
            . "directory that does not exist, while migration files DO exist elsewhere in the\n"
            . "module -- so those tables are created by nothing, silently.\n\n"
            . "If the set GREW, a new module has the same bug: report it, do not edit it in place\n"
            . "(packages/workdo/ is guarded -- see .github/scripts/guard-no-workdo-changes.sh).\n"
            . "If the set SHRANK, the owner has fixed one: remove its entry here, and if it was\n"
            . "LandingPage also retire tests/Feature/Platform/AddonMigrationStrandingTest.php and\n"
            . "drop the six owner_menu_settings entries from .github/known-test-outcomes.json."
        );
    }

    public function test_the_benign_pathless_modules_still_ship_no_migrations(): void
    {
        $pathlessAndEmpty = [];

        foreach ($this->declaredMigrationPaths() as $call) {
            if (is_dir($call['resolved'])) {
                continue;
            }

            if ($this->migrationDirectoriesIn($call['module']) === []) {
                $pathlessAndEmpty[] = $call['module'];
            }
        }

        $pathlessAndEmpty = array_values(array_unique($pathlessAndEmpty));
        sort($pathlessAndEmpty);

        $expected = self::EXPECTED_PATHLESS_AND_EMPTY;
        sort($expected);

        $this->assertSame(
            $expected,
            $pathlessAndEmpty,
            "The set of modules that declare a non-existent migration path AND ship no migrations\n"
            . "has changed. These are benign only while they stay empty: the moment one gains a\n"
            . "migration its tables are stranded by the same bug that cost LandingPage six tables.\n"
            . "A module LEAVING this list means either it gained migrations (check the stranded\n"
            . "test above -- it should have caught that) or its path now resolves."
        );
    }

    public function test_the_landing_page_provider_still_declares_the_mis_cased_path(): void
    {
        $provider = $this->repoRoot()
            . '/packages/workdo/LandingPage/src/Providers/LandingPageServiceProvider.php';

        $this->assertFileExists($provider);

        $source = (string) file_get_contents($provider);

        // Pinned as the exact literal, so that ANY edit to this line -- the fix, or a different
        // wrong value -- reddens here and forces the record to be updated with it.
        $this->assertStringContainsString(
            "loadMigrationsFrom(__DIR__ . '/../database/Migrations')",
            $source,
            'LandingPageServiceProvider no longer declares the mis-cased migration path. If the '
            . 'owner corrected it to /../database/migrations, NEW-ADDON-SCHEMA-02 is CLOSED: '
            . 'remove the LandingPage entry from EXPECTED_STRANDED, retire '
            . 'tests/Feature/Platform/AddonMigrationStrandingTest.php, and drop the six '
            . 'owner_menu_settings entries from .github/known-test-outcomes.json.'
        );

        $this->assertDirectoryExists(
            $this->repoRoot() . '/packages/workdo/LandingPage/src/database/migrations',
            'The real (lowercase) migrations directory is gone, so the stranded-migration finding '
            . 'needs re-measuring from scratch.'
        );

        $this->assertDirectoryDoesNotExist(
            $this->repoRoot() . '/packages/workdo/LandingPage/src/database/Migrations',
            'A directory now exists at the mis-cased path. On a case-insensitive filesystem this '
            . 'test cannot distinguish the two spellings at all -- which is itself worth knowing, '
            . 'because it means CI (Linux, case-sensitive) and a macOS developer machine disagree '
            . 'about whether this bug exists.'
        );
    }

    public function test_the_six_stranded_tables_are_still_the_six_recorded(): void
    {
        $directory = $this->repoRoot() . '/packages/workdo/LandingPage/src/database/migrations';

        $tables = [];

        foreach ((glob($directory . '/*.php') ?: []) as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match_all('/Schema::create\s*\(\s*[\'"]([a-z0-9_]+)[\'"]/i', $source, $m)) {
                foreach ($m[1] as $table) {
                    $tables[$table] = true;
                }
            }
        }

        ksort($tables);

        $this->assertSame(
            [
                'join_us',
                'landing_page_settings',
                'marketplace_page_settings',
                'owner_menu_items',
                'owner_menu_settings',
                'owner_menus',
            ],
            array_keys($tables),
            'The set of tables stranded by NEW-ADDON-SCHEMA-02 has changed. owner_menu_settings is '
            . 'the one that matters for the test suite (guest.blade.php:96 reads it), but all six '
            . 'are missing from any database built by `migrate` alone.'
        );
    }
}
