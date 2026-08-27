<?php

namespace Workdo\ProductEnquiry\app\Http\Controllers;

use App\Mail\CommonEmailTemplate;
use App\Models\EmailTemplate;
use App\Models\EmailTemplateLang;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\UserEmailTemplate;
use App\Models\Utility;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\ProductEnquiry\app\Models\ProductEnquiry;
use Workdo\ProductEnquiry\DataTables\ProductEnquiryData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class ProductEnquiryController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index(ProductEnquiryData $dataTable)
    {
        if (!auth()->user()) {
            return redirect()->route('login');
        }
        $queries = ProductEnquiry::where('store_id', getCurrentStore())->get();
        return $dataTable->render('product-enquiry::product-enquiry.index', compact('queries'));
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        return view('product-enquiry::create');
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
        return view('product-enquiry::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        $query = ProductEnquiry::find($id);
        if ($query) {
            $product = Product::where('id', $query->product_id)->first();
            return view('product-enquiry::product-enquiry.edit', compact('query', 'product'));
        } else {
            return redirect()->back()->with('error', __('The Product Enquiry is not found.'));
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
        if (auth()->user()->isAbleTo('Replay Product Question')) {
            $query = ProductEnquiry::find($id);
            if ($query) {
                $validator = \Validator::make(
                    $request->all(),
                    [
                        'answers' => 'required',
                    ]
                );
                if ($validator->fails()) {
                    $messages = $validator->getMessageBag();
                    return redirect()->back()->with('error', $messages->first());
                }
                $store = Store::find($query->store_id);
                $query->comment = $request->comment;
                $query->answers = $request->answers;
                $query->created_by = auth()->user()->id;
                $query->save();

                $user = User::find($store->created_by);
                $Template = EmailTemplate::where('name', 'Product Enquiry')->where('created_by', $user->id)->first();
                $product = Product::find($query->product_id);
                $UserEmailTemplate = UserEmailTemplate::where('user_id', $user->id)->where('template_id', $Template['id'])->where('is_active', '1')->first();

                if (isset($UserEmailTemplate) && $UserEmailTemplate->is_active == 1) {
                    $dArr = [
                        'product_name' => $product->name,
                        'product_query' => $query->comment,
                        'product_answer' => strip_tags($query->answers),
                    ];
                    $resp = $this->sendOwnerEmailTemplate('Product Enquiry', $query->email, $query->first_name, $dArr, $user, $store);
                }
                return redirect()->back()->with('success', __('Answers successfully updated.'));
            } else {
                return redirect()->back()->with('error', __('The Product Enquiry is not found.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     * @param int $id
     * @return Renderable
     */
    public function destroy($id)
    {
        if (auth()->user()->isAbleTo('Delete Product Question')) {
            $question = ProductEnquiry::find($id);
            if ($question) {
                $question->delete();
                return redirect()->back()->with('success', __('Product Query delete successfully.'));
            } else {
                return redirect()->back()->with('error', __('The Product Enquiry is not found.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function addproductenquiry(Request $request, $storeSlug)
    {
        if (auth()->guard('customers')->user()) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'first_name' => 'required',
                    'last_name' => 'required',
                    'email' => 'required',
                    'contact' => 'required',
                    'comment' => 'required',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
            $product = new ProductEnquiry();
            $product->first_name = $request->first_name;
            $product->last_name = $request->last_name;
            $product->email = $request->email;
            $product->contact = $request->contact;
            $product->comment = $request->comment;
            $product->customer_id = null;
            if (auth('customers')->user()) {
                $product->customer_id = auth('customers')->user()->id ?? null;
            }
            $store = getStore($storeSlug);
            $product->store_id = $store->id;
            $product->product_id = $request->product_id;
            $product->save();
        } else {
            return redirect()->back()->with('error', __('login or  Register to submit your questions.'));
        }

        return redirect()->back()->with('success', __('Thank you for enquiring, we will respond soon!'));
    }

    public function updateEnquiryEmailSettings(Request $request, $id)
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
        session()->put('app_setting_tab', $request->app_setting_tab ?? 'pills-product-enquiry-tab');
        $Template = EmailTemplate::where('name', 'Product Enquiry')->where('created_by', Auth::user()->id)->first();

        if ($user->type == 'admin') {
            if ($request->enquiry_email_enabled == 'on') {
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

    public function manageEnquiryEmailLang($id, $language = 'en')
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
            'message' => __('Language changed successfully'),
            'data' => [
                'emailTemplates' => $EmailTemplates,
                'currentEmailTemplateLang' => $currEmailTempLang
            ]
        ]);
    }

    public static function sendOwnerEmailTemplate($emailTemplate, $mailTo, $customerName, $obj, $user, $store)
    {
        // find template is exist or not in our record
        $template = EmailTemplate::where('name', 'LIKE', $emailTemplate)->first();

        if (isset($template) && !empty($template)) {

            // check template is active or not by company
            $is_actives = UserEmailTemplate::where('template_id', '=', $template->id)->where('user_id', $user->id)->first();
            $slug = Store::where('created_by', $template->created_by)->select('slug')->first()->slug;

            $setting = getAdminAllSetting($user->id);

            if ($is_actives->is_active == 1) {

                // get email content language base

                $EmailTemplateLang = EmailTemplateLang::where('parent_id', '=', $template->id)->where('is_default', 1)->first();
                if (isset($EmailTemplateLang->language) && isset($user->language)) {
                    $prefer_lang = ($user->language == $EmailTemplateLang->language) ? $user->language : $EmailTemplateLang->language;
                } else {
                    $prefer_lang = 'en';
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

                        $obj['customer_name'] = $customerName;
                        $obj['product_answer'] = $obj['product_answer'];
                        $obj['product_name'] = $obj['product_name'];
                        $obj['product_query'] = $obj['product_query'];

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
            '{product_name}',
            '{product_query}',
            '{product_answer}',
            '{customer_name}',
        ];
        $arrValue = [
            'product_name' => '-',
            'product_query' => '-',
            'product_answer' => '-',
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


    // For Email template Module
    public static function EmailTemplateDefaultData()
    {
        // Email Template
        $emailTemplate = [
            'Product Enquiry',
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
            'Product Enquiry' => [
                'subject' => 'Product Enquiry',
                'language' => [
                    'ar' => '<p>عزيزي {customer_name}،<br />شكرًا لتواصلك معنا. لقد راجعنا استفسارك حول {product_name}، وإليك الرد على استفسارك:</p><p><strong>استعلامك</strong>: {product_query}</p><br /><p>< strong>ردنا</strong>: {product_answer}</p><p>إذا كانت لديك أية أسئلة أخرى أو كنت بحاجة إلى معلومات إضافية، فلا تتردد في إخبارنا بذلك.</p>',
                    'da' => '<p>Kære {customer_name},<br />Tak, fordi du kontaktede os! Vi har gennemgået din forespørgsel om {product_name}, og her er svaret på din forespørgsel:</p><p><strong>Din forespørgsel</strong>: {product_query}</p><br /><p>< strong>Vores svar</strong>: {product_answer}</p><p>Hvis du har yderligere spørgsmål eller brug for yderligere oplysninger, er du velkommen til at give os besked.</p>',
                    'de' => '<p>Sehr geehrter {customer_name},<br />Vielen Dank für Ihre Kontaktaufnahme! Wir haben Ihre Anfrage zu {product_name} überprüft und hier ist die Antwort auf Ihre Anfrage:</p><p><strong>Ihre Anfrage</strong>: {product_query}</p><br /><p>< strong>Unsere Antwort</strong>: {product_answer}</p><p>Wenn Sie weitere Fragen haben oder zusätzliche Informationen benötigen, lassen Sie es uns gerne wissen.</p>',
                    'en' => '<p>Dear {customer_name},<br />Thank you for reaching out! We have reviewed your enquiry about {product_name}, and here’s the response to your query:</p><p><strong>Your Query</strong>: {product_query}</p><br /><p><strong>Our Response</strong>: {product_answer}</p><p>If you have any further questions or need additional information, feel free to let us know.</p>',
                    'es' => '<p>Estimado {customer_name}:<br />¡Gracias por comunicarte! Hemos revisado su consulta sobre {product_name} y aquí está la respuesta a su consulta:</p><p><strong>Su consulta</strong>: {product_query}</p><br /><p>< strong>Nuestra respuesta</strong>: {product_answer}</p><p>Si tiene más preguntas o necesita información adicional, no dude en hacérnoslo saber.</p>',
                    'fr' => '<p>Cher {customer_name},<br />Merci de nous avoir contactés ! Nous avons examiné votre demande concernant {product_name} et voici la réponse à votre requête :</p><p><strong>Votre requête</strong> : {product_query}</p><br /><p>< strong>Notre réponse</strong> : {product_answer}</p><p>Si vous avez dautres questions ou avez besoin dinformations supplémentaires, nhésitez pas à nous le faire savoir.</p>',
                    'it' => '<p>Gentile {customer_name},<br />grazie per averci contattato! Abbiamo esaminato la tua richiesta su {product_name} ed ecco la risposta alla tua domanda:</p><p><strong>La tua domanda</strong>: {product_query}</p><br /><p>< strong>La nostra risposta</strong>: {product_answer}</p><p>Se hai ulteriori domande o hai bisogno di ulteriori informazioni, non esitare a farcelo sapere.</p>',
                    'ja' => '<p>{customer_name} 様<br />お問い合わせいただきありがとうございます。 {product_name} に関するお問い合わせを確認いたしました。</p><p><strong>あなたのクエリ</strong>: {product_query}</p><br /><p>< strong>弊社からの回答</strong>: {product_answer}</p><p>さらにご質問がある場合、または追加情報が必要な場合は、お気軽にお問い合わせください。</p>',
                    'nl' => '<p>Beste {customer_name},<br />Bedankt dat u contact met ons opneemt! We hebben uw vraag over {product_name} beoordeeld en hier is het antwoord op uw vraag:</p><p><strong>Uw vraag</strong>: {product_query}</p><br /><p>< strong>Ons antwoord</strong>: {product_answer}</p><p>Als u nog vragen heeft of aanvullende informatie nodig heeft, kunt u ons dit laten weten.</p>',
                    'pl' => '<p>Szanowny {customer_name},<br />Dziękujemy za kontakt! Zapoznaliśmy się z Twoim zapytaniem dotyczącym produktu {product_name} i oto odpowiedź na Twoje pytanie:</p><p><strong>Twoje zapytanie</strong>: {product_query}</p><br /><p>< strong>Nasza odpowiedź</strong>: {product_answer}</p><p>Jeśli masz dalsze pytania lub potrzebujesz dodatkowych informacji, daj nam znać.</p>',
                    'ru' => '<p>Уважаемый {customer_name}!<br />Спасибо, что обратились к нам! Мы рассмотрели ваш запрос о {product_name} и вот ответ на ваш запрос:</p><p><strong>Ваш запрос</strong>: {product_query}</p><br /><p>< strong>Наш ответ</strong>: {product_answer}</p><p>Если у вас есть дополнительные вопросы или вам нужна дополнительная информация, сообщите нам об этом.</p>',
                    'pt' => '<p>Prezado {customer_name},<br />Obrigado por entrar em contato! Analisamos sua consulta sobre {product_name} e aqui está a resposta à sua consulta:</p><p><strong>Sua consulta</strong>: {product_query}</p><br /><p>< strong>Nossa resposta</strong>: {product_answer}</p><p>Se você tiver mais dúvidas ou precisar de informações adicionais, sinta-se à vontade para nos informar.</p>',
                    'zh' => '<p>尊敬的{customer_name}，<br />感谢您与我们联系！我们已审核您有关“{product_name}”的查询，现对您的查询进行回复：</p><p><strong>您的查询</strong>：{product_query}</p><br /><p>< strong>我们的回应</strong>：{product_answer}</p><p>如果您还有任何其他问题或需要其他信息，请随时告诉我们。</p>',
                    'he' => '<p>{customer_name} היקר,<br />תודה שפנית! בדקנו את שאלתך לגבי {product_name}, והנה התשובה לשאילתתך:</p><p><strong>השאילתה שלך</strong>: {product_query}</p><br /><p>< strong>התגובה שלנו</strong>: {product_answer}</p><p>אם יש לך שאלות נוספות או שאתה זקוק למידע נוסף, אל תהסס ליידע אותנו.</p>',
                    'tr' => '<p>Sayın {customer_name},<br />Bize ulaştığınız için teşekkür ederiz! {product_name} ile ilgili sorgunuzu inceledik ve sorunuzun yanıtını burada bulabilirsiniz:</p><p><strong>Sorgunuz</strong>: {product_query}</p><br /><p>< Strong>Yanıtımız</strong>: {product_answer}</p><p>Başka sorularınız varsa veya ek bilgiye ihtiyaç duyarsanız bize bildirmekten çekinmeyin.</p>',
                    'pt-br' => '<p>Prezado {customer_name},<br />Obrigado por entrar em contato! Analisamos sua consulta sobre {product_name} e aqui está a resposta à sua consulta:</p><p><strong>Sua consulta</strong>: {product_query}</p><br /><p>< strong>Nossa resposta</strong>: {product_answer}</p><p>Se você tiver mais dúvidas ou precisar de informações adicionais, sinta-se à vontade para nos informar.</p>',
                ],
            ],
        ];


        $email = EmailTemplate::where('name', 'Product Enquiry')->where('created_by', Auth::user()->id)->get();
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
}
