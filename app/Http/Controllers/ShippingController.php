<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Shipping;
use App\Models\ShippingZone;
use App\Models\ShippingMethod;
use App\DataTables\ShippingDataTable;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Log;

/**
 * F263 (1 of 4) — SHIPPING CLASSES WERE EDITABLE AND DELETABLE ACROSS EVERY TENANT.
 * =================================================================================================
 *
 * `edit`, `update` and `destroy` each resolved their row with a bare `Shipping::find($id)`.
 * `Shipping` registers no global scope and is **explicitly excluded** from
 * `config/tenancy.php`'s `scoped_models` — `TenantModelClassificationTest` forbids its addition —
 * so that `find()` reaches every store's row. A merchant holding `Edit Shipping Class` in their own
 * tenant could therefore rename, re-describe or DELETE another merchant's shipping class by
 * posting its id, and the victim's class simply vanished from their settings.
 *
 * The listing was never the hole: `ShippingDataTable::query()` already carries
 * `where('store_id', getCurrentStore())`. It is the three id-addressed handlers that had nothing.
 *
 * THIS IS F258's DEFECT ONE ROUTE OVER, and the fix is deliberately the same two controls rather
 * than a new idea — see `ShippingMethodController`'s docblock for the reasoning in full:
 *
 *   [permission] who may act at all. `edit` had **no check of any kind**, so a staff member whose
 *                role grants only Manage Blog could open the form and read another store's
 *                shipping classes. `update` and `destroy` were gated but unscoped.
 *   [tenancy]    which rows they may act ON. `scopedShipping()` is the only thing between a route
 *                parameter and another tenant's row.
 *
 * ON THE OPERATOR, and this is the one place the shape differs from F258 — because PRESERVING what
 * exists is worth more than symmetry:
 *
 *   - the three WRITES refuse the operator, which is what they already did. A bare `isAbleTo()`
 *     refuses the platform operator (Laratrust has no super-admin bypass and no role holds these
 *     on a database built from source), so the refusal is not new. It is made EXPLICIT so it stops
 *     being an accident that the next person "fixes" by adding an operator limb for consistency —
 *     which would hand the operator every merchant's shipping classes. Same reasoning as F258, and
 *     consistent with the coupon precedent (F157, owner 2026-10-28: the operator reads and
 *     exports, it does not rewrite a merchant's commercial settings).
 *
 * **AND THE "READ RIGHTS ONLY" CLAIM IS TRUE OF THESE DOORS, NOT OF THE PLATFORM.** Found by
 * the mandated independent review of this change, and worth stating because the sentence above
 * reads wider than it is: `App\Http\Middleware\BulkDeleteTenantGuard` lets the operator
 * through for every type whose model is not `Product` — it returns `$next($request)` before the
 * permission map, the model map and the tenant predicate — and its `MODEL_MAP` carries
 * `'shipping' => Shipping::class`, `'tax-method' => TaxMethod::class` and
 * `'shipping-taxmethod' => ShippingMethod::class`. So the operator can still BULK-DELETE the
 * very rows these single-row gates refuse them.
 *
 * That bypass is the owner's own F206 decision — narrowed to the catalogue precisely because
 * *"do not change other platform-administration functions unrelated to the merchant's
 * catalogue"* — so it is authorised, and narrowing it further would be a behaviour change F263
 * did not ask for and nobody approved. It is recorded here rather than acted on, so that the
 * next reader does not take this gate as a platform-wide guarantee it was never able to make.
 *   - the edit FORM admits the operator, because with no check at all it admitted everybody
 *     including the operator. Adding a gate that refused them would take away a read they have
 *     today, which is an unstated second decision riding on a security fix — the exact mistake an
 *     independent review caught in the first F258 draft.
 *
 * `index` IS LEFT EXACTLY AS IT IS, and that is a choice rather than an oversight. Its bare
 * `isAbleTo('Manage Shipping Class')` refuses the operator, so an operator-first limb there would
 * GRANT a read that does not exist today — a behaviour change F263 did not ask for. Noted so the
 * asymmetry between `index` and `edit` reads as deliberate.
 *
 * THE PERMISSION NAMES ARE THE SEEDED ONES THIS CONTROLLER ALREADY USED — `Manage/Create/Edit/
 * Delete Shipping Class` (`Utility.php`) — so no name is invented here. `edit` takes the same name
 * as its write, which is this codebase's convention for an edit form (`PlanCouponController:30-31`
 * states it).
 */
class ShippingController extends Controller
{
    /** The seeded permission the edit form and the update share. */
    private const PERMISSION_EDIT = 'Edit Shipping Class';

    /**
     * The store must RESOLVE before any predicate is built from it.
     *
     * `getCurrentStore()` returns null for a principal with no `current_store` and no store of
     * their own, and `where('store_id', null)` compiles to `IS NULL` — which matches any ORPHANED
     * row rather than nothing. Copied from `ShippingMethodController` for that reason.
     *
     * @return \Illuminate\Http\RedirectResponse|null
     */
    private function storeMustResolve()
    {
        if (getStoreById(getCurrentStore()) === null) {
            return redirect()->back()->with('error', __('No store is selected.'));
        }

        return null;
    }

    /**
     * Who may OPEN the shipping-class edit form. Null means permitted.
     *
     * Admits the operator — see the class docblock on why this one does.
     *
     * @return \Illuminate\Http\RedirectResponse|null
     */
    private function shippingReadRefusal()
    {
        $user = auth()->user();

        if (! $user) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $isOperator = UserPolicy::isOperator($user);

        if (! $isOperator && ! $user->isAbleTo(self::PERMISSION_EDIT)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        /*
         * `getCurrentStore()` hands an operator with no `current_store` the hardcoded literal 1,
         * and `getStoreById(1)` normally resolves — so without this the operator would be served
         * store 1's shipping classes by default. The F258 read gate carries the same guard.
         */
        if ($isOperator && empty($user->current_store)) {
            return redirect()->back()->with('error', __('No store is selected.'));
        }

        return $this->storeMustResolve();
    }

    /**
     * Who may WRITE a shipping class. Null means permitted.
     *
     * Refuses the operator EXPLICITLY, which is what a bare `isAbleTo()` already did by accident.
     *
     * @return \Illuminate\Http\RedirectResponse|null
     */
    private function shippingWriteRefusal(string $permission)
    {
        $user = auth()->user();

        if (! $user) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (UserPolicy::isOperator($user)) {
            Log::warning('shipping class write refused: the platform operator has read rights only', [
                'user_id' => $user->id,
                'current_store' => getCurrentStore(),
                'permission' => $permission,
            ]);

            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (! $user->isAbleTo($permission)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return $this->storeMustResolve();
    }

    /**
     * This store's shipping class, or null.
     *
     * `Shipping` registers no global scope and is excluded from `scoped_models`, so this predicate
     * is the ONLY thing standing between a route parameter and another tenant's row.
     */
    private function scopedShipping($id): ?Shipping
    {
        $storeId = getCurrentStore();

        if (empty($storeId)) {
            return null;
        }

        return Shipping::where('store_id', $storeId)->find($id);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(ShippingDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Shipping Class'))
        {
            /*
             * THE STORE MUST RESOLVE HERE TOO, AND THIS IS THE SAME TRAP ONE METHOD DOWN.
             *
             * `ShippingDataTable::query()` is `$model->where('store_id', getCurrentStore())`, and
             * Laravel converts a NULL value to `whereNull` — so for a principal with no
             * `current_store` and no store of their own, the list rendered every ORPHANED shipping
             * class (`shippings.store_id` is nullable, and the pre-F263 `store()` wrote an
             * unguarded `getCurrentStore()`). That is exactly what `storeMustResolve()` exists for,
             * and `index` was the one path still missing it. Found by the mandated independent
             * review of this change.
             *
             * It shows orphans, not another tenant's rows, so it is low severity — and the fix is
             * taken because it only ever REFUSES. The reason `index` keeps its bare `isAbleTo()`
             * is that an operator-first limb would GRANT the operator a read they do not have
             * today; a store check grants nothing, so it does not reopen that decision.
             */
            if ($refusal = $this->storeMustResolve()) {
                return $refusal;
            }

            return  $dataTable->render('shipping.index');
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('shipping.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if ($refusal = $this->shippingWriteRefusal('Create Shipping Class')) {
            return $refusal;
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'name' => 'required',
                'description' => 'required',
            ]
        );
        if($validator->fails())
        {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }
        $slug = str_replace(' ', '-', strtolower($request->name));
        $shipping                   = new Shipping();
        $shipping->name             = $request->name;
        $shipping->slug             = $slug;
        $shipping->description      = $request->description;
        $shipping->store_id         = getCurrentStore();
        $shipping->save();
        return redirect()->back()->with('success', __('Shipping successfully created.'));
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        if ($refusal = $this->shippingReadRefusal()) {
            return $refusal;
        }

        $shipping = $this->scopedShipping($id);

        if ($shipping === null) {
            return redirect()->back()->with('error', __('Shipping not found.'));
        }

        return view('shipping.edit', compact('shipping'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {

        if ($refusal = $this->shippingWriteRefusal(self::PERMISSION_EDIT)) {
            return $refusal;
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'name' => 'required',
                'description' => 'required',
            ]
        );
        if($validator->fails())
        {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }

        $shipping = $this->scopedShipping($id);

        if ($shipping === null) {
            return redirect()->back()->with('error', __('Shipping not found.'));
        }

        $slug = str_replace(' ', '-', strtolower($request->name));
        $shipping->name         = $request->name;
        $shipping->slug         = $slug;
        $shipping->description  = $request->description;

        /*
         * `store_id` IS DELIBERATELY NOT REASSIGNED. The row was found BY this store's id, so
         * writing it back would be a no-op on the happy path and a row THEFT on the unhappy
         * one — which is exactly what F258's three writes did (`$x->store_id =
         * getCurrentStore()` after an unscoped find). Leaving it alone keeps the predicate the
         * single source of truth about which row this is.
         */
        $shipping->save();
        return redirect()->back()->with('success', __('Shipping successfully updated.'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {

        if ($refusal = $this->shippingWriteRefusal('Delete Shipping Class')) {
            return $refusal;
        }

        $shipping = $this->scopedShipping($id);

        if ($shipping === null) {
            return redirect()->back()->with('error', __('Shipping not found.'));
        }

        $shipping->delete();
        return redirect()->back()->with('success', __('Shipping delete successfully.'));
    }
}
