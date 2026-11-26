<?php

namespace Tests\Unit\Security;

use App\Support\Modules\ModuleBenefitCallback;
use App\Support\Modules\ModulePaymentFlows;
use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * GAP-138 — THE RATCHET: NO LIVE `webhook.site` CALLBACK DESTINATION SURVIVES ANYWHERE.
 * =================================================================================================
 *
 * `post.url` is Tap's SERVER-TO-SERVER notification destination. A `webhook.site` bin is public to
 * anyone holding its URL, and this one's URL is committed in this repository and in its git
 * history, so it is not a secret. Five sites had it: two first-party (removed under P4-F046) and
 * three vendored (neutralised by the host overrides this file guards).
 *
 * WHAT A RATCHET HAS TO ANSWER HERE, AND WHY A BOOLEAN CANNOT
 * -------------------------------------------------------------------------------------------------
 * "Is the string absent" is the wrong question for the vendored half: `packages/workdo/` must not
 * be edited, so the literal is STILL THERE and always will be until an upstream update removes it.
 * A ratchet asserting absence over that tree could only ever be a standing red light — which is
 * exactly what `GatewayCallbackDestinationTest::VENDORED_COPIES_NOT_ASSERTED` says, and why it
 * recorded the three rather than guarding them.
 *
 * So this file asserts REACHABILITY instead, in three parts:
 *
 *   1. FIRST-PARTY: the string must be ABSENT from `app/` entirely. Nothing stops a host file
 *      being edited, so absence is the right bar there.
 *   2. VENDORED: the set of files naming a bin must be EXACTLY the three declared in
 *      `ModuleBenefitCallback::VENDORED_SITES`. A fourth copy arriving in a module update fails
 *      the build instead of shipping; a copy disappearing prompts the override to be retired
 *      rather than quietly kept.
 *   3. COVERAGE: every declared vendored site must have a host override that is bound over it,
 *      subclasses it, overrides the public `process()`, declares its own `processBenefit()` and
 *      takes its destination from the one constant. That is what makes the vendored literal
 *      UNREACHABLE: `process()` never reaches the vendored private method for `Benefit`.
 *
 * COMMENTS ARE STRIPPED BEFORE EVERY ASSERTION, and this is not a precaution — it is required.
 * `ModuleBenefitCallback`, the three overrides, `AppServiceProvider`, `PaymentService`,
 * `BenefitPaymentController` and this file's own docblock all name the bin while explaining its
 * removal. A raw `str_contains` would read those explanations as the defect; CLAUDE.md records six
 * measured instances of a census satisfied or broken by prose about the thing it measures, which is
 * why `Tests\Support\StripsSourceComments` exists.
 *
 * NO BOOTED APPLICATION, deliberately. This is a source and reflection census; it must hold even
 * when no module is loaded, and `StripsSourceComments` computes the repository root itself for
 * exactly that case. Reflection on the OVERRIDES map is therefore done on the strings, with
 * `class_exists()` guarded — the live-container half is
 * `Tests\Feature\Payments\ModuleBenefitCallbackDestinationTest`, which loads the modules through
 * the production loader and resolves the real controller.
 */
class ModuleBenefitCallbackCensusTest extends TestCase
{
    use StripsSourceComments;

    /** Never asserted raw; every use goes through a comment-stripped source. */
    private const BIN_HOST = 'webhook.site';

    private const ROOT = __DIR__ . '/../../..';

    /**
     * THE PREMISE. A sweep that finds nothing reads exactly like a sweep that found nothing wrong,
     * so both trees are asserted against a floor before anything is concluded from them.
     */
    public function test_premise_both_sweeps_reach_their_trees(): void
    {
        $this->assertGreaterThan(200, count($this->phpFilesIn('app')),
            'the app/ sweep found almost nothing, so every assertion built on it is vacuous');

        $this->assertGreaterThan(1500, count($this->phpFilesIn('packages/workdo')),
            'the packages/workdo sweep found almost nothing, so the "exactly three copies" '
            . 'assertion below would pass against an empty set');
    }

    /**
     * (1) FIRST-PARTY CODE NAMES NO CALLBACK BIN.
     *
     * Overlaps `GatewayCallbackDestinationTest` by design: that file guards the two P4-F046 sites,
     * this one guards the same property against the FOUR host files GAP-138 added, which name the
     * bin in their prose. The overlap is the point — a ratchet nobody else holds is one deletion
     * away from gone.
     */
    public function test_no_first_party_file_names_a_callback_bin(): void
    {
        $offenders = [];

        foreach ($this->filesNamingTheBin('app') as $relative) {
            $hits = substr_count($this->sourceWithoutComments($relative), self::BIN_HOST);

            if ($hits > 0) {
                $offenders[$relative] = $hits;
            }
        }

        ksort($offenders);

        $this->assertSame([], $offenders,
            "First-party code names a third-party callback bin in LIVE CODE, not in a comment.\n"
            . "A gateway callback carries, for every payment, who paid what and for how much, plus "
            . "the charge id this platform's return paths treat as proof of payment -- and a "
            . "webhook.site bin is readable by anyone holding its URL, which is committed here and "
            . "in git history.\n"
            . 'Found: ' . json_encode($offenders));
    }

    /**
     * (2) EXACTLY THE THREE DECLARED VENDORED COPIES, NO MORE AND NO FEWER.
     *
     * This is the assertion that stops a fourth copy appearing. It is also the one that tells us
     * when upstream has fixed it themselves.
     */
    public function test_exactly_the_declared_vendored_files_name_a_callback_bin(): void
    {
        $found = [];

        foreach ($this->filesNamingTheBin('packages/workdo') as $relative) {
            if (substr_count($this->sourceWithoutComments($relative), self::BIN_HOST) > 0) {
                $found[] = $relative;
            }
        }

        sort($found);

        $declared = array_keys(ModuleBenefitCallback::VENDORED_SITES);
        sort($declared);

        $this->assertSame($declared, $found,
            "The set of VENDORED files naming a third-party callback bin has changed.\n"
            . "A NEW one is a live data-exposure defect on a payment path and needs its own host "
            . "override in App\\Services\\Modules plus a row in "
            . "ModuleBenefitCallback::VENDORED_SITES and ::OVERRIDES -- binding is the only remedy, "
            . "because packages/workdo/ must not be edited.\n"
            . "A REMOVED one is good news: an upstream update has fixed it, and the matching host "
            . "override should be RETIRED rather than left shadowing the module's own charge.\n"
            . 'Expected ' . json_encode($declared) . ' and found ' . json_encode($found));
    }

    /** (3a) Every declared vendored site has a host override declared for it. */
    public function test_every_vendored_bin_site_has_a_host_override(): void
    {
        foreach (ModuleBenefitCallback::VENDORED_SITES as $file => $vendoredClass) {
            $override = ModuleBenefitCallback::overrideFor($vendoredClass);

            $this->assertNotNull($override,
                $file . ' names a callback bin but ' . $vendoredClass . ' has no entry in '
                . 'ModuleBenefitCallback::OVERRIDES, so nothing is bound over it and the bin is '
                . 'LIVE for every payer on that rail.');

            $this->assertFileExists(
                self::ROOT . '/app/Services/Modules/' . class_basename($override) . '.php',
                $override . ' is declared in ::OVERRIDES but its file does not exist.'
            );
        }

        $this->assertSame(
            count(ModuleBenefitCallback::VENDORED_SITES),
            count(ModuleBenefitCallback::OVERRIDES),
            'OVERRIDES and VENDORED_SITES have drifted apart: one binds a service the census does '
            . 'not watch, or the census watches a service nothing is bound over.'
        );
    }

    /**
     * (3b) THE OVERRIDES ARE REALLY OVERRIDES, read from their own source with comments stripped.
     *
     * A file that existed, extended the right parent and did nothing would satisfy (3a) and leave
     * the bin live. Each of these four properties is what makes the vendored literal unreachable.
     */
    public function test_each_host_override_actually_overrides_the_vendored_benefit_branch(): void
    {
        foreach (ModuleBenefitCallback::OVERRIDES as $vendored => $override) {
            $relative = 'app/Services/Modules/' . class_basename($override) . '.php';
            $code = $this->sourceWithoutComments($relative);
            $parent = class_basename($vendored);

            $this->assertStringContainsString('extends ' . $parent, $code,
                $relative . ' does not extend ' . $vendored . ', so the controller\'s type hint '
                . 'cannot be satisfied and the binding would fail at resolution.');

            $this->assertStringContainsString('public function process(', $code,
                $relative . ' does not override the public process(), which is the ONLY seam: '
                . 'processBenefit() is private in the vendored service and cannot be overridden.');

            $this->assertStringContainsString('function processBenefit(', $code,
                $relative . ' declares no processBenefit() of its own, so the Benefit branch would '
                . 'fall through to the vendored private method and its public bin.');

            $this->assertStringContainsString('parent::process(', $code,
                $relative . ' never delegates to parent::process(), so every gateway except '
                . 'Benefit has been lost by the override -- a far worse regression than the defect.');

            $this->assertStringContainsString('ModuleBenefitCallback::DESTINATION', $code,
                $relative . ' does not take its callback destination from the one constant that '
                . 'records the owner decision.');

            $this->assertSame(0, substr_count($code, self::BIN_HOST),
                $relative . ' names the bin in LIVE CODE. The host override is meant to remove it.');
        }
    }

    /**
     * (3c) THE OVERRIDE STILL WRITES THE FLOW KEY THE REST OF THE PLATFORM READS.
     *
     * RAISED BY THE `tenant-authz-reviewer` CHECKLIST (shape 6, payment ownership) rather than by
     * the original brief, and it is the one way this change could have broken a payment while every
     * callback assertion stayed green.
     *
     * Each vendored `processBenefit()` ends by putting the request into the session under the
     * module's own key, and TWO things downstream depend on it: the module's completion writer
     * tests it with `== null`, and `DisabledModuleRouteGuard` reads the same key through
     * `ModulePaymentFlows::vendoredFlowKey()` to tell a RETURN from a gateway apart from a new
     * transaction through a switched-off application. An override that reproduced the payload
     * perfectly and dropped this line would send a payer to Tap, take their money, and lose the
     * donation or the ticket on the way back -- with no failing test anywhere.
     *
     * Asserted against `ModulePaymentFlows`' own table, not against a literal repeated here, so the
     * two cannot drift apart.
     */
    public function test_each_host_override_still_writes_the_modules_payment_flow_key(): void
    {
        foreach (ModuleBenefitCallback::OVERRIDES as $vendored => $override) {
            $module = explode('\\', ltrim($vendored, '\\'))[1] ?? '';
            $key = ModulePaymentFlows::vendoredFlowKey($module);

            $this->assertNotNull($key,
                'ModulePaymentFlows has no flow key for "' . $module . '", derived from '
                . $vendored . '. One of the two has been renamed and they must be reconciled.');

            $code = $this->sourceWithoutComments(
                'app/Services/Modules/' . class_basename($override) . '.php'
            );

            $this->assertStringContainsString("session(['" . $key . "' =>", $code,
                class_basename($override) . " no longer writes the `{$key}` session entry that the "
                . $module . " module's completion writer and DisabledModuleRouteGuard both read. A "
                . 'Benefit payer would be charged and their donation, ticket or campaign lost on '
                . 'the return -- the callback assertions cannot see this.');
        }
    }

    /**
     * (4) THE REPLACEMENT VALUE, AND THE DECISION THAT WAS NOT TAKEN.
     *
     * The owner decision this work rests on is "remove webhook.site". CHOOSING A DESTINATION is the
     * separate, still-open escalation P1-SEC-55. If this constant is ever anything but the empty
     * string, somebody has taken that decision in a code change — which is what this assertion
     * exists to stop.
     *
     * AND NOT `null`. `PaymentService.php:1817-1845` measured why: the card flows reach Tap through
     * `Payment::charge()`, which builds its JSON by string concatenation and so sends
     * `"post":{"url":""}`. These sites `json_encode()` an array, so `null` would arrive as
     * `"url":null` -- a shape no Tap flow in this repository has ever sent and which nobody here
     * can verify, there being no Tap credential in this container.
     */
    public function test_the_destination_is_the_empty_string_the_card_flows_already_send(): void
    {
        $this->assertSame('', ModuleBenefitCallback::DESTINATION,
            'ModuleBenefitCallback::DESTINATION is no longer the empty string. Choosing a real '
            . 'callback destination is the OPEN owner escalation P1-SEC-55 and is not authorised '
            . 'in a code change. If the owner has now decided, update '
            . 'docs/security/P01-SECURITY-WORK-QUEUE.md and this test together.');

        $this->assertNotNull(ModuleBenefitCallback::DESTINATION,
            'DESTINATION is null, which reaches Tap as `"url":null` -- a shape nothing in this '
            . 'repository sends and nobody here can verify. See PaymentService.php:1817-1845.');
    }

    /**
     * (5) THE BINDING EXISTS IN THE PROVIDER THAT ALWAYS RUNS.
     *
     * Source-level, because this file boots no application. The live-container proof is
     * `ModuleBenefitCallbackDestinationTest::test_the_container_hands_the_controller_the_host_override`,
     * which resolves the real vendored controller with the modules loaded.
     */
    public function test_the_overrides_are_bound_in_the_application_service_provider(): void
    {
        $provider = $this->sourceWithoutComments('app/Providers/AppServiceProvider.php');

        $this->assertStringContainsString('ModuleBenefitCallback::OVERRIDES', $provider,
            'AppServiceProvider::register() no longer binds the host overrides, so every injected '
            . 'controller is handed the VENDORED service and the bin is live again. A passing '
            . 'payload test proves nothing without this: the payload is only reached if the '
            . 'override is what the container hands over.');

        $this->assertStringContainsString('$this->app->bind(', $provider,
            'the overrides are declared but nothing binds them.');

        $this->assertStringContainsString(
            'App\\Providers\\AppServiceProvider::class',
            (string) file_get_contents(self::ROOT . '/bootstrap/providers.php'),
            'AppServiceProvider is no longer registered, so the binding never runs.'
        );
    }

    /**
     * Every PHP file under $dir whose RAW bytes name the bin — the candidates, before comments.
     *
     * THE RAW PASS IS A FILTER, NOT THE MEASUREMENT. It can only over-include (a comment mentioning
     * the bin is a candidate; a file not mentioning it cannot be a defect), and the caller then puts
     * each candidate through `sourceWithoutComments()`, which is where the real question is asked.
     *
     * AND IT IS WHAT MAKES THE SWEEP WORK AT ALL. Stripping every file first hit
     * `packages/workdo/BulkDelete/src/resources/views/pages/button.blade.php`, which is ENTIRELY a
     * Blade comment: the shared stripper refuses a file it has emptied -- correctly, because a
     * stripper that eats its subject turns every absence assertion into a free pass -- and the
     * census died on a file that has nothing to do with payments. Filtering first means only the
     * handful of files that could possibly be defects are ever stripped, which also takes the
     * 2,085-file vendored sweep from seconds to milliseconds.
     *
     * `.blade.php` IS DELIBERATELY INCLUDED. A bin in a template would not be a Tap `post.url`, but
     * it could be an exfiltration target in inline JavaScript, and excluding templates would be a
     * census that stops looking at part of its subject.
     *
     * @return list<string> repository-relative paths
     */
    private function filesNamingTheBin(string $dir): array
    {
        $candidates = [];

        foreach ($this->phpFilesIn($dir) as $relative) {
            if (str_contains((string) file_get_contents(self::ROOT . '/' . $relative), self::BIN_HOST)) {
                $candidates[] = $relative;
            }
        }

        return $candidates;
    }

    /** @return list<string> repository-relative paths of every PHP file under $dir */
    private function phpFilesIn(string $dir): array
    {
        $root = realpath(self::ROOT);

        $this->assertNotFalse($root, 'the repository root does not resolve');

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = ltrim(str_replace($root, '', $file->getPathname()), '/');
            }
        }

        sort($files);

        return $files;
    }

    /**
     * EACH OVERRIDE'S COMPARISON LITERAL MUST EXIST AS A `case` LABEL IN THE SERVICE IT OVERRIDES.
     *
     * THIS TEST EXISTS BECAUSE ITS ABSENCE LET A LIVE DEFECT SHIP THROUGH SELF-REVIEW.
     * `LotteryBenefitCallbackOverride` compared `'Benefit'` while
     * `LotteryPaymentService:85` is `case 'benefit':` — lower case, and the only one of the three
     * services spelled that way. PHP's `switch` compares with `==`, which is case-sensitive for
     * strings, so the override intercepted a value the module rejects and handed back the one it
     * accepts: the bin stayed reachable on that rail, and the override would additionally have
     * enabled a payment branch that had only ever produced a 422.
     *
     * Found by independent review. Nothing mechanical could have caught it, because every
     * assertion in both new suites drove `'Benefit'` — the string the override recognised — so the
     * suite agreed with the bug. This asks the question the suite could not: does the label the
     * host compares actually exist in the parent it is standing in front of?
     *
     * SOURCE-LEVEL ON PURPOSE. The vendored services are not loaded in a test run (no
     * `add_on_managers` row), so reflection is unavailable and the files are read as text. Both
     * sides are read comment-stripped, because CLAUDE.md records six occasions when a census was
     * satisfied — or broken — by prose about the thing it measures, and these files now carry
     * long comments quoting both spellings.
     */
    public function test_every_override_compares_a_label_its_parent_actually_switches_on(): void
    {
        $checked = 0;

        foreach (ModuleBenefitCallback::VENDORED_SITES as $file => $vendoredClass) {
            $override = ModuleBenefitCallback::OVERRIDES[$vendoredClass] ?? null;
            $this->assertNotNull($override, $vendoredClass . ' has no override.');

            $overrideRelative = 'app/Services/Modules/' . class_basename($override) . '.php';
            $this->assertFileExists(self::ROOT . '/' . $overrideRelative);

            // Both sides comment-stripped: these files now carry long comments quoting BOTH
            // spellings, so a raw read would find the label in the prose that explains it.
            $overrideSource = $this->sourceWithoutComments($overrideRelative);
            $vendoredSource = $this->sourceWithoutComments($file);

            // The literal the host compares against to decide "this is the Benefit branch".
            $this->assertSame(
                1,
                preg_match("/===\\s*'([^']+)'/", $overrideSource, $m),
                class_basename($override) . ' has no single === literal comparison; this test '
                . 'reads that comparison and must be taught the new shape rather than deleted.'
            );

            $literal = $m[1];
            $checked++;

            $this->assertMatchesRegularExpression(
                "/case\\s+'" . preg_quote($literal, '/') . "'\\s*:/",
                $vendoredSource,
                sprintf(
                    "%s compares `=== '%s'`, and %s has NO `case '%s':` arm.

"
                    . "PHP's switch compares with `==`, which is case-sensitive for strings, so "
                    . "this override is standing in front of a branch the module never takes — the "
                    . "vendored bin stays reachable by the value the module DOES accept, and the "
                    . "value the override intercepts may be one the module deliberately rejects. "
                    . "Match the parent's own label; do not widen the override to catch both, "
                    . "because enabling a branch that currently errors is a money-path change and "
                    . "belongs to the owner.",
                    class_basename($override),
                    $literal,
                    class_basename($vendoredClass),
                    $literal
                )
            );
        }

        $this->assertSame(
            count(ModuleBenefitCallback::VENDORED_SITES),
            $checked,
            'PREMISE: not every declared site was examined, so a clean result here means nothing.'
        );
    }
}
