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

        return [
            'pulled' => $pulled,
            'applied' => $applied,
            'pruned' => $this->prune($link, $remote),
            'drifted' => $this->drift($link),
        ];
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
     * Projected NATIVE rows for scopes Lynomia no longer holds a decision about.
     *
     * A DECISION CAN BE REMOVED, NOT JUST CHANGED, and nothing told this side when it was.
     * `Commerce::MessagingConsents.clear_suppression` -- an operator clearing a delivery
     * fault once the number works again -- DESTROYS the row rather than writing a new state,
     * so there is nothing for the event feed to publish and nothing for the pull to apply.
     * The projection kept the suppression, and the merchant's consent screen went on saying
     * "will not be sent" about a customer Lynomia would message perfectly happily. The
     * screen was wrong in the direction that makes a merchant stop trying.
     *
     * SO ABSENCE IS THE SIGNAL, and this is the only place it can be read: the event feed
     * carries states, and "no longer any state" is not one.
     *
     * ONLY WHEN THE LIST IS COMPLETE, WHICH THE ENDPOINT NOW SAYS. The read is CAPPED at 200
     * rows, newest first -- it is the safety net behind the event feed, not a full export.
     * Deleting on absence from a capped list would delete every scope past the 200th, every
     * hour, including withdrawals: the merchant's screen would quietly stop showing that an
     * older customer had opted out. So `native_complete` is required, and a store with more
     * decisions than the cap simply does not get this repair -- which is a gap in a
     * convenience, not a wrong answer. (Lynomia remains the authority on whether a message
     * may go; this table is what the MERCHANT is shown.)
     *
     * A RESPONSE WITHOUT THE FLAG PRUNES NOTHING, because an older Lynomia that does not
     * send it is exactly the case where this side cannot tell the two situations apart.
     *
     * NATIVE ONLY. A partner row is this installation's own record of what it captured, and
     * its absence upstream would mean the decision never arrived -- which is the outbox's
     * problem to retry, not a reason to forget what the shopper told this store.
     */
    private function prune(LynomiaStoreLink $link, array $remote): int
    {
        if (($remote['native_complete'] ?? false) !== true) {
            return 0;
        }

        $known = [];
        foreach ($remote['native'] ?? [] as $row) {
            $known[] = ($row['external_subject_id'] ?? '') . '|' . ($row['purpose'] ?? '') . '|' . ($row['channel'] ?? '');
        }

        $stale = LynomiaConsentState::forStore($link->store_id)
            ->where('origin', 'lynomia_native')
            ->get()
            ->reject(fn (LynomiaConsentState $row) => in_array(
                $row->external_subject_id . '|' . $row->purpose . '|' . $row->channel,
                $known,
                true
            ));

        $stale->each(fn (LynomiaConsentState $row) => $row->delete());

        return $stale->count();
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
