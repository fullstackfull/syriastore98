<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\State;
use Illuminate\Http\Request;
use App\DataTables\ShippingZoneDataTable;
use App\DataTables\ShippingMethodDataTable;

/**
 * F261 — the sibling of F258, and the two writes here were worse.
 *
 * `ShippingZone` and `ShippingMethod` are BOTH absent from `config/tenancy.php`'s
 * `scoped_models`, so `StoreScope` adds no predicate to either. Every `find($id)` below took its
 * id straight from the route.
 *
 *   destroy()  deleted another tenant's zone AND one of their `shipping_methods` rows, on nothing
 *              more than holding `Delete Shipping Zone` — which a merchant's own tenant role
 *              grants. A capability check is not an ownership check.
 *   update()   carried `$ShippingZone->store_id = getCurrentStore()`, the exact row-theft just
 *              removed from `ShippingMethodController`, and then inserted new `shipping_methods`
 *              rows against the victim's zone id. The victim's zone vanished from their settings
 *              and their own method rows were left orphaned against a zone that now belonged to
 *              somebody else.
 *   edit()     had no permission check at all, and 500d on an unknown id (`explode()` on null).
 *   show()     resolved the zone unscoped; only the method list beside it was scoped.
 *
 * Found by an independent review of the F258 change — the same defect, one route away, which the
 * F258 work would have left in place.
 *
 * `scopedZone()` is the control. It is the ONLY thing between a route parameter and another
 * tenant's row, so it is applied in every handler that takes an id, and `empty($storeId)` returns
 * null BEFORE a query is built, because `where('store_id', null)` compiles to `IS NULL` and would
 * match an orphaned row rather than nothing.
 */
class ShippingZoneController extends Controller
{
    /**
     * This store's shipping zone, or null.
     */
    private function scopedZone($id): ?ShippingZone
    {
        $storeId = getCurrentStore();

        if (empty($storeId)) {
            return null;
        }

        return ShippingZone::where('store_id', $storeId)->find($id);
    }

    public function index(ShippingZoneDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Shipping Zone')) {
            $shippingZones = ShippingZone::where('store_id', getCurrentStore())->get();
            $shipping_method = [];
            foreach ($shippingZones as $key => $value) {
                if (! empty($value->shipping_method)) {
                    $data = explode(',', $value->shipping_method);
                    $shipping_method = ShippingMethod::whereIn('id', $data)->get()->pluck('method_name')->toArray();
                }
            }

            if (! empty($shipping_method)) {
                $shipping_method = implode(',', $shipping_method);
            }
            return  $dataTable->render('shippingzone.index', compact('shippingZones', 'shipping_method'));

        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $country_option = Country::orderBy('name', 'ASC')->pluck('name', 'id')->prepend('Select Country', 0);

        return view('shippingzone.create', compact('country_option'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {

        if ($request->country_id == 0) {
            $request->merge(['country_id' => null]);

        }
        if ($request->state_id == 0) {
            $request->merge(['state_id' => null]);
        }
        if (auth()->user() && auth()->user()->isAbleTo('Create Shipping Zone')) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'zone_name' => 'required',
                    'country_id' => 'nullable',
                    'state_id' => 'nullable',
                    'shipping_method' => 'required',
                ]

            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', implode(' ', $messages->all()));
            }

            $shippingZones = ShippingZone::create(
                [
                    'zone_name' => $request->zone_name,
                    'country_id' => $request->country_id,
                    'state_id' => $request->state_id,
                    'shipping_method' => implode(',', $request->shipping_method),
                    'store_id' => getCurrentStore(),
                ]
            );

            foreach ($request->shipping_method as $shippingMethods) {
                $shippingMethods = ShippingMethod::create(
                    [
                        'zone_id' => $shippingZones->id,
                        'method_name' => $shippingMethods,
                        'store_id' => getCurrentStore(),
                    ]
                );
            }

            $shippingMethods->save();
            $shippingZones->save();

            return redirect()->back()->with('success', __('Shipping successfully created.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id, ShippingMethodDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Show Shipping Zone')) {
            $shippingZones = $this->scopedZone($id);

            if ($shippingZones === null) {
                return redirect()->back()->with('error', __('Shipping zone not found.'));
            }

            $shippingMethods = ShippingMethod::where('zone_id', $id)->where('store_id', getCurrentStore())->get();
            $dataTable->setZoneId($id);
            return $dataTable->render('shipping_method.index', compact('shippingZones', 'shippingMethods'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        /* NO PERMISSION CHECK AT ALL before this. Its siblings all have one; this handler was
           reachable by any authenticated member of the tenant, for any store's zone. */
        if (! auth()->user() || ! auth()->user()->isAbleTo('Edit Shipping Zone')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $shippingzone = $this->scopedZone($id);

        if ($shippingzone === null) {
            // And this is also the 500 fix: `explode(',', null)` was fatal on an unknown id.
            return redirect()->back()->with('error', __('Shipping zone not found.'));
        }

        $zone_selected = explode(',', $shippingzone->shipping_method);

        $country_option = Country::orderBy('name', 'ASC')->pluck('name', 'id')->prepend('Select Country', 0);
        $state_option = State::orderBy('name', 'ASC')->pluck('name', 'id')->prepend('Select State', 0);
        $ShippingMethod = ShippingMethod::where('store_id', getCurrentStore())->get()->pluck('method_name', 'id');

        return view('shippingzone.edit', compact('shippingzone', 'country_option', 'state_option', 'ShippingMethod', 'zone_selected'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        if ($request->country_id == 0) {
            $request->merge(['country_id' => null]);

        }
        if ($request->state_id == 0) {
            $request->merge(['state_id' => null]);
        }

        if (auth()->user() && auth()->user()->isAbleTo('Edit Shipping Zone')) {
            $ShippingZone = $this->scopedZone($id);

            if ($ShippingZone === null) {
                return redirect()->back()->with('error', __('Shipping zone not found.'));
            }

            $ShippingZone->zone_name = $request->zone_name;
            $ShippingZone->country_id = $request->country_id;
            $ShippingZone->state_id = $request->state_id;
            $shipping_method = implode(',', $request->shipping_method);
            $ShippingZone->shipping_method = $shipping_method;
            /* `store_id` IS NOT REASSIGNED. It used to be set on every save, which -- with no
               predicate on the lookup above -- MOVED another tenant's zone into the caller's
               store. The row already belongs to this store, because the lookup now says so. */

            $shippingMethods = ShippingMethod::where('store_id', getCurrentStore())->where('zone_id', $id)->get();
            $shippingMethods->each->delete();

            foreach ($request->shipping_method as $shippingMethods) {
                $shippingMethods = ShippingMethod::create(
                    [
                        'zone_id' => $ShippingZone->id,
                        'method_name' => $shippingMethods,
                        'store_id' => getCurrentStore(),
                    ]
                );

            }

            $ShippingZone->save();

            return redirect()->back()->with('success', __('ShippingZone successfully updated.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {

        if (auth()->user() && auth()->user()->isAbleTo('Delete Shipping Zone')) {
            $ShippingZone = $this->scopedZone($id);

            if ($ShippingZone === null) {
                return redirect()->back()->with('error', __('Shipping zone not found.'));
            }

            /* SCOPED, and it was not. `ShippingMethod::where('zone_id', $id)` with no store
               predicate deleted whichever tenant's method happened to sit on that zone id. */
            $shippingMethods = ShippingMethod::where('zone_id', $id)
                ->where('store_id', getCurrentStore())
                ->first();
            if (! empty($shippingMethods)) {
                $shippingMethods->delete();
            }
            $ShippingZone->delete();

            return redirect()->back()->with('success', 'ShippingZone successfully deleted.');
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function states_list(Request $request)
    {
        $country_id = $request->country_id;
        $state_list = State::where('country_id', $country_id)->orderBy('name', 'ASC')->pluck('name', 'id')->prepend('Select State', 0)->toArray();

        return response()->json($state_list);

    }
}
