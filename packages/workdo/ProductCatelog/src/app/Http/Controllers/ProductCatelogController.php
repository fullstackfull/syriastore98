<?php

namespace Workdo\ProductCatelog\app\Http\Controllers;

use App\Mail\CommonEmailTemplate;
use App\Models\Customer;
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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Workdo\ProductCatelog\app\Models\ProductCatelog;
use Workdo\ProductCatelog\DataTables\ProductCatelogData;
use Illuminate\Support\Facades\Cache;

class ProductCatelogController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index(ProductCatelogData $dataTable)
    {
        if (!auth()->user()) {
            return redirect()->route('login');
        }

        if (auth()->user()->isAbleTo('Manage Catelog Request')) {
            $this->AdminDefaultEmail();
            $store = getStoreById(getCurrentStore());
            $enable_product_catelog = \App\Models\Utility::GetValueByName('enable_product_catelog', $store->theme_id, $store->id);
            if (isset($enable_product_catelog) && $enable_product_catelog == 'on') {
                $data = ProductCatelog::where('store_id', $store->id)->get();
                return $dataTable->render('product-catelog::index', compact('data'));
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
        return view('product-catelog::create');
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        $email = !empty($request->email) ? $request->email : \Auth::guard('customers')->user()->email;
        $customer_detail = Customer::where('email', $email)->first();

        $catelog_request = new ProductCatelog();
        $catelog_request->category_id = $request->category_id;
        $catelog_request->description = $request->description ?? '';
        $catelog_request->customer_id = !empty($customer_detail) ? \Auth::guard('customers')->user()->id : '';
        $catelog_request->email = !empty($customer_detail) ? \Auth::guard('customers')->user()->email : $email;
        $catelog_request->store_id = $request->store_id ?? getCurrentStore();
        $catelog_request->save();

        return redirect()->back()->with('success', __('Product Catelog store successfully.'));
    }

    public function adminManageEmailLang($id, $language = 'en')
    {
        $languages = Utility::languages();
        $emailTemplate = EmailTemplate::where('id', '=', $id)->first();
        $currEmailTempLang = EmailTemplateLang::where('parent_id', '=', $id)->where('is_default', 1)->first();
        $setting = getAdminAllSetting();

        if (!isset($currEmailTempLang) || empty($currEmailTempLang)) {
            EmailTemplateLang::where('parent_id', '=', $id)->update(['is_default' => 0]);
            $currEmailTempLang = EmailTemplateLang::where('parent_id', '=', $id)->where('language', $language)->first();

            $currEmailTempLang->is_default = 1;
            $currEmailTempLang->save();
        } else {
            EmailTemplateLang::where('parent_id', '=', $id)->update(['is_default' => 0]);
            $currEmailTempLang = EmailTemplateLang::where('parent_id', '=', $id)->where('language', $language)->first();
            $currEmailTempLang->is_default = 1;
            $currEmailTempLang->save();
        }

        $EmailTemplates = EmailTemplate::where('created_by', Auth::user()->id)->get();
        return redirect()->route('app-setting.index');
    }

    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('product-catelog::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        if (!empty(Auth::user()->id) && auth()->user()->isAbleTo('Send Email Catelog Request')) {
            $catelog_request = ProductCatelog::find($id);
            if ($catelog_request) {
                $dArr = Product::where('category_id', $catelog_request->category_id)->get();

                $store = Store::find($catelog_request->store_id);
                $owner = User::find($store->created_by);
                $ProductId = '';

                $resp = $this->sendOwnerEmailTemplate('Product Catelog', $catelog_request->email, $dArr, $owner, $store, $ProductId);

                if ($resp['is_success'] == true) {
                    return redirect()->back()->with('success', __('Email Send Successfully'));
                } else {
                    return redirect()->back()->with('error', $resp['error']);
                }
            } else {
                return redirect()->back()->with('error', __('The Product Catelog is not found'));
            }
        } else {
            return redirect()->back()->with('error', __('Please set SMTP Credentials in settings'));
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
        //
    }

    public function updateProductCatelogEmail(Request $request, $id)
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
        session()->put('app_setting_tab', $request->app_setting_tab);
        $UseremailTemplate = UserEmailTemplate::where('id', $request->user_email_template_id)->first();
        $UseremailTemplate->is_active = ($request->is_active == 'on' || $request->is_active == '1') ? 1 : 0;
        $UseremailTemplate->save();

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
        return redirect()->back()->with('success', __('Email Template successfully updated!'));
    }

    public function product_catelog(Request $request, $slug)
    {
        $store = getStore($slug);
        $store_id = $store->id;

         $return['html'] = view('product-catelog::pages.product-catelog', compact('store', 'store_id'))->render();
        return response()->json($return);
    }

    public static function AdminDefaultEmail()
    {
        $emailTemplate = 'Product Catelog';

        $emailTemp = EmailTemplate::where('name', $emailTemplate)->where('created_by', Auth::user()->id)->count();
        if ($emailTemp == 0) {
            EmailTemplate::create(
                [
                    'name' => $emailTemplate,
                    'from' => env('APP_NAME'),
                    'created_by' => Auth::user()->id,
                ]
            );

            $defaultTemplate = [
                'Product Catelog' => [
                    'subject' => 'Product Catelog',
                    'language' => [
                        'ar' => '<p>مرحبا </p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">مرحبا بك في {app_name}.</span></p><p>لاحظنا أنك قمت مؤخرا بزيارة موقع {app_name} وقمت باضافة بعض البنود الرائعة الى عربة التسوق الخاصة بك. نحن مبتهجون بأنك وجدت منتجات تحبها ومع ذلك ، يبدو أنك لم تنهي عملية الشراء الخاصة بك والانتهاء من طلبك في أقرب وقت ممكن</p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">معلومات منتج عربة الرسم :</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{</span><span style="font-family: var(--bs-body-font-family); font-size: var(--bs-body-font-size); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{pdf_view}</span><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">}</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">شكرا</span></p><p>{app_name}</p><p><br></p>',
                        'da' => '<p>Hallo?&nbsp;</p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Velkommen til {app_name}.</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Vi har bemærket, at du for nylig besøgte vores websted og tilføjede nogle fantastiske varer til indkøbsvognen. Vi er henrykte over, at du fandt produkter, du elsker! Men det ser ud til, at du ikke er færdig med dit køb.</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);"><br></span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Oplysninger om Cart-produkter:</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);"><br></span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{pdf_view}</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);"><br></span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Tak.</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{app_name}</span></p><p><br></p>',
                        'de' => '<p>Hallo,&nbsp;</p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Willkommen bei {app_name}.</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Wir haben bemerkt, dass Sie kürzlich unsere Website {app_name} besucht und einige fantastische Artikel in Ihren Warenkorb gelegt haben. Wir sind begeistert, dass Sie Produkte gefunden haben, die Sie lieben! Allerdings scheint es, als würden Sie Ihre Einkäufe nicht beenden. Sie beenden Ihren Bestellprozess so schnell wie möglich</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Warenkorb Produktinformation:</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{pdf_view}</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Danke,</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{app_name}</span></p><p><br></p>',
                        'en' => '<p>Hello, </p><p>Welcome to {app_name}.</p><p><span style="text-align: var(--bs-body-text-align);">We noticed that you recently visited our </span>{app_name} <span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">site and added some fantastic items to your shopping cart. We are thrilled that you found products you love! However, it seems like you did not finish your purchase.</span><span style="text-align: var(--bs-body-text-align);">You finish your order process as soon as possible</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);"><br></span></p><span style="font-weight: 600;">Category Related Product Information:</span><p></p><p><span style="font-weight: 600;"><br></span></p><p></p><p></p><p></p><p></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{pdf_view}</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);"><br></span></p><div><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Thanks,</span></p></div><p>{app_name}</p>',
                        'es' => '<p>Hola,&nbsp;</p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Bienvenido a {app_name}.</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Hemos notado que recientemente ha visitado nuestro sitio {app_name} y ha añadido algunos artículos fantásticos a su carrito de la compra. ¡Estamos encantados de que encontraste productos que amas! Sin embargo, parece que usted no terminó su compra. Usted termina su proceso de pedido tan pronto como sea posible</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);"><br></span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Información del producto del carro:</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{pdf_view}</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);"><br></span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Gracias,</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{app_name}</span></p><p><br></p>',
                        'fr' => '<p>Bonjour,&nbsp;</p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Bienvenue dans {app_name}.</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Nous avons remarqué que vous avez récemment visité notre site {app_name} et ajouté des articles fantastiques à votre panier. Nous sommes ravis que vous avez trouvé des produits que vous aimez ! Cependant, il semble que vous navez pas fini votre achat. Vous terminez votre commande dès que possible</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);"><br></span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Cart Renseignements sur le produit:</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{pdf_view}</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);"><br></span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Merci,</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{app_name}</span></p><p><br></p>',
                        'it' => '<p>Ciao,&nbsp;</p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Benvenuti in {app_name}.</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Abbiamo notato che recentemente hai visitato il nostro sito {app_name} e aggiunto alcuni articoli fantastici al tuo carrello. Siamo entusiasti di aver trovato dei prodotti che ami! Tuttavia, sembra che tu non abbia finito il tuo acquisto, finisci il tuo processo di ordine il prima possibile</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);"><br></span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Informazioni sul prodotto del carrello:</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{pdf_view}</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);"><br></span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Grazie,</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{app_name}</span></p><p><br></p>',
                        'ja' => '<p>こんにちは。&nbsp;</p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{app_name}へようこそ。</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">最近、弊社の {app_name} サイトを訪問し、お客様のショッピング・カートに素晴らしいアイテムをいくつか追加しました。 あなたが愛する製品を見つけたと私たちはワクワクしている ! しかし、購入を完了していないようですが、できるだけ早く注文処理を完了します。</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);"><br></span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">カート製品情報:</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);"><br></span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{pdf_view}</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);"><br></span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">ありがとう。</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{app_name}</span></p><p><br></p>',
                        'nl' => '<p>Hallo,&nbsp;</p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Welkom bij {app_name}.</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">We hebben gemerkt dat u onlangs onze site {app_name} hebt bezocht en enkele fantastische items aan uw winkelwagen heeft toegevoegd. We zijn blij dat je gevonden producten van je houdt! Echter, het lijkt alsof je niet klaar bent met uw aankoop. U klaar bent met uw bestelling proces zo snel mogelijk</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Productinformatie winkelwagen:</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{pdf_view}</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Bedankt.</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{app_name}</span></p><p><br></p>',
                        'pl' => '<p>Witaj,&nbsp;</p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Witamy w aplikacji {app_name}.</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Zauważyliśmy, że niedawno odwiedziliście naszą stronę {app_name} i dodaliśmy kilka fantastycznych pozycji do koszyka. Jesteśmy zachwyceni, że znalazłeś produkty, które kochasz! Jednak wydaje się, że nie skończyłaś swój zakup. Zakończ proces zamówienia tak szybko, jak to możliwe</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Informacje o produkcie koszyka:</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{pdf_view}</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Dziękuję,</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{app_name}</span></p><p><br></p>',
                        'ru' => '<p>Привет.&nbsp;</p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Вас приветствует {app_name}.</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Мы заметили, что вы недавно посетили наш сайт {app_name} и добавили несколько фантастических предметов в вашу корзину. Мы в восторге от того, что вы нашли продукты, которые вы любите! Однако, похоже, вы не закончили свою покупку. Вы закончите процесс заказа как можно скорее</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Информация о продукте корзины:</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{pdf_view}</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Спасибо.</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{app_name}</span></p><p><br></p>',
                        'pt' => '<p>Olá,&nbsp;</p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Bem-vindo a {app_name}.</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Notamos que você visitou recentemente o nosso site {app_name} e adicionou alguns itens fantásticos ao seu carrinho de compras. Estamos emocionados por você ter encontrado produtos que você ama! No entanto, parece que você não terminou a sua compra, termina o seu processo de encomenda o mais rápido possível</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Informações do Produto do carrinho:</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{pdf_view}</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Obrigado,</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{app_name}</span></p>',
                        'zh' => '<p>你好，</p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">欢迎使用 {app_name}。</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">我们注意到您最近访问了我们的 {app_name} 站点，并将一些精彩项目添加到购物车。 我们很高兴你找到了爱的产品 但是，你好像没有完成你的购买，你尽快完成你的订单流程</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">购物车产品信息:</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{pdf_view}</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">谢谢，</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{app_name}</span></p><div><br></div>',
                        'tr' => '<p>Merhaba.&nbsp;</p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{app_name} için hoş geldiniz.</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Kısa bir süre önce {app_name} sitemizi ziyaret ettiniz ve alışveriş sepetinize bazı fantastik öğeler eklediğinizi fark ettik. Sevdiğiniz ürünleri bulmanıza sevindik! Ancak, satın alma işleminizi tamamlamamış gibi görünmektedir.</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Alışveriş Sepeti Ürün Bilgileri:</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{pdf_view}</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Teşekkürler.</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{app_name}</span></p><p><br></p>',
                        'he' => '<p>שלום,&nbsp;</p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">ברוכים הבאים אל {app_name}.</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">שמנו לב שלאחרונה ביקרת באתר שלנו ב - {app_name} והוספת כמה פריטים נפלאים לעגלת הקניות שלך. אנחנו שמחים שמצאת מוצרים שאתה אוהב! עם זאת, זה נראה כאילו לא סיימת את הרכישה שלך.</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">פרטי מוצר של עגלה:</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{pdf_view}</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">תודה,</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{app_name}</span></p><p><br></p>',
                        'pt-br' => '<p>Olá,&nbsp;</p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Bem-vindo a {app_name}.</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Notamos que você visitou recentemente o nosso site {app_name} e adicionou alguns itens fantásticos ao seu carrinho de compras. Estamos emocionados por você ter encontrado produtos que você ama! No entanto, parece que você não terminou a sua compra, termina o seu processo de encomenda o mais rápido possível</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Informações do Produto do carrinho:</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{pdf_view}</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">Obrigado,</span></p><p><span style="font-family: var(--bs-body-font-family); font-weight: var(--bs-body-font-weight); text-align: var(--bs-body-text-align);">{app_name}</span></p><p><br></p>',
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
                                    'is_default' => ($lang == 'en') ? 1 : 0,
                                    'subject' => $defaultTemplate[$e->name]['subject'],
                                    'content' => $content,
                                ]
                            );
                        }
                    }
                }

            }

            $allEmail = EmailTemplate::where('created_by', Auth::user()->id)->get();
            foreach ($allEmail as $email) {
                $userEmailTemplate = UserEmailTemplate::firstOrNew(
                    [
                        'template_id' => $email->id,
                        'user_id' => Auth::user()->id
                    ]
                );

                if (!$userEmailTemplate->exists) {
                    $userEmailTemplate->is_active = 0;
                    $userEmailTemplate->save();
                }
            }
        }
    }

    public function sendOwnerEmailTemplate($emailTemplate, $mailTo, $obj, $owner, $store)
    {
        $template = EmailTemplate::where('name', 'LIKE', $emailTemplate)->first();

        $theme_id = $store->theme_id;

        if (isset($template) && !empty($template)) {

            $is_actives = UserEmailTemplate::where('template_id', '=', $template->id)->where('user_id', $owner->id)->first();
            $slug = Store::where('created_by', $template->created_by)->select('slug')->first()->slug;

            $setting = getAdminAllSetting($owner->id);
            if ($is_actives->is_active == 1) {
                $EmailTemplateLang = EmailTemplateLang::where('parent_id', '=', $template->id)->where('is_default', 1)->first();
                $prefer_lang = ($owner->language == $EmailTemplateLang->language) ? $owner->language : $EmailTemplateLang->language;

                $content = EmailTemplateLang::where('parent_id', '=', $template->id)->where('language', 'LIKE', $prefer_lang)->first();

                $content->from = $template->from;

                if (!empty($content->content)) {

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

                        $data["email"] = $mailTo;
                        $data["title"] = "Product Catelog for Category";
                        $data["body"] = "";
                        $data["slug"] = $slug ?? '';
                        $data["obj"] = $obj;

                        $pdfOutput = $pdf = \PDF::loadView('product-catelog::product-catelog.send_email', $data);
                        $content->content = self::replaceContent($content->content, $obj, $store, $pdfOutput->output(), 'product_catelog.pdf');

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
                        'error' => __('Mail not send, email template is empty'),
                    ];
                }
                return $arReturn;
            } else {
                return [
                    'is_success' => false,
                    'error' => __('Please enable your mail template.'),
                ];
            }
        } else {
            return [
                'is_success' => false,
                'error' => __('Mail not send, email template not found'),
            ];
        }
    }

    public function replaceContent($content, $obj, $store, $pdfContent, $pdfFileName)
    {
        $arrVariable = [
            '{app_name}',
            '{app_url}',
            '{pdf_view}',
        ];

        $arrValue = [
            'app_name' => '-',
            'app_url' => '-',
            'pdf_view' => '-',
        ];

        foreach ($obj as $key => $val) {
            $arrValue[$key] = $val;
        }
        $arrValue['app_name'] = $store->name;
        $arrValue['app_url'] = '<a href="' . env('APP_URL') . '" target="_blank">' . env('APP_URL') . '</a>';

        $ownername = User::where('id', $store->created_by)->first();
        $pdfContentBase64 = base64_encode($pdfContent);
        $pdfContentEncoded = 'data:application/pdf;base64,' . $pdfContentBase64;

        $arrValue['pdf_view'] = '
            <a href="' . $pdfContentEncoded . '" download="' . $pdfFileName . '">
            <img src="' . asset('packages/workdo/ProductCatelog/src/resources/assets/image/pdf.png') . '" alt="Image" style="max-width: 20%;">
            </a>';
        return str_replace($arrVariable, array_values($arrValue), $content);
    }
}
