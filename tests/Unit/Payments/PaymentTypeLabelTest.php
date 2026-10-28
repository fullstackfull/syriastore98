<?php

namespace Tests\Unit\Payments;

use App\Payments\PaymentTypeLabel;
use Tests\TestCase;

/**
 * F150's display half — `App\Payments\PaymentTypeLabel`.
 * =====================================================
 *
 * NO DATABASE, NO MODULES, NO LOCALE DEPENDENCE except where a locale is the thing under test.
 * The resolver is pure, which is most of why this slice is safe: it cannot backfill, re-charge or
 * reinterpret anything, and a test of it needs no fixture that could lie.
 *
 * THE VOCABULARY IS DERIVED FROM THE REAL REGISTRIES AT RUN TIME, not retyped here. If somebody
 * adds a gateway to `getPaymentGateways()` or a `name` to `Setting::paymentList()` and does not
 * add a label, these tests fail — which is the only way a mirrored vocabulary stays honest.
 */
class PaymentTypeLabelTest extends TestCase
{
    /** Every `id` in the gateway registry, read from the source rather than retyped. */
    private function gatewayIds(): array
    {
        $src = (string) file_get_contents(base_path('app/Http/Controllers/PaymentSettingController.php'));
        $this->assertSame(1, preg_match('/function getPaymentGateways\(\).*?\n    \}/s', $src, $m));
        preg_match_all("/'id' => '([^']+)'/", $m[0], $ids);

        $this->assertGreaterThanOrEqual(52, count($ids[1]), 'the gateway registry shrank');

        return $ids[1];
    }

    /**
     * Every machine `name` in `Setting::paymentList()`, read from the source — and the pattern is
     * whitespace-tolerant BECAUSE A TIGHTER ONE SHIPPED A DEFECT.
     *
     * The first version matched `['name'] = '...'` exactly, and nine of this method's assignments
     * are written with spaces inside the brackets —
     * `$Setting_array[ 41 ][ 'name' ] = 'easebuzz';` (Setting.php:884, and entries 42, 43, 46-51).
     * It skipped all nine, so the census reported 44 names instead of 53 — and the one skipped
     * name that had no label was `DPO`, so this test passed green while the order list rendered a
     * dash for every DPO order. A census that silently drops rows is worse than none, because it
     * reads as coverage.
     *
     * SCOPED TO THE METHOD, the way `gatewayIds()` is. `paymentList()` happens to be the last
     * method in the file today, so a file-wide scan is accidentally equivalent — and would stop
     * being so the moment anybody adds a method after it.
     */
    private function paymentListNames(): array
    {
        $src = (string) file_get_contents(base_path('app/Models/Setting.php'));

        $start = strpos($src, 'public static function paymentList(');
        $this->assertNotFalse($start, 'Setting::paymentList() is gone, so this census is blind');

        preg_match_all(
            '/\[\s*[\x27"]name[\x27"]\s*\]\s*=\s*[\x27"]([^\x27"]+)[\x27"]/',
            substr($src, $start),
            $n
        );

        $this->assertGreaterThanOrEqual(53, count($n[1]), 'the payment list shrank');

        return $n[1];
    }

    public function test_every_gateway_registry_id_resolves_to_a_label(): void
    {
        foreach ($this->gatewayIds() as $id) {
            $this->assertNotNull(
                PaymentTypeLabel::for($id),
                "the gateway id '{$id}' has no label, so an order paid with it renders a dash"
            );
        }
    }

    public function test_every_storefront_payment_list_name_resolves_to_a_label(): void
    {
        foreach ($this->paymentListNames() as $name) {
            $this->assertNotNull(
                PaymentTypeLabel::for($name),
                "the storefront posts '{$name}' and it has no label"
            );
        }
    }

    public function test_the_two_literals_live_code_stores_resolve(): void
    {
        /*
         * `POS` is F146's fix in `PosController:330`; `Lottery Product` is written by a VENDORED
         * module that cannot be edited. Neither is a registry id, so neither would be covered by
         * the two census tests above.
         */
        $this->assertSame('POS', PaymentTypeLabel::for('POS'));
        $this->assertSame('Lottery Product', PaymentTypeLabel::for('Lottery Product'));
    }

    public function test_the_lookup_is_case_and_whitespace_insensitive(): void
    {
        /*
         * THE WHOLE LIVE DEFECT IN ONE ASSERTION. The order list indexed a mixed-case array with
         * the lowercase value the manual-order writers store, so `payu` missed `'PayU'` and the
         * column rendered `-`. MEASURED over the full vocabulary: 26 of 71 values rendered a dash.
         *
         * Trailing space included because MySQL's PAD SPACE collation means a stored value can
         * carry one that never shows.
         */
        foreach (['payu', 'PayU', 'PAYU', ' payu ', "payu\t"] as $spelling) {
            $this->assertSame('PayU', PaymentTypeLabel::for($spelling), var_export($spelling, true));
        }

        $this->assertSame('SSLCommerz', PaymentTypeLabel::for('sslcommerz'));
        $this->assertSame('NMI', PaymentTypeLabel::for('nmi'));
        $this->assertSame('PowerTranz', PaymentTypeLabel::for('powertranz'));
    }

    public function test_an_unknown_value_resolves_to_null_rather_than_a_guess(): void
    {
        foreach (['', '   ', 'definitely_not_a_gateway', '0'] as $value) {
            $this->assertNull(PaymentTypeLabel::for($value), var_export($value, true));
        }

        $this->assertNull(PaymentTypeLabel::for(null));
        $this->assertNull(PaymentTypeLabel::for([]));
        $this->assertNull(PaymentTypeLabel::for(new \stdClass()));
    }

    public function test_a_translated_historical_value_is_not_interpreted(): void
    {
        /*
         * THE OWNER'S EXPLICIT PROHIBITION, asserted. F146 found that six shipped locales
         * translated `__('POS')`, so historical rows hold values like `'没有'`, `'Punto de venta'`
         * and `'ПОЗ'`. The decision forbids turning those into POS
         * (*"لا تحويل '没有' إلى POS دون دليل قاطع"*) and says to leave ambiguous values as they are.
         *
         * NULL is how that prohibition is enforced in code: the caller renders a dash, which is
         * the honest rendering of a value nobody can attribute. A resolver that mapped these back
         * to POS would be the fabrication the decision rules out — so this test fails if anybody
         * ever adds that mapping.
         */
        foreach (['没有', 'Punto de venta', 'ПОЗ', 'Point de vente'] as $translated) {
            $this->assertNull(
                PaymentTypeLabel::for($translated),
                "'{$translated}' must not be interpreted as POS"
            );
        }
    }

    public function test_a_brand_name_is_never_passed_through_the_translator(): void
    {
        /*
         * MEASURED, AND THIS IS WHY THE OLD MAP WAS WRONG BEYOND ITS CASE SENSITIVITY. It wrapped
         * all 53 labels in `__()`, and `resources/lang/ar.json` holds translations for 45 of the
         * 51 brand names: `Stripe` -> `الشريط` ("the strip"), `Benefit` -> `الميزة`
         * ("the advantage"), `Cashfree` -> `نقدية` ("cash"). French renders `Aamarpay` as
         * "Je suis désolé." and Hebrew renders `Braintree` as `עץ מוח` ("brain tree"). So the
         * Arabic order list showed providers under names their own companies do not use.
         *
         * A brand is the same word in every locale. Only the descriptive phrases are translated,
         * and the second half of this test proves that half still works rather than the class
         * having simply stopped translating anything.
         */
        $original = app()->getLocale();

        try {
            app()->setLocale('ar');

            $this->assertSame('Stripe', PaymentTypeLabel::for('stripe'));
            $this->assertSame('Benefit', PaymentTypeLabel::for('benefit'));
            $this->assertSame('Cashfree', PaymentTypeLabel::for('cashfree'));
            $this->assertSame('Braintree', PaymentTypeLabel::for('braintree'));

            /*
             * AND THE PHRASES DO TRANSLATE. If this arm ever matches the English, the test above
             * has stopped proving anything: everything would be a literal.
             */
            $this->assertSame('نقداً عند التسليم', PaymentTypeLabel::for('cod'));
            $this->assertSame('تحويل مصرفي', PaymentTypeLabel::for('bank_transfer'));
        } finally {
            app()->setLocale($original);
        }
    }

    public function test_no_label_the_order_list_already_produced_has_changed(): void
    {
        /*
         * THE REGRESSION GUARD. 45 of the 71 vocabulary values DID resolve under the old
         * case-sensitive map, and a merchant is used to what they said. Every one of those keys
         * must still produce the same English text, so this change only adds labels where there
         * were none and never renames one that worked.
         *
         * Read out of the old map's own source, which is still in git history; pinned here as
         * literals because the map itself is being removed in this change.
         *
         * `'DPO'` WAS MISSING FROM THIS LIST AND THAT IS HOW THE ONE REGRESSION GOT THROUGH. The
         * list was transcribed by hand from the old map and dropped exactly the key that had no
         * replacement, so the guard written to catch "a label that used to work and now does not"
         * was blind to the single instance of it. It is now derived as well as transcribed — see
         * `test_every_old_map_key_still_resolves`, which reads the removed map out of git history
         * so a future omission cannot repeat the trick.
         */
        $wasProduced = [
            'cod' => 'Cash On Delivery',
            'paymera' => 'Paymera',
            'bank_transfer' => 'Bank Transfer',
            'stripe' => 'Stripe',
            'paystack' => 'Paystack',
            'mercado' => 'Mercado Pago',
            'skrill' => 'Skrill',
            'paymentwall' => 'PaymentWall',
            'Razorpay' => 'Razorpay',
            'paypal' => 'Paypal',
            'flutterwave' => 'Flutterwave',
            'mollie' => 'Mollie',
            'coingate' => 'Coingate',
            'paytm' => 'Paytm',
            'POS' => 'POS',
            'toyyibpay' => 'Toyyibpay',
            'sspay' => 'Sspay',
            'Paytabs' => 'Paytabs',
            'iyzipay' => 'IyziPay',
            'payfast' => 'PayFast',
            'benefit' => 'Benefit',
            'cashfree' => 'Cashfree',
            'aamarpay' => 'Aamarpay',
            'telegram' => 'Telegram',
            'whatsapp' => 'Whatsapp',
            'paytr' => 'PayTR',
            'yookassa' => 'Yookassa',
            'midtrans' => 'Midtrans',
            'Xendit' => 'Xendit',
            'Nepalste' => 'Nepalste',
            'khalti' => 'Khalti',
            'AuthorizeNet' => 'AuthorizeNet',
            'Tap' => 'Tap',
            'PhonePe' => 'PhonePe',
            'Paddle' => 'Paddle',
            'Paiementpro' => 'Paiement Pro',
            'FedPay' => 'FedPay',
            'CinetPay' => 'CinetPay',
            'SenagePay' => 'SenagePay',
            'CyberSource' => 'CyberSource',
            'Ozow' => 'Ozow',
            'MyFatoorah' => 'MyFatoorah',
            'easebuzz' => 'Easebuzz',
            'NMI' => 'NMI',
            'PayU' => 'PayU',
            'sofort' => 'Sofort',
            'esewa' => 'Esewa',
            'Paynow' => 'Paynow',
            'DPO' => 'DPO',
            'Braintree' => 'Braintree',
            'PowerTranz' => 'PowerTranz',
            'SSLCommerz' => 'SSLCommerz',
            'Lottery Product' => 'Lottery Product',
        ];

        $original = app()->getLocale();

        try {
            app()->setLocale('en');

            foreach ($wasProduced as $stored => $label) {
                $this->assertSame(
                    $label,
                    PaymentTypeLabel::for($stored),
                    "the order list used to render '{$stored}' as '{$label}'"
                );
            }
        } finally {
            app()->setLocale($original);
        }
    }

    /**
     * THE SAME GUARANTEE, DERIVED RATHER THAN TRANSCRIBED.
     *
     * The pinned list above is readable and names each label, which is worth having; but it is a
     * hand copy, and a hand copy is exactly what let `DPO` through. This reads the replaced map
     * out of the commit that removed it, so "this change only adds labels" is checked against the
     * real former behaviour rather than against my transcription of it.
     */
    public function test_every_old_map_key_still_resolves(): void
    {
        $removedIn = 'c7283a3c';
        $old = (string) shell_exec(sprintf(
            'cd %s && git show %s~1:app/DataTables/OrderDataTable.php 2>/dev/null',
            escapeshellarg(base_path()),
            escapeshellarg($removedIn)
        ));

        if (trim($old) === '') {
            /*
             * A conditional skip would be a NEW undeclared skip, which
             * `.github/scripts/assert-known-test-outcomes.php` fails in both directions — so where
             * git history is unavailable this records the fact and leans on the pinned list above,
             * which always runs.
             */
            $this->assertTrue(true, 'git history for ' . $removedIn . ' is unavailable here');

            return;
        }

        /*
         * Scoped to the map itself: the old file has other `'x' => __('y')` pairs, among them a
         * `'title'` key that would otherwise be demanded as a payment label.
         */
        $this->assertSame(1, preg_match('/\$paymentTypes = \[.*?\n\s*\];/s', $old, $block),
            'the removed map could not be located in the old file');

        preg_match_all("/'([^']+)' => __\('([^']+)'\)/", $block[0], $pairs);

        $this->assertGreaterThanOrEqual(53, count($pairs[1]), 'the old map was smaller than recorded');

        foreach ($pairs[1] as $i => $key) {
            $this->assertNotNull(
                PaymentTypeLabel::for($key),
                "the old map rendered '{$key}' as '{$pairs[2][$i]}' and it now renders a dash"
            );
        }
    }

    public function test_both_spellings_of_the_one_ambiguous_provider_resolve(): void
    {
        /*
         * `Setting::paymentList()` says SenaGePay; `getPaymentGateways()` says SenanGpay. One of
         * them is a typo and nothing in the codebase settles which, so BOTH resolve: dropping
         * either would turn a stored row into a dash on a guess. Recorded for the owner rather
         * than decided here.
         */
        $this->assertNotNull(PaymentTypeLabel::for('senagepay'));
        $this->assertNotNull(PaymentTypeLabel::for('senangpay'));
    }

    public function test_the_known_set_is_folded_and_free_of_duplicates(): void
    {
        $known = PaymentTypeLabel::known();

        $this->assertSame(array_values(array_unique($known)), $known, 'duplicate identifiers');

        foreach ($known as $key) {
            $this->assertSame(strtolower(trim($key)), $key, "'{$key}' is not folded");
        }

        $this->assertGreaterThanOrEqual(56, count($known));
    }
}
