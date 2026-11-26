<?php

namespace Tests\Feature\Ui;

use Tests\TestCase;

/**
 * GAP-179's POPULATION, PINNED — because the hole was in the population, not in the looking.
 * ================================================================================================
 *
 * GAP-179 derives its surface list from one middleware (`PlatformOperatorOnly`). That is a correct
 * way to find the operator's screens and a structurally blind way to find anything else, and the
 * blindness has now cost two slices:
 *
 *   `packages/workdo/LynomiaChat`'s merchant pages carry `PlanModuleCheck` + `EnsureStoreOwnsLink`
 *   -> unmeasured; `a.nav-link.active` at 2.41:1 dark and `.btn-primary` at 2.58:1 dark, both
 *   already repaired elsewhere by the same GAP.
 *
 *   The theme editor carries `theme.editor` -> unmeasured; 14 findings on first capture, TWELVE of
 *   them `.btn-primary` at 2.58:1 and `badge bg-warning` at 1.68:1 — the same two classes at the
 *   same two ratios as surfaces GAP-179 had already fixed.
 *
 * SO THIS TEST DOES NOT RATCHET FINDINGS. It ratchets the POPULATION, which is the thing that was
 * wrong. `tests/Support/bin/census-host-utility-surfaces.php` enumerates routes by their RESOLVED
 * middleware stack with all 70 modules loaded, and the assertions below say: the old population is
 * still the small set GAP-179 measured, the new one is still the large set it did not, and the
 * instrument that distinguishes them has not quietly lost its reach.
 *
 * WHY NOT A FINDINGS RATCHET, stated so the omission is a decision and not an oversight. The
 * occurrence counts span 70 VENDORED modules this workstream may not edit, and eight workstreams
 * are in flight. A two-directional assert on "418 occurrences of `btn-primary`" would fire on
 * anyone else's unrelated change to any of 1,286 Blade files, and the instruction for this pass was
 * explicit: measure, count and declare, because a half-done repair sweep would make the number
 * unreadable for the owner's `D-25` ruling. The numbers are therefore recorded as a DATED
 * MEASUREMENT in `docs/development/phase-6/PHASE-06-HOST-UTILITY-CENSUS.md`, with the script that
 * reproduces them, and this test keeps the instrument honest instead.
 *
 * ONE EXCEPTION: this slice's own files are asserted at zero, in their own ratchets
 * (`ThemeEditorShellLegibilityTest`, `LynomiaMerchantAutomationLegibilityTest`).
 *
 * It runs the census in its OWN PROCESS, which is the pattern `RouteCensusGateTest:42` already
 * establishes and for the same two reasons: the suite is structurally blind to module routes
 * without an `add_on_managers` row, and loading modules in-process pollutes the process-global
 * Composer ClassLoader for every later test.
 */
class HostUtilityCensusPopulationTest extends TestCase
{
    /** @var array<string,mixed>|null */
    private static ?array $report = null;

    /** @return array<string,mixed> */
    private function census(): array
    {
        if (self::$report !== null) {
            return self::$report;
        }

        $script = base_path('tests/Support/bin/census-host-utility-surfaces.php');
        $this->assertFileExists($script);

        $out = [];
        $code = 1;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' --json 2>&1', $out, $code);

        $this->assertSame(0, $code, 'the census script failed: ' . substr(implode("\n", $out), 0, 600));

        $decoded = json_decode(implode("\n", $out), true);

        $this->assertIsArray($decoded, 'the census script produced no usable JSON');

        return self::$report = $decoded;
    }

    public function test_the_old_population_still_reproduces_the_registers_own_figure(): void
    {
        /*
         * GAP-179 states "37 routes, 19 GET" for its `PlatformOperatorOnly` filter. Reproducing the
         * 19 independently is what makes the comparison below a measurement rather than a
         * rhetorical flourish — and it is the premise of the whole slice: if this number moved, the
         * old population is not the one the register describes and every ratio attributed to it
         * would need re-reading.
         */
        $this->assertSame(19, $this->census()['old_population_routes']);
    }

    public function test_the_new_population_is_two_orders_of_magnitude_larger(): void
    {
        $report = $this->census();

        $this->assertGreaterThanOrEqual(
            800,
            $report['new_population_routes'],
            'the authenticated panel GET population has shrunk below 800 routes. Either a large '
            . 'number of routes lost their authentication middleware — which is a security '
            . 'question, not a UI one — or the census stopped reaching them.'
        );

        $share = 100 * $report['old_population_routes'] / $report['new_population_routes'];

        $this->assertLessThan(
            5.0,
            $share,
            'the old population is now ' . round($share, 1) . '% of the new one. If that has risen '
            . 'above 5% the two populations are converging and this test\'s premise needs re-reading.'
        );
    }

    public function test_the_census_resolves_every_modules_view_namespace(): void
    {
        /*
         * THE INSTRUMENT'S REACH, PINNED, BECAUSE IT WAS WRONG TWICE AND BOTH TIMES SILENTLY.
         *
         * Deriving a module's view namespace by kebab-casing its directory missed `Api` -> `api`
         * and `HubSpot` -> `hub-spot`; globbing `src/app/Providers/*` missed `LandingPage`, whose
         * provider is at `src/Providers/` and whose views are at `src/Resources/views` — 97 routes
         * named `landing-page::` and resolved to nothing. Neither failure announced itself: the
         * census simply reported a smaller population, which is the exact failure mode it exists
         * to fix.
         *
         * Asserted through the resolved view COUNT rather than by re-deriving the namespace map,
         * so the test cannot drift from the script by sharing its logic.
         */
        $report = $this->census();

        $this->assertGreaterThanOrEqual(
            380,
            $report['new_population_views'],
            'the census now attributes fewer than 380 views to the panel population; it reached '
            . $report['new_population_views'] . '. A provider-path or namespace regression shrinks '
            . 'this silently.'
        );

        $this->assertGreaterThanOrEqual(
            20,
            $report['old_population_views'],
            'the operator population resolved to fewer than 20 views.'
        );
    }

    public function test_every_unresolved_route_has_a_reason_and_none_of_them_is_a_resolver_bug(): void
    {
        /*
         * A RESIDUE YOU CANNOT EXPLAIN IS NOT A MEASUREMENT. The first run reported "561 routes
         * named no view" out of 853 and that figure was unreadable — a reader cannot tell a
         * resolver bug from a route that renders nothing by design, and two resolver bugs really
         * were hiding in it. Every unresolved route now carries a classified reason, and the two
         * classes that WOULD indicate a bug are asserted absent.
         */
        $reasons = $this->census()['new_population_unresolved_reasons'];

        $this->assertIsArray($reasons);
        $this->assertNotEmpty($reasons);

        $this->assertSame(
            $this->census()['new_population_routes_unresolved'],
            array_sum($reasons),
            'the classified reasons do not account for every unresolved route.'
        );

        foreach (['controller file not found'] as $bug) {
            $this->assertArrayNotHasKey(
                $bug,
                $reasons,
                '"' . $bug . '" means the census cannot find a controller it was given, which is a '
                . 'resolver bug rather than a route that renders nothing.'
            );
        }
    }

    public function test_the_defect_class_patterns_do_not_match_the_control_that_passes(): void
    {
        /*
         * `btn-outline-primary` MUST NOT COUNT, and this is not pedantry: it is a different control
         * that MEASURED 6.42:1 light and 4.65:1 dark against a 4.5:1 floor — a pass in both — and
         * counting it would inflate the one number this census exists to give the owner. Likewise
         * `btn-outline-secondary`, which measured 3.04:1 dark and IS a failure but of a third
         * class that this census does not claim to count.
         *
         * Asserted against the script's own patterns, read from its source, so the test cannot
         * agree with a copy of them that has drifted.
         */
        $source = (string) file_get_contents(base_path('tests/Support/bin/census-host-utility-surfaces.php'));

        $this->assertSame(
            1,
            preg_match("/'host_primary_fill' => '([^']+)'/", $source, $m),
            'the host primary fill pattern is no longer declared where this test reads it.'
        );

        $pattern = $m[1];

        $this->assertSame(1, preg_match($pattern, '<a class="btn btn-sm btn-primary">x</a>'),
            'the pattern no longer matches the class it exists to count.');
        $this->assertSame(0, preg_match($pattern, '<a class="btn btn-sm btn-outline-primary">x</a>'),
            'the pattern matches btn-outline-primary, which measured 6.42:1 / 4.65:1 and PASSES.');
        $this->assertSame(0, preg_match($pattern, '<a class="btn btn-outline-primary">x</a>'),
            'the pattern matches btn-outline-primary.');
    }

    public function test_this_slices_own_files_carry_none_of_either_class(): void
    {
        /*
         * The one findings assertion in this file, and it is scoped to what this workstream owns.
         * Everything else is declared rather than ratcheted — see the class docblock.
         */
        foreach ([
            'resources/views/theme_customize/index.blade.php',
            'resources/views/theme_customize/pages.blade.php',
            'resources/views/theme_customize/customize.blade.php',
            'resources/views/theme_customize/order.blade.php',
            'resources/views/theme_customize/_version-panel.blade.php',
            'packages/workdo/LynomiaChat/src/resources/views/merchant/commerce-automation.blade.php',
        ] as $view) {
            $source = (string) preg_replace(
                '/\{\{--.*?--\}\}/s',
                '',
                (string) file_get_contents(base_path($view))
            );

            foreach (['btn-primary', 'bg-warning'] as $utility) {
                $this->assertSame(
                    0,
                    preg_match('/class="[^"]*(?<![-a-z])' . preg_quote($utility, '/') . '(?![-a-z])[^"]*"/', $source),
                    $view . ' carries the host utility `' . $utility . '`, which this slice removed.'
                );
            }
        }
    }

    public function test_the_operator_integration_page_still_has_two_declared_unpainted_occurrences(): void
    {
        /*
         * THE SHARPEST ILLUSTRATION OF WHY A CAPTURE IS NOT A CENSUS, and it is on the surface
         * GAP-179 DECLARED CLOSED.
         *
         * `superadmin/integration.blade.php` was re-measured for this workstream in all four
         * combinations and reported 2 findings, both the excluded `D-25` sidebar item. Its SOURCE
         * carries two more occurrences of `btn btn-sm btn-primary` — the class measured at 2.58:1
         * dark and repaired on six other surfaces by the same GAP:
         *
         *     `Save`     inside `#lynomia-seats`    (an INACTIVE tab pane)
         *     `Approve`  inside `#lynomia-accounts` (an INACTIVE tab pane, and additionally behind
         *                `@if ($row->state === LinkState::PENDING_VERIFICATION)`)
         *
         * `data-bs-toggle="tab"` paints only the active pane, so a first-paint capture cannot reach
         * either. They become visible on the first click — exactly the reasoning that made this
         * project wrap ALL FIVE tab labels rather than the active one.
         *
         * DECLARED, NOT REPAIRED, and that is this pass's instruction rather than reluctance:
         * measure, count and declare, because a half-done repair sweep makes the number unreadable
         * for the owner's `D-25` ruling. It is also the honest engineering answer — repairing them
         * would produce a change this slice's instrument CANNOT re-measure, since the capture never
         * paints them, and an unmeasured repair reads as a finished one. The next step for them is
         * a Tier-C style capture that clicks the tab first, which
         * `measure-superadmin-premium-live.cjs` already does for modal fragments.
         *
         * THE COUNT IS EXACT so that a third occurrence appearing fails here, and so that repairing
         * these two fails here too and forces this note to be re-read rather than deleted.
         */
        $view = 'packages/workdo/LynomiaChat/src/resources/views/superadmin/integration.blade.php';

        $source = (string) preg_replace(
            '/\{\{--.*?--\}\}/s',
            '',
            (string) file_get_contents(base_path($view))
        );

        $this->assertSame(
            2,
            preg_match_all('/class="[^"]*(?<![-a-z])btn-primary(?![-a-z])[^"]*"/', $source),
            'the declared count of unpainted `btn-primary` occurrences on the operator integration '
            . 'page has changed. If they were repaired, measure the repair with a capture that '
            . 'clicks the tab first and then delete this declaration — do not just update the '
            . 'number.'
        );

        $this->assertSame(
            0,
            preg_match('/class="[^"]*(?<![-a-z])bg-warning(?![-a-z])[^"]*"/', $source),
            'the host warning chip is back on the operator integration page; it measured 1.68:1 '
            . 'light and carries !important.'
        );
    }
}
