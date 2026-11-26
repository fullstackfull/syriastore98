<?php

namespace Tests\Unit\Operations;

use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * OPS-002 — "قياسات موارد حقيقية" / **REAL** RESOURCE MEASUREMENTS, AS A CENSUS OF PROVENANCE.
 * =================================================================================================
 *
 * The recovered requirement is one line long and the load-bearing word is حقيقية — REAL. Somebody
 * anticipated the failure mode, and it is not "no figures": it is AN OPERATIONS SCREEN THAT SHOWS
 * PLAUSIBLE NUMBERS WHICH ARE NOT MEASUREMENTS. A hardcoded percentage, a disk gauge that reports
 * a constant, a queue depth that is a placeholder, a health badge that is always green. A wrong
 * number on an operations dashboard is worse than no number, because it is acted upon.
 *
 * `PlatformResourceMeasurementTest` and `PlatformOperationsMeasurementTest` assert that the figures
 * the two producers emit really are the system's — by changing the system and watching them move.
 * THIS file asks the other half of the question, which neither of those can: **is there anywhere
 * else in the product that shows an operator an operational figure?** A perfect producer is no use
 * if a controller somewhere renders its own CPU gauge from a constant.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 * THE CENSUS AS IT STOOD WHEN THIS FILE WAS WRITTEN (2026-11-26), MEASURED
 * ──────────────────────────────────────────────────────────────────────────────────────────────────
 *
 * Every operational figure this product shows an operator, and where it comes from:
 *
 *   load average, processors, machine memory, request memory, memory limit, disk total/free/used
 *   and both shares                        `SaasResourceProbe` -> `SaasPlatformResources`
 *                                          -> `/platform/resources`.  MEASURED.
 *
 *   database engine, version, schema, reachability, table count, size, open connections;
 *   queue connection, driver, jobs waiting, jobs held, oldest wait, failures in a window,
 *   failures in total, newest failure, failing queues; cache and session driver and row
 *   counts; mail transport and host; SMS integrations configured and active; payment-gateway
 *   enablement, completeness, withdrawn-but-on and ambiguous rows
 *                                          `SaasOperationsProbe` -> `SaasPlatformOperations`
 *                                          -> `/platform/operations`.  MEASURED.
 *
 *   the operator dashboard's cards         row counts of this product's own tables, through
 *                                          `HomeController`. Real counts of real rows; they are
 *                                          not system figures and are not labelled as any.
 *
 *   NOTHING ELSE. There is no other surface in this product that reports a figure about the
 *   machine, the database, the queue or an integration — and before `GAP-195` there was no surface
 *   at all. So the honest finding of the census is that this product shows an operator NO INVENTED
 *   OPERATIONAL FIGURE: the defect against OPS-002 was ABSENCE, not invention.
 *
 * WHICH IS EXACTLY WHY THIS FILE EXISTS. "We checked and found none" decays the moment somebody
 * adds a gauge. The three assertions below are the finding turned into something that fails.
 *
 * COMMENT-STRIPPED THROUGHOUT, and here it is load-bearing rather than precautionary: this very
 * docblock names `sys_get_loadavg`, `disk_free_space` and `memory_get_usage` while explaining
 * which files are allowed to call them, and `CLAUDE.md` records eight occasions on which a census
 * was satisfied — or broken — by prose about the thing it measures.
 */
final class OperationalFigureProvenanceCensusTest extends TestCase
{
    use StripsSourceComments;

    /**
     * THE CALLS THAT TAKE A REAL READING OF THE MACHINE. If one of these appears, the file is
     * claiming to know something about the host.
     */
    private const MACHINE_READINGS = [
        'sys_get_loadavg',
        'disk_free_space',
        'disk_total_space',
        'memory_get_usage',
        'memory_get_peak_usage',
        '/proc/loadavg',
        '/proc/meminfo',
        '/proc/cpuinfo',
    ];

    /**
     * THE ONLY FILES ALLOWED TO TAKE ONE — asserted in BOTH DIRECTIONS.
     *
     * One direction alone always fails the same way: "these three still measure" lets a fourth
     * file start measuring unnoticed, and "nothing else measures" lets all three stop. Both are
     * the same failure — the product's one honest source of machine figures is not where this
     * says it is — so both are failures here.
     */
    private const MACHINE_READERS = [
        /*
         * TWO FILES, AND `SuperAdminResourcesController` IS DELIBERATELY NOT ONE OF THEM -- which
         * the first version of this census got wrong, and which is a fact worth having rather than
         * a detail. That controller NAMES `disk_free_space()` in its docblock, explaining why its
         * screen takes no `path` parameter, and the census strips comments, so it is not a reader.
         * It really does take no reading of its own: it hands the producer's result to a view and
         * composes nothing, which `PlatformResourceMessagesAreDeclaredTest` separately pins by
         * declaring it a silent surface. So the product's machine figures come from exactly two
         * files, and the controller being absent from this list is evidence of the layering rather
         * than an omission.
         */
        'app/Services/SaasPlatformResources.php',
        'app/Services/SaasResourceProbe.php',
    ];

    /** The measurement blocks on the two operator screens, by the markers the views emit. */
    private const MEASUREMENT_BLOCKS = [
        'resources/views/superadmin/platform_resources.blade.php'
            => ['ss-resource-measurements', 'ss-resource-measurements-end'],
        'resources/views/superadmin/platform_operations.blade.php'
            => ['ss-operations-measurements', 'ss-operations-measurements-end'],
    ];

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<string> every first-party PHP file under app/ */
    private function appFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root() . '/app', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = ltrim(str_replace($this->root(), '', $file->getPathname()), '/');
            }
        }

        sort($files);

        return $files;
    }

    /** @return list<string> every first-party Blade file */
    private function bladeFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root() . '/resources/views', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $relative = ltrim(str_replace($this->root(), '', $file->getPathname()), '/');

            /*
             * `front_end/` IS EXCLUDED, the same exclusion `RequiredFieldMarkerTest` makes and for
             * the same reason: those are the Phase 03 storefront themes, they are not operator
             * surfaces, and one of them (`front_end/hooks/cart_sidebar.blade.php`) is entirely a
             * comment -- so the shared stripper's own "stripping removed the whole file" guard
             * fires on it, which is that guard working rather than a problem to suppress.
             */
            if (str_starts_with($relative, 'resources/views/front_end/')) {
                continue;
            }

            $files[] = $relative;
        }

        sort($files);

        return $files;
    }

    /**
     * THE MACHINE IS READ IN EXACTLY THESE THREE FILES AND NOWHERE ELSE.
     *
     * A fourth file taking a reading is not necessarily wrong — but it is a second place that
     * decides what a load average means, and `SaasPlatformResources`'s whole design is that the
     * figure, its period, its source and the sentence shown when the host refuses are produced
     * together. A reading taken anywhere else arrives without them.
     */
    public function test_the_machine_is_read_in_exactly_the_declared_files(): void
    {
        $readers = [];

        foreach ($this->appFiles() as $file) {
            $code = $this->sourceWithoutComments($file);

            foreach (self::MACHINE_READINGS as $reading) {
                if (str_contains($code, $reading)) {
                    $readers[] = $file;
                    break;
                }
            }
        }

        $expected = self::MACHINE_READERS;
        sort($expected);

        $this->assertSame($expected, $readers,
            "The set of files that take a real reading of the machine changed.\n"
            . "A NEW one is a second place that decides what a load average means, and it arrives "
            . "without the period, the source and the host-refused sentence that "
            . "SaasPlatformResources produces with every figure.\n"
            . "A MISSING one means the product's only honest source of machine figures is no "
            . "longer where this census says it is -- which, before GAP-195, was the state the "
            . "whole requirement was written against.\n"
            . "Measured now:\n  " . implode("\n  ", $readers));
    }

    /**
     * NO BLADE FILE ANYWHERE TAKES ITS OWN READING.
     *
     * A measurement in a view is the exact shape of the defect OPS-002 names: it has no period, no
     * stated source, no refusal path when the host says no, and no test can reach it. Asserted
     * over EVERY first-party Blade rather than over the operator screens, because the gauge
     * somebody adds will be on whatever page they were working on.
     */
    public function test_no_view_takes_its_own_reading_of_the_machine(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $code = $this->sourceWithoutComments($file);

            foreach (self::MACHINE_READINGS as $reading) {
                if (str_contains($code, $reading)) {
                    $offenders[] = $file . ' -> ' . $reading;
                }
            }
        }

        $this->assertSame([], $offenders,
            "A view takes its own reading of the machine. Such a figure has no period, no stated "
            . "source and no sentence for the case where the host refuses -- and no test can "
            . "reach it.\n  " . implode("\n  ", $offenders));
    }

    /**
     * NO FIGURE ON EITHER OPERATOR SCREEN IS WRITTEN INTO THE PAGE.
     *
     * The thing OPS-002's one word was written against: a number, a percentage or a bar width that
     * looks like a measurement and is a literal. Scoped to the measurement blocks, because
     * `CLAUDE.md` records a mutation surviving an assertion taken over a whole file — "an
     * assertion over a whole 7,000-line stylesheet answers 'does this string exist anywhere'".
     *
     * THE SHAPES REFUSED ARE THE ONES A FIGURE TAKES IN BLADE: a percentage literal, an inline
     * width, and `{{ 42 }}`. A bare number in a `colspan` or a class name is not a figure and is
     * not matched, which is why the patterns are specific rather than "any digit".
     */
    public function test_no_operator_measurement_block_contains_a_written_in_figure(): void
    {
        foreach (self::MEASUREMENT_BLOCKS as $file => [$open, $close]) {
            $raw = (string) file_get_contents($this->root() . '/' . $file);

            // Blade comments out, so prose inside the block cannot satisfy or break this.
            $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $raw);

            $from = strpos($code, $open);

            $this->assertNotFalse($from, "premise: {$file} no longer emits the `{$open}` marker, so "
                . 'this census is reading nothing. Re-point it at the new marker rather than '
                . 'deleting it.');

            $to = strpos($code, $close, $from);

            $this->assertNotFalse($to, "premise: {$file} no longer emits the `{$close}` marker.");

            $block = substr($code, $from, $to - $from);

            $this->assertNotSame('', trim($block), "premise: the measurement block in {$file} is empty.");

            foreach ([
                '/\d+(?:\.\d+)?\s*%/' => 'a literal percentage',
                '/style\s*=\s*["\'][^"\']*width\s*:\s*\d/i' => 'a hard-coded bar width',
                '/\{\{\s*-?\d+(?:\.\d+)?\s*\}\}/' => 'a number printed straight into the page',
            ] as $pattern => $what) {
                $this->assertSame(0, preg_match($pattern, $block), sprintf(
                    'The measurement block in %s contains %s. OPS-002 asks for قياسات موارد '
                    . 'حقيقية -- REAL resource measurements -- and a figure written into the page '
                    . 'is the failure that word was chosen to prevent: it has no period, no source '
                    . 'and no refusal path, and an operator acts on it. Every figure on these two '
                    . 'screens comes from SaasPlatformResources or SaasPlatformOperations, which '
                    . 'produce it with all three.',
                    $file,
                    $what
                ));
            }
        }
    }

    /**
     * AND NEITHER SCREEN SHOWS A HEALTH VERDICT, which is the second half of the same honesty.
     *
     * The producers are each pinned against carrying a verdict key and the screens against
     * carrying a Bootstrap colour; this adds the WORDS, because the cheapest way to invent an
     * operational policy is to write "Healthy" next to a figure. What counts as healthy has been
     * set by nobody — `PlatformIncidentPolicy::openDecisions()['thresholds']` — and a badge saying
     * so would be the platform stating a rule it does not have.
     */
    public function test_neither_operator_screen_declares_anything_healthy(): void
    {
        foreach (self::MEASUREMENT_BLOCKS as $file => [$open, $close]) {
            $raw = (string) file_get_contents($this->root() . '/' . $file);
            $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $raw);

            $from = (int) strpos($code, $open);
            $to = (int) strpos($code, $close, $from);
            $block = substr($code, $from, $to - $from);

            foreach (['Healthy', 'Unhealthy', 'All good', 'Warning', 'Critical', 'Degraded', 'OK'] as $verdict) {
                $this->assertStringNotContainsString($verdict, $block, sprintf(
                    '%s says "%s" about a figure. These screens state measurements and judge '
                    . 'none: what counts as healthy is an operational policy nobody has set, and a '
                    . 'word on the screen is how the product comes to state one.',
                    $file,
                    $verdict
                ));
            }
        }
    }
}
