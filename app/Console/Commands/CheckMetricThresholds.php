<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\Store;
use App\Services\StoreNotificationService;
use App\Support\Analytics\MetricThreshold;
use Illuminate\Console\Command;

/**
 * ANA-009 -- check each store's merchant-set metric floors and record the breaches.
 *
 * THE COMMAND HOLDS NO POLICY. It asks `MetricThreshold` which stores have configured a floor, asks
 * it which of those floors the window has fallen below, and hands each breach to the existing
 * `StoreNotificationService`. No threshold, no default and no notion of a "drop" lives here.
 *
 * IT VISITS ONLY STORES THAT HAVE CONFIGURED SOMETHING, and that is a correctness property rather
 * than an optimisation: a store with no floor has nothing to be below, so iterating every store
 * would be a long way to do nothing and would make the log unreadable on a platform of thousands.
 * The candidate set comes from `settings`, which is where the merchant's answer lives.
 *
 * HOURLY IS SAFE BECAUSE THE ALERT IS DEDUPLICATED ON THE PERIOD, not on the run. Twenty-four ticks
 * over one breached day write ONE notice: `StoreNotificationService::metricThresholdBreached()`
 * keys on the metric plus the window's end date. That is the same shape ANA-003's runner uses, and
 * for the same reason -- the tick rate should bound how LATE an alert is, never how MANY there are.
 */
class CheckMetricThresholds extends Command
{
    protected $signature = 'analytics:check-metric-thresholds';

    protected $description = 'Record an alert for each store whose metric has fallen below the floor its merchant set (ANA-009).';

    public function handle(StoreNotificationService $notifications): int
    {
        $settingNames = array_merge(
            array_column(MetricThreshold::METRICS, 'setting'),
            [MetricThreshold::WINDOW_SETTING]
        );

        /*
         * The stores a merchant has configured a floor on. `whereNotNull` AND `!= ''`: a settings row
         * written by a form the merchant cleared carries an empty string, not a null, and an empty
         * string is "not configured" rather than "zero" -- `MetricThreshold` makes the same
         * distinction and the candidate query must not disagree with it.
         */
        $storeIds = Setting::whereIn('name', $settingNames)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->distinct()
            ->pluck('store_id')
            ->filter(static fn ($id): bool => (int) $id > 0)
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($storeIds === []) {
            $this->info('No store has configured a metric floor, so there is nothing to check.');

            return self::SUCCESS;
        }

        $checked = 0;
        $recorded = 0;

        foreach ($storeIds as $storeId) {
            $currency = Setting::where('store_id', $storeId)
                ->where('name', 'defult_currancy')
                ->value('value') ?? 'USD';

            // A store row may be gone while its settings linger; a breach for a deleted store has
            // no owner to tell and `metricThresholdBreached()` would return null anyway.
            if (! Store::where('id', $storeId)->exists()) {
                continue;
            }

            $checked++;

            foreach (MetricThreshold::breachesFor($storeId, (string) $currency) as $breach) {
                $notice = $notifications->metricThresholdBreached($storeId, $breach);

                // `wasRecentlyCreated` distinguishes a NEW notice from the deduplicated return of an
                // existing one, so the count reports alerts raised rather than breaches seen.
                if ($notice !== null && $notice->wasRecentlyCreated) {
                    $recorded++;
                }
            }
        }

        $this->info(sprintf('Checked %d store(s) with a configured floor; recorded %d new alert(s).', $checked, $recorded));

        return self::SUCCESS;
    }
}
