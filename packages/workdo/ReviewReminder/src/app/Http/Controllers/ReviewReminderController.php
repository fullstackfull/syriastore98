<?php

namespace Workdo\ReviewReminder\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\CommonEmailTemplate;
use App\Models\EmailTemplate;
use App\Models\EmailTemplateLang;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Models\UserEmailTemplate;
use App\Models\Utility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Workdo\ReviewReminder\app\Models\OrderReviews;
use Workdo\ReviewReminder\DataTables\ReviewOrdersDataTable;

class ReviewReminderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(ReviewOrdersDataTable $dataTable)
    {

        if (!auth()->user()) {
            return redirect()->route('login');
        }
        if (auth()->user()->isAbleTo('Manage Order Reviews')) {
            $this->EmailTemplateDefaultData();
            $this->userTemplateDefaultData(Auth::user()->id);
            return $dataTable->render('review-reminder::index');
        } else {

            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    // For Email template Module
    public static function EmailTemplateDefaultData()
    {
        // Email Template
        $emailTemplate = [
            'Review Reminder',
        ];
        foreach ($emailTemplate as $eTemp) {
            $emailTemp = EmailTemplate::where('name', $eTemp)->where('created_by', Auth::user()->id)->count();
            if ($emailTemp == 0) {
                EmailTemplate::create(
                    [
                        'name' => $eTemp,
                        'from' => env('APP_NAME'),
                        'created_by' => Auth::user()->id,
                    ]
                );
            }
        }

        $defaultTemplate = [
            'Review Reminder' => [
                'subject' => 'Order Review',
                'language' => [
                    'ar' => '<p>عزيزي { customer_name } ،<br />شكرا لاختيارك { store_name }. نأمل أن تكون قد تم رضائك عن { store_name }.</p><p><strong>برجاء ترك التعليق التقييمي هنا</strong>: { review_url }</p><p>شكرا لك ، ونحن نتطلع لرؤيتك مرة أخرى في { app_name }.</p>',
                    'da' => '<p>Kære { customer_name },<br />Tak, fordi du har valgt { store_name }. Vi håber, at du er tilfreds med dit { store_name }.</p><p><strong>Vær venlig at give din feedback her</strong>: { review_url }</p><p>Tak, og vi ser frem til at se dig igen på { app_name }.</p>',
                    'de' => '<p>Liebe {customer_name},<br />Vielen Dank für die Auswahl von {store_name}. Wir hoffen, dass Sie mit Ihrem {store_name} zufrieden waren.</p><p><strong>Bitte hinterlassen Sie Ihr Feedback hier</strong>: {review_url}</p><p>Vielen Dank, und wir freuen uns darauf, Sie erneut unter {app_name} zu sehen.</p>',
                    'en' => '<p>Dear {customer_name},<br />Thank you for choosing {store_name}. We hope you were satisfied with your {store_name}.</p><p><strong>Please leave your feedback here</strong>: {review_url}</p><p>Thank you, and we look forward to seeing you again at {app_name}.</p>',
                    'es' => '<p>Estimado {customer_name},<br />Gracias por elegir {store_name}. Esperamos que esté satisfecho con su {store_name}.</p><p><strong>Por favor, deje sus comentarios aquí</strong>: {review_url}</p><p>Gracias, y esperamos volver a verlos en {app_name}.</p>',
                    'fr' => '<p>Cher { customer_name },<br />Merci davoir choisi { store_name }. Nous espérons que vous avez été satisfait de votre { store_name }.</p><p><strong>Veuillez laisser vos commentaires ici</strong>: { review_url }</p><p>Merci, et nous avons hâte de vous revoir à ladresse { app_name }.</p>',
                    'it' => '<p>Caro {customer_name},<br />Grazie per la scelta di {store_name}. Speriamo che tu fossi soddisfatto del tuo {store_name}.</p><p><strong>Ti prego di lasciare il tuo feedback qui</strong>: {revisw_url}</p><p>Grazie e non vediamo lora di rivederti a {app_name}.</p>',
                    'ja' => '<p>{ customer_name }、<br />{ store_name}を選択していただき、ありがとうございます。</p><p><strong>フィードバックをここにお送りください</strong>: {review_url}</p><p>ありがとうございます。また、 {app_name}で再度お客様にお会いできることをお待ちしています。</p>',
                    'nl' => '<p>Beste { customer_name },<br />Bedankt voor het kiezen van { store_name }. We hopen dat u tevreden bent met uw { store_name }.</p><p><strong>Laat uw feedback hier</strong>: { review_url }</p><p>Dank u, en we kijken ernaar uit u opnieuw te zien op { app_name }.</p>',
                    'pl' => '<p>Szanowny {customer_name },<br />Dziękujemy za wybranie {store_name }. Mamy nadzieję, że jesteś zadowolony z {store_name }.</p><p><strong>Proszę zostawić tu swoje opinie</strong>: {review_url }</p><p>Dziękujemy i czekamy na ponowne zobaczenie Cię w aplikacji {app_name }.</p>',
                    'ru' => '<p>Уважаемый пользователь { customer_name },<br />Спасибо, что вы выбрали { store_name }. Мы надеемся, что вас удовлетворит ваш { store_name }.</p><p><strong>Пожалуйста, оставьте свой отзыв здесь</strong>: { review_url }</p><p>Спасибо, и мы надеемся увидеть вас снова в { app_name }.</p>',
                    'pt' => '<p>Caros {customer_name},<br />Obrigado por escolher {store_name}. Esperamos que você tenha ficado satisfeito com o seu {store_name}.</p><p><strong>Deixe seu feedback aqui</strong>: {review_url}</p><p>Obrigado, e estamos ansiosos para vê-lo novamente em {app_name}.</p>',
                    'zh' => '<p>尊敬的 {customer_name}，<br />感谢您选择 {store_name}。我们希望您对 {store_name} 满意。</p><p><strong>请在此处留下您的反馈</strong>: {review_url}</p><p>谢谢，我们期待在 {app_name} 再次见到您。</p>',
                    'he' => '<p>היקר {customer_name},<br />תודה שבחרתם {store_name}. אנו מקווים שהייתם מסופקים עם {store_name}.</p><p><strong>אנא השאירו את המשוב שלכם כאן</strong>: {review_url}</p><p>תודה, ואנו מצפים לראות אתכם שוב ב - {app_name}.</p>',
                    'tr' => '<p>Sayın { customer_name },<br />{ store_name } ürününü seçtiğiniz için teşekkür ederiz. { store_name } mağazalarınızdan memnun olduğunuzu umuyoruz.</p><p><strong>Lütfen geribildiriminizi buraya bırakın</strong>: { review_url }</p><p>Teşekkür ederiz, ve sizi { app_name } içinde tekrar görmek için sabırsızlıkla bekliyoruz.</p>',
                    'pt-br' => '<p>Caros {customer_name},<br />Obrigado por escolher {store_name}. Esperamos que você tenha ficado satisfeito com o seu {store_name}.</p><p><strong>Deixe seu feedback aqui</strong>: {review_url}</p><p>Obrigado, e estamos ansiosos para vê-lo novamente em {app_name}.</p>',
                ],
            ],
        ];

        $email = EmailTemplate::where('created_by', Auth::user()->id)->get();

        foreach ($email as $e) {
            if (isset($defaultTemplate[$e->name])) {
                foreach ($defaultTemplate[$e->name]['language'] as $lang => $content) {
                    $emailNoti = EmailTemplateLang::where('parent_id', $e->id)->where('language', $lang)->count();
                    if ($emailNoti == 0) {
                        EmailTemplateLang::create(
                            [
                                'parent_id' => $e->id,
                                'language' => $lang,
                                'subject' => $defaultTemplate[$e->name]['subject'],
                                'content' => $content,
                            ]
                        );
                    }
                }
            }
        }
    }

    public static function userTemplateDefaultData($id)
    {
        // Make Entry In User_Email_Template
        $allEmail = EmailTemplate::where('created_by', $id)->get();
        foreach ($allEmail as $email) {
            $userEmailTemplate = UserEmailTemplate::firstOrNew(
                [
                    'template_id' => $email->id,
                    'user_id' => $id,
                ]
            );

            if (!$userEmailTemplate->exists) {
                $userEmailTemplate->is_active = 0;
                $userEmailTemplate->save();
            }
        }
    }

    public function manageReviewEmailLang(Request $request, $id, $language = 'en')
    {
        $languages = Utility::languages();
        $emailTemplate = EmailTemplate::where('id', '=', $id)->first();
        $currEmailTempLang = EmailTemplateLang::where('parent_id', '=', $id)->where('language', $language)->first();
        session()->put('app_setting_tab', $request->app_setting_tab ?? 'pills-review_reminder-tab');
        if (!isset($currEmailTempLang) || empty($currEmailTempLang)) {
            EmailTemplateLang::where('parent_id', '=', $id)->update(['is_default' => 0]);
            $currEmailTempLang = EmailTemplateLang::where('parent_id', '=', $id)->where('language', 'en')->first();
            $currEmailTempLang->language = $language;
            $currEmailTempLang->is_default = 1;
            $currEmailTempLang->save();
        } else {
            EmailTemplateLang::where('parent_id', '=', $id)->update(['is_default' => 0]);
            $currEmailTempLang->language = $language;
            $currEmailTempLang->is_default = 1;
            $currEmailTempLang->save();
        }

        $UseremailTemplate = UserEmailTemplate::where('template_id', $id)->where('user_id', auth()->user()->id)->first();
        if ($UseremailTemplate) {
            $UseremailTemplate->is_active = ($request->is_active == 'on' || $request->is_active == '1') ? 1 : 0;
            $UseremailTemplate->save();
        }
        return redirect()->route('app-setting.index');
    }

    public function updateReviewEmailSettings(Request $request, $id)
    {
      
        session()->put('app_setting_tab', $request->app_setting_tab ?? 'pills-review_reminder-tab');
        $validator = \Validator::make(
            $request->all(),
            [
                'is_active'=>'required',
                'from' => 'required',
                'subject' => 'required',
                'content' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();

            return redirect()->back()->with('error', $messages->first());
        }

        $user = auth()->user();
        $emailTemplate = $Template = EmailTemplate::where('id', $id)->first();
        if ($user->type == 'admin') {
            if ($request->is_active == '1') {
                UserEmailTemplate::where('user_id', $user->id)->where('template_id', $Template['id'])->update(['is_active' => 0]);
                $UserEmailTemplate = UserEmailTemplate::where('user_id', $user->id)->where('template_id', $Template['id'])->first();
                $UserEmailTemplate->is_active = 1;
                $UserEmailTemplate->save();
            } else {
                $UserEmailTemplate = UserEmailTemplate::where('user_id', $user->id)->where('template_id', $emailTemplate->id)->first();
                $UserEmailTemplate->is_active = 0;
                $UserEmailTemplate->save();
                
            }
        }

        $emailTemplate->from = $request->from;
        $emailTemplate->save();

        $emailLangTemplate = EmailTemplateLang::where('parent_id', '=', $id)->where('language', '=', $request->language)->first();

        if (empty($emailLangTemplate)) {
            $emailLangTemplate = new EmailTemplateLang();
            $emailLangTemplate->parent_id = $id;
            $emailLangTemplate->language = $request['language'];
            $emailLangTemplate->subject = $request['subject'];
            $emailLangTemplate->content = $request['content'];
            $emailLangTemplate->save();
        } else {
            $emailLangTemplate->subject = $request['subject'];
            $emailLangTemplate->content = $request['content'];
            $emailLangTemplate->save();
        }

        return redirect()->route('app-setting.index');
    }

    public static function sendOwnerEmailTemplate($emailTemplate, $mailTo, $customerName, $obj, $owner, $store, $order)
    {
        // find template is exist or not in our record
        $template = EmailTemplate::where('name', 'LIKE', $emailTemplate)->first();

        if (isset($template) && !empty($template)) {

            // check template is active or not by company
            $is_actives = UserEmailTemplate::where('template_id', '=', $template->id)->where('user_id', $owner->id)->first();

            $slug = Store::where('created_by', $template->created_by)->select('slug')->first()->slug;

            $setting = getAdminAllSetting($owner->id);

            if ($is_actives->is_active == 1) {

                // get email content language base
                $content = EmailTemplateLang::where('parent_id', '=', $template->id)->where('language', 'LIKE', $owner->language)->first();

                $content->from = $template->from;

                if ($order != null && !empty($content->content)) {

                    // send email
                    try {
                        config(
                            [
                                'mail.driver' => $setting['MAIL_DRIVER'],
                                'mail.host' => $setting['MAIL_HOST'],
                                'mail.port' => $setting['MAIL_PORT'],
                                'mail.encryption' => $setting['MAIL_ENCRYPTION'],
                                'mail.username' => $setting['MAIL_USERNAME'],
                                'mail.password' => $setting['MAIL_PASSWORD'],
                                'mail.from.address' => $setting['MAIL_FROM_ADDRESS'],
                                'mail.from.name' => $setting['MAIL_FROM_NAME'],
                            ]
                        );

                        $obj['customer_name'] = $customerName;
                        $obj['store_name'] = $store->name;
                        $obj['review_url'] = '<a href="' . route('order.review', ['slug' => $slug, 'order' => $order]) . '">Review Link</a>';

                        $content->content = self::replaceVariable($content->content, $obj, $store);

                        $ownername = User::where('id', $store->created_by)->first();

                        if ($mailTo == $ownername->email) {

                            Mail::to(
                                [
                                    $store['email'],
                                ]
                            )->send(new CommonEmailTemplate($content, $setting, $store));
                        } else {
                            Mail::to(
                                [
                                    $mailTo,
                                ]
                            )->send(new CommonEmailTemplate($content, $setting, $store));
                        }

                    } catch (\Exception $e) {
                        $error = __('E-Mail has been not sent due to SMTP configuration');
                    }
                    if (isset($error)) {
                        $arReturn = [
                            'is_success' => false,
                            'error' => $error,
                        ];
                    } else {
                        $arReturn = [
                            'is_success' => true,
                            'error' => false,
                        ];
                    }
                } else {
                    $arReturn = [
                        'is_success' => false,
                        'error' => __('Mail not send, email is empty'),
                    ];
                }
                return $arReturn;
            } else {
                return [
                    'is_success' => true,
                    'error' => false,
                ];
            }
        } else {
            return [
                'is_success' => false,
                'error' => __('Mail not send, email not found'),
            ];
        }
    }

    public static function replaceVariable($content, $obj, $store)
    {
        $arrVariable = [
            '{app_name}',
            '{store_name}',
            '{review_url}',
            '{customer_name}',
        ];
        $arrValue = [
            'app_name' => '-',
            'store_name' => '-',
            'review_url' => '-',
            'customer_name' => '-',
        ];

        foreach ($obj as $key => $val) {
            $arrValue[$key] = $val;
        }

        $arrValue['app_name'] = $store->name;
        $arrValue['app_url'] = '<a href="' . env('APP_URL') . '" target="_blank">' . env('APP_URL') . '</a>';

        $ownername = User::where('id', $store->created_by)->first();

        return str_replace($arrVariable, array_values($arrValue), $content);
    }

    public function orderReview(Request $request, $slug = null)
    {
        try {
            $slug = $request->slug;
            $orderid = !empty($request) ? $request->order : '';
            $orderId = Crypt::decrypt($orderid);
            $orderDetail = Order::find($orderId);
            $store = getStoreById($orderDetail->store_id);
            $storeId = $store->id;
            $storeName = $store->name;

            return view('review-reminder::review.order_review', compact('slug', 'orderId', 'storeId', 'storeName'));
        } catch (DecryptException $e) {
            return redirect()->back()->with('error', __('Something was wrong.'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Something was wrong.'));
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function orderReviewStore(Request $request, $slug = null, $order_id = null)
    {
        try {
            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => 'required',
                    'email_id' => 'required',
                    'rating' => 'required',
                    'description' => 'required',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $orderId = Crypt::decrypt($order_id);
            $order = Order::find($orderId);
            $productOrderId = $order->product_order_id;

            if ($order) {
                $orderReview = new OrderReviews();
                $orderReview->name = $request->name ?? '';
                $orderReview->email = $request->email_id ?? '';
                $orderReview->rating = $request->input('rating') ?? '';
                $orderReview->description = $request->description ?? '';
                $orderReview->order_id = $orderId ?? 0;
                $orderReview->product_order_id = $productOrderId ?? 0;
                $orderReview->store_id = $request->store_id ?? 0;
                $orderReview->save();

                return redirect()->back()->with('success', __('Order Review saved successfully'));
            } else {
                return redirect()->back()->with('error', __('Order Not Found'));
            }
        } catch (DecryptException $e) {
            return redirect()->back()->with('error', __('Something was wrong.'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Something was wrong.'));
        }
    }

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('review-reminder::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('review-reminder::edit');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id): RedirectResponse
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($orderReviewId)
    {
        if (auth()->user()->isAbleTo('Delete Order Reviews')) {

            OrderReviews::where('id', $orderReviewId)->delete();

            return redirect()->back()->with('success', __('Order review delete successfully.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
}
