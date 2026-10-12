<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Reconciles a claimed order total against its components, exactly.
 *
 * WHY THIS EXISTS
 * ---------------
 * Order totals are written at several sites and are never checked against the
 * components they are supposed to be the sum of, so a tampered or miscomputed
 * component silently produces a ledger row that does not add up. This class is
 * the one place that answers "do these numbers agree?".
 *
 * WHY NOT `==`
 * ------------
 * `10 * 1234.10 == 12341.00` is FALSE in PHP, and `orders.final_price` is a
 * float column today, so a float comparison both accepts totals that are wrong
 * and rejects totals that are right. Every comparison here is done on exact
 * integer minor units derived from the decimal STRING, never on a float.
 *
 * WHY NOT bcmath / brick/math
 * ---------------------------
 * `php -m` on this platform shows neither `bcmath` nor `gmp`, and the locked
 * `brick/math` package is not restorable in this checkout, so neither could be
 * executed — let alone tested — while this was written. The arithmetic needed
 * here is addition and comparison of amounts that fit comfortably inside a
 * 64-bit integer once scaled (DECIMAL(18,4) is 14 integer digits + 4 decimals,
 * max 10^18, against PHP_INT_MAX ~9.2*10^18), so it is done with native integer
 * arithmetic on exactly-parsed strings. Anything that does not fit is REJECTED
 * rather than approximated — see {@see toMinorUnits()}.
 */
class OrderTotalReconciler
{
    /**
     * Decimal places money is reconciled at.
     *
     * Scale 4, not 2: the seeded currency list includes the 3-decimal KWD, BHD
     * and OMR, and the DECIMAL(18,4) target shape in the database recovery plan
     * is the same scale.
     */
    public const SCALE = 4;

    /** Largest number of integer digits an amount may have (DECIMAL(18,4)). */
    public const MAX_INTEGER_DIGITS = 14;

    /**
     * Order components that are SUBTRACTED from the total rather than added.
     *
     * Everything else in the components map is added. Named explicitly so a new
     * component cannot be added to a caller without a decision being made about
     * its sign.
     */
    public const SUBTRACTIVE_COMPONENTS = [
        'coupon_price',
        'reward_discount',
        'return_price',
    ];

    /**
     * Reconcile a claimed total against its components.
     *
     * Nothing is thrown and nothing is logged: the caller decides what a
     * mismatch means. {@see assertConsistent()} is the throwing variant and
     * {@see warnIfInconsistent()} the warn-only one for pre-cutover rows.
     *
     * @param  array<string, mixed> $components  e.g.
     *         ['product_price' => '100.00', 'tax_price' => '15.00',
     *          'delivery_price' => '5.00',  'coupon_price' => '20.00']
     * @param  mixed $claimedTotal The total as persisted or as claimed.
     * @return array{
     *     matches: bool,
     *     reason: string,
     *     expected: ?string,
     *     claimed: ?string,
     *     difference: ?string,
     *     components: array<string, string>
     * }
     */
    public function reconcile(array $components, $claimedTotal): array
    {
        $normalised = [];
        $expectedMinor = 0;

        foreach ($components as $name => $value) {
            $minor = $this->tryToMinorUnits($value);

            if ($minor === null) {
                return $this->result(false, 'component_not_representable:' . $name, null, null, null, $normalised);
            }

            $normalised[$name] = $this->fromMinorUnits($minor);

            $expectedMinor += in_array($name, self::SUBTRACTIVE_COMPONENTS, true)
                ? -$minor
                : $minor;
        }

        $claimedMinor = $this->tryToMinorUnits($claimedTotal);

        if ($claimedMinor === null) {
            return $this->result(
                false,
                'claimed_total_not_representable',
                $this->fromMinorUnits($expectedMinor),
                null,
                null,
                $normalised
            );
        }

        $differenceMinor = $claimedMinor - $expectedMinor;

        return $this->result(
            $differenceMinor === 0,
            $differenceMinor === 0 ? 'ok' : 'mismatch',
            $this->fromMinorUnits($expectedMinor),
            $this->fromMinorUnits($claimedMinor),
            $this->fromMinorUnits($differenceMinor),
            $normalised
        );
    }

    /**
     * Reconcile and throw when the numbers do not agree.
     *
     * Used on the WRITE path, where a total that does not add up must never
     * reach the database.
     *
     * @throws RuntimeException
     */
    public function assertConsistent(array $components, $claimedTotal, string $site): array
    {
        $result = $this->reconcile($components, $claimedTotal);

        if ($result['matches']) {
            return $result;
        }

        Log::error('Order total reconciliation FAILED', [
            'site' => $site,
            'reason' => $result['reason'],
            'expected' => $result['expected'],
            'claimed' => $result['claimed'],
            'difference' => $result['difference'],
            'components' => $result['components'],
        ]);

        throw new RuntimeException(
            'Order total does not match its components ('
            . $result['reason'] . '): expected ' . $result['expected']
            . ', got ' . $result['claimed'] . '.'
        );
    }

    /**
     * Reconcile and only log when the numbers do not agree.
     *
     * Used when auditing rows written BEFORE this reconciler existed. Those
     * totals were produced by the old, unreconciled code, so failing them would
     * break legitimate historical orders; they are reported, not repaired.
     *
     * @return bool Whether the row reconciles.
     */
    public function warnIfInconsistent(array $components, $claimedTotal, string $site): bool
    {
        $result = $this->reconcile($components, $claimedTotal);

        if (!$result['matches']) {
            Log::warning('Order total reconciliation mismatch (warn-only)', [
                'site' => $site,
                'reason' => $result['reason'],
                'expected' => $result['expected'],
                'claimed' => $result['claimed'],
                'difference' => $result['difference'],
                'components' => $result['components'],
            ]);
        }

        return $result['matches'];
    }

    /**
     * Exact equality of two money amounts at {@see SCALE}.
     *
     * Returns false when either side is not exactly representable at that
     * scale — an unrepresentable amount is not equal to anything, because the
     * money column could not hold it.
     */
    public static function amountsMatch($a, $b): bool
    {
        $reconciler = new self();

        $left = $reconciler->tryToMinorUnits($a);
        $right = $reconciler->tryToMinorUnits($b);

        if ($left === null || $right === null) {
            return false;
        }

        return $left === $right;
    }

    /**
     * Convert an amount to exact integer minor units, or null if it cannot be
     * represented exactly at {@see SCALE}.
     *
     * Returning null rather than rounding is the whole point. A claimed total of
     * `12341.0000000000018` is NOT `12341.0000`; rounding it to scale would make
     * a float artefact look like a correct total, which is exactly the class of
     * bug this reconciler exists to catch.
     */
    public function tryToMinorUnits($value): ?int
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return null;
        }

        if (is_int($value)) {
            $text = (string) $value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                return null;
            }

            // json_encode uses serialize_precision, which defaults to the
            // shortest string that round-trips. That keeps the float's real
            // value visible (12341.0000000000018 stays 12341.000000000002)
            // instead of hiding it behind precision=14 string casting, which
            // would print a clean "12341".
            $text = json_encode($value);

            if (!is_string($text)) {
                return null;
            }
        } elseif (is_string($value)) {
            $text = trim($value);
        } else {
            return null;
        }

        // Scientific notation is not reconcilable without rounding, and no money
        // column produces it; treat it as unrepresentable rather than guessing.
        if (!preg_match('/^(?<sign>[+-]?)(?<int>\d*)(?:\.(?<frac>\d*))?$/', $text, $m)) {
            return null;
        }

        $integerPart = $m['int'] ?? '';
        $fractionPart = $m['frac'] ?? '';

        if ($integerPart === '' && $fractionPart === '') {
            return null;
        }

        // More precision than the money scale can hold -> not representable.
        if (rtrim($fractionPart, '0') !== '' && strlen(rtrim($fractionPart, '0')) > self::SCALE) {
            return null;
        }

        $integerPart = ltrim($integerPart, '0');

        if (strlen($integerPart) > self::MAX_INTEGER_DIGITS) {
            return null;
        }

        $scaledFraction = substr(str_pad($fractionPart, self::SCALE, '0'), 0, self::SCALE);
        $digits = ($integerPart === '' ? '0' : $integerPart) . $scaledFraction;

        $minor = (int) $digits;

        // Defensive: the digit-count caps above keep this inside PHP_INT_MAX,
        // so a disagreement here means the caps were edited without re-deriving
        // the bound.
        if ((string) $minor !== ltrim($digits, '0') && ltrim($digits, '0') !== '') {
            return null;
        }

        return ($m['sign'] ?? '') === '-' ? -$minor : $minor;
    }

    /**
     * Render integer minor units back as a fixed-scale decimal string.
     */
    public function fromMinorUnits(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $digits = str_pad((string) abs($minor), self::SCALE + 1, '0', STR_PAD_LEFT);

        return $sign
            . substr($digits, 0, strlen($digits) - self::SCALE)
            . '.'
            . substr($digits, -self::SCALE);
    }

    private function result(
        bool $matches,
        string $reason,
        ?string $expected,
        ?string $claimed,
        ?string $difference,
        array $components
    ): array {
        return [
            'matches' => $matches,
            'reason' => $reason,
            'expected' => $expected,
            'claimed' => $claimed,
            'difference' => $difference,
            'components' => $components,
        ];
    }
}
