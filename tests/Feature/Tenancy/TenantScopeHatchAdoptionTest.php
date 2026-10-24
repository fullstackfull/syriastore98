<?php

namespace Tests\Feature\Tenancy;

use App\Scopes\StoreScope;
use ReflectionMethod;
use Tests\TestCase;

/**
 * P1-PLT-03 -- the two cross-tenant escape hatches, and why they still have no production callers.
 *
 * THE ROW'S TITLE IS A COMPLAINT: "forStoreContext/allowCrossTenant have zero call sites". The
 * obvious reading is that the hatches were built and then forgotten, and that somewhere in app/
 * there are queries silently crossing tenants that ought to be doing it explicitly. The census
 * below says that reading is wrong, and says exactly why -- which is a better outcome than
 * sprinkling hatch calls to make a count go up.
 *
 * THE FOUR CONTEXTS THAT WOULD NEED A HATCH, from config/tenancy.php's own list, and what each
 * actually resolves to today:
 *
 *   1. THE PLATFORM OPERATOR already has a built-in bypass. StoreScope::resolveTenant() returns
 *      MODE_SUPER_ADMIN with `store_ids => []` and emits no predicate (:536-548). An operator
 *      query does not need allowCrossTenant() because it is already unscoped.
 *   2. A MERCHANT reading their own data gets `admissibleStoreIds()`, which is their current store
 *      UNION the stores they own. A merchant with several stores already crosses between them
 *      without a hatch, by design.
 *   3. THE STOREFRONT, THE `customers` AND `deliveryboy` GUARDS, A QUEUED JOB AND A CONSOLE
 *      COMMAND all resolve no web-guard principal, so `null_tenant` decides -- and it is 'open',
 *      which adds no predicate at all. Nothing there needs a hatch either, which is precisely the
 *      acknowledged isolation hole config/tenancy.php documents rather than hides.
 *   4. SO THE HATCHES ARE FOR THE WORLD AFTER `null_tenant` IS TIGHTENED. That is not a
 *      hypothetical deferral: see docs/security/P01-NULL-TENANT-TIGHTENING-PROPOSAL.md, which
 *      measures what 'closed' actually breaks.
 *
 * WHAT THIS TEST THEREFORE ASSERTS: that the premise above is still true. If a queued job, a
 * console command, an observer or a listener is added that queries an ENFORCED model, this test
 * fails and names the hatch it should be using -- because that is the first context in which the
 * absence of a call site stops being correct and starts being a bug.
 *
 * AND IT ASSERTS ITS OWN REACHABILITY, because this project has recorded four censuses that
 * passed vacuously: a scan keyed on a directory that was renamed, or on a model list that came
 * back empty, proves nothing.
 */
class TenantScopeHatchAdoptionTest extends TestCase
{
    /**
     * The directories whose code runs with NO web-guard principal.
     *
     * Each is a context config/tenancy.php names in its `null_tenant` argument. `app/Jobs` and
     * `app/Observers` do not exist today; they are listed anyway, so that creating one and
     * querying an enforced model from it fails here rather than being discovered later.
     *
     * @return array<int, string>
     */
    private function outOfRequestDirectories(): array
    {
        return ['app/Console/Commands', 'app/Jobs', 'app/Observers', 'app/Listeners'];
    }

    /** @return array<int, string> short class names of the enforced models */
    private function enforcedModelNames(): array
    {
        $names = [];

        foreach ((array) config('tenancy.scoped_models', []) as $class) {
            $names[] = class_basename($class);
        }

        return $names;
    }

    /**
     * THE PREMISE: THE HATCHES EXIST AND ARE CALLABLE.
     *
     * Asserted first because every other assertion here is about their ABSENCE from production
     * code, and "absent" is indistinguishable from "renamed" or "deleted" unless this passes.
     */
    public function test_both_escape_hatches_still_exist(): void
    {
        foreach (['forStoreContext', 'allowCrossTenant'] as $hatch) {
            $this->assertTrue(method_exists(StoreScope::class, $hatch),
                'StoreScope::' . $hatch . '() is gone. P1-PLT-03 is about its adoption, so if it '
                . 'has been renamed or removed this test and the ledger row both describe '
                . 'something that no longer exists.');

            $method = new ReflectionMethod(StoreScope::class, $hatch);
            $this->assertTrue($method->isStatic() && $method->isPublic(),
                'StoreScope::' . $hatch . '() is no longer a public static hatch.');
        }
    }

    /**
     * THE PREMISE: THE CENSUS BELOW IS LOOKING AT SOMETHING.
     *
     * Four censuses in this project have passed because they were keyed on a spelling that had
     * changed. This one asserts that the model list is non-empty and that at least one of the
     * scanned directories really exists, before concluding anything from an empty result.
     */
    public function test_the_census_is_reachable(): void
    {
        $this->assertNotEmpty($this->enforcedModelNames(),
            'config/tenancy.php lists no enforced models, so the scan below would find nothing '
            . 'no matter what the code did.');

        $existing = array_filter(
            $this->outOfRequestDirectories(),
            fn ($dir) => is_dir(base_path($dir))
        );

        $this->assertNotEmpty($existing,
            'None of the out-of-request directories exists, so this census covers nothing. '
            . 'Looked for: ' . implode(', ', $this->outOfRequestDirectories()));
    }

    /**
     * NO OUT-OF-REQUEST CODE QUERIES AN ENFORCED MODEL WITHOUT A HATCH.
     *
     * This is the assertion that turns "zero call sites" from an unexplained count into a
     * maintained property. A console command or a queued job that reads an enforced model is the
     * first place where `null_tenant => 'open'` is doing the work a hatch should be doing
     * explicitly, and it is the place a future tightening would silently break.
     */
    public function test_no_out_of_request_code_queries_an_enforced_model_without_a_hatch(): void
    {
        $names = $this->enforcedModelNames();
        $offenders = [];

        foreach ($this->outOfRequestDirectories() as $dir) {
            $path = base_path($dir);

            if (! is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $source = file_get_contents($file->getPathname());

                // A file that opens a hatch anywhere is exempt: the point of this census is to
                // find code that crosses tenants WITHOUT saying so, not to forbid the models.
                if (str_contains($source, 'forStoreContext') || str_contains($source, 'allowCrossTenant')) {
                    continue;
                }

                foreach ($names as $model) {
                    // Strip comment lines first. A docblock naming a model would otherwise satisfy
                    // this scan -- the exact trap the project skill records for source-grep
                    // ratchets.
                    $code = preg_replace('/^\s*(\/\/|\*|\/\*).*$/m', '', $source);

                    if (preg_match('/\b' . preg_quote($model, '/') . '::(find|findOrFail|where|first|all|create|updateOrCreate|destroy)\b/', $code)) {
                        $offenders[] = ltrim(str_replace(base_path(), '', $file->getPathname()), '/')
                            . ' queries ' . $model;
                    }
                }
            }
        }

        $this->assertSame([], $offenders,
            "Out-of-request code queries an ENFORCED model with no explicit tenant context. "
            . "`tenancy.null_tenant` is 'open', so the global scope adds NOTHING here and the "
            . "query silently sees every tenant's rows; tightening null_tenant would silently "
            . "break it instead. Wrap the query in StoreScope::forStoreContext(\$storeId, ...) if "
            . "it acts for one tenant, or StoreScope::allowCrossTenant(...) if it deliberately "
            . "acts for all of them:\n  " . implode("\n  ", $offenders));
    }

    /**
     * AND THE REASON THE HATCHES ARE NOT NEEDED IN A REQUEST IS STILL TRUE.
     *
     * The operator bypass is what makes allowCrossTenant() unnecessary on an admin screen. If that
     * bypass were ever narrowed, every operator-facing cross-store query WOULD need the hatch, and
     * this assertion is the tripwire for that change.
     */
    public function test_the_operator_bypass_is_still_what_makes_the_hatch_unnecessary(): void
    {
        $operator = new \App\Models\User();
        $operator->type = 'super admin';

        $this->be($operator, 'web');

        $tenant = StoreScope::resolveTenant();

        $this->assertSame(StoreScope::MODE_SUPER_ADMIN, $tenant['mode'] ?? null,
            'The platform operator no longer resolves to the super-admin mode, so operator '
            . 'screens are now being filtered. Every cross-store operator query would need '
            . 'StoreScope::allowCrossTenant() and P1-PLT-03 changes from a measured negative into '
            . 'real work. Resolved: ' . json_encode($tenant));
        $this->assertSame([], $tenant['store_ids'] ?? null,
            'The operator mode now carries store ids, so a predicate is being emitted for the '
            . 'platform operator.');
    }
}
