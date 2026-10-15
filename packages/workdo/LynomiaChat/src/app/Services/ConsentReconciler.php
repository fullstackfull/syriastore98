<?php

namespace Workdo\LynomiaChat\app\Services;

use Workdo\LynomiaChat\app\Models\LynomiaConsentDecision;
use Workdo\LynomiaChat\app\Models\LynomiaConsentState;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Support\StoreIdentity;

/**
 * Making this side's picture of Lynomia's consent state true again.
 *
 * WHY A SWEEP EXISTS AT ALL, when there is an event feed. The feed is at-least-once over a
 * network and the receiver can be down, rate-limited or mid-deploy when a delivery is
 * attempted; Lynomia's own webhook sender swallows a failed delivery rather than retrying it
 * (custom/app/services/custom/webhooks/trigger.rb documents that deliberately). So a lost
 * delivery is silent and permanent, and the thing it loses is an opt-out. A periodic pull is
 * the only thing that notices.
 *
 * IT PULLS, IT DOES NOT PUSH BACK. Store Syria is authoritative for what a customer told
 * THIS store and Lynomia is authoritative for whether a message is sent
 * (docs/partner-consent/AUTHORITY.md §1). A sweep that re-sent local decisions because the
 * remote disagreed would be this side overruling the other's refusal -- which is precisely
 * the "two competing authorities" failure the authority document exists to prevent. Undelivered
 * decisions are the outbox's job and have their own retry ladder; this one only reads.
 *
 * THE PROJECTOR DECIDES WHAT LANDS, not this. Every row goes through
 * ConsentStateProjector::apply, so a pulled row that is older than what this side already
 * holds for that origin is dropped exactly as a late webhook would be. That is what makes
 * running the sweep safe at any moment, including while deliveries are in flight: it can
 * only ever move a scope forward.
 *
 * DRIFT IS REPORTED, NEVER REPAIRED BY GUESSING. When the local newest decision says granted
 * and Lynomia says withdrawn, that is not a bug to paper over -- it is usually Lynomia being
 * right, because a shopper opted out on a channel this side never saw. The count is surfaced
 * so an operator can look, and the merchant screen shows both answers side by side.
 */
class ConsentReconciler
{
    public function __construct(private readonly PlatformApiClient $client, private readonly ConsentStateProjector $projector)
    {
    }

    /**
     * @return array{pulled: int, applied: int, drifted: int}
     */
    public function reconcile(LynomiaStoreLink $link): array
    {
        $remote = $this->client->partnerConsents(
            (int) $link->lynomia_account_id,
            StoreIdentity::of((int) $link->store_id)
        );

        $applied = 0;
        $pulled = 0;

        foreach ($this->events($remote) as $event) {
            $pulled++;
            $applied += $this->projector->apply($link, $event) ? 1 : 0;
        }

        return ['pulled' => $pulled, 'applied' => $applied, 'drifted' => $this->drift($link)];
    }

    /**
     * The two collections, each rewritten into the shape the projector already speaks -- the
     * one the event feed sends. One shape means one place decides ordering and scope, rather
     * than a pull path that can drift from the push path it is meant to repair.
     *
     * `origin` is set here because the read API returns the two collections separately
     * instead of labelling each row, and the origin is what keeps the two revision sequences
     * from being compared against each other.
     *
     * @return iterable<array>
     */
    private function events(array $remote): iterable
    {
        foreach ($remote['consents'] ?? [] as $row) {
            yield $this->event($row, 'partner', $row['captured_at'] ?? null);
        }

        foreach ($remote['native'] ?? [] as $row) {
            // 'lynomia_native' rather than 'native', because that is the literal the event
            // feed publishes and a pull that used a different word would write a SECOND row
            // for the same scope and then compare two sequences under one origin again.
            yield $this->event($row, 'lynomia_native', $row['decided_at'] ?? null);
        }
    }

    private function event(array $row, string $origin, $occurredAt): array
    {
        return [
            'origin' => $origin,
            'subject' => ['external_customer_id' => (string) ($row['external_subject_id'] ?? '')],
            'purpose' => (string) ($row['purpose'] ?? ''),
            'channel' => (string) ($row['channel'] ?? ''),
            'state' => (string) ($row['state'] ?? ''),
            'source' => $row['source'] ?? null,
            'revision' => $row['revision'] ?? null,
            'occurred_at' => is_string($occurredAt) ? $occurredAt : null,
        ];
    }

    /**
     * Scopes where this store's newest decision and Lynomia's effective answer disagree.
     *
     * COMPARED AS "DOES ANYTHING BLOCK", not state by state. A local `granted` against a
     * remote `suppressed` is drift; a local `granted` against a remote `granted` recorded
     * from a different source is not, and reporting it would bury the real ones.
     *
     * THE REMOTE ANSWER IS THE UNION ACROSS ORIGINS AND CHANNELS, because that is what the
     * sender will actually do. One row is never the answer: a partner grant sitting beside a
     * native withdrawal would otherwise read as agreement.
     *
     * TWO QUERIES, NOT TWO PER SCOPE. Both sides are loaded once and matched in memory; a
     * per-scope lookup would issue a query per customer who has ever decided anything, which
     * on a scheduled sweep across every store is the difference between a second and an hour.
     */
    private function drift(LynomiaStoreLink $link): int
    {
        $remote = LynomiaConsentState::forStore($link->store_id)->get()
            ->groupBy(fn (LynomiaConsentState $row) => $row->external_subject_id . '|' . $row->purpose);

        $drifted = 0;

        foreach (LynomiaConsentDecision::latestPerScope($link->store_id)->get() as $decision) {
            $rows = $remote->get($decision->external_subject_id . '|' . $decision->purpose);

            // Nothing known remotely yet -- a decision still in the outbox, or one Lynomia
            // has never been asked about. Not drift; the outbox owns that.
            if ($rows === null) {
                continue;
            }

            $localBlocks = $decision->state !== 'granted';
            $remoteBlocks = in_array(
                LynomiaConsentState::effectiveFor($rows)['state'],
                LynomiaConsentState::BLOCKING,
                true
            );

            if ($localBlocks !== $remoteBlocks) {
                $drifted++;
            }
        }

        return $drifted;
    }
}
