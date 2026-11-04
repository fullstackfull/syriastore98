<?php

namespace App\Http\Controllers;

use App\Models\TaxMethod;
use App\Models\Country;
use App\Models\City;
use App\Models\State;
use App\Policies\UserPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * F263 (4 of 4) — THE TAX-METHOD UPDATE TOOK ANOTHER TENANT'S ROW, and the edit form read it.
 * =================================================================================================
 *
 * `TaxMethod` is NOT in `config/tenancy.php`'s `scoped_models`, so `StoreScope` adds no predicate
 * for any principal — and that includes ROUTE-MODEL BINDING. `Route::resource('taxes-method', …)`
 * (routes/web.php:880) binds `TaxMethod $taxMethod` straight off the id in the URL, so the bound
 * model is whatever row carries that id in ANY store. Three consequences, measured against the
 * code rather than inferred:
 *
 *   - `edit()` had **no permission check of any kind** and re-resolved with a bare
 *     `TaxMethod::find($taxMethod->id)`, so any authenticated principal in the tenant could read
 *     any store's tax name, rate and jurisdiction.
 *   - `update()` was gated on `Edit Tax Method` but unscoped, and it ended with
 *     **`$taxmethod->store_id = getCurrentStore()`** — F258's row THEFT verbatim. A merchant
 *     posting another store's id did not merely rewrite that store's TAX RATE; it moved the row
 *     into their own store, so the victim's tax method disappeared from their settings while the
 *     attacker kept the edited copy.
 *   - `destroy()` was gated on `Delete Tax Method` and unscoped, so another store's tax method
 *     could be deleted outright.
 *
 * A TAX RATE IS A MONEY INPUT, which is why this one is worth saying plainly: `TaxMethod::tax_rate`
 * feeds the cart's tax calculation, so rewriting another tenant's rate changes what their shoppers
 * are charged. This change alters no tax RULE and no stored amount — it only stops one store
 * reaching another store's row.
 *
 * THE FIX IS F258's TWO CONTROLS, and `ShippingController`'s docblock in this same change carries
 * the full reasoning including the operator split: reads admit the platform operator (because
 * `edit` admitted everybody before, and removing a read somebody has today is an unstated second
 * decision), writes refuse it explicitly (because a bare `isAbleTo()` already refused it, and an
 * accident is what the next "consistency" edit undoes).
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
 *
 * `index()` AND `show()` ARE EMPTY STUBS and are left alone. `create()` and `cities_list()` take no
 * row id — `cities_list` reads the GLOBAL `cities` table, which is reference data shared by every
 * tenant and not a tenant row at all.
 *
 * THE PERMISSION NAMES ARE THE SEEDED ONES THIS CONTROLLER ALREADY USED — `Create/Edit/Delete Tax
 * Method`. `edit` takes the same name as its write, which is this codebase's convention for an edit
 * form.
 */
class TaxMethodController extends Controller
{
    /** The seeded permission the edit form and the update share. */
    private const PERMISSION_EDIT = 'Edit Tax Method';

    /**
     * The store must RESOLVE before any predicate is built from it: `where('store_id', null)`
     * compiles to `IS NULL`, which matches any ORPHANED row rather than nothing.
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
     * Who may OPEN the tax-method edit form. Null means permitted. Admits the operator.
     *
     * @return \Illuminate\Http\RedirectResponse|null
     */
    private function taxReadRefusal()
    {
        $user = auth()->user();

        if (! $user) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $isOperator = UserPolicy::isOperator($user);

        if (! $isOperator && ! $user->isAbleTo(self::PERMISSION_EDIT)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        /* `getCurrentStore()` hands an operator with no `current_store` the hardcoded literal 1. */
        if ($isOperator && empty($user->current_store)) {
            return redirect()->back()->with('error', __('No store is selected.'));
        }

        return $this->storeMustResolve();
    }

    /**
     * Who may WRITE a tax method. Null means permitted. Refuses the operator explicitly.
     *
     * @return \Illuminate\Http\RedirectResponse|null
     */
    private function taxWriteRefusal(string $permission)
    {
        $user = auth()->user();

        if (! $user) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (UserPolicy::isOperator($user)) {
            Log::warning('tax method write refused: the platform operator has read rights only', [
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
     * This store's tax method, or null.
     *
     * TAKES THE ID AND RE-RESOLVES rather than trusting the bound model, because route-model
     * binding on an unscoped model has already crossed the tenant boundary by the time the handler
     * runs. A `where('store_id', …)->find()` also answers null for a row whose `store_id` is NULL,
     * which a `$model->store_id === $storeId` comparison would get right only by accident.
     */
    private function scopedTaxMethod($id): ?TaxMethod
    {
        $storeId = getCurrentStore();

        if (empty($storeId)) {
            return null;
        }

        return TaxMethod::where('store_id', $storeId)->find($id);
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create($tax_option_id)
    {
        //
        $country_option = Country::orderBy('name','ASC')->pluck('name', 'id')->prepend('Select Country', 0);
        return view('taxes-method.create',compact('country_option','tax_option_id'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if ($refusal = $this->taxWriteRefusal('Create Tax Method')) {
            return $refusal;
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'name' => 'required',
                'tax_rate' => 'required'
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', implode(' ', $messages->all()));
        }

        /*
         * F263 FOLLOW-UP — `tax_id` WAS A CROSS-TENANT WRITE DRESSED AS A CREATE, and the new write
         * gate did not touch it. Found by the mandated independent review OF THIS CHANGE.
         *
         * ═══════════════════════════════════════════════════════════════════════════════════════
         * WHAT IT DID, traced end to end rather than inferred
         * ═══════════════════════════════════════════════════════════════════════════════════════
         *
         * `tax_id` was taken RAW from the request — the validator above covers only `name` and
         * `tax_rate` — and the row was then saved with `store_id = getCurrentStore()`, i.e. the
         * CALLER's store. So the row belonged to the attacker while pointing at the victim's tax
         * group. `Tax::tax_methods()` (`app/Models/Tax.php:128`) selected by `tax_id` ALONE, and
         * `Product::ProductPrice()` (`:429`) and `Tax::TaxCount()` (`:77`, `:84`, `:99`) then
         * summed `tax_rate * price / 100` over whatever it returned.
         *
         * `tax_methods()` takes the LEXICOGRAPHICALLY first `priority` (the column is a string with
         * default `'0'`), so posting `priority=''` wins outright and the victim's own methods are
         * excluded from the result entirely. A merchant holding `Create Tax Method` — seeded on the
         * ordinary `admin` role — could therefore post `tax_rate=0` to stop another store
         * collecting tax at all, or a large rate to inflate every price in their storefront.
         *
         * AND IT WAS INVISIBLE AND UNDELETABLE TO THE VICTIM. `TaxMethodDataTable::query()` filters
         * `store_id = getCurrentStore()`, so the row never appeared in the victim's list, and the
         * store-scoped `destroy()` added by F263 refuses it to them because it carries the
         * attacker's `store_id`.
         *
         * `tax_id` CARRIES A FOREIGN KEY to `taxes`, which validates that the id EXISTS — not that
         * it BELONGS. Ids are sequential.
         *
         * ═══════════════════════════════════════════════════════════════════════════════════════
         * THE FIX, AND WHY THIS ONE PLACE IS THE DOOR
         * ═══════════════════════════════════════════════════════════════════════════════════════
         *
         * The group is resolved through the CALLER's store before it is assigned, so the only
         * `tax_id` that can be written is one this store owns. `update()` deliberately does not
         * write `tax_id` at all, and this is the ONLY writer of a `TaxMethod` row in the whole tree
         * (`app/` and `packages/workdo/`, measured) — so closing this closes the injection. The
         * matching READ is scoped in `Tax::tax_methods()`, which is what neutralises a row already
         * injected before this change.
         */
        $group = \App\Models\Tax::where('store_id', getCurrentStore())->find($request->tax_id);

        if ($group === null) {
            return redirect()->back()->with('error', __('Tax not found.'));
        }

        $taxmethod = new TaxMethod();
        $taxmethod->name = $request->name;
        $taxmethod->tax_rate = $request->tax_rate;
        $taxmethod->tax_id = $group->id;
        $taxmethod->country_id = $request->country_id ?: null;
        $taxmethod->state_id = $request->state_id ?: null;
        $taxmethod->city_id = $request->city_id ?: null;
        $taxmethod->priority = $request->priority;
        $taxmethod->enable_shipping = $request->enable_shipping;
        $taxmethod->store_id = getCurrentStore();
        $taxmethod->save();

    // Redirect back with success message
        return redirect()->back()->with('success', __('Tax-method successfully created.'));
    }

    /**
     * Display the specified resource.
     */
    /**
     * An empty stub — but a ROUTED, BOUND, UNGATED one, which made it an id oracle.
     *
     * `Route::resource('taxes-method', …)` implicit-binds `TaxMethod` with `firstOrFail()` BEFORE
     * any handler code runs, and `TaxMethod` is absent from `scoped_models` so the binding crosses
     * the tenant boundary. With no gate here the two answers differed: an id that exists in another
     * store returned **200 with an empty body**, an id that exists nowhere returned **404**. No
     * field value leaked, but any authenticated `web` user — no permission needed — could
     * enumerate which `tax_methods` ids exist platform-wide.
     *
     * Found by the mandated independent review, which classed it as hardening rather than a hole.
     * Taken anyway because it costs one gate on a method with no body: there is no behaviour to
     * preserve, and the asymmetry is now deliberate rather than accidental.
     *
     * (`ShippingController::show()` needs nothing: it takes a bare `$id` with no route-model
     * binding, so it answers the same empty 200 for every id and distinguishes nothing.)
     */
    public function show(TaxMethod $taxMethod)
    {
        if ($refusal = $this->taxReadRefusal()) {
            return $refusal;
        }

        if ($this->scopedTaxMethod($taxMethod->id) === null) {
            return redirect()->back()->with('error', __('Tax-method not found.'));
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(TaxMethod $taxMethod)
    {
        if ($refusal = $this->taxReadRefusal()) {
            return $refusal;
        }

        $tax_method = $this->scopedTaxMethod($taxMethod->id);

        if ($tax_method === null) {
            return redirect()->back()->with('error', __('Tax-method not found.'));
        }

        $country_option = Country::orderBy('name','ASC')->pluck('name', 'id')->prepend('Select Country', 0);
        $state_option = State::orderBy('name','ASC')->pluck('name', 'id')->prepend('Select State', 0);
        $city_option = City::orderBy('name','ASC')->pluck('name', 'id')->prepend('Select City', 0);
        return view('taxes-method.edit', compact('tax_method','country_option','state_option','city_option'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, TaxMethod $taxMethod)
    {

        if ($refusal = $this->taxWriteRefusal(self::PERMISSION_EDIT)) {
            return $refusal;
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'name' => 'required',
                'tax_rate' => 'required',
            ]
        );

        if($validator->fails())
        {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', implode(' ', $messages->all()));
        }
        $taxmethod = $this->scopedTaxMethod($taxMethod->id);

        if ($taxmethod === null) {
            return redirect()->back()->with('error', __('Tax-method not found.'));
        }

        $taxmethod->name                 = $request->name;
        $taxmethod->tax_rate             = $request->tax_rate;
        $taxmethod->country_id           = $request->country_id ?: null;
        $taxmethod->state_id             = $request->state_id ?: null;
        $taxmethod->city_id              = $request->city_id ?: null;
        $taxmethod->priority             = $request->priority;
        $taxmethod->enable_shipping      = $request->enable_shipping;

        /*
         * `store_id` IS NO LONGER REASSIGNED, AND THAT LINE WAS THE ROW THEFT. The row is now
         * found BY this store's id, so writing `getCurrentStore()` back would be a no-op on
         * the happy path — and on the unhappy one it was what MOVED another tenant's tax
         * method into the attacker's store. Removing it leaves the predicate as the single
         * statement of which row this is.
         */
        $taxmethod->save();

        return redirect()->back()->with('success', __('Tax-method successfully updated.'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(TaxMethod $taxMethod)
    {

        if ($refusal = $this->taxWriteRefusal('Delete Tax Method')) {
            return $refusal;
        }

        $tax = $this->scopedTaxMethod($taxMethod->id);

        if ($tax === null) {
            return redirect()->back()->with('error', __('Tax-method not found.'));
        }

        $tax->delete();

        return redirect()->back()->with('success', __('Tax-method delete successfully.'));
    }

    public function cities_list(Request $request)
    {
        $state_id = $request->state_id;
        $cities_list = City::where('state_id',$state_id)->orderBy('name','ASC')->pluck('name', 'id')->prepend('Select City', 0)->toArray();
        return response()->json($cities_list);

    }
}
