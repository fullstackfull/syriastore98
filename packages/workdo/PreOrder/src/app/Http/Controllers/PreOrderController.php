<?php

namespace Workdo\PreOrder\app\Http\Controllers;

use App\Mail\CommonEmailTemplate;
use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\EmailTemplateLang;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\User;
use App\Models\UserEmailTemplate;
use App\Models\Utility;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\PreOrder\DataTables\PreOrderDataTable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Workdo\PreOrder\app\Models\PreOrder;
use Workdo\PreOrder\app\Models\PreOrderHistory;
use Workdo\PreOrder\DataTables\PreOrderSettingDataTable;

class PreOrderController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index(PreOrderDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Pre Order')) {
            return $dataTable->render('pre-order::preorder.index');
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function preOrderNotificationSetting(PreOrderSettingDataTable $dataTable)
    {
        if (auth()->user() && (auth()->user()->isAbleTo('Manage Pre Order Setting') || auth()->user()->isAbleTo('Manage Pre Order Notification Setting'))) {
            $pre_order_active_tab = session()->get('pre_order_active_tab');

            if (empty($pre_order_active_tab)) {
                $pre_order_active_tab = 'pills-pre-order-tab';
            }
            $store = Store::find(getCurrentStore());
            $user_id = Auth::user()->id ?? $store->created_by;
            if (Auth::user()) {
                PreOrderController::EmailTemplateDefaultData();
                PreOrderController::userTemplateDefaultData($user_id);
            }
            $language = 'en';
            $languages = Utility::languages();
            $emailTemplateName = [
                'Pre Order Notification',
                'Pre Order Created',
                'Pre Order Created For Owner',
            ];
            $emailTemplates = EmailTemplate::whereIn('name', $emailTemplateName)
                    ->where('created_by', Auth::user()->id)
                    ->get();
            $emailTemplate = EmailTemplate::where('name', 'Pre Order Notification')->where('created_by', $user_id)->first();

            if ($emailTemplate) {
                $currEmailTempLang = EmailTemplateLang::where('parent_id', $emailTemplate->id)->where('is_default', '1')->first();
                if (!isset($currEmailTempLang) || empty($currEmailTempLang)) {
                    $currEmailTempLang = EmailTemplateLang::where('parent_id', $emailTemplate->id)->where('language', 'en')->first();
                    $currEmailTempLang->language = $language;
                }
            }

            $UserEmailTemplate = UserEmailTemplate::where('user_id', $user_id)->where('template_id', $emailTemplate['id'])->first();

            $pre_order_detail = PreOrder::where('store_id', getCurrentStore())->first();

            return $dataTable->render('pre-order::setting.index', compact('pre_order_active_tab', 'emailTemplates', 'emailTemplate', 'languages', 'currEmailTempLang', 'UserEmailTemplate', 'pre_order_detail'));
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
        return view('pre-order::create');
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
        return view('pre-order::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('pre-order::edit');
    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param int $id
     * @return Renderable
     */
    public function update(Request $request, $id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Pre Order')) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => 'required',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $pre_order_detail = PreOrder::where('id', '=', $id)->where('store_id', getCurrentStore())->first();
            if (empty($pre_order_detail)) {
                $pre_order_detail                   = new PreOrder();
                $pre_order_detail->enable_pre_order = $request['enable_pre_order'] ?? 'off';
                $pre_order_detail->name             = $request['name'];
                $pre_order_detail->message          = $request['message'] ?? '';
                $pre_order_detail->created_by       = auth()->user()->creatorId();
                $pre_order_detail->store_id         = getCurrentStore();
                $pre_order_detail->save();
            } else {
                $pre_order_detail->enable_pre_order = $request['enable_pre_order'] ?? 'off';
                $pre_order_detail->name             = $request['name'];
                $pre_order_detail->message          = $request['message'] ?? '';
                $pre_order_detail->save();
            }

            session()->put('pre_order_active_tab', 'pills-pre-order-tab');

            return redirect()->route('preorder.setting')->with('success', __('Email Template successfully updated.'));
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
        //
    }

    public function updatePreOrderEmailSettings(Request $request, $id)
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
        session()->put('pre_order_active_tab', 'pills-pre-order-notification-setting-tab');
        // $emailTemplates = [
        //     'Pre Order Notification',
        //     'Pre Order Created',
        //     'Pre Order Created For Owner',
        // ];
        // $Template = EmailTemplate::whereIn('name', $emailTemplates)
        //         ->where('created_by', Auth::user()->id)
        //         ->get();
        $Template = EmailTemplate::where('id', $id)->first();

        if ($user->type == 'admin') {
            if ($request->pre_order_notification_email_enabled == 'on') {
                // UserEmailTemplate::where('user_id', $user->id)->update(['is_active' => 0]);
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
        return redirect()->route('preorder.setting')->with('success', __('Email Template successfully updated.'));
    }

    public function managePreOrderEmailLang($id, $language = 'en', PreOrderSettingDataTable $dataTable)
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

        $emailTemplateName = [
            'Pre Order Notification',
            'Pre Order Created',
            'Pre Order Created For Owner',
        ];
        $emailTemplates = EmailTemplate::whereIn('name', $emailTemplateName)
                ->where('created_by', Auth::user()->id)
                ->get();
        
        session()->put('pre_order_active_tab', 'pills-pre-order-notification-setting-tab');
        $pre_order_active_tab = session()->get('pre_order_active_tab');

        $UserEmailTemplate = UserEmailTemplate::where('user_id', Auth::user()->id)->where('template_id', $emailTemplate['id'])->first();

        $pre_order_detail = PreOrder::where('store_id', getCurrentStore())->first();

        return $dataTable->render('pre-order::setting.index', compact('pre_order_active_tab', 'emailTemplates', 'emailTemplate', 'languages', 'currEmailTempLang', 'UserEmailTemplate', 'pre_order_detail'));
    }

    public function manuallySendPreOrderEmail($orderId)
    {
        $order_id = Crypt::decrypt($orderId);
        $order = Order::find($order_id);

        if (isset($order)) {
            $order_products = json_decode($order->product_json, true);

            if (isset($order_products) && !empty($order_products)) {
                $send_mail = false;

                foreach ($order_products as $key => $order_product) {
                    if (isset($order_product['product_id']) && $order_product['product_id'] != 0) {
                        $product = Product::where('id', $order_product['product_id'])->first();

                        if (isset($product) && isset($order_product['variant_id']) && $order_product['variant_id'] != 0) {
                            $variant = ProductVariant::where('id', $order_product['variant_id'])->where('product_id', $order_product['product_id'])->first();
                            if (isset($variant)) {
                                $variationOptions = explode(',', $variant->variation_option);
                                $option = in_array('manage_stock', $variationOptions);
                                
                                if ($option) {
                                    $send_mail = $variant->stock > 0;
                                } else {
                                    $send_mail = $product->product_stock > 0;
                                }
                            }
                        } else {
                            if (isset($product) && $product->variant_product == 0 && $product->track_stock == 1 && $product->stock_status == 'in_stock' && $product->product_stock > 0) {
                                $send_mail = true;
                            } else {
                                $send_mail = false;
                            }
                        }
                    }
                }
            }

            $store = Store::where('id', $order->store_id)->where('is_active', 1)->first();
            if (isset($store)) {
                $customer = Customer::find($order->customer_id);
                $owner = User::find($store->created_by);
                $preOrderHistory = PreOrderHistory::where('order_id', $order->id)->first();
                $order_email = $preOrderHistory->order_email ?? (!empty($customer->email) ? $customer->email : '');

                try {
                    $productsData = $order->getProductsData();
                    $productNames = array_column($productsData, 'name');
                    $product_name = implode(', ', $productNames);
                } catch (\Exception $e) {
                    $product_name = '';
                }

                if ($send_mail) {
                    try {
                        $dArr = [
                            'product_name' => $product_name,
                            'order_id' => $order->product_order_id,
                            'order_link' => env('APP_URL') . '/' . $store->slug . '/order/' . Crypt::encrypt($order->id),
                            'customer_name' => $customer->first_name ?? '',
                            'order_status' => $order->delivered_status ?? '',
                            'order_date' => $order->order_date ?? '',
                            'owner_name' => $owner->name ?? '',
                            'payment_status' => $order->payment_status ?? '',
                        ];

                        // Send Email
                        $resp = PreOrderHistory::sendEmailTemplate('Pre Order Notification', $order_email, $dArr, $owner, $store);
                        
                        if (isset($resp['is_success']) && $resp['is_success']) {
                            if($order->delivered_status == 8){
                                $order->delivered_status = 0;
                                $order->save();
                            }
                        }
                    } catch (\Exception $e) {
                        $smtp_error = __('E-Mail has not been sent due to SMTP configuration');
                    }

                    if(isset($smtp_error) || (isset($resp['error']) && $resp['error'])){
                        return redirect()->back()->with('error', (isset($smtp_error) ? $smtp_error : ((isset($resp['error']) && $resp['error']) ? $resp['error'] : '')));
                    }else{
                        return redirect()->back()->with('success', __('Email sent successfully!'));
                    }
                }else{
                    return redirect()->back()->with('error', __('Product stock is not available.'));
                }
            }else{
                return redirect()->back()->with('error', __('Store not found.'));
            }
        }else{
            return redirect()->back()->with('error', __('Order not found.'));
        }
    }

    // For Email template Module
    public static function EmailTemplateDefaultData()
    {
        // Email Template
        $emailTemplate = [
            'Pre Order Notification',
            'Pre Order Created',
            'Pre Order Created For Owner',
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
            'Pre Order Notification' => [
                'subject' => 'Pre Order Notification',
                'language' => [
                    'ar' => '<p>عزيزي {customer_name}،</p><p>يسعدنا إبلاغك بأن المنتج الذي طلبته مسبقًا، {product_name}، أصبح متاحًا الآن!</p><p>طلبك المسبق ({order_id}) تم تحويله بنجاح إلى طلب.</p><p>يُرجى التحقق من طلبك هنا: {order_link}</p><p>مع أطيب التحيات،<br />{app_name}</p>',
                    'da' => '<p>Kære {customer_name},</p><p>Vi er glade for at kunne informere dig om, at dit forudbestilte produkt, {product_name}, nu er tilgængeligt!</p><p>Din forudbestilling ({order_id}) er blevet konverteret til en ordre.</p><p>Tjek venligst din ordre her: {order_link}</p><p>Med venlig hilsen<br />{app_name}</p>',
                    'de' => '<p>Sehr geehrter {customer_name},</p><p>wir freuen uns, Ihnen mitteilen zu können, dass Ihr vorbestelltes Produkt, {product_name}, jetzt verfügbar ist!</p><p>Ihre Vorbestellung ({order_id}) wurde erfolgreich in eine Bestellung umgewandelt.</p><p>Bitte überprüfen Sie Ihre Bestellung hier: {order_link}</p><p>Mit freundlichen Grüßen<br />{app_name}</p>',
                    'en' => '<p>Dear {customer_name},</p><p>We’re excited to inform you that your pre-ordered product, {product_name}, is now available!</p><p>Your pre-order ({order_id}) has been successfully converted into an order.</p><p>Please check your order here: {order_link}</p><p>Best regards,<br />{app_name}</p>',
                    'es' => '<p>Estimado {customer_name}:</p><p>¡Nos complace informarle que su producto reservado, {product_name}, ya está disponible!</p><p>Su pedido anticipado ({order_id}) se ha convertido correctamente en un pedido.</p><p>Comprueba tu pedido aquí: {order_link}</p><p>Saludos cordiales,<br />{app_name}</p>',
                    'fr' => '<p>Cher {customer_name},</p><p>Nous sommes ravis de vous informer que votre produit en précommande, {product_name}, est maintenant disponible !</p><p>Votre précommande ({order_id}) a été converti avec succès en commande.</p><p>Veuillez vérifier votre commande ici : {order_link}</p><p>Meilleures salutations,<br />{app_name}</p>',
                    'it' => '<p>Gentile {customer_name},</p><p>Siamo lieti di informarti che il tuo prodotto preordinato, {product_name}, è ora disponibile!</p><p>Il tuo preordine ({order_id}) è stato convertito con successo in un ordine.</p><p>Controlla il tuo ordine qui: {order_link}</p><p>Cordiali saluti,<br />{app_name}</p>',
                    'ja' => '<p>{customer_name} 様</p><p>ご予約いただいた商品 {product_name} がご利用いただけるようになりましたことをお知らせいたします。</p><p>ご予約いただいた商品 ({order_id}) は正常に注文に変換されました。</p><p>ここで注文を確認してください: {order_link}</p><p>よろしくお願いいたします。<br />{app_name}</p>',
                    'nl' => '<p>Beste {customer_name},</p><p>We willen u graag laten weten dat uw vooraf bestelde product, {product_name}, nu beschikbaar is!</p><p>Uw reservering ({order_id}) is succesvol omgezet in een bestelling.</p><p>Controleer uw bestelling hier: {order_link}</p><p>Met vriendelijke groet,<br />{app_name}</p>',
                    'pl' => '<p>Szanowny {customer_name},</p><p>Z radością informujemy, że Twój produkt zamówiony w przedsprzedaży, {product_name}, jest już dostępny!</p><p>Twoje zamówienie w przedsprzedaży ({Order_id}) zostało pomyślnie przekonwertowane na zamówienie.</p><p>Sprawdź swoje zamówienie tutaj: {order_link}</p><p>Pozdrowienia,<br />{app_name}</p>',
                    'ru' => '<p>Уважаемый {customer_name}!</p><p>Мы рады сообщить Вам, что ваш предварительно заказанный продукт {product_name} теперь доступен!</p><p>Ваш предзаказ ({order_id}) успешно преобразован в заказ.</p><p>Проверьте свой заказ здесь: {order_link}</p><p>С уважением,<br />{app_name</p>',
                    'pt' => '<p>Prezado {customer_name},</p><p>Temos o prazer de informar que seu produto encomendado, {product_name}, já está disponível!</p><p>Sua encomenda ({order_id}) foi convertido em um pedido.</p><p>Verifique seu pedido aqui: {order_link}</p><p>Atenciosamente,<br />{app_name}</p>',
                    'zh' => '<p>尊敬的 {customer_name}，</p><p>我们很高兴地通知您，您预订的产品 {product_name} 现已上市！</p><p>您的预订 ({order_id}）已成功转换为订单。</p><p>请在此处检查您的订单：{order_link}</p><p>此致，<br />{app_name}</p>',
                    'he' => '<p>{customer_name} היקר,</p><p>אנו שמחים להודיע ​​לך שהמוצר שהזמנת מראש, {product_name}, זמין כעת!</p><p>ההזמנה המוקדמת שלך ({order_id}) הומר בהצלחה להזמנה.</p><p>אנא בדוק את ההזמנה שלך כאן: {order_link}</p><p>בברכה,<br />{app_name}</p>',
                    'tr' => '<p>Sayın {customer_name},</p><p>Ön sipariş verdiğiniz {product_name} ürününün artık mevcut olduğunu size bildirmekten heyecan duyuyoruz!</p><p>Ön siparişiniz ({order_id}) başarıyla bir siparişe dönüştürüldü.</p><p>Lütfen siparişinizi buradan kontrol edin: {order_link}</p><p>Saygılarımızla,<br />{app_name}</p>',
                    'pt-br' => '<p>Prezado {customer_name},</p><p>Temos o prazer de informar que seu produto encomendado, {product_name}, já está disponível!</p><p>Sua encomenda ({order_id}) foi convertido em um pedido.</p><p>Verifique seu pedido aqui: {order_link}</p><p>Atenciosamente,<br />{app_name}</p>',
                ],
            ],
            'Pre Order Created' => [
                'subject' => 'Pre Order Created',
                'language' => [
                    'ar' => '<p>عزيزي {customer_name}،</p><p>شكرًا لك على طلبك المسبق! يسعدنا أن نؤكد أن طلبك قد تم تقديمه بنجاح.</p><p> تفاصيل الطلب المسبق:</p><p>معرف الطلب المسبق: {order_id}</p><p>الطلب المسبق عنوان URL: {order_link}</p><p>إذا كانت لديك أية أسئلة أو كنت بحاجة إلى المساعدة، فلا تتردد في التواصل معنا.</p><p>مع أطيب التحيات<br />{app_name}</p>',
                    'da' => '<p>Kære {customer_name},</p><p>Tak for din forudbestilling! Vi er glade for at kunne bekræfte, at din ordre er blevet afgivet.</p><p> Forudbestillingsoplysninger:</p><p>Forudbestillings-id: {order_id}</p><p>Forudbestil URL: {order_link}</p><p>Hvis du har spørgsmål eller brug for hjælp, er du velkommen til at kontakte os.</p><p>Med venlig hilsen<br />{app_name}</p>',
                    'de' => '<p>Sehr geehrter {customer_name},</p><p>Vielen Dank für Ihre Vorbestellung! Wir freuen uns, Ihnen mitteilen zu können, dass Ihre Bestellung erfolgreich aufgegeben wurde.</p><p>Vorbestellungsdetails:</p><p>Vorbestellungs-ID: {order_id}</p><p>Vorbestellung URL: {order_link}</p><p>Wenn Sie Fragen haben oder Hilfe benötigen, können Sie sich gerne an uns wenden.</p><p>Mit freundlichen Grüßen<br />{app_name}</p>',
                    'en' => '<p>Dear {customer_name},</p><p>Thank you for your pre-order! We are excited to confirm that your order has been successfully placed.</p><p> Pre-Order Details:</p><p>Pre-Order ID: {order_id}</p><p>Pre-Order URL: {order_link}</p><p>If you have any questions or need assistance, feel free to reach out.</p><p>Best regards<br />{app_name}</p>',
                    'es' => '<p>Estimado {customer_name}:</p><p>¡Gracias por tu reserva! Nos complace confirmar que su pedido se realizó correctamente.</p><p> Detalles del pedido anticipado:</p><p>ID del pedido anticipado: {order_id}</p><p>Pedido anticipado URL: {order_link}</p><p>Si tiene alguna pregunta o necesita ayuda, no dude en comunicarse con nosotros.</p><p>Saludos cordiales<br />{app_name}</p>',
                    'fr' => '<p>Cher {customer_name},</p><p>Merci pour votre précommande ! Nous sommes ravis de confirmer que votre commande a été passée avec succès.</p><p> Détails de la précommande :</p><p>ID de précommande : {order_id}</p><p>Précommande URL : {order_link}</p><p>Si vous avez des questions ou avez besoin daide, nhésitez pas à nous contacter.</p><p>Meilleures salutations<br />{app_name}</p>',
                    'it' => '<p>Gentile {customer_name},</p><p>grazie per il tuo preordine! Siamo entusiasti di confermare che il tuo ordine è stato effettuato con successo.</p><p> Dettagli preordine:</p><p>ID preordine: {order_id}</p><p>Preordine URL: {order_link}</p><p>Se hai domande o hai bisogno di assistenza, non esitare a contattarci.</p><p>Cordiali saluti<br />{app_name}</p>',
                    'ja' => '<p>{customer_name} 様、</p><p>ご予約いただきありがとうございます。ご注文が正常に完了したことを確認させていただきました。</p><p>予約注文の詳細:</p><p>予約注文 ID: {order_id}</p><p>予約注文URL: {order_link}</p><p>ご質問がある場合、またはサポートが必要な場合はお気軽にお問い合わせください。</p><p>よろしくお願いいたします<br />{app_name}</p>',
                    'nl' => '<p>Beste {customer_name},</p><p>Bedankt voor uw reservering! We zijn blij om te kunnen bevestigen dat uw bestelling succesvol is geplaatst.</p><p> Pre-ordergegevens:</p><p>Pre-order-ID: {order_id}</p><p>Pre-order URL: {order_link}</p><p>Als u vragen heeft of hulp nodig heeft, neem dan gerust contact met ons op.</p><p>Met vriendelijke groet<br />{app_name}</p>',
                    'pl' => '<p>Szanowny {customer_name},</p><p>Dziękujemy za zamówienie w przedsprzedaży! Z przyjemnością potwierdzamy, że Twoje zamówienie zostało pomyślnie złożone.</p><p> Szczegóły zamówienia w przedsprzedaży:</p><p>Identyfikator zamówienia w przedsprzedaży: {order_id}</p><p>Zamówienie w przedsprzedaży URL: {order_link}</p><p>Jeśli masz jakieś pytania lub potrzebujesz pomocy, skontaktuj się z nami.</p><p>Pozdrowienia<br />{app_name}</p>',
                    'ru' => '<p>Уважаемый {customer_name}!</p><p>Спасибо за предварительный заказ! Мы рады подтвердить, что ваш заказ был успешно размещен.</p><p> Подробности предварительного заказа:</p><p>Идентификатор предварительного заказа: {order_id}</p><p>Предварительный заказ URL: {order_link}</p><p>Если у вас есть какие-либо вопросы или вам нужна помощь, обращайтесь.</p><p>С уважением<br />{app_name}</p>',
                    'pt' => '<p>Prezado {customer_name},</p><p>Obrigado por sua encomenda! Temos o prazer de confirmar que seu pedido foi realizado com sucesso.</p><p> Detalhes da pré-encomenda:</p><p>ID da pré-encomenda: {order_id}</p><p>Pré-encomenda URL: {order_link}</p><p>Se você tiver alguma dúvida ou precisar de ajuda, sinta-se à vontade para entrar em contato.</p><p>Atenciosamente<br />{app_name}</p>',
                    'zh' => '<p>尊敬的{customer_name}，</p><p>感谢您的预订！我们很高兴确认您的订单已成功下达。</p><p>预购详情：</p><p>预购 ID：{order_id}</p><p>预购网址：{order_link}</p><p>如果您有任何疑问或需要帮助，请随时与我们联系。</p><p>此致<br />{app_name}</p>',
                    'he' => '<p>{customer_name} היקר,</p><p>תודה על ההזמנה המוקדמת שלך! אנו שמחים לאשר שההזמנה שלך בוצעה בהצלחה.</p><p> פרטי הזמנה מוקדמת:</p><p>מזהה הזמנה מוקדמת: {order_id}</p><p>הזמנה מוקדמת כתובת אתר: {order_link}</p><p>אם יש לך שאלות כלשהן או שאתה זקוק לסיוע, אל תהסס לפנות.</p><p>בברכה<br />{app_name}</p>',
                    'tr' => '<p>Sayın {customer_name},</p><p>Ön siparişiniz için teşekkür ederiz! Siparişinizin başarıyla verildiğini onaylamanın heyecanını yaşıyoruz.</p><p> Ön Sipariş Ayrıntıları:</p><p>Ön Sipariş Kimliği: {order_id}</p><p>Ön Sipariş URL: {order_link}</p><p>Herhangi bir sorunuz varsa veya yardıma ihtiyacınız varsa bizimle iletişime geçmekten çekinmeyin.</p><p>Saygılarımızla<br />{app_name}</p>',
                    'pt-br' => '<p>Prezado {customer_name},</p><p>Obrigado por sua encomenda! Temos o prazer de confirmar que seu pedido foi realizado com sucesso.</p><p> Detalhes da pré-encomenda:</p><p>ID da pré-encomenda: {order_id}</p><p>Pré-encomenda URL: {order_link}</p><p>Se você tiver alguma dúvida ou precisar de ajuda, sinta-se à vontade para entrar em contato.</p><p>Atenciosamente<br />{app_name}</p>',
                ],
            ],
            'Pre Order Created For Owner' => [
                'subject' => 'Pre Order Created For Owner',
                'language' => [
                    'ar' => '<p>عزيزي {owner_name}،</p><p>تم بنجاح إنشاء طلب مسبق جديد في نظامنا. إليك التفاصيل:</p><p>معرف الطلب: {order_id}</p><p>عنوان URL للطلب المسبق: {order_link}</p><p>اسم العميل: {customer_name}</p> <p>تاريخ الطلب: {order_date}</p>',
                    'da' => '<p>Kære {owner_name},</p><p>En ny forudbestilling er blevet oprettet i vores system. Her er detaljerne:</p><p>Ordre-id: {order_id}</p><p>URL til forudbestilling: {order_link}</p><p>Kundenavn: {customer_name}</p> <p>Ordredato: {order_date}</p>',
                    'de' => '<p>Lieber {owner_name},</p><p>Eine neue Vorbestellung wurde erfolgreich in unserem System erstellt. Hier sind die Details:</p><p>Bestell-ID: {order_id}</p><p>Vorbestellungs-URL: {order_link}</p><p>Kundenname: {customer_name}</p> <p>Bestelldatum: {order_date}</p>',
                    'en' => '<p>Dear {owner_name},</p><p>A new pre-order has been successfully created in our system. Here are the details:</p><p>Order ID: {order_id}</p><p>Pre-Order URL: {order_link}</p><p>Customer Name: {customer_name}</p><p>Order Date: {order_date}</p>',
                    'es' => '<p>Estimado {owner_name}:</p><p>Se ha creado correctamente un nuevo pedido anticipado en nuestro sistema. Aquí están los detalles:</p><p>ID del pedido: {order_id}</p><p>URL de pedido anticipado: {order_link}</p><p>Nombre del cliente: {customer_name}</p> <p>Fecha del pedido: {order_date}</p>',
                    'fr' => '<p>Cher {owner_name},</p><p>Une nouvelle précommande a été créée avec succès dans notre système. Voici les détails :</p><p>ID de commande : {order_id}</p><p>URL de précommande : {order_link}</p><p>Nom du client : {customer_name}</p> <p>Date de commande : {order_date}</p>',
                    'it' => '<p>Gentile {owner_name},</p><p>Un nuovo preordine è stato creato con successo nel nostro sistema. Ecco i dettagli:</p><p>ID ordine: {order_id}</p><p>URL preordine: {order_link}</p><p>Nome cliente: {customer_name}</p> <p>Data dellordine: {order_date}</p>',
                    'ja' => '<p>{owner_name} 様、</p><p>新しい予約注文がシステムで正常に作成されました。詳細は次のとおりです:</p><p>注文 ID: {order_id}</p><p>予約注文 URL: {order_link}</p><p>顧客名: {customer_name}</p> <p>注文日: {order_date}</p>',
                    'nl' => '<p>Beste {owner_name},</p><p>Er is met succes een nieuwe pre-order aangemaakt in ons systeem. Hier zijn de details:</p><p>Bestellings-ID: {order_id}</p><p>Pre-order-URL: {order_link}</p><p>Klantnaam: {customer_name}</p> <p>Besteldatum: {order_date}</p>',
                    'pl' => '<p>Szanowny {owner_name},</p><p>Nowe zamówienie w przedsprzedaży zostało pomyślnie utworzone w naszym systemie. Oto szczegóły:</p><p>Identyfikator zamówienia: {order_id}</p><p>URL zamówienia w przedsprzedaży: {order_link}</p><p>Nazwa klienta: {customer_name}</p> <p>Data zamówienia: {order_date}</p>',
                    'ru' => '<p>Уважаемый {owner_name}!</p><p>В нашей системе успешно создан новый предварительный заказ. Вот подробности:</p><p>Идентификатор заказа: {order_id}</p><p>URL предварительного заказа: {order_link}</p><p>Имя клиента: {customer_name}</p> <p>Дата заказа: {order_date}</p>',
                    'pt' => '<p>Prezado {owner_name},</p><p>Uma nova encomenda foi criada com sucesso em nosso sistema. Aqui estão os detalhes:</p><p>ID do pedido: {order_id}</p><p>URL de pré-encomenda: {order_link}</p><p>Nome do cliente: {customer_name}</p> <p>Data do pedido: {order_date}</p>',
                    'zh' => '<p>尊敬的{owner_name}，</p><p>我们的系统中已成功创建新的预订单。详细信息如下：</p><p>订单 ID：{order_id}</p><p>预购 URL：{order_link}</p><p>客户名称：{customer_name}</p> <p>订单日期：{order_date}</p>',
                    'he' => '<p>{owner_name} היקר,</p><p>הזמנה מוקדמת חדשה נוצרה בהצלחה במערכת שלנו. להלן הפרטים:</p><p>מזהה הזמנה: {order_id}</p><p>כתובת אתר להזמנה מראש: {order_link}</p><p>שם לקוח: {customer_name}</p> <p>תאריך הזמנה: {order_date}</p>',
                    'tr' => '<p>Sayın {owner_name},</p><p>Sistemimizde başarıyla yeni bir ön sipariş oluşturuldu. Ayrıntıları burada bulabilirsiniz:</p><p>Sipariş Kimliği: {order_id}</p><p>Ön Sipariş URLsi: {order_link}</p><p>Müşteri Adı: {customer_name}</p> <p>Sipariş Tarihi: {order_date}</p>',
                    'pt-br' => '<p>Prezado {owner_name},</p><p>Uma nova encomenda foi criada com sucesso em nosso sistema. Aqui estão os detalhes:</p><p>ID do pedido: {order_id}</p><p>URL de pré-encomenda: {order_link}</p><p>Nome do cliente: {customer_name}</p> <p>Data do pedido: {order_date}</p>',
                ],
            ],
        ];

        foreach ($emailTemplate as $e) {
            $template = EmailTemplate::where('name',$e)->where('created_by', Auth::user()->id)->first();
            if ($template) {
                if (isset($defaultTemplate[$template->name])) {
                    foreach ($defaultTemplate[$template->name]['language'] as $lang => $content) {
                        $emailNoti = EmailTemplateLang::where('parent_id', $template->id)->where('language', $lang)->count();
                        if ($emailNoti == 0) {
                            EmailTemplateLang::create(
                                [
                                    'parent_id' => $template->id,
                                    'language' => $lang,
                                    'subject' => $defaultTemplate[$template->name]['subject'],
                                    'content' => $content,
                                ]
                            );
                        }
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
