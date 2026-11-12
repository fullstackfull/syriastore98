<?php

namespace App\Http\Controllers;

use App\Models\PixelFields;
use App\Models\Store;
use App\Models\Utility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class PixelFieldsController extends Controller
{
    /**
     * P2.5-PKG-B. Does the caller own this pixel row?
     *
     * `PixelFields` registers no global scope and is absent from `config/tenancy.php`'s
     * `scoped_models`, so `PixelFields::find($id)` reaches every tenant's rows for every
     * principal. MEASURED in `SettingsAndContentCrossTenantTest` before this guard existed: a
     * second merchant opened another store's pixel settings, rewrote its tracking id, TOOK the row
     * into their own store, and deleted it.
     *
     * Fails closed when either side is absent -- a row with a NULL `store_id` matches no store
     * predicate anywhere in the application and must not become visible here.
     *
     * THERE IS DELIBERATELY NO PERMISSION CHECK ADDED ALONGSIDE THIS, and that is a judgement
     * worth stating. `Route::resource('pixel-setting', ...)` at routes/web.php:685 is ungated on
     * every one of its seven methods.
     *
     * AN EARLIER VERSION OF THIS COMMENT SAID NO PIXEL PERMISSION EXISTS. THAT WAS FALSE, and the
     * tenant-authz review caught it: `business pixel settings` is seeded at
     * `packages/workdo/Marketing/src/database/seeders/PermissionTableSeeder.php:46`. The decision
     * not to gate on it survives the correction for a better reason than the one I had written:
     *
     *   1. It is a MODULE permission, and routes/web.php:685 is a first-party resource route, not
     *      a Marketing surface.
     *   2. `Utility::addNewData()` never grants it -- it is absent from `$companyNewPermission`
     *      (verified by grep over app/Models/Utility.php) -- and the Marketing seeder's own grant
     *      sits inside an `if (!$table)` guard, so on any install where the row already exists the
     *      grant is skipped. Whether the `admin` role holds it is therefore install-dependent,
     *      which is exactly the "refuse every merchant their own settings" risk.
     *   3. The first-party UI expects no permission: `resources/views/setting/
     *      pixel_field_setting.blade.php` renders Add, Edit and Delete with no `@permission`
     *      wrapper anywhere.
     *
     * So gating here would take a capability away from merchants and staff who have it today, and
     * the directive is explicit that every merchant function and permission is preserved. What
     * this change does NOT close, and is a real gap: any principal INSIDE the tenant, including a
     * staff member whose role grants nothing, can still read and rewrite their own store's pixel
     * ids. That is pre-existing and is recorded as a separate owner decision.
     */
    private function callerOwnsPixel(?PixelFields $pixel): bool
    {
        $user = Auth::user();

        if ($user === null || $pixel === null) {
            return false;
        }

        $currentStore = getCurrentStore($user->id);

        return ! empty($currentStore)
            && ! empty($pixel->store_id)
            && (int) $pixel->store_id === (int) $currentStore;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
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
        $user = Auth::user();
        $store_settings = getStoreById(getCurrentStore());
        $pixel_plateforms = Utility::pixel_plateforms();
        return view('pixel.create',compact('store_settings','pixel_plateforms'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        session()->put(['setting_tab' => 'pixel_field_setting']);
        $store = Store::find(getCurrentStore());

        $validator = \Validator::make(
            $request->all(),
            [
                'platform'=>'required',
                'pixel_id'=>'required'
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', implode(' ', $messages->all()));
        }

        $pixel_fields = new PixelFields();
        $pixel_fields->platform = $request->platform;
        $pixel_fields->pixel_id = $request->pixel_id;
        $pixel_fields->store_id = $store->id;
        $pixel_fields->save();

        return redirect()->back()->with('success', __('Fields Saves Successfully.!'));
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\PixelFields  $pixelFields
     * @return \Illuminate\Http\Response
     */
    public function show(PixelFields $pixelFields)
    {
        // P2.5-PKG-B. AN EMPTY BODY IS STILL AN EXISTENCE ORACLE. Route model binding resolves the
        // row BEFORE this method runs, and `PixelFields` is absent from `config/tenancy.php`'s
        // `scoped_models`, so it resolves any tenant's id: the response was an empty 200 for a row
        // that exists in ANY store and a 404 only for one that exists nowhere. That difference
        // answers "does row N exist on this platform" for every id, to any authenticated
        // principal. The guard makes the two indistinguishable. Nothing in the repository
        // references this route -- `pixel-setting.show` has no callers in resources/, app/, routes/ or
        // packages/ -- so the route is left registered rather than removed: refusing what it must
        // not serve is a smaller change than withdrawing a URL some client may hold.

        if (! $this->callerOwnsPixel($pixelFields)) {
            abort(404);
        }
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\PixelFields  $pixelFields
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $pixelFields = PixelFields::find($id);

        // P2.5-PKG-B. This read was completely unguarded: any authenticated web principal could
        // open any store's pixel settings and read its third-party tracking id. 404 for a foreign
        // or absent row, so the two are indistinguishable and the response is not an existence
        // oracle.
        if (! $this->callerOwnsPixel($pixelFields)) {
            abort(404);
        }

        $pixel_plateforms = Utility::pixel_plateforms();
        return view('pixel.update',compact('pixelFields', 'pixel_plateforms'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\PixelFields  $pixelFields
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        session()->put(['setting_tab' => 'pixel_field_setting']);
        $pixelFields = PixelFields::find($id);

        // P2.5-PKG-B. The guard precedes the validator as well as the write, so a cross-store
        // attempt cannot be told apart from a refusal by which error comes back.
        if (! $this->callerOwnsPixel($pixelFields)) {
            abort(404);
        }

        $validator = \Validator::make(
            $request->all(),
            [
                'platform'=>'required',
                'pixel_id'=>'required'
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', implode(' ', $messages->all()));
        }

        $pixelFields->platform = $request->platform;
        $pixelFields->pixel_id = $request->pixel_id;
        // THE ASSIGNMENT THAT USED TO BE HERE IS GONE, not merely guarded.
        // `$pixelFields->store_id = $store->id;` -- with `$store = Store::find(getCurrentStore())`
        // -- was the takeover primitive: a foreign row reaching it did not just get rewritten, it
        // CHANGED HANDS into the caller's store, removing it from the victim's. callerOwnsPixel()
        // above now guarantees the two values are already equal, so the line was a no-op; leaving
        // it means any future weakening of the guard re-opens a TRANSFER rather than an
        // unauthorised edit. Same reasoning as ProductBrandController and ProductLabelController.
        // `$store` is no longer resolved at all: it had no other use, and in THIS METHOD
        // `Store::find(null)` followed by `$store->id` was additionally a 500 for a principal with
        // no current store. Narrowed deliberately -- `store()` still carries the identical fatal,
        // so this is not a general repair of that shape and must not be read as one.
        $pixelFields->save();

        return redirect()->back()->with('success', __('Fields Saves Successfully.!'));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\PixelFields  $pixelFields
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        session()->put(['setting_tab' => 'pixel_field_setting']);
        $user = \Auth::guard()->user();
        if($user && $user->type == 'admin')
        {
            $pixelfield = PixelFields::find($id);

            // P2.5-PKG-B. The `type == 'admin'` test above is not a TENANT gate: 'admin' is the
            // `users.type` string every merchant carries (User::creatorId() treats it as the root
            // of its own tenant), so it admitted every merchant owner to every store's rows.
            //
            // It is not, however, "no gate at all" -- an earlier version of this comment said
            // that and the review corrected it. It does exclude `type = 'staff'` and
            // `type = 'super admin'`. That is the reason to LEAVE it rather than merely a reason
            // not to bother: removing it would GRANT staff a delete they do not have today.
            if (! $this->callerOwnsPixel($pixelfield)) {
                abort(404);
            }

            if ($pixelfield) {
                $pixelfield->delete();
            }

            return redirect()->back()->with('success', __('Pixel Deleted Successfully!'));
        }
        else
        {
            return redirect()->back()->with('error',__('Permission denied'));
        }
    }
}
