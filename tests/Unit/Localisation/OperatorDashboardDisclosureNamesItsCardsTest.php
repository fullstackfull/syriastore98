<?php

namespace Tests\Unit\Localisation;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * THE OPERATOR DASHBOARD'S DISCLOSURE SENTENCE MUST NAME THE CARDS BY THE WORDS ON THEM.
 * =================================================================================================
 *
 * `GAP-231` gave `resources/views/superadmin/dashboard.blade.php` a standalone `ss-stat__hint`
 * paragraph saying which of three cards counts purchase ATTEMPTS and which count PAYMENTS. A
 * disclosure that a reader cannot attach to a card is not a disclosure, and **in Arabic it could
 * not be attached to any of the three.** Measured 2026-11-27, straight out of the two catalogues:
 *
 *   | card's own label (ar.json)   | the name the Arabic disclosure used |
 *   |------------------------------|-------------------------------------|
 *   | `إجمالي طلبات الاشتراك`        | `إجمالي طلبات الباقات`                |
 *   | `الخطة الشعبية`                | `الباقة الأكثر طلباً`                   |
 *   | `الكوبون الشعبي`               | `القسيمة الأكثر استخداماً`              |
 *
 * **All three.** So an Arabic-reading operator was given a correct, careful sentence about three
 * figures that are not on the screen, while the English sentence named all three exactly. Nothing
 * asserted it: `OperatorDashboardAttemptCountDisclosureTest::test_the_disclosure_renders_in_arabic`
 * checks that the Arabic renders at all (`__()` returns its key, so an undeclared sentence shows
 * up in English on the Arabic page), which is a different question and cannot see this.
 *
 * One of the three was a known-wrong word being reintroduced rather than a fresh slip.
 * `docs/development/phase-6/PHASE-06-DASHBOARD-CARD-LABELS.md` §7 records that the retired
 * `Total Coupon` label used `قسيمة` and that this "disagreed with" ar.json's own `كوبون` for
 * `Coupon`, which is why the card was translated `الكوبون الشعبي`. The disclosure then wrote
 * `قسيمة` again.
 *
 * WHAT THIS ASSERTS, AND WHY IT IS THE CATALOGUES AND NOT A RENDER. The sentence is one `__()`
 * call on a view the rendering test above already drives, so the thing that can drift is the
 * CATALOGUE VALUE, in either language, and that is readable without a request. Keeping it in the
 * Unit suite also means it runs on the blocking gate.
 *
 * IT ASSERTS NOTHING ABOUT WHAT THE CARDS COUNT. Which redemptions `Popular Coupon` ranks on is an
 * open owner question (`docs/development/phase-6/PHASE-06-POPULAR-COUPON-METRIC-RECONCILIATION.md`)
 * and this file must survive either answer: it pins the NAMING, so a changed claim keeps the
 * three names and a changed name keeps the claim.
 */
class OperatorDashboardDisclosureNamesItsCardsTest extends TestCase
{
    /**
     * The disclosure's own key, which is also its English text -- the catalogues are keyed on the
     * English string.
     */
    private const DISCLOSURE = 'Total Plan Orders counts every purchase attempt, whether or not it '
        . 'was ever paid. Popular Plan and Popular Coupon count only purchases the platform was '
        . 'actually paid for, and Popular Coupon counts only redemptions whose order can be found.';

    /** The three cards the sentence is about, by the `:label` each is given in the view. */
    private const CARDS = ['Total Plan Orders', 'Popular Plan', 'Popular Coupon'];

    /** @return array<string, string> */
    private function catalogue(string $locale): array
    {
        $path = base_path("resources/lang/{$locale}.json");

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded, "resources/lang/{$locale}.json is not valid JSON.");

        return $decoded;
    }

    /**
     * PREMISE. Without this, every assertion below passes vacuously against a renamed or deleted
     * sentence: `str_contains('', ...)` is false, but so is the whole subject.
     */
    #[Test]
    public function test_premise_the_disclosure_and_all_three_card_labels_are_declared_in_both_catalogues(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $catalogue = $this->catalogue($locale);

            $this->assertArrayHasKey(self::DISCLOSURE, $catalogue,
                "the operator dashboard's disclosure sentence is not declared in {$locale}.json, "
                . 'so either it was reworded without updating the catalogue or this test is '
                . 'asserting against a sentence the product no longer has.');

            foreach (self::CARDS as $card) {
                $this->assertArrayHasKey($card, $catalogue,
                    "the card label \"{$card}\" is not declared in {$locale}.json.");
            }
        }
    }

    #[Test]
    public function test_the_english_disclosure_names_each_of_the_three_cards(): void
    {
        $catalogue = $this->catalogue('en');

        foreach (self::CARDS as $card) {
            $this->assertStringContainsString($catalogue[$card], $catalogue[self::DISCLOSURE],
                "the English disclosure does not name the card \"{$card}\" by its own label, so a "
                . 'reader cannot tell which card the sentence is making a claim about.');
        }
    }

    /**
     * THE HALF THAT WAS FALSE FOR ALL THREE CARDS. See the class docblock for the measurement.
     */
    #[Test]
    public function test_the_arabic_disclosure_names_each_of_the_three_cards_as_the_card_prints_it(): void
    {
        $catalogue = $this->catalogue('ar');

        foreach (self::CARDS as $card) {
            $this->assertStringContainsString($catalogue[$card], $catalogue[self::DISCLOSURE],
                'the Arabic disclosure names the card "' . $card . '" as something other than '
                . '"' . $catalogue[$card] . '", which is what the card itself prints. An '
                . 'Arabic-reading operator is then given a sentence about a figure that is not on '
                . 'the screen.');
        }
    }

    /**
     * AND THE ARABIC IS REALLY ARABIC. Copying the English value into ar.json would satisfy every
     * assertion above -- each card's Arabic label would be absent, so in fact it would fail, but
     * only by accident of this sentence naming all three. Asserting the translation is distinct
     * makes that deliberate rather than lucky.
     */
    #[Test]
    public function test_the_arabic_disclosure_is_a_translation_and_not_the_english_text(): void
    {
        $en = $this->catalogue('en');
        $ar = $this->catalogue('ar');

        $this->assertNotSame($en[self::DISCLOSURE], $ar[self::DISCLOSURE],
            'ar.json carries the English sentence verbatim, so the Arabic dashboard discloses in '
            . 'English.');

        $this->assertStringNotContainsString('every purchase attempt', $ar[self::DISCLOSURE]);
    }
}
