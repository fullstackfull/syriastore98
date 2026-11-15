<?php

namespace Tests\Unit\Platform;

use App\Models\Plan;
use App\Support\Platform\PlatformCapabilities;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M5 — THE SITE CANNOT ADVERTISE SOMETHING THE PRODUCT DOES NOT HAVE.
 * =================================================================================================
 *
 * The owner's instruction for M5 is "Do not invent supported features." A marketing page is the
 * easiest place in a codebase to write something untrue, because nothing compiles it. This suite
 * is the compiler.
 *
 *   - Every module a claim names must EXIST in `packages/workdo/`. A claim naming a module that
 *     was never vendored, or one that has since been removed, fails here rather than appearing on
 *     the public site.
 *   - A claim whose module is absent must be FILTERED OUT of what the page renders, which is the
 *     mechanism the first point protects. Both halves are needed: the first stops the registry
 *     from naming fiction, the second stops a real-but-removed module from being advertised.
 *   - The theme list must be the directory, not a copy of it.
 *   - `-1` is unlimited and `0` is zero. Measured against `User.php:339`,
 *     `DashboardController.php:2966` and `Utility.php:868`; the first draft had it backwards.
 */
class PlatformCapabilitiesTest extends TestCase
{
    #[Test]
    public function every_module_a_claim_names_is_actually_vendored(): void
    {
        $missing = [];

        foreach (PlatformCapabilities::groupNames() as $group) {
            foreach (PlatformCapabilities::declared($group) as $entry) {
                if ($entry['module'] === null) {
                    continue;
                }
                if (! is_dir(base_path('packages/workdo/' . $entry['module']))) {
                    $missing[] = $group . ' / ' . $entry['module'] . ' (' . $entry['title'] . ')';
                }
            }
        }

        $this->assertSame([], $missing,
            "The official site claims a capability whose module is not in this repository:\n  "
            . implode("\n  ", $missing));
    }

    #[Test]
    public function the_registry_is_not_empty_and_names_real_modules(): void
    {
        /* PREMISE GUARD. Every assertion above is vacuous against an empty registry. */
        $named = 0;
        foreach (PlatformCapabilities::groupNames() as $group) {
            foreach (PlatformCapabilities::declared($group) as $entry) {
                if ($entry['module'] !== null) {
                    $named++;
                }
            }
        }

        $this->assertGreaterThanOrEqual(20, $named,
            'PREMISE: the registry names too few modules for the check above to mean anything.');
        $this->assertCount(4, PlatformCapabilities::groupNames());
    }

    #[Test]
    public function a_claim_is_dropped_when_its_module_is_not_installed(): void
    {
        /* The filter is the point, so it is exercised directly rather than inferred. */
        $this->assertFalse(PlatformCapabilities::moduleIsInstalled('ThisModuleDoesNotExist'));
        $this->assertTrue(PlatformCapabilities::moduleIsInstalled('ProductReview'));

        $declared = PlatformCapabilities::declared('storefront');
        $available = PlatformCapabilities::available('storefront');

        $this->assertNotEmpty($available);
        $this->assertLessThanOrEqual(count($declared), count($available));

        /* Every surviving entry is either first-party or a module that really is on disk. */
        foreach ($available as $entry) {
            if ($entry['module'] !== null) {
                $this->assertDirectoryExists(base_path('packages/workdo/' . $entry['module']));
            }
        }
    }

    #[Test]
    public function a_module_name_cannot_walk_out_of_the_packages_directory(): void
    {
        /* The registry is a constant today, so this is not reachable with caller input. It is
           asserted so that it stays true if a later caller is less careful. */
        $this->assertFalse(PlatformCapabilities::moduleIsInstalled('../../app'));
        $this->assertFalse(PlatformCapabilities::moduleIsInstalled('Api/../../../app'));
        $this->assertFalse(PlatformCapabilities::moduleIsInstalled(''));
        $this->assertFalse(PlatformCapabilities::moduleIsInstalled('Product Review'));
    }

    #[Test]
    public function an_unknown_group_renders_nothing_rather_than_throwing(): void
    {
        $this->assertSame([], PlatformCapabilities::available('no-such-group'));
        $this->assertSame([], PlatformCapabilities::declared('no-such-group'));
    }

    #[Test]
    public function the_theme_list_is_the_themes_directory(): void
    {
        $onDisk = array_map('basename', glob(base_path('themes') . '/*', GLOB_ONLYDIR) ?: []);
        sort($onDisk);

        $this->assertSame($onDisk, PlatformCapabilities::themes());
        $this->assertNotEmpty($onDisk, 'PREMISE: no themes on disk, so this would pass vacuously.');
        $this->assertContains('stylique', PlatformCapabilities::themes());
    }

    #[Test]
    public function minus_one_is_unlimited_and_zero_is_a_real_limit_of_zero(): void
    {
        $plan = new Plan([
            'max_stores'   => '-1',
            'max_products' => '0',
            'max_users'    => '25',
            'storage_limit' => '1024',
        ]);

        $by = collect(PlatformCapabilities::planHighlights($plan))->keyBy('label');

        $this->assertSame('Unlimited', $by['Stores included']['value']);
        $this->assertSame('0', $by['Products']['value']);
        $this->assertSame('25', $by['Staff accounts']['value']);
        $this->assertSame('1,024', $by['Storage (MB)']['value']);
    }

    #[Test]
    public function flags_report_on_off_and_unconfigured_as_three_different_answers(): void
    {
        $plan = new Plan([
            'enable_domain'    => 'on',
            'enable_subdomain' => 'off',
            'pwa_store'        => '',
            'shipping_method'  => 'ON',
        ]);

        $by = collect(PlatformCapabilities::planHighlights($plan))->keyBy('label');

        $this->assertSame('yes', $by['Custom domain']['state']);
        $this->assertSame('no', $by['Subdomain']['state']);
        /* `''` and `'off'` must NOT collapse: "not configured" and "not included" are different
           answers to somebody deciding whether to buy. */
        $this->assertSame('unknown', $by['Installable store app (PWA)']['state']);
        /* Case is not meaningful in the column. */
        $this->assertSame('yes', $by['Shipping methods']['state']);
    }

    #[Test]
    public function an_absent_limit_is_unknown_rather_than_zero(): void
    {
        $plan = new Plan(['max_products' => '']);

        $by = collect(PlatformCapabilities::planHighlights($plan))->keyBy('label');

        $this->assertSame('unknown', $by['Products']['state']);
        $this->assertSame('', $by['Products']['value']);
    }

    #[Test]
    public function every_claim_carries_a_title_and_a_body(): void
    {
        foreach (PlatformCapabilities::groupNames() as $group) {
            foreach (PlatformCapabilities::declared($group) as $entry) {
                $this->assertArrayHasKey('title', $entry);
                $this->assertArrayHasKey('body', $entry);
                $this->assertNotSame('', trim($entry['title']));
                $this->assertNotSame('', trim($entry['body']));
            }
        }
    }
}
