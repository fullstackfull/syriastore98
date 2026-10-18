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
}
