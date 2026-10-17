<?php

namespace Workdo\LynomiaChat\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Workdo\LynomiaChat\app\Exceptions\LynomiaApiException;
use Workdo\LynomiaChat\app\Http\Middleware\EnsureStoreOwnsLink;
use Workdo\LynomiaChat\app\Models\LynomiaConsentDecision;
use Workdo\LynomiaChat\app\Models\LynomiaConsentState;
use Workdo\LynomiaChat\app\Services\ConsentReconciler;

/**
 * WHAT THIS STORE'S CUSTOMERS DECIDED, AND WHETHER IT HAS TAKEN EFFECT.
 *
 * A SECOND CONSENT SURFACE, AND IT IS NOT A DUPLICATE. The existing consent tab proxies
 * Lynomia's own per-contact consent API: it answers "what does this workspace hold about this
 * person", and it is how a merchant records a decision a customer gave them by telephone.
 * This one answers a question that surface cannot: "what did MY checkout capture, has Lynomia
 * been told, and did it agree". Those differ exactly when something is wrong -- a decision
 * stuck in the outbox, a grant Lynomia refused because the shopper had already withdrawn, an
 * opt-out that arrived on WhatsApp -- which is when a merchant needs to look.
 *
 * LOCAL DATA, READ LOCALLY. Unlike the proxying tab, every row here is this side's own:
 * `lynomia_consent_decisions` is what this store captured, `lynomia_consent_states` is what
 * Lynomia last said it holds. No remote call is made to render the page, so it answers while
 * Lynomia is unreachable -- which is one of the states it exists to show.
 *
 * TENANCY COMES FROM THE MIDDLEWARE, NEVER FROM THE REQUEST. EnsureStoreOwnsLink resolves the
 * store from the session and authorizes it before this runs; no route here takes a store id,
 * so there is nothing to tamper with.
 */
class StoreConsentController extends Controller
{
    /** One page of the ledger. Bounded because a store's decision log grows without limit. */
    private const PAGE = 50;

    /**
     * The store's own consent ledger, each decision beside what Lynomia did with it.
     */
    public function index(Request $request): JsonResponse
    {
        $storeId = (int) $request->attributes->get(EnsureStoreOwnsLink::STORE_ATTRIBUTE);
        $link = $request->attributes->get(EnsureStoreOwnsLink::ATTRIBUTE);

        $decisions = LynomiaConsentDecision::latestPerScope($storeId)
            ->orderByDesc('id')
            ->limit(self::PAGE)
            ->get();

        // BOUNDED BY THE PAGE, like the names below it. `$decisions` is limited and only a
        // shown decision ever looks a remote state up, but this loaded EVERY projected row
        // for the store into PHP memory first -- a merchant with a large customer base paid
        // for their whole consent history to render twenty-five lines of it.
        $subjects = $decisions->pluck('external_subject_id')->unique()->all();

        $remote = LynomiaConsentState::forStore($storeId)
            ->whereIn('external_subject_id', $subjects)
            ->get()
            ->groupBy(fn (LynomiaConsentState $row) => $row->external_subject_id . '|' . $row->purpose);

        $names = $this->customerNames($storeId, $subjects);

        return response()->json([
            'settings' => [
                'checkout_optin_enabled' => (bool) ($link?->checkout_optin_enabled),
                'policy_version' => $link?->consent_policy_version,
            ],
            'pending' => LynomiaConsentDecision::forStore($storeId)->pending()->count(),
            'decisions' => $decisions->map(fn (LynomiaConsentDecision $decision) => [
                'subject' => $decision->external_subject_id,
                'customer' => $names[$decision->external_subject_id] ?? null,
                'purpose' => $decision->purpose,
                'channel' => $decision->channel,
                'state' => $decision->state,
                'source' => $decision->source,
                'policy_version' => $decision->policy_version,
                'captured_at' => optional($decision->captured_at)->toIso8601String(),
                'sync' => $this->sync($decision),
                // What LYNOMIA will actually do, which is the fact a merchant is really
                // asking for. The union of the negatives across origins, never one row:
                // a partner grant sitting beside a native withdrawal is not agreement.
                'remote' => $this->remote($remote->get($decision->external_subject_id . '|' . $decision->purpose)),
            ])->all(),
        ]);
    }

    /**
     * Whether the opt-in appears on this store's checkout.
     *
     * A WRITE A MERCHANT OWNS, not an operator. It changes their own storefront and nothing
     * about what anyone is allowed to be sent -- the decisions already captured keep their
     * own policy version, so switching it off does not retroactively unsay anything.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'checkout_optin_enabled' => ['required', 'boolean'],
            'policy_version' => ['nullable', 'string', 'max:64'],
        ]);

        $link = $request->attributes->get(EnsureStoreOwnsLink::ATTRIBUTE);

        $link->forceFill([
            'checkout_optin_enabled' => $validated['checkout_optin_enabled'],
            'consent_policy_version' => $validated['policy_version'] ?? null,
        ])->save();

        return response()->json([
            'checkout_optin_enabled' => (bool) $link->checkout_optin_enabled,
            'policy_version' => $link->consent_policy_version,
        ]);
    }

    /**
     * Pull Lynomia's consent state now, rather than waiting for the hourly sweep.
     *
     * READ-ONLY, like the sweep: it refreshes this side's picture and never pushes a local
     * decision to force agreement. A merchant pressing it while a customer is on the phone
     * is the case it exists for.
     */
    public function reconcile(Request $request, ConsentReconciler $reconciler): JsonResponse
    {
        $link = $request->attributes->get(EnsureStoreOwnsLink::ATTRIBUTE);

        try {
            return response()->json($reconciler->reconcile($link));
        } catch (ConnectionException | LynomiaApiException) {
            return response()->json(['is_success' => false, 'msg' => __('Lynomia Chat could not be reached. Nothing was changed.')], 503);
        }
    }

    /**
     * Where a decision has got to. Four states a merchant can act on, and they are not the
     * same question as what the customer decided.
     */
    private function sync(LynomiaConsentDecision $decision): array
    {
        if ($decision->delivered_at === null) {
            return ['status' => 'pending', 'detail' => $decision->last_error];
        }

        return match ($decision->remote_outcome) {
            'refused_withdrawal_dominant' => ['status' => 'refused', 'detail' => 'withdrawal_dominant'],
            'rejected' => ['status' => 'rejected', 'detail' => $decision->last_error],
            null => ['status' => 'retired', 'detail' => $decision->last_error],
            default => ['status' => 'accepted', 'detail' => $decision->remote_outcome],
        };
    }

    private function remote($rows): array
    {
        if ($rows === null || $rows->isEmpty()) {
            return ['state' => 'unknown', 'origin' => null, 'occurred_at' => null];
        }

        $effective = LynomiaConsentState::effectiveFor($rows);

        return [
            'state' => $effective['state'],
            'origin' => $effective['origin'],
            'occurred_at' => optional($effective['occurred_at'])->toIso8601String(),
        ];
    }

    /**
     * The customers behind the subject ids, so the merchant reads a name rather than a
     * number. Scoped to the store, and only the ones on this page.
     *
     * @return array<string, string>
     */
    private function customerNames(int $storeId, array $subjects): array
    {
        $ids = array_values(array_filter(array_map('intval', $subjects)));

        if ($ids === []) {
            return [];
        }

        return Customer::whereIn('id', $ids)
            ->where('store_id', $storeId)
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (Customer $customer) => [
                (string) $customer->id => trim($customer->first_name . ' ' . $customer->last_name),
            ])
            ->all();
    }
}
