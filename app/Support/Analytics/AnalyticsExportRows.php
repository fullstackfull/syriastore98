<?php

namespace App\Support\Analytics;

use App\Support\Metrics\FigureCsv;

/**
 * ANA-003 — THE ANALYTICS EXPORT'S ROW SET, in ONE place, because it now has two callers.
 * =================================================================================================
 *
 * `MerchantAnalyticsController::export()` built these rows inline. ANA-003's scheduled export needs
 * the same file, and copying the row set would have created a SECOND DEFINITION of what the export
 * contains -- so the download a merchant clicks and the file that arrives on a schedule could drift
 * apart in a later edit of one of them. That is the same class of defect as a figure and its caveat
 * living in different files, which is what `FigureCsv` and `ReportFigureNote` exist to prevent.
 *
 * THE LABELS ARE THE ONLY STRINGS CHOSEN HERE, and they are deliberately the same strings
 * `analytics/index.blade.php` puts on its tiles -- a test asserts the file's row set matches the
 * screen's tile set, so the two cannot drift. Everything else (value, period, disclosure) comes
 * from the figures.
 *
 * NOTHING HERE COMPUTES OR RE-READS. It is handed the array the Blade is handed. A second query
 * here would be a second definition of every number in the file.
 */
final class AnalyticsExportRows
{
    /**
     * The tile labels, in the order the file lists them. Shared with the screen's own tile set.
     *
     * @return array<string, string>
     */
    public static function figureLabels(): array
    {
        return [
            'orders' => __('Orders'),
            'visits' => __('Page views'),
            'conversion' => __('Conversion'),
            'customers' => __('Buyers'),
            'retention' => __('Repeat purchase'),
            'payments' => __('Payments received'),
            'profit' => __('Estimated gross profit'),
            'redemptions' => __('Coupon discounts given'),
        ];
    }

    /** The breakdowns, appended after the single figures so the file stays readable. */
    public static function breakdownLabels(): array
    {
        return [
            'devices' => __('Devices'),
            'channels' => __('Referring pages'),
            'sources' => __('Orders by channel'),
        ];
    }

    /**
     * @param  array<string, mixed>  $figures  exactly what the Blade is handed
     * @return list<list<string>>  header row first
     */
    public static function build(array $figures): array
    {
        $rows = FigureCsv::rows($figures, self::figureLabels());

        /*
         * The THREE breakdowns are rows, not tiles, so they go through `breakdownRows()` -- same four
         * columns, every row carrying its own period and disclosure. Each is prefixed with its own
         * name so two lists in one download stay readable.
         */
        foreach (self::breakdownLabels() as $key => $label) {
            if (isset($figures[$key])) {
                $rows = array_merge($rows, FigureCsv::breakdownRows($figures[$key], $label));
            }
        }

        /*
         * AND THE CLAMP TRAVELS WITH THE FILE. When a requested period was shortened or moved, the
         * screen says so above the figures; a file that dropped that sentence would be a figure
         * separated from its own limits. It is a ROW, in the same four columns, because a header or
         * a trailing comment survives neither sorting nor copying a range.
         */
        if (! empty($figures['period']['clamped'])) {
            $rows[] = [
                __('Period'),
                (string) ($figures['period']['label'] ?? ''),
                (string) ($figures['period']['label'] ?? ''),
                (string) $figures['period']['clamped'],
            ];
        }

        return $rows;
    }
}
