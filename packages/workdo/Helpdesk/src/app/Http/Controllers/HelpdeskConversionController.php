<?php

namespace Workdo\Helpdesk\app\Http\Controllers;

use App\Models\EmailTemplate;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Workdo\Helpdesk\app\Models\HelpdeskConversion;
use Workdo\Helpdesk\app\Models\HelpdeskTicket;

class HelpdeskConversionController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */

    public function storeTicketConversion(Request $request, $ticketId)
    {
        if (Auth::user() && Auth::user()->isAbleTo('Reply Helpdesk Ticket')) {
            $ticket = HelpdeskTicket::where('id', $ticketId)->first();
            if ($ticket) {
                if (Auth::check()) {
                    $user        = User::where('id', Auth::user()->id)->first();
                } else {
                    $user        = User::where('id', $ticket->created_by)->first();
                }
                $validation = ['reply_description' => ['required']];
                $validator = Validator::make(
                    $request->all(),
                    $validation
                );
                if ($validator->fails()) {
                    $messages = $validator->getMessageBag();
                    return redirect()->back()->withInput()->with('error', $messages->first());
                }


                $post = [];
                $post['ticket_id'] = $ticket->id;
                $post['description'] = $request->reply_description;
                $post['sender'] = ($user) ? $user->id : 'user';
                $data = [];
                if ($request->hasfile('reply_attachments')) {
                    $totalImageSize = 0;
                    if (!empty($request->file('reply_attachments'))) {
                        foreach ($request->file('reply_attachments') as $key => $image) {
                            $totalImageSize += \File::size($image);
                        }
                    }
                    $result = Utility::updateStorageLimit($user->id, $totalImageSize);
                    if ($result != 1) {
                        return redirect()->back()->with('error', $result);
                    }
                    foreach ($request->file('reply_attachments') as $file) {
                        $name = $file->getClientOriginalName();
                        $data[] = [
                            'name' => $name,
                            'path' => 'uploads/helpdesk/' . $ticket->ticket_id . '/' . $name
                        ];
                        Utility::upload_file($file, 'reply_attachments', $name, 'helpdesk/' . $ticket->ticket_id);
                    }
                }
                $post['attachments'] = json_encode($data);
                HelpdeskConversion::create($post);
                $superAdminSettings = getAdminAllSetting();
                if (isset($superAdminSettings['New Helpdesk Ticket Reply']) && $superAdminSettings['New Helpdesk Ticket Reply'] == true) {
                    $superAdminEmail = User::where('type', 'super admin')->first();
                    $uArr = [
                        'ticket_name' => $ticket->name,
                        'ticket_id' => $ticket->ticket_id,
                        'email' => $ticket->email,
                        'reply_description' => $request->reply_description,

                    ];

                    $error_msg = EmailTemplate::sendEmailTemplate('New Helpdesk Ticket Reply', [Auth::user()->type == 'super admin' ? $ticket->email : $superAdminEmail->email], $uArr);
                    return redirect()->back()->with('success', __('Reply added successfully') . ((isset($error_msg['error'])) ? '<br> <span class="text-danger">' . $error_msg['error'] . '</span>' : ''));
                }
                return redirect()->back()->with('success', __('Reply added successfully.'));
            } else {
                return redirect()->back()->with('error', 'Ticket Not Found !!');
            }
        } else {
            return redirect()->back()->with('error', 'Permission Denied.');
        }
    }

    public function showLinkTicketReply(Request $request, $ticketId)
    {
        $ticket = HelpdeskTicket::where('ticket_id', $ticketId)->first();
        if ($ticket) {
            $validation = [
                'reply_description' => 'required'
            ];
            $validator = Validator::make(
                $request->all(),
                $validation
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->withInput()->with('error', $messages->first());
            }
            $post                = [];
            $post['sender']      = 'user';
            $post['ticket_id']   = $ticket->id;
            $post['description'] = $request->reply_description;
            $data                = [];
            if ($request->hasfile('reply_attachments')) {
                $totalImageSize = 0;
                if (!empty($request->file('reply_attachments'))) {
                    foreach ($request->file('reply_attachments') as $key => $image) {
                        $totalImageSize += \File::size($image);
                    }
                }
                $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $totalImageSize);
                if ($result != 1) {
                    return redirect()->back()->with('error', $result);
                }
                foreach ($request->file('reply_attachments') as $file) {

                    $name = $file->getClientOriginalName();
                    $data[] = [
                        'name' => $name,
                        'path' => 'uploads/helpdesk/' . $ticket->ticket_id . '/' . $name
                    ];
                    Utility::upload_file($file, 'attachments', $name, 'helpdesk/' . $ticket->ticket_id);
                }
            }
            $post['attachments'] = json_encode($data);
            HelpdeskConversion::create($post);

            // Send Email to User
            try {
                if (Auth::check()) {
                    $user        = User::where('id', Auth::user()->id)->first();
                    $superAdmin = User::where('type', 'super admin')->first();
                } else {
                    $user  = User::where('id', $ticket->created_by)->first();
                }
                $superAdminSettings = getAdminAllSetting();
                if (isset($superAdminSettings['New Helpdesk Ticket Reply']) && $superAdminSettings['New Helpdesk Ticket Reply'] == true) {
                    $uArr = [
                        'ticket_name' => $ticket->name,
                        'ticket_id' => $ticket->ticket_id,
                        'email' => $ticket->email,
                        'reply_description' => $request->reply_description,
                    ];
                    try {
                        if (Auth::check()) {
                            if (Auth::user()->type == 'super admin') {
                                $resp =   EmailTemplate::sendEmailTemplate('New Helpdesk Ticket Reply', [$ticket->email], $uArr);
                            } else {
                                $resp = EmailTemplate::sendEmailTemplate('New Helpdesk Ticket Reply', [$superAdmin->email], $uArr);
                            }
                        } else {
                            $resp =   EmailTemplate::sendEmailTemplate('New Helpdesk Ticket Reply', [$user->email], $uArr, $user->id);
                        }
                    } catch (\Exception $e) {
                        $resp['error'] = $e->getMessage();
                    }
                }
            } catch (\Exception $e) {
                $resp['status'] = false;
                $resp['error'] = $e->getMessage();
            }
            return redirect()->back()->with('success', __('Reply added successfully.') . ((isset($resp['error'])) ? '<br> <span class="text-danger">' . $resp['error'] . '</span>' : ''));
        } else {
            return redirect()->back()->with('error', 'Something Wend Wrong !!!');
        }
    }

    public function index()
    {
        return view('helpdesk::index');
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        return view('helpdesk::create');
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('helpdesk::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('helpdesk::edit');
    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param int $id
     * @return Renderable
     */
    public function update(Request $request, $id)
    {
        //
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
