<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Store;
use Illuminate\Http\Request;
use App\DataTables\ContactDataTable;
use Illuminate\Support\Facades\Cache;

class ContactController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(ContactDataTable $dataTable)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Manage Contact Us'))
        {
            return $dataTable->render('contact.index');

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
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, $slug=null)
    {
        $validator = \Validator::make(
            $request->all(),
            [
                'first_name' => 'required',
                'last_name' => 'required',
                'email' => 'required',
                'contact' => 'required',
                'subject' => 'required',
                'description' => 'required',
            ]
        );
        if($validator->fails())
        {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }

        if (!empty($slug)) {
            $store = getStore($slug);
            $store_id = $store->id ?? null;
        } else {
            $store_id = getCurrentStore();
        }

        $contact                    = new Contact();
        $contact->first_name        = $request->first_name;
        $contact->last_name         = $request->last_name;
        $contact->email             = $request->email;
        $contact->contact           = $request->contact;
        $contact->subject           = $request->subject;
        $contact->description       = $request->description;
        $contact->store_id          = $store_id;
        $contact->save();

        return redirect()->back()->with('success', __('Contact successfully created.'));
    }

    /**
     * Display the specified resource.
     */
    public function show(Contact $contact)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */

    /**
     * P2.5-PKG-B. Does the caller own this contact submission?
     *
     * `Contact` is absent from `config/tenancy.php`'s `scoped_models`, so
     * `StoreScope::isActiveFor()` short-circuits and the global scope adds NO predicate for any
     * principal -- route model binding reaches every tenant's rows.
     *
     * MEASURED in `ContentFamilyCrossTenantTest` before this guard existed: all seven controllers
     * of this shape failed their cross-tenant cases while all seven positive controls passed, so
     * the fixtures were sound and the holes were real.
     *
     * Fails closed when either side is absent -- a row with a NULL `store_id` matches no store
     * predicate anywhere in the application and must not become visible here. No operator limb,
     * as on `ProductBrandController::callerOwnsBrand()`.
     */
    private function callerOwnsContact(?Contact $contact): bool
    {
        $user = auth()->user();

        if ($user === null || $contact === null) {
            return false;
        }

        $currentStore = getCurrentStore($user->id);

        return ! empty($currentStore)
            && ! empty($contact->store_id)
            && (int) $contact->store_id === (int) $currentStore;
    }

    public function edit(Contact $contact)
    {
        // P2.5-PKG-B. contact submission -- the name, email address and message of a person who wrote to
        // ANOTHER merchant. Personal data, and this read had no permission check either.
        if (! $this->callerOwnsContact($contact)) {
            abort(404);
        }

        return view('contact.edit', compact('contact'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Contact $contact)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Edit Contact Us'))
        {
            // P2.5-PKG-B. The permission was checked and the owner was not, so this wrote onto
            // ANOTHER STORE'S row. Above the validator, so a cross-store attempt cannot
            // be told apart from a refusal by which error comes back.
            if (! $this->callerOwnsContact($contact)) {
                abort(404);
            }

            $validator = \Validator::make(
                $request->all(),
                [
                    'first_name' => 'required',
                    'last_name' => 'required',
                    'email' => 'required',
                    'contact' => 'required',
                    'subject' => 'required',
                    'description' => 'required',

                ]
            );

            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', implode(' ', $messages->all()));
            }
            $contact->first_name     = $request->first_name;
            $contact->last_name      = $request->last_name;
            $contact->email          = $request->email;
            $contact->contact        = $request->contact;
            $contact->subject        = $request->subject;
            $contact->description    = $request->description;
            $contact->save();

            return redirect()->back()->with('success', __('Contact successfully updated.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Contact $contact)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Delete Contact Us'))
        {
            // P2.5-PKG-B. Permission was checked, owner was not. The guard precedes the delete.
            if (! $this->callerOwnsContact($contact)) {
                abort(404);
            }

            $contact->delete();
            return redirect()->back()->with('success', __('Contact delete successfully.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

}
