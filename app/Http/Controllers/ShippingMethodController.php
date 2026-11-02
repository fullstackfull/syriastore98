<?php

namespace App\Http\Controllers;

use App\Models\ShippingMethod;
use App\Policies\UserPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\ShippingZone;
use App\Models\Shipping;

/**
 * F258 — THIS CONTROLLER HAD NO AUTHORIZATION OF ANY KIND, AND ITS WRITES STOLE ROWS.
 * =================================================================================================
 *
 * Measured before anything was changed: `grep -c 'isAbleTo\|authorize(\|permission'` over all 155
 * lines returned **0**. Six handlers are reachable — `edit`, `update`, `freeShippingEdit`,
 * `freeShippingUpdate`, `localShippingEdit`, `localShippingUpdate` — and every one of them
 * resolved its row with a bare `ShippingMethod::find($id)`.
 *
 * `ShippingMethod` IS NOT IN `config/tenancy.php`'s `scoped_models`, so `StoreScope` adds no
 * predicate for any principal. A bare `find()` therefore reaches every tenant's row.
 *
 * AND THE THREE WRITES DID NOT MERELY EDIT SOMEBODY ELSE'S ROW — THEY TOOK IT. Each one ended
 * with `$shipping->store_id = getCurrentStore();`, so an authenticated merchant posting another
 * store's id rewrote that store's shipping cost AND moved the row into their own store. The
 * victim's shipping method simply disappeared from their settings.
 *
 * Reachable from `routes/web.php:864-868`, which exposes the three update paths under a bare
 * `{id}` prefix inside the ordinary authenticated group.
 *
 * TWO CONTROLS, BOTH NEEDED, because each alone leaves a hole:
 *
 *   [permission] `shippingWriteRefusal()` — who may act at all. Without it, a staff member whose
 *                role grants only Manage Blog could still edit shipping prices.
 *   [tenancy]    `scopedShippingMethod()` — which rows they may act ON. Without it, a merchant
 *                with the permission still reaches every other tenant's row.
 *
 * ON THE OPERATOR, stated rather than left as a side effect, and split the way the coupon
 * precedent splits it: the platform operator MAY READ and MAY NOT WRITE
 * (`CouponController::couponReadRefusal` admits them, `refuseOperatorWrite` refuses them — F157,
 * owner decision 2026-10-28: read and export, do not rewrite a merchant's commercial settings).
 *
 * The first version of this change got that wrong in a way an independent review caught: it used
 * the WRITE refusal for the three edit-FORM reads too, so the operator — who could open those
 * forms before the change — lost a read they already had. That is an unstated second decision
 * riding on a security fix, which is exactly the thing not to do. Reads admit, writes refuse.
 *
 * No owner decision names SHIPPING, so the write refusal is an analogy and recorded as one: a
 * single early-return to reverse. What it must not become again is an ACCIDENT — a bare
 * `isAbleTo()` refuses the operator too, but only because Laratrust has no super-admin bypass, and
 * the next person adding an operator limb "for consistency" would have silently handed the
 * operator every merchant's shipping prices.
 *
 * ON THE PERMISSION NAME, also corrected by that review. The first version gated the reads on
 * `Manage Shipping`, which is the NAVIGATION-GROUP permission — `CompanyMenuListener:316` adds it
 * with `'route' => ''`, and before this change nothing in the codebase called `isAbleTo()` on it
 * at all. Using it here would have promoted a menu-visibility permission into a data-read
 * permission: a staff member holding only the menu entry could then GET all three forms and read
 * every shipping cost in the store, while being unable to save. `Edit Shipping Method`
 * (`Utility.php:2824`) is the seeded name these screens actually belong to — it is what the view
 * labels the buttons with — and the convention elsewhere is that an edit FORM uses the same name
 * as its write (`PlanCouponController:30-31` says so explicitly).
 */
class ShippingMethodController extends Controller
{
    /** The one permission these six handlers belong to. See the class docblock on why not `Manage Shipping`. */
    private const PERMISSION = 'Edit Shipping Method';

    /**
     * Who may OPEN a shipping-method form. Null means permitted.
     *
     * Admits the operator, per the coupon read precedent.
     *
     * @return \Illuminate\Http\RedirectResponse|null
     */
    private function shippingReadRefusal()
    {
        $user = auth()->user();

        if (! $user) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        // Operator-first: `isAbleTo()` ALONE refuses the platform operator, because no role holds
        // this permission on a database built from source.
        $isOperator = UserPolicy::isOperator($user);

        if (! $isOperator && ! $user->isAbleTo(self::PERMISSION)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        // `getCurrentStore()` hands an operator with no `current_store` the hardcoded literal 1,
        // and `getStoreById(1)` normally resolves — so without this the operator would be served
        // store 1's costs by default. The coupon read gate carries the same guard for the same
        // reason.
        if ($isOperator && empty($user->current_store)) {
            return redirect()->back()->with('error', __('No store is selected.'));
        }

        return $this->storeMustResolve();
    }

    /**
     * Who may WRITE a shipping method. Null means permitted.
     *
     * Refuses the operator explicitly — see the class docblock.
     *
     * @return \Illuminate\Http\RedirectResponse|null
     */
    private function shippingWriteRefusal()
    {
        $user = auth()->user();

        if (! $user) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (UserPolicy::isOperator($user)) {
            Log::warning('shipping method write refused: the platform operator has read rights only', [
                'user_id' => $user->id,
                'current_store' => getCurrentStore(),
                'permission' => self::PERMISSION,
            ]);

            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (! $user->isAbleTo(self::PERMISSION)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return $this->storeMustResolve();
    }

    /**
     * The store must RESOLVE. `getCurrentStore()` returns null for a principal with no
     * `current_store` and no store of their own; `where('store_id', null)` compiles to `IS NULL`,
     * which would match any orphaned row rather than nothing.
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
     * This store's shipping method, or null.
     *
     * `ShippingMethod` registers no global scope and is absent from `scoped_models`, so this
     * predicate is the ONLY thing standing between a route parameter and another tenant's row.
     */
    private function scopedShippingMethod($id): ?ShippingMethod
    {
        $storeId = getCurrentStore();

        if (empty($storeId)) {
            return null;
        }

        return ShippingMethod::where('store_id', $storeId)->find($id);
    }
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\ShippingMethod  $shippingMethod
     * @return \Illuminate\Http\Response
     */
    public function show(ShippingMethod $shippingMethod)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\ShippingMethod  $shippingMethod
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        if ($refusal = $this->shippingReadRefusal()) {
            return $refusal;
        }

        $shippingMethod = $this->scopedShippingMethod($id);

        if ($shippingMethod === null) {
            return redirect()->back()->with('error', __('Shipping method not found.'));
        }

        $shippings = Shipping::where('store_id',getCurrentStore())->get()->pluck('name', 'id');
        $shippings_count = Shipping::where('store_id',getCurrentStore())->get()->pluck('name', 'id')->count();
        return view('shipping_method.edit',compact('shippingMethod','shippings','shippings_count'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\ShippingMethod  $shippingMethod
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        if ($refusal = $this->shippingWriteRefusal()) {
            return $refusal;
        }

        $shipping = $this->scopedShippingMethod($id);

        if ($shipping === null) {
            return redirect()->back()->with('error', __('Shipping method not found.'));
        }

        $shipping->cost             = $request->cost;
        $shipping->calculation_type = $request->calculation_type;
        /* `store_id` IS NOT REASSIGNED. It used to be set to getCurrentStore() on every save,
           which -- with no predicate on the lookup above -- MOVED another tenant's row into the
           caller's store. The row already belongs to this store, because the lookup now says so. */

        $data = [];
        $data['product_cost'] = $request->product_cost;
        $data['product_no_cost'] = !empty($request->product_no_cost) ? $request->product_no_cost : 0;
        $data = json_encode($data);
        $shipping->product_cost     = $data;

        $shipping->save();

        return redirect()->back()->with('success', __('Shipping successfully updated.'));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\ShippingMethod  $shippingMethod
     * @return \Illuminate\Http\Response
     */
    public function destroy(ShippingMethod $shippingMethod)
    {
        //
    }

    public function freeShippingEdit($id)
    {
        if ($refusal = $this->shippingReadRefusal()) {
            return $refusal;
        }

        $shippingMethod = $this->scopedShippingMethod($id);

        if ($shippingMethod === null) {
            return redirect()->back()->with('error', __('Shipping method not found.'));
        }

        return view('shipping_method.free_shipping',compact('shippingMethod'));
    }

    public function freeShippingUpdate(Request $request, $id)
    {
        if ($refusal = $this->shippingWriteRefusal()) {
            return $refusal;
        }

        $shipping = $this->scopedShippingMethod($id);

        if ($shipping === null) {
            return redirect()->back()->with('error', __('Shipping method not found.'));
        }

        $shipping->shipping_requires = $request->shipping_requires;
        /* See update(): the store_id reassignment is gone. */
        if($shipping->shipping_requires == '1' || $shipping->shipping_requires == '2')
        {
            $shipping->cost     = '0';
        }
        else
        {
            $shipping->cost     = $request->cost;
        }
        $shipping->save();
        return redirect()->back()->with('success', __('Shipping successfully updated.'));

    }


    public function localShippingEdit($id)
    {
        if ($refusal = $this->shippingReadRefusal()) {
            return $refusal;
        }

        $shippingMethod = $this->scopedShippingMethod($id);

        if ($shippingMethod === null) {
            return redirect()->back()->with('error', __('Shipping method not found.'));
        }

        return view('shipping_method.local_shipping',compact('shippingMethod'));
    }

    public function localShippingUpdate(Request $request, $id)
    {
        if ($refusal = $this->shippingWriteRefusal()) {
            return $refusal;
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'cost' => 'required',
            ]
        );
        if($validator->fails())
        {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }

        $shipping = $this->scopedShippingMethod($id);

        if ($shipping === null) {
            return redirect()->back()->with('error', __('Shipping method not found.'));
        }

        $shipping->cost             = $request->cost;
        /* See update(): the store_id reassignment is gone. */
        $shipping->save();

        return redirect()->back()->with('success', __('Shipping successfully updated.'));
    }
}
