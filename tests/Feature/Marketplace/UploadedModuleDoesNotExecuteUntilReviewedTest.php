<?php

namespace Tests\Feature\Marketplace;

use App\Models\AddOnManager;
use App\Models\ModuleReviewRecord;
use App\Providers\PackageServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\Feature\Security\SecurityTestCase;

/**
 * MARKET-004 / EX-010 — AN UPLOADED PACKAGE'S OWN CODE RAN ON THE NEXT REQUEST, BEFORE ANY REVIEW.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * THE DEFECT, MEASURED, AND WHY IT WAS NOT VISIBLE
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * `ModuleController::install()` extracts an uploaded zip into `packages/workdo/<name>` and writes an
 * `add_on_managers` row with `is_enable = 0`. That reads like a safety and is not one.
 * `PackageServiceProvider::loadDynamicAddons()` does `AddOnManager::pluck('module')` with **no
 * `is_enable` predicate** and hands the result to `loadAddons()`, which registers the package's
 * PSR-4 prefix, calls `$this->app->register($provider)` for every provider its own `composer.json`
 * names, and registers its migrations. So the uploaded package's `register()` and `boot()` ran
 * before anybody enabled it and with nothing having read a line of it.
 *
 * NOTHING COULD HAVE CAUGHT THIS WITH A STATUS CODE. The module answers no routes while disabled —
 * `DisabledModuleRouteGuard` sees to that — so every HTTP probe of a disabled module looked right
 * while its provider was running on every request. THIS TEST THEREFORE DRIVES THE LOADER ITSELF and
 * asserts on a side effect the package's own `register()` produces.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHY A REAL PACKAGE DIRECTORY, AND WHAT IT COSTS
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * `loadAddons()` reads `base_path("packages/workdo/{$addon}/composer.json")`, so there is no seam to
 * fake: a test that does not put files there is testing something else. A probe package is written
 * under a name no vendored module uses and removed in `tearDown`, and `setUp` refuses to run if the
 * directory already exists rather than overwriting whatever is there.
 *
 * CLAUDE.md's warning about process-global pollution applies and is bounded deliberately: the
 * approved case DOES add a PSR-4 prefix to the Composer `ClassLoader` for the whole process. The
 * namespace is unique to this file, so nothing else can autoload through it, and the container
 * binding the provider writes lives on the per-test application and goes with it.
 */
class UploadedModuleDoesNotExecuteUntilReviewedTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const PROBE = 'P6MarketTrustProbe';
    private const MARKER = 'p6-market.trust-probe.registered';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = base_path('packages/workdo/' . self::PROBE);

        $this->assertDirectoryDoesNotExist(
            $this->directory,
            'premise: the probe package must not already exist, so this test never overwrites real files.'
        );

        File::makeDirectory($this->directory . '/src', 0777, true);
        File::makeDirectory($this->directory . '/src/database/migrations', 0777, true);

        File::put($this->directory . '/module.json', json_encode([
            'name' => self::PROBE,
            'alias' => 'Trust Probe',
            'version' => 2.5,
            'parent_module' => ['ProductReview'],
            'package_name' => 'trust-probe',
        ]));

        File::put($this->directory . '/composer.json', json_encode([
            'name' => 'workdo/trust-probe',
            'autoload' => ['psr-4' => ['Workdo\\P6MarketTrustProbe\\' => 'src/']],
            'extra' => ['laravel' => ['providers' => ['Workdo\\P6MarketTrustProbe\\TrustProbeServiceProvider']]],
        ]));

        /*
         * THE PAYLOAD. A real uploaded package can run anything from `register()`; this one records
         * that it ran, which is the only fact the assertions need.
         */
        File::put($this->directory . '/src/TrustProbeServiceProvider.php', <<<'PROVIDER'
<?php

namespace Workdo\P6MarketTrustProbe;

use Illuminate\Support\ServiceProvider;

class TrustProbeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->instance('p6-market.trust-probe.registered', true);
    }
}
PROVIDER);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            File::deleteDirectory($this->directory);
        }

        parent::tearDown();
    }

    /** The install door's own row, exactly as `ModuleController::install()` writes it. */
    private function installedRow(): void
    {
        AddOnManager::create([
            'module' => self::PROBE,
            'name' => 'Trust Probe',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 0,
            'package_name' => 'trust-probe',
        ]);
    }

    private function reviewRecord(string $state): void
    {
        DB::table('module_review_records')->insert([
            'subject_type' => ModuleReviewRecord::TYPE_MODULE,
            'subject' => self::PROBE,
            'provenance' => ModuleReviewRecord::PROVENANCE_UPLOADED,
            'review_state' => $state,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function runTheLoader(): void
    {
        $provider = new PackageServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'loadDynamicAddons');
        $method->setAccessible(true);
        $method->invoke($provider);
    }

    /**
     * THE PREMISE, AND IT IS THE WHOLE EVIDENCE THAT THE DEFECT WAS REAL. Without a review record
     * the loader behaves exactly as it did before this change: the package's provider runs. If this
     * assertion ever fails the refusal tests below prove nothing, because the loader would not be
     * registering the probe for any reason.
     */
    #[Test]
    public function test_the_loader_does_register_an_installed_package_that_has_no_review_record(): void
    {
        $this->installedRow();

        $this->runTheLoader();

        $this->assertTrue(
            $this->app->bound(self::MARKER),
            'premise: with no review record the loader must still register the package, as it does for all 70 vendored modules.'
        );
    }

    #[Test]
    public function test_an_uploaded_package_awaiting_review_is_not_registered(): void
    {
        $this->installedRow();
        $this->reviewRecord(ModuleReviewRecord::STATE_PENDING);

        $this->runTheLoader();

        $this->assertFalse(
            $this->app->bound(self::MARKER),
            'An uploaded package with no approved review decision must not have its service provider registered.'
        );
    }

    #[Test]
    public function test_an_uploaded_package_that_was_rejected_is_not_registered(): void
    {
        $this->installedRow();
        $this->reviewRecord(ModuleReviewRecord::STATE_REJECTED);

        $this->runTheLoader();

        $this->assertFalse($this->app->bound(self::MARKER));
    }

    #[Test]
    public function test_an_approved_package_is_registered(): void
    {
        $this->installedRow();
        $this->reviewRecord(ModuleReviewRecord::STATE_APPROVED);

        $this->runTheLoader();

        $this->assertTrue(
            $this->app->bound(self::MARKER),
            'Recording an approval must let the package load, or the install door is a dead end.'
        );
    }

    /**
     * ITS MIGRATIONS ARE ITS CODE. `loadAddons()` ends in `registerAddonMigrations()`, so an
     * unreviewed package used to contribute migrations to `php artisan migrate` as well — which is
     * arbitrary SQL and arbitrary PHP in an `up()` method. This is the half that
     * `PHASE-06-DISABLED-APPLICATION-BOUNDARY.md` §3 deliberately kept open for DISABLED modules,
     * for a good reason that does not extend to unreviewed ones.
     */
    #[Test]
    public function test_an_unreviewed_package_contributes_no_migrations(): void
    {
        $this->installedRow();
        $this->reviewRecord(ModuleReviewRecord::STATE_PENDING);

        $this->runTheLoader();

        $this->assertNotContains(
            $this->directory . '/src/database/migrations',
            $this->app['migrator']->paths(),
            'An unreviewed package must not contribute migrations.'
        );
    }

    #[Test]
    public function test_an_approved_package_contributes_its_migrations(): void
    {
        $this->installedRow();
        $this->reviewRecord(ModuleReviewRecord::STATE_APPROVED);

        $this->runTheLoader();

        $this->assertContains(
            $this->directory . '/src/database/migrations',
            $this->app['migrator']->paths(),
            'premise: the migration half of the loader is reached at all, so the refusal above is measuring something.'
        );
    }

    /**
     * A VENDORED MODULE IS UNAFFECTED, driven in the same run as a refused upload so the filter is
     * proved to be selective rather than global. `return false` from `mayExecute()` would pass every
     * refusal test in this file and unload the entire platform; this is the assertion that refuses it.
     */
    #[Test]
    public function test_a_vendored_module_still_loads_alongside_a_refused_upload(): void
    {
        $this->installedRow();
        $this->reviewRecord(ModuleReviewRecord::STATE_PENDING);

        AddOnManager::create([
            'module' => 'ProductReview',
            'name' => 'Product Review',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => 'product-review',
        ]);

        $this->runTheLoader();

        $this->assertFalse($this->app->bound(self::MARKER), 'the upload is still refused');
        $this->assertContains(
            base_path('packages/workdo/ProductReview/src/database/migrations'),
            $this->app['migrator']->paths(),
            'A vendored module has no review record and must load exactly as before.'
        );
    }
}
