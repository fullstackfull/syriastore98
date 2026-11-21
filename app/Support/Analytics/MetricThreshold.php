<?php

namespace App\Support\Analytics;

use App\Models\Setting;
use App\Support\Metrics\CollectedCustomerPayments;
use App\Support\Metrics\GrossMerchandiseValue;
use App\Support\Metrics\MetricScope;
use App\Support\Metrics\MetricWindow;

/**
 * ANA-009 -- A THRESHOLD ON A METRIC, AND THE MERCHANT SETS IT.
 *
 * The traceability row measured the gap exactly: "No sales-drop, trend, anomaly, forecast or
 * threshold-on-a-metric detection exists anywhere in app/ or packages/workdo/". It also names the
 * trap. Deciding what counts as a "drop" -- 20%? against last week? against the same week last
 * year? -- is a BUSINESS POLICY, and this project's standing rule is not to invent one.
 *
 * SO THERE IS NO DEFAULT AND NO SHIPPED NUMBER. A store has no thresholds until a merchant writes
 * one, and `for()` returns an empty array for every store that has not. That is the whole design:
 * the engineering is the comparison, the policy is the merchant's, and absent configuration NOTHING
 * fires. An invented default would be the thing to avoid even if it were generous, because a
 * merchant cannot tell a threshold they chose from one chosen for them.
 *
 * IT IS A FLOOR, NOT A TREND. The comparison is "this period's figure is below the number you set",
 * which needs no baseline, no seasonality and no forecast -- all of which would require policy this
 * slice is not entitled to write. Trend and anomaly detection remain genuinely absent and the
 * `ANA-009` row says so; what is delivered here is the threshold half, named as such.
 *
 * REUSE, NOT A SECOND ENGINE. The figures come from the metric layer (`CollectedCustomerPayments`,
 * `GrossMerchandiseValue`) -- the same objects the reports and `/finance` read, so an alert cannot
 * disagree with the screen the merchant checks after receiving it. The threshold is an ordinary
 * `settings` row, the same mechanism `out_of_stock_threshold` already uses, so there is no new table
 * and no migration.
 */
final class MetricThreshold
{
    /**
     * The metrics a merchant may set a floor on, and the setting name each reads.
     *
     * DELIBERATELY SHORT. Each entry is a figure this project has already defined and labelled under
     * owner decision D2, so an alert's wording can be the label the merchant already knows. Adding a
     * metric here means defining what its floor means, which is a product decision rather than a
     * loop iteration.
     */
    public const METRICS = [
        'payments_received' => [
            'setting' => 'alert_min_payments_received',
            'metric' => CollectedCustomerPayments::class,
            'label' => 'Payments received',
        ],
        'order_value' => [
            'setting' => 'alert_min_order_value',
            'metric' => GrossMerchandiseValue::class,
            'label' => 'Order value',
        ],
    ];

    /** How many days a floor is measured over, when the merchant has not said otherwise. */
    public const WINDOW_SETTING = 'alert_window_days';

    /**
     * The `store_notifications.reference_type` prefix an alert is filed under.
     *
     * A PREFIX AND NOT A BARE METRIC KEY, so the pair (reference_type, reference_id) cannot collide
     * with another feature's rows in the same indexed columns. 50 characters is the column's width
     * and `MetricThresholdTest` asserts every METRICS key still fits inside it with this prefix --
     * a longer metric name would silently truncate, and a truncated dedup key deduplicates the
     * wrong things together.
     */
    public const REFERENCE_PREFIX = 'metric_threshold:';

    /**
     * The merchant's configured floors for one store.
     *
     * @return array<string, array{metric: string, label: string, floor: float, days: int}>
     */
    public static function configuredFor(int $storeId): array
    {
        $rows = Setting::where('store_id', $storeId)
            ->whereIn('name', array_merge(
                array_column(self::METRICS, 'setting'),
                [self::WINDOW_SETTING]
            ))
            ->pluck('value', 'name')
            ->all();

        /*
         * THE WINDOW IS THE MERCHANT'S TOO, and it is only read when they have set a floor at all --
         * a window with nothing to measure is not a configuration, it is a stray row. 7 is used when
         * a floor exists and no window was given, which is a UNIT choice rather than a policy one:
         * the figure has to cover some span, and the form offers the span explicitly.
         */
        $days = self::positiveInt($rows[self::WINDOW_SETTING] ?? null) ?? 7;

        $configured = [];

        foreach (self::METRICS as $key => $spec) {
            $floor = self::nonNegativeFloat($rows[$spec['setting']] ?? null);

            if ($floor === null) {
                // Not configured. NOT a floor of zero: a merchant who set 0 means "tell me if I take
                // nothing", and one who set nothing means "do not watch this". Those differ.
                continue;
            }

            $configured[$key] = [
                'metric' => $spec['metric'],
                'label' => $spec['label'],
                'floor' => $floor,
                'days' => $days,
            ];
        }

        return $configured;
    }

    /**
     * The stored values exactly as a merchant last typed them, for the configuration FORM.
     *
     * WHY THIS IS NOT `configuredFor()`. That method answers "what is being watched" and therefore
     * DROPS a metric with no floor -- which is right for the checker and wrong for the form, where a
     * dropped key would render as an empty field indistinguishable from one the merchant cleared on
     * purpose... and would then be re-saved as empty, which is the same thing. The real difference
     * is a value this class REFUSES on read: a negative number or a 400-day window is stored but
     * never compared, and `configuredFor()` would show the merchant an empty field while the row
     * still holds their typo. The form must show what is there so they can see and correct it.
     *
     * Every key is present and every value is a string, so the view needs no null handling.
     *
     * @return array<string, string>
     */
    public static function rawFor(int $storeId): array
    {
        $rows = Setting::where('store_id', $storeId)
            ->whereIn('name', array_merge(
                array_column(self::METRICS, 'setting'),
                [self::WINDOW_SETTING]
            ))
            ->pluck('value', 'name')
            ->all();

        $raw = [];

        foreach (self::METRICS as $key => $spec) {
            $raw[$key] = (string) ($rows[$spec['setting']] ?? '');
        }

        $raw['window_days'] = (string) ($rows[self::WINDOW_SETTING] ?? '');

        return $raw;
    }

    /**
     * Which of a store's configured floors the last `days` have fallen below.
     *
     * @return array<int, array{key: string, label: string, floor: float, actual: float, days: int,
     *                          currency: string, period_type: string, period_day: int,
     *                          period_key: string}>
     */
    public static function breachesFor(int $storeId, string $storeCurrency, ?int $now = null): array
    {
        $configured = self::configuredFor($storeId);

        if ($configured === []) {
            return [];
        }

        $scope = MetricScope::store($storeId);
        $breaches = [];

        foreach ($configured as $key => $spec) {
            $window = self::window($spec['days'], $now);

            /** @var \App\Support\Metrics\Metric $metric */
            $metric = new $spec['metric'];
            $result = $metric->for($scope, $window)->assumingUnknownCurrencyIs($storeCurrency);

            /*
             * A MIXED-CURRENCY FIGURE IS NOT COMPARED, because a floor is denominated in one
             * currency and there is no rate here to convert with -- the same refusal the tiles make.
             * Skipping is the honest outcome: a false alert about money is worse than none.
             */
            if (count($result->byCurrency()) > 1) {
                continue;
            }

            $actual = $result->total();

            if ($actual >= $spec['floor']) {
                continue;
            }

            $breaches[] = [
                'key' => $key,
                'label' => $spec['label'],
                'floor' => $spec['floor'],
                'actual' => $actual,
                'days' => $spec['days'],
                'currency' => $result->currency() ?? $storeCurrency,
                /*
                 * THE DEDUPLICATION IDENTITY, IN TWO PARTS BECAUSE THE COLUMNS IT LANDS IN HAVE
                 * TWO TYPES. `store_notifications.reference_type` is a `string(50)` and
                 * `reference_id` is an `unsignedBigInteger`, with a composite index over the pair.
                 *
                 * An earlier version of this put `payments_received:2026-06-15` into
                 * `reference_id`. SQLite's type affinity stores that verbatim and the suite was
                 * green; a real MySQL would NOT. Non-strict (`config/database.php`'s `mysql`
                 * connection) coerces the string to 0, so the FIRST alert a store ever raised
                 * would then match every later one and suppress it -- one notice per store, ever,
                 * directly contradicting what the screen promises the merchant. Strict (`mariadb`)
                 * raises 1366, the writer catches it, and NO alert is ever written. That is the
                 * P01-SEC-13 shape exactly (`customers.password_otp` INTEGER receiving a 60-char
                 * bcrypt hash), found by review rather than by the suite, which cannot see it.
                 *
                 * So the metric goes in the string column and the window's end goes in the integer
                 * one as `Ymd` -- a real integer, which the index can use and which orders by date.
                 */
                'period_type' => self::REFERENCE_PREFIX.$key,
                'period_day' => self::periodDay($window),
                // Kept for the `data` payload and for log messages: it is the identity in one
                // readable piece, and it is NOT what the dedup columns are keyed on.
                'period_key' => $key.':'.$window->to()?->toDateString(),
            ];
        }

        return $breaches;
    }

    /**
     * The window's end day as `Ymd` -- the integer half of the dedup identity.
     *
     * IT THROWS RATHER THAN DEFAULTING TO 0, and 0 is precisely the reason. `MetricWindow::to()` is
     * nullable, so the obvious spelling is `(int) $window->to()?->format('Ymd')`, which answers 0
     * for an unbounded window. 0 is the value a non-strict MySQL wrote when this column was given a
     * string, and it is the value every row would then share -- one notice per store for ever. A
     * floor measured over an unbounded window has no period to deduplicate on, so the honest
     * outcome is a loud failure rather than a key that silently collides.
     *
     * `self::window()` always builds a bounded window, so this cannot fire today; it exists so that
     * a later change to `window()` cannot reintroduce the collision quietly.
     */
    private static function periodDay(MetricWindow $window): int
    {
        $end = $window->to();

        if ($end === null) {
            throw new \RuntimeException(
                'A metric threshold cannot be measured over an unbounded window: there is no period '
                .'to deduplicate its alert on.'
            );
        }

        return (int) $end->format('Ymd');
    }

    /** The last `$days` ending today, inclusive of both ends. */
    private static function window(int $days, ?int $now): MetricWindow
    {
        $end = \Carbon\Carbon::createFromTimestamp($now ?? time())->startOfDay();

        return MetricWindow::between($end->copy()->subDays($days - 1), $end);
    }

    /** A floor may be 0 -- "tell me if I take nothing" -- but never negative and never blank. */
    private static function nonNegativeFloat($value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $floor = (float) $value;

        return $floor < 0.0 ? null : $floor;
    }

    private static function positiveInt($value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $days = (int) $value;

        // An upper bound as well: a 10,000-day window is not a configuration, it is a typo, and the
        // metric layer would happily scan it.
        return ($days >= 1 && $days <= 365) ? $days : null;
    }
}
