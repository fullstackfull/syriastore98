<?php

namespace App\Payments;

/**
 * THE MERCHANT-FACING NAME OF A STORED PAYMENT TYPE — read-side only.
 * ===================================================================
 *
 * F150's decision, as the owner stated it: *"Database stores canonical payment identifiers. UI
 * displays translated payment names."* This class is the second half and ONLY the second half. It
 * reads; it cannot write, backfill, re-charge, change an amount or a status, or decide that a
 * value means COD. Every forbidden operation in that decision is forbidden by construction here,
 * because there is nothing in this file that writes anything.
 *
 * THE FIRST HALF NEEDED NO WORK, WHICH IS WHY THIS IS ALL THERE IS. A six-way census of every
 * consumer and writer of `orders.payment_type` across app/, all 70 vendored modules, the Flutter
 * app and Lynomia found NO writer of that column anywhere that passes a value through `__()`. The
 * one that did — `PosController::330` — was already fixed by F146 and now stores the literal
 * `'POS'`. The 51 remaining translator writers all write `plan_orders`, a different column on a
 * different table, and three of them are keyed by a replay guard
 * (`GuardsPlanActivation::existingPlanOrderForProviderTxn`), so touching them is the
 * *"تغيير في نتيجة Payment Callback"* the owner reserved for independent review. They are
 * recorded in the ledger, not changed.
 *
 * SO WHAT IS LEFT IS A LIVE DISPLAY DEFECT, AND IT IS NOT A LOCALE PROBLEM AT ALL. The order
 * list's own lookup was a CASE-SENSITIVE array index over mixed-case keys
 * (`'NMI'`, `'PayU'`, `'SSLCommerz'`, `'DPO'`…), while the two manual-order writers store the
 * registry ids verbatim — `PaymentSettingController::getPaymentGateways()`'s `id`, which is
 * lowercase for 49 of 52. MEASURED over the whole vocabulary: of the 71 values the two first-party
 * registries and the two stored literals contain, **26 rendered a bare dash** in the order list,
 * in English, with no translation involved. Five had no label at any casing.
 *
 * WHERE THE VOCABULARY COMES FROM, and it was re-derived rather than taken on trust:
 *
 *   - `PaymentSettingController::getPaymentGateways()` — 52 `id`s, a pure array literal, what the
 *     two manual-order writers store (`OrderController:350`, `Api\DashboardController:6242`);
 *   - `Setting::paymentList($slug)` — 44 `name`s, what the storefront checkout posts;
 *   - the two literals live code stores and this project cannot change: `'POS'`
 *     (`PosController:330`, fixed by F146) and `'Lottery Product'`
 *     (`packages/workdo/LotteryPrizes/.../LotteryResultController:135`, vendored, not editable).
 *
 * Case-folded, those sets are NOT the same set — a claim worth correcting because it was made in
 * the census and it is wrong. `paymentList` holds 2 the gateways do not (`other_payment`,
 * `senagepay`), and the gateways hold **10** that `paymentList` does not (`senangpay`,
 * `myfatoorah`, `easebuzz`, `nmi`, `payu`, `paynow`, `dpopay`, `braintree`, `powertranz`,
 * `sslcommerz`) — which are precisely the ones the old map keyed in mixed case. The union is 56
 * distinct identifiers once folded, and that is what this map holds.
 *
 * `Setting::paymentList()` IS NOT CALLED FROM HERE, deliberately: it takes a store slug, hits the
 * settings table per provider and `abort(404)`s on an unknown slug. A label resolver that can
 * abort a response is not a label resolver. Its vocabulary is mirrored as literals instead, and a
 * test asserts the mirror still covers it so the two cannot drift apart silently.
 *
 * NULL IN, NULL OUT — the same rule as `Order::orderSourceLabel()`, which is the shipped
 * first-party precedent for exactly this change on the sibling `order_source` column. An
 * unrecognised value resolves to NULL and every caller renders that absence as an absence. That
 * is what makes this safe for the ambiguous historical rows the owner said to leave alone
 * (*"أبقِ القيم الملتبسة على حالها"*): a row holding `'没有'` — the Chinese translation of `__('POS')`
 * that F146 found in the shipped locales — resolves to NULL and renders a dash. It is NOT
 * interpreted as POS, because *"لا تحويل '没有' إلى POS دون دليل قاطع"*, and a dash is the honest
 * rendering of a value nobody can attribute.
 *
 * BRAND NAMES ARE LITERALS, NOT `__()` CALLS. "Stripe" is Stripe in every locale; routing it
 * through the translator only creates a key a locale file can get wrong. Only the two genuinely
 * descriptive phrases are translated. The old map wrapped all 53 in `__()`, which is why the
 * Arabic order list could in principle have shown a mistranslated brand.
 */
final class PaymentTypeLabel
{
    /**
     * THE DESCRIPTIVE PHRASES, which are the only entries a locale should ever change.
     *
     * `other_payment` is `paymentList`'s own catch-all entry and is a phrase, not a brand.
     */
    private const TRANSLATED = [
        'cod' => 'Cash On Delivery',
        'bank_transfer' => 'Bank Transfer',
        'other_payment' => 'Other Payment',
    ];

    /**
     * THE BRANDS, keyed by the folded identifier.
     *
     * Each label is the one the order list already produced where it had one, so no value that
     * rendered correctly before renders differently now — that is asserted, not assumed. The five
     * that had no label at any casing (`dpopay`, `payhere`, `senangpay`, plus the two phrases
     * above) take the provider's own spelling.
     *
     * `senagepay` and `senangpay` are BOTH kept. They are two different spellings of one provider
     * — `Setting::paymentList()` says SenaGePay, `getPaymentGateways()` says SenanGpay — and until
     * somebody establishes which is the typo, dropping either would turn a stored row into a dash.
     * Recorded as a question for the owner rather than guessed at.
     */
    private const BRANDS = [
        'aamarpay' => 'Aamarpay',
        'authorizenet' => 'AuthorizeNet',
        'benefit' => 'Benefit',
        'braintree' => 'Braintree',
        'cashfree' => 'Cashfree',
        'cinetpay' => 'CinetPay',
        'coingate' => 'Coingate',
        'cybersource' => 'CyberSource',
        'dpopay' => 'DPO Pay',
        'easebuzz' => 'Easebuzz',
        'esewa' => 'Esewa',
        'fedpay' => 'FedPay',
        'flutterwave' => 'Flutterwave',
        'iyzipay' => 'IyziPay',
        'khalti' => 'Khalti',
        'mercado' => 'Mercado Pago',
        'midtrans' => 'Midtrans',
        'mollie' => 'Mollie',
        'myfatoorah' => 'MyFatoorah',
        'nepalste' => 'Nepalste',
        'nmi' => 'NMI',
        'ozow' => 'Ozow',
        'paddle' => 'Paddle',
        'paiementpro' => 'Paiement Pro',
        'payfast' => 'PayFast',
        'payhere' => 'PayHere',
        'paymentwall' => 'PaymentWall',
        'paymera' => 'Paymera',
        'paynow' => 'Paynow',
        'paypal' => 'Paypal',
        'paystack' => 'Paystack',
        'paytabs' => 'Paytabs',
        'paytm' => 'Paytm',
        'paytr' => 'PayTR',
        'payu' => 'PayU',
        'phonepe' => 'PhonePe',
        'powertranz' => 'PowerTranz',
        'razorpay' => 'Razorpay',
        'senagepay' => 'SenagePay',
        'senangpay' => 'SenangPay',
        'skrill' => 'Skrill',
        'sofort' => 'Sofort',
        'sslcommerz' => 'SSLCommerz',
        'sspay' => 'Sspay',
        'stripe' => 'Stripe',
        'tap' => 'Tap',
        'telegram' => 'Telegram',
        'toyyibpay' => 'Toyyibpay',
        'whatsapp' => 'Whatsapp',
        'xendit' => 'Xendit',
        'yookassa' => 'Yookassa',
    ];

    /**
     * THE TWO VALUES THAT ARE NOT REGISTRY IDENTIFIERS, kept separate because they are not
     * configurable gateways and must never be offered as choices anywhere.
     *
     * `POS` is written by `PosController:330` as a literal, which is F146's fix. `Lottery Product`
     * is written by a VENDORED module and is not a payment method at all — the lottery draw stores
     * it alongside `payment_status => 'Paid'` with no money taken. It is labelled rather than
     * hidden because the row exists and a merchant has to be able to read what it says; what it
     * MEANS is a question for the owner, recorded in the ledger.
     */
    private const STORED_LITERALS = [
        'pos' => 'POS',
        'lottery product' => 'Lottery Product',
    ];

    /**
     * The label for a stored payment type, or NULL when there is no honest label for it.
     *
     * Case- and whitespace-insensitive, because the writers disagree about both: the registry ids
     * are 49-of-52 lowercase, `paymentList` has 15 non-lowercase names, and MySQL's PAD SPACE
     * collation means a stored value can carry trailing spaces that never round-trip visibly.
     */
    public static function for($paymentType): ?string
    {
        if (! is_scalar($paymentType)) {
            return null;
        }

        $key = strtolower(trim((string) $paymentType));

        if ($key === '') {
            return null;
        }

        if (isset(self::TRANSLATED[$key])) {
            return __(self::TRANSLATED[$key]);
        }

        return self::BRANDS[$key] ?? self::STORED_LITERALS[$key] ?? null;
    }

    /**
     * Every identifier this resolver knows, folded. For tests and for a census — never for
     * building a picker, because two of these are not selectable gateways.
     */
    public static function known(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(self::TRANSLATED),
            array_keys(self::BRANDS),
            array_keys(self::STORED_LITERALS)
        )));
    }
}
