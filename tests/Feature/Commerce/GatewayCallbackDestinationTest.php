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
     * First-party source files that still name a third-party callback bin.
     *
     * **EMPTY SINCE 2026-11-17, AND THAT IS THE POINT OF THIS COMMIT.** Both entries were removed
     * under P4-F046, on the owner's decision "remove webhook.site":
     *
     *   - `PaymentService::processBenefit()` -- the STOREFRONT Benefit charge.
     *   - `BenefitPaymentController` -- the SAAS PLAN path, a merchant buying a subscription.
     *
     * Both now send `"post" => ["url" => '']`, which is what the card flows for the same provider
     * and the same endpoint put ON THE WIRE (`test_the_benefit_charges_send_the_empty_string_the_
     * card_flows_send` below says why it is not `null`, which is what this note used to claim). So
     * the bin is gone without a new destination being chosen -- and choosing one remains the open
     * owner decision P1-SEC-55, which that change deliberately does not pre-empt.
     *
     * KEEPING THIS CONSTANT AT `[]` RATHER THAN DELETING THE TEST IS DELIBERATE. The assertion now
     * reads "no first-party file names a bin", which is a stronger ratchet than the one it
     * replaces: previously a third file could only be caught as a new KEY, now any hit at all
     * fails. Deleting it would retire the guard at the moment it finally has nothing to permit.
     *
     * A THIRD COPY WAS DELETED instead of declared: see
     * `test_the_dead_order_exfiltration_helper_is_not_reintroduced()`.
     *
     * THE THREE VENDORED COPIES ARE NOW ASSERTED ELSEWHERE -- see VENDORED_COPIES_NOT_ASSERTED,
     * whose name is now wrong and is kept only because other files cite it. First-party code being
     * clean is not the platform being clean, and this file has always said so.
     */
    private const DECLARED_FIRST_PARTY_BINS = [];

    /**
     * The vendored copies. THE NAME OF THIS CONSTANT IS NOW STALE AND THE REASONING UNDER IT HAS
     * BEEN OVERTAKEN -- corrected 2026-11-26 after an independent review pointed out that two
     * newer files quote it as current and a reader would conclude the vendored tree is unguarded.
     *
     * What it said: "CLAUDE.md forbids editing `packages/workdo/`, so a ratchet over them could
     * only ever be a standing red light." That was true while the only available remedy was an
     * edit. It stopped being true under GAP-138: the three vendored services are now intercepted
     * host-side by `App\Services\Modules\*BenefitCallbackOverride`, bound over them in
     * `AppServiceProvider::register()`, so the vendored literals are UNREACHABLE rather than
     * removed -- and `tests/Unit/Security/ModuleBenefitCallbackCensusTest` asserts the set of
     * vendored sites in both directions, so a fourth copy fails the build.
     *
     * The constant keeps its name because other files cite it by that name; what changed is that
     * "not asserted" now means "not asserted HERE". They are still listed, for the original and
     * still-correct reason: so that "first-party code is clean" is never mistaken for "the
     * platform is clean", which is the precise misreading this project has made before.
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
     * WHAT REPLACED THE BIN IS THE SHAPE THE CARD FLOWS ALREADY PROVE ON THE WIRE. AUD-4.
     *
     * The first version of this change sent `null` and said that was what `processTap` ships. The
     * PR #140 deletion audit measured otherwise. processTap and `TapPaymnetController` pass PHP
     * `null` into `App\Package\Payment::charge()`, which builds its JSON by STRING CONCATENATION,
     * so what reaches Tap is `"post":{"url":""}`. The two Benefit sites `json_encode()` an array,
     * so `null` reached Tap as `"url":null` -- a shape no other Tap flow in this repository sends,
     * and one nobody can verify, because no credential exists in this container.
     *
     * WHY THAT MATTERS MORE HERE THAN IT WOULD ELSEWHERE. A Benefit charge Tap refuses is caught
     * and shown to the shopper as a CURRENCY problem. An unverifiable shape on the payment rail
     * is exactly the kind of change that fails quietly and is then blamed on something else.
     * `''` is byte-identical to what the card flows send, so it adds no unknown. Whether Tap would
     * have accepted `null` is still not known, and does not need to be.
     *
     * Source-level, with comments stripped, for the reason the class docblock gives: the client is
     * constructed inline so nothing can be intercepted. Every assertion carries its anchor.
     */
    public function test_the_benefit_charges_send_the_empty_string_the_card_flows_send(): void
    {
        // THE PREMISE: the card flows' wire shape really is what the paragraph above says.
        $wire = $this->codeOf('app/Package/Payment.php');
        $this->assertStringContainsString(
            '\"post\":{\"url\":\"".$this->CHARGE_VARS[\'post\'][\'url\']."\"}',
            $wire,
            'Payment::charge() no longer builds post.url by string concatenation, so "an empty '
            .'string on the wire" is no longer the shape the card flows send. Re-derive this test.'
        );

        foreach (['app/Services/PaymentService.php', 'app/Http/Controllers/TapPaymnetController.php'] as $card) {
            $this->assertMatchesRegularExpression(
                "/'post'\s*=>\s*\[\s*'url'\s*=>\s*null\s*\]/",
                $this->codeOf($card),
                $card.' no longer hands a PHP null to Payment::charge() for post.url. The claim that '
                .'`null` there becomes `"url":""` on the wire is what makes `\'\'` the matching shape.'
            );
        }

        foreach (['app/Services/PaymentService.php', 'app/Http/Controllers/BenefitPaymentController.php'] as $benefit) {
            $code = $this->codeOf($benefit);

            $this->assertStringContainsString(
                'https://api.tap.company/v2/charges',
                $code,
                $benefit.': the Tap charge POST has moved, so the counts below describe another file'
            );
            $this->assertSame(
                1,
                substr_count($code, '"post" => ["url" => \'\']'),
                $benefit.' must send exactly one `"post" => ["url" => \'\']`'
            );
            $this->assertStringNotContainsString(
                '"post" => ["url" => null]',
                $code,
                $benefit.' sends `"url":null`, which no other Tap flow in this repository sends'
            );
        }
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
            . "A REMOVED one is good news and wants this list updated, not relaxed -- it was "
            . "updated to EMPTY on 2026-11-17 when P4-F046 removed both.\n"
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
     * THE SETTLEMENT HALF, NOW REQUIRED — THE TRIPWIRE FIRED AND THIS IS WHAT REPLACED IT.
     *
     * UNTIL GAP-138 / `P5-FIN-6` this case asserted the OPPOSITE: that
     * `PaymentController::getProductStatus` contains no `== 'benefit'`, because a storefront
     * Benefit order was written with no capture verification whatsoever and the honest assertion
     * for a finding that is recorded and not fixed is the direction that is true today. Its own
     * message said what to do when the branch arrived — "replace it with a case that REQUIRES the
     * branch to reach a charge lookup before claimGatewayReturn()" — and that is this case,
     * written to that instruction rather than deleted.
     *
     * KEYED ON THE ORDER OF TWO CALLS, NOT ON EITHER ONE'S PRESENCE, because presence is the part
     * that cannot go quietly wrong. `claimGatewayReturn()` makes the stored checkout state
     * single-use; a verification that ran AFTER it would refuse the shopper AND burn their state,
     * so a real payment could not be retried. That ordering is the whole reason the `Tap` arm sits
     * where it does, and this is the only check in the suite that can see it move.
     *
     * COMMENT-STRIPPED by `codeOf()`, which matters more here than usual: the branch's own docblock
     * quotes `claimGatewayReturn()`, `benefitChargeStatus()` and both spellings of Benefit while
     * explaining them, so an unstripped read would find every token it looks for in prose.
     */
    public function test_the_storefront_return_verifies_a_benefit_capture_before_claiming_the_return(): void
    {
        $code = $this->codeOf('app/Http/Controllers/PaymentController.php');

        $this->assertStringContainsString("'Tap'", $code,
            'the Tap branch has moved — re-read this test before trusting it');

        $benefitBranch = strpos($code, "== 'benefit'");
        $chargeLookup = strpos($code, 'benefitChargeStatus(');
        $claim = strpos($code, "claimGatewayReturn(\$request, 'storefront-return'");

        $this->assertNotFalse(
            $benefitBranch,
            'The storefront return no longer has a `benefit` branch, so a Benefit order is once '
            . 'again written with no capture verification (GAP-138 / P5-FIN-6).'
        );

        $this->assertNotFalse(
            $chargeLookup,
            'The `benefit` branch no longer reaches a charge lookup, so it verifies nothing.'
        );

        $this->assertNotFalse($claim, 'the storefront claim has moved — re-read this test');

        $this->assertLessThan(
            $claim,
            $benefitBranch,
            'The `benefit` branch now sits BELOW claimGatewayReturn(), which makes the stored '
            . 'checkout state single-use: a refusal there would burn a real shopper\'s state and '
            . 'they could not retry. Keep it beside the Tap arm, above the claim.'
        );

        $this->assertLessThan(
            $claim,
            $chargeLookup,
            'The charge lookup now happens after the return has been claimed.'
        );

        /*
         * THE SEAM'S CONTRACT, asserted against the class rather than the text. GAP-138's rule for
         * the POST half was that the transport is replaceable ONLY by subclassing — no property, no
         * setter, no production binding — and `public` would make it callable from anywhere while
         * `private` would make the test double impossible. Exactly `protected` is the contract.
         */
        $seam = new \ReflectionMethod(\App\Http\Controllers\PaymentController::class, 'benefitChargeStatus');

        $this->assertTrue($seam->isProtected(),
            'benefitChargeStatus() must stay protected: public widens a gateway call to every '
            . 'caller, private makes the test double that keeps this suite off the network impossible.');
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
