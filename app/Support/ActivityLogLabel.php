<?php

namespace App\Support;

/**
 * THE MERCHANT-FACING NAME OF A STORED ACTIVITY-LOG TYPE — read-side only.
 * ========================================================================
 *
 * Built in the shape of `App\Payments\PaymentTypeLabel`, which `customer/view.blade.php:117`
 * already uses for this exact problem on the sibling screen. Like that one, this class READS. It
 * has no writer, so it cannot change what `activity_logs.log_type` stores, and the stored value
 * remains the switch discriminator the timeline branches on.
 *
 * THE DEFECT IT CLOSES. `customer/timeline.blade.php` printed `{{ $value->log_type }}` BARE at
 * fourteen sites — never through `__()`. The stored values are the lowercase enum strings
 * `register`, `last login`, `add wishlist`, `delete wishlist`, `add to cart`, `remove to cart`
 * and `place order`, and **all seven were measured absent from `resources/lang/ar.json`**, so
 * wrapping the call in `__()` on its own would have changed nothing: there was nothing to
 * translate to. The seven keys are added with this change.
 *
 * WHY A MAP AND NOT `__($value->log_type)` DIRECTLY. Two reasons, both from the sibling's
 * experience. A bare translator call on a database value silently renders the raw value when the
 * key is missing, which is how fourteen English strings survived on an Arabic screen unnoticed.
 * And the stored vocabulary is a closed set of seven, so an unknown value is a DATA problem worth
 * seeing rather than passing through — this returns the raw value for one, unchanged, so nothing
 * disappears, but the set is written down in one place where it can be checked against the
 * writers.
 *
 * THE WRITERS, enumerated so the map can be checked rather than trusted: `ActivityLog::create()`
 * call sites across app/ and the vendored modules write exactly these seven strings.
 */
class ActivityLogLabel
{
    /**
     * The seven stored values, mapped to their translatable merchant-facing names.
     *
     * The KEY is what the database holds and what the view's `@switch` branches on; it must not
     * change. Only the value a merchant reads is translated.
     */
    private const LABELS = [
        'register' => 'Registered',
        'last login' => 'Signed in',
        'add wishlist' => 'Added to wishlist',
        'delete wishlist' => 'Removed from wishlist',
        'add to cart' => 'Added to cart',
        'remove to cart' => 'Removed from cart',
        'place order' => 'Placed an order',
    ];

    public static function for(?string $type): string
    {
        $key = trim((string) $type);

        if ($key === '') {
            return '';
        }

        // Unknown values pass through UNCHANGED rather than becoming a dash. A value outside the
        // seven is a writer this map has not been told about, and showing it is how that gets
        // noticed; hiding it behind a placeholder is how the sibling screen lost 26 values.
        if (! array_key_exists($key, self::LABELS)) {
            return $key;
        }

        return __(self::LABELS[$key]);
    }

    /** The stored vocabulary, for tests that assert the map covers every writer. */
    public static function known(): array
    {
        return array_keys(self::LABELS);
    }
}
