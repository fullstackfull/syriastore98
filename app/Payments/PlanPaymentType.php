<?php

namespace App\Payments;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * WHAT A `plan_orders.payment_type` VALUE MEANS, whatever language it was written in.
 *
 * =================================================================================================
 * THE DEFECT THIS EXISTS FOR (F153)
 * =================================================================================================
 *
 * `BankTransferController:103` writes `$order->payment_type = __('Bank Transfer')`, so the stored
 * value is whatever the BUYER'S locale rendered on the day they paid. `plans/action.blade.php:2`
 * then compared it against the English literal `'Bank Transfer'` to decide whether to show the
 * Payment Status control -- the one a platform operator uses to approve a bank transfer.
 *
 * For a merchant who submitted in Arabic the column holds `تحويل مصرفي`, the comparison is false,
 * and **the approve control never renders**. The order is stuck: paid, pending, and invisible to
 * the only screen that can advance it.
 *
 * SIXTEEN LOCALES translate `Bank Transfer` -- ar, da, de, es, fr, he, it, ja, nl, pl, pt, pt-br,
 * ru, tr, zh -- so this was never an Arabic bug. English was the only language it worked in.
 *
 * =================================================================================================
 * WHY THIS IS A COMPARISON FIX AND NOT A DATA FIX
 * =================================================================================================
 *
 * The stored values are historical facts. A row written in 2025 holds the spelling that was
 * current then, and no migration can be trusted to rewrite a column that a financial approval
 * workflow reads. So the WRITE side is left exactly as it is and the READ side learns to
 * recognise what it finds -- the same shape as `GuardsPlanActivation::HISTORICAL_SPELLINGS`,
 * which this class deliberately mirrors rather than duplicating: that one answers "is this the
 * same order?" for a replay guard, this one answers "what kind of payment is this?" for a view.
 *
 * NOTHING HERE IS AN AUTHORIZATION DECISION. It decides whether a button is DRAWN. Every
 * authorization on this surface stays exactly where it was: `plan.order.show` is gated by
 * `PlanOrderViewGate`, and approve/reject are gated on their own routes. A merchant who can now
 * see the control still cannot act on it without passing those gates -- which is the point:
 * before this, an OPERATOR could not see it either.
 *
 * =================================================================================================
 * WHY THE SPELLINGS ARE PINNED AND NOT READ FROM THE LANGUAGE FILES ALONE
 * =================================================================================================
 *
 * This phase already shipped the failure mode once. `GuardsPlanActivation` derived a replay
 * guard's lookup set by globbing `resources/lang/*.json`, and removing 32 mistranslated brand
 * names silently narrowed it -- a presentation edit quietly changed what a money control could
 * see. The owner's instruction is explicit: do not derive security-critical identifiers solely
 * from translation files.
 *
 * So the constant below is AUTHORITATIVE and append-only. The live-translation sweep in
 * {@see self::spellings()} is a SUPPLEMENT, so a locale added tomorrow works without an edit
 * here; it can only ever widen the set, never narrow it.
 */
final class PlanPaymentType
{
    public const BANK_TRANSFER = 'bank_transfer';
    public const MANUALLY = 'manually';

    /**
     * Every spelling this application has shipped, recovered from `resources/lang/*.json` at
     * 2026-11-10 and pinned. Append-only: removing one re-opens the defect for rows already
     * written in that language.
     *
     * @var array<string, list<string>>
     */
    private const PINNED = [
        self::BANK_TRANSFER => [
            'Bank Transfer',
            'تحويل مصرفي',            // ar
            'Bankoverførsel',         // da
            'Überweisung',            // de
            'Transferencia bancaria', // es
            'Virement bancaire',      // fr
            'העברה בנקאית',           // he
            'Trasferimento bancario', // it
            '銀行 送金',               // ja
            'Bankoverschrijving',     // nl
            'Przelew bankowy',        // pl
            'Transferência bancária', // pt, pt-br
            'Банковский перевод',     // ru
            'Banka transferi',        // tr
            '银行转账',                // zh
        ],
        self::MANUALLY => [
            'Manually',
            'يدوياً',          // ar
            'Manuelt',        // da
            'Handbetrieb',    // de
            'De manera manual', // es
            'À la main',      // fr
            'ידנית',          // he
            'Manuale',        // it
            '手動で',          // ja
            'Handmatig',      // nl
            'Ręcznie',        // pl
            'Manualmente',    // pt, pt-br
            'Ручной',         // ru
            'El ile',         // tr
            '手动使用',         // zh
        ],
    ];

    /**
     * The English key each canonical type is stored under in the language files, so the live
     * sweep knows what to translate.
     *
     * @var array<string, string>
     */
    private const TRANSLATION_KEY = [
        self::BANK_TRANSFER => 'Bank Transfer',
        self::MANUALLY => 'Manually',
    ];

    /** @var array<string, list<string>>|null */
    private static ?array $cache = null;

    /**
     * Is this stored value that kind of payment, in any language this application has shipped?
     *
     * Comparison is case- and whitespace-insensitive because the column is free text that four
     * different controllers write into; it is NOT fuzzy beyond that, because a near-match on a
     * payment type is a worse answer than no match.
     */
    public static function is(?string $stored, string $canonical): bool
    {
        return self::canonical($stored) === $canonical;
    }

    /** The canonical type for a stored value, or null when nothing recognises it. */
    public static function canonical(?string $stored): ?string
    {
        $needle = self::fold($stored);

        if ($needle === '') {
            return null;
        }

        foreach (self::spellings() as $canonical => $spellings) {
            foreach ($spellings as $spelling) {
                if (self::fold($spelling) === $needle) {
                    return $canonical;
                }
            }
        }

        return null;
    }

    /**
     * Every spelling, pinned first and live translations after.
     *
     * The live sweep reads the language files directly rather than through `trans(..., $locale)`
     * so it cannot be affected by whatever locale is active on the request -- a view rendering
     * for an Arabic merchant must still recognise a row written in French.
     *
     * @return array<string, list<string>>
     */
    public static function spellings(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $all = self::PINNED;

        foreach (self::TRANSLATION_KEY as $canonical => $key) {
            foreach (self::localeFiles() as $file) {
                $decoded = json_decode((string) File::get($file), true);

                if (! is_array($decoded)) {
                    // A malformed language file must not take a money screen down with it.
                    continue;
                }

                $value = $decoded[$key] ?? null;

                if (is_string($value) && trim($value) !== '' && ! in_array($value, $all[$canonical], true)) {
                    $all[$canonical][] = $value;
                }
            }
        }

        return self::$cache = $all;
    }

    /** @return list<string> */
    private static function localeFiles(): array
    {
        $path = resource_path('lang');

        if (! File::isDirectory($path)) {
            return [];
        }

        return array_values(array_filter(
            File::glob($path . '/*.json') ?: [],
            // `language.json` is the LIST of languages, not a translation map.
            static fn ($file) => basename($file) !== 'language.json'
        ));
    }

    private static function fold(?string $value): string
    {
        return Str::lower(trim(preg_replace('/\s+/u', ' ', (string) $value) ?? ''));
    }

    /** Test seam: the live sweep is cached for the request, and a test may change a file. */
    public static function flushCache(): void
    {
        self::$cache = null;
    }
}
