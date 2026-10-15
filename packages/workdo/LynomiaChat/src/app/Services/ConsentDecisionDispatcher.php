<?php

namespace Workdo\LynomiaChat\app\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Models\LynomiaConsentDecision;
use Workdo\LynomiaChat\app\Models\LynomiaStoreLink;
use Workdo\LynomiaChat\app\Support\LinkState;

/**
 * Tells Lynomia what a customer told this store.
 *
 * THE SAME SHAPE AS CommerceEventDispatcher, deliberately. A consent decision is a durable
 * obligation to tell the other side, which is the problem a commerce event already has: stored
 * backoff in the row rather than held in a worker, a bounded retry ladder, an error string that
 * never carries a URL, and a store that is no longer connected retiring the row instead of
 * retrying it forever. A second delivery mechanism would be a second set of those decisions to
 * get right.
 *
 * WHAT IS DIFFERENT, AND WHY IT MATTERS MORE HERE:
 *
 *   A REFUSAL IS AN ANSWER, NOT A FAILURE. Lynomia refuses a plain grant that arrives after a
 *   withdrawal, and that refusal is correct and permanent. The row is marked delivered with
 *   `remote_outcome` recorded and is never retried -- retrying would be asking the same
 *   question until the answer changed, and it will not. Treating it as a transport failure
 *   would also hide the one fact the merchant needs: their opt-in was not accepted.
 *
 *   THE DECISION REF IS NEVER REGENERATED. It is minted when the decision is recorded, so a
 *   retry, a redelivery after a crash and a reconciliation replay all carry the same one.
 *   Lynomia's UNIQUE (commerce_store_id, decision_ref) turns every repeat into a no-op, which
 *   is what makes at-least-once delivery safe for something that must not be applied twice.
 *
 *   IT GOES OVER THE PLATFORM CREDENTIAL, not a merchant's. Consent sync is system to system:
 *   it happens when no one is signed in, it is not an action a person took in a browser, and
 *   binding it to a person would make it fail the moment that person left the company.
 */
class ConsentDecisionDispatcher
{
    /** Seconds. The last value repeats; a decision is never abandoned while the store is live. */
    private const BACKOFF = [30, 120, 600, 1800, 3600];

    /** Outcomes Lynomia reports that are final. None of them is retried. */
    private const SETTLED = ['applied', 'duplicate', 'superseded', 'refused_withdrawal_dominant'];

    public function __construct(private readonly PlatformApiClient $client)
    {
    }

    /** @return bool true when Lynomia has an answer for this decision, refusal included. */
    public function deliver(LynomiaConsentDecision $decision): bool
    {
        if ($decision->delivered_at !== null) {
            return true;
        }

        $link = LynomiaStoreLink::forStore($decision->store_id)->first();

        if ($link === null || $link->state !== LinkState::ACTIVE || $link->lynomia_account_id === null) {
            // Nowhere to deliver this and there never will be. Retired rather than retried.
            $decision->forceFill(['delivered_at' => now(), 'last_error' => 'link_not_active'])->save();

            return false;
        }

        try {
            $outcome = $this->client->recordPartnerConsent((int) $link->lynomia_account_id, $this->body($decision));
        } catch (ConnectionException) {
            return $this->defer($decision, 'unreachable');
        } catch (LynomiaApiException $e) {
            return $this->handleApiError($decision, $e);
        }

        return $this->settle($decision, $outcome);
    }

    /**
     * WHAT TRAVELS. The store's own identifiers for itself and for its customer, the decision,
     * and the evidence for it. No name, no phone, no email: Lynomia resolves the subject through
     * the customer link it already holds, so sending those would put a customer's contact
     * details into a request log and a queue for nobody to read.
     */
    private function body(LynomiaConsentDecision $decision): array
    {
        return [
            'provider' => 'storesyria',
            'external_store_id' => (string) $decision->store_id,
            'external_subject_id' => (string) $decision->external_subject_id,
            'decision_ref' => (string) $decision->decision_ref,
            'state' => (string) $decision->state,
            'purpose' => (string) $decision->purpose,
            'channel' => (string) $decision->channel,
            'source' => (string) $decision->source,
            'policy_version' => $decision->policy_version,
            're_consent' => (bool) $decision->re_consent,
            'captured_at' => optional($decision->captured_at)->toIso8601String(),
            'evidence' => $decision->evidence ?? [],
        ];
    }

    private function settle(LynomiaConsentDecision $decision, array $outcome): bool
    {
        $reported = (string) ($outcome['outcome'] ?? '');

        $decision->forceFill([
            'delivered_at' => now(),
            'attempts' => $decision->attempts + 1,
            'remote_outcome' => in_array($reported, self::SETTLED, true) ? $reported : 'applied',
            'last_error' => null,
        ])->save();

        return true;
    }

    /**
     * A 4xx is this side's mistake and will be the same mistake next time, so it is recorded
     * and retired -- a malformed decision retried hourly is noise that hides the real backlog.
     * Anything else is treated as transient.
     */
    private function handleApiError(LynomiaConsentDecision $decision, LynomiaApiException $e): bool
    {
        if ($e->isTransient()) {
            return $this->defer($decision, 'kind_' . $e->kind);
        }

        $decision->forceFill([
            'delivered_at' => now(),
            'attempts' => $decision->attempts + 1,
            'remote_outcome' => 'rejected',
            'last_error' => Str::limit('kind_' . $e->kind, 191, ''),
        ])->save();

        return false;
    }

    private function defer(LynomiaConsentDecision $decision, string $reason): bool
    {
        $attempts = $decision->attempts + 1;
        $delay = self::BACKOFF[min($attempts, count(self::BACKOFF)) - 1];

        $decision->forceFill([
            'attempts' => $attempts,
            'deliver_after' => now()->addSeconds($delay),
            'last_error' => Str::limit($reason, 191, ''),
        ])->save();

        return false;
    }
}
