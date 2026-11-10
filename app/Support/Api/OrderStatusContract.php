<?php

namespace App\Support\Api;

use App\Models\User;

/**
 * STABLE IDENTIFIER AND LOCALISED LABEL for an order's fulfilment status, as API fields.
 *
 * =================================================================================================
 * THE PROBLEM THIS SOLVES (F154), AND THE ONE IT DELIBERATELY DOES NOT
 * =================================================================================================
 *
 * The admin API never runs `SetLocate`. Measured from the booted kernel: the `web` middleware
 * group carries it, the `api` group has two middleware and neither is it, and `setlocate` appears
 * zero times in `routes/api.php`. So every string the server renders through `__()` reaches the
 * Flutter app in the APPLICATION DEFAULT language, not the merchant's — `order_status_text` among
 * them. An Arabic merchant read `Pending` on an otherwise Arabic screen.
 *
 * THE OBVIOUS FIX IS THE WRONG ONE. Adding `SetLocate` to the API group would change the language
 * of existing response fields for every consumer at once. A census of the Flutter client found
 * five places comparing `payment_status` to the English literal `'paid'`
 * (`order_status_badge.dart:389,418,477`, `payment_status_bottom_sheet.dart:26`,
 * `notification_screen.dart:543`). `payment_status` happens to be safe — it is written as a plain
 * literal (`Order.php:650`), never through `__()` — but that is luck, not design, and the next
 * field would not be.
 *
 * SO THIS IS ADDITIVE. `order_status_text` keeps its exact current value and semantics. Two NEW
 * fields sit beside it:
 *
 *     order_status_key    a stable machine-readable identifier, never translated
 *     order_status_label  the display string, in the MERCHANT'S language
 *
 * A consumer that reads `order_status_text` today is unaffected. A consumer that wants to branch
 * should move to `order_status_key`, which is the thing it should have had all along.
 *
 * =================================================================================================
 * THE KEY COMES FROM THE INTEGER, NOT FROM A LANGUAGE FILE
 * =================================================================================================
 *
 * `orders.delivered_status` is the source of truth. The key is derived from it directly, so no
 * edit to `resources/lang/` can change what a consumer branches on. This phase already shipped
 * the opposite once — `GuardsPlanActivation` derived a replay guard's lookup set by globbing the
 * language files, and removing 32 mistranslated brand names silently narrowed it — and the
 * owner's instruction is explicit that security-critical identifiers must not be derived from
 * translation files. Only {@see self::label()} touches the translator.
 *
 * NINE VALUES, NOT SEVEN. `delivered_status` carries 0..8, and the two usually missed are the
 * awkward ones: 7 is Partially Paid (a PAYMENT state) and 8 is Pre Order (an order TYPE), both
 * recorded in the same integer column as the seven fulfilment states. That is a schema problem
 * (host ledger F0) and splitting the column is Phase 04; naming them is not.
 */
final class OrderStatusContract
{
    /**
     * `delivered_status` => stable key. Lower snake_case, never translated, never renamed.
     *
     * These match the keys the Flutter client already uses for its own client-side translation
     * (`order_status_badge.dart:162-186`), so a consumer that adopts `order_status_key` can feed
     * it straight into the lookup it already has.
     *
     * @var array<int, string>
     */
    private const KEYS = [
        0 => 'pending',
        1 => 'delivered',
        2 => 'cancelled',
        3 => 'return',
        4 => 'confirmed',
        5 => 'picked_up',
        6 => 'shipped',
        7 => 'partially_paid',
        8 => 'pre_order',
    ];

    /**
     * The English phrase each key is translated under.
     *
     * Kept separate from KEYS on purpose: the key is a contract and must never move, while the
     * phrase is copy and may be reworded. `Comfirmed` is NOT used here — that host misspelling is
     * pinned for `order_status_text` by `tests/Feature/ApiContract/OrderStatusTextTest` and must
     * stay exactly where it is, but there is no reason to carry it into a new field.
     *
     * @var array<string, string>
     */
    private const PHRASES = [
        'pending' => 'Pending',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        'return' => 'Return',
        'confirmed' => 'Confirmed',
        'picked_up' => 'Picked Up',
        'shipped' => 'Shipped',
        'partially_paid' => 'Partially Paid',
        'pre_order' => 'Pre Order',
    ];

    public const UNKNOWN = 'unknown';

    /** The stable identifier for a `delivered_status` value. */
    public static function key(?int $status): string
    {
        return self::KEYS[$status] ?? self::UNKNOWN;
    }

    /**
     * The display string, in the given locale.
     *
     * Resolved with `trans(..., $locale)` rather than by switching the application locale,
     * because this runs inside a response builder: changing `App::setLocale()` there would leak
     * into every other field assembled afterwards, which is precisely the blast radius this
     * whole approach exists to avoid.
     */
    public static function label(?int $status, ?string $locale = null): string
    {
        $key = self::key($status);
        $phrase = self::PHRASES[$key] ?? null;

        if ($phrase === null) {
            return (string) trans('status_unrecognised', [], $locale ?: app()->getLocale());
        }

        $translated = trans($phrase, [], $locale ?: app()->getLocale());

        // `trans()` answers with the key itself when a locale has no entry. That is the correct
        // fallback for a display field -- English is better than an empty cell -- but it must not
        // be mistaken for a translation, so it is returned as-is rather than retried.
        return is_string($translated) ? $translated : $phrase;
    }

    /**
     * THE MERCHANT'S OWN LANGUAGE, without touching the request's locale.
     *
     * `SetLocate` reads `auth()->user()->language` among its sources, and `CustomAuth` logs an
     * API caller into the `web` guard, so that column is populated on exactly these requests.
     * Reading it directly gives the new label field the right language while leaving every
     * existing field, and the application locale itself, exactly as they were.
     */
    public static function localeForRequest(): string
    {
        $user = auth()->user();

        if ($user instanceof User) {
            $language = trim((string) ($user->language ?? ''));

            if ($language !== '') {
                return $language;
            }
        }

        return app()->getLocale();
    }

    /**
     * The two additive fields for a response payload.
     *
     * @return array{order_status_key: string, order_status_label: string}
     */
    public static function fields(?int $status, ?string $locale = null): array
    {
        $locale = $locale ?: self::localeForRequest();

        return [
            'order_status_key' => self::key($status),
            'order_status_label' => self::label($status, $locale),
        ];
    }

    /** @return array<int, string> */
    public static function allKeys(): array
    {
        return self::KEYS;
    }
}
