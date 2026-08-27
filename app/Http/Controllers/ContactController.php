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
    public function edit(Contact $contact)
    {
        return view('contact.edit', compact('contact'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Contact $contact)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Edit Contact Us'))
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
            $contact->delete();
            return redirect()->back()->with('success', __('Contact delete successfully.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

}
