<?php

namespace Workdo\LynomiaChat\app\Console\Commands;

use Illuminate\Console\Command;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Services\PlatformApiClient;
use Workdo\LynomiaChat\app\Support\StoreIdentity;

/**
 * Makes this side's belief about each store's commerce installation match Lynomia's.
 *
 * THE DRIFT IT CLOSES. An installation's existence is a fact on the other system, and four
 * ordinary things move it without telling us: an operator disables or disconnects the store in
 * Lynomia, a credential is rejected unattended and the store drops to needs_reauth, an HTTP
 * response is lost after Lynomia has already committed, or a merchant abandons the page
 * mid-attempt. In every one of those the row here says something that was true and is not any
 * more -- and because the merchant's page is DERIVED from this row, a stale belief is a wrong
 * sentence shown to a merchant with the wrong button under it.
 *
 * IT RECORDS, IT DOES NOT INSTALL. This sweep never calls the installation endpoint, never mints
 * a credential and never rotates one. That is the single most important thing about it: a sweep
 * that could create an installation is a sweep that can re-create one somebody deliberately
 * disconnected, at 3am, with nobody watching -- and "never silently attach a merchant's store to
 * a workspace" is not a property you can keep while also having a background job that attaches
 * stores. Installing stays where consent for it exists: a merchant pressing a button, or an
 * operator acting deliberately.
 *
 * WHICH SIDE IS AUTHORITATIVE, FOR WHAT. For whether an installation EXISTS and what state it is
 * in: Lynomia, because it owns the row -- so this copies Lynomia's answer down. For whether it is
 * PERMITTED: this side, because it owns the link state and the credential -- so a link that is
 * not `allowsSso()` is skipped entirely rather than having its commerce belief refreshed. Facts
 * only ever move in the direction of the system that owns them.
 *
 * ONE GET PER STORE, from the same read-only endpoint the operator console already uses. Nothing
 * here calls a provider or writes anything upstream.
 */
class ReconcileLynomiaCommerce extends Command
{
    protected $signature = 'lynomia:reconcile-commerce
                            {--limit= : examine at most this many stores (default: every one)}
                            {--dry-run : report the drift and change nothing}';

    protected $description = "Refresh each store's recorded commerce installation state from Lynomia.";

    public function handle(PlatformApiClient $api): int
    {
        if (! config('lynomia.enabled')) {
            $this->info('Lynomia integration is disabled; nothing to reconcile.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $examined = 0;
        $repaired = 0;

        foreach ($this->candidates() as $link) {
            // RE-ASKED PER ROW, not filtered in SQL. allowsSso() is state + provisioned +
            // ownership-proven-where-claimed, and the last of those is not expressible as a
            // predicate the query could carry without restating the rule in a second place.
            if (! $link->allowsSso()) {
                continue;
            }

            // `StoreIdentity::of()` is a lazy WRITER: the first caller for a store persists the
            // composed identity, because that value is what Lynomia matches the store by forever
            // after. For a real sweep that is a legitimate first use. For `--dry-run` it was a
            // broken promise -- the flag says "change nothing" and it pinned the identity of every
            // provisioned link it looked at, before the HTTP call and so even for links it then
            // skipped. A dry run reads what is recorded and skips, loudly, what is not.
            $external = $dryRun
                ? StoreIdentity::known((int) $link->store_id)
                : StoreIdentity::of((int) $link->store_id);

            // SKIPPED, NOT TREATED AS "LYNOMIA HOLDS NOTHING". Without the continue this fell into
            // drift() with a null installation, which reports clearing a belief -- so a dry run
            // would have announced drift it had invented out of its own refusal to write.
            if ($external === null) {
                $this->warn("store {$link->store_id}: no commerce identity recorded yet -- skipped (a real run would record one)");

                continue;
            }

            $examined++;

            try {
                $installation = $this->installationFor($api, $link, $external);
            } catch (LynomiaApiException $e) {
                // An unreachable Lynomia is not drift. Leaving the belief alone is right: it was
                // the last thing Lynomia actually said, and replacing it with "we could not ask"
                // would turn an outage into a wrong answer on every merchant's page at once.
                $this->warn("store {$link->store_id}: could not ask Lynomia ({$e->kind})");

                continue;
            }

            $changes = $this->drift($link, $installation);

            if ($changes === []) {
                continue;
            }

            $repaired++;
            $this->line("store {$link->store_id}: " . json_encode($changes));

            if (! $dryRun) {
                $link->forceFill($changes)->save();
            }
        }

        $this->info($dryRun
            ? "Examined {$examined} stores; {$repaired} would be corrected."
            : "Examined {$examined} stores; corrected {$repaired}.");

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int,LynomiaStoreLink>
     */
    private function candidates()
    {
        $query = LynomiaStoreLink::query()
            ->whereNotNull('lynomia_account_id')
            ->orderBy('id');

        $limit = (int) $this->option('limit');

        return $limit > 0 ? $query->limit($limit)->get() : $query->get();
    }

    /**
     * This store's row in Lynomia's installation list, or null when it holds none.
     *
     * MATCHED ON PROVIDER AS WELL AS ON THE ID, because a merchant may legitimately have
     * connected a Shopify or Salla store to the same workspace, and matching on the external id
     * alone would read somebody else's installation as this one's.
     *
     * @return array<string,mixed>|null
     */
    private function installationFor(PlatformApiClient $api, LynomiaStoreLink $link, string $external): ?array
    {
        $health = $api->integrationHealth((int) $link->lynomia_account_id);

        foreach ($health['installations'] as $installation) {
            if (! is_array($installation)) {
                continue;
            }

            if (($installation['provider'] ?? null) === 'storesyria'
                && (string) ($installation['external_store_id'] ?? '') === $external) {
                return $installation;
            }
        }

        return null;
    }

    /**
     * What this row says that Lynomia does not, expressed as the columns that need changing.
     *
     * AN EMPTY RESULT IS THE COMMON CASE and costs one write of nothing. Returning the diff
     * rather than applying it is what makes --dry-run honest: the reported change and the applied
     * change are computed once, by the same code.
     *
     * @return array<string,mixed>
     */
    private function drift(LynomiaStoreLink $link, ?array $installation): array
    {
        if ($installation === null) {
            // Lynomia holds nothing for this store. If we thought it did, that belief is what is
            // wrong -- and it is cleared rather than turned into an error, because "there is no
            // installation" is a state with a button under it, while an error is a dead end.
            return $link->lynomia_commerce_store_id === null && $link->commerce_remote_status === null
                ? []
                : [
                    'lynomia_commerce_store_id' => null,
                    'commerce_remote_status' => null,
                    'commerce_installed_at' => null,
                    'commerce_verified_at' => now(),
                    'commerce_last_error' => null,
                ];
        }

        $status = (string) ($installation['status'] ?? 'unknown');
        $id = is_numeric($installation['id'] ?? null) ? (int) $installation['id'] : null;

        $changes = [];

        if ($id !== null && (int) $link->lynomia_commerce_store_id !== $id) {
            $changes['lynomia_commerce_store_id'] = $id;
        }

        if ((string) $link->commerce_remote_status !== $status) {
            $changes['commerce_remote_status'] = $status;
        }

        if ($link->commerce_installed_at === null) {
            // Seen for the first time by the sweep rather than by an activation -- which is what
            // happens to every store connected manually before automatic activation existed,
            // including the one already in production. Recorded as "since at least now", because
            // when it was really installed is not something this side can know.
            $changes['commerce_installed_at'] = now();
        }

        // An installation Lynomia confirms is not a failed one, whatever the last attempt here
        // recorded. Clearing the stale reason is most of the point of the sweep: without it a
        // merchant whose first attempt timed out after Lynomia had already committed reads
        // "not connected" over a working connection, forever.
        if ($link->commerce_last_error !== null) {
            $changes['commerce_last_error'] = null;
        }

        return $changes === [] ? [] : $changes + ['commerce_verified_at' => now()];
    }
}
