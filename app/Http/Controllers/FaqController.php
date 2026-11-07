<?php

namespace App\Http\Controllers;

use App\Models\Faq;
use Illuminate\Http\Request;
use App\DataTables\FaqDataTable;

class FaqController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(FaqDataTable $dataTable)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Manage Faqs'))
        {
            return $dataTable->render('faq.index');
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
        return view('faq.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Create Faqs'))
        {
            $validator = \Validator::make(
                $request->all(),
                [
                    'topic' => 'required',

                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', implode(' ', $messages->all()));
            }

            $faq                 = new Faq();
            $faq->topic         = $request->topic;
            $faq->store_id       = getCurrentStore();
            $faq->save();

            return redirect()->back()->with('success', __('FAQs successfully created.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Faq $faq)
    {
        // P2.5-PKG-B. AN EMPTY BODY IS STILL AN EXISTENCE ORACLE. Route model binding resolves
        // the row BEFORE this method runs, and `Faq` is absent from scoped_models, so the
        // response was an empty 200 for a row in ANY store and 404 only for one existing nowhere.
        // Nothing in the repository references `faqs.show`, so the route is left registered and
        // simply refuses what it must not serve -- a smaller change than withdrawing a URL.
        if (! $this->callerOwnsFaq($faq)) {
            abort(404);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */

    /**
     * P2.5-PKG-B. Does the caller own this FAQ?
     *
     * `Faq` is absent from `config/tenancy.php`'s `scoped_models`, so
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
    private function callerOwnsFaq(?Faq $faq): bool
    {
        $user = auth()->user();

        if ($user === null || $faq === null) {
            return false;
        }

        $currentStore = getCurrentStore($user->id);

        return ! empty($currentStore)
            && ! empty($faq->store_id)
            && (int) $faq->store_id === (int) $currentStore;
    }

    public function edit(Faq $faq)
    {
        // P2.5-PKG-B. FAQ. This read had no permission check either, so any authenticated principal could open
        // another store's.
        if (! $this->callerOwnsFaq($faq)) {
            abort(404);
        }

        return view('faq.edit', compact('faq'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Faq $faq)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Edit Faqs'))
        {
            // P2.5-PKG-B. The permission was checked and the owner was not, so this wrote onto
            // ANOTHER STORE'S row. Above the validator, so a cross-store attempt cannot
            // be told apart from a refusal by which error comes back.
            if (! $this->callerOwnsFaq($faq)) {
                abort(404);
            }

            $validator = \Validator::make(
                $request->all(),
                [
                    'topic' => 'required',

                ]
            );

            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', implode(' ', $messages->all()));
            }

            $faq->topic               = $request->topic;
            $faq->description         = $request->kt_docs_repeater_basic;
            $faq->save();

            return redirect()->back()->with('success', __('FAQs successfully updated.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Faq $faq)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Delete Faqs'))
        {
            // P2.5-PKG-B. Permission was checked, owner was not. The guard precedes the delete.
            if (! $this->callerOwnsFaq($faq)) {
                abort(404);
            }

            $faq->delete();
            return redirect()->back()->with('success', __('FAQs delete successfully.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
}
