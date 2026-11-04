<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\StoreWallet;
use App\Models\StoreWalletTransaction;
use App\Models\StoreWithdrawal;
use App\Services\OrderFinancialService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StoreFinanceController extends Controller
{
    /** The value a wallet-change claim holds while the change is being written. */
    private const WALLET_CHANGE_WRITING = 'writing';

    /**
     * How long one manual wallet change's claim survives.
     *
     * Shorter than the till's 120s, and deliberately: a manual deposit is a deliberate act typed
     * into a modal, so the window only has to cover a double-click and a resubmit of the same
     * form. A genuine second change of the same amount with the same description a minute later
     * is then accepted rather than refused.
     */
    private const WALLET_CHANGE_CLAIM_SECONDS = 60;

    /**
     * F270 — A DOUBLE SUBMIT CREDITED THE WALLET TWICE, AND THE LOCK DID NOT STOP IT.
     *
     * `addBalance()` and `deductBalance()` each wrap their work in `DB::transaction()` around a
     * `StoreWallet::where('store_id', $storeId)->lockForUpdate()->firstOrFail()`, which is correct
     * and is not the problem. A row lock SERIALISES two writes; it does not make the second one
     * redundant. So two submits of the same manual deposit took the lock one after the other and
     * credited the wallet twice, writing two `manual_deposit` rows that are indistinguishable from
     * two deliberate deposits.
     *
     * AND THE FORM INVITES IT. `finance/index.blade.php:466-487` is a plain
     * `<form method="POST">` with a submit button and NO disable-on-submit, inside a Bootstrap
     * modal — a double-click submits twice. Nothing client-side would be a control anyway.
     *
     * THE CONTROL IS THE SAME ATOMIC CLAIM F269 AND F226 USE, taken before the transaction opens
     * and released only on a path that wrote nothing. `Cache::add()` is a single put-if-absent; on
     * the production `database` store it is an INSERT against the `cache` table's primary key, so
     * exactly one of two concurrent submits can take it.
     *
     * THE FINGERPRINT IS SERVER-DERIVED where it matters — the resolved store and the
     * authenticated actor — and canonicalises the amount, so `100`, `100.00` and `100.000` are one
     * deposit rather than three. The direction is part of it, so a credit and a debit of the same
     * figure never collide.
     *
     * FIVE CLAIM SITES NOW SHARE THIS PRIMITIVE BY CONVENTION RATHER THAN BY CODE —
     * `Concerns\GuardsGatewayReturn`, `PaymentController` (twice), `Api\DashboardController`,
     * `PosController` and here. Extracting a shared concern would mean editing four already
     * reviewed and tested payment handlers, which is a larger change than this slice, so it is
     * RECORDED rather than done.
     */
    private function walletChangeFingerprint(
        string $direction,
        $storeId,
        int $actorId,
        $amount,
        $description
    ): string {
        return 'wallet_change:' . hash('sha256', json_encode([
            'store' => (string) $storeId,
            'actor' => $actorId,
            'direction' => $direction,
            'amount' => number_format((float) $amount, 2, '.', ''),
            'description' => trim((string) $description),
        ]));
    }

    /** The one answer a refused duplicate gets, so both handlers cannot drift apart. */
    private function refuseDuplicateWalletChange(string $direction, $storeId)
    {
        Log::warning('Manual wallet change refused as a duplicate submission', [
            'direction' => $direction,
            'store_id' => $storeId,
            'user_id' => auth()->id(),
        ]);

        return redirect()
            ->route('finance.index')
            ->with(
                'error',
                __('A wallet change with the same amount and description was recorded a moment ago. This submission was ignored, so the balance has not been changed twice.')
            );
    }
    /**
     * Authorize a finance action.
     *
     * Routed through the `finance` gate (AuthServiceProvider), NOT through
     * User::isAbleTo() directly. The difference is the whole point:
     *
     *   isAbleTo('Add Finance Balance')  -- false for EVERY user who has not been
     *                                       GRANTED the permission, the platform
     *                                       operator included, because Laratrust
     *                                       resolves strictly through the
     *                                       role/permission pivot tables and has
     *                                       no super-admin bypass.
     *   Gate::allows('finance', $p)      -- true for a super admin regardless of
     *                                       seeding or grant, and the normal
     *                                       Laratrust answer for everyone else.
     *
     * CORRECTED 2026-11-04 (finance null-store guard slice). The first line used
     * to read "false for EVERY user on a database built from source, because
     * Utility::addNewData() never creates any permission row containing
     * 'Finance'". THAT IS NO LONGER TRUE: addNewData() now seeds all six finance
     * names (the FINANCE block in its $arrPermissions; measured:
     * `grep -c -i finance app/Models/Utility.php` === 18). The rows are created
     * and granted to no role by design, so the CONCLUSION survives -- an
     * ungranted user, operator included, is still denied by isAbleTo() -- but the
     * REASON has changed from "the row cannot exist" to "the row exists and is
     * not granted". That distinction is load-bearing: the old wording said the
     * delegated limb was unreachable on any from-source database, which is a
     * written reason not to test the one principal who can hold a finance
     * permission and resolve no store. See AuthServiceProvider's `finance` gate
     * for the same correction and for which copies of it remain uncorrected.
     *
     * The operator half was verified by execution on a fresh sqlite schema:
     * isAbleTo() returned false for a super admin on all six finance names while
     * this gate returned true.
     */
    protected function canFinance(string $permission): bool
    {
        return \Illuminate\Support\Facades\Gate::allows('finance', $permission);
    }

    /**
     * THE PERMISSIONS A WITHDRAWAL STATE CHANGE REQUIRES -- all of them.
     *
     * NEW-withdrawal-view-permission-authorises-state-change. approveWithdrawal,
     * rejectWithdrawal and payWithdrawal were each gated on the single
     * permission 'View Finance Withdrawal Requests', in the route file AND here
     * in the controller. That is a VIEW permission authorising three
     * state-changing, money-moving actions: whoever could READ the withdrawal
     * list could APPROVE and PAY. Read must not imply write.
     *
     * 'Edit Finance Payment Status' is added rather than a new name because a
     * name absent from Utility::addNewData() cannot exist as a ROW, and this
     * controller is the exact place that lesson was learned: all six finance
     * names were gated on while none was seeded, so every finance mutator was
     * 403 for every user including the platform operator. A seventh unseeded
     * name would recreate that lockout for delegated users. Of the six seeded
     * names (the FINANCE block in Utility::addNewData()'s $arrPermissions -- named
     * rather than cited by line, because the old "Utility.php:2881-2886" here had
     * drifted to :3001-3006 by 2026-11-04) it is the only one denoting a change to a
     * finance record's payment state, which is precisely what approving,
     * rejecting and paying a withdrawal is.
     *
     * A dedicated 'Approve Finance Withdrawal' permission remains the clean
     * split and is reported as an owner decision, because it needs a seeder
     * entry in a file this workstream does not own.
     *
     * ONE SOURCE OF TRUTH. routes/web.php builds its `finance.permission:`
     * parameter list from this constant, so the route-level guard and the
     * controller-level guard cannot drift apart -- which is how approve/reject/
     * pay came to have the route guard and the controller guard disagree in the
     * first place.
     */
    public const WITHDRAWAL_REVIEW_PERMISSIONS = [
        'View Finance Withdrawal Requests',
        'Edit Finance Payment Status',
    ];

    /**
     * ALL-OF. Returns false on the first permission the caller lacks.
     *
     * Deliberately not a loose `||`: the whole defect being repaired is a single
     * weak permission standing in for a stronger one.
     */
    protected function canFinanceAll(array $permissions): bool
    {
        if ($permissions === []) {
            return false;
        }

        foreach ($permissions as $permission) {
            if (!$this->canFinance($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The tenant whose finance records this request may touch.
     *
     * Returned separately from the authorization decision so that "you may not
     * do this" (403) stays distinguishable from "no store is selected", which
     * previously surfaced as a confusing ModelNotFound 404 from a
     * `where('store_id', null)` lookup.
     */
    protected function financeStoreId()
    {
        $storeId = getCurrentStore();

        return empty($storeId) ? null : $storeId;
    }

    /**
     * JSON refusal for the /api/admin/wallet/* endpoints, which reach this
     * controller through `custom.auth` and must never emit an HTML redirect.
     */
    protected function financeApiDenied(string $message, int $status = 403)
    {
        return response()->json([
            'status'  => 0,
            'message' => 'fail',
            'data'    => ['message' => $message],
        ], $status);
    }

    /**
     * OWNER DECISION 7 -- HAS THE CALLER ACTUALLY CHOSEN THE TENANT WHOSE MONEY THIS WOULD MOVE?
     *
     * The owner's words: *"An operator who has not explicitly selected a merchant must not create
     * a wallet, credit a wallet, approve a payment, or perform a tenant-specific money operation
     * merely because getCurrentStore() falls back to store ID 1."*
     *
     * WHAT WAS MEASURED, because the sibling null-store slice's "pure hardening" argument DOES NOT
     * TRANSFER HERE and the difference is the whole finding. That slice was right that a NULL store
     * moves nothing: `store_id` is `NOT NULL` in all three money tables, so `store_id IS NULL`
     * matches no row and the matching INSERT cannot succeed. The operator's store is not NULL. It
     * is the HARDCODED LITERAL 1. Driven through the real route against a store 1 owned by a
     * DIFFERENT merchant (`tests/Feature/Security/OperatorImplicitStoreFinancialGuardTest`):
     *
     *     POST /finance/add-balance  amount=77.00, operator with current_store = NULL
     *       -> 302 /finance, flash "Balance added successfully."
     *       -> store_wallets.available_balance for store 1: 100.00 -> 177.00
     *       -> store_wallet_transactions: 0 rows -> 1 row, type manual_deposit
     *
     * A COMPLETED CROSS-TENANT CREDIT of another merchant's wallet by a principal who named no
     * merchant. No null, no refused insert, no exception -- the money simply landed in whoever
     * owns store 1. This is not low-severity hardening.
     *
     * THE DISTINCTION IS NOT AVAILABLE FROM `getCurrentStore()`'s RETURN VALUE, and that is why
     * the column is read instead. An operator who CHOSE store 1 and an operator who chose NOTHING
     * both see `1` come back, so a comparison against 1 would have broken the authorized case --
     * which is the owner's second requirement, not an edge case.
     *
     * WHY READING THE COLUMN IS RELIABLE, which was the owner's STOP CONDITION:
     *
     *   1. `getCurrentStore()` (helper.php:758-782) reaches the literal 1 on exactly one branch:
     *      `empty($user->current_store)` AND `$user->type == 'super admin'`. Nothing else returns
     *      that value, so the fallback is a function of this column plus the type.
     *   2. THIS GUARD USES THE IDENTICAL `empty()` TEST ON THE IDENTICAL COLUMN off the same
     *      authenticated user, so the guard and the helper cannot disagree about whether the
     *      fallback was taken. That is load-bearing rather than tidy: `empty()` is also false-y for
     *      `0` and `'0'`, and because the helper uses the same test, such a value is "unset" to
     *      BOTH -- the guard refuses exactly the inputs for which the helper defaults.
     *   3. Nothing writes a sentinel into the column. All nine persisted writes assign a real
     *      `Store->id` (`Api\DashboardController:4860/:5039/:5111`,
     *      `Auth\RegisteredUserController:147/:284`, `StoreController:297/:715/:869`,
     *      `UserController:161/:802`), and the migration declares it
     *      `unsignedBigInteger()->nullable()` with no default, so a fresh operator is NULL.
     *      THE ONE NON-NUMERIC ASSIGNMENT IS NOT A WRITE: `UserController:190` sets
     *      `current_store` to a store NAME to shape `json_encode($user)` for a webhook, AFTER
     *      `$user->save()` at :168, and the method returns a redirect with no further save. If
     *      anyone ever adds a save there the column would hold a name, `empty()` would be false,
     *      and `getCurrentStore()` would return the name -- so this guard would stop refusing at
     *      exactly the point the helper stopped defaulting. That hazard is the helper's; it is
     *      reported rather than fixed here, because the owner excluded rewriting the helper.
     *
     * THE SHAPE IS COPIED FROM `unreconciledCaptures()` IN THIS SAME FILE rather than invented.
     * That handler already refuses this principal, for the reason stated there -- a money REVIEW
     * must not silently default to an arbitrary tenant -- and consistency with an existing in-repo
     * decision beats a new mechanism.
     *
     * THE OPERATOR TEST IS `UserPolicy::isOperator`, AND THAT IS A CORRECTION TO THIS SLICE'S OWN
     * FIRST DRAFT, which carried `strtolower(trim((string) $caller->type)) === 'super admin'`
     * copied from `unreconciledCaptures`. `OperatorCheckCensusTest` records a MEASURED defect of
     * exactly that shape: `OrderController::fileExport` used the shared helper while
     * `mayUseOrderScreen` carried its own `strtolower(trim(...))`, and for `users.type` =
     * `' super admin'` the two ANSWERED DIFFERENTLY -- the order screen admitting a principal the
     * document guard refused. P1-SEC-11 criterion 3 exists to end that: *"the operator is defined
     * once: normalised comparison, one helper"*. A ninth hand-rolled spelling beside the helper is
     * the defect, not extra safety.
     *
     * AND `rtrim` LOSES NOTHING HERE, which is the part worth checking rather than assuming.
     * `rtrim(' ')` is what MySQL's PAD SPACE collation actually treats as equal, so the helper
     * agrees with `User::where('type', 'super admin')` and with every SQL-side operator check;
     * `trim` would have claimed `' super admin'` is the operator while the ENGINE says it is not.
     * The guard is still a strict SUPERSET of the fallback condition: PHP's `==` between two
     * non-numeric strings is a plain string comparison, so `getCurrentStore()`'s
     * `$user->type == 'super admin'` is true for the exact literal and nothing else -- and
     * `isOperator('super admin')` is true for that literal. SO EVERY INPUT THAT REACHES THE
     * FALLBACK IS REFUSED.
     *
     * THE EXTRA REFUSALS ARE A SET, NOT ONE CASE, and this paragraph understated them until an
     * independent review measured it: `isOperator()` LOWERCASES as well as rtrims, so `Super
     * Admin`, `SUPER ADMIN` and `Super Admin ` are operators to the guard, while `helper.php`'s
     * `$user->type == 'super admin'` admits none of them. For every one of those the helper falls
     * through to the user's OWN store (or to null, which the `empty($storeId)` guard above already
     * refuses), so the guard refuses a caller the helper would have resolved correctly.
     *
     * THE GUARD IS STILL THE RIGHT SIDE OF THAT DISAGREEMENT. On MySQL's `utf8mb4_unicode_ci` --
     * case- and accent-insensitive, PAD SPACE -- those rows ARE the operator to every SQL query in
     * this codebase, so `isOperator()` agrees with the engine and the helper's `==` is the
     * component that is out of step. Refusing a row the database calls an operator is fail-closed;
     * admitting it would not be. Reaching it also needs a hand-edited `users.type`: the only
     * writers are `'admin'`, `'customer'`, the seeder's exact `'super admin'`, and role names
     * gated by `RolePolicy::RESERVED_NAMES` -- AND a granted finance permission, because such a
     * row fails `AuthServiceProvider:117`'s strict `===` operator limb and reaches these handlers
     * only on the delegated `isAbleTo()` limb.
     *
     * `Services\MerchantQuickActions:143` already pairs the same helper with the same
     * `empty($user->current_store)` for the same question.
     *
     * `unreconciledCaptures` STILL CARRIES THE OLD SPELLING and is deliberately left alone: it is
     * outside this decision's scope, converting it would change a refusal boundary the owner did
     * not authorise touching, and it is reported instead. Note that NEITHER spelling is counted by
     * `OperatorCheckCensusTest::PATTERNS`, whose three PCREs require `type` immediately followed by
     * the operator -- `type)) === 'super admin'` does not match. That is census-failure mode 1 from
     * the project skill (keyed on spelling) and is reported, not silently relied upon.
     *
     * NOT A MERCHANT'S PROBLEM. `getCurrentStore()` falls through to
     * `Store::where('created_by', $user->id)->first()` for a non-operator, so a merchant with no
     * selection resolves THEIR OWN single tenant -- a legitimate implicit resolution that the
     * owner's "normal merchant -> unchanged" requires this guard to leave alone. The operator limb
     * is therefore part of the predicate and not a convenience.
     */
    private function operatorHasChosenNoStore(): bool
    {
        $caller = auth()->user();

        return $caller !== null
            && \App\Policies\UserPolicy::isOperator($caller)
            && empty($caller->current_store);
    }

    /**
     * THE ONE REFUSAL, so eight handlers cannot drift apart in message or in logging.
     *
     * The caller passes its OWN redirect target, because each of these handlers already has a
     * settled one and the null-store slice's reasoning for each still applies: `index()` must
     * terminate at `dashboard` (`back()` loops there, because Laravel 11's
     * `StartSession::storeCurrentUrl()` sets the previous URL on every GET with a matched route
     * including one that answers a redirect, and `finance.index` IS that handler); the three
     * mutators that answer `route('finance.index')` keep it; and the four that answer `back()`
     * keep that. A refused POST's `back()` resolves to the last GET, so it cannot loop.
     *
     * THE MESSAGE IS THIS CONTROLLER'S EXISTING `__('No store is selected.')`, reused rather than
     * replaced, for two reasons. It is literally the condition -- for an operator who has chosen
     * nothing, no store IS selected -- and it is already translated in `resources/lang/ar.json`,
     * where a newly invented string would not be. A more directive wording ("choose a merchant
     * first") would need an `ar.json` entry owned by the open finance-translation task, so it is
     * reported rather than taken. NOTE that `approveWithdrawal`, `rejectWithdrawal` and
     * `payWithdrawal` phrase their own PRE-EXISTING null-store refusal as
     * `__('No store selected.')` -- a different string, and one with NO Arabic translation
     * (measured). That is left exactly as it was; this guard answers with the translated form.
     *
     * IT IS LOGGED. A platform operator attempting a money write against an unchosen tenant is
     * precisely the event the security log inventory exists for, and this file already sets that
     * precedent in `refuseDuplicateWalletChange()`. One log site, so one inventory row.
     */
    private function refuseUnchosenOperatorStore(
        string $handler,
        \Illuminate\Http\RedirectResponse $redirect
    ): \Illuminate\Http\RedirectResponse {
        Log::warning('Financial action refused: operator has selected no merchant store', [
            'handler' => $handler,
            'user_id' => auth()->id(),
            'fallback_store_id' => getCurrentStore(),
        ]);

        return $redirect->with('error', __('No store is selected.'));
    }

    public function updateFinancialPaymentStatus(Request $request, $id)
    {
        if (!$this->canFinance('Edit Finance Payment Status')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'payment_status' => 'required|in:paid,unpaid',
        ]);

        $storeId = getCurrentStore();

        /*
         * Same guard and the same reasoning as index(), which carries the full account.
         *
         * THIS HANDLER ALREADY REFUSED a storeless caller, so what changes is only the
         * DIAGNOSIS. `where('store_id', null)` compiles to `store_id IS NULL`, and
         * `store_order_financials.store_id` is NOT NULL, so no row matched and the answer was
         * `__('Financial record not found.')` below -- measured. That message blames the RECORD
         * for a condition of the SESSION and sends a merchant looking for an order that is
         * perfectly present. `back()` is how this handler's two other refusals answer.
         */
        if (empty($storeId)) {
            return redirect()
                ->back()
                ->with('error', __('No store is selected.'));
        }

        // OWNER DECISION 7. "Approve a payment" is named in the decision verbatim, and that is
        // what marking a financial paid is: OrderFinancialService::markFinancialAsPaid() moves
        // store_wallets.total_income and available_balance for the tenant the id belongs to.
        // `back()` is this handler's own refusal shape, used by its two others.
        if ($this->operatorHasChosenNoStore()) {
            return $this->refuseUnchosenOperatorStore(
                'updateFinancialPaymentStatus',
                redirect()->back()
            );
        }

        $financial = DB::table('store_order_financials')
            ->where('id', $id)
            ->where('store_id', $storeId)
            ->first();

        if (!$financial) {
            return back()->with(
                'error',
                __('Financial record not found.')
            );
        }

        try {

            if ($request->payment_status === 'paid') {

                app(OrderFinancialService::class)
                    ->markFinancialAsPaid((int) $financial->id);

            } else {

                app(OrderFinancialService::class)
                    ->markFinancialAsUnpaid((int) $financial->id);
            }

        } catch (\Exception $e) {

            return back()->with(
                'error',
                $e->getMessage()
            );
        }

        return back()->with(
            'success',
            __('Financial payment status updated successfully.')
        );
    }


    /**
     * D4 / D-29.4 — THE REVIEW PATH FOR A COLLECTED PAYMENT THAT HAS NO ORDER.
     *
     * The owner's decision is a REFUSAL first and a feature second: *"a merchant's statement alone
     * is not payment proof."* Where no gateway verification exists, the capture must stay in a
     * review / pending-settlement state, show a needs-follow-up status, keep the reference and the
     * available information without disclosing secrets, create no duplicate order, declare no
     * capture success without evidence, release or restore no stock on a guess, and not release the
     * replay claim unsafely. What D4 then asks for is *"a documented review path with permissions
     * and an audit log"*.
     *
     * SO THIS HANDLER IS DEFINED BY WHAT IT CANNOT DO, and that is the whole design rather than
     * caution about an unfinished one:
     *
     *   - It is a GET. There is no POST sibling, no form and no action column, because every action
     *     a reviewer might want — mark paid, create the order, release the claim, restore stock —
     *     is a thing D-29.4 forbids doing without evidence. An empty action column would invite
     *     somebody to fill it.
     *   - It never writes to `order_gateway_references`. `GatewayReference` has no `markPaid`, no
     *     claim release and no stock movement, and this handler adds none.
     *   - It never touches the replay claims. A capture appearing here means the claim did its job.
     *
     * PERMISSION: `View Finance Dashboard`, which is SEEDED (`Utility::addNewData()`) and is a VIEW
     * name for a VIEW surface. Both halves matter. A name absent from the seeder cannot exist as a
     * row, and this controller is where that was learned — all six finance names were gated on
     * while none was seeded, so every finance mutator was 403 for everyone including the operator.
     * And this file's own `WITHDRAWAL_REVIEW_PERMISSIONS` exists because a VIEW permission had been
     * authorising three money-moving actions: read must not imply write. A read-only surface takes
     * a read permission, and no new name is invented that would need a seeder entry to work.
     *
     * The gate is `canFinance()`, i.e. `Gate::allows('finance', ...)`, which is operator-first and
     * then `isAbleTo()` — the ordering CLAUDE.md requires, because `isAbleTo()` alone REFUSES the
     * platform operator.
     *
     * TENANT ISOLATION comes from `getCurrentStore()`, never from request input: a predicate built
     * from a caller-supplied `store_id` only compares the caller's own two values, which has
     * produced four separate defects here.
     *
     * THE AUDIT ENTRY IS OF THE REVIEW ITSELF. Nothing is changed, so there is no before-and-after
     * to record; what is worth recording is that somebody LOOKED, and at how many unresolved
     * captures. D4 asks for an audit log on the review path, and a read is the only thing this path
     * does.
     */
    /**
     * A refusal that distinguishes "no store" from "no captures", in both response shapes.
     *
     * It returns BEFORE the audit write, deliberately: an audit row saying a review happened, when
     * the handler could not establish whose money it was reviewing, is a false record of a control
     * having been exercised.
     */
    private function unresolvedStoreRefusal(Request $request, string $reason)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'fail',
                'message' => __('No store is selected, so collected payments cannot be reviewed.'),
                'reason' => $reason,
            ], 409);
        }

        return redirect()->back()->with(
            'error',
            __('No store is selected, so collected payments cannot be reviewed.')
        );
    }

    public function unreconciledCaptures(Request $request)
    {
        if (!$this->canFinance('View Finance Dashboard')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        /*
         * THE STORE MUST RESOLVE EXPLICITLY, AND A MONEY REVIEW IS WHERE THAT MATTERS MOST.
         *
         * Three findings from an independent review of this handler, all of them about
         * `getCurrentStore()` answering something other than "the caller's store":
         *
         *   F157 — for an operator whose `current_store` is empty (the normal state for one who has
         *   never used the store switcher) the helper returns the HARDCODED LITERAL 1. So the
         *   operator was shown one arbitrary tenant's collected payments, with that tenant's
         *   gateway references, AND the audit row was stamped with that tenant's id.
         *
         *   A STAFF user whose `current_store` was never set falls through to
         *   `Store::where('created_by', $user->id)->first()` — and staff do not create stores — so
         *   it returns NULL. `GatewayReference::unreconciled()` then short-circuits on
         *   `! is_numeric($storeId)` and returns an empty collection, so an authorised reviewer was
         *   told "no payments are awaiting review" WHILE THEIR EMPLOYER HAD COLLECTED MONEY WITH NO
         *   ORDER. That is the worst possible wrong answer on this page: it is the one that makes a
         *   reviewer stop looking.
         *
         * An empty list is indistinguishable from a clean one, so the only safe behaviour is to
         * REFUSE rather than render. The refusal says which condition failed, because "no store"
         * and "no captures" are different facts and the merchant can act on the first.
         */
        $storeId = getCurrentStore();

        if (! is_numeric($storeId) || (int) $storeId <= 0) {
            return $this->unresolvedStoreRefusal($request, 'no store is selected');
        }

        /*
         * AND THE OPERATOR MUST HAVE CHOSEN ONE. `getCurrentStore()` cannot distinguish "the
         * operator picked store 1" from "the operator picked nothing and the helper defaulted", so
         * the column is read directly here. An operator who has selected a store is served
         * normally — the operator is not refused from the surface, only from an unchosen default.
         */
        $caller = auth()->user();

        if ($caller
            && strtolower(trim((string) $caller->type)) === 'super admin'
            && empty($caller->current_store)) {
            return $this->unresolvedStoreRefusal($request, 'select a store first');
        }

        $captures = \App\Services\GatewayReference::unreconciled($storeId);

        /*
         * NO SECRETS LEAVE THIS HANDLER. D-29.4: "keep the reference and available information
         * without disclosing secrets". The table holds no key or token -- `gateway` is a name,
         * `reference` is the gateway's own public transaction id, `amount` and `capture_state` are
         * values the merchant already owns -- so the projection below is explicit rather than
         * passing the row through, and a column added to that table later does not reach a screen
         * by default.
         */
        $rows = collect($captures)->map(fn ($row) => [
            'gateway' => (string) ($row->gateway ?? ''),
            'reference' => (string) ($row->reference ?? ''),
            'amount' => (string) ($row->amount ?? ''),
            'capture_state' => (string) ($row->capture_state ?? 'unknown'),
            'seen_at' => (string) ($row->created_at ?? ''),
        ])->values();

        \App\Models\AuditLog::record(
            'finance.unreconciled_captures.reviewed',
            null,
            [],
            ['count' => $rows->count(), 'store_id' => $storeId]
        );

        if ($request->expectsJson()) {
            /*
             * `record_available` IS NOT DECORATION. The D-29.3 migration is dev/test-only until the
             * owner authorises it, so in production `order_gateway_references` DOES NOT EXIST — and
             * `GatewayReference::unreconciled()` returns an empty collection when the table is
             * absent. Without this key the JSON branch answered
             * `200 {"status":"success","needs_follow_up":0,"captures":[]}` on exactly the
             * environment where the record cannot be consulted: a caller or an integration reads
             * that as "no collected payment is unresolved", which is the one conclusion the data
             * cannot support. The HTML branch already said so in a warning; the JSON branch said
             * nothing. Measured by an independent review with the table dropped.
             */
            return response()->json([
                'status' => 'success',
                'record_available' => \App\Services\GatewayReference::available(),
                'needs_follow_up' => $rows->count(),
                'captures' => $rows,
            ]);
        }

        return view('finance.unreconciled-captures', [
            'captures' => $rows,
            'tableIsAvailable' => \App\Services\GatewayReference::available(),
        ]);
    }

    public function index(Request $request)
    {
        if (!$this->canFinance('View Finance Dashboard')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $storeId = getCurrentStore();

        /*
         * NO STORE, NO DASHBOARD. THE ACCOUNT FOR ALL FOUR OF THESE GUARDS LIVES HERE.
         *
         * Six handlers on this controller already refuse a principal for whom `getCurrentStore()`
         * answers nothing -- addBalance and deductBalance under the F270 rationale,
         * approveWithdrawal / rejectWithdrawal / payWithdrawal through `financeStoreId()`, and
         * unreconciledCaptures. Four did not: this one, requestWithdrawal(), withdrawals() and
         * updateFinancialPaymentStatus(). The shape is copied from addBalance rather than
         * invented, and each of the four answers the way that handler's OWN existing refusals
         * answer, which is already true of all six siblings.
         *
         * WHAT WAS REALLY WRONG, AND WHAT WAS NOT. `store_id` is NOT NULL in `store_wallets`,
         * `store_withdrawals` AND `store_order_financials` -- the measured production structure in
         * `App\Support\Database\GateFProductionSchema::DDL`, reconciled against a restored copy of
         * the production dump. So `where('store_id', null)`, which compiles to `store_id IS NULL`,
         * matches NO ROW IN ANY TENANT, there is no shared null-store wallet to credit, and the
         * matching INSERT cannot succeed at all. Nothing was disclosed and no money could move:
         * this is HARDENING, and an exposure read into it would be wrong.
         *
         * What it actually was, measured by driving each handler with a delegated principal who
         * holds a real Finance permission row and resolves no store
         * (tests/Feature/Security/FinanceNullStoreGuardTest):
         *
         *   here                            HTTP 500. The lazy `StoreWallet::create()` below
         *                                   violates NOT NULL, so the finance dashboard was an
         *                                   error page.
         *   requestWithdrawal()             flashed the raw ModelNotFoundException text, naming
         *                                   `App\Models\StoreWallet` to the user.
         *   withdrawals()                   an empty result set -- see the note there.
         *   updateFinancialPaymentStatus()  refused, but blamed the record.
         *
         * IT IS A PURE GUARD: it refuses only requests that already fail, because the insert it
         * stands in front of is one the database rejects. THE LAZY CREATE ITSELF IS DELIBERATELY
         * LEFT for a RESOLVED store, whose wallet may predate StoreController's own
         * `firstOrCreate` (:179-191, :336); that path is untouched and a positive control pins it.
         *
         * THE OPERATOR IS NOT AFFECTED, AND THAT IS A DECISION RATHER THAN AN OVERSIGHT.
         * `getCurrentStore()` (helper.php:767-781) returns the HARDCODED LITERAL 1 for a
         * 'super admin' whose `current_store` is empty, so such an operator never reaches this
         * branch: store 1's wallet is created for them today and still is. Refusing it would
         * REMOVE a capability that works, which is an owner decision and is escalated rather than
         * taken here. unreconciledCaptures DOES refuse that case, for a reason stated there -- a
         * money REVIEW must not silently default to an arbitrary tenant -- and the asymmetry is
         * intentional.
         *
         * WHY THE TARGET IS `dashboard` AND NOT addBalance's `finance.index` OR the siblings'
         * `back()`. THIS HANDLER *IS* finance.index, so addBalance's target is a redirect to
         * itself. And `back()` -- the shape approveWithdrawal, rejectWithdrawal, payWithdrawal and
         * unreconciledCaptures all use -- IS A LOOP HERE, which is not obvious and was nearly
         * shipped: Laravel 11's `StartSession::storeCurrentUrl()` (:200-209) sets the previous URL
         * on EVERY GET with a matched route, INCLUDING ONE THAT ANSWERS A REDIRECT, so for a
         * merchant whose last page was /finance -- the common case, since losing a store happens
         * after using it -- `back()` returns /finance and the two bounce forever. A 302 chain is
         * worse than the 500 it replaces, so the target has to be a route outside this surface.
         *
         * With this handler terminating at /dashboard, withdrawals() and requestWithdrawal() can
         * keep their own `route('finance.index')` and updateFinancialPaymentStatus() its `back()`:
         * every finance refusal now ends here, and here ends outside finance.
         *
         * HONEST ABOUT WHAT THE MERCHANT THEN SEES, because the dashboard is NOT a working page
         * for this principal either: `/dashboard` is itself a 500 for a storeless caller --
         * measured -- because `partision/header.blade.php` hands the resolved store to
         * `HomeController::getThemeUrl()`, which reads `$store->slug` on null. THE SHARED CHROME
         * IS BROKEN FOR EVERY MERCHANT SCREEN A STORELESS PRINCIPAL OPENS, and that is reported
         * separately; it is not finance's to fix and this guard does not claim to. What this guard
         * does buy is real and bounded: no impossible INSERT against a money table, no
         * exception text on screen, no silently empty money list, and a flash message that names
         * the true condition for the moment the chrome is repaired.
         */
        if (empty($storeId)) {
            return redirect()
                ->route('dashboard')
                ->with('error', __('No store is selected.'));
        }

        /*
         * OWNER DECISION 7 -- AND THIS HANDLER IS A WRITE, WHICH IS EASY TO MISS ON A DASHBOARD.
         *
         * The lazy `StoreWallet::create()` a few lines below is the FIRST thing the owner's
         * decision names: *"must not create a wallet"*. So an operator who has chosen no merchant
         * opening /finance was creating a wallet row for whoever owns store 1.
         *
         * THIS SUPERSEDES THE PARAGRAPH ABOVE that reads "THE OPERATOR IS NOT AFFECTED, AND THAT
         * IS A DECISION RATHER THAN AN OVERSIGHT ... Refusing it would REMOVE a capability that
         * works, which is an owner decision and is escalated rather than taken here." That
         * escalation is what produced owner decision 7, and the owner decided to refuse. The
         * paragraph is kept rather than deleted because it records why the earlier slice stopped
         * where it did, and because its reading of the severity was WRONG in a way worth keeping
         * visible: "store 1's wallet is created for them today and still is" treated the fallback
         * as harmless, when store 1 belongs to a merchant who did not ask for it.
         *
         * `dashboard` is this handler's own target, for the reason given above: `back()` loops
         * here and `finance.index` IS this handler.
         */
        if ($this->operatorHasChosenNoStore()) {
            return $this->refuseUnchosenOperatorStore('index', redirect()->route('dashboard'));
        }

        $currencyCode = Setting::where('store_id', $storeId)
            ->where('name', 'defult_currancy')
            ->value('value') ?? 'USD';

        $wallet = StoreWallet::where(
            'store_id',
            $storeId
        )->first();

        if (!$wallet) {

            $wallet = StoreWallet::create([
                'store_id' => $storeId,
                'currency_code' => $currencyCode,
                'available_balance' => 0,
                'pending_balance' => 0,
                'withdrawal_pending_balance' => 0,
                'total_income' => 0,
                'total_withdrawn' => 0,
            ]);
        }

        $query = StoreWalletTransaction::where(
            'store_id',
            $storeId
        );

        // ==========================================
        // 🔍 معالجة البحث الذكي حسب الحقل المحدد
        // ==========================================
        if ($request->filled('search_field') && $request->filled('search_value')) {
            $field = $request->search_field;
            $value = $request->search_value;

            switch ($field) {
                case 'id':
                    $query->where('id', $value);
                    break;

                case 'created_at':
                    $query->whereDate('created_at', $value);
                    break;

                case 'type':
                    $query->where('type', $value);
                    break;

                case 'description':
                    $query->where('description', 'LIKE', "%{$value}%");
                    break;

                case 'currency_code':
                    $query->where('currency_code', 'LIKE', '%' . strtoupper($value) . '%');
                    break;

                case 'credit':
                    $query->where('direction', 'credit')
                          ->where('amount', $value);
                    break;

                case 'debit':
                    $query->where('direction', 'debit')
                          ->where('amount', $value);
                    break;

                case 'balance_before':
                    $query->where('balance_before', $value);
                    break;

                case 'balance_after':
                    $query->where('balance_after', $value);
                    break;

                case 'status':
                    $query->where('status', $value);
                    break;
            }
        }

        // ==========================================
        // 📅 معالجة الفلاتر التقليدية (التاريخ، النوع، الحالة)
        // ==========================================
        if ($request->filled('from_date')) {
            $query->whereDate(
                'created_at',
                '>=',
                $request->from_date
            );
        }

        if ($request->filled('to_date')) {
            $query->whereDate(
                'created_at',
                '<=',
                $request->to_date
            );
        }

        if ($request->filled('type')) {
            $query->where(
                'type',
                $request->type
            );
        }

        if ($request->filled('status')) {
            $query->where(
                'status',
                $request->status
            );
        }

        $transactions = $query
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $orderFinancials = DB::table(
            'store_order_financials'
        )
            ->where('store_id', $storeId)
            ->orderByDesc('id')
            ->paginate(
                15,
                ['*'],
                'orders_page'
            )
            ->withQueryString();

        /*
         |--------------------------------------------------------------------------
         | Inventory
         |--------------------------------------------------------------------------
         */

        $products = Product::where(
            'store_id',
            $storeId
        )->get();

        $inventoryUnits = 0;
        $inventoryCost = 0;
        $inventoryRetailValue = 0;

        foreach ($products as $product) {

            $costPrice =
                (float) ($product->cost_price ?? 0);

            if ($product->variant_product == 1) {

                $variants = ProductVariant::where(
                        'product_id',
                        $product->id
                    )
                    ->where(
                        'store_id',
                        $storeId
                    )
                    ->get();

                foreach ($variants as $variant) {

                    $stock =
                        (int) ($variant->stock ?? 0);

                    if ($stock <= 0) {
                        continue;
                    }

                    $inventoryUnits += $stock;

                    $inventoryCost +=
                        ($costPrice * $stock);

                    $sellingPrice = (float) (
                        $variant->variation_price
                        ?? $variant->price
                        ?? 0
                    );

                    $inventoryRetailValue +=
                        ($sellingPrice * $stock);
                }

            } else {

                $stock =
                    (int) ($product->product_stock ?? 0);

                if ($stock <= 0) {
                    continue;
                }

                $inventoryUnits += $stock;

                $inventoryCost +=
                    ($costPrice * $stock);

                $sellingPrice = (float) (
                    $product->sale_price
                    ?? $product->price
                    ?? 0
                );

                $inventoryRetailValue +=
                    ($sellingPrice * $stock);
            }
        }

        $inventoryCost =
            round($inventoryCost, 2);

        $inventoryRetailValue =
            round($inventoryRetailValue, 2);

        $inventoryPotentialProfit = round(
            $inventoryRetailValue - $inventoryCost,
            2
        );

        return view(
            'finance.index',
            compact(
                'wallet',
                'transactions',
                'orderFinancials',
                'inventoryUnits',
                'inventoryCost',
                'inventoryRetailValue',
                'inventoryPotentialProfit'
            )
        );
    }


    public function addBalance(Request $request)
    {
        if (!$this->canFinance('Add Finance Balance')) {
            return redirect()->route('finance.index')->with('error', __('Permission denied.'));
        }


        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' =>
                'required|string|max:255',
        ]);

        $storeId = getCurrentStore();

        /*
         * F270. NO STORE, NO WRITE — and this is not defensive padding.
         * `where('store_id', null)` compiles to `store_id IS NULL`, so a principal who resolves
         * no store would either fail on `firstOrFail()` or, if a wallet row with a null
         * `store_id` exists, CREDIT THAT ROW. A fingerprint keyed on an empty store would also
         * put every such principal onto one shared claim.
         */
        if (empty($storeId)) {
            return redirect()
                ->route('finance.index')
                ->with('error', __('No store is selected.'));
        }

        /*
         * OWNER DECISION 7 -- *"must not ... credit a wallet"*, named verbatim. THIS IS THE PATH
         * THAT WAS MEASURED MOVING MONEY: 100.00 -> 177.00 on another merchant's wallet.
         *
         * BEFORE THE CLAIM, DELIBERATELY. `Cache::add()` below is a put-if-absent that is released
         * only on a path which wrote nothing, so taking it first and then refusing would burn a
         * 60-second idempotency claim on a request that did nothing -- and the operator's genuine
         * retry, after choosing a merchant, would be refused as a duplicate of a credit that never
         * happened. The F270 note below has the full argument for why that release rule matters.
         */
        if ($this->operatorHasChosenNoStore()) {
            return $this->refuseUnchosenOperatorStore(
                'addBalance',
                redirect()->route('finance.index')
            );
        }

        $walletClaim = $this->walletChangeFingerprint(
            'credit',
            $storeId,
            (int) auth()->id(),
            $request->amount,
            $request->description
        );

        if (! Cache::add($walletClaim, self::WALLET_CHANGE_WRITING, now()->addSeconds(self::WALLET_CHANGE_CLAIM_SECONDS))) {
            return $this->refuseDuplicateWalletChange('credit', $storeId);
        }

        try {

        DB::transaction(function () use (
            $request,
            $storeId
        ) {

            $wallet = StoreWallet::where(
                    'store_id',
                    $storeId
                )
                ->lockForUpdate()
                ->firstOrFail();

            $currencyCode =
                $wallet->currency_code
                ?? 'USD';

            $before =
                (float) $wallet->available_balance;

            $amount =
                (float) $request->amount;

            $after = $before + $amount;

            $wallet->available_balance = $after;

            $wallet->total_income =
                (float) $wallet->total_income
                + $amount;

            $wallet->save();

            StoreWalletTransaction::create([
                'store_id' => $storeId,
                'wallet_id' => $wallet->id,
                'currency_code' => $currencyCode,

                'type' => 'manual_deposit',
                'direction' => 'credit',

                'amount' => $amount,

                'balance_before' => $before,
                'balance_after' => $after,

                'reference_type' => 'manual',
                'reference_id' => null,

                'description' =>
                    $request->description,

                'status' => 'completed',

                'created_by' => auth()->id(),
            ]);
        });

        } catch (\Throwable $e) {
            /*
             * THE TRANSACTION ROLLED BACK, so nothing was written and the claim must not hold.
             * Releasing only here is the point: a claim is released on a path that wrote nothing
             * and NEVER on a path that wrote something, which is what keeps a retry after a
             * successful write from crediting twice. The exception is re-thrown so the outcome
             * for the caller is exactly what it was before this guard existed.
             */
            Cache::forget($walletClaim);

            throw $e;
        }

        return redirect()
            ->route('finance.index')
            ->with(
                'success',
                __('Balance added successfully.')
            );
    }


    public function deductBalance(Request $request)
    {
        if (!$this->canFinance('Deduct Finance Balance')) {
            return redirect()->route('finance.index')->with('error', __('Permission denied.'));
        }

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' =>
                'required|string|max:255',
        ]);

        $storeId = getCurrentStore();

        /* F270, same reasoning as addBalance: `where('store_id', null)` compiles to `IS NULL`. */
        if (empty($storeId)) {
            return redirect()
                ->route('finance.index')
                ->with('error', __('No store is selected.'));
        }

        /*
         * OWNER DECISION 7 -- a debit of another merchant's wallet is squarely the decision's
         * *"tenant-specific money operation"*. Before the claim, for the reason given in
         * addBalance: a refused request must not burn the idempotency claim.
         */
        if ($this->operatorHasChosenNoStore()) {
            return $this->refuseUnchosenOperatorStore(
                'deductBalance',
                redirect()->route('finance.index')
            );
        }

        $walletClaim = $this->walletChangeFingerprint(
            'debit',
            $storeId,
            (int) auth()->id(),
            $request->amount,
            $request->description
        );

        if (! Cache::add($walletClaim, self::WALLET_CHANGE_WRITING, now()->addSeconds(self::WALLET_CHANGE_CLAIM_SECONDS))) {
            return $this->refuseDuplicateWalletChange('debit', $storeId);
        }

        try {

            DB::transaction(function () use (
                $request,
                $storeId
            ) {

                $wallet = StoreWallet::where(
                        'store_id',
                        $storeId
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                $currencyCode =
                    $wallet->currency_code
                    ?? 'USD';

                $before =
                    (float) $wallet->available_balance;

                $amount =
                    (float) $request->amount;

                if ($amount > $before) {
                    throw new \Exception(
                        __('Insufficient wallet balance.')
                    );
                }

                $after = $before - $amount;

                $wallet->available_balance = $after;

                $wallet->total_withdrawn =
                    (float) $wallet->total_withdrawn
                    + $amount;

                $wallet->save();

                StoreWalletTransaction::create([
                    'store_id' => $storeId,
                    'wallet_id' => $wallet->id,
                    'currency_code' => $currencyCode,

                    'type' => 'manual_withdrawal',
                    'direction' => 'debit',

                    'amount' => $amount,

                    'balance_before' => $before,
                    'balance_after' => $after,

                    'reference_type' => 'manual',
                    'reference_id' => null,

                    'description' =>
                        $request->description,

                    'status' => 'completed',

                    'created_by' => auth()->id(),
                ]);
            });

        } catch (\Exception $e) {

            /*
             * F270. NOTHING WAS WRITTEN — `DB::transaction` rolled back — so the claim is
             * released. "Insufficient wallet balance." reaches here, and an admin who tops the
             * wallet up and retries the SAME figure must not be refused as a duplicate of a
             * deduction that never happened.
             */
            Cache::forget($walletClaim);

            return redirect()
                ->route('finance.index')
                ->with(
                    'error',
                    $e->getMessage()
                );
        }

        return redirect()
            ->route('finance.index')
            ->with(
                'success',
                __('Balance deducted successfully.')
            );
    }


    public function requestWithdrawal(Request $request)
    {
        if (!$this->canFinance('Request Finance Withdrawal')) {
            return redirect()->route('finance.index')->with('error', __('Permission denied.'));
        }

        $request->validate([
            'amount' =>
                'required|numeric|min:0.01',
            'payment_method' =>
                'required|string|max:100',
            'payment_details' =>
                'required|string|max:2000',
            'store_note' =>
                'nullable|string|max:500',
        ]);

        $storeId = getCurrentStore();

        /*
         * Same guard and the same reasoning as index(), which carries the full account.
         *
         * Without it the `StoreWallet::where('store_id', $storeId)->lockForUpdate()
         * ->firstOrFail()` below threw a ModelNotFoundException into this handler's own
         * `catch (\Exception $e)`, which flashes `$e->getMessage()` -- so the merchant was shown
         * `No query results for model [App\Models\StoreWallet].`, an internal class name on a
         * money screen. Measured, not inferred.
         *
         * `route('finance.index')` is addBalance's target verbatim, and is where this handler's
         * permission refusal above already sends a caller.
         */
        if (empty($storeId)) {
            return redirect()
                ->route('finance.index')
                ->with('error', __('No store is selected.'));
        }

        /*
         * OWNER DECISION 7. This writes THREE rows for the resolved tenant -- it moves
         * `available_balance` into `withdrawal_pending_balance`, inserts a `store_withdrawals` row
         * and inserts a `withdrawal_request` ledger row -- so it is a *"tenant-specific money
         * operation"* under any reading. `route('finance.index')` is this handler's own target.
         */
        if ($this->operatorHasChosenNoStore()) {
            return $this->refuseUnchosenOperatorStore(
                'requestWithdrawal',
                redirect()->route('finance.index')
            );
        }

        try {

            DB::transaction(function () use (
                $request,
                $storeId
            ) {

                $wallet = StoreWallet::where(
                        'store_id',
                        $storeId
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                $currencyCode =
                    $wallet->currency_code
                    ?? 'USD';

                $amount =
                    (float) $request->amount;

                if (
                    $amount >
                    (float) $wallet->available_balance
                ) {
                    throw new \Exception(
                        __('Insufficient available balance.')
                    );
                }

                $before =
                    (float) $wallet->available_balance;

                $wallet->available_balance -=
                    $amount;

                $wallet->withdrawal_pending_balance +=
                    $amount;

                $wallet->save();

                $withdrawal = StoreWithdrawal::create([
                    'store_id' => $storeId,
                    'wallet_id' => $wallet->id,

                    'amount' => $amount,

                    'status' => 'pending',

                    'payment_method' =>
                        $request->payment_method,

                    'payment_details' =>
                        $request->payment_details,

                    'store_note' =>
                        $request->store_note,

                    'requested_by' =>
                        auth()->id(),

                    'requested_at' => now(),
                ]);

                StoreWalletTransaction::create([
                    'store_id' => $storeId,
                    'wallet_id' => $wallet->id,
                    'currency_code' => $currencyCode,

                    'type' => 'withdrawal_request',
                    'direction' => 'debit',

                    'amount' => $amount,

                    'balance_before' => $before,
                    'balance_after' =>
                        $wallet->available_balance,

                    'reference_type' => 'withdrawal',
                    'reference_id' =>
                        $withdrawal->id,

                    'description' =>
                        'Withdrawal request #' .
                        $withdrawal->id,

                    'status' => 'pending',

                    'created_by' => auth()->id(),
                ]);
            });

        } catch (\Exception $e) {

            return redirect()
                ->route('finance.index')
                ->with(
                    'error',
                    $e->getMessage()
                );
        }

        return redirect()
            ->route('finance.index')
            ->with(
                'success',
                __('Withdrawal request submitted successfully.')
            );
    }


    public function withdrawals()
    {
        if (!$this->canFinance('View Finance Withdrawal Requests')) {
            return redirect()->route('finance.index')->with('error', __('Permission denied.'));
        }

        $storeId = getCurrentStore();

        /*
         * Same guard and the same reasoning as index(), which carries the full account. THIS IS
         * THE ONE HANDLER WHOSE BEHAVIOUR ACTUALLY CHANGES, and the reason to change it is
         * already written a few hundred lines above: `store_withdrawals.store_id` is NOT NULL, so
         * the `store_id IS NULL` predicate matched nothing and the query answered an EMPTY LIST --
         * "the worst possible wrong answer on this page: it is the one that makes a reviewer stop
         * looking" (unreconciledCaptures). A storeless caller now gets a refusal that names the
         * condition they can act on.
         *
         * AND A CORRECTION TO THE REVIEW THIS CAME FROM, which described the pre-guard answer as
         * `200` with that empty table. The QUERY returned nothing, but THE PAGE never reached 200:
         * `partision/header.blade.php` resolves the current store and hands it to
         * `HomeController::getThemeUrl()`, which reads `$store->slug` -- null here -- so the real
         * answer was a 500 out of the shared chrome. That chrome defect is not finance's and is
         * reported separately; it affects every merchant screen a storeless principal opens.
         * Refusing here is right under either reading, and both are strictly worse than a
         * refusal.
         */
        if (empty($storeId)) {
            return redirect()
                ->route('finance.index')
                ->with('error', __('No store is selected.'));
        }

        $withdrawals = StoreWithdrawal::with(
                'wallet'
            )
            ->where('store_id', $storeId)
            ->orderByDesc('id')
            ->paginate(25);

        return view(
            'finance.withdrawals',
            compact('withdrawals')
        );
    }


    public function approveWithdrawal($id)
    {
        // ALL of WITHDRAWAL_REVIEW_PERMISSIONS, not just the view permission.
        // See the constant for the evidence; the route-level guard in
        // routes/web.php is built from the same constant.
        if (!$this->canFinanceAll(self::WITHDRAWAL_REVIEW_PERMISSIONS)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $storeId = $this->financeStoreId();

        if ($storeId === null) {
            return redirect()->back()->with('error', __('No store selected.'));
        }

        /*
         * OWNER DECISION 7. Approving a withdrawal is the clearest reading of the decision's
         * *"approve a payment"*: it moves the withdrawal to `approved` and completes the
         * `withdrawal_request` ledger row, which is what makes it payable.
         *
         * `financeStoreId()` is NOT a second control against this principal: it is
         * `getCurrentStore()` with `empty()` mapped to null, so it answers 1 for an operator who
         * chose nothing and `=== null` is false. The existing
         * `->where('store_id', $storeId)` predicate is likewise no help -- it faithfully scopes to
         * the FALLBACK tenant. `back()` is this handler's own refusal shape, and a refused POST's
         * `back()` resolves to the last GET, so it cannot loop.
         */
        if ($this->operatorHasChosenNoStore()) {
            return $this->refuseUnchosenOperatorStore(
                'approveWithdrawal',
                redirect()->back()
            );
        }

        try {

            DB::transaction(function () use (
                $id,
                $storeId
            ) {

                $withdrawal =
                    StoreWithdrawal::where(
                        'id',
                        $id
                    )
                    ->where(
                        'store_id',
                        $storeId
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $withdrawal->status !== 'pending'
                ) {
                    throw new \Exception(
                        'Withdrawal request is not pending.'
                    );
                }

                $withdrawal->status = 'approved';
                $withdrawal->reviewed_by =
                    auth()->id();
                $withdrawal->reviewed_at = now();

                $withdrawal->save();

                StoreWalletTransaction::where(
                        'store_id',
                        $storeId
                    )
                    ->where(
                        'reference_type',
                        'withdrawal'
                    )
                    ->where(
                        'reference_id',
                        $withdrawal->id
                    )
                    ->where(
                        'type',
                        'withdrawal_request'
                    )
                    ->update([
                        'status' => 'completed',
                        'updated_at' => now(),
                    ]);
            });

        } catch (\Exception $e) {

            return back()->with(
                'error',
                $e->getMessage()
            );
        }

        return back()->with(
            'success',
            __('Withdrawal approved.')
        );
    }


    public function rejectWithdrawal(
        Request $request,
        $id
    ) {
        // ALL of WITHDRAWAL_REVIEW_PERMISSIONS, not just the view permission.
        // See the constant for the evidence; the route-level guard in
        // routes/web.php is built from the same constant.
        if (!$this->canFinanceAll(self::WITHDRAWAL_REVIEW_PERMISSIONS)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $storeId = $this->financeStoreId();

        if ($storeId === null) {
            return redirect()->back()->with('error', __('No store selected.'));
        }

        /*
         * OWNER DECISION 7. A rejection RESTORES the amount to the tenant's `available_balance`
         * and releases its `withdrawal_pending_balance`, so it moves money just as surely as an
         * approval does.
         *
         * THIS IS THE ONLY ONE OF THE EIGHT PLACED BEFORE ITS VALIDATOR, and an earlier draft of
         * this comment claimed the opposite -- "for the same reason the other seven sit as early
         * as they do". MEASURED: `updateFinancialPaymentStatus` (:365 before :391), `addBalance`
         * (:953 before :984), `deductBalance` (:1090 before :1110) and `requestWithdrawal` (:1227
         * before :1264) all validate FIRST; `index`, `approveWithdrawal` and `payWithdrawal` have
         * no validator at all. The ordering here is still deliberate -- a refusal that cannot name
         * the tenant should not first argue about the shape of an admin note -- but it is a
         * difference from its siblings rather than a symmetry with them. The one observable
         * consequence: a refused caller who posts an over-500-character `admin_note` now sees the
         * store refusal instead of a validation error.
         */
        if ($this->operatorHasChosenNoStore()) {
            return $this->refuseUnchosenOperatorStore(
                'rejectWithdrawal',
                redirect()->back()
            );
        }

        $request->validate([
            'admin_note' =>
                'nullable|string|max:500',
        ]);

        try {

            DB::transaction(function () use (
                $request,
                $id,
                $storeId
            ) {

                $withdrawal =
                    StoreWithdrawal::where(
                        'id',
                        $id
                    )
                    ->where(
                        'store_id',
                        $storeId
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    !in_array(
                        $withdrawal->status,
                        ['pending', 'approved']
                    )
                ) {
                    throw new \Exception(
                        'This withdrawal cannot be rejected.'
                    );
                }

                $wallet = StoreWallet::where(
                        'id',
                        $withdrawal->wallet_id
                    )
                    ->where(
                        'store_id',
                        $storeId
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                $currencyCode =
                    $wallet->currency_code
                    ?? 'USD';

                $amount =
                    (float) $withdrawal->amount;

                if (
                    (float) $wallet
                        ->withdrawal_pending_balance
                    < $amount
                ) {
                    throw new \Exception(
                        'Invalid withdrawal pending balance.'
                    );
                }

                $before =
                    (float) $wallet->available_balance;

                $wallet
                    ->withdrawal_pending_balance -=
                    $amount;

                $wallet->available_balance +=
                    $amount;

                $wallet->save();

                $withdrawal->status = 'rejected';

                $withdrawal->admin_note =
                    $request->admin_note;

                $withdrawal->reviewed_by =
                    auth()->id();

                $withdrawal->reviewed_at = now();

                $withdrawal->save();

                StoreWalletTransaction::create([
                    'store_id' =>
                        $withdrawal->store_id,

                    'wallet_id' =>
                        $wallet->id,

                    'currency_code' =>
                        $currencyCode,

                    'type' =>
                        'withdrawal_refund',

                    'direction' => 'credit',

                    'amount' => $amount,

                    'balance_before' => $before,

                    'balance_after' =>
                        $wallet->available_balance,

                    'reference_type' =>
                        'withdrawal',

                    'reference_id' =>
                        $withdrawal->id,

                    'description' =>
                        'Rejected withdrawal #' .
                        $withdrawal->id .
                        ' returned to wallet',

                    'status' => 'completed',

                    'created_by' => auth()->id(),
                ]);

                StoreWalletTransaction::where(
                        'store_id',
                        $storeId
                    )
                    ->where(
                        'reference_type',
                        'withdrawal'
                    )
                    ->where(
                        'reference_id',
                        $withdrawal->id
                    )
                    ->where(
                        'type',
                        'withdrawal_request'
                    )
                    ->update([
                        'status' => 'cancelled',
                        'updated_at' => now(),
                    ]);
            });

        } catch (\Exception $e) {

            return back()->with(
                'error',
                $e->getMessage()
            );
        }

        return back()->with(
            'success',
            __('Withdrawal rejected and balance returned.')
        );
    }


    public function payWithdrawal($id)
    {
        // ALL of WITHDRAWAL_REVIEW_PERMISSIONS, not just the view permission.
        // See the constant for the evidence; the route-level guard in
        // routes/web.php is built from the same constant.
        if (!$this->canFinanceAll(self::WITHDRAWAL_REVIEW_PERMISSIONS)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $storeId = $this->financeStoreId();

        if ($storeId === null) {
            return redirect()->back()->with('error', __('No store selected.'));
        }

        /*
         * OWNER DECISION 7. This is the handler that actually pays money OUT: it draws the
         * tenant's `withdrawal_pending_balance` down and writes the `withdrawal_paid` ledger row.
         * The last place an unchosen tenant should be acceptable.
         */
        if ($this->operatorHasChosenNoStore()) {
            return $this->refuseUnchosenOperatorStore(
                'payWithdrawal',
                redirect()->back()
            );
        }

        try {

            DB::transaction(function () use (
                $id,
                $storeId
            ) {

                $withdrawal =
                    StoreWithdrawal::where(
                        'id',
                        $id
                    )
                    ->where(
                        'store_id',
                        $storeId
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                if (
                    $withdrawal->status !== 'approved'
                ) {
                    throw new \Exception(
                        'Withdrawal must be approved first.'
                    );
                }

                $wallet = StoreWallet::where(
                        'id',
                        $withdrawal->wallet_id
                    )
                    ->where(
                        'store_id',
                        $storeId
                    )
                    ->lockForUpdate()
                    ->firstOrFail();

                $currencyCode =
                    $wallet->currency_code
                    ?? 'USD';

                $amount =
                    (float) $withdrawal->amount;

                if (
                    (float) $wallet
                        ->withdrawal_pending_balance
                    < $amount
                ) {
                    throw new \Exception(
                        'Invalid withdrawal pending balance.'
                    );
                }

                $wallet
                    ->withdrawal_pending_balance -=
                    $amount;

                $wallet->total_withdrawn =
                    (float) $wallet->total_withdrawn
                    + $amount;

                $wallet->save();

                $withdrawal->status = 'paid';
                $withdrawal->paid_at = now();

                $withdrawal->save();

                StoreWalletTransaction::create([
                    'store_id' =>
                        $withdrawal->store_id,

                    'wallet_id' =>
                        $wallet->id,

                    'currency_code' =>
                        $currencyCode,

                    'type' => 'withdrawal_paid',

                    'direction' => 'debit',

                    'amount' => $amount,

                    /*
                     * available balance لم يتغير هون
                     * لأنه انحجز من وقت الطلب.
                     */
                    'balance_before' =>
                        $wallet->available_balance,

                    'balance_after' =>
                        $wallet->available_balance,

                    'reference_type' =>
                        'withdrawal',

                    'reference_id' =>
                        $withdrawal->id,

                    'description' =>
                        'Withdrawal #' .
                        $withdrawal->id .
                        ' paid',

                    'status' => 'completed',

                    'created_by' => auth()->id(),
                ]);
            });

        } catch (\Exception $e) {

            return back()->with(
                'error',
                $e->getMessage()
            );
        }

        return back()->with(
            'success',
            __('Withdrawal marked as paid.')
        );
    }
    
    ///api
    
    
    
public function walletTransactionsApi(Request $request)
{
    try {
        $adminUser = auth()->user();
        if (!$adminUser) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Unauthorized. Valid token is required.']
            ], 401);
        }

        // /api/admin/wallet/transactions reaches this method through
        // `custom.auth` only -- api.php applies no permission middleware -- so
        // before this check any authenticated token holder could read any
        // store's wallet ledger that they owned. The ownership test below
        // bounded WHICH store; nothing bounded WHETHER the caller may read
        // wallet data at all.
        if (!$this->canFinance('View Finance Dashboard')) {
            return $this->financeApiDenied(
                'Permission denied. You do not have permission to view wallet transactions.'
            );
        }

        $storeId = $request->query('store_id');

        \Validator::make(['store_id' => $storeId], [
            'store_id' => 'required|integer|exists:stores,id'
        ])->validate();

        $store = \App\Models\Store::where('id', $storeId)
            ->where('created_by', $adminUser->id)
            ->first();

        if (!$store && $adminUser->type !== 'super admin') {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'You do not have permission to access this store.']
            ], 403);
        }

        $wallet = \App\Models\StoreWallet::where('store_id', $storeId)->first();

        if (!$wallet) {
            $wallet = \App\Models\StoreWallet::create([
                'store_id' => $storeId,
                'currency_code' => 'USD',
                'available_balance' => 0,
                'pending_balance' => 0,
                'withdrawal_pending_balance' => 0,
                'total_income' => 0,
                'total_withdrawn' => 0,
            ]);
        }

        $currencyCode = $wallet->currency_code ?? 'USD';

        $currencyRate = \App\Models\CurrencyRate::where('store_id', $storeId)
            ->where('currency_code', $currencyCode)
            ->where('is_active', 1)
            ->first();

        $currencySymbol = $currencyRate ? $currencyRate->symbol : ($currencyCode === 'USD' ? '$' : $currencyCode);

        $query = \App\Models\StoreWalletTransaction::where('store_id', $storeId);

        if ($request->filled('search_field') && $request->filled('search_value')) {
            $field = $request->search_field;
            $value = $request->search_value;

            switch ($field) {
                case 'id':
                    $query->where('id', $value);
                    break;

                case 'type':
                    $query->where('type', $value);
                    break;

                case 'description':
                    $query->where('description', 'LIKE', "%{$value}%");
                    break;

                case 'status':
                    $query->where('status', $value);
                    break;
            }
        }

        if ($request->filled('from_date')) {
            $validator = \Validator::make(['from_date' => $request->from_date], [
                'from_date' => 'required|date|date_format:Y-m-d'
            ]);
            
            if (!$validator->fails()) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }
        }

        if ($request->filled('to_date')) {
            $validator = \Validator::make(['to_date' => $request->to_date], [
                'to_date' => 'required|date|date_format:Y-m-d'
            ]);
            
            if (!$validator->fails()) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $transactions = $query
            ->orderByDesc('id')
            ->paginate($request->per_page ?? 25);

        return response()->json([
            'status' => 1,
            'message' => 'Wallet data retrieved successfully',
            'data' => [
                'store_id' => (int) $storeId,
                'available_balance' => number_format((float) $wallet->available_balance, 2),
                'currency_code' => $currencyCode,          // ✅ كود العملة الحالي
                'currency_symbol' => $currencySymbol,      // ✅ رمز العملة الديناميكي والصحيح
                'filters_applied' => [
                    'from_date' => $request->from_date ?? null,
                    'to_date' => $request->to_date ?? null,
                    'type' => $request->type ?? null,
                    'search_field' => $request->search_field ?? null,
                    'search_value' => $request->search_value ?? null,
                ],
                'transactions' => $transactions->map(function ($transaction) {
                    return [
                        'id' => $transaction->id,
                        'type' => $transaction->type,
                        'direction' => $transaction->direction,
                                                'currency_code' => $transaction->currency_code,
                        'amount' => number_format((float) $transaction->amount, 2),
                        'balance_before' => number_format((float) $transaction->balance_before, 2),
                        'balance_after' => number_format((float) $transaction->balance_after, 2),
                        'description' => $transaction->description,
                        'status' => $transaction->status,
                        'created_at' => $transaction->created_at->toDateTimeString(),
                    ];
                }),
                'pagination' => [
                    'total' => $transactions->total(),
                    'per_page' => $transactions->perPage(),
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'has_more' => $transactions->hasMorePages(),
                ],
            ]
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ]
        ], 422);

    } catch (\Exception $e) {
        \Log::error('API Wallet Transactions Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());

        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Failed to retrieve wallet data: ' . $e->getMessage()
            ]
        ], 500);
    }
}    
    
    public function addBalanceApi(Request $request)
{
    try {
        $adminUser = auth()->user();
        if (!$adminUser) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Unauthorized. Valid token is required.']
            ], 401);
        }

        if (!$this->canFinance('Add Finance Balance')) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Permission denied. You do not have permission to add balance.']
            ], 403);
        }

        $storeId = $request->query('store_id');

        \Validator::make(['store_id' => $storeId], [
            'store_id' => 'required|integer|exists:stores,id'
        ])->validate();

        $store = \App\Models\Store::where('id', $storeId)
            ->where('created_by', $adminUser->id)
            ->first();

        if (!$store && $adminUser->type !== 'super admin') {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'You do not have permission to access this store.']
            ], 403);
        }

        \Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:255',
        ])->validate();


        /*
         * F270. THE API TWIN CARRIES THE SAME DEFECT, and leaving it would be half a fix: the
         * shipped Flutter admin posts here (`wallet_repository.dart:28-43`), and its submit
         * button has no in-flight guard either.
         *
         * THE CLAIM IS TAKEN AFTER THE OWNERSHIP CHECK, DELIBERATELY. `$storeId` arrives as
         * `$request->query('store_id')` — caller-supplied — and the block above is what ties it
         * to this caller. Claiming before that check would let any authenticated caller name
         * ANOTHER store's id and evict or block its claim, which is this codebase's standing
         * rule: a predicate built from caller input is not a control until it has been checked.
         *
         * THE REFUSAL IS A 409 IN THE API'S OWN ENVELOPE. The Flutter client's error parser
         * prefers `data.message` (`network.dart:242-246`), so the refusal text reaches the
         * merchant's toast through the existing `onFailure` branch with no app change; 409 falls
         * into that parser's `default` arm, so it neither logs the merchant out (401) nor arms
         * the rate-limit backoff (429).
         */
        $walletClaim = $this->walletChangeFingerprint(
            'credit',
            $storeId,
            (int) $adminUser->id,
            $request->amount,
            $request->description
        );

        if (! Cache::add($walletClaim, self::WALLET_CHANGE_WRITING, now()->addSeconds(self::WALLET_CHANGE_CLAIM_SECONDS))) {
            Log::warning('Manual wallet change refused as a duplicate submission', [
                'direction' => 'credit',
                'store_id' => $storeId,
                'user_id' => $adminUser->id,
            ]);

            return response()->json([
                'status' => 0,
                'message' => __('A wallet change with the same amount and description was recorded a moment ago. This submission was ignored, so the balance has not been changed twice.'),
                'data' => ['message' => __('A wallet change with the same amount and description was recorded a moment ago. This submission was ignored, so the balance has not been changed twice.')]
            ], 409);
        }

        $result = DB::transaction(function () use ($request, $storeId, $adminUser) {
            
            $wallet = \App\Models\StoreWallet::where('store_id', $storeId)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                $currencyCode = \App\Models\Setting::where('store_id', $storeId)
                    ->where('name', 'defult_currancy')
                    ->value('value') ?? 'USD';

                $wallet = \App\Models\StoreWallet::create([
                    'store_id' => $storeId,
                    'currency_code' => $currencyCode,
                    'available_balance' => 0,
                    'pending_balance' => 0,
                    'withdrawal_pending_balance' => 0,
                    'total_income' => 0,
                    'total_withdrawn' => 0,
                ]);
            }

            $currencyCode = $wallet->currency_code ?? 'USD';
            $before = (float) $wallet->available_balance;
            $amount = (float) $request->amount;
            $after = $before + $amount;

            $wallet->available_balance = $after;
            $wallet->total_income = (float) $wallet->total_income + $amount;
            $wallet->save();

            $transaction = \App\Models\StoreWalletTransaction::create([
                'store_id' => $storeId,
                'wallet_id' => $wallet->id,
                'currency_code' => $currencyCode,
                'type' => 'manual_deposit',
                'direction' => 'credit',
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'reference_type' => 'manual',
                'reference_id' => null,
                'description' => $request->description,
                'status' => 'completed',
                'created_by' => $adminUser->id,
            ]);

            return [
                'wallet' => $wallet,
                'transaction' => $transaction,
            ];
        });

        return response()->json([
            'status' => 1,
            'message' => 'Balance added successfully',
            'data' => [
                'store_id' => (int) $storeId,
                'wallet' => [
                    'id' => $result['wallet']->id,
                    'available_balance' => number_format((float) $result['wallet']->available_balance, 2),
                    'total_income' => number_format((float) $result['wallet']->total_income, 2),
                    'currency_code' => $result['wallet']->currency_code ?? 'USD',
                ],
                'transaction' => [
                    'id' => $result['transaction']->id,
                    'type' => $result['transaction']->type,
                    'direction' => $result['transaction']->direction,
                    'amount' => number_format((float) $result['transaction']->amount, 2),
                    'balance_before' => number_format((float) $result['transaction']->balance_before, 2),
                    'balance_after' => number_format((float) $result['transaction']->balance_after, 2),
                    'description' => $result['transaction']->description,
                    'status' => $result['transaction']->status,
                    'created_at' => $result['transaction']->created_at->toDateTimeString(),
                ],
            ]
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ]
        ], 422);

    } catch (\Exception $e) {

        /*
         * F270. The transaction rolled back, so nothing was written and the claim must not hold.
         * Released ONLY here — never on a path that wrote something, which is what stops a retry
         * after a successful write from changing the balance twice.
         *
         * `isset` BECAUSE THIS CATCH IS WIDER THAN THE CLAIM. It also catches a throw from the
         * store lookup ABOVE the claim — `QueryException` extends `Exception` — and at that point
         * `$walletClaim` does not exist yet. Forgetting an undefined variable would turn a
         * database error into a second, misleading one.
         */
        if (isset($walletClaim)) {
            Cache::forget($walletClaim);
        }

        \Log::error('API Add Balance Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());

        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Failed to add balance: ' . $e->getMessage()
            ]
        ], 500);
    }
}
public function deductBalanceApi(Request $request)
{
    try {
        $adminUser = auth()->user();
        if (!$adminUser) {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'Unauthorized. Valid token is required.']
            ], 401);
        }

        // THE EMPTY GAP. addBalanceApi checks 'Add Finance Balance' at the
        // equivalent point; these three blank lines are where the matching
        // check for the DEDUCT endpoint should always have been. Deducting is
        // the more dangerous of the two -- it removes merchant funds -- and it
        // was the one left unguarded.
        if (!$this->canFinance('Deduct Finance Balance')) {
            return $this->financeApiDenied(
                'Permission denied. You do not have permission to deduct balance.'
            );
        }

        $storeId = $request->query('store_id');

        \Validator::make(['store_id' => $storeId], [
            'store_id' => 'required|integer|exists:stores,id'
        ])->validate();

        $store = \App\Models\Store::where('id', $storeId)
            ->where('created_by', $adminUser->id)
            ->first();

        if (!$store && $adminUser->type !== 'super admin') {
            return response()->json([
                'status' => 0,
                'message' => 'fail',
                'data' => ['message' => 'You do not have permission to access this store.']
            ], 403);
        }

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:255',
        ]);


        /*
         * F270. THE API TWIN CARRIES THE SAME DEFECT, and leaving it would be half a fix: the
         * shipped Flutter admin posts here (`wallet_repository.dart:28-43`), and its submit
         * button has no in-flight guard either.
         *
         * THE CLAIM IS TAKEN AFTER THE OWNERSHIP CHECK, DELIBERATELY. `$storeId` arrives as
         * `$request->query('store_id')` — caller-supplied — and the block above is what ties it
         * to this caller. Claiming before that check would let any authenticated caller name
         * ANOTHER store's id and evict or block its claim, which is this codebase's standing
         * rule: a predicate built from caller input is not a control until it has been checked.
         *
         * THE REFUSAL IS A 409 IN THE API'S OWN ENVELOPE. The Flutter client's error parser
         * prefers `data.message` (`network.dart:242-246`), so the refusal text reaches the
         * merchant's toast through the existing `onFailure` branch with no app change; 409 falls
         * into that parser's `default` arm, so it neither logs the merchant out (401) nor arms
         * the rate-limit backoff (429).
         */
        $walletClaim = $this->walletChangeFingerprint(
            'debit',
            $storeId,
            (int) $adminUser->id,
            $request->amount,
            $request->description
        );

        if (! Cache::add($walletClaim, self::WALLET_CHANGE_WRITING, now()->addSeconds(self::WALLET_CHANGE_CLAIM_SECONDS))) {
            Log::warning('Manual wallet change refused as a duplicate submission', [
                'direction' => 'debit',
                'store_id' => $storeId,
                'user_id' => $adminUser->id,
            ]);

            return response()->json([
                'status' => 0,
                'message' => __('A wallet change with the same amount and description was recorded a moment ago. This submission was ignored, so the balance has not been changed twice.'),
                'data' => ['message' => __('A wallet change with the same amount and description was recorded a moment ago. This submission was ignored, so the balance has not been changed twice.')]
            ], 409);
        }

        $result = DB::transaction(function () use ($request, $storeId, $adminUser) {
            
            $wallet = \App\Models\StoreWallet::where('store_id', $storeId)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                throw new \Exception(__('Wallet not found for this store.'));
            }

            $currencyCode = $wallet->currency_code ?? 'USD';
            $before = (float) $wallet->available_balance;
            $amount = (float) $request->amount;

            if ($amount > $before) {
                throw new \Exception(__('Insufficient wallet balance.'));
            }

            $after = $before - $amount;

            $wallet->available_balance = $after;
            $wallet->total_withdrawn = (float) $wallet->total_withdrawn + $amount;
            $wallet->save();

            $transaction = \App\Models\StoreWalletTransaction::create([
                'store_id' => $storeId,
                'wallet_id' => $wallet->id,
                'currency_code' => $currencyCode,
                'type' => 'manual_withdrawal',
                'direction' => 'debit',
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'reference_type' => 'manual',
                'reference_id' => null,
                'description' => $request->description,
                'status' => 'completed',
                'created_by' => $adminUser->id,
            ]);

            return [
                'wallet' => $wallet,
                'transaction' => $transaction,
            ];
        });

        return response()->json([
            'status' => 1,
            'message' => 'Balance deducted successfully',
            'data' => [
                'store_id' => (int) $storeId,
                'wallet' => [
                    'id' => $result['wallet']->id,
                    'available_balance' => number_format((float) $result['wallet']->available_balance, 2),
                    'total_withdrawn' => number_format((float) $result['wallet']->total_withdrawn, 2),
                    'currency_code' => $result['wallet']->currency_code ?? 'USD',
                ],
                'transaction' => [
                    'id' => $result['transaction']->id,
                    'type' => $result['transaction']->type,
                    'direction' => $result['transaction']->direction,
                    'amount' => number_format((float) $result['transaction']->amount, 2),
                    'balance_before' => number_format((float) $result['transaction']->balance_before, 2),
                    'balance_after' => number_format((float) $result['transaction']->balance_after, 2),
                    'description' => $result['transaction']->description,
                    'status' => $result['transaction']->status,
                    'created_at' => $result['transaction']->created_at->toDateTimeString(),
                ],
            ]
        ], 200);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ]
        ], 422);

    } catch (\Exception $e) {

        /*
         * F270. The transaction rolled back, so nothing was written and the claim must not hold.
         * Released ONLY here — never on a path that wrote something, which is what stops a retry
         * after a successful write from changing the balance twice.
         *
         * `isset` BECAUSE THIS CATCH IS WIDER THAN THE CLAIM. It also catches a throw from the
         * store lookup ABOVE the claim — `QueryException` extends `Exception` — and at that point
         * `$walletClaim` does not exist yet. Forgetting an undefined variable would turn a
         * database error into a second, misleading one.
         */
        if (isset($walletClaim)) {
            Cache::forget($walletClaim);
        }

        \Log::error('API Deduct Balance Error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ' | Line: ' . $e->getLine());

        return response()->json([
            'status' => 0,
            'message' => 'fail',
            'data' => [
                'message' => 'Failed to deduct balance: ' . $e->getMessage()
            ]
        ], 500);
    }
}}