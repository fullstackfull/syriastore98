<?php

namespace Tests\Unit\Localisation;

use Tests\TestCase;

/**
 * THE CURRENCY NAMES AND THE RETIRED-GATEWAY SUFFIX RESOLVE TO ARABIC (P2.5-SET-02).
 *
 * Measured defect: the Arabic settings page printed `Syrian Pound`, `US Dollar` and eleven more in
 * Latin script, and the disabled-gateway note read `(يوكاسا) (retired - no longer available)`.
 *
 * WHAT MAKES THIS WORTH A TEST RATHER THAN A ONE-LINE FIX. Both call sites were ALREADY wrapped --
 * `{{ __($currency->currency_name) }}` and `__('retired - no longer available')` -- so the code
 * read as localised and the defect was invisible to review. `__()` returns its own argument when
 * the key is absent, which means a missing key and a correct translation are indistinguishable at
 * the call site. The only thing that tells them apart is asserting the resolved value DIFFERS from
 * the key, which is what the loop below does.
 *
 * This is the third instance of this exact shape in this phase (the dashboard chart's
 * `__(date('d-M'))` was the first), so the assertion is the general one: no name may resolve to
 * itself, and each must resolve to the expected Arabic.
 */
class CurrencyNameTranslationTest extends TestCase
{
    /**
     * The thirteen names exactly as `SettingController::$defaultCurrencies` seeds them.
     *
     * Kept as a literal rather than read from the controller on purpose: if someone edits that
     * list, this test should FAIL and make them translate the new entry, not silently follow them
     * and assert nothing.
     */
    private const CURRENCIES = [
        'US Dollar'       => 'دولار أمريكي',
        'Euro'            => 'يورو',
        'Syrian Pound'    => 'ليرة سورية',
        'Lebanese Pound'  => 'ليرة لبنانية',
        'Iraqi Dinar'     => 'دينار عراقي',
        'Egyptian Pound'  => 'جنيه مصري',
        'Jordanian Dinar' => 'دينار أردني',
        'Saudi Riyal'     => 'ريال سعودي',
        'UAE Dirham'      => 'درهم إماراتي',
        'Kuwaiti Dinar'   => 'دينار كويتي',
        'Qatari Riyal'    => 'ريال قطري',
        'Bahraini Dinar'  => 'دينار بحريني',
        'Omani Rial'      => 'ريال عماني',
    ];

    public function test_every_default_currency_name_has_an_arabic_translation(): void
    {
        app()->setLocale('ar');

        foreach (self::CURRENCIES as $english => $expectedArabic) {
            $resolved = __($english);

            // The defect: `__()` hands back the key, so this is the assertion that catches it.
            $this->assertNotSame($english, $resolved, sprintf(
                'The Arabic settings page prints "%s" in Latin script: resources/lang/ar.json has '
                . 'no key for it, so __() returned the key unchanged.',
                $english
            ));

            $this->assertSame($expectedArabic, $resolved, sprintf(
                'Currency name "%s" resolved to "%s", expected "%s".',
                $english,
                $resolved,
                $expectedArabic
            ));
        }
    }

    public function test_the_retired_gateway_suffix_has_an_arabic_translation(): void
    {
        app()->setLocale('ar');

        // SettingController.php:2277 appends this to a disabled gateway's own name, so it has to
        // read as a status note sitting after a proper noun, not as a sentence.
        $resolved = __('retired - no longer available');

        $this->assertNotSame('retired - no longer available', $resolved,
            'The Arabic payment-settings tab prints "(retired - no longer available)" in English.');
        $this->assertSame('متوقّف - غير متاح', $resolved);
    }

    public function test_the_english_locale_still_reads_in_english(): void
    {
        app()->setLocale('en');

        // Adding the keys to en.json as well (the integrity ratchet requires the pair) must not
        // change what the English UI says. A translation file that alters its own source language
        // is a regression, not a translation.
        foreach (array_keys(self::CURRENCIES) as $english) {
            $this->assertSame($english, __($english));
        }

        $this->assertSame('retired - no longer available', __('retired - no longer available'));
    }
}
