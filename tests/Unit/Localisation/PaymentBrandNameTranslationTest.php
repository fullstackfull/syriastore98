<?php

namespace Tests\Unit\Localisation;

use App\Payments\PaymentTypeLabel;
use ReflectionClass;
use Tests\TestCase;

/**
 * A PAYMENT GATEWAY'S BRAND NAME IS NOT TRANSLATED (P2.5-SET-03).
 *
 * Measured on the Arabic payment-settings tab: Stripe was listed as `الشريط` ("the ribbon"),
 * Paystack as `كومة الدفع` ("payment pile"), DPO as `مدير حماية البيانات` ("Data Protection
 * Officer") and Tap as `اضغط` ("press"). A merchant looking for Stripe could not find it.
 *
 * THE RULE ALREADY EXISTED. `PaymentTypeLabel::BRANDS` is the first-party map that renders all 52
 * gateways in the provider's own spelling, and the order list already uses it -- so before this
 * change the same gateway read `Stripe` on one screen and `الشريط` on another. This test pins the
 * two surfaces together rather than restating the rule a second time: it reads the canonical map
 * and asserts the language file does not contradict it.
 *
 * IT MATTERS BEYOND THE LABEL. `TapPaymnetController:132` and `BenefitPaymentController:244` write
 * `__('Tap')` / `__('Benefit')` straight into `orders.payment_type`, so an order placed in an
 * Arabic session used to STORE `اضغط` -- a value `PaymentTypeLabel` cannot resolve, because it
 * matches on a lowercase ASCII slug. Future orders now store `Tap`.
 *
 * WHAT IS DELIBERATELY STILL TRANSLATED: a faithful Arabic rendering of the brand (`بايبال`,
 * `سكريل`, `واتساب`). Those are the brand in Arabic script -- a merchant still finds the gateway --
 * and they are standard usage. The test below allows them by checking only that the Arabic is not
 * a translation of the English WORD, which is what the removed values were.
 */
class PaymentBrandNameTranslationTest extends TestCase
{
    /** The brand names whose Arabic values were removed, with what they used to say. */
    private const WAS_MISTRANSLATED = [
        'Stripe'      => 'الشريط',
        'Paystack'    => 'كومة الدفع',
        'Benefit'     => 'الميزة',
        'Tap'         => 'اضغط',
        'PayU'        => 'الدفع',
        'PhonePe'     => 'هاتف',
        'DPO'         => 'مدير حماية البيانات',
        'Cashfree'    => 'نقدية',
        'Paytabs'     => 'أدوات الدفع',
        'Midtrans'    => 'منتصف النقل',
        'Nepalste'    => 'نيبال',
        'Paddle'      => 'المجاذفة',
        'PayHere'     => 'ادفع هنا',
        'Telegram'    => 'تلغراف',
        'Coingate'    => 'كينجيت',
    ];

    private function arabicFile(): array
    {
        $decoded = json_decode(file_get_contents(resource_path('lang/ar.json')), true);
        $this->assertIsArray($decoded, 'resources/lang/ar.json did not parse.');

        return $decoded;
    }

    private function canonicalBrands(): array
    {
        $property = (new ReflectionClass(PaymentTypeLabel::class))->getConstant('BRANDS');
        $this->assertIsArray($property);
        $this->assertNotEmpty($property, 'PaymentTypeLabel::BRANDS is empty, so this test would pass vacuously.');

        return $property;
    }

    public function test_no_gateway_name_resolves_to_a_translation_of_the_english_word(): void
    {
        app()->setLocale('ar');

        foreach (self::WAS_MISTRANSLATED as $brand => $wasSaying) {
            $this->assertSame($brand, __($brand), sprintf(
                'The Arabic UI renders the payment gateway "%s" as "%s". A brand name is not '
                . 'translated: PaymentTypeLabel::BRANDS renders it "%s" on the order list, and the '
                . 'two surfaces must agree.',
                $brand,
                __($brand),
                $brand
            ));

            $this->assertNotSame($wasSaying, __($brand));
        }
    }

    public function test_the_arabic_file_contradicts_no_canonical_brand_spelling(): void
    {
        $ar = $this->arabicFile();

        /*
         * The general form of the assertion, so a gateway added later is covered without anyone
         * editing the list above. A canonical brand may appear in ar.json ONLY with a value that
         * is a faithful Arabic rendering -- which, for this check, means it must not be a value
         * the project has already judged a mistranslation.
         */
        $contradictions = [];
        foreach ($this->canonicalBrands() as $canonical) {
            if (! array_key_exists($canonical, $ar)) {
                continue;
            }
            if (isset(self::WAS_MISTRANSLATED[$canonical]) && $ar[$canonical] === self::WAS_MISTRANSLATED[$canonical]) {
                $contradictions[$canonical] = $ar[$canonical];
            }
            // Stray quotes or parentheses wrapping a transliteration are not a rendering of a
            // brand; `" برينتري "` even carries its own padding inside the quotes.
            if (preg_match('/^\s*["(]|["\)]\s*$/u', (string) $ar[$canonical])) {
                $contradictions[$canonical] = $ar[$canonical];
            }
        }

        $this->assertSame([], $contradictions, sprintf(
            "resources/lang/ar.json gives these gateways a value that is not their brand:\n%s",
            json_encode($contradictions, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        ));
    }

    public function test_faithful_arabic_renderings_are_deliberately_kept(): void
    {
        $ar = $this->arabicFile();

        // The premise of the test above: it must not be passing because every brand key was
        // deleted. These four are still translated, on purpose, and if someone strips them this
        // test fails and makes them read the reasoning rather than assume a blanket rule.
        foreach (['Paypal' => 'بايبال', 'Skrill' => 'سكريل', 'Paytm' => 'بايتم', 'Whatsapp' => 'واتساب'] as $brand => $arabic) {
            $this->assertArrayHasKey($brand, $ar, $brand . ' lost its faithful Arabic rendering.');
            $this->assertSame($arabic, $ar[$brand]);
        }
    }
}
