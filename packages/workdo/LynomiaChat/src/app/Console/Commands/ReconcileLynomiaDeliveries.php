<?php

namespace Workdo\LynomiaChat\app\Console\Commands;

use Illuminate\Console\Command;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Services\PlatformDeliveryReconciler;
use Workdo\LynomiaChat\app\Services\PlatformWorkspaceGateway;

/**
 * The safety net behind the platform delivery feed.
 *
 * A WEBHOOK CAN BE LOST IN EVERY WAY A REQUEST CAN BE LOST: Lynomia enqueues the delivery and
 * the worker dies, it arrives while this side is redeploying, the signature clock has drifted
 * past tolerance, the body exceeded the cap. Each of those leaves a notice sitting at
 * `accepted` for ever -- which is indistinguishable from one that genuinely has not resolved
 * yet, and `accepted` meaning nothing is precisely what this phase set out to fix.
 *
 * SO THIS ASKS. It reads the message back and writes the answer through the same projector the
 * feed uses, with a `reconcile-` delivery id, so the ordering rule and the audit trail apply
 * identically and the two sources cannot disagree.
 *
 * EVERY FIFTEEN MINUTES, AND ONLY FOR NOTICES OLDER THAN THE GRACE WINDOW. This repairs rare
 * losses; the feed carries the live traffic. Asking sooner would mean racing Lynomia's own
 * webhook and recording whichever answer happened to be slower.
 */
class ReconcileLynomiaDeliveries extends Command
{
    protected $signature = 'lynomia:reconcile-deliveries
                            {--limit=50 : how many unresolved notices to ask about in one pass}';

    protected $description = 'Ask Lynomia what became of platform notices whose delivery events never arrived.';

    public function handle(PlatformDeliveryReconciler $reconciler, PlatformWorkspaceGateway $workspace): int
    {
        if (! config('lynomia.enabled')) {
            $this->info('The Lynomia integration is switched off; nothing was reconciled.');

            return self::SUCCESS;
        }

        // Not a failure. An installation that has not provisioned a platform workspace has
        // sent no notices, so there is nothing whose outcome could be outstanding.
        if (! $workspace->configured()) {
            $this->info('Platform communications are not set up; nothing was reconciled.');

            return self::SUCCESS;
        }

        // SELF-HEALING, AND THE REASON IT IS HERE. Lynomia posts an account webhook only to an
        // endpoint somebody registered for the event, and nothing in the merchant path can do
        // that for the platform workspace -- it takes a store link and this account has none.
        // Doing it on the same cadence as the sweep means an operator who deletes the webhook,
        // or restores a workspace from before the feed existed, gets it back without knowing
        // to ask: one list call on one account every quarter of an hour.
        try {
            if ($workspace->ensureDeliveryFeedSubscribed()) {
                $this->info('Registered the delivery feed webhook on the platform workspace.');
            }
        } catch (LynomiaApiException $e) {
            // Not fatal to the sweep. Reconciliation is precisely what covers a feed that is
            // not arriving, so it must still run when the subscription could not be repaired.
            $this->warn('Could not check the delivery feed subscription: ' . $e->kind);
        }

        $result = $reconciler->run(max(1, (int) $this->option('limit')));

        $this->info(sprintf(
            'Checked %d, resolved %d, unreachable %d.',
            $result['checked'],
            $result['resolved'],
            $result['unreachable']
        ));

        return self::SUCCESS;
    }
}
