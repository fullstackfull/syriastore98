<?php

namespace Tests\Unit\Localisation;

use Tests\TestCase;

/**
 * THE TWO RELABELLED OPERATOR DASHBOARD CARDS READ IN ARABIC.
 * =================================================================================================
 *
 * `superadmin/dashboard.blade.php` had two cards whose words did not describe their own value:
 * `Total Store` over `User::countCompany()` (a count of MERCHANTS, not stores) and `Total Coupon`
 * over a coupon NAME. Both were relabelled; the measurement is in
 * `Tests\Feature\Platform\OperatorDashboardCardLabelsTest`.
 *
 * THIS FILE EXISTS BECAUSE THE TRANSLATION RATCHET RUNS IN ONE DIRECTION ONLY, which was measured
 * while adding these keys rather than assumed.
 * `TranslationFileIntegrityTest::test_arabic_declares_no_key_english_has_dropped` fails an ar.json
 * key with no en.json sibling. There is NO case asserting the reverse, so an English key with no
 * Arabic one is silently legal: `__('Total Merchants')` would hand the Arabic UI the English
 * string and nothing in the suite would notice.
 *
 * THAT GAP IS NOT A DEFECT ON ITS OWN, and it is deliberately not closed here. 35 en.json keys have
 * no Arabic sibling and the ones sampled are payment-gateway brand names -- Aamarpay, Benefit,
 * Braintree, Cashfree, Coingate -- which must NOT be translated;
 * `PaymentBrandNameTranslationTest` is a whole file asserting exactly that, because they once were.
 * A reverse ratchet therefore needs a proper-noun policy, which is its own piece of work and is
 * recorded as such. What this file does instead is pin the two keys this change introduced.
 *
 * The vocabulary is this file's own, not invented: `تاجر` is how ar.json already renders merchant
 * (`Merchant ID` -> `هوية التاجر`), and `الكوبون الشعبي` follows `Popular Plan` ->
 * `الخطة الشعبية`, the sibling card whose idiom the coupon label was matched to. `كوبون` is also
 * ar.json's existing word for `Coupon`; the retired `Total Coupon` used `قسيمة`, which disagreed
 * with it.
 */
class OperatorDashboardCardLabelTranslationTest extends TestCase
{
    private const LABELS = [
        'Total Merchants' => 'إجمالي التجار',
        'Popular Coupon' => 'الكوبون الشعبي',
    ];

    public function test_both_relabelled_cards_have_an_arabic_translation(): void
    {
        app()->setLocale('ar');

        foreach (self::LABELS as $english => $expectedArabic) {
            $resolved = __($english);

            // The defect this catches: `__()` hands back the key when ar.json has no entry, so the
            // Arabic operator dashboard would print the English label and the one-directional
            // ratchet would stay green.
            $this->assertNotSame($english, $resolved,
                'The Arabic operator dashboard renders the card label "' . $english . '" in '
                . 'English, because resources/lang/ar.json has no entry for it.');
            $this->assertSame($expectedArabic, $resolved,
                'The Arabic label for "' . $english . '" changed.');
        }
    }

    /**
     * The English side resolves too, rather than relying on the key-is-the-fallback behaviour.
     *
     * `__('Total Merchants')` renders correctly in English whether or not en.json holds the key,
     * because Laravel falls back to the key itself. So this is not about what a visitor sees -- it
     * is the premise of the ratchet: the ar.json entries added with these labels would be
     * orphans, and `test_arabic_declares_no_key_english_has_dropped` would fail CI, if the en.json
     * siblings were ever removed.
     */
    public function test_both_labels_are_declared_in_english_too(): void
    {
        $en = json_decode((string) file_get_contents(resource_path('lang/en.json')), true);

        $this->assertIsArray($en);

        foreach (array_keys(self::LABELS) as $english) {
            $this->assertArrayHasKey($english, $en,
                'resources/lang/en.json has no "' . $english . '" entry, so its Arabic sibling is '
                . 'an orphan and the translation ratchet will fail.');
            $this->assertSame($english, $en[$english],
                'the English entry for "' . $english . '" is no longer its own key');
        }
    }

    /** The words they replaced are gone from the cards, so nothing reads them as current. */
    public function test_the_retired_labels_are_no_longer_on_the_operator_cards(): void
    {
        $view = (string) file_get_contents(
            resource_path('views/superadmin/dashboard.blade.php')
        );

        // SCOPED TO THE CARD DECLARATIONS. `Total Store` is still CORRECT further down the same
        // view, on the per-merchant tiles, where it labels a real count of `stores` rows -- a
        // whole-file search for that string would demand breaking a correct label. The strings
        // below are the `<x-dashboard-card>` label arguments and nothing else.
        foreach (["__('Total Store')", "__('Total Coupon')"] as $retired) {
            $this->assertStringNotContainsString(':label="' . $retired . '"', $view,
                'a dashboard card still passes ' . $retired . ' as its label');
        }
    }
}
