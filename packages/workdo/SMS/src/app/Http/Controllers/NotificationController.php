<?php

namespace Workdo\SMS\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Utility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Workdo\SMS\app\Models\Notification;
use Workdo\SMS\app\Models\NotificationTemplateLang;

class NotificationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $notifications = Notification::get();
        return view('sms::notification_templates.index', compact('notifications'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('sms::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        //
    }

    /**
     * Show the specified resource.
     */
    public function show($id, $lang = 'en')
    {
        $languages = Utility::languages();
        $notification = Notification::where('id', '=', $id)->first();

        $currTempLang = NotificationTemplateLang::where('parent_id', '=', $id)->where('lang', $lang)->first();
        if (!isset($currTempLang) || empty($currTempLang)) {
            $currTempLang = NotificationTemplateLang::where('parent_id', '=', $id)->where('lang', 'en')->first();
            if (!empty($currTempLang)) {
                $currTempLang->lang = $lang;
            } else {
                return redirect()->back()->with('error', __('Template Not Found.'));
            }
        }
        return view('sms::notification_templates.show',compact('notification', 'languages', 'currTempLang'));
    }

    public function storeNotificationLang(Request $request, $id)
    {
       
            $validator = \Validator::make(
                $request->all(), [
                    'content' => 'required',
                ]
            );

            if ($validator->fails()) {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }

            $notificationlLangTemplate = NotificationTemplateLang::where('parent_id', '=', $id)->where('lang', '=', $request->lang)->first();

            if (empty($notificationlLangTemplate)) {
                $notificationlLangTemplate = new NotificationTemplateLang();
                $notificationlLangTemplate->parent_id = $id;
                $notificationlLangTemplate->lang = $request['lang'];
                $notificationlLangTemplate->module = $request['module'];
                $notificationlLangTemplate->content = $request['content'];
                $notificationlLangTemplate->variables = $request['variables'];
                $notificationlLangTemplate->save();
            } else {
                $notificationlLangTemplate->content = $request['content'];
                $notificationlLangTemplate->save();
            }

            return redirect()->route(
                'manage.notification.language', [
                    $id,
                    $request->lang,
                ]
            )->with('success', __('Notification Template Detail successfully updated.'));

    }
}
