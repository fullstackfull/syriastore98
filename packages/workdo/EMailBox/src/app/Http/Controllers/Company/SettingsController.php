<?php
// This file use for handle company setting page

namespace Workdo\EMailBox\app\Http\Controllers\Company;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\EMailBox\app\Models\EmailboxCredentail;

class SettingsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $mail_credentail = EmailboxCredentail::where('created_by', auth()->id())->first();
        return view('email-box::setting.email_configration
        ', compact('mail_credentail'));
    }

    /**
     * Store a newly created resource in storage.
     */

     public function store(Request $request)
     {
        $user = auth()->user();
        $store_id = $user->current_store;
         // Centralized validation rules
         $rules = [
             'emailbox_mail_driver' => 'required|string|max:255',
             'emailbox_mail_host' => 'required|string|max:255',
             'emailbox_outgoing_port' => 'required|string|max:255',
             'emailbox_incoming_port' => 'required|string|max:255',
             'emailbox_mail_username' => 'required|string|max:255',
             'emailbox_mail_password' => 'required|string|max:255',
             'emailbox_mail_encryption' => 'nullable|string|max:255',
             'emailbox_mail_from_address' => 'required|email|max:255',
             'emailbox_mail_from_name' => 'required|string|max:255',
         ];
     
         // Validate input with error handling
         $validated = $request->validate($rules);
     
         try {
          
             // Check if an entry exists (assuming uniqueness on store_id)
             $emailbox = EmailboxCredentail::firstOrNew(['created_by' => auth()->id()]); // Adjust key if needed
     
             // Populate model with validated data
             $emailbox->fill([
                 'emailbox_mail_driver' => $validated['emailbox_mail_driver'],
                 'emailbox_mail_host' => $validated['emailbox_mail_host'],
                 'emailbox_outgoing_port' => $validated['emailbox_outgoing_port'],
                 'emailbox_incoming_port' => $validated['emailbox_incoming_port'],
                 'emailbox_mail_username' => $validated['emailbox_mail_username'],
                 'emailbox_mail_password' => $validated['emailbox_mail_password'],
                 'emailbox_mail_encryption' => $validated['emailbox_mail_encryption'] ?? null,
                 'emailbox_mail_from_address' => $validated['emailbox_mail_from_address'],
                 'emailbox_mail_from_name' => $validated['emailbox_mail_from_name'],
                 'created_by' => auth()->id(),
             ]);
     
             // Save changes
             $emailbox->save();
     
             // Redirect with success message
             return redirect()->route('emailbox.configuration.index')
                 ->with('success', $emailbox->wasRecentlyCreated 
                     ? __('Emailbox configuration created successfully.') 
                     : __('Emailbox configuration updated successfully.'));
         } catch (\Exception $e) {
             // Log the error for debugging
             \Log::error('Emailbox configuration error: ' . $e->getMessage());
     
             // Redirect back with error message
             return back()->withInput()->withErrors(['error' => __('An unexpected error occurred. Please try again.')]);
         }
     }
  
     
}
