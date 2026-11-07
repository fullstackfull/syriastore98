<?php

namespace Tests\Unit\Ci;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A GATE KEYED ON AN EXTERNAL DATABASE MUST NOT BE ABLE TO SWITCH OFF THIS REPOSITORY'S GUARDS.
 * =================================================================================================
 *
 * `composer audit must not grow` compares a count against `AUDIT_CEILING`, and the thing it counts
 * is the PUBLIC ADVISORY DATABASE, which grows on its own schedule against dependencies nobody
 * here has touched. On 2026-11-04 at 20:18Z it went red for exactly that reason, with
 * `composer.lock` byte-identical to `main`'s.
 *
 * IT USED TO SIT AT STEP 7 OF 18 INSIDE THE `static` JOB. A GitHub Actions step defaults to
 * `if: success()`, so its failure SKIPPED THE ELEVEN STEPS AFTER IT. Measured: two of those
 * eleven also run in the `tests` jobs, and NINE RUN NOWHERE ELSE —
 *
 *     Guard - the known .env exposure is reported, and no new one appeared
 *     Guard - the attestation gate behaves as specified
 *     Guard - packages/workdo/ is unmodified
 *     Guard - no NEW user upload is committed to the repository root
 *     php -l on changed PHP files
 *     php -l on EVERY tracked PHP file
 *     Design-system token contrast must stay WCAG-conformant
 *     Cross-repo API contract - the shipped Flutter app must not be broken
 *     Admin API auth coverage must not regress
 *
 * — so for 26 commits across ~8.5 hours, no commit was checked by any of them, and the same was
 * true of `main`. Two of them, the vendored-code guard and the uploads guard, had been repaired in
 * #125 precisely because they were REPORTING SUCCESS WITHOUT MEASURING ANYTHING; they went from
 * that to not reporting at all.
 *
 * This file pins the separation. It does not assert that the advisory gate is lenient — the same
 * step, the same ceiling and the same lock-change condition still run, and a real advisory still
 * turns a required check red. It asserts only that the two cannot be in a position to silence each
 * other again.
 */
class AdvisoryGateIsolationTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/ci.yml';

    /** The steps that were skipped and that run in no other job. */
    private const ORPHANED_GUARDS = [
        'Guard - the known .env exposure is reported, and no new one appeared',
        'Guard - the attestation gate behaves as specified',
        'Guard - packages/workdo/ is unmodified',
        'Guard - no NEW user upload is committed to the repository root',
        'php -l on changed PHP files',
        'php -l on EVERY tracked PHP file',
        'Design-system token contrast must stay WCAG-conformant',
        'Cross-repo API contract - the shipped Flutter app must not be broken',
        'Admin API auth coverage must not regress',
    ];

    private const ADVISORY_STEP = 'composer audit must not grow';

    /** @return array<string, array<string, mixed>> */
    private function jobs(): array
    {
        $path = dirname(__DIR__, 3) . '/' . self::WORKFLOW;
        $this->assertFileExists($path);

        $parsed = Yaml::parseFile($path);
        $this->assertIsArray($parsed['jobs'] ?? null, 'premise: the workflow declares jobs');

        return $parsed['jobs'];
    }

    /** @return array<int, string> the named steps of one job */
    private function stepNames(array $job): array
    {
        return array_values(array_filter(array_map(
            static fn ($s) => is_array($s) ? ($s['name'] ?? null) : null,
            $job['steps'] ?? []
        )));
    }

    private function jobContainingStep(string $step): string
    {
        foreach ($this->jobs() as $id => $job) {
            if (in_array($step, $this->stepNames($job), true)) {
                return (string) $id;
            }
        }

        $this->fail("premise: no job declares the step `{$step}`");
    }

    /**
     * THE SEPARATION ITSELF. If these are the same job again, the first failure silences the rest.
     */
    public function test_the_advisory_gate_is_not_in_the_same_job_as_the_guards(): void
    {
        $advisoryJob = $this->jobContainingStep(self::ADVISORY_STEP);

        foreach (self::ORPHANED_GUARDS as $guard) {
            $this->assertNotSame(
                $advisoryJob,
                $this->jobContainingStep($guard),
                "`{$guard}` is back in the same job as `" . self::ADVISORY_STEP . "`. A step "
                . 'defaults to `if: success()`, so the advisory gate going red — which it does '
                . 'whenever the PUBLIC advisory database grows, with no change here — skips this '
                . 'guard. That is how nine guards stopped running for 26 commits.'
            );
        }
    }

    /**
     * AND NOT WIRED BACK TOGETHER THROUGH `needs:`, which reintroduces the same skip at job level
     * and would be easy to add while "tidying up" the dependency order.
     */
    public function test_the_guards_job_does_not_depend_on_the_advisory_job(): void
    {
        $jobs = $this->jobs();
        $advisoryJob = $this->jobContainingStep(self::ADVISORY_STEP);
        $guardsJob = $this->jobContainingStep(self::ORPHANED_GUARDS[0]);

        $needs = $jobs[$guardsJob]['needs'] ?? [];
        $needs = is_array($needs) ? $needs : [$needs];

        $this->assertNotContains(
            $advisoryJob,
            $needs,
            "the guards job `{$guardsJob}` declares `needs: {$advisoryJob}`. A failed dependency "
            . 'skips the dependent job entirely, which is the same defect one level up.'
        );
    }

    /**
     * AND EVERY ONE OF THE NINE IS STILL PRESENT. Splitting the job must not have dropped one on
     * the way — a guard deleted is quieter than a guard skipped.
     *
     * @dataProvider orphanedGuards
     */
    public function test_each_previously_masked_guard_still_exists(string $guard): void
    {
        $all = [];
        foreach ($this->jobs() as $job) {
            $all = array_merge($all, $this->stepNames($job));
        }

        $this->assertContains(
            $guard,
            $all,
            "`{$guard}` is no longer declared in any job of " . self::WORKFLOW . '.'
        );
    }

    /** The advisory gate itself must still be enforcing — this is not a licence to go lenient. */
    public function test_the_advisory_gate_still_fails_the_build(): void
    {
        $jobs = $this->jobs();
        $advisoryJob = $this->jobContainingStep(self::ADVISORY_STEP);

        $step = null;
        foreach ($jobs[$advisoryJob]['steps'] as $s) {
            if (is_array($s) && ($s['name'] ?? null) === self::ADVISORY_STEP) {
                $step = $s;
            }
        }

        $this->assertIsArray($step, 'premise: the advisory step is readable');
        $this->assertArrayNotHasKey(
            'continue-on-error',
            $step,
            'the advisory gate must still fail its own check. Splitting the job was to stop it '
            . 'silencing the guards, not to stop it reporting.'
        );
        $this->assertStringContainsString(
            'AUDIT_CEILING',
            (string) ($step['env']['AUDIT_CEILING'] ?? '') . ($step['run'] ?? ''),
            'the ceiling comparison must still be the thing this step does.'
        );
    }

    public static function orphanedGuards(): array
    {
        $out = [];
        foreach (self::ORPHANED_GUARDS as $g) {
            $out[$g] = [$g];
        }

        return $out;
    }
}
