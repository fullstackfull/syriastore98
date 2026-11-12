<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\EmailTemplate;
use App\Models\EmailTemplateLang;
use App\Models\UserEmailTemplate;
use App\Models\Utility;

class EmailTemplateController extends Controller
{
    public function __construct()
    {
        if(auth()->check())
        {
            \App::setLocale(auth()->user()->language);
        }
    }

    public function emailTemplate($language = 'en')
    {
        // $usr = \Auth::user();
        $languages         = Utility::languages();
        $emailTemplate     = EmailTemplate::first();

        /* P2.5 -- AN EMPTY TABLE WAS A 500, NOT AN EMPTY STATE.
           =========================================================================================

           `EmailTemplate::first()` returns null when `email_templates` holds no rows, and the next
           line read `$emailTemplate->id`. MEASURED on a migrated database with the table empty:
           HTTP 500 in all four language/direction/theme combinations, with no page at all.

           PRODUCTION IS NOT CURRENTLY AFFECTED -- the dump ships six platform templates and five
           language rows -- so this is a fail-safe rather than a live repair. The reachable states
           it covers are a freshly migrated environment before the addon seeder has run, and a
           tenant whose templates have been removed. Neither should be a white error page.

           The SECOND dereference below is the same defect one line further on: when a template
           exists but has no `en` language row, `$currEmailTempLang->lang = $language` wrote to
           null. Both are guarded here, and the screen now says what is missing instead. */
        if ($emailTemplate === null) {
            return redirect()->back()->with(
                'error',
                __('No email template has been set up yet.')
            );
        }

        $currEmailTempLang = EmailTemplateLang::where('parent_id', '=', $emailTemplate->id)->where('language', $language)->first();

        if(!isset($currEmailTempLang) || empty($currEmailTempLang))
        {
            $currEmailTempLang       = EmailTemplateLang::where('parent_id', '=', $emailTemplate->id)->where('language', 'en')->first();

            if ($currEmailTempLang === null) {
                return redirect()->back()->with(
                    'error',
                    __('This email template has no content in any language yet.')
                );
            }

            $currEmailTempLang->lang = $language;

        }

        $EmailTemplates = EmailTemplate::where('created_by',\Auth::user()->id)->get();
        return view('email_templates.show', compact('emailTemplate', 'languages', 'currEmailTempLang','language','EmailTemplates'));
    }

    public function manageEmailLang($id, $language = 'en')
    {
        $languages         = Utility::languages();
        $emailTemplate     = EmailTemplate::where('id', '=', $id)->first();
        $currEmailTempLang = EmailTemplateLang::where('parent_id', '=', $id)->where('language', $language)->first();

        if(!isset($currEmailTempLang) || empty($currEmailTempLang))
        {
            $currEmailTempLang       = EmailTemplateLang::where('parent_id', '=', $id)->where('language', 'en')->first();

            if ($currEmailTempLang) {
                $currEmailTempLang->language = $language;
            }
        }

        $EmailTemplates = EmailTemplate::where('created_by',\Auth::user()->id)->get();
        return view('email_templates.show', compact('emailTemplate', 'languages', 'currEmailTempLang','EmailTemplates'));
    }

    public function updateEmailSettings(Request $request,$id)
    {
        $validator = \Validator::make(
            $request->all(), [
                               'from' => 'required',
                               'subject' => 'required',
                               'content' => 'required',
                           ]
        );

        if($validator->fails())
        {
            $messages = $validator->getMessageBag();

            return redirect()->back()->with('error', implode(' ', $messages->all()));
        }

        $emailTemplate       = EmailTemplate::where('id',$id)->first();
        $emailTemplate->from = $request->from;
        $emailTemplate->save();

        $emailLangTemplate = EmailTemplateLang::where('parent_id', '=', $id)->where('language', '=', $request->language)->first();
        // if record not found then create new record else update it.
        if(empty($emailLangTemplate))
        {
            $emailLangTemplate            = new EmailTemplateLang();
            $emailLangTemplate->parent_id = $id;
            $emailLangTemplate->language      = $request['language'];
            $emailLangTemplate->subject   = $request['subject'];
            $emailLangTemplate->content   = $request['content'];
            $emailLangTemplate->save();
        }
        else
        {
            $emailLangTemplate->subject = $request['subject'];
            $emailLangTemplate->content = $request['content'];
            $emailLangTemplate->save();
        }

        return redirect()->route(
            'manage.email.language', [
                                       $emailTemplate->id,
                                       $request->lang,
                                   ]
        )->with('success', __('Email Template successfully updated.'));
    }
    public function updateEmailNotificationStatus(Request $request)
    {
        session()->put(['setting_tab' => 'email_notify_setting']);
        $post = $request->all();
        unset($post['_token']);

        $user = auth()->user();

        if($user->type == 'super admin' || $user->type == 'admin')
        {
            UserEmailTemplate::where('user_id', $user->id)->update([ 'is_active' => 0]);
            foreach ($post as $key => $value) {
                $UserEmailTemplate  = UserEmailTemplate::where('user_id', $user->id)->where('template_id', $key)->first();
                $UserEmailTemplate->is_active = $value;
                $UserEmailTemplate->save();
            }
            return redirect()->back()->with('success', __('Status successfully updated!'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission Denied.'));

        }
    }
}
