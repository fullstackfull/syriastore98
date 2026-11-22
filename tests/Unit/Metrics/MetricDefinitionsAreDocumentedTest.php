<?php

namespace Tests\Unit\Metrics;

use Tests\TestCase;

/**
 * EVERY METRIC CITES A SECTION OF THE DEFINITIONS DOCUMENT, AND EVERY METRIC SECTION HAS A METRIC.
 *
 * `Metric::definition()` exists so a figure on a screen can be traced to the text that says what it
 * means and what it leaves out. Nothing checked that the citation resolved. `CustomerRetention`
 * returned `'PHASE-05-EXECUTION-PLAN.md P5-6'` — a real file, but the definitions document had no
 * retention section at all, which `ANA-008` recorded as a tracked gap for months. A citation that
 * points nowhere is worse than none: it reads as though the figure were documented.
 *
 * So this asserts BOTH directions, which is the half that catches drift:
 *
 *  - forward: every `definition()` names a section the document really has;
 *  - reverse: every numbered metric section is claimed by exactly one metric class.
 *
 * The reverse direction is why `NON_METRIC_SECTIONS` is spelled out rather than inferred. Adding a
 * section to the document now forces a choice — implement it, or declare it as prose — instead of
 * letting an undelivered metric sit in a document that reads as a specification of what exists.
 */
class MetricDefinitionsAreDocumentedTest extends TestCase
{
    /** Sections that are prose, not a metric. Each must stay justified by its own heading. */
    private const NON_METRIC_SECTIONS = [
        '0a',   // which approved decisions this document answers to
        '0',    // why SUM(orders.final_price) is not revenue
        '11',   // the rules that apply to every metric
    ];

    /**
     * SECTIONS THAT DEFINE A FIGURE COMPUTED BY A PRESENTER, NOT BY A `Metric` CLASS.
     *
     * Added 2026-11-22 with §13 (conversion) and §14 (campaign arrivals). Both figures ship on
     * `/analytics`, and neither is a class of the `Metric` family -- deliberately: that base reads
     * the three money records of §0, and these read the visit log and none of them.
     *
     * The two existing answers were both wrong for them. `NON_METRIC_SECTIONS` means PROSE, and
     * these are delivered figures; listing them there would have been the cheapest way to make
     * this test pass and would have removed the only check that the thing a section describes
     * exists. Wrapping each in a `Metric` subclass purely to satisfy a regex would have moved
     * shipped, tested code to fit a test.
     *
     * So the section names the METHOD that computes it, and
     * `test_every_presenter_figure_section_names_a_method_that_exists` asserts by reflection that
     * the method is really there. The census keeps its purpose in both directions: a section still
     * cannot describe a figure nobody built, and a figure still cannot be exempted by assertion.
     *
     * @var array<string, array{0: class-string, 1: string}>
     */
    private const PRESENTER_FIGURE_SECTIONS = [
        '13' => [\App\Support\Metrics\MerchantAnalyticsFigures::class, 'conversion'],
        '14' => [\App\Support\Metrics\MerchantAnalyticsFigures::class, 'campaigns'],
    ];

    /**
     * The verification tests each presenter-figure section names. A `Metric` section's test is
     * `MetricLayerTest`, which the metric's own class anchors; a presenter figure has no such
     * anchor, so the document's claim that a test exists is checked against the filesystem.
     *
     * @var array<string, list<string>> section => test files, relative to the repository root
     */
    private const PRESENTER_VERIFICATION_TESTS = [
        '13' => [
            'tests/Feature/Analytics/MerchantAnalyticsFiguresTest.php',
            'tests/Feature/Analytics/ConversionBaselineTest.php',
            'tests/Feature/Analytics/VisitCoverageStartTest.php',
        ],
        '14' => [
            'tests/Feature/Analytics/CampaignTrafficTest.php',
        ],
    ];

    private const DOC = 'docs/development/phase-5/PHASE-05-METRIC-DEFINITIONS.md';

    /** @return array<string, string> metric class short name => cited section number */
    private function citations(): array
    {
        $found = [];

        foreach (glob(base_path('app/Support/Metrics/*.php')) as $file) {
            $source = file_get_contents($file);

            // Only a class that declares definition() is a metric for this purpose. Metric.php is
            // the base class and declares none; the value objects (MetricResult, MetricWindow, ...)
            // declare none either.
            if (! preg_match('/public function definition\(\): string\s*\{\s*return\s*\'([^\']+)\'/', $source, $m)) {
                continue;
            }

            $found[basename($file, '.php')] = $m[1];
        }

        return $found;
    }

    public function test_the_premise_that_metrics_declare_a_definition_at_all(): void
    {
        $citations = $this->citations();

        // If this drops to nothing the regex has gone stale and both real assertions below would
        // pass vacuously, which is the failure mode this project has actually hit.
        $this->assertGreaterThanOrEqual(11, count($citations),
            'Found ' . count($citations) . ' metric definition() declarations; the scan is probably stale.');
    }

    public function test_every_metric_cites_a_section_the_definitions_document_really_has(): void
    {
        $doc = file_get_contents(base_path(self::DOC));

        foreach ($this->citations() as $class => $citation) {
            $this->assertMatchesRegularExpression(
                '/^PHASE-05-METRIC-DEFINITIONS\.md §\d+$/',
                $citation,
                "{$class}::definition() must cite a section of the definitions document, got '{$citation}'."
            );

            $section = (int) substr($citation, strpos($citation, '§') + strlen('§'));

            $this->assertMatchesRegularExpression(
                '/^## ' . $section . '\. /m',
                $doc,
                "{$class} cites §{$section}, but " . self::DOC . " has no '## {$section}.' heading."
            );
        }
    }

    public function test_every_numbered_metric_section_is_claimed_by_exactly_one_metric(): void
    {
        preg_match_all('/^## (\d+a?)\. /m', file_get_contents(base_path(self::DOC)), $m);

        $sections = array_values(array_diff(
            $m[1],
            self::NON_METRIC_SECTIONS,
            array_map('strval', array_keys(self::PRESENTER_FIGURE_SECTIONS))
        ));

        $claimed = [];
        foreach ($this->citations() as $class => $citation) {
            $claimed[substr($citation, strpos($citation, '§') + strlen('§'))][] = $class;
        }

        foreach ($sections as $section) {
            $this->assertArrayHasKey($section, $claimed,
                self::DOC . " §{$section} describes a metric that no class in app/Support/Metrics implements. "
                . 'Implement it, or add it to NON_METRIC_SECTIONS with a reason.');

            $this->assertCount(1, $claimed[$section],
                "§{$section} is cited by more than one metric: " . implode(', ', $claimed[$section]) . '.');
        }
    }
    /**
     * A presenter-figure section is only as good as the method it names. Reflection, not a grep:
     * a docblock mentioning the method would satisfy a string search after the method was deleted.
     */
    public function test_every_presenter_figure_section_names_a_method_that_exists(): void
    {
        $doc = file_get_contents(base_path(self::DOC));

        foreach (self::PRESENTER_FIGURE_SECTIONS as $section => [$class, $method]) {
            $this->assertMatchesRegularExpression('/^## ' . $section . '\. /m', $doc,
                "§{$section} is declared as a presenter figure and " . self::DOC . ' has no such section.');

            $this->assertTrue(method_exists($class, $method),
                "§{$section} says it is computed by {$class}::{$method}(), which does not exist. "
                . 'The section now describes a figure nobody builds.');

            // The section must SAY which method computes it, so a reader of the document is sent
            // to the code that this test checked rather than left to find it.
            preg_match('/^## ' . $section . '\. .*?(?=^## \d|\z)/ms', $doc, $body);

            $this->assertStringContainsString(
                '`' . class_basename($class) . '::' . $method . '()`',
                $body[0] ?? '',
                "§{$section} does not name {$class}::{$method}() as what computes it."
            );
        }
    }

    /** A section is a metric, a presenter figure, or prose -- exactly one, never two. */
    public function test_no_section_is_declared_in_two_categories(): void
    {
        $presenter = array_map('strval', array_keys(self::PRESENTER_FIGURE_SECTIONS));

        $this->assertSame([], array_values(array_intersect($presenter, self::NON_METRIC_SECTIONS)),
            'A section is declared both as prose and as a presenter figure.');

        $claimedByAMetric = [];
        foreach ($this->citations() as $citation) {
            $claimedByAMetric[] = substr($citation, strpos($citation, '§') + strlen('§'));
        }

        $this->assertSame([], array_values(array_intersect($presenter, $claimedByAMetric)),
            'A section is both cited by a Metric class and declared as a presenter figure. '
            . 'If the figure became a real metric, remove it from PRESENTER_FIGURE_SECTIONS.');
    }
    /** The document says a test verifies each presenter figure; each one named must be a real file. */
    public function test_every_presenter_figure_section_names_verification_tests_that_exist(): void
    {
        $doc = file_get_contents(base_path(self::DOC));

        $this->assertSame(
            array_map('strval', array_keys(self::PRESENTER_FIGURE_SECTIONS)),
            array_map('strval', array_keys(self::PRESENTER_VERIFICATION_TESTS)),
            'Every presenter-figure section must declare its verification tests, and no other section may.'
        );

        foreach (self::PRESENTER_VERIFICATION_TESTS as $section => $files) {
            preg_match('/^## ' . $section . '\. .*?(?=^## \d|\z)/ms', $doc, $body);

            foreach ($files as $file) {
                $this->assertFileExists(base_path($file),
                    "§{$section} is said to be verified by {$file}, which does not exist.");

                $this->assertStringContainsString('`' . basename($file, '.php') . '`', $body[0] ?? '',
                    "§{$section} does not name " . basename($file, '.php') . ' as a verification test.');
            }
        }
    }
}
