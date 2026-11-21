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

        $sections = array_values(array_diff($m[1], self::NON_METRIC_SECTIONS));

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
}
