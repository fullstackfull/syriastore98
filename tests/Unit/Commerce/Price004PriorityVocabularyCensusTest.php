<?php

namespace Tests\Unit\Commerce;

use PHPUnit\Framework\TestCase;
use Tests\Support\StripsSourceComments;

/**
 * `PRICE-004` — AN EXPLICIT DISCOUNT-PRIORITY VOCABULARY DOES EXIST. IT GOVERNS ONE MODULE.
 * =================================================================================================
 *
 * THE RECOVERED REQUIREMENTS, verbatim from `DECISION-ID-REGISTER.md:101-102`:
 *
 *   `PRICE-004`  قواعد صريحة لأولويات الخصومات.  — *Explicit discount-priority rules*
 *   `PRICE-005`  عدم اختيار أقل سعر أو جمع الخصومات تلقائياً. — *Never auto-pick the lowest price,
 *                never auto-stack discounts*
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THIS FILE EXISTS: TO NARROW A CLAIM THIS REPOSITORY MAKES TOO WIDELY
 * -------------------------------------------------------------------------------------------------
 * `GAP-311` records that **"`PRICE-004` does not exist at all"**, and the census it rests on is a
 * scan of comment-stripped `Product.php` and `CouponBasketAllocator.php`. That census is CORRECT
 * WITHIN ITS SCOPE — neither file contains a priority rule — but the conclusion is wider than its
 * input, and the owner package's `J18` repeats it as *"There is none in the code"*.
 *
 * Measured here: the vendored `ProductPricing` module ships an explicit, merchant-facing
 * discount-priority vocabulary, and the host wires it into the cart readers. So `J18`'s decision
 * does not have to invent a vocabulary — one already exists, merchants already see it in a
 * dropdown, and it is a candidate basis for the platform-wide rule rather than a competitor to it.
 *
 *     store level (`settings.all_rules_condition`)    ProductPricingRule::$allRulesConditions
 *         none                        — apply nothing
 *         apply_all_rules             — STACK every applicable rule
 *         apply_first_rule            — apply the FIRST applicable rule only
 *         all_rules_disabled          — apply nothing
 *
 *     rule level (`product_pricing_rules.rule_status`)  ProductPricingRule::$ruleConditions
 *         none
 *         apply_with_other_rules                      — this rule stacks
 *         apply_this_rule_and_disregard_other_rules   — this rule WINS and suppresses the rest
 *         disabled
 *
 * **AND `PRICE-005`'s PROHIBITED BEHAVIOUR IS PRESENT IN THE SOURCE AS A DISABLED OPTION.** Two
 * further store-level values are written and commented out:
 * `apply_smaller_price_rule` ("Apply rule for smaller price") and `apply_bigger_price_rule`. The
 * first IS "auto-pick the lowest price", which `PRICE-005` forbids by name. It is not reachable
 * today. Nobody should re-enable it without reading `PRICE-005` first, and that is this file's
 * second job: it asserts both stay commented out, so un-commenting one turns a test red with the
 * decision's text attached.
 *
 * -------------------------------------------------------------------------------------------------
 * THE LIMITS OF THE FINDING, STATED BEFORE SOMEBODY OVER-READS IT
 * -------------------------------------------------------------------------------------------------
 *   - The vocabulary governs **only `ProductPricing`'s own rules**. It does not order the
 *     `sale_price` override, the flash sale, the coupon, the two tax passes or the price-list
 *     tier — the seven layers `GAP-311` and `Price002WholesaleTierLayerMeasurementTest` measure.
 *   - It is **module-gated**. With `ProductPricing` inactive — which is the default — none of it
 *     runs, and `settings.all_rules_condition` defaults to `none` even when it does.
 *   - It is **vendored**, so it is not this project's to change, and the host reaches it through
 *     two call sites that hand it an ALREADY-DISCOUNTED `final_price`.
 *   - This is a SOURCE census, not a driven measurement. It asserts what the code says, not what
 *     a basket is charged. The driven figures for the other layers are in the two Feature files
 *     named below; a driven figure for this layer is not claimed here and is not in this file.
 *
 * NOTHING HERE DECIDES THE RULE. `J18` is the owner's.
 *
 * @see \Tests\Feature\Commerce\Gap187DiscountStackMeasurementTest           layers 1-5, driven
 * @see \Tests\Feature\Commerce\Price002WholesaleTierLayerMeasurementTest    layer 6, driven
 */
final class Price004PriorityVocabularyCensusTest extends TestCase
{
    use StripsSourceComments;

    private const MODULE = 'packages/workdo/ProductPricing/src/app/Models/ProductPricingRule.php';

    private function moduleSource(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::MODULE;

        $this->assertFileExists($path,
            'premise: the module that carries the vocabulary is vendored in this repository. '
            . 'Every assertion below is vacuous without it.');

        return (string) file_get_contents($path);
    }

    /** `sourceWithoutComments()` takes a RELATIVE PATH, not source text. */
    private function moduleCodeOnly(): string
    {
        return $this->sourceWithoutComments(self::MODULE);
    }

    /**
     * The body of ONE static map, not the whole 9,051-line file.
     *
     * THIS SCOPING IS LOAD-BEARING AND A SURVIVING MUTANT PROVED IT. The first draft asserted
     * `'apply_first_rule'` against the whole comment-stripped file, and deleting that value from
     * the `$allRulesConditions` map left the test GREEN -- because the same literal also appears
     * as a `case 'apply_first_rule':` in the rule engine's switch, 8,000 lines away. So the
     * assertion was answering "does this string exist anywhere in the file", which is the exact
     * failure `CLAUDE.md` records for a stylesheet-wide search. Scoped to the map's own braces,
     * deleting the value reddens.
     */
    private function mapBody(string $property): string
    {
        $source = $this->moduleCodeOnly();

        $start = strpos($source, 'public static $' . $property . ' = [');

        $this->assertNotFalse($start, sprintf(
            'premise: the module still declares `$%s` as a static array. PRICE-004\'s '
            . 'disposition rests on this map, so a rename must be read rather than absorbed.',
            $property
        ));

        $end = strpos($source, '];', $start);

        $this->assertNotFalse($end, sprintf('premise: `$%s` closes.', $property));

        $body = substr($source, $start, $end - $start);

        $this->assertLessThan(2000, strlen($body), sprintf(
            'premise: the extracted body is a short map and not a swathe of the file -- a '
            . 'mis-scoped extraction would restore the very weakness this method exists to fix. '
            . 'Extracted %d bytes of `$%s`.', strlen($body), $property
        ));

        return $body;
    }

    /** The two host call sites that reach the module's rule engine. */
    private function hostCallSites(): array
    {
        return [
            'app/Models/Cart.php',
            'app/Http/Controllers/Api/ApiController.php',
        ];
    }

    /**
     * THE STORE-LEVEL VOCABULARY, enumerated as a SAFE SET rather than searched for by keyword.
     *
     * `CLAUDE.md` records that a census hunting for a spelling is always short by the one nobody
     * thought of, so this asserts the exact set of keys the shipped map declares — a value added
     * or removed changes the count and reddens, which is the point.
     */
    public function test_the_module_declares_an_explicit_store_level_priority_vocabulary(): void
    {
        $storeLevel = $this->mapBody('allRulesConditions');

        foreach ([
            "'none'" => 'apply nothing',
            "'apply_all_rules'" => 'STACK every applicable rule',
            "'apply_first_rule'" => 'apply the first applicable rule only',
            "'all_rules_disabled'" => 'apply nothing',
        ] as $key => $meaning) {
            $this->assertStringContainsString($key, $storeLevel, sprintf(
                'the store-level priority value %s (%s) is gone from `$allRulesConditions`. '
                . 'PRICE-004\'s disposition rests on this vocabulary existing, so its '
                . 'disappearance must be read, not absorbed. Map body: %s',
                $key, $meaning, $storeLevel
            ));
        }

        $ruleLevel = $this->mapBody('ruleConditions');

        foreach ([
            "'apply_with_other_rules'" => 'this rule stacks',
            "'apply_this_rule_and_disregard_other_rules'" => 'this rule WINS and suppresses the rest',
            "'disabled'" => 'this rule is off',
        ] as $key => $meaning) {
            $this->assertStringContainsString($key, $ruleLevel, sprintf(
                'the per-rule priority value %s (%s) is gone from `$ruleConditions`. The '
                . 'vocabulary is TWO levels, and the per-rule override is the shape PRICE-004 '
                . 'asks for. Map body: %s', $key, $meaning, $ruleLevel
            ));
        }
    }

    /**
     * **`PRICE-005`'s FORBIDDEN BEHAVIOUR IS IN THE SOURCE, COMMENTED OUT, AND MUST STAY THAT WAY.**
     *
     * The assertion reads the RAW source deliberately — this is the one case where the comment IS
     * the subject, which is the inverse of the trap `CLAUDE.md` warns about eight times. Both
     * directions are asserted: the literal is present (so the test is measuring something) and it
     * is present only on a commented line (so the behaviour is unreachable).
     */
    public function test_the_auto_pick_lowest_price_option_that_PRICE_005_forbids_is_still_disabled(): void
    {
        $raw = $this->moduleSource();

        foreach (['apply_smaller_price_rule', 'apply_bigger_price_rule'] as $option) {
            $this->assertStringContainsString($option, $raw, sprintf(
                'premise: %s is written in the module. If it has been deleted outright this test '
                . 'is measuring nothing and should be re-read rather than relaxed.', $option
            ));

            $live = array_values(array_filter(
                explode("\n", $raw),
                fn (string $line) => str_contains($line, $option)
                    && ! str_starts_with(ltrim($line), '//')
                    && ! str_starts_with(ltrim($line), '*')
            ));

            $this->assertSame([], $live, sprintf(
                '`%s` is now on a LIVE line. "Apply rule for smaller price" is literally '
                . 'PRICE-005\'s prohibition — عدم اختيار أقل سعر — so enabling it would breach an '
                . 'approved decision. Do not relax this test: take it to the owner (J18). '
                . 'Live lines: %s', $option, implode(' | ', $live)
            ));
        }

        $this->assertStringNotContainsString('apply_smaller_price_rule',
            $this->moduleCodeOnly(),
            'and the comment-stripped source confirms it: the option exists only as a comment. '
            . 'This is the one census in the repository where the comment is the subject rather '
            . 'than a hazard, and both readings are asserted so neither can be mistaken for the '
            . 'other.');
    }

    /**
     * THE VOCABULARY IS NOT THE PLATFORM'S — it reaches exactly two host call sites, and both hand
     * it a price that other discounts have already changed.
     *
     * This is the half of the finding that keeps it from being over-read: `PRICE-004` asks for a
     * rule over ALL discounts, and what exists orders one module's own rules and then applies them
     * to a `final_price` that already carries the `sale_price` override and the flash sale.
     */
    public function test_the_vocabulary_governs_only_its_own_rules_and_is_handed_an_already_discounted_price(): void
    {
        $sites = 0;

        foreach ($this->hostCallSites() as $relative) {
            $source = $this->sourceWithoutComments($relative);

            $this->assertStringContainsString('productPricingApply', $source, sprintf(
                'premise: %s reaches the module\'s rule engine. The claim that the vocabulary is '
                . 'wired into the charge rests on these call sites.', $relative
            ));

            $this->assertStringContainsString("'final_price'                   => \$final_price", $source, sprintf(
                'and %s hands it the ALREADY-DISCOUNTED line price, so the module\'s rules apply '
                . 'ON TOP of layers 1 and 2 rather than instead of them. That is the precise '
                . 'reason the vocabulary is not a PRICE-004 answer: it orders its own rules and '
                . 'compounds with everything else.', $relative
            ));

            $sites++;
        }

        $this->assertSame(2, $sites,
            'two call sites, enumerated rather than searched for, so a third arriving is read.');
    }

    /**
     * AND THE SEVEN LAYERS STILL HAVE NO RULE BETWEEN THEM, asserted where `GAP-311` asserted it
     * so that this file narrows the claim without weakening it.
     *
     * The host's own pricing path — the product reader and the basket coupon allocator — carries
     * no priority vocabulary of any kind. That was `GAP-311`'s finding and it stands; what this
     * file adds is that the absence is the HOST's, not the repository's.
     */
    public function test_the_hosts_own_pricing_path_still_declares_no_priority_rule(): void
    {

        foreach (['app/Models/Product.php', 'app/Services/Commerce/CouponBasketAllocator.php'] as $relative) {
            $source = $this->sourceWithoutComments($relative);

            foreach (['DISCOUNT_PRIORITY', 'discount_priority', 'allRulesConditions', 'ruleConditions'] as $token) {
                $this->assertStringNotContainsString($token, $source, sprintf(
                    '%s now names `%s`. A priority rule arriving in the HOST pricing path is '
                    . 'PRICE-004 being answered, which is owner decision J18 — so this test '
                    . 'turning red is news, and the register must be updated rather than the test '
                    . 'relaxed.', $relative, $token
                ));
            }
        }

        // PREMISE, the other direction: the files were read and are the pricing path.
        $this->assertStringContainsString('ProductPrice',
            $this->sourceWithoutComments('app/Models/Product.php'),
            'premise: Product.php really is the file holding the pricing engine, so the four '
            . 'absences above are a reading of it and not of an empty string.');
    }
}
