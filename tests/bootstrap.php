<?php

/**
 * PHPUnit bootstrap -- LAYER 0, THE PRIMARY TEST-ISOLATION CONTROL.
 *
 * Runs before any test class is loaded and before the Laravel application exists, so
 * it is the only guard guaranteed to run before RefreshDatabase can issue
 * migrate:fresh (which DROPS EVERY TABLE in whatever database it is pointed at).
 *
 * Why here and not in tests/TestCase.php:
 *   1. Illuminate\Foundation\Testing\TestCase::setUp() is where setUpTraits() runs,
 *      and that is what invokes RefreshDatabase::refreshDatabase(). A setUp()
 *      assertion therefore runs AFTER the tables have already been dropped.
 *   2. afterApplicationCreated() callbacks are dispatched from inside parent::setUp()
 *      in the same window, so they are late for the same reason.
 *   3. tests/Unit/ExampleTest.php extends PHPUnit\Framework\TestCase directly, so a
 *      guard on Tests\TestCase cannot cover every test class at all.
 *
 * TWO CONFIGURATION SOURCES, BY DESIGN. It is NOT established whether PHPUnit 11
 * applies phpunit.xml's <env force="true"> entries before or after loading this file,
 * and that could not be verified in the environment where this was written (the
 * phpunit package was not installed there). So:
 *   - if the environment already carries DB_CONNECTION/DB_DATABASE, they are asserted
 *     directly, and a non-sqlite value is fatal;
 *   - if it does not, phpunit.xml is read and must itself force sqlite / :memory:.
 * Either way the run proceeds only when the configuration provably pins an in-memory
 * SQLite database, and a MySQL-pointed run is impossible. Do not "simplify" this to a
 * single source without first establishing that ordering.
 *
 * Do not remove this file, and do not point phpunit.xml's bootstrap= away from it.
 * See docs/development/phase-1/05-REGRESSION-TEST-PLAN.md §1.3 and §1.4.
 */

require __DIR__ . '/../vendor/autoload.php';

use Tests\Support\TestDatabaseGuard;

$fail = static function (string $detail, ?string $connection, ?string $database): void {
    fwrite(STDERR, PHP_EOL . str_repeat('=', 78) . PHP_EOL);
    fwrite(STDERR, 'ABORTING TEST RUN BEFORE ANY TEST LOADED.' . PHP_EOL);
    fwrite(STDERR, sprintf(
        'DB_CONNECTION=%s  DB_DATABASE=%s  (required: sqlite / :memory:)' . PHP_EOL,
        var_export($connection, true),
        var_export($database, true)
    ));
    fwrite(STDERR, $detail . PHP_EOL);
    fwrite(STDERR, 'RefreshDatabase runs migrate:fresh, which DROPS EVERY TABLE in the' . PHP_EOL);
    fwrite(STDERR, 'target database. phpunit.xml must set both DB_CONNECTION and' . PHP_EOL);
    fwrite(STDERR, 'DB_DATABASE with force="true". See' . PHP_EOL);
    fwrite(STDERR, 'docs/development/phase-1/05-REGRESSION-TEST-PLAN.md.' . PHP_EOL);
    fwrite(STDERR, str_repeat('=', 78) . PHP_EOL . PHP_EOL);
    exit(1);
};

/**
 * LAYER 0b -- THE INSTALLER MARKER, AND WHY THE SUITE NEEDS IT.
 *
 * Three Auth controllers guard their constructors with the Workdo installer check:
 *
 *     if (!file_exists(storage_path() . "/installed")) { header('location:install'); die; }
 *
 * (AuthenticatedSessionController:24-27, RegisteredUserController:26-30,
 * PasswordResetLinkController:17-22.) `die` terminates the PHP process, and PHPUnit is that
 * process. storage/ is gitignored, so on any fresh checkout -- a new clone, a CI runner, a
 * container -- the marker is absent and the FIRST test that touches one of those controllers
 * kills the run.
 *
 * It does not fail. It exits 0, prints no summary, and reports no failures. Measured on the
 * merge commit 0d2ca628 with the marker absent:
 *
 *     tests/Feature/Auth        0 of 21 tests executed, exit 0
 *     tests/Feature/Security    ~160 of 632 executed, exit 0, no summary
 *     tests/Feature (whole)     0 executed, exit 0
 *
 * A CI step that runs the suite and checks the exit code sees success. With the marker
 * present the Auth suite runs all 21 and reports 7 real failures that were invisible before.
 *
 * Creating the file here, rather than in a CI step, keeps the guarantee with the suite: it
 * holds for every runner and every local checkout. It is a test-environment artifact only --
 * storage/ is gitignored, nothing is committed, and no production behaviour changes.
 */
$installedMarker = __DIR__ . '/../storage/installed';

if (!file_exists($installedMarker)) {
    if (!is_dir(dirname($installedMarker))) {
        @mkdir(dirname($installedMarker), 0775, true);
    }

    @touch($installedMarker);

    if (!file_exists($installedMarker)) {
        fwrite(STDERR, PHP_EOL . str_repeat('=', 78) . PHP_EOL);
        fwrite(STDERR, 'CANNOT CREATE storage/installed.' . PHP_EOL);
        fwrite(STDERR, 'Without it the Auth controllers `die` in their constructors and the' . PHP_EOL);
        fwrite(STDERR, 'run terminates silently with exit 0, reporting success having executed' . PHP_EOL);
        fwrite(STDERR, 'only part of the suite. Refusing to run rather than report a false pass.' . PHP_EOL);
        fwrite(STDERR, str_repeat('=', 78) . PHP_EOL . PHP_EOL);
        exit(1);
    }
}

$connection = $_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? getenv('DB_CONNECTION') ?: null;
$database   = $_ENV['DB_DATABASE']   ?? $_SERVER['DB_DATABASE']   ?? getenv('DB_DATABASE')   ?: null;

if ($connection !== null || $database !== null) {
    // Source A: the environment already carries values. Assert them strictly.
    try {
        TestDatabaseGuard::assertSqliteInMemory($connection, $database);
    } catch (RuntimeException $e) {
        $fail($e->getMessage(), $connection, $database);
    }
} else {
    // Source B: nothing in the environment yet. phpunit.xml must force the overrides
    // itself, or we refuse to run.
    $configPath = __DIR__ . '/../phpunit.xml';

    if (!is_readable($configPath)) {
        $fail('phpunit.xml is not readable, so the test database cannot be confirmed.', null, null);
    }

    $xml = (string) file_get_contents($configPath);

    $forcesConnection = (bool) preg_match(
        '/<env\s+name="DB_CONNECTION"\s+value="sqlite"[^>]*force="true"/',
        $xml
    );
    $forcesDatabase = (bool) preg_match(
        '/<env\s+name="DB_DATABASE"\s+value=":memory:"[^>]*force="true"/',
        $xml
    );

    if (!$forcesConnection || !$forcesDatabase) {
        $fail(
            'The environment carries no DB_CONNECTION/DB_DATABASE and phpunit.xml does '
            . 'not force both to sqlite / :memory:. Refusing to run.',
            $connection,
            $database
        );
    }
}
