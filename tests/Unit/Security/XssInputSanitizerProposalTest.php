<?php

namespace Tests\Unit\Security;

use App\Support\Security\XssInputSanitizerProposal as Proposal;
use PHPUnit\Framework\TestCase;

/**
 * TESTS A PROPOSAL, NOT A SHIPPED CONTROL. Nothing calls the class under test.
 * =================================================================================================
 *
 * Owner instruction of 2026-11-07, item 3: a *"narrowly scoped, tested Phase 01-compatible repair
 * proposal without weakening XSS defenses."* The "tested" part is this file; the "without
 * weakening" part is §3 below, which compares the candidate against the CURRENT behaviour by
 * execution over a corpus rather than by argument.
 *
 * `app/Http/Middleware/XSS.php` is UNCHANGED. `grep -rn XssInputSanitizerProposal app/` finds the
 * candidate's own file and nothing else.
 */
class XssInputSanitizerProposalTest extends TestCase
{
    /** Values a merchant or shopper legitimately types, which the current rule corrupts. */
    public static function benignCorpus(): array
    {
        return [
            'monitoring mailbox'      => ['alerts@company.com'],
            'the word as a local part'=> ['javascript@x.example'],
            'a person\'s name'        => ['Alberto-alert'],
            'a longer word'           => ['alerting the team'],
            'a sentence'              => ['Please send alerts to this address'],
            'a product note'          => ['Arrived in <2 days, great seller'],
            'a bare lesser-than'      => ['<3'],
            'a job title'             => ['JavaScript developer'],
            'a company name'          => ['Alert Systems Ltd'],
        ];
    }

    /** Shapes that actually execute, and which any replacement must still neutralise. */
    public static function dangerousCorpus(): array
    {
        return [
            'url scheme'              => ['javascript:void(0)', 'javascript:'],
            'url scheme, mixed case'  => ['JavaScript:alert(1)', 'javascript:'],
            'url scheme with spacing' => ["javascript\t: alert(1)", 'javascript'],
            'a call'                  => ['alert(1)', 'alert('],
            'a call with spacing'     => ['alert (1)', 'alert ('],
            'a call, mixed case'      => ['Alert(document.cookie)', 'lert('],
            'both together'           => ['<a href="javascript:alert(1)">x</a>', 'javascript:'],
        ];
    }

    // =============================================================================================
    //  1. BENIGN VALUES SURVIVE — which is the defect being repaired
    // =============================================================================================

    /** @dataProvider benignCorpus */
    public function test_a_benign_value_is_not_corrupted(string $input): void
    {
        $out = Proposal::clean($input);

        // Angle brackets are ENCODED rather than removed, so compare on that basis: nothing is
        // lost, and '<2 days' stays readable.
        $expected = str_replace(['<', '>'], ['&lt;', '&gt;'], $input);

        $this->assertSame($expected, $out,
            "the candidate altered a benign value: '{$input}' became '{$out}'");
    }

    /**
     * And the point of the exercise: the CURRENT rule does corrupt several of these. This asserts
     * the difference rather than asserting it in prose.
     */
    public function test_the_current_rule_corrupts_values_the_candidate_preserves(): void
    {
        $corrupted = [];

        foreach (self::benignCorpus() as $label => [$input]) {
            $now = Proposal::currentBehaviour($input);
            $then = Proposal::clean($input);
            $encodedInput = str_replace(['<', '>'], ['&lt;', '&gt;'], $input);

            if ($now !== $input && $then === $encodedInput) {
                $corrupted[$label] = [$input, $now];
            }
        }

        $this->assertNotEmpty($corrupted,
            'premise: the current rule corrupts none of the benign corpus, so there is nothing to '
            . 'repair and this proposal should be withdrawn');

        // Named explicitly, so the report carries the measured values rather than a count.
        $this->assertArrayHasKey('monitoring mailbox', $corrupted,
            'premise: alerts@company.com is no longer corrupted by the current rule');
        $this->assertSame('s@company.com', $corrupted['monitoring mailbox'][1],
            'the measured corruption of alerts@company.com has changed');
    }

    // =============================================================================================
    //  2. DANGEROUS SHAPES ARE STILL NEUTRALISED
    // =============================================================================================

    /** @dataProvider dangerousCorpus */
    public function test_a_dangerous_shape_is_neutralised(string $input, string $mustNotSurvive): void
    {
        $out = Proposal::clean($input);

        $this->assertStringNotContainsStringIgnoringCase($mustNotSurvive, $out,
            "the candidate left '{$mustNotSurvive}' intact in '{$out}'");
    }

    /**
     * And the raw angle bracket, which the CURRENT rule never saw at all because
     * `htmlspecialchars_decode()` ran first and its targets were the ENCODED forms. This is the
     * one place the candidate is strictly stronger, not merely equal.
     */
    public function test_a_raw_tag_is_encoded_where_the_current_rule_passed_it_through(): void
    {
        $input = '<img src=x onerror=alert(1)>';

        $now = Proposal::currentBehaviour($input);
        $then = Proposal::clean($input);

        $this->assertStringContainsString('<img', $now,
            'premise: the current rule already neutralises a raw tag, so the candidate is not '
            . 'stronger here and this test should be removed');

        $this->assertStringNotContainsString('<img', $then,
            'the candidate still lets a raw tag through');
        $this->assertStringContainsString('&lt;img', $then,
            'the candidate neither encoded nor removed the raw tag');
    }

    // =============================================================================================
    //  3. THE DIFFERENTIAL — "no weaker" asserted by execution, over the whole corpus
    // =============================================================================================

    /**
     * THE OWNER'S CONDITION, MEASURED. For every dangerous input, whatever the current rule
     * removed must also be absent from the candidate's output. A replacement that let any of these
     * through would be a weakening, however much more precise it is elsewhere.
     *
     * Compared on the four strings the current rule targets, because those are the only things it
     * claims to remove.
     */
    public function test_the_candidate_removes_everything_the_current_rule_removed(): void
    {
        $targets = ['&lt;', '&gt;', 'javascript', 'alert'];
        $weakenings = [];

        foreach (self::dangerousCorpus() as $label => [$input]) {
            $now = Proposal::currentBehaviour($input);
            $then = Proposal::clean($input);

            foreach ($targets as $t) {
                $goneFromCurrent = ! str_contains(strtolower($now), strtolower($t));
                $presentInCandidate = str_contains(strtolower($then), strtolower($t));

                // A bare word surviving is only a weakening if it survives in an EXECUTABLE shape.
                // `javascript` as a word in `JavaScript developer` is not a vector; `javascript:`
                // is. So the comparison is on the shape, which is what the candidate targets.
                if ($goneFromCurrent && $presentInCandidate) {
                    $dangerousShape = ($t === 'javascript' && str_contains(strtolower($then), 'javascript:'))
                        || ($t === 'alert' && preg_match('/\balert\s*\(/i', $then) === 1);

                    if ($dangerousShape) {
                        $weakenings[] = "{$label}: '{$t}' survives as an executable shape in '{$then}'";
                    }
                }
            }
        }

        $this->assertSame([], $weakenings,
            "the candidate is WEAKER than the current rule on these inputs:\n  "
            . implode("\n  ", $weakenings));
    }

    /**
     * The decode is gone, and that is the second tightening. Asserted because an encoded payload
     * being decoded before inspection is the inversion that made the current rule ineffective.
     */
    public function test_the_candidate_does_not_decode_entities_before_inspecting(): void
    {
        // The current rule decodes &lt; into a raw < and then looks for &lt;, finding nothing.
        $input = '&lt;img src=x onerror=1&gt;';

        $now = Proposal::currentBehaviour($input);
        $then = Proposal::clean($input);

        $this->assertStringContainsString('<img', $now,
            'premise: the current rule no longer decodes before inspecting, so this tightening is '
            . 'already in place');

        $this->assertStringNotContainsString('<img', $then,
            'the candidate decoded the entity and let a raw tag through');
        $this->assertSame($input, $then,
            'an already-encoded value should pass through unchanged; it became ' . $then);
    }

    // =============================================================================================
    //  4. THE PROPOSAL IS INERT — asserted, so it cannot be applied by accident
    // =============================================================================================

    public function test_the_middleware_is_unchanged_and_nothing_calls_the_candidate(): void
    {
        $middleware = file_get_contents(__DIR__ . '/../../../app/Http/Middleware/XSS.php');

        $this->assertStringNotContainsString('XssInputSanitizerProposal', $middleware,
            'the candidate has been wired into the middleware. That is a change to a Phase 01 '
            . 'control and needs the owner\'s approval, which this file does not constitute');

        // RE-POINTED 2026-11-10. The sweep's four substrings moved out of the `str_replace(...)`
        // call and into `XSS::STRIPPED_SUBSTRINGS`, so that `CredentialSanitisationTest` could
        // measure the REAL list instead of a copy of it. The behaviour did not change, and this
        // ratchet's job did not either: it asserts the live rule is still the same four
        // substrings, applied by the middleware, and that the candidate is still uncalled.
        //
        // Asserted against the CONSTANT and not a quoted copy of it, for the same reason the
        // constant exists: a ratchet that quotes the thing it guards stops guarding it the moment
        // the thing moves.
        $this->assertSame(
            ['&lt;', '&gt;', 'javascript', 'alert'],
            \App\Http\Middleware\XSS::STRIPPED_SUBSTRINGS,
            'the live sweep no longer strips the same four substrings, so either it was repaired '
            . 'elsewhere or the proposal was applied — either way this test file needs rewriting'
        );

        $this->assertStringContainsString('str_replace(self::STRIPPED_SUBSTRINGS', $middleware,
            'the middleware no longer applies the sweep through its own constant, so the list '
            . 'above may no longer be what runs');
    }
}
