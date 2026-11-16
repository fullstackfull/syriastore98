<?php

namespace Tests\Feature\Commerce;

use Tests\TestCase;

/**
 * P4-F046 — WHERE A PAYMENT CALLBACK IS TOLD TO GO.
 * =================================================================================================
 *
 * A gateway's server-to-server callback is the only part of a payment that does not depend on the
 * shopper's browser. Whoever receives it learns, for every payment, who bought what and for how
 * much — and, if the application ever trusted it, could assert that a payment happened.
 *
 * THE FINDING. `app/Services/PaymentService.php::processBenefit()` builds a Tap charge whose
 * `post.url` is a HARDCODED PUBLIC `webhook.site` bin. The URL is committed in this repository and
 * in its git history, so it is not a secret: anybody holding it receives whatever Tap posts there.
 * Three more copies live in vendored WorkDo modules, and a fourth lived in a dead private helper in
 * this same file.
 *
 * WHAT THIS TEST IS, AND WHAT IT IS NOT
 * -------------------------------------------------------------------------------------------------
 * It is **a ratchet over a declared state**, not a fix. The live site is untouched, deliberately:
 * changing where a payment callback goes is an owner decision, it was escalated as P1-SEC-55 and
 * it is still open. What this file does is make the current state EXPLICIT and make it impossible
 * to grow quietly — a new third-party bin anywhere in first-party code fails CI, and the day the
 * owner answers, the declaration below is the checklist.
 *
 * WHY A STRUCTURAL TEST AND NOT A BEHAVIOURAL ONE. `processBenefit()` constructs
 * `$client = new Client()` inline (no injection, not the `Http` facade), so `Http::fake()` cannot
 * see the call — the same seam gap `E14-GATEWAY-MATRIX` records for `BenefitPaymentController`.
 * There is nothing to intercept. Stated here rather than left implicit, because a source-reading
 * test is exactly the shape that passes for the wrong reason, so every assertion below also
 * asserts its own anchor and says so when the anchor has moved.
 *
 * COMMENTS ARE STRIPPED BEFORE ANY ASSERTION. This file's own prose names the hosts it is about,
 * and so does the note left where the dead helper was deleted. A raw `str_contains` would read
 * those and report a defect that is not there — the trap `GatewayStockDecrementRoutingTest`
 * documents and provides `code()` for.
 */
class GatewayCallbackDestinationTest extends TestCase
{
    /**
     * Every first-party source file that still names a third-party callback bin, with the exact
     * reason it is still there.
     *
     * TWO ENTRIES, AND BOTH ARE OWNER DECISIONS rather than oversights:
     *
     *   - `PaymentService::processBenefit()` is the STOREFRONT Benefit charge. Re-pointing its
     *     callback changes where a live gateway sends real settlement notifications for real
     *     money, which is not an engineering call. This is P4-F046 and it is in Phase 04's scope.
     *
     *   - `BenefitPaymentController` is the SAAS PLAN path — a merchant buying a subscription,
     *     not a shopper buying goods. Same bin, same mechanism, different rail, and that rail is
     *     Phase 06. Listed here rather than left out, because "the commerce callback is declared"
     *     must not read as "this is the only one".
     *
     * A THIRD COPY WAS DELETED instead of declared: see
     * `test_the_dead_order_exfiltration_helper_is_not_reintroduced()`.
     */
    private const DECLARED_FIRST_PARTY_BINS = [
        'app/Http/Controllers/BenefitPaymentController.php' => 1,
        'app/Services/PaymentService.php' => 1,
    ];

    /**
     * The vendored copies, recorded and NOT asserted.
     *
     * CLAUDE.md forbids editing `packages/workdo/`, so a ratchet over them could only ever be a
     * standing red light. They are listed so that "first-party code is clean" is never mistaken
     * for "the platform is clean", which is the precise misreading this project has made before.
     */
    private const VENDORED_COPIES_NOT_ASSERTED = [
        'packages/workdo/ — three sites, per P1-SEC-55-OUTBOUND-SENDER-CENSUS.md §3',
    ];

    private function codeOf(string $relativePath): string
    {
        $absolute = base_path($relativePath);

        $this->assertFileExists($absolute, $relativePath . ' has moved — re-read this test');

        $out = '';

        foreach (token_get_all((string) file_get_contents($absolute)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    /**
     * THE PREMISE. If this fails, every other assertion here is measuring a file that has been
     * restructured and the counts below mean nothing.
     */
    public function test_premise_the_benefit_charge_builder_is_still_where_this_test_thinks(): void
    {
        $code = $this->codeOf('app/Services/PaymentService.php');

        $this->assertStringContainsString('function processBenefit', $code,
            'processBenefit() has moved or been renamed — re-read this test before trusting it');

        $this->assertStringContainsString('https://api.tap.company/v2/charges', $code,
            'the Tap charge POST has moved — re-read this test before trusting it');
    }

    /**
     * THE RATCHET. Exactly the declared bins, no more.
     *
     * Asserted as a COUNT PER FILE rather than a boolean, because a boolean cannot tell "the known
     * one" from "the known one plus a new one" — and a second bin added beside the first is
     * precisely the drift worth catching.
     */
    public function test_no_new_third_party_callback_bin_appears_in_first_party_code(): void
    {
        $found = [];

        foreach ($this->firstPartyPhpFiles() as $relativePath) {
            $hits = substr_count($this->codeOf($relativePath), 'webhook.site');

            if ($hits > 0) {
                $found[$relativePath] = $hits;
            }
        }

        ksort($found);

        $expected = self::DECLARED_FIRST_PARTY_BINS;
        ksort($expected);

        $this->assertSame(
            $expected,
            $found,
            "the set of first-party files naming a third-party callback bin has changed.\n"
            . "A NEW one is a defect: a gateway callback carries, for every payment, who bought "
            . "what and for how much, and a webhook.site bin is public to anyone holding its URL "
            . "-- which is committed here and in git history.\n"
            . "A REMOVED one is good news and wants this list updated, not relaxed.\n"
            . 'Vendored copies are deliberately not counted: ' . implode('; ', self::VENDORED_COPIES_NOT_ASSERTED)
        );
    }

    /**
     * THE DEAD HELPER IS GONE AND MUST STAY GONE.
     *
     * `webhook_backup($order, $r, $products_weight)` POSTed the whole order model and the raw
     * request to a second public bin. It had zero callers — `P1-SEC-55-OUTBOUND-SENDER-CENSUS.md`
     * had already proved that — so it implemented no requirement and was one call site away from
     * exfiltrating every order on the platform. Deleted in P4-5, 2026-11-16.
     *
     * This is the one half of P4-F046 that needed no owner decision, which is exactly why it is
     * the one half that was done.
     */
    public function test_the_dead_order_exfiltration_helper_is_not_reintroduced(): void
    {
        foreach ($this->firstPartyPhpFiles() as $relativePath) {
            $this->assertStringNotContainsString(
                'function webhook_backup',
                $this->codeOf($relativePath),
                $relativePath . ' has reintroduced webhook_backup(), which POSTs the entire order '
                . 'model and the raw request to a public bin and is called by nothing'
            );
        }
    }

    /**
     * THE SETTLEMENT HALF, RECORDED RATHER THAN FIXED.
     *
     * `PaymentController::getProductStatus` gates its only Tap charge lookup on
     * `payment_type == 'Tap'` and has NO `benefit` branch at all, so a storefront Benefit order is
     * written with no capture verification whatsoever — settlement rests entirely on the shopper's
     * browser reaching the redirect URL.
     *
     * ASSERTED IN THE DIRECTION THAT IS TRUE TODAY, which is the honest thing for a finding that
     * is recorded and not fixed. The day a `benefit` verification branch is added, this test fails
     * and is rewritten to require it — a deliberate tripwire, and the message says so, so nobody
     * reads the green as "Benefit settlement is verified".
     */
    public function test_recorded_the_storefront_return_still_has_no_benefit_verification_branch(): void
    {
        $code = $this->codeOf('app/Http/Controllers/PaymentController.php');

        $this->assertStringContainsString("'Tap'", $code,
            'the Tap branch has moved — re-read this test before trusting it');

        $this->assertStringNotContainsString(
            "== 'benefit'",
            $code,
            'A `benefit` branch has appeared on the storefront return. THAT IS GOOD NEWS AND THIS '
            . 'TEST IS NOW WRONG: it records the ABSENCE of capture verification for Benefit '
            . '(P4-F046, owner-escalated). Replace it with a case that REQUIRES the branch to '
            . 'reach a charge lookup before claimGatewayReturn().'
        );
    }

    /** First-party PHP: `app/` only. `packages/workdo/` is vendored and must not be edited. */
    private function firstPartyPhpFiles(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');
            }
        }

        sort($files);

        $this->assertGreaterThan(200, count($files),
            'the app/ sweep found almost nothing, so every assertion built on it is vacuous');

        return $files;
    }
}
