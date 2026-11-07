<?php

namespace App\Http\Controllers;

use App\Models\Newsletter;
use Illuminate\Http\Request;
use App\Models\Store;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\NewsletterExport;
use Cookie;
use App\DataTables\NewsletterDataTable;
use Illuminate\Support\Facades\Cache;

class NewsletterController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(NewsletterDataTable $dataTable)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Manage Newsletter'))
        {
            return $dataTable->render('newsletter.index');
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
    public function store(Request $request)
    {
        $parameters = $request->query();
        $slug = null;
        foreach ($parameters as $key => $value) {
            $slug = $key;
        }
        $store = getStore($slug);
        if (!$store) {
            abort(404);
        }
        $validator = \Validator::make(
            $request->all(),
            [
                'email' => ['required','unique:newsletters'],

            ]
        );
        if($validator->fails())
        {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }

        $newsletter                 = new Newsletter();
        $newsletter->email         = $request->email;
        if(auth('customers')->user())
        {
            $newsletter->customer_id         = auth('customers')->user()->id;
        }
        else{
            $newsletter->customer_id         = '0';
        }
        $newsletter->store_id       = $store->id;
        $newsletter->save();

        return redirect()->back()->with('success', __('Newsletter successfully subscribe.'));
    }

    /**
     * Display the specified resource.
     */
    public function show(Newsletter $newsletter)
    {
        // P2.5-PKG-B. AN EMPTY BODY IS STILL AN EXISTENCE ORACLE. Route model binding resolves
        // the row BEFORE this method runs, and `Newsletter` is absent from scoped_models, so the
        // response was an empty 200 for a row in ANY store and 404 only for one existing nowhere.
        // Nothing in the repository references `newsletter.show`, so the route is left registered and
        // simply refuses what it must not serve -- a smaller change than withdrawing a URL.
        if (! $this->callerOwnsNewsletter($newsletter)) {
            abort(404);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */

    /**
     * P2.5-PKG-B. Does the caller own this newsletter subscriber?
     *
     * `Newsletter` is absent from `config/tenancy.php`'s `scoped_models`, so
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
    private function callerOwnsNewsletter(?Newsletter $newsletter): bool
    {
        $user = auth()->user();

        if ($user === null || $newsletter === null) {
            return false;
        }

        $currentStore = getCurrentStore($user->id);

        return ! empty($currentStore)
            && ! empty($newsletter->store_id)
            && (int) $newsletter->store_id === (int) $currentStore;
    }

    public function edit(Newsletter $newsletter)
    {
        // P2.5-PKG-B. AN EMPTY BODY IS STILL AN EXISTENCE ORACLE: binding resolves the row first, so the
        // response was an empty 200 for a subscriber in ANY store and 404 only for one existing
        // nowhere. Same shape closed on five controllers in F-SEC-02.
        if (! $this->callerOwnsNewsletter($newsletter)) {
            abort(404);
        }

        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Newsletter $newsletter)
    {
        // P2.5-PKG-B. AN EMPTY BODY IS STILL AN EXISTENCE ORACLE, and this one survived the hand
        // census for a SECOND structural reason: that regex required the model to be the FIRST
        // parameter, so `(Request $request, Newsletter $x)` was never examined. Found only by
        // `OwnedResourceMethodsAreGuardedTest`, which walks the real route table by reflection.
        //
        // The method is an unimplemented resource stub and stays inert -- the guard changes
        // nothing for a caller who owns the row, and removes the oracle for one who does not.
        if (! $this->callerOwnsNewsletter($newsletter)) {
            abort(404);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Newsletter $newsletter)
    {
        
        if(auth()->user() && auth()->user()->isAbleTo('Delete Newsletter'))
        {
            // P2.5-PKG-B. Permission was checked, owner was not. The guard precedes the delete.
            if (! $this->callerOwnsNewsletter($newsletter)) {
                abort(404);
            }

            $newsletter->delete();
            return redirect()->back()->with('success', __('Email Newsletter delete successfully.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * P2.5-PKG-B. THIS HAD NO PERMISSION CHECK AT ALL.
     *
     * Four lines, routed at `routes/web.php:1402` inside the ['auth','xss','setlocate','verified']
     * group, returning the store's ENTIRE SUBSCRIBER EMAIL LIST as a spreadsheet.
     * `NewsletterExport::collection()` scopes to `getCurrentStore()`, so this is a WITHIN-TENANT
     * bypass and not a cross-tenant one -- but the payload is personal data and a staff member
     * holding ZERO permissions could download it. MEASURED in
     * `ContentFamilyCrossTenantTest::test_the_newsletter_export_refuses_a_principal_with_no_permission`
     * before this check existed.
     *
     * `Manage Newsletter` and not a new name: `index()` already requires exactly that, and the
     * export button lives on `newsletter/index.blade.php:24` -- a page this principal cannot
     * reach. So anyone who can SEE the button already holds the permission, and this locks nobody
     * out. Found by the tenant-authz review of the seven-controller tenancy fix; it is an
     * authorization gap rather than a tenancy one, which is why neither census surfaced it.
     */
    public function fileExport()
    {
        if (! auth()->user() || ! auth()->user()->isAbleTo('Manage Newsletter')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $fileName = 'Newsletter.xlsx';
        return Excel::download(new NewsletterExport, $fileName);
    }
}
