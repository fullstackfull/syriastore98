<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

/**
 * P1-PLT-04 / #36 -- MODULE LOADING SWALLOWED EVERY EXCEPTION WITH NO LOG LINE.
 *
 * `PackageServiceProvider::loadDynamicAddons()` ended in `catch (\Exception $e) { // Handle
 * silently }`. A broken install did not fail; it DISAPPEARED. Every affected module's routes,
 * migrations, menu entries and policies were simply absent, nothing said so, and the first symptom
 * an operator saw was a 404 on a page that worked yesterday.
 *
 * THE OLD CATCH WAS WRONG IN THREE SEPARATE WAYS, and this suite pins the correction of each:
 *
 *   1. IT SAID NOTHING. No level, no module name, no exception class.
 *   2. IT WAS ALL-OR-NOTHING. The catch sat OUTSIDE the per-module loop, so one malformed
 *      composer.json abandoned every module after it in the list -- an operator saw a set of
 *      unrelated features vanish at once with no line connecting them.
 *   3. IT CAUGHT THE WRONG TYPE. `\Exception` does not include `\Error`, and a missing class in a
 *      provider's constructor -- the most likely way a half-installed module fails -- is an
 *      `\Error`. So the failure it was most likely to meet was never caught here at all.
 *
 * WHAT IS DELIBERATELY NOT CHANGED: the loader still CONTINUES. Rethrowing from `app->booted()`
 * takes the whole application down, storefront included, for every tenant, over one module's
 * broken composer.json. A live business prefers a degraded install that reports to a dead one that
 * does not. #36's criterion is "reports instead of disappearing", and that is what this asserts.
 *
 * THE FIXTURE WRITES A REAL BROKEN MODULE and removes it again. It has to: the defect is in how
 * the loader reacts to a module on disk, and a mock of the loader would be asserting against the
 * mock. The directory name is unique per run and the removal is in a `finally`, with a final
 * assertion that it is gone -- a stray directory under `packages/workdo/` would be caught by
 * `.github/scripts/guard-no-workdo-changes.sh` on the next commit, which is the backstop.
 */
class AddonLoaderFailureReportingTest extends SecurityTestCase
{
    use RefreshDatabase;

    private ?string $brokenModuleDir = null;

    protected function tearDown(): void
    {
        $this->removeBrokenModule();
        parent::tearDown();
    }

    /**
     * A module directory whose composer.json names a service provider class that does not exist.
     *
     * That is the realistic half-installed shape: the files are there, the autoload map is there,
     * and `$this->app->register()` then throws. On PHP 8 an unresolvable class in `register()` is a
     * `ReflectionException` wrapped by the container into a `BindingResolutionException`, and
     * neither is what the old `catch (\Exception)` was written for.
     */
    private function makeBrokenModule(): string
    {
        $name = 'P1Plt04Broken' . str_replace('.', '', uniqid('', true));
        $dir = base_path('packages/workdo/' . $name);

        mkdir($dir, 0775, true);
        $this->brokenModuleDir = $dir;

        file_put_contents($dir . '/composer.json', json_encode([
            'name' => 'workdo/' . strtolower($name),
            'extra' => ['laravel' => ['providers' => ['Workdo\\' . $name . '\\NoSuchServiceProvider']]],
        ]));

        return $name;
    }

    private function removeBrokenModule(): void
    {
        if ($this->brokenModuleDir === null) {
            return;
        }

        foreach (glob($this->brokenModuleDir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->brokenModuleDir);
        $this->brokenModuleDir = null;
    }

    private function loadAddons(array $addons): void
    {
        $provider = new \App\Providers\PackageServiceProvider($this->app);
        (new \ReflectionMethod($provider, 'loadAddons'))->invoke($provider, $addons);
    }

    // =====================================================================================
    //  PREMISE
    // =====================================================================================

    /**
     * Without this, every assertion below could be satisfied by a module that loads cleanly
     * because its provider was never reached.
     */
    public function test_the_premise_the_broken_module_really_does_make_the_loader_throw(): void
    {
        $name = $this->makeBrokenModule();

        $this->assertFileExists(base_path('packages/workdo/' . $name . '/composer.json'));

        $threw = false;

        try {
            $this->app->register('Workdo\\' . $name . '\\NoSuchServiceProvider');
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Registering the nonexistent provider did not throw, so the fixture models nothing.');
    }

    // =====================================================================================
    //  THE DEFECT
    // =====================================================================================

    public function test_a_broken_module_is_reported_rather_than_disappearing(): void
    {
        $name = $this->makeBrokenModule();

        Log::spy();

        $this->loadAddons([$name]);

        Log::shouldHaveReceived('critical')
            ->withArgs(fn ($message, $context = []) => str_contains((string) $message, 'a module failed to load')
                && ($context['module'] ?? null) === $name)
            ->once();
    }

    /**
     * THE NAME IS THE POINT. Which module failed is the single most useful fact about this
     * failure, and neither the old outer catch nor the uncaught static path recorded it.
     */
    public function test_the_report_names_the_module_the_exception_class_and_the_source_line(): void
    {
        $name = $this->makeBrokenModule();

        $captured = [];
        Log::listen(function ($log) use (&$captured) {
            if ($log->level === 'critical') {
                $captured[] = $log->context;
            }
        });

        $this->loadAddons([$name]);

        $this->assertNotEmpty($captured, 'No critical record was written.');
        $context = $captured[0];

        $this->assertSame($name, $context['module'] ?? null);
        $this->assertNotEmpty($context['exception'] ?? '', 'The exception CLASS is what tells an operator whether this is a missing file or a missing class.');
        $this->assertNotEmpty($context['message'] ?? '');
        $this->assertStringContainsString(':', (string) ($context['file'] ?? ''), 'file should be path:line');
    }

    /** 2. The old catch sat outside the loop, so one broken module abandoned every later one. */
    public function test_a_broken_module_does_not_stop_the_modules_after_it_from_loading(): void
    {
        $broken = $this->makeBrokenModule();

        // A real, healthy module that ships a provider. LandingPage is loaded statically on every
        // boot, so if the loader reaches it at all it registers without error.
        $this->assertFileExists(base_path('packages/workdo/LandingPage/composer.json'),
            'Fixture premise: the healthy module must exist, or "it still loaded" proves nothing.');

        $reached = false;

        try {
            $this->loadAddons([$broken, 'LandingPage']);
            $reached = true;
        } catch (\Throwable $e) {
            $this->fail('The loader threw out of the whole pass: ' . $e->getMessage());
        }

        $this->assertTrue($reached, 'The module after the broken one was never reached.');
    }

    /** 3. `\Error` is not an `\Exception`, and it is the shape a half-installed module throws. */
    public function test_the_loader_catches_a_php_error_and_not_only_an_exception(): void
    {
        $source = file_get_contents(app_path('Providers/PackageServiceProvider.php'));

        $stripped = implode('', array_map(
            fn ($token) => is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($token) ? $token[1] : $token),
            token_get_all($source)
        ));

        $this->assertStringNotContainsString('catch (\Exception', $stripped,
            'A `catch (\Exception)` in the addon loader does not catch a PHP Error, which is how a half-installed '
            . 'module most often fails. Comments are stripped before this check so a docblock quoting the string cannot satisfy it.');
        $this->assertSame(2, substr_count($stripped, 'catch (\Throwable'),
            'Both the per-module catch and the dynamic-pass catch must be \Throwable.');
        $this->assertStringNotContainsString('Handle silently', $stripped,
            'The silent catch is back.');
    }

    /** The loader must still continue: a dead application is worse than a degraded one. */
    public function test_the_application_is_not_taken_down_by_a_broken_module(): void
    {
        $broken = $this->makeBrokenModule();

        $this->loadAddons([$broken]);

        // The app is still usable afterwards: a route still resolves and answers.
        $this->assertNotSame(500, $this->get('/login')->getStatusCode(),
            'A broken module took the application down rather than being reported.');
    }

    /** The fixture must leave nothing behind under a directory CI refuses to let a diff touch. */
    public function test_the_fixture_removes_its_own_broken_module(): void
    {
        $name = $this->makeBrokenModule();
        $dir = base_path('packages/workdo/' . $name);

        $this->assertDirectoryExists($dir);

        $this->removeBrokenModule();

        $this->assertDirectoryDoesNotExist($dir, 'The fixture left a directory under packages/workdo/.');
        $this->assertSame([], glob(base_path('packages/workdo/P1Plt04Broken*')) ?: [],
            'An earlier run left a broken-module directory behind.');
    }
}
