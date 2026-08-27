<?php

namespace Workdo\PartialPayments\app\Http\Controllers;

use App\Events\OrderPaymentStatus;
use App\Http\Controllers\Api\ApiController;
use App\Mail\CommonEmailTemplate;
use App\Models\EmailTemplate;
use App\Models\EmailTemplateLang;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Models\UserEmailTemplate;
use App\Models\Utility;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\PartialPayments\app\Models\OrderPartialPayments;
use Workdo\PartialPayments\app\Models\PartialPayments;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Auth;
use DB;
use Workdo\PartialPayments\DataTables\PartialPaymentData;
use Illuminate\Support\Facades\Cache;

class PartialPaymentsController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index(PartialPaymentData $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Partial Payment')) {
            $partial_payment = OrderPartialPayments::where('store_id', getCurrentStore())->get();
            return $dataTable->render('partial-payments::admin.index', compact('partial_payment'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        return view('partial-payments::create');
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
        return view('partial-payments::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Show Partial Payment')) {
            try {
                $id = Crypt::decrypt($id);
                $partial_payment = OrderPartialPayments::find($id);
                if ($partial_payment) {
                    $order_data = Order::where('product_order_id', $partial_payment->order_id)->where('store_id', $partial_payment->store_id)->first();
                    $order = Order::order_detail($order_data->id);

                    $store = Store::find(getCurrentStore());
                    $payment_data = OrderPartialPayments::where('order_id', $order_data->product_order_id)->where('store_id', $partial_payment->store_id)->get();

                    return view('partial-payments::admin.edit', compact('partial_payment', 'order', 'payment_data', 'store'));
                } else {
                    return redirect()->back()->with('error', __('The partial payment is not found.'));
                }
            } catch (DecryptException $e) {
                return redirect()->back()->with('error', __('Something was wrong.'));
            } catch (\Exception $e) {
                return redirect()->back()->with('error', __('Something was wrong.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
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
        if (auth()->user() && auth()->user()->isAbleTo('Delete Partial Payment')) {
            $payment = OrderPartialPayments::find($id);
            if ($payment) {
                $payment->delete();
                return redirect()->back()->with('success', __('Partial Payment delete successfully.'));
            } else {
                return redirect()->back()->with('error', __('The partial payment is not found.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    // For Email template Module
    public static function EmailTemplateDefaultData()
    {
        // Email Template
        $emailTemplate = [
            'Payment Reminder',
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
            'Payment Reminder' => [
                'subject' => 'Payment Reminder',
                'language' => [
                    'ar' => '<p>عزيزي {customer_name}،<br /></p><p> هذا تذكير ودي بشأن الدفع الجزئي. </p><p> لقد استلمنا الدفعة الأولية بقيمة {amount} في {date} على الفور، ونحن نشكرك على ذلك.</p><p><br>يستحق الرصيد المتبقي بقيمة {remaining_amount} في {remaining_date} date.</p><p> يرجى التأكد من إتمام هذه الدفعة في الوقت المناسب لتجنب أي إزعاج.</p><p> شكرًا لك على تعاونك.</p>',
                    'da' => '<p>Kære {customer_name}<br /></p><p> Dette er en venlig påmindelse om delbetalingen. </p><p> Vi har modtaget den første betaling på {amount} den {date} omgående, hvilket vi takker dig for.</p><p><br>Den resterende saldo på {remaining_amount} forfalder i {remaining_date} dato.</p><p> Sørg for rettidig gennemførelse af denne betaling for at undgå ulejlighed.</p><p> Tak for dit samarbejde.</p>',
                    'de' => '<p>Sehr geehrter {customer_name},<br /></p><p> Dies ist eine freundliche Erinnerung an die Teilzahlung. </p><p>Wir haben die erste Zahlung in Höhe von {amount} am {date} umgehend erhalten, wofür wir uns bei Ihnen bedanken.</p><p><br>Der Restbetrag von {remaining_amount} ist fällig in {remaining_date} Datum.</p><p> Bitte stellen Sie sicher, dass diese Zahlung rechtzeitig abgeschlossen wird, um etwaige Unannehmlichkeiten zu vermeiden.</p><p> Vielen Dank für Ihre Kooperation.</p>',
                    'en' => '<p>Dear {customer_name},<br /></p><p> This is a friendly reminder about the partial payment. </p><p> We have received the initial payment of {amount} on {date} promptly, for which we thank you.</p><p><br>The remaining balance of {remaining_amount} is due in {remaining_date} date.</p><p> Please ensure timely completion of this payment to avoid any inconvenience.</p><p> Thank you for your cooperation.</p>',
                    'es' => '<p>Estimado {customer_name}:<br /></p><p> Este es un recordatorio amistoso sobre el pago parcial. </p><p>Hemos recibido el pago inicial de {amount} el {date} puntualmente, por lo que le agradecemos.</p><p><br>El saldo restante de {remaining_amount} vence en {remaining_date}.</p><p> Asegúrese de completar este pago a tiempo para evitar cualquier inconveniente.</p><p> Gracias por su cooperación.</p>',
                    'fr' => '<p>Cher {customer_name},<br /></p><p> Ceci est un rappel amical concernant le paiement partiel. </p><p> Nous avons reçu le paiement initial de {amount} le {date} dans les plus brefs délais, ce dont nous vous remercions.</p><p><br>Le solde restant de {remaining_amount} est dû dans {remaining_date} date.</p><p> Veuillez vous assurer deffectuer ce paiement dans les délais pour éviter tout inconvénient.</p><p> Merci de votre coopération.</p>',
                    'it' => '<p>Gentile {customer_name},<br /></p><p> Questo è un amichevole promemoria sul pagamento parziale. </p><p> Abbiamo ricevuto tempestivamente il pagamento iniziale di {amount} in data {date}, per questo ti ringraziamo.</p><p><br>Il saldo rimanente di {remaining_amount} è dovuto in {remaining_date} data.</p><p> Assicurati di completare tempestivamente questo pagamento per evitare eventuali inconvenienti.</p><p> Grazie per la collaborazione.</p>',
                    'ja' => '<p>{customer_name} 様<br /></p><p> 一部お支払いについてのお知らせです。 </p><p> {date} に最初の {amount} のお支払いを速やかに受け取りました。ありがとうございます。</p><p><br>{remaining_amount} の残りの残高は、{remaining_date}。 </p><p>ご迷惑を避けるため、この支払いは期限内に完了してください。</p><p>ご協力いただきありがとうございます。</p>',
                    'nl' => '<p>Beste {customer_name},<br /></p><p> Dit is een vriendelijke herinnering over de gedeeltelijke betaling. </p><p> We hebben de eerste betaling van {amount} snel ontvangen op {date}, waarvoor onze dank.</p><p><br>Het resterende saldo van {remaining_amount} moet over {remaining_date} datum.</p><p> Zorg ervoor dat u deze betaling tijdig voltooit om eventuele ongemakken te voorkomen.</p><p> Hartelijk dank voor uw medewerking.</p>',
                    'pl' => '<p>Szanowny {customer_name},<br /></p><p> To jest przyjazne przypomnienie o częściowej płatności. </p><p> Otrzymaliśmy niezwłocznie płatność początkową w wysokości {amount} w dniu {date}, za co dziękujemy.</p><p><br>Pozostałe saldo w wysokości {remaining_amount} należy uregulować w {remaining_date} data.</p><p> Prosimy o terminowe dokonanie tej płatności, aby uniknąć wszelkich niedogodności.</p><p> Dziękujemy za współpracę.</p>',
                    'ru' => '<p>Уважаемый {customer_name}!<br /></p><p> Это дружеское напоминание о частичной оплате. </p><p> Мы получили первоначальный платеж в размере {amount} {date} оперативно, за что благодарим вас.</p><p><br>Оставшийся остаток в размере {remaining_amount} подлежит оплате через {remaining_date} дата.</p><p> Пожалуйста, обеспечьте своевременное завершение этого платежа, чтобы избежать каких-либо неудобств.</p><p> Благодарим за сотрудничество.</p>',
                    'pt' => '<p>Prezado {customer_name},<br /></p><p> Este é um lembrete amigável sobre o pagamento parcial. </p><p> Recebemos o pagamento inicial de {amount} em {date} prontamente, pelo qual agradecemos.</p><p><br>O saldo restante de {remaining_amount} vence em {remaining_date} data.</p><p> Garanta a conclusão atempada deste pagamento para evitar qualquer inconveniente.</p><p> Obrigado pela sua cooperação.</p>',
                    'zh' => '<p>尊敬的{customer_name}，<br /></p><p>这是关于部分付款的友好提醒。 </p><p> 我们已于 {date} 立即收到 {amount} 的首期付款，对此我们表示感谢。</p><p><br>剩余的 {remaining_amount} 余额应于 {remaining_date}日期。</p><p>请确保及时完成此付款，以免造成任何不便。</p><p>感谢您的合作。</p>',
                    'he' => '<p>{customer_name} היקר,<br /></p><p> זוהי תזכורת ידידותית לגבי התשלום החלקי. </p><p> קיבלנו את התשלום הראשוני בסך {amount} בתאריך {date} באופן מיידי, ועל כך אנו מודים לך.</p><p><br>היתרה הנותרת בסך {remaining_amount} תגיע ב-{remaining_date}.</p><p> אנא הקפד להשלים בזמן של תשלום זה כדי למנוע אי נוחות.</p><p> תודה על שיתוף הפעולה.</p>',
                    'tr' => '<p>Sayın {customer_name},<br /></p><p> Bu, kısmi ödemeyle ilgili dostça bir hatırlatmadır. </p><p> {amount} tutarındaki ilk ödemeyi {date} tarihinde derhal aldık, bunun için size teşekkür ederiz.</p><p><br>Kalan {remaining_amount} bakiyesinin vadesi {remaining_date} tarihi.</p><p> Herhangi bir rahatsızlığı önlemek için lütfen bu ödemenin zamanında tamamlandığından emin olun.</p><p> İşbirliğiniz için teşekkür ederiz.</p>',
                    'pt-br' => '<p>Prezado {customer_name},<br /></p><p> Este é um lembrete amigável sobre o pagamento parcial. </p><p> Recebemos o pagamento inicial de {amount} em {date} prontamente, pelo qual agradecemos.</p><p><br>O saldo restante de {remaining_amount} vence em {remaining_date} data.</p><p> Garanta a conclusão atempada deste pagamento para evitar qualquer inconveniente.</p><p> Obrigado pela sua cooperação.</p>',
                ],
            ],
        ];

        $email = EmailTemplate::where('name', 'Payment Reminder')->where('created_by', Auth::user()->id)->get();
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
        $allEmail = EmailTemplate::where('created_by', Auth::user()->id)->get();
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

    public function updateCustomerEmailSettings(Request $request, $id)
    {
        $validator = \Validator::make(
            $request->all(),
            [
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
        session()->put('app_setting_tab', $request->app_setting_tab ?? 'pills-partial_payment-tab');
        $Template = EmailTemplate::where('name', 'Payment Reminder')->where('created_by', Auth::user()->id)->first();

        if ($user->type == 'admin') {
            if ($request->customer_email_enabled == 'on') {
                UserEmailTemplate::where('user_id', $user->id)->update(['is_active' => 0]);
                $UserEmailTemplate = UserEmailTemplate::where('user_id', $user->id)->where('template_id', $Template['id'])->first();
                $UserEmailTemplate->is_active = 1;
                $UserEmailTemplate->save();
            } else {
                $UserEmailTemplate = UserEmailTemplate::where('user_id', $user->id)->where('template_id', $Template['id'])->first();
                $UserEmailTemplate->is_active = 0;
                $UserEmailTemplate->save();
            }
        }

        $emailTemplate = EmailTemplate::where('id', $id)->first();
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
        return redirect()->route('app-setting.index')->with('success', __('Email Template successfully updated.'));
    }

    public function manageCustomerEmailLang($id, $language = 'en')
    {
        $languages = Utility::languages();
        $emailTemplate = EmailTemplate::where('id', '=', $id)->first();
        $currEmailTempLang = EmailTemplateLang::where('parent_id', '=', $id)->where('language', $language)->first();

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
        $EmailTemplates = EmailTemplate::where('created_by', Auth::user()->id)->get();
        return response()->json([
            'status' => 'success',
            'message' => 'Language changed successfully',
            'data' => [
                'emailTemplates' => $EmailTemplates,
                'currentEmailTemplateLang' => $currEmailTempLang
            ]
        ]);
    }

    public static function sendOwnerEmailTemplate($emailTemplate, $mailTo, $obj, $user, $store)
    {
        // find template is exist or not in our record
        $template = EmailTemplate::where('name', 'LIKE', $emailTemplate)->first();
        $theme_id = $store->theme_id;
        if (isset($template) && !empty($template)) {

            // check template is active or not by company
            $is_actives = UserEmailTemplate::where('template_id', '=', $template->id)->where('user_id', $user->id)->first();
            $slug = Store::where('created_by', $template->created_by)->select('slug')->first()->slug;

            $setting = getAdminAllSetting($user->id);

            if ($is_actives->is_active == 1) {

                $EmailTemplateLang = EmailTemplateLang::where('parent_id', '=', $template->id)->where('is_default', 1)->first();
                if (!isset($user->language) || !isset($EmailTemplateLang->language)) {
                    $prefer_lang = $user->language;
                } else {
                    $prefer_lang = ($user->language == $EmailTemplateLang->language) ? $user->language : $EmailTemplateLang->language;
                }

                // get email content language base
                $content = EmailTemplateLang::where('parent_id', '=', $template->id)->where('language', 'LIKE', $prefer_lang)->first();

                $content->from = $template->from;

                if (!empty($content->content)) {

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

                        $obj['store_name'] = $store->name;
                        $obj['amount'] = $obj['amount'];
                        $obj['date'] = $obj['date'];
                        $obj['remaining_amount'] = $obj['remaining_amount'];
                        $obj['remaining_date'] = $obj['remaining_date'];
                        $obj['customer_name'] = $obj['customer_name'];

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
            '{remaining_amount}',
            '{remaining_date}',
            '{amount}',
            '{date}',
            '{customer_name}',
        ];
        $arrValue = [
            'remaining_amount' => '-',
            'remaining_date' => '-',
            'amount' => '-',
            'date' => '-',
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

    public function PartialPaymentSettings(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Partial Payment Setting')) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'deposite_payment_methods' => 'required|array',
                    'partial_payment_methods' => 'required|array'
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', 'You have to select alteast more than one payment method.');
            }
            $theme_id = !empty(APP_THEME()) ? APP_THEME() : '';
            session()->put('app_setting_tab', $request->app_setting_tab ?? 'pills-partial_payment-tab');


            $deposit_paymentMethods = $request['deposite_payment_methods'];
            $partial_paymentMethods = $request['partial_payment_methods'];
            if (isset($deposit_paymentMethods) || isset($partial_paymentMethods)) {
                if (isset($deposit_paymentMethods)) {
                    $deposit_paymentNames = DB::table('settings')
                        ->whereIn('id', $deposit_paymentMethods)
                        ->pluck('name')
                        ->map(function ($name) {
                            return ucfirst(str_replace(['is_', '_enabled'], '', $name));
                        })
                        ->toArray();
                    $deposit_paymentsname = array_combine($deposit_paymentMethods, $deposit_paymentNames);
                    $deposit_paymentMethods = json_encode($deposit_paymentNames);
                }
                if (isset($partial_paymentMethods)) {
                    $partial_paymentNames = DB::table('settings')
                        ->whereIn('id', $partial_paymentMethods)
                        ->pluck('name')
                        ->map(function ($name) {
                            return ucfirst(str_replace(['is_', '_enabled'], '', $name));
                        })
                        ->toArray();
                    $partial_paymentsname = array_combine($partial_paymentMethods, $partial_paymentNames);
                    $partial_paymentMethods = json_encode($partial_paymentNames);
                }
            } else {
                $deposit_paymentMethods = json_encode([]);
                $partial_paymentMethods = json_encode([]);
            }
            if (isset($request->remainder_duration_period)) {
                $validator = \Validator::make(
                    $request->all(),
                    [
                        'remainder_duration_period' => 'required|numeric|max:12',
                    ]
                );
                if ($validator->fails()) {
                    $messages = $validator->getMessageBag();
                    return redirect()->back()->with('error', $messages->first());
                }
            }
            $post['remainder_duration_period'] = $request->remainder_duration_period ?? 1;
            $post['remainder_duration_type'] = $request->remainder_duration_type;
            $post['deposite_payment_methods'] = $deposit_paymentMethods;
            $post['partial_payment_methods'] = $partial_paymentMethods;
            $post['enable_partial_payment'] = $request->enable_partial_payment;
            $post['force_deposit'] = $request->force_deposit;
            $post['deposit_type'] = $request->deposit_type;
            $post['deposit_amount'] = $request->deposit_amount;
            $post['deposit_selection'] = $request->deposit_selection;
            $post['tax_option'] = $request->tax_option;
            $post['shipping_option'] = $request->shipping_option;
            $post['coupon_option'] = $request->coupon_option;
            $post['set_order_status'] = json_encode($request->input('set_order_status'));

            $settingQuery = Setting::query();
            foreach ($post as $key => $data) {
                $status = (clone $settingQuery)->updateOrCreate(
                    [
                        'name' => $key,                        
                        'store_id' => getCurrentStore(),
                        'created_by' => auth()->user()->id
                    ],
                    [
                        'value' => $data,
                        'name' => $key,                        
                        'store_id' => getCurrentStore(),
                        'created_by' => auth()->user()->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
            if (isset($status)) {
                return redirect()->back()->with('success', __('Partial Payment setting successfully updated.'));
            } else {
                return redirect()->back()->with('error', __('Something is wrong'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public static function ManageDeposit(Request $request, $slug)
    {
        $api = new ApiController();
        $store = getStore($slug);
        $settings = Setting::where('store_id', $store->id)->pluck('value', 'name')->toArray();
        $request['status'] = false;
        
        $data = $api->cart_list($request, $slug);
        
        $response = (array) $data->getData()->data;
        $products = $response['product_list'];
        if (!empty($products)) {
            $deposit_price = 0;
            $pending_price = 0;
            $tax_price = $request['tax_price'] ?? 0;
            $shipping_price = $request['shipping_price'] ?? 0;
            $coupon_price = $request['coupon_price'] ?? 0;
            foreach ($products as $key => $product) {
                $product_id = $product->product_id;
                $variant_id = $product->variant_id;
                $qtyy = !empty($product->qty) ? $product->qty : 0;
                $product_data = Product::find($product_id);
                $partial_payments = PartialPayments::where('product_id', $product_id)->where('store_id', $store->id)->first();

                if ((isset($settings['enable_partial_payment']) && $settings['enable_partial_payment'] == 'on')) {
                    if (isset($partial_payments)) {
                        if (
                            (isset($settings['enable_partial_payment']) && $settings['enable_partial_payment'] == 'on') &&
                            (isset($partial_payments->enable_deposit) && $partial_payments->enable_deposit == 'on') &&
                            (isset($partial_payments->force_deposit) && $partial_payments->force_deposit == 'on')
                        ) {
                            $final_price = !empty($product->sale_price) ? $product->sale_price : $product->final_price;

                            $deposit_amount = $partial_payments->deposit_amount ?? 0;
                            if ($partial_payments->deposit_type == 'flat') {
                                $deposit_price += $deposit_amount;
                            } else {
                                $deposit_amount = $final_price * $deposit_amount /
                                    100;
                                $deposit_price += $deposit_amount;
                            }
                            $pending_price += $final_price - $deposit_amount;
                        } elseif (
                            (isset($partial_payments->enable_deposit) && $partial_payments->enable_deposit == null) &&
                            (isset($partial_payments->force_deposit) && $partial_payments->force_deposit == null)
                        ) {
                            if (isset($settings['deposit_selection']) && $settings['deposit_selection'] == 'pay_deposit') {
                                $final_price = !empty($product->sale_price) ? $product->sale_price : $product->final_price;
                                $deposit_amount = $settings['deposit_amount'] ?? 0;
                                if ($settings['deposit_type'] == 'flat') {
                                    $deposit_price += $deposit_amount;
                                } else {
                                    $deposit_amount = $final_price * $deposit_amount /
                                        100;
                                    $deposit_price += $deposit_amount;
                                }
                                $pending_price += $final_price - $deposit_amount;
                            } else {
                                $sub_price = $request['total_sub_price'] ?? $response['total_sub_price'];
                                $request_data['deposite_amount'] = $sub_price;
                                $request_data['pending_amount'] = 0;
                                $request_data['tax_price_value'] = SetNumber($tax_price ?? 0);
                                $request_data['coupon_price_value'] = SetNumber($coupon_price ?? 0);
                                $request_data['shipping_price_value'] = SetNumber($shipping_price ?? 0);
                                $request_data['currency'] = $settings['CURRENCYCURRENCY'] ?? '$';
                                return $request_data;
                            }
                        } else {
                            $sub_price = $request['total_sub_price'] ?? $response['total_sub_price'];
                            $request_data['deposite_amount'] = $sub_price;
                            $request_data['pending_amount'] = 0;
                            $request_data['tax_price_value'] = SetNumber($tax_price ?? 0);
                            $request_data['coupon_price_value'] = SetNumber($coupon_price ?? 0);
                            $request_data['shipping_price_value'] = SetNumber($shipping_price ?? 0);
                            $request_data['currency'] = $settings['CURRENCYCURRENCY'] ?? '$';
                            return $request_data;
                        }
                    } else {
                        if (
                            (isset($settings['enable_partial_payment']) && $settings['enable_partial_payment'] == 'on') &&
                            (isset($settings['force_deposit']) && $settings['force_deposit'] == 'on')
                        ) {
                            if (isset($settings['deposit_selection']) && $settings['deposit_selection'] == 'pay_deposit') {
                                $final_price = !empty($product->sale_price) ? $product->sale_price : $product->final_price;
                                $deposit_amount = $settings['deposit_amount'] ?? 0;
                                if ($settings['deposit_type'] == 'flat') {
                                    $deposit_price += $deposit_amount;
                                } else {
                                    $deposit_amount = $final_price * $deposit_amount /
                                        100;
                                    $deposit_price += $deposit_amount;
                                }
                                $pending_price += $final_price - $deposit_amount;
                            } else {
                                $sub_price = $request['total_sub_price'] ?? $response['total_sub_price'];
                                $request_data['deposite_amount'] = $sub_price;
                                $request_data['pending_amount'] = 0;
                                $request_data['tax_price_value'] = SetNumber($tax_price ?? 0);
                                $request_data['coupon_price_value'] = SetNumber($coupon_price ?? 0);
                                $request_data['shipping_price_value'] = SetNumber($shipping_price ?? 0);
                                $request_data['currency'] = $settings['CURRENCYCURRENCY'] ?? '$';
                                return $request_data;
                            }
                        } else {
                            $sub_price = $request['total_sub_price'] ?? $response['total_sub_price'];
                            $request_data['deposite_amount'] = $sub_price;
                            $request_data['pending_amount'] = 0;
                            $request_data['tax_price_value'] = SetNumber($tax_price ?? 0);
                            $request_data['coupon_price_value'] = SetNumber($coupon_price ?? 0);
                            $request_data['shipping_price_value'] = SetNumber($shipping_price ?? 0);
                            $request_data['currency'] = $settings['CURRENCYCURRENCY'] ?? '$';
                            return $request_data;
                        }
                    }
                }
            }
            if ((isset($settings['tax_option']) && $settings['tax_option'] == 'with_deposit') || (isset($settings['shipping_option']) && $settings['shipping_option'] == 'with_deposit') || (isset($settings['coupon_option']) && $settings['coupon_option'] == 'with_deposit')) {
                $deposit_price -= $coupon_price;
                $deposit_price += $tax_price + $shipping_price;
            } elseif ((isset($settings['tax_option']) && $settings['tax_option'] == 'with_future_payment') || (isset($settings['shipping_option']) && $settings['shipping_option'] == 'with_future_payment') || (isset($settings['coupon_option']) && $settings['coupon_option'] == 'with_future_payment')) {
                $pending_price -= $coupon_price;
                $pending_price += $tax_price + $shipping_price;
            } else {
                $tax_price /= 2;
                $shipping_price /= 2;
                $coupon_price /= 2;

                $deposit_price -= $coupon_price;
                $pending_price -= $coupon_price;

                $deposit_price += $tax_price + $shipping_price;
                $pending_price += $tax_price + $shipping_price;
            }
            $request_data['deposite_amount'] = SetNumber($deposit_price ?? 0);
            $request_data['pending_amount'] = SetNumber($pending_price ?? 0);
            $request_data['tax_price_value'] = SetNumber($tax_price ?? 0);
            $request_data['coupon_price_value'] = SetNumber($coupon_price ?? 0);
            $request_data['shipping_price_value'] = SetNumber($shipping_price ?? 0);
            $request_data['currency'] = $settings['CURRENCYCURRENCY'] ?? '$';
            return $request_data;
        }
    }

    public static function PaymentList($payment_list, $store)
    {
        $enable_partial_payment = Utility::GetValueByName('enable_partial_payment', $store->theme_id, $store->id);
        if ($enable_partial_payment == 'on') {
            $deposite_payment_methods = \App\Models\Utility::GetValueByName('deposite_payment_methods', $store->theme_id, $store->id);
            $deposite_payment_methods_array = json_decode($deposite_payment_methods);

            $filtered_payment_methods = [];
            foreach ($payment_list as $method) {
                $methodName = strtolower($method['name']);
                $deposite_payment_methods_array_lower = array_map('strtolower', $deposite_payment_methods_array);
                if (in_array($methodName, $deposite_payment_methods_array_lower)) {
                    $filtered_payment_methods[] = $method;
                }
            }
            return $filtered_payment_methods;
        } else {
            return $payment_list;
        }
    }

    public static function ManageOrderStatus($data)
    {
        $user = auth()->user();
        if (module_is_active('PartialPayments')) {
            if ($user) {
                $order_id = $data['order_id'];
                $order_status = $data['order_status'];
                $order = Order::find($order_id);
                if ($order_status == 'partial_paid') {
                    $order->delivered_status = 7;
                    $order->save();
                    event(new OrderPaymentStatus($order));

                    $return['status'] = 'success';
                    $return['message'] = __('Order status changed.');
                    return $return;
                }
            }
        }
    }

    public static function ManageOrderStatusValue($order_array, $order)
    {
        $user = auth()->user();
        if (module_is_active('PartialPayments')) {
            if ($user) {
                if ($order->delivered_status == 7) {
                    $order_array['order_status_text'] = __('Partially Paid');
                    return $order_array;
                }
            }
        }
        return $order_array;
    }

    public function customerpayment(Request $request, $slug, $order_id)
    {
        try {
            $id = Crypt::decrypt($order_id);
            $store = getStore($slug);
            if (empty($store)) {
                return redirect()->back()->with('error', __('Store not available'));
            }

            $param = [
                'customer_id' => !empty(\Auth::guard('customers')->user()) ? \Auth::guard('customers')->user()->id : 0,
                'slug' => $slug,
                'store_id' => $store->id,
                'type' => 'pending_amount',
            ];
            $request->merge($param);
            $api = new ApiController();

            $payment_list_data = $api->payment_list($request, $slug);
            $payment_list = $payment_list_data->getData();

            $partial_payment_methods = \App\Models\Utility::GetValueByName('partial_payment_methods', $store->theme_id, $store->id);
            $partial_payment_methods_array = json_decode($partial_payment_methods);

            $filtered_payment_methods = [];
            foreach ($payment_list->data as $key => $method) {
                $methodName = strtolower($method->name);
                $partial_payment_methods_array_lower = array_map('strtolower', $partial_payment_methods_array);

                if (in_array($methodName, $partial_payment_methods_array_lower)) {
                    $filtered_payment_methods[] = $method;
                }
            }

            $order = Order::order_detail($id);
            $order_data = Order::find($id);
            $pending_order = OrderPartialPayments::where('order_id', $order_data->product_order_id)->where('payment_status', 'Pending payment')->whereNot('payment_amount', 0)->first();

            $paid_order = OrderPartialPayments::where('order_id', $order_data->product_order_id)->where('payment_status', 'On Hold')->whereNot('payment_amount', 0)->first();
            if ($pending_order) {
                return view('partial-payments::theme.payment-view', compact('slug', 'store', 'order', 'pending_order', 'filtered_payment_methods', 'paid_order'));
            }
        } catch (DecryptException $e) {
            return redirect()->back()->with('error', __('Something was wrong.'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Something was wrong.'));
        }
    }

    public function payment_status_change(Request $request)
    {
        $data['payment_id'] = $request->id;
        $data['payment_status'] = $request->delivered;
        $responce = OrderPartialPayments::payment_status_change($data);
        if ($responce['status'] == 'success') {
            $module = 'Status Change';
            $store = Store::find(getCurrentStore());

            $return['status'] = 'success';
            $return['payment_status'] = ucfirst($data['payment_status']) ?? '';
            $return['message'] = $responce['message'] . ((isset($msgs)) ? '<br> <span class="text-danger">' . $msgs . '</span>' : '');
            return response()->json($return);
        } else {
            $return['status'] = 'error';
            $return['message'] = $responce['message'];
            return response()->json($return);
        }
    }
}
