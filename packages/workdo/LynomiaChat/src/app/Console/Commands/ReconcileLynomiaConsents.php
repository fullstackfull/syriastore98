<?php

namespace Workdo\LynomiaChat\app\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\ConsentReconciler;
use Workdo\LynomiaChat\app\Support\LinkState;

/**
 * The safety net behind the consent event feed.
 *
 * A LOST DELIVERY IS SILENT AND PERMANENT. Lynomia's webhook sender swallows a failed
 * delivery rather than retrying it, so an opt-out that arrives while this side is deploying,
 * rate-limited or simply down is gone -- and the fact it loses is the one that matters most.
 * Nothing in the push path notices; this is what notices.
 *
 * EVERY ACTIVE LINK, ONE AT A TIME, AND ONE STORE'S FAILURE IS ITS OWN. A merchant whose
 * workspace is unreachable must not stop the sweep reaching the next merchant, so each store
 * is wrapped and reported rather than allowed to abort the run.
 *
 * READ-ONLY TOWARDS LYNOMIA. It pulls and projects; it never re-sends a local decision to
 * force agreement. Undelivered decisions belong to the outbox and its own backoff ladder,
 * and a sweep that pushed would be this side overruling Lynomia's refusals -- the competing
 * authorities failure docs/partner-consent/AUTHORITY.md exists to prevent.
 *
 * HOURLY, not every minute. This repairs rare losses; the feed is the live path. An hourly
 * read per connected store is a cost a platform can carry at any number of merchants.
 */
class ReconcileLynomiaConsents extends Command
{
    protected $signature = 'lynomia:reconcile-consents
                            {--store= : reconcile one store instead of every connected one}';

    protected $description = 'Pull Lynomia consent state for connected stores and repair this side\'s view of it.';

    public function handle(ConsentReconciler $reconciler): int
    {
        if (! config('lynomia.enabled')) {
            $this->info('The Lynomia integration is switched off; nothing was reconciled.');

            return self::SUCCESS;
        }

        if (blank(config('lynomia.base_url'))) {
            $this->error('LYNOMIA_BASE_URL is not configured; nothing was reconciled.');

            return self::FAILURE;
        }

        $links = LynomiaStoreLink::query()
            ->where('state', LinkState::ACTIVE)
            ->whereNotNull('lynomia_account_id')
            ->when($this->option('store'), fn ($query, $store) => $query->where('store_id', (int) $store))
            ->orderBy('store_id')
            ->get();

        $totals = ['pulled' => 0, 'applied' => 0, 'drifted' => 0, 'unreachable' => 0];

        foreach ($links as $link) {
            try {
                $result = $reconciler->reconcile($link);
            } catch (ConnectionException | LynomiaApiException $e) {
                // Named, not swallowed: a store that cannot be reached for a whole day is the
                // thing an operator needs to see, and the next pass will try it again.
                $totals['unreachable']++;
                $this->warn("store {$link->store_id}: " . $e::class);

                continue;
            }

            $totals['pulled'] += $result['pulled'];
            $totals['applied'] += $result['applied'];
            $totals['drifted'] += $result['drifted'];
        }

        $this->info(sprintf(
            'Stores %d, pulled %d, applied %d, drifted %d, unreachable %d.',
            $links->count(),
            $totals['pulled'],
            $totals['applied'],
            $totals['drifted'],
            $totals['unreachable']
        ));

        return self::SUCCESS;
    }
}
