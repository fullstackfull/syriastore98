<?php

namespace Workdo\Marketing\app\Http\Controllers;

use Illuminate\Http\Request;
use Workdo\Marketing\app\Models\MarketingContact;
use App\Models\Store;
use App\Http\Controllers\Controller;
use Melipayamak\Contacts;
use Workdo\Marketing\DataTables\MarketingContactDataTable;

class ContactController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(MarketingContactDataTable $dataTable)
    {

        if (auth()->user() && auth()->user()->isAbleTo('Manage Marketing Contact')) {
            return $dataTable->render('marketing::contacts.index');
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
        $store_id = $request->business_id;
        $data = Store::where('slug', $request->slug)->where('id', $store_id)->first();
        $contact = MarketingContact::create([
            'store_id' => $store_id,
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'message' => $request->message,
            'created_by' => $data->created_by
        ]);

        return redirect()->back()->with('success', __('Contact Created Successfully.'));
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Appointment_deatail  $appointment_deatail
     * @return \Illuminate\Http\Response
     */
    public function show(Appointment_deatail $appointment_deatail)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Appointment_deatail  $appointment_deatail
     * @return \Illuminate\Http\Response
     */
    public function edit(Request $request, $id)
    {
        $Contacts = MarketingContact::where('id', $id)->first();
        if ($Contacts) {
            return view('marketing::contacts.edit', compact('Contacts'));
        } else {
            return redirect()->back()->with('error', __('Contact not found.'));
        }

    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Appointment_deatail  $appointment_deatail
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Contacts $Contacts, $id)
    {
        $validator = \Validator::make(
            $request->all(),
            [
                'name' => 'required',
                'email' => 'required',
                'phone' => 'required|numeric',
                'message' => 'required',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();

            return redirect()->back()->with('error', $messages->first());
        }
        $Contacts = MarketingContact::where('id', $id)->first();
        $Contacts->name = $request->name;
        $Contacts->email = $request->email;
        $Contacts->phone = $request->phone;
        $Contacts->message = $request->message;
        $Contacts->save();

        return redirect()->route('Marketing.contacts.index')->with('success', __('Contact successfully updated.'));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Appointment_deatail  $appointment_deatail
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Delete Marketing Contact')) {
            $contact = MarketingContact::find($id);
            if ($contact) {
                $contact->delete();
                return redirect()->back()->with('success', __('Contact successfully deleted.'));
            }
            return redirect()->back()->with('error', __('Contact not found.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
    public function add_note($id)
    {

        $contact = MarketingContact::where('id', $id)->first();
        if ($contact) {
            return view('marketing::contacts.add_note', compact('contact'));
        } else {
            return redirect()->back()->with('error', __('The contact is not found.'));
        }

    }
    public function note_store($id, Request $request)
    {

        if (auth()->user() && auth()->user()->isAbleTo('Edit Marketing Contact')) {
            $contacts = MarketingContact::where('id', $id)->first();
            $contacts->status = $request->status;
            $contacts->note = $request->note;
            $contacts->save();

            return redirect()->back()->with('Success', __('Note added successfully.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
}
