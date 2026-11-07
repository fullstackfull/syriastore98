<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Tax;
use App\Models\TaxOption;
use App\Models\Country;
use App\Models\TaxMethod;
use App\Models\Plan;
use App\DataTables\TaxDataTable;
use App\DataTables\TaxMethodDataTable;

class TaxController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(TaxDataTable $dataTable)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Manage Tax'))
        {
            $setting = getAdminAllSetting();
            if(isset($setting['taxes']) && $setting['taxes'] == 'on')
            {
                return  $dataTable->render('taxes.index');
            } else {
                return redirect()->back()->with('error', __('Tax setting is disabled please contact to your admin to enable tax.'));
            }
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
        return view('taxes.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Create Tax'))
        {
            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => 'required',
                ]
            );

            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $tax                        = new Tax();
            $tax->name                 = $request->name;
            $tax->store_id              = getCurrentStore();
            $tax->save();
            return redirect()->back()->with('success', __('Tax successfully created.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id, TaxMethodDataTable $dataTable)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Show Tax'))
        {
            $tax_option = Tax::find($id);

            // P2.5-PKG-B. This one is NOT merely an oracle: `taxes/show.blade.php:7` renders
            // `$tax_option->name` in the breadcrumb, so a merchant holding `Show Tax` read another
            // store's tax name. The tax-methods table beneath it was already safe --
            // `TaxMethodDataTable::query()` filters on `store_id = getCurrentStore()` as well as
            // `tax_id` -- which is why only the heading leaked.
            if (! $this->callerOwnsTax($tax_option)) {
                abort(404);
            }

            $dataTable->setTaxId($id);
            return $dataTable->render('taxes.show', compact('tax_option'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        // P2.5-PKG-B. The form now refuses exactly when the submit would: `update()` checks
        // `Edit Tax` and this checked nothing. Same change, same reasoning, as
        // ProductBrandController::edit() and ProductLabelController::create().
        if (! auth()->user() || ! auth()->user()->isAbleTo('Edit Tax')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $taxes = Tax::find($id);

        // P2.5-PKG-B. This read had no ownership check, so a merchant could open another store's
        // tax row. 404 for a foreign or absent row, so the response is not an existence oracle.
        if (! $this->callerOwnsTax($taxes)) {
            abort(404);
        }

        return view('taxes.edit', compact('taxes'));
    }

    /**
     * P2.5-PKG-B. Does the caller own this tax row?
     *
     * `Tax` registers no global scope and is absent from `config/tenancy.php`'s `scoped_models`,
     * so `Tax::find($id)` reaches every tenant's rows. MEASURED in
     * `SettingsAndContentCrossTenantTest` before this guard existed: a merchant holding only
     * `Edit Tax` / `Delete Tax` -- permissions over THEIR OWN taxes -- opened, renamed, TOOK and
     * deleted another store's tax row. `taxes` carries only `name` and `store_id`, so the damage
     * is the row's identity and its ownership: moving one out of the victim's store removes their
     * tax entity entirely.
     *
     * Fails closed when either side is absent. No operator limb, as on
     * `ProductBrandController::callerOwnsBrand()`.
     *
     * WHY THAT IS SAFE, STATED PRECISELY, because an earlier version of this line was true in
     * effect and wrong in mechanism. It is NOT that `isAbleTo()` structurally refuses the
     * operator; it is that `$superadminNewPermission` in `app/Models/Utility.php` contains no Tax
     * or Page permission, so the seeded `super admin` role holds none of them. If an operator's
     * role is ever given one through the role editor, this helper DOES fire -- and
     * `getCurrentStore()` returns the hardcoded literal 1 for a super admin with an empty
     * `current_store` (app/Helper/helper.php:775), so the operator would be silently scoped to
     * store 1 rather than refused. Recorded because the shorter sentence decays into a wrong
     * belief.
     */
    private function callerOwnsTax(?Tax $tax): bool
    {
        $user = auth()->user();

        if ($user === null || $tax === null) {
            return false;
        }

        $currentStore = getCurrentStore($user->id);

        return ! empty($currentStore)
            && ! empty($tax->store_id)
            && (int) $tax->store_id === (int) $currentStore;
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {

        if(auth()->user() && auth()->user()->isAbleTo('Edit Tax'))
        {
            $tax = Tax::find($id);

            // P2.5-PKG-B. The permission was checked and the owner was not, and the assignment
            // further down made that a TAKEOVER rather than an unauthorised edit. Above the
            // validator so a cross-store attempt is indistinguishable from a refusal.
            if (! $this->callerOwnsTax($tax)) {
                abort(404);
            }

            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => 'required',
                ]
            );

            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
            $tax->name                 = $request->name;
            // THE ASSIGNMENT THAT USED TO BE HERE IS GONE, not merely guarded.
            // `$tax->store_id = getCurrentStore();` was the takeover primitive: a foreign row
            // reaching it changed hands. callerOwnsTax() above now guarantees the two values are
            // already equal, so the line was a no-op -- but leaving it means any future weakening
            // of the guard re-opens a TRANSFER rather than an unauthorised edit.
            $tax->save();

            return redirect()->back()->with('success', __('Tax successfully updated.'));

        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {

        if(auth()->user() && auth()->user()->isAbleTo('Delete Tax'))
        {
            $tax = Tax::find($id);

            // P2.5-PKG-B. Permission was checked, owner was not. The guard precedes the delete.
            if (! $this->callerOwnsTax($tax)) {
                abort(404);
            }

            $tax->delete();
            return redirect()->back()->with('success', __('Tax delete successfully.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
}
