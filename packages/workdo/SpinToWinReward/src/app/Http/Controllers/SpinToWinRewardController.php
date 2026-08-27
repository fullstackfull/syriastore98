<?php

namespace Workdo\SpinToWinReward\app\Http\Controllers;

use App\Models\{Store, Coupon, EmailTemplate, EmailTemplateLang, UserEmailTemplate, User, Utility};
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Carbon\Carbon;
use Workdo\SpinToWinReward\app\Models\SpinCustomer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Mail\CommonEmailTemplate;
use Workdo\SpinToWinReward\app\Models\SpinToWinLabels;
use Illuminate\Support\Facades\Cache;

class SpinToWinRewardController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index()
    {
        abort(404);
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create($slug)
    {
        $store = getStore($slug);
        $currentDate = Carbon::now();
        $coupons = Coupon::where('store_id', $store->id)
            ->where('coupon_expiry_date', '>=', $currentDate)
            ->get();

        $staticLabels = SpinToWinLabels::where('store_id', $store->id)->pluck('name')->toArray();
        // $staticLabels = [
        //     'Not Lucky',
        //     'Next Time!',
        //     'No Luck Today!',
        //     'So Close',
        //     'Almost There',
        //     'You Got It!',
        //     'Better Luck Next Time'
        // ];

        $labels = [];
        foreach ($coupons as $coupon) {
            if ($coupon->coupon_type == 'percentage') {
                $labels[] = $coupon->discount_amount . '% off';
            } elseif ($coupon->coupon_type == 'flat') {
                $labels[] = '$' . $coupon->discount_amount . ' off';
            }
        }

        $labels = array_merge($staticLabels, $labels);
        shuffle($labels);
        $labelsJson = json_encode($labels);
        $customer = auth('customers')->user();

        $return['html'] = view('spin-to-win-reward::theme.create', compact(
            'store',
            'labels',
            'labelsJson',
            'customer'
        ))->render();
        return response()->json($return);
    }
    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request, $slug)
    {

        $validator = \Validator::make(
            $request->all(),
            [
                'name' => 'required',
                'email' => 'required|email'
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            $return['status'] = 'error';
            $return['message'] = 'All fields is required';
            return $return;
        }
        $store = getStore($slug);
        if (!$store) {
            $return['status'] = 'error';
            $return['message'] = 'Store not found.';
            return response()->json($return);
        }

        $existingSpinCustomer = SpinCustomer::where('email', $request->email)->where('store_id', $store->id)->first();
        if ($existingSpinCustomer) {
            $createdAt = new Carbon($existingSpinCustomer->created_at);
            $now = Carbon::now();

            if ($createdAt->diffInHours($now) >= 24) {
                $existingSpinCustomer->delete();
            } else {
                $return['status'] = 'error';
                $return['message'] = __('Try Your Luck Next Time Because You Have Already Spin.');
                return response()->json($return);
            }
        }

        $spin_customer  = new SpinCustomer();
        $spin_customer->name  = $request->name;
        $spin_customer->email  = $request->email;
        $spin_customer->number  = $request->number;
        $spin_customer->is_used  = $request->is_used;
        $spin_customer->winner_value  = $request->winner_value;
        $spin_customer->store_id  = $store->id;
        $spin_customer->save();

        $return['status'] = 'success';
        return $return;
    }

    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('spin-to-win-reward::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('spin-to-win-reward::edit');
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

    // For Email template Module
    public static function EmailTemplateDefaultData()
    {
        // Email Template
        $emailTemplate = [
            'Lucky Winner',
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
            'Lucky Winner' => [
                'subject' => 'Lucky Customer',
                'language' => [
                    'ar' => '<p>عزيزي {customer_name}،<br />يسعدنا أن نعلن أنه تم اختيارك لتكون الفائز المحظوظ! وكتعبير عن تقديرنا، يسعدنا أن نقدم لك رمز قسيمة حصريًا يمكنك استخدامه أثناء الدفع لفتح التوفيرات الخاصة في عملية الشراء التالية.</p><p><strong>رمز القسيمة</strong>: {coupon_code}</p><br /><p><strong>تاريخ انتهاء الصلاحية</strong>: { coupon_expire_date}</p><p>أسرع، هذا العرض صالح لفترة محدودة فقط، لذا تأكد من استخدامه رمز القسيمة الخاص بك قبل انتهاء صلاحيته. تفضل بزيارة موقعنا على الويب {store_name} واستكشف مجموعتنا المثيرة من المنتجات. شكرًا لك على المشاركة، وتهانينا مرة أخرى على فوزك!</p>',
                    'da' => '<p>Kære {customer_name},<br />Vi er glade for at kunne meddele, at du er blevet udvalgt som vores heldige vinder! Som et tegn på vores påskønnelse er vi glade for at kunne tilbyde dig en eksklusiv kuponkode, som du kan bruge under kassen for at låse op for særlige besparelser på dit næste køb.</p><p><strong>Kuponkode</strong>: { coupon_code}</p><br /><p><strong>Udløbsdato</strong>: {coupon_expire_date}</p><p>Skynd dig, dette tilbud er kun gyldigt i en begrænset periode, så sørg for at bruge din kuponkode, inden den udløber. Besøg vores hjemmeside {store_name} og udforsk vores spændende udvalg af produkter. Tak for din deltagelse, og endnu en gang tillykke med din sejr!</p>',
                    'de' => '<p>Sehr geehrter {customer_name},<br />Wir freuen uns, Ihnen mitteilen zu können, dass Sie zu unserem glücklichen Gewinner gewählt wurden! Als Zeichen unserer Wertschätzung bieten wir Ihnen gerne einen exklusiven Gutscheincode an, mit dem Sie beim Bezahlvorgang besondere Ersparnisse bei Ihrem nächsten Einkauf freischalten können.</p><p><strong>Gutscheincode</strong>: {coupon_code}</p><br /><p><strong>Ablaufdatum</strong>: {coupon_expire_date}</p><p>Beeilen Sie sich, dieses Angebot ist nur für eine begrenzte Zeit gültig, also nutzen Sie es unbedingt Ihren Gutscheincode, bevor er abläuft. Besuchen Sie unsere Website {store_name} und entdecken Sie unser aufregendes Produktsortiment. Vielen Dank für Ihre Teilnahme und noch einmal herzlichen Glückwunsch zu Ihrem Gewinn!</p>',
                    'en' => '<p>Dear {customer_name},<br />We are thrilled to announce that you have been selected as our lucky winner! As a token of our appreciation, we are delighted to offer you an exclusive coupon code that you can use during checkout to unlock special savings on your next purchase.</p><p><strong>Coupon Code</strong>: {coupon_code}</p><br /><p><strong>Expiration Date</strong>: {coupon_expire_date}</p><p>Hurry, this offer is valid for a limited time only, so make sure to use your coupon code before it expires. Visit our website {store_name} and explore our exciting range of products.Thank you for participating, and congratulations once again on your win!</p>',
                    'es' => '<p>Estimado {customer_name}:<br />¡Estamos encantados de anunciar que ha sido seleccionado como nuestro afortunado ganador! Como muestra de nuestro agradecimiento, estamos encantados de ofrecerle un código de cupón exclusivo que puede utilizar durante el proceso de pago para desbloquear ahorros especiales en su próxima compra.</p><p><strong>Código de cupón</strong>: {coupon_code}</p><br /><p><strong>Fecha de vencimiento</strong>: {coupon_expire_date}</p><p>Apúrate, esta oferta es válida solo por tiempo limitado, así que asegúrate de usarla su código de cupón antes de que caduque. Visite nuestro sitio web {store_name} y explore nuestra interesante gama de productos. ¡Gracias por participar y felicidades una vez más por su victoria!</p>',
                    'fr' => '<p>Cher {customer_name},<br />Nous sommes ravis dannoncer que vous avez été sélectionné comme notre heureux gagnant ! En guise de remerciement, nous sommes ravis de vous offrir un code promo exclusif que vous pouvez utiliser lors du paiement pour débloquer des économies spéciales sur votre prochain achat.</p><p><strong>Code promo</strong> : {coupon_code}</p><br /><p><strong>Date dexpiration</strong> : {coupon_expire_date}</p><p>Dépêchez-vous, cette offre est valable pour une durée limitée seulement, alors assurez-vous dutiliser votre code promo avant son expiration. Visitez notre site Web {store_name} et explorez notre gamme passionnante de produits. Merci davoir participé et félicitations encore une fois pour votre victoire !</p>',
                    'it' => '<p>Caro {customer_name},<br />siamo entusiasti di annunciare che sei stato selezionato come nostro fortunato vincitore! In segno di apprezzamento, siamo lieti di offrirti un codice coupon esclusivo che puoi utilizzare durante il pagamento per sbloccare sconti speciali sul tuo prossimo acquisto.</p><p><strong>Codice coupon</strong>: {coupon_code}</p><br /><p><strong>Data di scadenza</strong>: {coupon_expire_date}</p><p>Affrettati, questa offerta è valida solo per un periodo di tempo limitato, quindi assicurati di utilizzarla il tuo codice coupon prima che scada. Visita il nostro sito web {store_name} ed esplora la nostra entusiasmante gamma di prodotti. Grazie per aver partecipato e congratulazioni ancora per la tua vincita!</p>',
                    'ja' => '<p>{customer_name} 様<br />あなたが幸運な当選者に選ばれたことをお知らせできることを嬉しく思います。感謝の気持ちを込めて、チェックアウト時に使用すると、次回の購入時に特別な割引が得られる特別なクーポン コードを提供させていただきます。</p><p><strong>クーポン コード</strong>: {coupon_code}</p><br /><p><strong>有効期限</strong>: {coupon_expire_date}</p><p>お急ぎください。この特典は期間限定ですので、必ずご利用ください。有効期限が切れる前にクーポンコードを取得してください。当社のウェブサイト {store_name} にアクセスして、幅広い製品をご覧ください。ご参加いただきありがとうございます。そして、改めて優勝おめでとうございます!</p>',
                    'nl' => '<p>Beste {customer_name},<br />Met veel plezier kondigen wij aan dat u bent geselecteerd als onze gelukkige winnaar! Als blijk van onze waardering bieden we u graag een exclusieve couponcode aan die u tijdens het afrekenen kunt gebruiken om speciale kortingen te ontgrendelen op uw volgende aankoop.</p><p><strong>Couponcode</strong>: {coupon_code}</p><br /><p><strong>Vervaldatum</strong>: {coupon_expire_date}</p><p>Schiet op, deze aanbieding is slechts een beperkte tijd geldig, dus zorg ervoor dat u deze gebruikt uw couponcode voordat deze verloopt. Bezoek onze website {store_name} en ontdek ons ​​opwindende assortiment. Bedankt voor uw deelname en nogmaals gefeliciteerd met uw overwinning!</p>',
                    'pl' => '<p>Drogi {customer_name},<br />Z radością ogłaszamy, że zostałeś wybrany naszym szczęśliwym zwycięzcą! W dowód naszego uznania z przyjemnością oferujemy Ci ekskluzywny kod kuponu, którego możesz użyć podczas realizacji transakcji, aby odblokować specjalne oszczędności przy następnym zakupie.</p><p><strong>Kod kuponu</strong>: { coupon_code</p><br /><p><strong>Data ważności</strong>: {coupon_expire_date}</p><p>Pospiesz się, ta oferta jest ważna tylko przez ograniczony czas, więc pamiętaj, aby skorzystać swój kod kuponu przed jego wygaśnięciem. Odwiedź naszą stronę internetową {store_name} i poznaj naszą ekscytującą gamę produktów. Dziękujemy za udział i jeszcze raz gratulujemy wygranej!</p>',
                    'ru' => '<p>Уважаемый {customer_name}!<br />Мы рады сообщить, что вы стали нашим счастливым победителем! В знак нашей признательности мы рады предложить вам эксклюзивный код купона, который вы можете использовать при оформлении заказа, чтобы получить специальную скидку на следующую покупку.</p><p><strong>Код купона</strong>: {coupon_code}</p><br /><p><strong>Дата истечения срока действия</strong>: {coupon_expire_date}</p><p>Спешите, это предложение действительно только в течение ограниченного времени, поэтому обязательно воспользуйтесь ваш код купона до истечения срока его действия. Посетите наш веб-сайт {store_name} и изучите наш потрясающий ассортимент товаров. Благодарим за участие и еще раз поздравляем с победой!</p>',
                    'pt' => '<p>Prezado {customer_name},<br />Temos o prazer de anunciar que você foi selecionado como nosso sortudo vencedor! Como forma de agradecimento, temos o prazer de oferecer a você um código de cupom exclusivo que você pode usar durante a finalização da compra para desbloquear descontos especiais em sua próxima compra.</p><p><strong>Código de cupom</strong>: {coupon_code}</p><br /><p><strong>Data de validade</strong>: {coupon_expire_date}</p><p>Depressa, esta oferta é válida apenas por tempo limitado, então certifique-se de usar seu código de cupom antes que ele expire. Visite nosso site {store_name} e explore nossa incrível variedade de produtos. Obrigado por participar e parabéns mais uma vez pela sua vitória!</p>',
                    'zh' => '<p>尊敬的{customer_name}，<br />我们很高兴地宣布您已被选为我们的幸运获奖者！为了表达我们的谢意，我们很高兴为您提供专属优惠券代码，您可以在结账时使用该代码，以便在下次购买时享受特别优惠。</p><p><strong>优惠券代码</strong>：{coupon_code}</p><br /><p><strong>到期日期</strong>：{coupon_expire_date}</p><p>快点，此优惠仅在有限时间内有效，因此请务必使用您的优惠券代码在过期之前。请访问我们的网站 {store_name} 并探索我们令人兴奋的产品系列。感谢您的参与，并再次祝贺您获奖！</p>',
                    'he' => '<p>{customer_name} היקר,<br />אנו שמחים להודיע ​​שנבחרת כזוכה המאושר שלנו! כאות להערכתנו, אנו שמחים להציע לך קוד קופון בלעדי שבו תוכל להשתמש במהלך התשלום כדי לשחרר חיסכון מיוחד ברכישה הבאה שלך.</p><p><strong>קוד קופון</strong>: {coupon_code}</p><br /><p><strong>תאריך תפוגה</strong>: {coupon_expire_date}</p><p>מהר, מבצע זה תקף לזמן מוגבל בלבד, אז הקפד להשתמש קוד הקופון שלך לפני שהוא יפוג. בקר באתר האינטרנט שלנו {store_name} וחקור את מגוון המוצרים המרגש שלנו. תודה לך על ההשתתפות, ושוב מזל טוב על הזכייה שלך!</p>',
                    'tr' => '<p>Sayın {customer_name},<br />Şanslı talihlimiz olarak seçildiğinizi duyurmaktan büyük heyecan duyuyoruz! Takdirimizin bir göstergesi olarak, bir sonraki satın alma işleminizde özel indirimlerin kilidini açmak için ödeme sırasında kullanabileceğiniz özel bir kupon kodunu size sunmaktan mutluluk duyuyoruz.</p><p><strong>Kupon Kodu</strong>: {coupon_code}</p><br /><p><strong>Son Kullanma Tarihi</strong>: {coupon_expire_date}</p><p>Acele edin, bu teklif yalnızca sınırlı bir süre için geçerlidir, bu nedenle mutlaka kullanın Kupon kodunuzun süresi dolmadan. {store_name} web sitemizi ziyaret edin ve heyecan verici ürün yelpazemizi keşfedin. Katıldığınız için teşekkür ederiz ve kazandığınız için bir kez daha tebrik ederiz!</p>',
                    'pt-br' => '<p>Prezado {customer_name},<br />Temos o prazer de anunciar que você foi selecionado como nosso sortudo vencedor! Como forma de agradecimento, temos o prazer de oferecer a você um código de cupom exclusivo que você pode usar durante a finalização da compra para desbloquear descontos especiais em sua próxima compra.</p><p><strong>Código de cupom</strong>: {coupon_code}</p><br /><p><strong>Data de validade</strong>: {coupon_expire_date}</p><p>Depressa, esta oferta é válida apenas por tempo limitado, então certifique-se de usar seu código de cupom antes que ele expire. Visite nosso site {store_name} e explore nossa incrível variedade de produtos. Obrigado por participar e parabéns mais uma vez pela sua vitória!</p>',
                ],
            ],
        ];


        $email = EmailTemplate::where('name', 'Lucky Winner')->where('created_by', Auth::user()->id)->get();
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



    public function WinnerCustomer(Request $request, $slug)
    {
        $store = getStore($slug);
        $spin_customer  = SpinCustomer::where('email', $request->email)->where('store_id', $store->id)->first();

        $staticLabels = SpinToWinLabels::where('store_id', $store->id)->pluck('name')->toArray();
        // $staticLabels = [
        //     'Not Lucky',
        //     'Next Time!',
        //     'No Luck Today!',
        //     'So Close',
        //     'Almost There',
        //     'You Got It!',
        //     'Better Luck Next Time'
        // ];
        $value_data = $request->xp;
        if (in_array($value_data, $staticLabels)) {
            $value_data = $request->xp;
            $spin_customer->is_used  = 'yes';
            $spin_customer->winner_value  = $value_data;
            $spin_customer->save();
            $return['status'] = 'error';
            $winnerValue = __("Oops! you weren't selected as the lucky winner this time");
            $return['message'] = $winnerValue;

            return $return;
        } else {
            if (substr($value_data, 0, 1) == '$') {
                if (preg_match('/\$(\d+)/', $value_data, $matches)) {
                    $number = $matches[1];
                }
                $currentDate = Carbon::now();
                $coupon = Coupon::where('coupon_type', 'flat')->where('discount_amount', $number)
                    ->where('store_id', $store->id)
                    ->where('coupon_expiry_date', '>=', $currentDate)
                    ->first();
                $value_data = $coupon->coupon_code ?? '';
                $expiry_data = $coupon->coupon_expiry_date ?? '';
            } else {
                if (preg_match('/(\d+)%\s*off/', $value_data, $matches)) {
                    $number = $matches[1];
                    $type = '%';
                }
                $currentDate = Carbon::now();
                $coupon = Coupon::where('coupon_type', 'percentage')->where('discount_amount', $number)
                    ->where('store_id', $store->id)
                    ->where('coupon_expiry_date', '>=', $currentDate)
                    ->first();
                $value_data = $coupon->coupon_code ?? '';
                $expiry_data = $coupon->coupon_expiry_date ?? '';
            }
            $spin_customer->is_used  = 'yes';
            $spin_customer->winner_value  = $value_data;
            $spin_customer->save();
            $return['status'] = 'success';
            $winnerValue = __("Thank you for participating! Watch out for an email with all the exciting details");
            $return['message'] = $winnerValue;

            $user = User::find($store->created_by);
            $Template = EmailTemplate::where('name', 'Lucky Winner')->where('created_by', $user->id)->first();
            $UserEmailTemplate = UserEmailTemplate::where('user_id', $user->id)->where('template_id', $Template['id'])->where('is_active', '1')->first();
            if (isset($UserEmailTemplate) && $UserEmailTemplate->is_active == 1) {
                $coupon_code = $value_data;
                $dArr = [
                    'coupon_code' => $coupon_code,
                    'coupon_expire_date' => $expiry_data,
                ];
                $resp = $this->sendOwnerEmailTemplate('Lucky Winner', $spin_customer->email, $spin_customer->name, $dArr, $user, $store);
            }
            return $return;
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
        session()->put('app_setting_tab', $request->app_setting_tab ?? 'pills-customer_email-tab');
        $Template = EmailTemplate::where('name', 'Lucky Winner')->where('created_by', Auth::user()->id)->first();

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
                        $obj['store_name'] = $store->name;
                        $obj['coupon_code'] = $obj['coupon_code'];
                        $obj['coupon_expire_date'] = $obj['coupon_expire_date'];

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
            '{coupon_code}',
            '{store_name}',
            '{coupon_expire_date}',
            '{customer_name}',
        ];
        $arrValue = [
            'coupon_code' => '-',
            'store_name' => '-',
            'coupon_expire_date' => '-',
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


    public function CustomerData(Request $request, $slug)
    {
        $store = getStore($slug);
        if (!$store) {
            $return['status'] = 'error';
            $return['message'] = 'Store not found.';
            return response()->json($return);
        }

        $spin_customer = SpinCustomer::where('email', $request->email)->where('store_id', $store->id)->first();
        if ($spin_customer && $spin_customer->is_used != 'no') {
            $createdAt = new Carbon($spin_customer->created_at);
            $now = Carbon::now();

            if ($createdAt->diffInHours($now) >= 24) {
                $spin_customer->delete();
            } else {
                $return['status'] = 'error';
                $return['message'] = __('Try Your Luck Next Time Because You Have Already Spin.');
                return response()->json($return);
            }
        }

        if (empty($spin_customer)) {
            $spin_customer = new SpinCustomer();
            $spin_customer->name = $request->name;
            $spin_customer->email = $request->email;
            $spin_customer->number = $request->number;
            $spin_customer->is_used = null;
            $spin_customer->winner_value = $request->winner_value;
            $spin_customer->store_id = $store->id;
            $spin_customer->save();
        }

        $staticLabels = SpinToWinLabels::where('store_id', $store->id)->pluck('name')->toArray();
        // $staticLabels = [
        //     'Not Lucky',
        //     'Next Time!',
        //     'No Luck Today!',
        //     'So Close',
        //     'Almost There',
        //     'You Got It!',
        //     'Better Luck Next Time'
        // ];
        $value_data = $request->xp;
        if (in_array($value_data, $staticLabels)) {
            $value_data = $request->xp;
            $spin_customer->is_used  = 'yes';
            $spin_customer->winner_value  = $value_data;
            $spin_customer->save();
            $return['status'] = 'error';
            $winnerValue = __("Oops! you weren't selected as the lucky winner this time");
            $return['message'] = $winnerValue;

            return response()->json($return);
        } else {
            if (substr($value_data, 0, 1) == '$') {
                if (preg_match('/\$(\d+)/', $value_data, $matches)) {
                    $number = $matches[1];
                }
                $currentDate = Carbon::now();
                $coupon = Coupon::where('coupon_type', 'flat')->where('discount_amount', $number)
                    ->where('store_id', $store->id)
                    ->where('coupon_expiry_date', '>=', $currentDate)
                    ->first();
                $value_data = $coupon->coupon_code ?? '';
                $expiry_data = $coupon->coupon_expiry_date ?? '';
            } else {
                if (preg_match('/(\d+)%\s*off/', $value_data, $matches)) {
                    $number = $matches[1];
                    $type = '%';
                }
                $currentDate = Carbon::now();
                $coupon = Coupon::where('coupon_type', 'percentage')->where('discount_amount', $number)
                    ->where('store_id', $store->id)
                    ->where('coupon_expiry_date', '>=', $currentDate)
                    ->first();
                $value_data = $coupon->coupon_code ?? '';
                $expiry_data = $coupon->coupon_expiry_date ?? '';
            }
            $spin_customer->is_used  = 'yes';
            $spin_customer->winner_value  = $value_data;
            $spin_customer->save();
            $return['status'] = 'success';
            $winnerValue = __("Thank you for participating! Watch out for an email with all the exciting details");
            $return['message'] = $winnerValue;

            $user = User::find($store->created_by);
            $Template = EmailTemplate::where('name', 'Lucky Winner')->where('created_by', $user->id)->first();
            $UserEmailTemplate = UserEmailTemplate::where('user_id', $user->id)->where('template_id', $Template['id'])->where('is_active', '1')->first();
            if (isset($UserEmailTemplate) && $UserEmailTemplate->is_active == 1) {
                $coupon_code = $value_data;
                $dArr = [
                    'coupon_code' => $coupon_code,
                    'coupon_expire_date' => $expiry_data,
                ];
                $resp = $this->sendOwnerEmailTemplate('Lucky Winner', $spin_customer->email, $spin_customer->name, $dArr, $user, $store);
            }
            return response()->json($return);
        }
    }
}
