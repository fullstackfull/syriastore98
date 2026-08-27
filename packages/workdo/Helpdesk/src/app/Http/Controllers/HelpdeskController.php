<?php

namespace Workdo\Helpdesk\app\Http\Controllers;

use App\Models\EmailTemplate;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Workdo\Helpdesk\app\Models\HelpdeskCategory;
use Workdo\Helpdesk\app\Models\HelpdeskStatus;
use Workdo\Helpdesk\app\Models\HelpdeskTicket;
use Exception;
use Workdo\Helpdesk\DataTables\HelpdeskData;

class HelpdeskController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index(Request $request, HelpdeskData $dataTable, $status = '')
    {
        if (Auth::user() && Auth::user()->isAbleTo('Manage Helpdesk Ticket')) {
            $ticketStatus = HelpdeskStatus::all();
            $tickets =  HelpdeskTicket::with('getTicketCategory', 'getTicketStatus');
            if ($status == 1) {
                $tickets->where('status_id', '=', 1);
            } elseif ($status == 2) {
                $tickets->where('status_id', '=', 2);
            } elseif ($status == 3) {
                $tickets->where('status_id', '=', 3);
            } elseif ($status == 4) {
                $tickets->where('status_id', '=', 4);
            }
            if (Auth::user()->type == 'super admin') {
                $tickets =  $tickets->orderBy('id', 'desc')->get();
            }else{
                $tickets =  $tickets->where('user_id', Auth::user()->id)->orderBy('id', 'desc')->get();
            }
            return $dataTable->with('request', $request)->with('status', $status)->render('helpdesk::helpdesk_ticket.index', compact('tickets', 'ticketStatus','status'));
        } else {
            return redirect()->back()->with('error', 'Permission Denied !!');
        }
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        if (Auth::user() && Auth::user()->isAbleTo('Create Helpdesk Ticket')) {
            $helpdeskCategory = HelpdeskCategory::all();
            $helpdeskStatus = HelpdeskStatus::all();
            $company = [];
            if (Auth::user()->type == 'super admin') {
                $company = User::where('type', 'admin')->pluck('name', 'id');
            }else{
                $company = User::where('type', 'super admin')->pluck('name', 'id');
            }
            return view('helpdesk::helpdesk_ticket.create', compact('helpdeskCategory', 'helpdeskStatus', 'company'));
        } else {
            return redirect()->back()->with('error', 'Permission Denied !!');
        }
    }

    public function  getSelectedUserData(Request $request)
    {
        $user = User::find($request->user_id);
        if ($user) {
            $userData = [
                'name' => $user->name,
                'email' => $user->email,
            ];
            return response()->json($userData);
        } else {
            return response()->json(['error' => 'User not found']);
        }
    }
    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        if (Auth::user() && Auth::user()->isAbleTo('Create Helpdesk Ticket')) {
            if (Auth::user() && Auth::user()->type == 'super admin') {
                $request->validate([
                    'company_id' => 'required',
                    'email' => 'required|string|email',
                    'category' => 'required|string',
                    'subject' => 'required|string|max:255',
                    'status' => 'required|string',
                    'description' => 'required',
                ]);

                $user = User::find($request->company_id);
            } else {
                $request->validate([
                    'category' => 'required|string|max:255',
                    'subject' => 'required|string|max:255',
                    'status' => 'nullable|string|max:100',
                    'description' => 'required',
                ]);
                $user = Auth::user();
            }
            $ticket = new HelpdeskTicket();
            $ticket->ticket_id       = time();
            $data = [];

            if($request->hasfile('attachments'))
            {
                $totalImageSize = 0;
                if (!empty($request->file('attachments'))) {
                    foreach ($request->file('attachments') as $key => $image) {
                        $totalImageSize += \File::size($image);
                    }
                }
                $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $totalImageSize);
                if ($result != 1) {
                    return redirect()->back()->with('error', $result);
                }

                $errors=[];
                foreach($request->file('attachments') as $filekey => $file)
                {
                    if($result==1)
                    {
                        $imageName = $file->getClientOriginalName();
                        $dir        = 'uploads/helpdesk/' . $ticket->ticket_id;
                        $path = Utility::keyWiseUpload_file($request,'attachments',$imageName,$dir,$filekey,[]);
                        if($path['flag'] == 1){
                            $data[] = [
                                            'name' => $imageName,
                                            'path' => $path['url'],
                                        ];
                        }
                        else{
                            $errors = __($path['msg']);
                        }
                    }
                    else
                    {
                        return redirect()->back()->with('error', $result);
                    }
                $file   = 'helpdesk/' . $ticket->ticket_id . $imageName;

                }
            }
            if (!isset($request->status)) {
                $helpdeskStatus = HelpdeskStatus::where('status_name', 'Pending')->first();
                $status = $helpdeskStatus->id ?? null;
            } else {
                $status = $request->status;
            }
            $ticket->name           = !empty($user) ? $user->name : '';
            $ticket->email          = $user->email;
            $ticket->attachments    = json_encode($data);
            $ticket->category_id    = $request->category;
            $ticket->status_id      = $status;
            $ticket->subject        = $request->subject;
            $ticket->description    = !empty($request->description) ? $request->description : '';
            $ticket->user_id        = $user->id;
            $ticket->created_by     = Auth::user()->id;

            $ticket->save();

            $user = User::where('id', $ticket->created_by)->first();
            $ticket_url = route('helpdesk.ticket.view', [\Illuminate\Support\Facades\Crypt::encrypt($ticket->ticket_id)]);


            $superAdminSettings = getAdminAllSetting();
            if (isset($superAdminSettings['New Helpdesk Ticket']) && $superAdminSettings['New Helpdesk Ticket'] == true) {
                $superAdminEmail = User::where('type', 'super admin')->first();
                $uArr = [
                    'ticket_name' => $ticket->name,
                    'email' => isset($request->email) ? $request->email : $superAdminEmail->email,
                    'ticket_id' => $ticket->ticket_id,
                    'ticket_url' => $ticket_url,
                ];
                try {
                    $resp = EmailTemplate::sendEmailTemplate('New Helpdesk Ticket', [Auth::user()->type == 'super admin' ? $ticket->email : $superAdminEmail->email], $uArr);
                } catch (\Exception $e) {
                    $resp['error'] = $e->getMessage();
                }
                return redirect()->route('helpdesk.ticket.index')->with('success', __('Ticket Has Been Created Successfully.') . ((isset($resp['error'])) ? '<br> <span class="text-danger">' . $resp['error'] . '</span>' : ''));
            }

            return redirect()->route('helpdesk.ticket.index')->with('success', 'Ticket Has Been Successfully.');
        } else {
            return redirect()->back()->with('error', 'Permission Denied !!!');
        }
    }

    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($ticketId)
    {
        try {
            $ticketId = decrypt($ticketId);
        } catch (Exception $e) {
            return redirect()->back();
        }

        $ticket = HelpdeskTicket::with('getTicketCategory', 'getTicketStatus')->where('ticket_id', $ticketId)->first();
        if ($ticket) {
            return view('helpdesk::helpdesk_ticket.show', compact('ticket'));
        } else {
            return redirect()->back()->with('error', 'Something Went Wrong !!');
        }
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($ticketId)
    {
        if (Auth::user() && Auth::user()->isAbleTo('Edit Helpdesk Ticket')) {
            try {
                $ticketId = Decrypt($ticketId);
            } catch (Exception $th) {
                return redirect()->back();
            }

            $ticket = HelpdeskTicket::where('ticket_id', $ticketId)->first();
            if ($ticket) {
                $helpdeskCategory = HelpdeskCategory::all();
                $helpdeskStatus = HelpdeskStatus::all();
                $company = [];
                if (Auth::user()->type == 'super admin') {
                    $company = User::where('type', 'admin')->pluck('name', 'id');
                }else{
                    $company = User::where('type', 'super admin')->pluck('name', 'id');
                }
                return view('helpdesk::helpdesk_ticket.edit', compact('ticket', 'helpdeskCategory', 'helpdeskStatus', 'company'));
            } else {
                return redirect()->back()->with('error', 'Ticket Not Found !!');
            }
        } else {
            return redirect()->back()->with('error', 'Permission Denied !!');
        }
    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param int $id
     * @return Renderable
     */
    public function update(Request $request, $ticketId)
    {
        if (Auth::user() && Auth::user()->isAbleTo('Edit Helpdesk Ticket')) {
            if (Auth::user()->type == 'super admin') {
                $request->validate([
                    'company_id' => 'required',
                    'email' => 'required|string|email',
                    'category' => 'required|string',
                    'subject' => 'required|string|max:255',
                    'status' => 'required|string',
                    'description' => 'required',
                ]);

                $user = User::find($request->company_id);
            } else {
                $request->validate([
                    'category' => 'required|string|max:255',
                    'subject' => 'required|string|max:255',
                    'description' => 'required',
                ]);
                $user = Auth::user();
            }
            $ticketId = Decrypt($ticketId);
            $ticket = HelpdeskTicket::where('ticket_id', $ticketId)->first();

            if ($request->hasfile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $name = $file->getClientOriginalName();
                    $data[] = [
                        'name' => $name,
                        'path' => 'uploads/helpdesk/' . $ticket->ticket_id . '/' . $name,
                    ];
                    Utility::upload_file($file, 'attachments', $name, 'helpdesk/' . $ticket->ticket_id);
                }
                if ($request->hasfile('attachments')) {
                    $json_decode = json_decode($ticket->attachments);
                    $attachments = json_encode(array_merge($json_decode, $data));
                } else {
                    $attachments = json_encode($data);
                }
                $ticket->attachments = isset($attachments) ? $attachments : null;
            }

            $ticket->name           = $user->name;
            $ticket->user_id        = $user->id;
            $ticket->email          = !empty($user->email) ? $user->email : '';
            $ticket->category_id    = !empty($request->category) ? $request->category : '';
            if (!isset($request->status)) {
                $helpdeskStatus = HelpdeskStatus::where('status_name', 'Pending')->first();
                $status = $helpdeskStatus->id ?? null;
            } else {
                $status = $request->status;
            }
            $ticket->status_id      = $status;
            $ticket->subject        = !empty($request->subject) ? $request->subject : '';
            $ticket->description    = !empty($request->description) ? $request->description : '';
            $ticket->save();

            return redirect()->route('helpdesk.ticket.index')->with('success', __('Ticket Update successfully'));
        } else {
            return redirect()->back()->with('error', 'Permission Denied !!');
        }
    }

    public function ticketReply(Request $reqest, $ticketId)
    {
        if (Auth::user() && Auth::user()->isAbleTo('Reply Helpdesk Ticket')) {
            try {
                $ticketId = decrypt($ticketId);
            } catch (Exception $e) {
                return redirect()->back();
            }

            if ($ticketId) {
                $ticket = HelpdeskTicket::with('getTicketCategory', 'getTicketStatus')->where('ticket_id', $ticketId)->first();
                if ($ticket) {
                    return view('helpdesk::helpdesk_ticket.ticket_reply', compact('ticket'));
                } else {
                    return redirect()->back()->with('error', 'Ticket Not Found !!!');
                }
            } else {
                return redirect()->back()->with('error', 'Ticket Id Not Found !!!');
            }
        } else {
            return redirect()->back()->with('error', 'Permission Denied !!!');
        }
    }

    public function storeTicketNote(Request $request, $ticketId)
    {
        $ticket = HelpdeskTicket::find($ticketId);
        if ($ticket) {
            $ticket->note = $request->note;
            $ticket->save();

            return redirect()->back()->with('success', __('Ticket note saved successfully'));
        } else {
            return redirect()->back()->with('error', 'Something Wend Wrong !!!');
        }
    }

    public function deleteTicket($ticketId)
    {
        if (Auth::user() && Auth::user()->isAbleTo('Delete Helpdesk Ticket')) {
            $ticket = HelpdeskTicket::where('id', $ticketId)->first();
            if ($ticket) {
                delete_folder('helpdesk/' . $ticket->ticket_id);
                $ticket->delete();
                return redirect()->back()->with('success', 'Ticket Deleted Successfully.');
            } else {
                return redirect()->back()->with('error', 'Ticket Not Found !!');
            }
        } else {
            return redirect()->back()->with('error', 'Permssion Denied !!');
        }
    }
    /**
     * Remove the specified resource from storage.
     * @param int $id
     * @return Renderable
     */
    public function destroy($id)
    {
        //
    }
}
