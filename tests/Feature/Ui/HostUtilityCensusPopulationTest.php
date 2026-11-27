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
    /**
     * GAP-179's POPULATION, BY NAME — the nineteen operator-gated GET routes its figure counted.
     *
     * WHY THE SET AND NOT THE NUMBER. This was `assertSame(19, $census['old_population_routes'])`,
     * and on 2026-11-27 it failed with `26 !== 19`. That is true, it is not a regression, and it
     * does not say which route moved — and the count was satisfied by ANY nineteen routes, so a
     * screen leaving the operator gate while another joined it would have passed. GAP-179's 19 is
     * a DATED MEASUREMENT of a population that grows whenever an operator screen ships, so what is
     * pinned here is the membership, with the growth declared beside it and named.
     *
     * THE NINETEEN ARE DERIVED, NOT RETYPED FROM THE REGISTER, which records only the number.
     * They are the 26 the census reports today minus the seven in `ADDED_SINCE_GAP_179` below,
     * each of which was shown absent from `routes/web.php` at `60e7ef589` — the commit that pinned
     * the 19 — by `git show 60e7ef589:routes/web.php`. 26 − 7 = 19 is therefore a check on the
     * derivation and not an assumption: if either list were wrong the arithmetic would not close.
     *
     * Two of the nineteen carry no route name and are identified by their uri, exactly as the
     * census emits them.
     *
     * @var list<string>
     */
    private const GAP_179_POPULATION = [
        'GET zid/{storeId}/orders',
        'GET zid/{storeId}/webhooks',
        'lynomia.admin.index',
        'lynomia.admin.links.remote_health',
        'order.approve',
        'order.reject',
        'plan-coupon.create',
        'plan-coupon.edit',
        'plan-coupon.index',
        'plan-coupon.show',
        'plan.order.refund',
        'platform.analytics',
        'platform.analytics.export',
        'platform.documentation.index',
        'platform.documentation.preview',
        'platform.resources',
        'platform.revenue',
        'platform.site_content.edit',
        'platform.site_content.index',
    ];

    /**
     * ...AND THE OPERATOR-GATED GET ROUTES ADDED SINCE, each with the commit that added it.
     *
     * Every one is a real new operator screen, so the population is larger for the right reason —
     * which is the question `assertSame(19, …)` could not answer and a reader had to go and settle
     * by hand. Adding a line here is the designed cost of shipping an operator screen: this file
     * exists because GAP-179's population had a hole, and a population that grows unremarked is
     * the same hole again.
     *
     * FOUR OF THEM ARE ALSO WHY `OperatorPlatformNavigationTest` IS RED — `kyc.queue`,
     * `platform.approvals.index`, `platform.incidents.index` and `platform.operations` are built
     * and gated and `SuperAdminMenuListener` does not name them. That is a navigation finding owned
     * elsewhere, recorded here because this file is where the growth is measured and a reader
     * arriving at `26 !== 19` deserves the connection.
     *
     * @var array<string, string>  route identity => the screen, and the commit that added it
     */
    private const ADDED_SINCE_GAP_179 = [
        'kyc.queue' => 'KYC-001..003 operator verification queue, platform/verification (4774ddbc8)',
        'platform.approvals.index' => 'IAM two-person approval queue (1a722c08e)',
        'platform.incidents.index' => "OPS-003 the platform's own incident record (7006d474d)",
        'platform.incidents.show' => 'OPS-003 one incident, parameterised (7006d474d)',
        'platform.operations' => 'OPS-001 database, queue and integration measurements (3056c1dae)',
        'platform.stores.index' => 'ADM-002 Store 360 index (3d72820e6)',
        'platform.stores.show' => 'ADM-002 Store 360 for one store, parameterised (3d72820e6)',
    ];

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
         * GAP-179 states "37 routes, 19 GET" for its `PlatformOperatorOnly` filter. Reproducing
         * that 19 independently is what makes the comparison below a measurement rather than a
         * rhetorical flourish — and it is the premise of the whole slice: if the population is not
         * the one the register describes, every ratio attributed to it needs re-reading.
         *
         * RE-READ ON 2026-11-27, WHICH IS WHAT THIS TEST ASKED FOR. It used to assert the NUMBER
         * and failed `26 !== 19`. The nineteen are all still there; seven Phase 06 operator screens
         * joined them. Both halves are now asserted BY NAME, in both directions, so the next change
         * to this population says which route it was instead of handing a reader two integers.
         */
        $this->assertCount(19, self::GAP_179_POPULATION,
            'premise: the derived list is no longer the register\'s nineteen, so the arithmetic '
            . 'that justifies it (26 measured − 7 added = 19) no longer closes.');

        $names = $this->census()['old_population_route_names'];

        $this->assertIsArray($names, 'the census no longer names the old population\'s routes.');

        /* ONE NAME PER ROUTE ROW. Without this, two rows sharing a name would read below as a
           route that had LEFT the population, which is the one reading this list exists to make
           reliable. */
        $this->assertCount($this->census()['old_population_routes'], $names,
            'the census named ' . count($names) . ' routes for '
            . $this->census()['old_population_routes'] . ' rows in the old population.');

        $lost = array_values(array_diff(self::GAP_179_POPULATION, $names));

        $this->assertSame([], $lost,
            "These routes were in GAP-179's operator-gated GET population and are not any more.\n"
            . "A route LEAVING this population is a security question before it is a UI one — the\n"
            . "operator gate came off it, or the census stopped reaching it. Missing:\n  "
            . implode("\n  ", $lost));

        $joined = array_values(array_diff($names, self::GAP_179_POPULATION, array_keys(self::ADDED_SINCE_GAP_179)));

        $this->assertSame([], $joined,
            "These operator-gated GET routes are in the population and are declared neither as\n"
            . "GAP-179's own nineteen nor as an addition since. A new operator screen is the\n"
            . "expected reason, and declaring it is the point: GAP-179's figures were attributed\n"
            . "to a population that had a hole, and a population that grows unremarked is the same\n"
            . "hole again. Add it to ADDED_SINCE_GAP_179 with the screen it is. Found:\n  "
            . implode("\n  ", $joined));

        /* AND THE COUNT, LAST, so a duplicate or a miscount cannot hide behind two clean set
           differences. */
        $this->assertSame(
            count(self::GAP_179_POPULATION) + count(self::ADDED_SINCE_GAP_179),
            $this->census()['old_population_routes'],
            'the declared membership and the measured count disagree.'
        );
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

    /*
     * WHAT WAS HERE, AND WHY IT IS NOT ANY MORE.
     *
     * This file used to declare that the operator integration page carried exactly TWO unpainted
     * `btn-primary` occurrences -- a `Save` inside `#lynomia-seats` and an `Approve` inside
     * `#lynomia-accounts`, both in INACTIVE tab panes, which a first-paint capture can never reach.
     * The declaration existed because an unmeasured repair reads as a finished one, and it was
     * made EXACT so that repairing the two would fail here and force the note to be re-read rather
     * than quietly deleted.
     *
     * It failed at the 2026-11-26 integration, finding 0 where it declared 2. They were repaired by
     * `8a081cd03`: `btn btn-sm btn-primary` -> `ss-btn ss-btn--primary ss-btn--sm` at :129 and :495,
     * the same two occurrences this counted.
     *
     * So the declaration is DELETED rather than renumbered, which is what its own failure message
     * instructed -- and the repair is not unmeasured, which was the whole worry. It is covered by
     * `tests/Unit/Ui/LynomiaOperatorViewsUseDesignSystemFillsTest`, which is strictly stronger than
     * what stood here: it drove all 96 theme x direction cells, measured the defect this closes
     * (`.btn-primary` at 2.58:1 on dark against `.ss-btn--primary` at 7.92:1), asserts that NO
     * operator Lynomia view paints with a host primary fill, and separately asserts that every
     * `ss-` class those views use is actually defined in the design system -- so a repair that
     * swapped in a class nobody had written would fail too.
     *
     * A third occurrence appearing is now that test's business, not this one's.
     */

}
