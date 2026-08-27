<?php

namespace Workdo\LotteryPrizes\app\Http\Controllers;

use App\Http\Controllers\Api\ApiController;
use App\Models\{
    Country,
    Store,
    Product,
    Category,
    EmailTemplate,
    EmailTemplateLang,
    UserEmailTemplate,
    Utility,
    Testimonial,
    ProductVariant,
    ProductQuestion,
    User
};
use Session;
use App\Khalti\Khalti;
use App\Mail\CommonEmailTemplate;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use DB;
use Workdo\LotteryPrizes\app\Models\LotteryCustomer;
use Workdo\LotteryPrizes\app\Models\LotterySetting;
use Illuminate\Support\Facades\Cache;
use Qirolab\Theme\Theme;
use Illuminate\Http\RedirectResponse;
use Workdo\LotteryPrizes\app\Models\PurchaseTicket;
use Workdo\LotteryPrizes\app\Services\LotteryPaymentService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Mail;

class LotteryPrizesController extends Controller
{
    protected $LotterypaymentService;

    public function __construct(LotteryPaymentService $LotterypaymentService)
    {
        $this->LotterypaymentService = $LotterypaymentService;
    }
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index()
    {
        if (!auth()->user()) {
            return redirect()->route('login');
        }
        if (auth()->user()->isAbleTo('Manage Lottery Setting')) {
            $store                 = getStoreById(getCurrentStore());
            $enable_lottery_button = \App\Models\Utility::GetValueByName('enable_lottery_button', $store->id);

            if (isset($enable_lottery_button) && $enable_lottery_button == 'on') {
                $products          = Product::where('product_type', null)->where('store_id', $store->id)->pluck('name', 'id')->toArray();
                $lotterySettings   = LotterySetting::where('store_id', getCurrentStore())
                    ->where('created_by', auth()->user()->id)
                    ->get();
                return view('lottery-prizes::admin.lottery.index', compact('products', 'lotterySettings'));
            } else {
                return redirect()->route('dashboard')->with('error', __('Permission denied.'));
            }

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
        return view('lottery-prizes::create');
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */

    public function store(Request $request)
    {
        if (auth()->user()->isAbleTo('Edit Lottery Setting')) {
            $lotterySettings = $request->input('lottery_repeater_basic');
            $errors          = [];
            foreach ($lotterySettings as $index => $setting) {
                $minTicket   = (int)$setting['min_ticket'];
                $maxTicket   = (int)$setting['max_ticket_per_user'];

                if ($minTicket > $maxTicket) {
                    $errors[] = "Minimum Ticket cannot be greater than Maximum Ticket in item " . ($index + 1);
                    break;
                }
            }
            if (!empty($errors)) {
                return redirect()->back()->with('error', $errors[0]);
            }
            $existingSettings = LotterySetting::where('store_id', getCurrentStore())
                ->get();

            $incomingProducts = [];

            foreach ($request['lottery_repeater_basic'] as $lotterySettingData) {
                $lotterySettingData['created_by']    = auth()->user()->id;
                $lotterySettingData['store_id']      = getCurrentStore();
                $lotterySettingData['refund_ticket'] = implode(',', $lotterySettingData['refund_ticket'] ?? []);

                $incomingProducts[] = $lotterySettingData['lottery_products'];

                $existingSetting = $existingSettings->firstWhere('lottery_products', $lotterySettingData['lottery_products']);

                if ($existingSetting) {
                    $existingSetting->update($lotterySettingData);
                } else {
                    LotterySetting::create($lotterySettingData);
                }
            }
            foreach ($existingSettings as $existingSetting) {
                if (!in_array($existingSetting->lottery_products, $incomingProducts)) {
                    $existingSetting->delete();
                }
            }

            return redirect()->back()->with('success', 'Lottery Setting successfully updated.');
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('lottery-prizes::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('lottery-prizes::edit');
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
            'Lottery Win',
            'Lottery Fail',
            'Lottery Remainder',
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
            'Lottery Win' => [
                'subject' => 'Lottery Win',
                'language' => [
                    'ar' => '<p>عزيزي {customer_name}،<br />أخبار مذهلة! لقد فزت بأحدث يانصيب منتجاتنا ويسعدنا أن نعلن أن جائزتك هي:</p><p><strong>اسم المنتج</strong>: {product_name}</p><br /><p><strong>سعر التجزئة</strong>: {product_price}</p><p>للحصول على جائزتك، يرجى اتباع الخطوات التالية:</p><p><strong>قدم بطاقة هويتك الصالحة والتذكرة الفائزة</strong>: {ticket_number}</p><br />إذا كانت لديك أي أسئلة، فلا تتردد في التواصل معنا. نحن سعداء من أجلك ولا نستطيع الانتظار حتى تستمتع بـ {product_name}! تهانينا مرة أخرى!</p><p>مع أطيب التحيات</p>',
                    'da' => '<p>Kære {customer_name},<br />Fantastiske nyheder! Du har vundet vores seneste produktlotteri, og vi er glade for at kunne meddele, at din præmie er:</p><p><strong>Produktnavn</strong>: {product_name}</p><br /><p ><strong>Detailpris</strong>: {product_price}</p><p>For at gøre krav på din præmie, følg venligst nedenstående trin:</p><p><strong>Vis dit gyldige ID og vinderkupon< /strong>: {ticket_number}</p><br /><p>Hvis du har spørgsmål, er du velkommen til at kontakte os. Vi er begejstrede for dig og kan ikke vente på, at du får glæde af dit {product_name}! Endnu en gang tillykke!</p><p>Med venlig hilsen</p>',
                    'de' => '<p>Sehr geehrter {customer_name},<br />Tolle Neuigkeiten! Sie haben bei unserer letzten Produktlotterie gewonnen und wir freuen uns, Ihnen mitteilen zu können, dass Ihr Preis ist:</p><p><strong>Produktname</strong>: {product_name}</p><br /><p><strong>Verkaufspreis</strong>: {product_price}</p><p>Um Ihren Preis einzufordern, folgen Sie bitte den folgenden Schritten:</p><p><strong>Legen Sie Ihren gültigen Ausweis und Ihr Gewinnlos vor</strong>: {ticket_number}</p><br /><p>Wenn Sie Fragen haben, können Sie sich gerne an uns wenden. Wir freuen uns für Sie und können es kaum erwarten, bis Sie Ihr {product_name} genießen können! Nochmals herzlichen Glückwunsch!</p><p>Mit freundlichen Grüßen</p>',
                    'en' => '<p>Dear {customer_name},<br />Amazing news! You’ve won our latest product lottery and we’re excited to announce that your prize is:</p><p><strong>Product Name</strong>: {product_name}</p><br /><p><strong>Retail Price</strong>: {product_price}</p><p>To claim your prize, please follow the steps below:</p><p><strong>Present your valid ID and winning ticket</strong>: {ticket_number}</p><br /><p>If you have any questions, feel free to reach out to us. We’re thrilled for you and can’t wait for you to enjoy your {product_name}! Congratulations once again!</p><p>Best regards</p>',
                    'es' => '<p>Estimado {customer_name}:<br />¡Buenas noticias! Ha ganado nuestro último sorteo de productos y nos complace anunciar que su premio es:</p><p><strong>Nombre del producto</strong>: {product_name}</p><br /><p><strong>Precio de venta al público</strong>: {product_price}</p><p>Para reclamar su premio, siga los pasos a continuación:</p><p><strong>Presente su identificación válida y el boleto ganador</strong>: {ticket_number}</p><br /><p>Si tiene alguna pregunta, no dude en comunicarse con nosotros. ¡Estamos encantados por usted y no podemos esperar a que disfrute de su {product_name}! ¡Felicitaciones una vez más!</p><p>Saludos cordiales</p>',
                    'fr' => '<p>Cher {customer_name},<br />Excellente nouvelle ! Vous avez gagné notre dernière loterie de produits et nous sommes ravis de vous annoncer que votre prix est :</p><p><strong>Nom du produit</strong> : {product_name}</p><br /><p><strong>Prix de vente</strong> : {product_price}</p><p>Pour réclamer votre prix, veuillez suivre les étapes ci-dessous :</p><p><strong>Présentez votre pièce d’identité valide et votre ticket gagnant</strong> : {ticket_number}</p><br /><p>Si vous avez des questions, n’hésitez pas à nous contacter. Nous sommes ravis pour vous et avons hâte que vous profitiez de votre {product_name} ! Félicitations encore une fois !</p><p>Cordialement</p>',
                    'it' => '<p>Gentile {customer_name},<br />Notizie fantastiche! Hai vinto la nostra ultima lotteria di prodotti e siamo lieti di annunciare che il tuo premio è:</p><p><strong>Nome del prodotto</strong>: {product_name</p><br /><p><strong>Prezzo al dettaglio</strong>: {product_price</p><p>Per richiedere il tuo premio, segui i passaggi sottostanti:</p><p><strong>Presenta il tuo documento didentità valido e il biglietto vincente</strong>: {ticket_number</p><br /><p>Se hai domande, non esitare a contattarci. Siamo entusiasti per te e non vediamo lora che tu possa goderti il ​​tuo {product_name}! Congratulazioni ancora una volta!</p><p>Cordiali saluti</p>',
                    'ja' => '<p>{customer_name} 様、<br />素晴らしいニュースです！ 最新の製品抽選に当選されました。賞品は次のとおりです。</p><p><strong>製品名</strong>: {product_name}</p><br /><p><strong>小売価格</strong>: {product_price}</p><p>賞品を受け取るには、以下の手順に従ってください。</p><p><strong>有効な ID と当選チケットを提示してください</strong>: {ticket_number}</p><br /><p>ご質問がございましたら、お気軽にお問い合わせください。 お客様に喜んでいただき、{product_name} を楽しんでいただけるのを楽しみにしています。 改めてお祝い申し上げます。</p><p>よろしくお願いいたします</p>',
                    'nl' => '<p>Beste {customer_name},<br />Geweldig nieuws! U heeft onze laatste productloterij gewonnen en we zijn verheugd om aan te kondigen dat uw prijs is:</p><p><strong>Productnaam</strong>: {product_name}</p><br /><p><strong>Verkoopprijs</strong>: {product_price}</p><p>Om uw prijs te claimen, volgt u de onderstaande stappen:</p><p><strong>Toon uw geldige identiteitsbewijs en winnende lot</strong>: {ticket_number}</p><br /><p>Als u vragen heeft, neem dan gerust contact met ons op. We zijn blij voor u en kunnen niet wachten om u te laten genieten van uw {product_name}! Nogmaals gefeliciteerd!</p><p>Met vriendelijke groet</p>',
                    'pl' => '<p>Szanowny {customer_name},<br />Wspaniałe wieści! Wygrałeś naszą najnowszą loterię produktów i z przyjemnością ogłaszamy, że Twoja nagroda to:</p><p><strong>Nazwa produktu</strong>: {product_name</p><br /><p><strong>Cena detaliczna</strong>: {product_price</p><p>Aby odebrać nagrodę, wykonaj poniższe czynności:</p><p><strong>Przekaż swój ważny dowód tożsamości i zwycięski kupon</strong>: {ticket_number</p><br /><p>Jeśli masz jakieś pytania, skontaktuj się z nami. Cieszymy się z Twojego powodu i nie możemy się doczekać, aż będziesz cieszyć się swoim {product_name}! Jeszcze raz gratulujemy!</p><p>Najlepsze pozdrowienia</p>',
                    'ru' => '<p>Уважаемый {customer_name},<br />Потрясающие новости! Вы выиграли в нашей последней лотерее продуктов, и мы рады сообщить, что ваш приз:</p><p><strong>Название продукта</strong>: {product_name}</p><br /><p><strong>Розничная цена</strong>: {product_price}</p><p>Чтобы получить свой приз, выполните следующие действия:</p><p><strong>Предъявите свое действительное удостоверение личности и выигрышный билет</strong>: {ticket_number}</p><br /><p>Если у вас есть какие-либо вопросы, не стесняйтесь обращаться к нам. Мы рады за вас и с нетерпением ждем, когда вы сможете насладиться своим {product_name}! Поздравляем еще раз!</p><p>С наилучшими пожеланиями</p>',
                    'pt' => '<p>Caro {customer_name},<br />Notícias incríveis! Você ganhou nossa última loteria de produtos e estamos felizes em anunciar que seu prêmio é:</p><p><strong>Nome do produto</strong>: {product_name}</p><br /><p><strong>Preço de varejo</strong>: {product_price}</p><p>Para reivindicar seu prêmio, siga as etapas abaixo:</p><p><strong>Apresente seu documento de identidade válido e o bilhete vencedor</strong>: {ticket_number}</p><br /><p>Se você tiver alguma dúvida, sinta-se à vontade para entrar em contato conosco. Estamos felizes por você e mal podemos esperar para que você aproveite seu {product_name}! Parabéns mais uma vez!</p><p>Atenciosamente</p>',
                    'zh' => '<p>尊敬的 {customer_name}，<br />好消息！您赢得了我们最新的产品抽奖，我们很高兴地宣布您的奖品是：</p><p><strong>产品名称</strong>：{product_name}</p><br /><p><strong>零售价</strong>：{product_price}</p><p>要领取奖品，请按照以下步骤操作：</p><p><strong>出示您的有效身份证件和中奖彩票</strong>：{ticket_number}</p><br /><p>如果您有任何疑问，请随时与我们联系。我们为您感到高兴，迫不及待地希望您享受您的 {product_name}！再次恭喜！</p><p>此致敬意</p>',
                    'he' => '<p>{customer_name} היקר,<br />תודה שפנית! בדקנו את שאלתך לגבי {product_name}, והנה התשובה לשאילתתך:</p><p><strong>השאילתה שלך</strong>: {product_query}</p><br /><p>< strong>התגובה שלנו</strong>: {product_answer}</p><p>אם יש לך שאלות נוספות או שאתה זקוק למידע נוסף, אל תהסס ליידע אותנו.</p>',
                    'tr' => '<p>Sayın {customer_name},<br />Harika bir haber! En son ürün piyangomuzu kazandınız ve ödülünüzün şu olduğunu duyurmaktan heyecan duyuyoruz:</p><p><strong>Ürün Adı</strong>: {product_name}</p><br /><p><strong>Perakende Fiyatı</strong>: {product_price}</p><p>Ödülünüzü talep etmek için lütfen aşağıdaki adımları izleyin:</p><p><strong>Geçerli kimliğinizi ve kazanan biletinizi sunun</strong>: {ticket_number}</p><br /><p>Herhangi bir sorunuz varsa, bizimle iletişime geçmekten çekinmeyin. Sizin için heyecanlıyız ve {product_name}inizin keyfini çıkarmanızı sabırsızlıkla bekliyoruz! Bir kez daha tebrikler!</p><p>Saygılarımızla</p>',
                    'pt-br' => '<p>Caro {customer_name},<br />Notícias incríveis! Você ganhou nossa última loteria de produtos e estamos felizes em anunciar que seu prêmio é:</p><p><strong>Nome do produto</strong>: {product_name}</p><br /><p><strong>Preço de varejo</strong>: {product_price}</p><p>Para reivindicar seu prêmio, siga as etapas abaixo:</p><p><strong>Apresente seu documento de identidade válido e o bilhete vencedor</strong>: {ticket_number}</p><br /><p>Se você tiver alguma dúvida, sinta-se à vontade para entrar em contato conosco. Estamos felizes por você e mal podemos esperar para que você aproveite seu {product_name}! Parabéns mais uma vez!</p><p>Atenciosamente</p>',
                ],
            ],
            'Lottery Fail' => [
                'subject' => 'Lottery Fail',
                'language' => [
                    'ar' => '<p>عزيزي {customer_name}،<br />نشكرك على المشاركة في يانصيب المنتجات الأخير! ورغم أن تذكرتك {ticket_number} لم تكن المحظوظة هذه المرة، نود أن نعرض عليك الجائزة المذهلة التي كانت متاحة للفوز بها:</p><p><strong>اسم المنتج</strong>: {product_name}</p><br /><p><strong>سعر التجزئة</strong>: {product_price}</p><p>لكن لا تقلق، فسوف نجري المزيد من يانصيب المنتجات المثيرة قريبًا، وقد يكون فوزك التالي قريبًا! ترقب السحوبات القادمة لمزيد من الفرص للفوز بمنتجات رائعة مثل هذا المنتج.</p><p>شكرًا لك مرة أخرى على المشاركة!</p><p>مع أطيب التحيات</p>',
                    'da' => '<p>Kære {customer_name},<br />Tak, fordi du deltog i vores seneste produktlotteri! Selvom din billet {ticket_number} ikke var den heldige denne gang, vil vi gerne vise dig den fantastiske præmie, der var på højkant:</p><p><strong>Produktnavn</strong>: {product_name }</p><br /><p><strong>Detailpris</strong>: {product_price}</p><p>Men bare rolig, vi har snart flere spændende produktlotterier, og din næste sejr kan være lige om hjørnet! Hold øje med kommende lodtrækninger for flere chancer for at vinde fantastiske produkter som denne.</p><p>Tak for din deltagelse!</p><p>Med venlig hilsen</p>',
                    'de' => '<p>Sehr geehrter {customer_name},<br />vielen Dank für Ihre Teilnahme an unserer letzten Produktverlosung! Obwohl Ihr Los {ticket_number} diesmal nicht das Glückslos war, möchten wir Ihnen den fantastischen Preis zeigen, den es zu gewinnen gab:</p><p><strong>Produktname</strong>: {product_name}</p><br /><p><strong>Verkaufspreis</strong>: {product_price}</p><p>Aber keine Sorge, wir werden bald weitere spannende Produktverlosungen haben und Ihr nächster Gewinn könnte gleich um die Ecke sein! Bleiben Sie dran für kommende Ziehungen, um weitere Chancen zu haben, fantastische Produkte wie dieses zu gewinnen.</p><p>Nochmals vielen Dank für Ihre Teilnahme!</p><p>Mit freundlichen Grüßen</p>',
                    'en' => '<p>Dear {customer_name},<br />Thank you for participating in our recent product lottery! While your ticket {ticket_number} wasn’t the lucky one this time, we’d like to show you the amazing prize that was up for grabs:</p><p><strong>Product Name</strong>: {product_name}</p><br /><p><strong>Retail Price</strong>: {product_price}</p><p>But don’t worry, we’ll have more exciting product lotteries soon, and your next win could be just around the corner! Stay tuned for upcoming draws for more chances to win fantastic products like this one.</p><p>Thank you again for participating!</p><p>Best regards</p>',
                    'es' => '<p>Estimado {customer_name}:<br />¡Gracias por participar en nuestro reciente sorteo de productos! Si bien esta vez su boleto {ticket_number} no fue el afortunado, nos gustaría mostrarle el increíble premio que estaba en juego:</p><p><strong>Nombre del producto</strong>: {product_name}</p><br /><p><strong>Precio minorista</strong>: {product_price}</p><p>Pero no se preocupe, pronto tendremos más sorteos de productos emocionantes y su próximo premio podría estar a la vuelta de la esquina. Esté atento a los próximos sorteos para tener más oportunidades de ganar productos fantásticos como este.</p><p>¡Gracias nuevamente por participar!</p><p>Saludos cordiales</p>',
                    'fr' => '<p>Cher {customer_name},<br />Merci davoir participé à notre récente loterie de produits ! Bien que votre billet {ticket_number} nait pas été le gagnant cette fois-ci, nous aimerions vous montrer le prix incroyable qui était en jeu :</p><p><strong>Nom du produit</strong> : {product_name}</p><br /><p><strong>Prix de vente</strong> : {product_price}</p><p>Mais ne vous inquiétez pas, nous organiserons bientôt dautres loteries de produits passionnantes, et votre prochain gain pourrait bien être imminent ! Restez à lécoute des prochains tirages pour avoir plus de chances de gagner des produits fantastiques comme celui-ci.</p><p>Merci encore davoir participé !</p><p>Cordialement</p>',
                    'it' => '<p>Gentile {customer_name},<br />Grazie per aver partecipato alla nostra recente lotteria dei prodotti! Anche se il tuo biglietto {ticket_number} non è stato quello fortunato questa volta, vorremmo mostrarti il ​​fantastico premio in palio:</p><p><strong>Nome del prodotto</strong>: {product_name</p><br /><p><strong>Prezzo al dettaglio</strong>: {product_price</p><p>Ma non preoccuparti, presto avremo altre entusiasmanti lotterie di prodotti e la tua prossima vincita potrebbe essere dietro langolo! Resta sintonizzato per le prossime estrazioni per avere più possibilità di vincere fantastici prodotti come questo.</p><p>Grazie ancora per aver partecipato!</p><p>Cordiali saluti</p>',
                    'ja' => '<p>{customer_name} 様、<br />先日の製品抽選にご参加いただき、ありがとうございました。今回、お客様のチケット {ticket_number} は当選しませんでしたが、獲得できる素晴らしい賞品をご紹介したいと思います:</p><p><strong>製品名</strong>: {product_name}</p><br /><p><strong>小売価格</strong>: {product_price}</p><p>でも、ご心配なく。近々、もっとエキサイティングな製品抽選を開催しますので、次の当選ももうすぐかもしれません。今回のような素晴らしい製品を獲得するチャンスをさらに増やすため、今後の抽選にご期待ください。</p><p>ご参加いただき、ありがとうございました!</p><p>よろしくお願いいたします</p>',
                    'nl' => '<p>Beste {customer_name},<br />Bedankt voor uw deelname aan onze recente productloterij! Hoewel uw lot {ticket_number} deze keer niet de gelukkige was, willen we u graag de geweldige prijs laten zien die te winnen was:</p><p><strong>Productnaam</strong>: {product_name}</p><br /><p><strong>Verkoopprijs</strong>: {product_price}</p><p>Maar maak u geen zorgen, we hebben binnenkort meer spannende productloterijen en uw volgende winst kan zomaar om de hoek liggen! Blijf op de hoogte van komende trekkingen voor meer kansen om fantastische producten zoals deze te winnen.</p><p>Nogmaals bedankt voor uw deelname!</p><p>Met vriendelijke groet</p>',
                    'pl' => '<p>Szanowny {customer_name},<br />Dziękujemy za udział w naszej ostatniej loterii produktowej! Chociaż Twój los {ticket_number} nie był tym razem szczęśliwy, chcielibyśmy pokazać Ci niesamowitą nagrodę, która była do wygrania:</p><p><strong>Nazwa produktu</strong>: {product_name</p><br /><p><strong>Cena detaliczna</strong>: {product_price</p><p>Ale nie martw się, wkrótce będziemy mieć więcej ekscytujących loterii produktowych, a Twoja kolejna wygrana może być tuż za rogiem! Bądź na bieżąco z nadchodzącymi losowaniami, aby mieć więcej szans na wygranie fantastycznych produktów, takich jak ten.</p><p>Jeszcze raz dziękujemy za udział!</p><p>Pozdrawiamy</p>',
                    'ru' => '<p>Уважаемый {customer_name},<br />Спасибо за участие в нашей недавней лотерее продуктов! Хотя ваш билет {ticket_number} в этот раз не оказался счастливым, мы хотели бы показать вам потрясающий приз, который можно было выиграть:</p><p><strong>Название продукта</strong>: {product_name}</p><br /><p><strong>Розничная цена</strong>: {product_price}</p><p>Но не волнуйтесь, скоро у нас будет еще больше захватывающих лотерей продуктов, и ваш следующий выигрыш может быть не за горами! Следите за предстоящими розыгрышами, чтобы получить больше шансов выиграть такие фантастические продукты, как этот.</p><p>Еще раз спасибо за участие!</p><p>С наилучшими пожеланиями</p>',
                    'pt' => '<p>Caro {customer_name},<br />Obrigado por participar da nossa recente loteria de produtos! Embora seu bilhete {ticket_number} não tenha sido o sortudo desta vez, gostaríamos de mostrar a você o prêmio incrível que estava em disputa:</p><p><strong>Nome do produto</strong>: {product_name}</p><br /><p><strong>Preço de varejo</strong>: {product_price}</p><p>Mas não se preocupe, teremos mais loterias de produtos emocionantes em breve, e sua próxima vitória pode estar chegando! Fique ligado nos próximos sorteios para mais chances de ganhar produtos fantásticos como este.</p><p>Obrigado novamente por participar!</p><p>Atenciosamente</p>',
                    'zh' => '<p>尊敬的 {customer_name}，<br />感谢您参加我们最近的产品抽奖活动！虽然您的票号 {ticket_number} 这次不是幸运的，但我们想向您展示您可以赢得的惊人奖品：</p><p><strong>产品名称</strong>：{product_name}</p><br /><p><strong>零售价</strong>：{product_price}</p><p>但别担心，我们很快会有更多令人兴奋的产品抽奖活动，您的下一次获奖可能就在眼前！请继续关注即将举行的抽奖活动，以获得更多赢得类似这款精彩产品的机会。</p><p>再次感谢您的参与！</p><p>此致敬意</p>',
                    'he' => '<p>{customer_name} היקר,<br />תודה על ההשתתפות בהגרלת המוצרים האחרונה שלנו! אמנם הכרטיס שלך {ticket_number} לא היה בר המזל הפעם, אבל ברצוננו להראות לך את הפרס המדהים שעומד בפניך:</p><p><strong>שם המוצר</strong>: {product_name }</p><br /><p><strong>מחיר קמעונאי</strong>: {product_price}</p><p>אבל אל דאגה, בקרוב יהיו לנו הגרלות מוצרים מרגשות נוספות, הניצחון הבא יכול להיות ממש מעבר לפינה! הישאר מעודכן להגרלות הקרובות לקבלת סיכויים נוספים לזכות במוצרים פנטסטיים כמו זה.</p><p>שוב תודה לך על ההשתתפות!</p><p>בברכה</p>',
                    'tr' => '<p>Sayın {customer_name},<br />Son ürün çekilişimize katıldığınız için teşekkür ederiz! Bu seferki {ticket_number} numaralı biletiniz şanslı olmasa da, kazanabileceğiniz muhteşem ödülü size göstermek istiyoruz:</p><p><strong>Ürün Adı</strong>: {product_name</p><br /><p><strong>Perakende Fiyatı</strong>: {product_price</p><p>Ancak endişelenmeyin, yakında daha heyecan verici ürün çekilişlerimiz olacak ve bir sonraki kazancınız çok yakın olabilir! Bunun gibi harika ürünler kazanma şansınız için yaklaşan çekilişleri takip etmeye devam edin.</p><p>Katılımınız için tekrar teşekkür ederiz!</p><p>Saygılarımızla</p>',
                    'pt-br' => '<p>Caro {customer_name},<br />Obrigado por participar da nossa recente loteria de produtos! Embora seu bilhete {ticket_number} não tenha sido o sortudo desta vez, gostaríamos de mostrar a você o prêmio incrível que estava em disputa:</p><p><strong>Nome do produto</strong>: {product_name}</p><br /><p><strong>Preço de varejo</strong>: {product_price}</p><p>Mas não se preocupe, teremos mais loterias de produtos emocionantes em breve, e sua próxima vitória pode estar chegando! Fique ligado nos próximos sorteios para mais chances de ganhar produtos fantásticos como este.</p><p>Obrigado novamente por participar!</p><p>Atenciosamente</p>',
                ],
            ],
            'Lottery Remainder' => [
                'subject' => 'Lottery Remainder',
                'language' => [
                    'ar' => '<p>عزيزي {customer_name}،<br />نأمل أن تكون بخير! نتواصل معك لتذكيرك بأن اليانصيب الأخير الخاص بـ **معرف المنتج: {product_id}** قد انتهى الآن، وحان الوقت لاختيار الفائزين المحظوظين!</p><p><strong>للمتابعة، يُرجى:</strong></p><br /><p>حدد **{number_of_winner}** لهذا اليانصيب، والذي يمكنك العثور عليه في إعداداتك.</p><br /><p>إذا كانت لديك أي أسئلة أو تحتاج إلى مساعدة، فلا تتردد في التواصل معنا. نحن هنا لجعل هذه العملية سلسة قدر الإمكان!</p><p>شكرًا لك مرة أخرى على المشاركة!</p><p>مع أطيب التحيات</p>',
                    'da' => '<p>Kære {customer_name},<br />Vi håber, du har det godt! Vi kontakter dig for at minde dig om, at dit seneste lotteri for **Produkt-id: {product_id}** nu er afsluttet, og det er tid til at vælge din(e) heldige vinder(e)!</p><p><strong> For at fortsætte, venligst:</strong></p><br /><p>Vælg **{number_of_winner}** for dette lotteri, som du kan finde i dine indstillinger.</p><br />< p>Hvis du har spørgsmål eller brug for hjælp, er du velkommen til at kontakte os. Vi er her for at gøre denne proces så gnidningsfri som muligt!</p><p>Tak for din deltagelse igen!</p><p>Med venlig hilsen</p>',
                    'de' => '<p>Sehr geehrter {customer_name},<br />wir hoffen, es geht Ihnen gut! Wir möchten Sie daran erinnern, dass Ihre letzte Lotterie für **Produkt-ID: {product_id}** nun beendet ist und es Zeit ist, Ihren/Ihre glücklichen Gewinner auszuwählen!</p><p><strong>Um fortzufahren, gehen Sie bitte folgendermaßen vor:</strong></p><br /><p>Wählen Sie die **{number_of_winner}** für diese Lotterie aus, die Sie in Ihren Einstellungen finden.</p><br /><p>Wenn Sie Fragen haben oder Hilfe benötigen, können Sie sich jederzeit an uns wenden. Wir sind hier, um diesen Prozess so reibungslos wie möglich zu gestalten!</p><p>Nochmals vielen Dank für Ihre Teilnahme!</p><p>Mit freundlichen Grüßen</p>',
                    'en' => '<p>Dear {customer_name},<br />We hope you are doing great! We’re reaching out to remind you that your recent lottery for **Product ID: {product_id}** has now ended, and it’s time to select your lucky winner(s)!</p><p><strong>To proceed, please:</strong></p><br /><p>Select the **{number_of_winner}** for this lottery, which you can find in your settings.</p><br /><p>If you have any questions or need assistance, feel free to reach out. We are here to make this process as smooth as possible!</p><p>Thank you again for participating!</p><p>Best regards</p>',
                    'es' => '<p>Estimado {customer_name}:<br />Esperamos que estés muy bien. Nos comunicamos contigo para recordarte que tu reciente lotería para el **ID de producto: {product_id}** ya terminó y es momento de seleccionar a tus afortunados ganadores.</p><p><strong>Para continuar, por favor:</strong></p><br /><p>Selecciona el **{number_of_winner}** para esta lotería, que puedes encontrar en tu configuración.</p><br /><p>Si tienes alguna pregunta o necesitas ayuda, no dudes en comunicarte con nosotros. ¡Estamos aquí para hacer que este proceso sea lo más sencillo posible!</p><p>¡Gracias nuevamente por participar!</p><p>Saludos cordiales</p>',
                    'fr' => '<p>Cher {customer_name},<br />Nous espérons que vous allez bien ! Nous vous contactons pour vous rappeler que votre récente loterie pour **ID produit : {product_id}** est désormais terminée et quil est temps de sélectionner votre ou vos heureux gagnants !</p><p><strong>Pour continuer, veuillez :</strong></p><br /><p>Sélectionnez le **{number_of_winner}** pour cette loterie, que vous pouvez trouver dans vos paramètres.</p><br /><p>Si vous avez des questions ou si vous avez besoin daide, nhésitez pas à nous contacter. Nous sommes là pour rendre ce processus aussi fluide que possible !</p><p>Merci encore pour votre participation !</p><p>Cordialement</p>',
                    'it' => '<p>Gentile {customer_name},<br />Ci auguriamo che tu stia bene! Ti contattiamo per ricordarti che la tua recente lotteria per **ID prodotto: {product_id}** è terminata ed è il momento di selezionare il/i tuo/i fortunato/i vincitore/i!</p><p><strong>Per procedere, ti preghiamo di:</strong></p><br /><p>Seleziona il **{number_of_winner}** per questa lotteria, che puoi trovare nelle tue impostazioni.</p><br /><p>Se hai domande o hai bisogno di assistenza, non esitare a contattarci. Siamo qui per rendere questo processo il più agevole possibile!</p><p>Grazie ancora per aver partecipato!</p><p>Cordiali saluti</p>',
                    'ja' => '<p>{customer_name} 様、<br />お元気でお過ごしのことと思います。**製品 ID: {product_id}** の最近の抽選が終了しましたので、幸運な当選者を選ぶ時が来ました。</p><p><strong>続行するには、次の手順に従ってください。</strong></p><br /><p>この抽選の **{number_of_winner}** を選択します。これは設定で確認できます。</p><br /><p>ご質問やサポートが必要な場合は、お気軽にお問い合わせください。このプロセスをできるだけスムーズにするために尽力しています。</p><p>ご参加いただき、ありがとうございました。</p><p>よろしくお願いいたします</p>',
                    'nl' => '<p>Beste {customer_name},<br />We hopen dat het goed met je gaat! We willen je eraan herinneren dat je recente loterij voor **Product-ID: {product_id}** nu is afgelopen en dat het tijd is om je gelukkige winnaar(s) te selecteren!</p><p><strong>Om verder te gaan, doe je het volgende:</strong></p><br /><p>Selecteer het **{number_of_winner}** voor deze loterij, dat je in je instellingen kunt vinden.</p><br /><p>Als je vragen hebt of hulp nodig hebt, neem dan gerust contact met ons op. We zijn er om dit proces zo soepel mogelijk te laten verlopen!</p><p>Nogmaals bedankt voor je deelname!</p><p>Met vriendelijke groet</p>',
                    'pl' => '<p>Szanowny {customer_name},<br />Mamy nadzieję, że wszystko u Ciebie w porządku! Przypominamy, że ostatnia loteria **Identyfikator produktu: {product_id}** dobiegła końca i nadszedł czas, aby wybrać szczęśliwców!</p><p><strong>Aby kontynuować, wykonaj następujące czynności:</strong></p><br /><p>Wybierz **{number_of_winner}** dla tej loterii, którą znajdziesz w swoich ustawieniach.</p><br /><p>Jeśli masz jakieś pytania lub potrzebujesz pomocy, skontaktuj się z nami. Jesteśmy tutaj, aby ten proces przebiegał jak najsprawniej!</p><p>Jeszcze raz dziękujemy za udział!</p><p>Pozdrawiamy</p>',
                    'ru' => '<p>Уважаемый {customer_name},<br />Надеемся, у вас все отлично! Мы обращаемся к вам, чтобы напомнить, что ваша недавняя лотерея для **Идентификатора продукта: {product_id}** уже закончилась, и пришло время выбрать счастливчика(ов)!</p><p><strong>Чтобы продолжить, пожалуйста:</strong></p><br /><p>Выберите **{number_of_winner}** для этой лотереи, который вы можете найти в своих настройках.</p><br /><p>Если у вас есть какие-либо вопросы или вам нужна помощь, не стесняйтесь обращаться к нам. Мы здесь, чтобы сделать этот процесс максимально гладким!</p><p>Еще раз спасибо за участие!</p><p>С наилучшими пожеланиями</p>',
                    'pt' => '<p>Caro {customer_name},<br />Esperamos que você esteja bem! Estamos entrando em contato para lembrá-lo de que sua loteria recente para **Product ID: {product_id}** terminou, e é hora de selecionar seu(s) ganhador(es) sortudo(s)!</p><p><strong>Para prosseguir, por favor:</strong></p><br /><p>Selecione o **{number_of_winner}** para esta loteria, que você pode encontrar em suas configurações.</p><br /><p>Se você tiver alguma dúvida ou precisar de ajuda, sinta-se à vontade para entrar em contato. Estamos aqui para tornar este processo o mais tranquilo possível!</p><p>Obrigado novamente por participar!</p><p>Atenciosamente</p>',
                    'zh' => '<p>尊敬的 {customer_name}，<br />希望您一切顺利！我们在此提醒您，您最近针对 **产品 ID：{product_id}** 的抽奖现已结束，现在是时候选择您的幸运获奖者了！</p><p><strong>要继续，请：</strong></p><br /><p>选择此抽奖的 **{number_of_winner}**，您可以在设置中找到它。</p><br /><p>如果您有任何疑问或需要帮助，请随时与我们联系。我们在这里让这个过程尽可能顺利！</p><p>再次感谢您的参与！</p><p>此致敬意</p>',
                    'he' => '<p>{customer_name} היקר,<br />אנחנו מקווים שאתה מצליח! אנו פונים כדי להזכיר לך שההגרלה האחרונה שלך עבור **מזהה מוצר: {product_id}** הסתיימה כעת, וזה הזמן לבחור את הזוכים המאושרים שלך!</p><p><strong>ל המשך, בבקשה:</strong></p><br /><p>בחר את **{number_of_winner}** להגרלה זו, אותה תוכל למצוא בהגדרות שלך.</p><br /><p > אם יש לך שאלות כלשהן או שאתה זקוק לסיוע, אל תהסס לפנות. אנחנו כאן כדי להפוך את התהליך הזה לחלק ככל האפשר!</p><p>שוב תודה על ההשתתפות!</p><p>בברכה</p>',
                    'tr' => '<p>Sayın {customer_name},<br />Umarız iyisinizdir! **Ürün Kimliği: {product_id}** için yakın zamanda düzenlenen piyangonuzun sona erdiğini ve şanslı kazananınızı/kazananlarınızı seçme zamanının geldiğini hatırlatmak için sizinle iletişime geçiyoruz!</p><p><strong>Devam etmek için lütfen:</strong></p><br /><p>Ayarlarınızda bulabileceğiniz bu piyango için **{number_of_winner}**ı seçin.</p><br /><p>Herhangi bir sorunuz varsa veya yardıma ihtiyacınız varsa, bizimle iletişime geçmekten çekinmeyin. Bu süreci olabildiğince sorunsuz hale getirmek için buradayız!</p><p>Katılımınız için tekrar teşekkür ederiz!</p><p>Saygılarımızla</p>',
                    'pt-br' => '<p>Caro {customer_name},<br />Esperamos que você esteja bem! Estamos entrando em contato para lembrá-lo de que sua loteria recente para **Product ID: {product_id}** terminou, e é hora de selecionar seu(s) ganhador(es) sortudo(s)!</p><p><strong>Para prosseguir, por favor:</strong></p><br /><p>Selecione o **{number_of_winner}** para esta loteria, que você pode encontrar em suas configurações.</p><br /><p>Se você tiver alguma dúvida ou precisar de ajuda, sinta-se à vontade para entrar em contato. Estamos aqui para tornar este processo o mais tranquilo possível!</p><p>Obrigado novamente por participar!</p><p>Atenciosamente</p>',
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
        $allEmail              = EmailTemplate::where('created_by', Auth::user()->id)->get();
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

    public function LotteryemailTemplate($language = 'en')
    {
        if (auth()->user()->isAbleTo('Manage Lottery Mail Setting')) {
            $languages         = Utility::languages();
            $emailTemplate     = EmailTemplate::whereIn('name', ['Lottery Win', 'Lottery Fail'])->where('created_by', \Auth::user()->id)->first();
            if (Auth::user()) {
                LotteryPrizesController::EmailTemplateDefaultData();
                LotteryPrizesController::userTemplateDefaultData(Auth::user()->id);
            }
            $currEmailTempLang = EmailTemplateLang::where('parent_id', '=', $emailTemplate->id)->where('language', $language)->first();

            if (!isset($currEmailTempLang) || empty($currEmailTempLang)) {
                $currEmailTempLang       = EmailTemplateLang::where('parent_id', '=', $emailTemplate->id)->where('language', 'en')->first();
                $currEmailTempLang->lang = $language;
            }

            $EmailTemplates = EmailTemplate::whereIn('name', ['Lottery Win', 'Lottery Fail'])->where('created_by', \Auth::user()->id)->get();

            return view('lottery-prizes::admin.mail.show', compact('emailTemplate', 'languages', 'currEmailTempLang', 'language', 'EmailTemplates'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function updateLotteryEmailSettings(Request $request, $id)
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

        $emailTemplate       = EmailTemplate::where('id', $id)->first();
        $emailTemplate->from = $request->from;
        $emailTemplate->save();

        $emailLangTemplate = EmailTemplateLang::where('parent_id', '=', $id)->where('language', '=', $request->language)->first();
        // if record not found then create new record else update it.
        if (empty($emailLangTemplate)) {
            $emailLangTemplate            = new EmailTemplateLang();
            $emailLangTemplate->parent_id = $id;
            $emailLangTemplate->language  = $request['language'];
            $emailLangTemplate->subject   = $request['subject'];
            $emailLangTemplate->content   = $request['content'];
            $emailLangTemplate->save();
        } else {
            $emailLangTemplate->subject   = $request['subject'];
            $emailLangTemplate->content   = $request['content'];
            $emailLangTemplate->save();
        }

        return redirect()->route(
            'manage.lottery.email.language',
            [
                $emailTemplate->id,
                $request->lang,
            ]
        )->with('success', __('Email Template successfully updated.'));
    }

    public function manageLotteryEmailLang(Request $request, $id, $language = 'en')
    {
        $languages         = Utility::languages();
        $emailTemplate     = EmailTemplate::where('id', '=', $id)->first();
        $currEmailTempLang = EmailTemplateLang::where('parent_id', '=', $id)->where('language', $language)->first();

        if (!isset($currEmailTempLang) || empty($currEmailTempLang)) {
            $currEmailTempLang       = EmailTemplateLang::where('parent_id', '=', $id)->where('language', 'en')->first();

            if ($currEmailTempLang) {
                $currEmailTempLang->language = $language;
            }
        }

        $EmailTemplates = EmailTemplate::whereIn('name', ['Lottery Win', 'Lottery Fail'])->where('created_by', \Auth::user()->id)->get();

        return view('lottery-prizes::admin.mail.show', compact('emailTemplate', 'languages', 'currEmailTempLang', 'EmailTemplates'));
    }
    public function lottery_product(Request $request, $storeSlug, $product_slug)
    {
        $store = getStore($storeSlug);
        if (!$store) {
            abort(404);
        }
        $store_id     = $store->id;
        $slug         = $store->slug;
        $currentTheme = $store->theme_id;
        Theme::set($currentTheme);
        $theme_id     = $currentTheme;
        $storeId      = $store->id;
        $product      = Product::where('slug', $product_slug)->where('store_id', $store_id)->first();
        if (!$product) {
            abort(404);
        }
        $id = $product->id;
        $MainCategoryList = Category::where('status', 1)->where('store_id', $store_id)->get();
        
        $question         = ProductQuestion::where('product_id', $id)->where('store_id', $storeId)->get();
        $category_options = Category::where('store_id', $store->id)
            ->where('status', 1)
            ->pluck('name', 'id')
            ->prepend('All Products', '0');
        $reviews          = Testimonial::where('status', 1)->where('store_id', $storeId)->get();
        
        $customer         = auth('customers')->user();

        $lottery_setting  = LotterySetting::where('store_id', $store->id)
            ->where('lottery_products', $product->id)
            ->first();
        if ($lottery_setting) {
            $purchased_tickets_count = PurchaseTicket::where('lottery_id', $lottery_setting->id)->count();
            $remaining_tickets       = $lottery_setting->total_ticket - $purchased_tickets_count;
            $percentage              = ($lottery_setting->total_ticket > 0) ? ($purchased_tickets_count / $lottery_setting->total_ticket) * 100 : 0;

            $purchased_tickets       = PurchaseTicket::where('lottery_id', $lottery_setting->id)->orderBy('id', 'desc')->get();

            if (auth('customers')->user()) {
                $customer_tickets_count = PurchaseTicket::where('lottery_id', $lottery_setting->id)->where('customer_id', auth('customers')->user()->id)->count();
                $user_remaining_tickets = $lottery_setting->max_ticket_per_user - $customer_tickets_count;
                if ($user_remaining_tickets < 0) {
                    $user_remaining_tickets = 0;
                }
            } else {
                $user_remaining_tickets = 0;
            }

            $activeLotteries = null;
            $currentDateTime = \Carbon\Carbon::now();
            date_default_timezone_set('Asia/Kolkata');
            $lotteryStartDateTime = \Carbon\Carbon::parse($lottery_setting->lottery_start_date . ' ' . $lottery_setting->lottery_start_time);
            $lotteryEndDateTime = \Carbon\Carbon::parse($lottery_setting->lottery_end_date . ' ' . $lottery_setting->lottery_end_time);

            if ($lotteryEndDateTime < $lotteryStartDateTime) {
                $lotteryEndDateTime->addDay();
            }
            if ($currentDateTime->between($lotteryStartDateTime, $lotteryEndDateTime)) {

                $activeLotteries = $lottery_setting->lottery_products;
            }
            $winner_customer = LotteryCustomer::with('tickets')->where('lottery_id', $lottery_setting->id)->where('store_id', $store->id)
                ->where('status', 'win')
                ->where('email_sent', 1)
                ->get();

            return view('lottery-prizes::theme.lottery_product_detail', compact('slug', 'product', 'MainCategoryList', 'store', 'question', 'category_options', 'store_id', 'reviews', 'customer', 'lottery_setting', 'user_remaining_tickets', 'purchased_tickets_count', 'percentage', 'purchased_tickets', 'activeLotteries', 'winner_customer'));
        } else {
            abort(404);
        }
    }

    public function lottery_data(Request $request, $storeSlug, $product_slug)
    {
        $store = getStore($storeSlug);
        if (!$store) {
            abort(404);
        }
        $product = Product::where('slug', $product_slug)->where('store_id', $store->id)->first();
        if (!$product) {
            abort(404);
        }
        $lottery_setting = LotterySetting::where('store_id', $store->id)
            ->where('lottery_products', $product->id)
            ->first();
        $country_option = Country::orderBy('name', 'ASC')->pluck('name', 'id')->prepend('Select Country', 0)->toArray();

        $param = [
            'customer_id' => !empty(\Auth::guard('customers')->user()) ? \Auth::guard('customers')->user()->id : 0,
            'slug' => $store->slug,
            'store_id' => $store->id,
        ];
        $request->merge($param);
        $api = new ApiController();

        $payment_list_data = $api->payment_list($request, $store->slug);
        $payment_list      = $payment_list_data->getData();
        $filtered_payment_list = [];
        $stopFiltering     = false;
        foreach ($payment_list->data as $payment) {
            if ($stopFiltering) {
                break;
            }
            if ($payment->name === 'CinetPay') {
                $filtered_payment_list[] = $payment;
                $stopFiltering = true;
            } elseif ($payment->name !== 'cod' && $payment->name !== 'bank_transfer') {
                $filtered_payment_list[] = $payment;
            }
        }
        $payment_list->data = $filtered_payment_list;

        return view('lottery-prizes::theme.lottery-form', compact('lottery_setting', 'product', 'store', 'country_option', 'payment_list'));
    }

    public function lottery_form_data(Request $request, $slug)
    {
        $validator = \Validator::make(
            $request->all(),
            [
                'lottery_info.firstname' => 'required',
                'lottery_info.lastname' => 'required',
                'lottery_info.email' => 'required',
                'lottery_info.lottery_user_telephone' => 'required',
                'lottery_info.lottery_address' => 'required',
                'lottery_info.lottery_country' => 'required',
                'lottery_info.lottery_state' => 'required',
                'lottery_info.lottery_city' => 'required',
                'lottery_info.lottery_postecode' => 'required',
                'payment_methods' => 'required'
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }

        $paymentMethod = $request->payment_methods;
        $store         = getStore($slug);
        $product       = Product::find($request->product_id);

        $lottery_setting = LotterySetting::where('store_id', $store->id)
            ->where('lottery_products', $product->id)
            ->first();
        try {
            $response = $this->LotterypaymentService->process($request->all(), $paymentMethod, $slug, $lottery_setting->ticket_price);

            if (in_array($paymentMethod, ['paystack', 'Razorpay', 'paymentwall', 'Flutterwave', 'Paytm', 'telegram', 'paytr', 'midtrans', 'whatsapp',  'Khalti', 'authorizenet'])) {
                $viewHtml = $response->render();
                return $viewHtml;
            } elseif (in_array($paymentMethod, ['mercado', 'skrill', 'Sspay', 'toyyibpay', 'Paytabs', 'cod', 'bank_transfer', 'nepalste', 'tap', 'payhere', 'Phonepe', 'paddle', 'paiementpro', 'fedpay', 'cinetpay', 'coingate'])) {

                return new RedirectResponse($response->getTargetUrl());
            } elseif ($paymentMethod == 'coingate') {
                return new RedirectResponse($response->payment_url);
            } elseif ($paymentMethod == 'payfast') {
                return $response;
            } else {
                if (filter_var($response, FILTER_VALIDATE_URL)) {
                    return new RedirectResponse($response);
                } else {
                    throw new \Exception("Invalid response URL for payment method: $paymentMethod");
                }
            }
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Incorrect currency of payment.'));
        }
    }

    public function StoreLotteryPayment(Request $request, $slug)
    {
        $requests_data     = (Session::get('lottery_request_data'));
        if ($requests_data == null) {
            $requests_data = $request->all();
        }
        $ticket_amount     = $requests_data['ticket_price'];

        if ($requests_data['payment_methods'] == 'paytr') {
            $ticket_amount = sprintf("%01.2f", round(($requests_data['amount'] / 100), 2));
        } elseif ($requests_data['payment_methods'] == 'Midtrans') {
            $response = json_decode($request->json, true);
            if (isset($response['status_code']) && $response['status_code'] != 200) {
                return redirect()->back()->with('error', $response['status_message']);
            }
        } elseif ($requests_data['payment_methods'] == 'Khalti') {
            $store_data  = (Session::get('store_request_data'));
            $payload     = $request->payload;
            $token       = $payload['token'];
            $amount      = $payload['amount'];
            $admin_payment_setting = getAdminAllSetting($store_data->created_by, $store_data->id);
            $secret     = !empty($admin_payment_setting['khalti_secret_key']) ? $admin_payment_setting['khalti_secret_key'] : '';
            $khalti     = new Khalti();
            $response   = $khalti->verifyPayment($secret, $token, $amount);
            if ($response['status_code'] != '200') {
                return redirect()->back()->with('error', __('Transaction has been failed.'));
            }
        }

        $store = getStore($slug);
        $lottery_setting = LotterySetting::where('store_id', $store->id)
            ->where('lottery_products', $requests_data['product_id'])
            ->first();

        $code                       = $this->generateRandomCode('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz', 8);
        $lottery_data               = new PurchaseTicket();
        $lottery_data->firstname    = $requests_data['lottery_info']['firstname'] ?? '';
        $lottery_data->lottery_code = $code ?? 0;
        $lottery_data->lastname     = $requests_data['lottery_info']['lastname'] ?? '';
        $lottery_data->email        = $requests_data['lottery_info']['email'] ?? '';
        $lottery_data->number       = $requests_data['lottery_info']['lottery_user_telephone'] ?? '';
        $lottery_data->address      = $requests_data['lottery_info']['lottery_address'] ?? '';
        $lottery_data->country_id   = $requests_data['lottery_info']['lottery_country'] ?? 0;
        $lottery_data->state_id     = $requests_data['lottery_info']['lottery_state'] ?? 0;
        $lottery_data->city_id      = $requests_data['lottery_info']['lottery_city'] ?? 0;
        $lottery_data->postcode     = $requests_data['lottery_info']['lottery_postecode'] ?? '';
        $lottery_data->product_id   = $requests_data['product_id'] ?? '';
        $lottery_data->lottery_id   = $lottery_setting->id;
        $lottery_data->customer_id  = auth('customers')->user()->id ??  0;
        $lottery_data->amount       = $ticket_amount;
        $lottery_data->payment_type = $requests_data['payment_methods'];
        $lottery_data->store_id     = $store->id;
        $lottery_data->save();

        

        if ($requests_data['payment_methods'] == 'telegram' || $requests_data['payment_methods'] == 'whatsapp') {
            Session::put('lottery_data', $lottery_data);
            $successUrl = route('ticket.customer.success', $store->slug);
            return response()->json([
                'status' => 'success',
                'data' => [
                    'lottery_data' => $lottery_data,
                    'store' => $store,
                    'slug' => $slug,
                    'redirect_url' => $successUrl
                ]
            ]);
        } elseif ($requests_data['payment_methods'] == 'Khalti') {
            Session::put('lottery_data', $lottery_data);
            $successs = route('ticket.customer.success', $store->slug);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'lottery_data' => $lottery_data,
                    'status_code'  => $response['status_code'],
                    'store' => $store,
                    'slug' => $slug,
                    'redirect_url' => $successs
                ]
            ]);
        } else {
            return view('lottery-prizes::theme.payment_success', compact('lottery_data', 'store', 'slug'));
        }
    }

    public function getWhatsappUrl(Request $request, $slug)
    {
        $store = getStore($slug);
        $theme_id = $store->theme_id;
        $requests_data = Session::get('lottery_request_data');

        $telegram_access_token = \App\Models\Utility::GetValueByName('telegram_access_token', $store->id);
        $telegram_chat_id      = \App\Models\Utility::GetValueByName('telegram_chat_id', $store->id);
        $whatsapp_number       = $requests_data['lottery_info']['lottery_user_telephone'];

        $settings = getAdminAllSetting();
        $resp = 'Thank You!';
        if ($requests_data['payment_methods'] == 'telegram') {
            $msg = $resp;
            // Set your Bot ID and Chat ID.
            $telegrambot    = $telegram_access_token;
            $telegramchatid = $telegram_chat_id;

            // Function call with your own text or variable
            $url = 'https://api.telegram.org/bot' . $telegrambot . '/sendMessage';
            $data = array(
                'chat_id' => $telegramchatid,
                'text' => $msg,
            );
            $options = array(
                'http' => array(
                    'method' => 'POST',
                    'header' => "Content-Type:application/x-www-form-urlencoded\r\n",
                    'content' => http_build_query($data),
                ),
            );
            $context = stream_context_create($options);
            $url = $url;
        } else {
            $url = 'https://api.whatsapp.com/send?phone=' . $whatsapp_number . '&text=' . urlencode($resp);
        }
        return  $url;
    }

    private function generateRandomCode($characters, $length)
    {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }
        return $code;
    }

    public function showSuccessPage(Request $request, $slug)
    {
        $requests_data = (Session::get('lottery_request_data'));
        $lottery_data  = (Session::get('lottery_data'));
        $store = getStore($slug);
        return view('lottery-prizes::theme.payment_success', compact(
            'store',
            'slug',
            'lottery_data',
            ));
    }

    public static function sendOwnerEmailTemplate($emailTemplate, $mailTo, $customerName, $obj, $user, $store)
    {
        // find template is exist or not in our record
        $template = EmailTemplate::where('name', 'LIKE', $emailTemplate)->first();

        $theme_id = $store->theme_id;
        if (isset($template) && !empty($template)) {

            // check template is active or not by company
            $is_actives = UserEmailTemplate::where('template_id', '=', $template->id)->where('user_id', $user->id)->first();
            $slug = Store::where('created_by', $template->created_by)->select('slug')->first()->slug;

            $setting = getAdminAllSetting($user->id);
            if ($is_actives) {

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
                        $obj['product_id'] = $obj['product_id'] ?? 0;
                        $obj['number_of_winner'] = $obj['number_of_winner'] ?? 0;
                        $obj['product_name'] = $obj['product_name'] ?? '';
                        $obj['product_price'] = $obj['product_price'] ?? 0;
                        $obj['ticket_number'] = $obj['ticket_number'] ?? '0';

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
            '{product_id}',
            '{number_of_winner}',
            '{customer_name}',
            '{product_name}',
            '{product_price}',
            '{ticket_number}',
        ];
        $arrValue = [
            'product_id' => '-',
            'number_of_winner' => '-',
            'customer_name' => '-',
            'product_name' => '-',
            'product_price' => '-',
            'ticket_number' => '-',
        ];

        foreach ($obj as $key => $val) {
            $arrValue[$key] = $val;
        }

        $arrValue['app_name'] = $store->name;
        $arrValue['app_url'] = '<a href="' . env('APP_URL') . '" target="_blank">' . env('APP_URL') . '</a>';

        $ownername = User::where('id', $store->created_by)->first();

        return str_replace($arrVariable, array_values($arrValue), $content);
    }

    public static function refund_lottery(Request $request, $storeSlug, $product_slug)
    {
        $store = getStore($storeSlug);
        if (!$store) {
            abort(404);
        }
        $product = Product::where('slug', $product_slug)->where('store_id', $store->id)->first();
        if (!$product) {
            abort(404);
        }
        $lottery_setting = LotterySetting::where('store_id', $store->id)
            ->where('lottery_products', $product->id)
            ->first();

        $tickets = PurchaseTicket::where('store_id', $store->id)->where('customer_id', auth('customers')->user()->id)->where('product_id',$product->id)->where('lottery_id',$lottery_setting->id)->get();
        return view('lottery-prizes::theme.refund-lottery-form', compact('lottery_setting', 'product', 'store', 'tickets'));
    }

    public function refund_lottery_form_data(Request $request, $storeSlug)
    {
        if($request['ticket_code'])
        {
            $lottery_setting = PurchaseTicket::where('lottery_code',$request['ticket_code'])->where('customer_id',auth('customers')->user()->id)->first();
            $lottery_setting->delete();
    
            return redirect()->back()->with('success', __('Refund sucessfully.'));
        }else{
            return redirect()->back()->with('error', __('Please select a ticket code.'));
        }
    }
}
 