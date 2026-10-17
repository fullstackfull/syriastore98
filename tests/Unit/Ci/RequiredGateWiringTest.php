<?php

namespace Tests\Unit\Ci;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * THE GATE OVER THE GATE.
 *
 * WHY THIS FILE EXISTS. Until recently .github/workflows/ci.yml carried
 *
 *     tests:
 *       if: vars.ALLOW_COMPOSER_INSTALL == 'true'
 *
 * so on every repository where that variable was never set -- which is this one -- the Unit
 * suite, the Feature suite and the blocking vendor/-matches-the-lock step NEVER RAN. The job
 * reported `skipped` and the pull request went green. Every test number this project published
 * was measured by hand on a developer machine and enforced by nothing.
 *
 * That condition is gone. Nothing stops it coming back. The ways a required test gate gets
 * quietly disabled are few, well known, and all of them look like housekeeping in a diff:
 *
 *   * an `if:` on the job, so it skips instead of failing;
 *   * `continue-on-error: true` on the job, so it fails green -- which is what sat on the
 *     Feature suite and hid eight failures behind a tick;
 *   * `|| true` or `continue-on-error` on the install, so the suites silently run against
 *     nothing, or do not run at all;
 *   * `--filter`, `--group` or a subdirectory after `--testsuite`, so the suite that runs is
 *     not the suite that is claimed;
 *   * a second, laxer copy of the known-outcome manifest for whichever job is inconvenient.
 *
 * Every item on that list is asserted below against the parsed workflow. A comment asking people
 * not to do these things is not a control; this is.
 *
 * SCOPE. This test says nothing about whether CI passes. It says that if CI passes, it did so
 * having really run the suites. Those are different claims and this project has conflated them
 * before.
 *
 * @see \Tests\Unit\Ci\KnownTestOutcomesCheckerTest for the manifest and the gate script itself
 */
final class RequiredGateWiringTest extends TestCase
{
    private const WORKFLOW = __DIR__ . '/../../../.github/workflows/ci.yml';

    /** The jobs that must really run the PHPUnit suites, and must do so blocking. */
    private const REQUIRED_TEST_JOBS = ['tests', 'tests-degraded'];

    private const MANIFEST = '.github/known-test-outcomes.json';

    /** @var array<string, mixed>|null */
    private static ?array $workflow = null;

    /** @return array<string, mixed> */
    private function workflow(): array
    {
        if (self::$workflow === null) {
            $this->assertFileExists(self::WORKFLOW);
            self::$workflow = (array) Yaml::parseFile(self::WORKFLOW);
        }

        return self::$workflow;
    }

    /** @return array<string, mixed> */
    private function job(string $name): array
    {
        $jobs = $this->workflow()['jobs'] ?? [];

        $this->assertArrayHasKey(
            $name,
            $jobs,
            "The workflow has no '{$name}' job. If it was renamed, rename it here too; if it was "
            . 'DELETED, the required test gate has been removed and that is not housekeeping.'
        );

        return (array) $jobs[$name];
    }

    /** Every `run:` script in a job, concatenated, for the text-level assertions. */
    private function runScripts(string $jobName): string
    {
        $out = '';

        foreach (($this->job($jobName)['steps'] ?? []) as $step) {
            $out .= ($step['run'] ?? '') . "\n";
        }

        return $out;
    }

    public function test_the_workflow_parses(): void
    {
        $this->assertIsArray($this->workflow()['jobs'] ?? null, 'ci.yml has no jobs map.');
    }

    /**
     * BOTH test jobs must exist. `tests` is the strict gate and is what gates the day the dead
     * PayHere upstream returns; `tests-degraded` is what runs the suites in the meantime. Losing
     * either one is a real loss: without `tests` nothing ever verifies the LOCKED dependency set,
     * and without `tests-degraded` the suites do not run at all while the upstream is dead.
     */
    public function test_both_required_test_jobs_exist(): void
    {
        foreach (self::REQUIRED_TEST_JOBS as $name) {
            $this->assertNotSame([], $this->job($name));
        }
    }

    #[DataProvider('requiredTestJobs')]
    public function test_the_job_is_not_conditional(string $jobName): void
    {
        $job = $this->job($jobName);

        $this->assertArrayNotHasKey(
            'if',
            $job,
            "A job-level `if:` on '{$jobName}' makes a REQUIRED gate skippable, and a skipped "
            . "required check is indistinguishable from a passing one in the merge UI. This is "
            . "exactly how `if: vars.ALLOW_COMPOSER_INSTALL == 'true'` kept every PHPUnit suite "
            . 'in this project from ever executing in CI. Put the condition on a STEP if '
            . 'something really is optional.'
        );
    }

    #[DataProvider('requiredTestJobs')]
    public function test_the_job_is_not_continue_on_error(string $jobName): void
    {
        $this->assertArrayNotHasKey(
            'continue-on-error',
            $this->job($jobName),
            "`continue-on-error` at JOB level on '{$jobName}' makes the whole gate advisory. It "
            . 'would fail green, which is worse than not existing because the tick is then a lie.'
        );
    }

    /**
     * ONE step may be continue-on-error, and only one: the Feature phpunit invocation, because
     * eight tests fail BY RECORD so phpunit exits non-zero on every run. The step that JUDGES
     * that failure against the manifest must never be continue-on-error -- that step is the
     * entire gate.
     */
    #[DataProvider('requiredTestJobs')]
    public function test_only_the_feature_phpunit_step_tolerates_its_own_exit_status(string $jobName): void
    {
        $tolerant = [];

        foreach (($this->job($jobName)['steps'] ?? []) as $step) {
            if (($step['continue-on-error'] ?? false) === true) {
                $tolerant[] = $step['name'] ?? '(unnamed)';
            }
        }

        $this->assertCount(
            1,
            $tolerant,
            "Exactly one step in '{$jobName}' may be continue-on-error. Found: " . implode(' | ', $tolerant)
        );
        $this->assertStringContainsString(
            'Feature suite',
            $tolerant[0],
            "The one tolerant step in '{$jobName}' must be the Feature phpunit run and nothing "
            . 'else. Found: ' . $tolerant[0]
        );
    }

    #[DataProvider('requiredTestJobs')]
    public function test_the_known_outcome_assertion_step_is_blocking(string $jobName): void
    {
        $found = false;

        foreach (($this->job($jobName)['steps'] ?? []) as $step) {
            if (! str_contains((string) ($step['run'] ?? ''), 'assert-known-test-outcomes.php')) {
                continue;
            }

            $found = true;

            $this->assertNotSame(
                true,
                $step['continue-on-error'] ?? false,
                "The known-outcome assertion in '{$jobName}' is the gate. continue-on-error on it "
                . 'reduces the whole pipeline to printing numbers, which is the state this project '
                . 'was in for five phases.'
            );
        }

        $this->assertTrue(
            $found,
            "'{$jobName}' never invokes assert-known-test-outcomes.php, so its Feature run is "
            . 'unjudged: eight failures and a ninth would look identical.'
        );
    }

    /**
     * ONE RECORD, NOT TWO. Both jobs must assert against the same committed manifest. A second,
     * laxer manifest for whichever job is inconvenient would let a failure be accepted in one
     * place and rejected in the other, and the project would not know which was true.
     */
    #[DataProvider('requiredTestJobs')]
    public function test_the_job_asserts_against_the_one_committed_manifest(string $jobName): void
    {
        $this->assertStringContainsString(
            self::MANIFEST,
            $this->runScripts($jobName),
            "'{$jobName}' must judge its Feature run against " . self::MANIFEST . ' and no other file.'
        );
    }

    /**
     * THE REAL SUITES, WHOLE. `--testsuite Unit` and `--testsuite Feature` are the entire
     * directories as declared in phpunit.xml. A narrowing flag here would mean the job's name
     * and the job's behaviour disagree, which is the failure mode that is hardest to spot in a
     * green pipeline.
     */
    #[DataProvider('requiredTestJobs')]
    public function test_the_job_runs_both_real_suites_unnarrowed(string $jobName): void
    {
        $scripts = $this->runScripts($jobName);

        $this->assertStringContainsString('--testsuite Unit', $scripts, "'{$jobName}' does not run the Unit suite.");
        $this->assertStringContainsString('--testsuite Feature', $scripts, "'{$jobName}' does not run the Feature suite.");

        foreach (['--filter', '--group ', '--exclude-group', '--testsuite Feature/', '--test-suffix'] as $narrowing) {
            $this->assertStringNotContainsString(
                $narrowing,
                $scripts,
                "'{$jobName}' narrows the suite with `{$narrowing}`. The required gate must run "
                . 'tests/Feature in full -- Security/, Tenancy/ and Commerce/ included. If some '
                . 'test genuinely cannot run, it skips with a named blocker and is recorded in '
                . self::MANIFEST . ', where the gate can see it.'
            );
        }
    }

    /**
     * NO SUBSTITUTE FOR AN INSTALL. If `composer install` is allowed to fail softly the suites
     * either run against a tree nobody measured or do not run at all, and in both cases the job
     * can still reach a green conclusion. The degraded job is permitted to screen a failed
     * install -- that is its whole purpose -- but it must do so with an explicit decision, never
     * with `|| true`.
     */
    #[DataProvider('requiredTestJobs')]
    public function test_the_install_is_never_swallowed(string $jobName): void
    {
        $scripts = $this->runScripts($jobName);

        foreach (['composer install'] as $needle) {
            $this->assertStringContainsString($needle, $scripts, "'{$jobName}' never installs dependencies.");
        }

        foreach (preg_split('/\R/', $scripts) ?: [] as $line) {
            if (! str_contains($line, 'composer install')) {
                continue;
            }

            $this->assertStringNotContainsString(
                '|| true',
                $line,
                "'{$jobName}' swallows an install failure: " . trim($line) . "\n"
                . 'A tolerance written as `|| true` tolerates EVERY dependency failure forever. '
                . 'Screen the failure and name what may fail, as the degraded job does.'
            );
        }

        foreach (($this->job($jobName)['steps'] ?? []) as $step) {
            if (! str_contains((string) ($step['run'] ?? ''), 'composer install')) {
                continue;
            }

            $this->assertNotSame(
                true,
                $step['continue-on-error'] ?? false,
                "The install step in '{$jobName}' is continue-on-error, so the suites below it "
                . 'would run against whatever happens to be on disk.'
            );
        }
    }

    #[DataProvider('requiredTestJobs')]
    public function test_the_job_guards_the_test_database(string $jobName): void
    {
        $this->assertStringContainsString(
            'guard-test-isolation.sh',
            $this->runScripts($jobName),
            "'{$jobName}' runs RefreshDatabase, which runs migrate:fresh, which DROPS EVERY TABLE "
            . 'in whatever database the suite resolves. Every job that runs the suite needs its '
            . 'own copy of that guard, not a reference to another job having run it.'
        );
    }

    #[DataProvider('requiredTestJobs')]
    public function test_the_job_pins_sqlite_in_memory(string $jobName): void
    {
        $env = (array) ($this->job($jobName)['env'] ?? []);

        $this->assertSame('sqlite', $env['DB_CONNECTION'] ?? null, "'{$jobName}' does not pin DB_CONNECTION.");
        $this->assertSame(':memory:', $env['DB_DATABASE'] ?? null, "'{$jobName}' does not pin DB_DATABASE.");
        $this->assertSame('testing', $env['APP_ENV'] ?? null, "'{$jobName}' does not pin APP_ENV.");
    }

    /** @return array<string, array{0:string}> */
    public static function requiredTestJobs(): array
    {
        $out = [];

        foreach (self::REQUIRED_TEST_JOBS as $name) {
            $out[$name] = [$name];
        }

        return $out;
    }

    // -----------------------------------------------------------------------------------------
    // THE CONDITION THAT MUST NOT COME BACK.
    // -----------------------------------------------------------------------------------------

    /**
     * The repository variable that disabled the gate may still be NAMED in comments -- the
     * history is worth keeping -- but it must never again appear in an evaluated expression.
     */
    public function test_no_job_or_step_condition_depends_on_a_repository_variable(): void
    {
        foreach (($this->workflow()['jobs'] ?? []) as $jobName => $job) {
            $conditions = [];

            if (isset($job['if'])) {
                $conditions["job {$jobName}"] = (string) $job['if'];
            }

            foreach (($job['steps'] ?? []) as $i => $step) {
                if (isset($step['if'])) {
                    $conditions[sprintf('%s step %d (%s)', $jobName, $i, $step['name'] ?? 'unnamed')] = (string) $step['if'];
                }
            }

            foreach ($conditions as $where => $expression) {
                $this->assertStringNotContainsString(
                    'vars.',
                    $expression,
                    "{$where} is gated on a repository variable: {$expression}\n"
                    . 'A control whose passing state depends on a setting outside the repository '
                    . 'cannot be reviewed in a diff, and when the variable is unset the check '
                    . 'silently does nothing. That is precisely what '
                    . "`if: vars.ALLOW_COMPOSER_INSTALL == 'true'` did to every PHPUnit suite here."
                );
            }
        }
    }

    // -----------------------------------------------------------------------------------------
    // THE DEGRADED JOB'S OWN HONESTY CONTROLS.
    // -----------------------------------------------------------------------------------------

    /**
     * The degraded job exists because one package's upstream is dead. Three properties keep that
     * from becoming a general-purpose excuse, and all three are in its scripts:
     *
     *   1. the failure is SCREENED, so only the declared-dead package may fail;
     *   2. the committed manifests are proven unmodified by checksum;
     *   3. the package's ABSENCE is asserted, so the job cannot pass while reading as a fix.
     */
    public function test_the_degraded_job_screens_the_install_failure(): void
    {
        $scripts = $this->runScripts('tests-degraded');

        $this->assertStringContainsString(
            'screen-install-failure.php',
            $scripts,
            'The degraded job must decide WHICH packages failed before excluding anything. '
            . 'Without the screen it is `composer install || true` with extra steps.'
        );
        $this->assertStringContainsString(
            'DEAD_PACKAGES',
            $scripts,
            'What is tolerated must be named in the workflow, where a reviewer sees it.'
        );
    }

    public function test_the_degraded_job_proves_it_did_not_edit_the_committed_manifests(): void
    {
        $scripts = $this->runScripts('tests-degraded');

        $this->assertStringContainsString('sha256sum composer.json', $scripts);
        $this->assertStringContainsString('sha256sum composer.lock', $scripts);
        $this->assertStringContainsString(
            'was MODIFIED by this job',
            $scripts,
            'The job must FAIL, not warn, if composer.json or composer.lock changed. They belong '
            . 'to the dependency owner, and dropping the dead package from the real lock is the '
            . 'forbidden remedy: five files use its classes, so the edit turns a recoverable '
            . 'upstream outage into a fatal at class load while reading as a resolved problem.'
        );
    }

    public function test_the_degraded_job_asserts_the_excluded_package_is_absent(): void
    {
        $steps = (array) ($this->job('tests-degraded')['steps'] ?? []);
        $names = array_map(static fn (array $s): string => (string) ($s['name'] ?? ''), $steps);

        $matching = array_values(array_filter($names, static fn (string $n): bool => str_contains($n, 'is ABSENT')));

        $this->assertNotEmpty(
            $matching,
            'The degraded job must assert that the package it excluded really is absent. Without '
            . 'that step a reader skimming a green job would reasonably conclude the dependency '
            . 'problem was solved. It is not solved.'
        );
    }

    /**
     * The tolerance must be spelt out at the one call site that measures the tree, and must be
     * the narrow flag rather than a wholesale downgrade to --report.
     */
    public function test_the_degraded_job_measures_the_tree_against_the_real_lock(): void
    {
        $scripts = $this->runScripts('tests-degraded');

        $this->assertStringContainsString('guard-composer-lock-integrity.sh', $scripts);
        $this->assertStringContainsString(
            '--allow-missing=',
            $scripts,
            'The degraded tree must be measured against the REAL committed lock with the narrow '
            . 'absent-only tolerance.'
        );
        $this->assertStringNotContainsString(
            'guard-composer-lock-integrity.sh . --report',
            $scripts,
            '--report exits 0 on ANY drift. In a job that just performed the install, drift is a '
            . 'real defect and must block.'
        );
    }

    /**
     * THE STRICT JOB STAYS STRICT. It must not acquire the tolerance: it is the job that gates
     * the day the upstream returns, and it earns that role by refusing to run on anything but an
     * exact install from the lock.
     */
    public function test_the_strict_job_has_no_tolerance_of_any_kind(): void
    {
        $scripts = $this->runScripts('tests');

        $this->assertStringContainsString('guard-composer-lock-integrity.sh', $scripts);
        $this->assertStringNotContainsString(
            '--allow-missing',
            $scripts,
            "The strict 'tests' job must keep demanding that vendor/ match composer.lock exactly. "
            . 'It is expected to be RED while the PayHere upstream is dead, and that red is the '
            . 'only remaining signal that an owner decision is still owed.'
        );
        $this->assertStringNotContainsString(
            'make-degraded-manifest.php',
            $scripts,
            "The strict job must install from the committed lock, unaltered."
        );
    }

    // -----------------------------------------------------------------------------------------
    // P01-A' -- THE GATES THAT RAN ONLY ON A PULL REQUEST.
    //
    // REQUIRED_TEST_JOBS deliberately does NOT gain `regression-gate`: that job is conditional
    // by design and `test_the_job_is_not_conditional` would fail it. What follows asserts the
    // narrower, true properties instead -- that the controls which were trapped inside that
    // conditional job now run on every event, and that the job itself has not quietly been
    // deleted or widened.
    // -----------------------------------------------------------------------------------------

    /**
     * Steps whose name matches, across every job.
     *
     * @return array<string, array<string, mixed>>
     */
    private function stepsMatching(string $pattern, bool $unconditionalJobsOnly = false): array
    {
        $found = [];

        foreach (($this->workflow()['jobs'] ?? []) as $jobName => $job) {
            $job = (array) $job;

            if ($unconditionalJobsOnly && isset($job['if'])) {
                continue;
            }

            foreach (($job['steps'] ?? []) as $step) {
                $step = (array) $step;

                if (isset($step['name']) && preg_match($pattern, (string) $step['name']) === 1) {
                    $found[$jobName . '::' . $step['name']] = $step;
                }
            }
        }

        return $found;
    }

    /**
     * THE SINGLE LARGEST HOLE PHASE 00B FOUND. `flag-sensitive-diff.sh` is the only control
     * standing between a change to config/, routes/, Middleware/, Policies/, Permission, Role,
     * Billing, Token or Secret and `main`, and it lived in exactly one place: the last-but-one
     * step of `regression-gate`, which is `if: github.event_name == 'pull_request'`. Exactly one
     * pull request has ever been opened against this repository, so in practice the checklist
     * had never run.
     *
     * This asserts the property, not the location: SOME unconditional job must carry it.
     */
    public function test_the_security_sensitive_diff_checklist_runs_on_every_event(): void
    {
        $anywhere = $this->stepsMatching('/sensitive[- ]diff/i');

        $this->assertNotSame(
            [],
            $anywhere,
            'No step references a security-sensitive diff any more. If the checklist was '
            . 'deliberately retired, that is an owner decision and this test should be deleted '
            . 'in the same commit that states it -- not left passing over a removed control.'
        );

        $unconditional = $this->stepsMatching('/sensitive[- ]diff/i', true);

        $this->assertNotSame(
            [],
            $unconditional,
            "The checklist exists only inside a CONDITIONAL job, so a direct push to main is "
            . "never asked the questions. It needs nothing but git and bash, so it belongs in a "
            . "job with no `if:`. Found only in: " . implode(', ', array_keys($anywhere))
        );

        foreach ($unconditional as $where => $step) {
            $this->assertArrayNotHasKey(
                'if',
                $step,
                "{$where} sits in an unconditional job but is itself conditional, which puts the "
                . 'control back behind an event test one level down.'
            );
        }
    }

    /**
     * A PUSH HAS NO PULL REQUEST BODY, so the step must say which mode it wants rather than let
     * the script guess. `enforce` blocks on a pull request; `report` makes a push-time bypass
     * loudly visible. An unknown mode is a usage error in the script, never a silent downgrade.
     */
    public function test_the_checklist_step_chooses_its_mode_explicitly(): void
    {
        $steps = $this->stepsMatching('/sensitive[- ]diff/i', true);
        $this->assertNotSame([], $steps);

        foreach ($steps as $where => $step) {
            $run = (string) ($step['run'] ?? '');

            $this->assertStringContainsString('flag-sensitive-diff.sh', $run, "{$where}");
            $this->assertStringContainsString(
                'enforce',
                $run,
                "{$where} never asks for enforcement, so the gate cannot block on a pull request."
            );
            $this->assertStringContainsString(
                'report',
                $run,
                "{$where} has no push-time reporting mode, so a push is either blocked or silent."
            );
            $this->assertStringContainsString(
                'pull_request',
                $run,
                "{$where} does not distinguish the pull-request event, so it cannot enforce on "
                . 'the one event where an acknowledgement can actually be given.'
            );
        }
    }

    /**
     * THE GUARD THAT SAW ONE COMMIT OF A PUSH. This step read only the pull-request base and
     * fell straight through to HEAD~1, while its siblings (`php -l`, the secret scan) already
     * consulted `github.event.before`. A packages/workdo/ edit in commit 1 of a 3-commit push
     * was therefore never inspected by the guard whose entire job is to catch it.
     */
    public function test_the_workdo_guard_sees_the_whole_pushed_range(): void
    {
        $steps = $this->stepsMatching('/packages\/workdo\/ is unmodified/i');

        $this->assertNotSame(
            [],
            $steps,
            'The vendored-module guard step is gone. packages/workdo/ is commercial upstream '
            . 'code; an in-place edit is reverted by the next upstream update and leaves no '
            . 'patch record.'
        );

        foreach ($steps as $where => $step) {
            $env = implode("\n", array_map('strval', (array) ($step['env'] ?? [])));
            $run = (string) ($step['run'] ?? '');

            $this->assertStringContainsString(
                'github.event.before',
                $env,
                "{$where} does not take `github.event.before`, so on a push it can only fall "
                . 'back to HEAD~1 and inspect the last commit of the range.'
            );
            $this->assertStringContainsString(
                'BEFORE_SHA',
                $run,
                "{$where} receives the push base but never reads it."
            );
        }
    }

    /**
     * THE CONTRACT SUITE MUST RUN IN WHICHEVER JOB REACHES PHPUNIT. Three cross-repository
     * artefacts are SHA-pinned on both sides; a drift means Store Syria and Lynomia silently
     * disagree about the wire format. It ran in the strict job only, so in exactly the situation
     * `tests-degraded` exists for -- the strict install failing -- contract drift was ungated.
     */
    #[DataProvider('requiredTestJobs')]
    public function test_the_job_runs_the_cross_repository_contract_suite(string $jobName): void
    {
        $this->assertStringContainsString(
            '--testsuite Contract',
            $this->runScripts($jobName),
            "'{$jobName}' does not run the Contract suite. If this job is the only one that "
            . 'reaches PHPUnit, Lynomia contract drift between the two repositories goes '
            . 'unnoticed.'
        );
    }

    /**
     * The merge-base comparison genuinely needs a pull request -- it resolves
     * `origin/${{ github.base_ref }}` -- so this job staying conditional is correct. Asserted so
     * that deleting it, or widening it to push where it cannot work, is a deliberate act.
     */
    public function test_the_regression_gate_job_is_still_present_and_pull_request_scoped(): void
    {
        $job = $this->job('regression-gate');

        $this->assertArrayHasKey(
            'if',
            $job,
            'regression-gate is no longer conditional. It resolves the merge base from '
            . '`origin/${{ github.base_ref }}`, which only a pull_request event supplies, so on '
            . 'a push it would fail resolving a base rather than compare anything.'
        );
        $this->assertStringContainsString('pull_request', (string) $job['if']);
    }
}
