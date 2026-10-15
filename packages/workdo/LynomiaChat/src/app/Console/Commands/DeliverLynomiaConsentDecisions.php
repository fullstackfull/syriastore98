<?php

namespace Workdo\LynomiaChat\app\Console\Commands;

use Illuminate\Console\Command;
use Workdo\LynomiaChat\app\Models\LynomiaConsentDecision;
use Workdo\LynomiaChat\app\Services\ConsentDecisionDispatcher;
use Workdo\LynomiaChat\app\Services\PlatformApiClient;

/**
 * Drains the consent outbox.
 *
 * THE SAME SHAPE AS DeliverLynomiaCommerceEvents, deliberately -- a scheduled command rather
 * than a queued job, because this module dispatches no jobs and an installation may have no
 * worker running at all, and not inline either, because an HTTP call inside the transaction
 * that saved an order is the defect the retired Chatwoot integration shipped.
 *
 * WHAT IS DIFFERENT, AND IT IS THE RETENTION. Commerce events are pruned once delivered: they
 * exist to invalidate a cache and are worth nothing afterwards. A consent decision is
 * EVIDENCE -- it is the answer to "when did this customer agree, what were they shown, and
 * what did Lynomia do with it" -- so nothing here prunes anything, ever. The table grows with
 * the number of decisions customers actually make, which is small, and a merchant who cannot
 * produce the record of a grant has no defence for having sent the message.
 *
 * BOUNDED, so one store's backlog cannot hold the scheduler, and oldest first, which is also
 * the order the decisions were made in. A store that is failing backs its own rows off
 * through the dispatcher's stored backoff and stops competing for the batch within a couple
 * of passes.
 */
class DeliverLynomiaConsentDecisions extends Command
{
    protected $signature = 'lynomia:deliver-consent-decisions
                            {--limit=200 : deliver at most this many decisions in one pass}';

    protected $description = 'Deliver recorded Store Syria consent decisions to Lynomia.';

    public function handle(): int
    {
        if (! config('lynomia.enabled')) {
            $this->info('The Lynomia integration is switched off; nothing was delivered.');

            return self::SUCCESS;
        }

        if (blank(config('lynomia.base_url'))) {
            $this->error('LYNOMIA_BASE_URL is not configured; nothing was delivered and nothing was lost.');

            return self::FAILURE;
        }

        $dispatcher = new ConsentDecisionDispatcher(app(PlatformApiClient::class));
        $limit = max(1, (int) $this->option('limit'));
        $settled = 0;
        $deferred = 0;

        LynomiaConsentDecision::pending()
            ->orderBy('id')
            ->limit($limit)
            ->get()
            // Counted off the ROW rather than off the return value: `deliver` answers "does
            // Lynomia now hold this", which is false both for a row deferred to the next pass
            // and for one retired because the link is gone or the payload was rejected. Those
            // are opposite situations and a count that merged them would say a backlog is
            // growing when it is in fact settled.
            ->each(function (LynomiaConsentDecision $decision) use ($dispatcher, &$settled, &$deferred) {
                $dispatcher->deliver($decision);
                $decision->delivered_at === null ? $deferred++ : $settled++;
            });

        $this->info("Settled {$settled}, deferred {$deferred}.");

        return self::SUCCESS;
    }
}
