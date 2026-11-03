<?php

namespace Tests\Unit\Ci;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * THE GATE OVER THE HUMAN GATE, and it exists because the first attempt at this workflow shipped
 * the bypass RequiredGateWiringTest catches.
 *
 * .github/SECURITY-DIFF-REVIEW.md requires a person to work a checklist and leave a marker in the
 * pull request DESCRIPTION. ci.yml's trigger takes the default pull_request types, so an edit to
 * the description triggered nothing and a job re-run replays the original payload with the
 * pre-edit body: a reviewer who signed the checklist watched the gate stay red, and the only ways
 * past it were a cosmetic commit or merging through a red required check.
 *
 * THE FIX THAT WAS TRIED FIRST, and why this file is here. Adding `edited` to ci.yml and giving
 * the code jobs `if: github.event.action != 'edited'` -- so a text change would not re-run ten
 * minutes of suites -- turns a required gate into a skippable one. A check run is per-commit, so
 * a job that skipped on the edit published a `skipped` run for the same head SHA and REPLACED the
 * `failure` from the code run, and a skipped required check reads as satisfied in the merge UI.
 * Failing tests plus a description edit would have been a green required check. That diff was
 * caught by RequiredGateWiringTest::test_the_job_is_not_conditional, which already existed for
 * precisely this reason -- so the invariants of the workflow that replaced it are written down
 * here rather than left to the next person's judgement.
 *
 * SCOPE. Nothing here says the attestation passes. It says that if the attestation ever reports
 * green, it did so having actually asked the script, on the body as it stands now.
 */
final class SecurityAttestationWiringTest extends TestCase
{
    private const WORKFLOW = __DIR__ . '/../../../.github/workflows/security-attestation.yml';

    private const CI_WORKFLOW = __DIR__ . '/../../../.github/workflows/ci.yml';

    private const SCRIPT = '.github/scripts/flag-sensitive-diff.sh';

    private function workflow(): array
    {
        $this->assertFileExists(self::WORKFLOW);

        return (array) Yaml::parseFile(self::WORKFLOW);
    }

    /** `on` is parsed by Symfony's YAML as the boolean true, per YAML 1.1. */
    private function triggers(array $workflow): array
    {
        return (array) ($workflow[true] ?? $workflow['on'] ?? []);
    }

    private function job(): array
    {
        return (array) (($this->workflow()['jobs'] ?? [])['attestation'] ?? []);
    }

    /**
     * The whole point: a description edit has to start a run. Without `edited` the marker a
     * reviewer adds is never read, and the gate can only be cleared by pushing something.
     */
    public function test_a_description_edit_triggers_the_check(): void
    {
        $types = (array) ($this->triggers($this->workflow())['pull_request']['types'] ?? []);

        $this->assertContains('edited', $types,
            'without `edited` a reviewer who signs the checklist cannot clear the gate without a cosmetic commit');

        foreach (['opened', 'synchronize', 'reopened'] as $type) {
            $this->assertContains($type, $types,
                "dropping '{$type}' would leave a sensitive diff unchecked on the event that introduces it");
        }
    }

    /**
     * The same rule RequiredGateWiringTest enforces for the suites, for the same reason, on the
     * job that is now the only place the attestation is enforced: a condition makes it skippable,
     * and a skipped required check is indistinguishable from a passing one.
     */
    public function test_the_job_is_not_conditional_or_tolerant(): void
    {
        $job = $this->job();

        $this->assertNotSame([], $job, 'the attestation job is gone');
        $this->assertArrayNotHasKey('if', $job,
            'a job-level `if:` makes this gate skippable, and a skipped required check reads as satisfied');
        $this->assertArrayNotHasKey('continue-on-error', $job,
            'continue-on-error on this job would make the gate fail green');

        foreach ((array) ($job['steps'] ?? []) as $step) {
            $this->assertArrayNotHasKey('continue-on-error', $step,
                'a step that tolerates its own exit status cannot enforce anything');
            $this->assertStringNotContainsString('|| true', (string) ($step['run'] ?? ''),
                'swallowing the exit status is the same as not running the check');
        }
    }

    /** It has to actually ask the script, on the body the event carries, against the merge base. */
    public function test_it_asks_the_script_with_the_current_body(): void
    {
        $steps = (array) ($this->job()['steps'] ?? []);
        $runs = implode("\n", array_map(static fn ($s) => (string) ($s['run'] ?? ''), $steps));

        $this->assertStringContainsString(self::SCRIPT, $runs, 'the job does not run the gate script');
        $this->assertStringContainsString('git merge-base', $runs, 'the job does not resolve a merge base to diff against');

        $envs = array_merge(...array_map(static fn ($s) => (array) ($s['env'] ?? []), $steps)) ?: [];
        $this->assertSame('${{ github.event.pull_request.body }}', $envs['PR_BODY'] ?? null,
            'the body must come from the event payload -- on an `edited` event that is the body as it stands');
    }

    /**
     * Enforced in exactly ONE place. Two would be worse than one: whichever copy does not run on
     * an edit keeps its red conclusion and the reviewer is blocked anyway, which is the state this
     * workflow was created to end.
     */
    public function test_the_attestation_is_enforced_in_exactly_one_workflow(): void
    {
        $this->assertStringNotContainsString(self::SCRIPT, (string) file_get_contents(self::CI_WORKFLOW),
            'ci.yml enforces the attestation again; it does not run on `edited`, so the gate cannot be cleared there');
    }

    /**
     * ci.yml must stay unconditional on the event action. This is the invariant the first attempt
     * broke, asserted from this side too, because the hazard is a property of the PAIR of files:
     * a skip there plus a replacement check run here is how a failure becomes satisfied.
     */
    public function test_ci_has_no_event_action_conditions(): void
    {
        $this->assertStringNotContainsString('github.event.action', (string) file_get_contents(self::CI_WORKFLOW),
            'a ci.yml job conditional on the event action publishes a skipped run that replaces a failed one');
    }

    /**
     * THE GATE HAS TO SEE THE FILE THE LIMITERS LIVE IN. It did not: the pattern matched no
     * alternative against the service provider, so a diff touching only the limiter closures --
     * retuning a multiple, reordering the throttle tiers, the change that re-opens the P0 these
     * pull requests close -- printed "no security-sensitive paths" and exited 0.
     *
     * Asserted by the paths rather than by the pattern's text, so the pattern can be rewritten and
     * these still have to be covered.
     *
     * [2026-11-02, P1-SEC-56] AND THE EXTRACTION NOW HONOURS THAT SENTENCE, which it did not.
     * It used to read the pattern with `preg_match("/^PATTERN='(.+)'$/m")` -- i.e. by assuming
     * the source line is a single-quoted one-liner. So the docblock claimed independence from the
     * pattern's text while the test depended on its exact spelling, and when P1-SEC-56 turned the
     * one-liner into a commented array (each entry carrying the reason it exists, because a line
     * of twenty-six alternations is a list nobody can audit) THIS TEST was what failed -- which
     * is the system working, but for the wrong reason: the limiter surfaces were all still
     * covered.
     *
     * It now asks BASH for the pattern the script actually builds. That is the value the gate
     * uses, so a refactor of how it is assembled cannot break this test and a change to what it
     * covers cannot slip past it.
     */
    public function test_the_pattern_covers_the_surfaces_the_limiters_live_on(): void
    {
        $script = realpath(__DIR__ . '/../../../' . self::SCRIPT);
        $this->assertNotFalse($script, self::SCRIPT . ' is missing');

        // Replay only the assignment block, then print the result. No part of the gate runs.
        $cmd = sprintf(
            'bash -c %s 2>/dev/null',
            escapeshellarg(sprintf(
                'eval "$(sed -n \'/^PATTERN_PARTS=(/,/^PATTERN=/p\' %s)"; printf %%s "$PATTERN"',
                escapeshellarg($script)
            ))
        );
        $pattern = trim((string) shell_exec($cmd));

        $this->assertNotSame('', $pattern, 'could not read the pattern the gate actually builds');
        $this->assertStringStartsWith('(', $pattern, "the gate's pattern is not an alternation: {$pattern}");

        $covered = [
            'packages/workdo/LynomiaChat/src/app/Providers/LynomiaChatServiceProvider.php' => 'every rate limiter in the integration',
            'packages/workdo/LynomiaChat/src/app/Services/EventSignature.php' => 'the delivery signature',
            'packages/workdo/LynomiaChat/src/app/Http/Middleware/AuthenticateCommerceApi.php' => 'the credential check the tiers sit around',
            'packages/workdo/LynomiaChat/src/routes/web.php' => 'the throttle ordering',
            'packages/workdo/LynomiaChat/src/config/lynomia.php' => 'the budgets',
            'packages/workdo/LynomiaChat/src/database/migrations/2026_11_03_000100_add_commerce_activation_to_lynomia_store_links.php' => 'schema',

            /*
             * [2026-11-02] ADDED BECAUSE THIS LIST DID NOT ACTUALLY RATCHET THE ENTRY IT NAMES.
             * Every path above also matches some OTHER alternative -- the provider via
             * `Providers/`, `EventSignature` via `Signature`, the middleware via `Middleware/`,
             * then `routes/`, `/config/`, `database/migrations/`. Measured: deleting
             * `packages/workdo/LynomiaChat/src/app/` from the pattern left this test GREEN. That
             * is faithful to the docblock -- the property is that the PATHS are covered, not which
             * alternative covers them -- but it meant the integration's jobs and listeners, which
             * nothing else matches, were unguarded by any assertion. A `src/app/Jobs/` path
             * fails the moment that entry is removed.
             */
            'packages/workdo/LynomiaChat/src/app/Jobs/DeliverCommerceEvent.php' => 'the integration\'s own jobs, which no other alternative covers',
            'packages/workdo/LynomiaChat/src/app/Listeners/SyncStore.php' => 'and its listeners',
        ];

        foreach ($covered as $path => $why) {
            $this->assertSame(1, preg_match('#' . $pattern . '#', $path), "the gate does not flag {$path} ({$why})");
        }

        $this->assertSame(0, preg_match('#' . $pattern . '#', 'packages/workdo/LynomiaChat/src/resources/views/setup.blade.php'),
            'a view-only diff should not require a security checklist; the pattern has widened past its purpose');
    }
}
