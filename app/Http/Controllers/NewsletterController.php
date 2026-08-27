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
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Newsletter $newsletter)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Newsletter $newsletter)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Newsletter $newsletter)
    {
        
        if(auth()->user() && auth()->user()->isAbleTo('Delete Newsletter'))
        {
            $newsletter->delete();
            return redirect()->back()->with('success', __('Email Newsletter delete successfully.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function fileExport()
    {
        $fileName = 'Newsletter.xlsx';
        return Excel::download(new NewsletterExport, $fileName);
    }
}
