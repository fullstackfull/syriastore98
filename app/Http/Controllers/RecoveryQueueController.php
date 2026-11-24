<?php

namespace App\Http\Controllers;

use App\Models\MarketingRecoveryCandidate;
use App\Models\Setting;
use App\Policies\UserPolicy;
use App\Services\Marketing\MarketingSendGate;
use App\Services\Marketing\NudgeThrottle;
use App\Support\Marketing\RecoveryCandidates;
use Illuminate\Http\Request;

/**
 * MKT-007 — THE RECOVERY QUEUE, AND THE AGES THE MERCHANT SETS.
 *
 * TWO THINGS ON ONE SCREEN, and they belong together: the queue is meaningless without the age that
 * produced it, and an age with no visible queue is a setting nobody can tell is working. A merchant
 * who sets "24 hours" and sees nothing needs to know whether that means "no abandoned baskets" or
 * "the sweep has never run".
 *
 * **THE SCREEN SAYS THAT NOTHING IS SENT, IN WORDS.** Automatic promotional sending is off by owner
 * decision until consent enforcement is approved and active, and every queued row carries the reason
 * it was held. A screen that listed recoverable baskets without saying so would read as "these have
 * been chased", which is the opposite of the truth.
 *
 * AUTHORIZATION IS `Abandon Cart`, REUSED AND SEEDED -- the same name the existing one-click
 * recovery requires (`CartController::abandon_carts_emailsend`), because this is the same activity
 * seen as a list. No new permission name is introduced: a name absent from `Utility::addNewData()`
 * cannot exist as a row and evaluates FALSE for everybody including the tenant owner, and that exact
 * lockout has happened in this repository. The ordering is operator, then the tenant owner, then the
 * permission -- `permissions_as_gates => false`, so `isAbleTo()` is the only working test.
 *
 * NO `store_id` IS A PARAMETER. `OperatorStoreSelection::reportStoreId()`, so an operator who has
 * selected no store is refused rather than reading store 1's queue (GAP-223(b)).
 *
 * THIS CONTROLLER DOES NOT SEND AND HAS NO ACTION THAT COULD. The recovery ACTION stays
 * `CartController`'s existing per-cart click, which already reserves through `NudgeLedger`; adding a
 * second sender here would be a second engine and would route around the gate.
 */
class RecoveryQueueController extends Controller
{
    private const PERMISSION = 'Abandon Cart';

    private function permits(): bool
    {
        $user = auth('web')->user();

        if ($user === null) {
            return false;
        }

        if (UserPolicy::isOperator($user)) {
            return true;
        }

        if ((int) $user->creatorId() === (int) $user->id) {
            return true;
        }

        return (bool) $user->isAbleTo(self::PERMISSION);
    }

    /** @return int|\Illuminate\Http\RedirectResponse */
    private function resolveStore()
    {
        if (! $this->permits()) {
            return redirect()->route('dashboard')->with('error', __('Permission denied.'));
        }

        $storeId = \App\Support\Tenancy\OperatorStoreSelection::reportStoreId();

        if ($storeId <= 0) {
            return redirect()->route('dashboard')->with('error', __('No store is selected.'));
        }

        return $storeId;
    }

    public function index(Request $request, MarketingSendGate $gate, NudgeThrottle $throttle)
    {
        $resolved = $this->resolveStore();

        if (! is_int($resolved)) {
            return $resolved;
        }

        return view('recovery_queue.index', [
            'candidates' => MarketingRecoveryCandidate::forStore($resolved)
                ->orderByDesc('detected_at')
                ->paginate(50),
            'ages' => self::rawAgesFor($resolved),
            'kinds' => RecoveryCandidates::KINDS,
            /*
             * THE TWO GATES, SHOWN AS THEY ARE. The screen states whether sending is possible at
             * all, rather than leaving a merchant to infer it from an empty queue -- and it reads the
             * same two objects the sweep reads, so the screen and the sweep cannot disagree.
             */
            'consentEnforced' => $gate->mode() === MarketingSendGate::MODE_ENFORCE,
            'cadenceConfigured' => $throttle->minIntervalSeconds() !== null,
        ]);
    }

    /**
     * The stored ages exactly as the merchant typed them.
     *
     * RAW AND NOT `configuredFor()`, for the reason `MetricThreshold::rawFor()` records: the checker
     * refuses a value out of range, and a form showing the checker's filtered view would present an
     * empty field while the row still held the merchant's typo.
     *
     * @return array<string, string>
     */
    public static function rawAgesFor(int $storeId): array
    {
        $rows = Setting::where('store_id', $storeId)
            ->whereIn('name', array_column(RecoveryCandidates::KINDS, 'setting'))
            ->pluck('value', 'name')
            ->all();

        $ages = [];

        foreach (RecoveryCandidates::KINDS as $kind => $spec) {
            $ages[$kind] = (string) ($rows[$spec['setting']] ?? '');
        }

        return $ages;
    }

    /**
     * Set the ages. NOTHING ELSE IS WRITABLE from the web on this feature.
     *
     * A BLANK FIELD SWITCHES A KIND OFF and is stored as an empty string rather than as 0 -- the
     * same distinction `MetricThreshold` makes, and for the same reason: 0 and blank are different
     * instructions, and an `empty()` anywhere in the chain collapses them silently. Here 0 is not a
     * meaningful age either way (an age of zero hours means every basket ever), so the validator
     * requires at least 1 and blank is the only "off".
     */
    public function storeAges(Request $request)
    {
        $resolved = $this->resolveStore();

        if (! is_int($resolved)) {
            return $resolved;
        }

        $user = auth('web')->user();

        $rules = [];

        foreach (array_keys(RecoveryCandidates::KINDS) as $kind) {
            // 8760 hours is a year. Above that is a typo, and the sweep would scan it.
            $rules[$kind] = 'nullable|integer|min:1|max:8760';
        }

        $validator = \Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return redirect()->route('recovery-queue.index')
                ->with('error', $validator->getMessageBag()->first());
        }

        foreach (RecoveryCandidates::KINDS as $kind => $spec) {
            $value = $request->input($kind);
            $value = ($value === null) ? '' : trim((string) $value);

            Setting::putForStore($spec['setting'], $value, $resolved, (int) $user->id);
        }

        $configured = RecoveryCandidates::configuredFor($resolved);

        return redirect()->route('recovery-queue.index')->with(
            'success',
            $configured === []
                ? __('Recovery detection is off: no age is set.')
                : __('Recovery ages updated.')
        );
    }
}
