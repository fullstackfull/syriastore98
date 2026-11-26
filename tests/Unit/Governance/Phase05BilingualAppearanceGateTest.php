<?php

namespace Tests\Unit\Governance;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * P05-16 / GAP-164 — AR/EN AND LIGHT/DARK AS A PER-SLICE ACCEPTANCE CONDITION FOR PHASE 05.
 * =================================================================================================
 *
 * THE OWNER'S ACTION, VERBATIM: *"Make AR/EN plus light/dark a per-slice acceptance condition at
 * P5-4, as Phase 03 M7 did for the storefront."* So the deliverable is not a feature. It is a
 * condition that cannot be quietly skipped, which means a test — Phase 03 proved the point twice
 * over. M7 found three defects (a public site following the merchant admin's dark setting, 15
 * brand-text contrast nodes, a mis-placed landmark) that were ALL INVISIBLE IN THE SOURCE, and
 * `StorefrontThemeCoverageTest` is the ratchet that keeps "every shipped theme is covered" true
 * without anyone remembering to re-check it: it reads the FILESYSTEM and the HARNESS, so a fourth
 * theme fails until the harness knows about it.
 *
 * This file is that ratchet for Phase 05, over three documents and the code:
 *
 *   docs/development/phase-5/PHASE-05-BILINGUAL-APPEARANCE-GATE.md   the register (four tables)
 *   docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md              the slices, §2 and §4.9
 *   docs/development/phase-5/evidence/<dir>/render-matrix.json       the capture records
 *
 * WHY A CODE CENSUS AND NOT A LIST OF SCREENS. A register of surfaces maintained by hand is only
 * as good as the memory of whoever adds the next screen, which is the habit this replaces. The
 * census arm binds the register to something a new surface CANNOT avoid touching: Phase 05's own
 * plan forbids a controller computing money inline (§1, "No controller computes money inline"), so
 * every Phase 05 figure reaches a screen through `App\Support\Metrics\`. The gate therefore counts
 * the references to that namespace in every first-party file outside the layer, and requires the
 * register to account for each file AND to carry its exact reference count. Adding a surface, or
 * re-pointing an existing one at the metric layer, changes a number — and a changed number is a
 * red test with a message naming the file.
 *
 * WHAT IT DELIBERATELY DOES NOT CLAIM, because a gate that overstates its reach is worse than one
 * that states its limits:
 *
 *   - It does not look at pixels. The CAPTURE does that, and the capture's own record is what this
 *     reads. A premise line can be typed by a human just as a JSON file can; what the gate makes
 *     impossible is the ABSENCE of a record, not its forgery.
 *   - A view that receives metric values as plain variables never names the namespace and is
 *     invisible to the census. It is reached through its CONTROLLER, which does name it, and the
 *     register's `feeds` column is the human-written bridge from that controller to its screens.
 *     Measured examples of exactly this: `superadmin/dashboard.blade.php` and
 *     `reports/order_report.blade.php` mention the metric layer ONLY IN COMMENTS and are not
 *     censused — which is the comment trap working correctly, and the reason the bridge exists.
 *   - It says nothing about whether the Arabic reads like Arabic. That is human review, as M7 §7
 *     records for the storefront.
 *
 * COMMENTS ARE STRIPPED THROUGH THE SHARED TRAIT, and here it is load-bearing in BOTH directions,
 * measured on this tree: `app/Http/Controllers/Api/DashboardController.php` names
 * `App\Support\Metrics\MetricResult` in a `@param` docblock and nowhere in its code, so a census
 * reading comments would demand a register row for a file that reads no metric; and
 * `resources/views/reports/order_report.blade.php` names `App\Support\Metrics\FigureCsv` in a Blade
 * comment explaining a choice. CLAUDE.md records eight occasions on which a census was satisfied —
 * or broken — by prose about the thing it measures. These are the ninth and tenth.
 */
class Phase05BilingualAppearanceGateTest extends TestCase
{
    use StripsSourceComments;

    private const REGISTER = 'docs/development/phase-5/PHASE-05-BILINGUAL-APPEARANCE-GATE.md';
    private const PLAN = 'docs/development/phase-5/PHASE-05-EXECUTION-PLAN.md';

    /** The namespace every Phase 05 figure passes through. */
    private const LAYER = 'App\\Support\\Metrics\\';

    /** The four combinations, and the direction each one must render in. */
    private const COMBINATIONS = [
        ['lang' => 'en', 'dir' => 'ltr', 'theme' => 'light'],
        ['lang' => 'en', 'dir' => 'ltr', 'theme' => 'dark'],
        ['lang' => 'ar', 'dir' => 'rtl', 'theme' => 'light'],
        ['lang' => 'ar', 'dir' => 'rtl', 'theme' => 'dark'],
    ];

    /**
     * The only statuses a surface row may carry.
     *
     * `BLOCKED:` takes a reason from the second list. Both are CLOSED vocabularies pinned here
     * rather than in the document, so widening either is a change to a test file that a reviewer
     * reads — the lesson of `AnalyticsRouteAuthorizationCensusTest`, whose first proposed remedy
     * could be made green again by weakening it.
     */
    private const STATUSES = ['CAPTURED', 'BLOCKED'];

    private const BLOCKERS = [
        // The measured Phase 2.5 blocker: raw MONTH()/YEAR()/SUBSTRING_INDEX, absent from SQLite.
        'SQLITE-MYSQL-ONLY-SQL',
        // The surface renders, but the dev fixture holds no row that would populate it.
        'NO-DEV-FIXTURE-DATA',
        // The surface is not built because an owner decision is outstanding.
        'OWNER-DECISION-PENDING',
    ];

    private const SLICE_APPEARANCE = ['SURFACES-IN-TABLE-B', 'NO-RENDERED-SURFACE'];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function path(string $relative): string
    {
        return self::root() . '/' . $relative;
    }

    /* ======================================================== reading the register's four tables */

    /**
     * The rows of one marked table, as lists of trimmed cells.
     *
     * SCOPED BETWEEN HTML COMMENT MARKERS, not matched across the whole document. An assertion
     * over a whole file answers "does this string exist anywhere" — CLAUDE.md's measured example
     * is a mutation that survived because another component carried the same rule. A marker pair
     * is also invisible in rendered Markdown, so the document reads normally.
     *
     * @return list<list<string>>
     */
    private function table(string $name): array
    {
        $doc = (string) file_get_contents($this->path(self::REGISTER));

        $open = "<!-- GATE-TABLE: {$name} -->";
        $close = '<!-- /GATE-TABLE -->';

        $from = strpos($doc, $open);

        $this->assertNotFalse($from, sprintf(
            '%s carries no `%s` marker, so the %s table cannot be read. The gate reads tables '
            . 'between `%s` and `%s` markers; prose outside them is not data.',
            self::REGISTER, $open, $name, $open, $close
        ));

        $to = strpos($doc, $close, $from);
        $this->assertNotFalse($to, "The {$name} table is not closed with `{$close}`.");

        $rows = [];

        foreach (explode("\n", substr($doc, $from + strlen($open), $to - $from - strlen($open))) as $line) {
            $line = trim($line);

            if (! str_starts_with($line, '|') || ! str_ends_with($line, '|')) {
                continue;
            }

            $cells = array_map('trim', explode('|', trim($line, '|')));

            // The header row and the `|---|` separator are not data.
            if ($cells === [] || preg_match('/^:?-{3,}:?$/', $cells[0]) === 1) {
                continue;
            }

            // A header is recognised by its first cell being a bare word this schema uses.
            if (in_array(strtolower($cells[0]), ['slice', 'surface', 'file', 'view'], true)) {
                continue;
            }

            $rows[] = $cells;
        }

        return $rows;
    }

    /** `` `x` `` -> `x`; anything not backticked is returned as it stands. */
    private function unquote(string $cell): string
    {
        return trim($cell, '` ');
    }

    /** @return array<string, array{surfaces: list<string>, appearance: string}> slice id => row */
    private function sliceRows(): array
    {
        $out = [];

        foreach ($this->table('SLICES') as $cells) {
            $this->assertGreaterThanOrEqual(4, count($cells),
                'A SLICES row needs four cells: slice | surfaces | appearance | note. Got: '
                . implode(' | ', $cells));

            $id = $this->unquote($cells[0]);

            preg_match_all('/`([a-z0-9-]+)`/', $cells[1], $named);

            $out[$id] = [
                'surfaces' => $named[1],
                'appearance' => $this->unquote($cells[2]),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, array{route: string, view: string, principal: string, marker: string,
     *                             status: string, evidence: string}>
     */
    private function surfaceRows(): array
    {
        $out = [];

        foreach ($this->table('SURFACES') as $cells) {
            $this->assertGreaterThanOrEqual(7, count($cells),
                'A SURFACES row needs seven cells: surface | route | view | principal | marker | '
                . 'status | evidence. Got: ' . implode(' | ', $cells));

            $out[$this->unquote($cells[0])] = [
                'route' => $this->unquote($cells[1]),
                'view' => $this->unquote($cells[2]),
                'principal' => $this->unquote($cells[3]),
                'marker' => $this->unquote($cells[4]),
                'status' => $this->unquote($cells[5]),
                'evidence' => $this->unquote($cells[6]),
            ];
        }

        return $out;
    }

    /** @return array<string, int> repo-relative file => the reference count the register declares */
    private function declaredReaders(): array
    {
        $out = [];

        foreach ($this->table('READERS') as $cells) {
            $this->assertGreaterThanOrEqual(3, count($cells),
                'A READERS row needs three cells: file | references | feeds. Got: '
                . implode(' | ', $cells));

            $out[$this->unquote($cells[0])] = (int) $this->unquote($cells[1]);
        }

        return $out;
    }

    /** @return array<string, array<string, int>> view => (physical-direction hit => count) */
    private function declaredDirectionExceptions(): array
    {
        $out = [];

        foreach ($this->table('DIRECTION-EXCEPTIONS') as $cells) {
            $this->assertGreaterThanOrEqual(4, count($cells),
                'A DIRECTION-EXCEPTIONS row needs four cells: view | hit | count | reason. Got: '
                . implode(' | ', $cells));

            $out[$this->unquote($cells[0])][strtolower($this->unquote($cells[1]))] =
                (int) $this->unquote($cells[2]);
        }

        return $out;
    }

    /** The declared ceiling on BLOCKED surface rows, read from its own marked line. */
    private function blockedCeiling(): int
    {
        $doc = (string) file_get_contents($this->path(self::REGISTER));

        $this->assertSame(1, preg_match('/<!-- GATE-BLOCKED-CEILING: (\d+) -->/', $doc, $m),
            self::REGISTER . ' carries no `<!-- GATE-BLOCKED-CEILING: n -->` line. The ceiling is '
            . 'asserted as an EXACT figure in both directions, so it has to be declared.');

        return (int) $m[1];
    }

    /* ================================================================== measuring the code itself */

    /**
     * Every first-party file outside the metric layer whose CODE names the layer, with how often.
     *
     * @return array<string, int>
     */
    private function measuredReaders(): array
    {
        $out = [];

        foreach (['app', 'resources/views'] as $dir) {
            $walk = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->path($dir), \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($walk as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen(self::root()) + 1);

                if (str_starts_with($relative, 'app/Support/Metrics/')) {
                    continue;
                }

                // A cheap raw pre-filter, so the stripper runs on candidates rather than on
                // every file in app/ and resources/views/.
                if (! str_contains((string) file_get_contents($file->getPathname()), self::LAYER)) {
                    continue;
                }

                $count = substr_count($this->sourceWithoutComments($relative), self::LAYER);

                if ($count > 0) {
                    $out[$relative] = $count;
                }
            }
        }

        ksort($out);

        return $out;
    }

    /** @return list<string> the slice ids the execution plan declares, in document order */
    private function planSlices(): array
    {
        preg_match_all(
            '/^### (P5-\d+) /m',
            (string) file_get_contents($this->path(self::PLAN)),
            $m
        );

        return $m[1];
    }

    /** The `__()` keys a Blade view prints, comments stripped. @return list<string> */
    private function keysPrintedBy(string $view): array
    {
        $code = $this->sourceWithoutComments($view);

        $keys = [];

        foreach ([
            "/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/",
            '/__\(\s*"((?:[^"\\\\]|\\\\.)*)"/',
        ] as $pattern) {
            preg_match_all($pattern, $code, $m);

            foreach ($m[1] as $raw) {
                $keys[stripcslashes($raw)] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * Physical-direction constructs in a view's code.
     *
     * LOGICAL PROPERTIES ARE NOT LISTED. `margin-inline-start`, Bootstrap 5's `ms-*`/`me-*` and
     * `text-end` all flip with the document, so they are correct in both directions and are not
     * what this looks for. What it looks for is the handful of constructs that pin a side: a
     * hard-coded `dir`, a physical margin/padding/alignment/float, and Bootstrap 4's `text-left`
     * / `text-right`, which do NOT flip.
     *
     * @return array<string, int> lower-cased hit => how many times
     */
    private function physicalDirectionHits(string $view): array
    {
        $code = $this->sourceWithoutComments($view);

        $pattern = '/\b(?:dir\s*=\s*(["\'])(?:ltr|rtl)\1'
            . '|margin-left\s*:|margin-right\s*:|padding-left\s*:|padding-right\s*:'
            . '|text-align\s*:\s*(?:left|right)|float\s*:\s*(?:left|right)'
            . '|text-left\b|text-right\b)/i';

        preg_match_all($pattern, $code, $m);

        $hits = [];

        foreach ($m[0] as $hit) {
            $key = strtolower(preg_replace('/\s+/', '', $hit));
            $hits[$key] = ($hits[$key] ?? 0) + 1;
        }

        return $hits;
    }

    /* ============================================================================== the premises */

    /**
     * THE PREMISE. Every assertion below reads a table, and an empty table satisfies most of them.
     * Three of the four parsers can silently return nothing: a renamed marker, a changed column
     * count, a document that was moved.
     */
    #[Test]
    public function test_the_register_exists_and_its_four_tables_hold_rows(): void
    {
        $this->assertFileExists($this->path(self::REGISTER),
            'The Phase 05 bilingual/appearance register is missing. It IS the acceptance condition '
            . 'GAP-164 asks for; without it this gate measures nothing.');

        $this->assertGreaterThanOrEqual(16, count($this->sliceRows()),
            'The SLICES table holds fewer rows than the execution plan has slices (16 on '
            . '2026-11-26), so the per-slice condition is not actually per-slice.');

        $this->assertGreaterThanOrEqual(6, count($this->surfaceRows()),
            'The SURFACES table holds fewer than the six Phase 05 surfaces measured on 2026-11-26, '
            . 'so something has been dropped rather than dispositioned.');

        $this->assertGreaterThanOrEqual(20, count($this->declaredReaders()),
            'The READERS table holds fewer than the 22 metric-layer readers measured on '
            . '2026-11-26. A shrinking census reads as a clean one.');
    }

    /** And the census must really be reading the code, not an empty directory walk. */
    #[Test]
    public function test_the_census_reads_the_metric_layer_it_claims_to(): void
    {
        $measured = $this->measuredReaders();

        $this->assertGreaterThanOrEqual(20, count($measured), sprintf(
            'Only %d files were measured as naming %s. 22 were measured on 2026-11-26, so the '
            . 'directory walk or the comment stripper has broken and every census assertion below '
            . 'is vacuous.',
            count($measured),
            self::LAYER
        ));

        foreach ([
            'app/Http/Controllers/MerchantAnalyticsController.php',
            'app/Http/Controllers/PlatformAnalyticsController.php',
            'app/Http/Controllers/StoreFinanceController.php',
            'app/Http/Controllers/ReportController.php',
        ] as $anchor) {
            $this->assertArrayHasKey($anchor, $measured,
                "{$anchor} reads the metric layer and the census did not see it.");
        }
    }

    /* ================================================================ the per-slice condition */

    /**
     * THE SLICE RATCHET, AND THE DIRECT ANSWER TO GAP-164's NEXT ACTION. Every slice the execution
     * plan declares must carry a row in the register; every row must name a slice the plan
     * declares. A seventeenth slice is red until somebody writes down what it renders and in which
     * combinations that was verified.
     */
    #[Test]
    public function test_every_slice_in_the_plan_carries_an_appearance_row_and_vice_versa(): void
    {
        $plan = $this->planSlices();
        $register = array_keys($this->sliceRows());

        sort($plan);
        sort($register);

        $this->assertSame($plan, $register, sprintf(
            "The execution plan's slices and the register's appearance rows disagree.\n"
            . "  only in the plan:     %s\n"
            . "  only in the register: %s\n"
            . 'A slice with no row has no AR/EN or light/dark acceptance condition; a row with no '
            . 'slice is a condition against nothing.',
            implode(', ', array_diff($plan, $register)) ?: '(none)',
            implode(', ', array_diff($register, $plan)) ?: '(none)'
        ));
    }

    #[Test]
    public function test_each_slice_either_names_its_surfaces_or_declares_it_renders_none(): void
    {
        $surfaces = $this->surfaceRows();
        $problems = [];

        foreach ($this->sliceRows() as $slice => $row) {
            if (! in_array($row['appearance'], self::SLICE_APPEARANCE, true)) {
                $problems[] = sprintf('%s: appearance `%s` is not one of %s', $slice,
                    $row['appearance'], implode(' / ', self::SLICE_APPEARANCE));

                continue;
            }

            if ($row['appearance'] === 'NO-RENDERED-SURFACE' && $row['surfaces'] !== []) {
                $problems[] = sprintf('%s: declares NO-RENDERED-SURFACE but names %s', $slice,
                    implode(', ', $row['surfaces']));

                continue;
            }

            if ($row['appearance'] === 'SURFACES-IN-TABLE-B' && $row['surfaces'] === []) {
                $problems[] = $slice . ': declares SURFACES-IN-TABLE-B and names no surface';

                continue;
            }

            foreach ($row['surfaces'] as $named) {
                if (! array_key_exists($named, $surfaces)) {
                    $problems[] = sprintf('%s: names surface `%s`, which has no SURFACES row',
                        $slice, $named);
                }
            }
        }

        $this->assertSame([], $problems,
            "The per-slice appearance condition is not satisfied:\n  " . implode("\n  ", $problems));
    }

    /**
     * THE ESCAPE HATCH, CLOSED AS FAR AS A DOCUMENT CAN CLOSE IT.
     *
     * The mutation that found this: change `P5-4`'s row from `SURFACES-IN-TABLE-B | analytics` to
     * `NO-RENDERED-SURFACE | —` and every other assertion in this file stayed GREEN. A slice can
     * declare its way out of the condition — on the very slice the owner's action names. Every
     * other arm here measures CODE or a capture RECORD; "this slice renders nothing" is the one
     * claim with nothing behind it.
     *
     * So it is anchored to the slice's OWN SECTION of the execution plan: if the text between a
     * slice's `###` heading and the next one names a route that Table B registers, the slice may
     * not claim it renders nothing. It must name at least one surface — not necessarily that one,
     * because a slice legitimately mentions a screen it does not own (`P5-5` says its coverage
     * start works "exactly as `/finance` does"), and a rule that demanded the exact set would
     * force inaccurate rows into the register, which is a different way of lying.
     *
     * THE PROSE DEPENDENCE IS DELIBERATE AND ONE-DIRECTIONAL. CLAUDE.md records eight censuses
     * satisfied or broken by prose about the thing they measure; here prose can only make the
     * requirement STRICTER, never excuse a slice from it. What it cannot catch is a slice that
     * renders a screen and never writes its route down — `P5-1`, `P5-3` and `P5-11` all do render
     * one and none of them names a route, so they are declared honestly rather than caught. That
     * residue is recorded in the register's §6.
     */
    #[Test]
    public function test_a_slice_whose_own_text_names_a_registered_route_cannot_claim_it_renders_nothing(): void
    {
        $routes = [];

        foreach ($this->surfaceRows() as $row) {
            $routes[substr($row['route'], strlen('GET '))] = true;
        }

        $this->assertNotEmpty($routes, 'No routes to look for; the SURFACES table read as empty.');

        $plan = (string) file_get_contents($this->path(self::PLAN));

        preg_match_all('/^### (P5-\d+) .*$/m', $plan, $m, PREG_OFFSET_CAPTURE);

        $this->assertNotEmpty($m[0], 'No slice headings were found in the execution plan.');

        $slices = $this->sliceRows();
        $problems = [];

        foreach ($m[0] as $i => $heading) {
            $id = $m[1][$i][0];
            $from = $heading[1];

            /*
             * A SECTION ENDS AT THE NEXT SLICE **OR THE NEXT `##`**, WHICHEVER COMES FIRST.
             * Without the second limb the last slice's section runs to the end of the file and
             * swallows §3, §4 and §5 — so a route named in the verification standard would be
             * attributed to `P5-15`, which renders nothing. Caught while writing §4.9, which is
             * the only reason it is not a latent false positive waiting for the next edit.
             */
            $to = isset($m[0][$i + 1]) ? $m[0][$i + 1][1] : strlen($plan);

            if (preg_match('/^## /m', $plan, $next, PREG_OFFSET_CAPTURE, $from + 1) === 1) {
                $to = min($to, $next[0][1]);
            }

            $section = substr($plan, $from, $to - $from);

            $named = [];

            foreach (array_keys($routes) as $route) {
                // A boundary, so `/finance` is not found inside `/finance-reports`.
                if (preg_match('#' . preg_quote($route, '#') . '(?![a-z0-9/_-])#i', $section) === 1) {
                    $named[] = $route;
                }
            }

            if ($named === []) {
                continue;
            }

            if (($slices[$id]['appearance'] ?? '') === 'NO-RENDERED-SURFACE') {
                $problems[] = sprintf('%s: its own section names %s, so it cannot declare '
                    . 'NO-RENDERED-SURFACE. Name the surface(s) it puts a figure on, and say in '
                    . 'the note how it relates to them.', $id, implode(', ', $named));
            }
        }

        $this->assertSame([], $problems,
            "Slices declaring they render nothing while writing about a registered screen:\n  "
            . implode("\n  ", $problems));
    }

    /* ======================================================================== the surface rows */

    #[Test]
    public function test_every_surface_row_is_well_formed(): void
    {
        $problems = [];

        foreach ($this->surfaceRows() as $surface => $row) {
            $status = explode(':', $row['status'])[0];

            if (! in_array($status, self::STATUSES, true)) {
                $problems[] = sprintf('%s: status `%s` is not one of %s', $surface, $row['status'],
                    implode(' / ', self::STATUSES));
            }

            if ($status === 'BLOCKED') {
                $reason = substr($row['status'], strlen('BLOCKED:'));

                if (! in_array($reason, self::BLOCKERS, true)) {
                    $problems[] = sprintf('%s: blocker `%s` is not one of %s — a new blocker is a '
                        . 'change to this test, so a reviewer reads it', $surface, $reason,
                        implode(' / ', self::BLOCKERS));
                }
            }

            if (! str_starts_with($row['route'], 'GET /')) {
                $problems[] = sprintf('%s: route `%s` is not a `GET /…` path. A surface is '
                    . 'something a person looks at.', $surface, $row['route']);
            }

            if (! is_file($this->path($row['view']))) {
                $problems[] = sprintf('%s: view `%s` does not exist', $surface, $row['view']);
            }

            if ($row['marker'] === '' || $row['principal'] === '') {
                $problems[] = $surface . ': the principal and the English content marker are both '
                    . 'required — the marker is what proves the capture photographed this screen '
                    . 'and not the one a permission refusal redirected to.';
            }
        }

        $this->assertSame([], $problems, "Malformed surface rows:\n  " . implode("\n  ", $problems));
    }

    /**
     * THE CENTRAL ASSERTION: A `CAPTURED` ROW MUST HAVE FOUR COMBINATIONS OF REAL EVIDENCE.
     *
     * Everything checked here is a way the Phase 2.5 and Phase 05 capture runs have ACTUALLY
     * lied in this repository:
     *
     *   - four files, one per combination — a missing combination is the whole defect;
     *   - four DISTINCT screenshots. The first P5-R8 run produced the operator's four images
     *     byte-identical in light/dark pairs: four pictures about to be committed as eight states.
     *   - HTTP 200 and no premise failure, from the harness's own record. Four of the five ways a
     *     capture lies answer HTTP 200 (M7 §3), so the harness records the landed path, the
     *     rendered direction and the stylesheet, and a row carrying a premise failure is not
     *     evidence.
     *   - the observed direction matches the language. 22 Arabic loads once came back `dir="ltr"`
     *     because a raw `LANGUAGE` cookie fails `EncryptCookies` and is discarded (M7 §5).
     *   - four DISTINCT stylesheets. On this admin the sheet is a four-way discriminator
     *     (`style.css`, `style-dark.css`, `style-rtl.css`, `rtl-style-dark.css`), so one sheet
     *     serving two combinations means the run never left the first one.
     *   - the English heading equals the register's declared marker, and the ARABIC heading is
     *     present and DIFFERENT. That last one is the bilingual check proper: a screen whose
     *     heading is identical in both languages is not translated, and it photographs perfectly.
     */
    #[Test]
    public function test_every_captured_surface_carries_four_combinations_of_evidence(): void
    {
        $problems = [];

        foreach ($this->surfaceRows() as $surface => $row) {
            if ($row['status'] !== 'CAPTURED') {
                continue;
            }

            $dir = rtrim($row['evidence'], '/');
            $record = $this->path($dir . '/render-matrix.json');

            if (! is_file($record)) {
                $problems[] = sprintf('%s: no capture record at %s/render-matrix.json. Produce one '
                    . 'with docs/development/phase-2.5/tools/render-matrix.cjs — the register says '
                    . 'how.', $surface, $dir);

                continue;
            }

            $rows = json_decode((string) file_get_contents($record), true);

            if (! is_array($rows)) {
                $problems[] = $surface . ': ' . $dir . '/render-matrix.json is not readable as JSON.';

                continue;
            }

            $route = substr($row['route'], strlen('GET '));
            $mine = array_values(array_filter($rows,
                fn ($r): bool => is_array($r) && ($r['path'] ?? null) === $route));

            $sheets = [];
            $shots = [];

            foreach (self::COMBINATIONS as $combo) {
                $found = array_values(array_filter($mine, fn (array $r): bool =>
                    ($r['lang'] ?? null) === $combo['lang'] && ($r['theme'] ?? null) === $combo['theme']));

                if (count($found) !== 1) {
                    $problems[] = sprintf('%s: %s/render-matrix.json holds %d rows for %s in '
                        . '%s/%s, not 1.', $surface, $dir, count($found), $route, $combo['lang'],
                        $combo['theme']);

                    continue;
                }

                $r = $found[0];
                $where = sprintf('%s [%s/%s]', $surface, $combo['lang'], $combo['theme']);

                if (($r['status'] ?? null) !== 200) {
                    $problems[] = $where . ': HTTP ' . var_export($r['status'] ?? null, true)
                        . ', so nothing was photographed.';
                }

                if (($r['premiseFail'] ?? []) !== []) {
                    $problems[] = $where . ': premise failed — '
                        . implode('; ', (array) $r['premiseFail']);
                }

                if (($r['consoleErrors'] ?? []) !== []) {
                    $problems[] = $where . ': console errors — '
                        . implode('; ', (array) $r['consoleErrors']);
                }

                if (($r['dir'] ?? null) !== $combo['dir']) {
                    $problems[] = sprintf('%s: rendered dir=%s, wanted %s.', $where,
                        var_export($r['dir'] ?? null, true), $combo['dir']);
                }

                $h1 = trim((string) ($r['h1'] ?? ''));

                if ($combo['lang'] === 'en' && $h1 !== $row['marker']) {
                    $problems[] = sprintf('%s: heading "%s" is not the declared marker "%s" — this '
                        . 'capture is of a different screen.', $where, $h1, $row['marker']);
                }

                if ($combo['lang'] === 'ar' && ($h1 === '' || $h1 === $row['marker'])) {
                    $problems[] = sprintf('%s: Arabic heading is "%s". An Arabic page whose heading '
                        . 'is empty, or identical to the English one, is not translated.',
                        $where, $h1);
                }

                $sheets[] = (string) ($r['sheet'] ?? '');

                $shot = $this->path($dir . '/' . $this->shotName($route, $combo));

                if (! is_file($shot)) {
                    $problems[] = $where . ': no screenshot at ' . $dir . '/'
                        . $this->shotName($route, $combo);

                    continue;
                }

                $shots[$this->shotName($route, $combo)] = sha1_file($shot);
            }

            if (count(array_unique($sheets)) !== count($sheets) && $sheets !== []) {
                $problems[] = sprintf('%s: the four combinations reported only %d distinct '
                    . 'stylesheets (%s). One sheet serving two combinations means the run never '
                    . 'changed state.', $surface, count(array_unique($sheets)),
                    implode(', ', $sheets));
            }

            if (count(array_unique($shots)) !== count($shots)) {
                $duplicates = array_keys(array_intersect($shots,
                    array_diff_assoc($shots, array_unique($shots))));

                $problems[] = sprintf('%s: identical screenshots for different combinations (%s). '
                    . 'That is four pictures presented as eight states — the measured P5-R8 '
                    . 'failure.', $surface, implode(', ', $duplicates));
            }
        }

        $this->assertSame([], $problems,
            "Phase 05 surfaces whose four-combination evidence does not hold up:\n  "
            . implode("\n  ", $problems));
    }

    /** render-matrix.cjs's own naming, reproduced so the gate looks for the file it writes. */
    private function shotName(string $route, array $combo): string
    {
        return sprintf('%s__%s_%s_%s.png',
            preg_replace('/[^a-z0-9]+/i', '_', $route),
            $combo['lang'], $combo['dir'], $combo['theme']);
    }

    /**
     * The BLOCKED count is asserted as an EXACT figure, in both directions.
     *
     * `assert-known-test-outcomes.php` is the pattern: an undeclared failure AND an
     * unexpectedly-passing declared failure both fail the gate. A ceiling that only bounds from
     * above lets blocked rows accumulate; one that is not asserted downwards stays stale after the
     * blocker is cleared. A slice that needs to block a surface raises the number deliberately,
     * in a diff somebody reads.
     */
    #[Test]
    public function test_the_number_of_blocked_surfaces_is_exactly_the_declared_ceiling(): void
    {
        $blocked = array_keys(array_filter($this->surfaceRows(),
            fn (array $r): bool => str_starts_with($r['status'], 'BLOCKED')));

        $this->assertCount($this->blockedCeiling(), $blocked, sprintf(
            'The register declares a ceiling of %d blocked surfaces and carries %d: %s. Raise the '
            . 'ceiling deliberately, or delete the row whose blocker is cleared.',
            $this->blockedCeiling(), count($blocked), implode(', ', $blocked) ?: '(none)'
        ));
    }

    /* ======================================================================== the census bridge */

    /**
     * EVERY FILE THAT READS THE METRIC LAYER IS DECLARED, WITH WHAT IT FEEDS — AND NOTHING ELSE IS.
     *
     * This is the arm that makes a new Phase 05 surface impossible to add quietly. Its counterpart
     * direction matters just as much: a register row for a file that no longer reads the layer is
     * a stale claim, and stale claims are how `MASTER-PLAN-REQUIREMENT-TRACEABILITY.md` came to
     * disagree with the gap register for a day.
     */
    #[Test]
    public function test_every_metric_layer_reader_is_declared_and_every_declared_reader_reads_it(): void
    {
        $measured = array_keys($this->measuredReaders());
        $declared = array_keys($this->declaredReaders());

        sort($measured);
        sort($declared);

        $this->assertSame($measured, $declared, sprintf(
            "The register's READERS table and the code disagree about who reads %s.\n"
            . "  reads it, not declared: %s\n"
            . "  declared, does not:     %s\n"
            . 'A file that reads the metric layer can put a Phase 05 figure on a screen, so the '
            . 'register must say WHICH screen and in which combinations that screen was verified.',
            self::LAYER,
            implode(', ', array_diff($measured, $declared)) ?: '(none)',
            implode(', ', array_diff($declared, $measured)) ?: '(none)'
        ));
    }

    /**
     * AND THE COUNT, PER FILE — because adding a surface to a file that is already declared, or
     * re-pointing an existing screen at the metric layer (which is what P5-3 does, and it adds no
     * new file and no new `view()` call), changes nothing the set comparison above can see.
     */
    #[Test]
    public function test_each_declared_reader_carries_its_measured_reference_count(): void
    {
        $measured = $this->measuredReaders();
        $declared = $this->declaredReaders();

        $drift = [];

        foreach ($measured as $file => $count) {
            if (! array_key_exists($file, $declared)) {
                continue; // The set test above names it; one failure per fact.
            }

            if ($declared[$file] !== $count) {
                $drift[] = sprintf('%s: register says %d, code has %d', $file, $declared[$file],
                    $count);
            }
        }

        $this->assertSame([], $drift, sprintf(
            "Metric-layer reference counts have drifted:\n  %s\n"
            . 'Update the READERS row — and while you are there, say whether the change puts a '
            . "figure on a screen. If it does, that screen needs a SURFACES row and a "
            . 'four-combination capture before the slice is accepted.',
            implode("\n  ", $drift)
        ));
    }

    /* ===================================================================== the bilingual arm */

    /**
     * EVERY STRING A REGISTERED SURFACE PRINTS IS DECLARED IN ARABIC.
     *
     * `__()` RETURNS ITS KEY when nothing is declared, so an undeclared sentence renders correctly
     * in English by accident and renders IN ENGLISH on the Arabic page. That is the rendering
     * defect, and `ar.json` is where it is fixed.
     *
     * EN IS DELIBERATELY NOT REQUIRED HERE, and the reason is measured rather than stylistic:
     * `resources/views/finance/index.blade.php` prints 15 keys that `ar.json` declares and
     * `en.json` does not — the Arabic-only surplus `TranslationFileIntegrityTest` bounds at 31.
     * Those render correctly in BOTH languages. Requiring `en.json` here would redden a surface
     * that is bilingually correct, and the cheapest way to make it green again is to weaken this
     * test. `AnalyticsMessagesAreDeclaredTest` does require both, for the two analytics screens and
     * the whole metric layer, and that stricter rule stands untouched.
     */
    #[Test]
    public function test_every_string_a_registered_surface_prints_is_declared_in_arabic(): void
    {
        $ar = json_decode((string) file_get_contents($this->path('resources/lang/ar.json')), true);

        $this->assertIsArray($ar, 'resources/lang/ar.json is not readable as JSON.');
        $this->assertGreaterThan(4000, count($ar), 'ar.json holds far fewer keys than it should.');

        $undeclared = [];
        $read = 0;

        foreach ($this->surfaceRows() as $surface => $row) {
            $keys = $this->keysPrintedBy($row['view']);
            $read += count($keys);

            foreach ($keys as $key) {
                if (! array_key_exists($key, $ar)) {
                    $undeclared[] = sprintf('%s: %s', $surface, mb_strimwidth($key, 0, 90, '…'));
                }
            }
        }

        $this->assertGreaterThan(100, $read,
            "Only {$read} keys were extracted from every registered surface view together, so the "
            . 'extraction has broken and the assertion below is vacuous.');

        sort($undeclared);

        $this->assertSame([], $undeclared, sprintf(
            "%d string(s) a Phase 05 surface prints are not declared in resources/lang/ar.json, so "
            . "the Arabic page prints English:\n  %s",
            count($undeclared),
            implode("\n  ", $undeclared)
        ));
    }

    /**
     * A SURFACE MUST NOT PIN A SIDE, EXCEPT WHERE THE REGISTER SAYS WHY — in both directions.
     *
     * This is the one arm that measures RTL correctness in the SOURCE rather than in a picture, and
     * it is here because a physical margin is invisible in an English capture and wrong in Arabic.
     * The exceptions are pinned exactly: an undeclared hit fails, and so does a declared hit that
     * is no longer there, because a stale exception is a claim nobody re-reads.
     */
    #[Test]
    public function test_no_registered_surface_pins_a_side_except_where_the_register_says_why(): void
    {
        $declared = $this->declaredDirectionExceptions();
        $problems = [];

        foreach ($this->surfaceRows() as $surface => $row) {
            $measured = $this->physicalDirectionHits($row['view']);
            $allowed = $declared[$row['view']] ?? [];

            foreach ($measured as $hit => $count) {
                if (! array_key_exists($hit, $allowed)) {
                    $problems[] = sprintf('%s (%s): `%s` x%d pins a side and is not declared. Use '
                        . 'a logical property (margin-inline-start, ms-*/me-*, text-end), or '
                        . 'declare it in DIRECTION-EXCEPTIONS with the reason.',
                        $surface, $row['view'], $hit, $count);

                    continue;
                }

                if ($allowed[$hit] !== $count) {
                    $problems[] = sprintf('%s (%s): `%s` occurs %d times, the register declares %d.',
                        $surface, $row['view'], $hit, $count, $allowed[$hit]);
                }
            }

            foreach ($allowed as $hit => $count) {
                if (! array_key_exists($hit, $measured)) {
                    $problems[] = sprintf('%s (%s): the register declares `%s` x%d and the view no '
                        . 'longer carries it. Delete the DIRECTION-EXCEPTIONS row.',
                        $surface, $row['view'], $hit, $count);
                }
            }
        }

        $this->assertSame([], $problems,
            "Direction handling in the registered Phase 05 surfaces:\n  "
            . implode("\n  ", $problems));
    }
}
