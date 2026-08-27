<?php

namespace Workdo\AutomaticOrderPrinting\app\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Utility;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\AutomaticOrderPrinting\app\Models\AutomaticOrderPrinting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cache;

class AutomaticOrderPrintingController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index()
    {
        if (auth()->user()->isAbleTo('Manage Print Setting')) {
            $products = AutomaticOrderPrinting::where('store_id', getCurrentStore())->get();
            $templateData = AutomaticOrderPrinting::templateData();
            return view('automatic-order-printing::index', compact('products'), ['templateData' => $templateData]);
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
        return view('automatic-order-printing::create');
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
        return view('automatic-order-printing::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('automatic-order-printing::edit');
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

    public function invoice_preview($template, $color)
    {
        $settings = Utility::Setting();
        $print_settings = AutomaticOrderPrinting::getPrintSettings($user_id = null, $store_id = null);

        if ($template) {
            $print_settings['invoice_template'] = $template;
        }
        if ($color) {
            $print_settings['invoice_color'] = $color;
        }


        $order = new Order();
        $user = Auth::user();

        $customerdetails = [
            ucfirst('Client'),
            '+216 654654',
            'Hankenshire',
            'New York' . 'New York',
            'USA',
            '999999',
        ];

        $items = [];
        for ($i = 1; $i <= 3; $i++) {
            $item = new \stdClass();
            $item->name = 'Item ' . $i;
            $item->qty = 2;
            $item->final_price = '$200.00';
            $items[] = $item;
        }

        $color = '#' . ltrim($print_settings['invoice_color'] ?? '003580', '#');
        $font_color = AutomaticOrderPrinting::getFontColor($color);

        $subtotal = '$300.00';
        $totalquantity = 6;
        $totaltax = 0;
        $product_sum = 600;
        $p_total = [200, 200, 200];

        $templateMap = [
            "template1" => "New York",
            "template2" => "Toronto",
            "template3" => "Rio",
            "template4" => "London",
            "template5" => "Istanbul",
            "template6" => "Mumbai",
            "template7" => "Hong Kong",
            "template8" => "Tokyo",
            "template9" => "Sydney",
            "template10" => "Paris",
        ];

        $templateKey = $print_settings['invoice_template'] ?? 'template1';

        if (!isset($templateMap[$templateKey])) {
            abort(404, __('Template not found.'));
        }

        return view('automatic-order-printing::template.' . $templateKey, compact(
            'order',
            'items',
            'settings',
            'print_settings',
            'color',
            'font_color',
            'customerdetails',
            'subtotal',
            'totalquantity',
            'totaltax',
            'product_sum',
            'p_total'
        ))->with('is_Invoice', false);
    }

    public function printSettings(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Print Setting')) {
            session()->put(['setting_tab' => 'auto_print_setting']);
            $themeId = APP_THEME() ?: '';
            $user = Auth::user();
            $dir = 'uploads/' .getCurrentStore();

            $request->validate([
                'print_setting_enabled' => 'required|string',
                'footer' => 'nullable|string',
                'footer_notes' => 'nullable|string',
                'billig_display' => 'nullable|string',
                'order_qr_display' => 'nullable|string',
                'invoice_template' => 'nullable|string',
                'invoice_color' => 'nullable|string',
            ]);

            $post = $request->only([
                'print_setting_enabled',
                'footer',
                'footer_notes',
                'billig_display',
                'order_qr_display',
                'invoice_template',
                'invoice_color',
            ]);

            if (!isset($post['order_qr_display'])) {
                $post['order_qr_display'] = 'off';
            };
            try {
                foreach ($post as $key => $value) {

                    AutomaticOrderPrinting::updateOrCreate(
                        [
                            'name' => $key,
                            'store_id' => getCurrentStore()
                        ],
                        [
                            'value' => $value,
                            'created_by' => $user->id,
                        ]
                    );
                }

                return redirect()->back()->with('success', __('Print setting successfully updated.'));
            } catch (\Exception $e) {
                return redirect()->back()->with('error', __('Something went wrong: ') . $e->getMessage());
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function shippinglabel(Request $request, $id = null, $template = null, $color = null)
    {
        try {
            $id = Crypt::decrypt($id);
            $order = Order::order_detail($id);
            $settings = Utility::Setting();
            $print_settings = AutomaticOrderPrinting::getPrintSettings($user_id = null, $store_id = null);
            $product = [];
            $products = [];
            foreach ($order['product'] as $product_item) {
                if ($product_item['variant_id'] == '0') {
                    $product[] = Product::where('id', $product_item)->pluck('product_weight', 'id')->first();
                } else {
                    $products[] = ProductVariant::where('id', $product_item['variant_id'])->pluck('weight', 'id')->first();
                }
            }
            $newArray = [];
            foreach ($product as $key => $value) {
                $newArray[$key] = intval($value);
            }
            $product = array_merge($newArray, $products);
            $product_sum = array_sum($product);

            $subtotal = 0;
            foreach ($order['product'] as $product_item) {
                $subtotal += $product_item['orignal_price'] * ($product_item['qty'] ?? $product_item['quantity']);
            }
            $items = [];
            foreach ($order['product'] as $product_item) {
                $item = (object) $product_item;
                $items[] = $item;
            }

            $totalquantity = 0;
            foreach ($order['product'] as $product_item) {
                $totalquantity = ($product_item['qty'] ?? $product_item['quantity']);
            }

            $customerdetails = $order['delivery_informations'];

            if (!empty($order['message'])) {
                return redirect()->back()->with('error', __('Order Not Found.'));
            }

            $defaultTemplate = 'template1';
            $color = '#' . ltrim($print_settings['invoice_color'] ?? '003580', '#');
            $font_color = AutomaticOrderPrinting::getFontColor($color);

            $templateMap = [
                "template1" => "New York",
                "template2" => "Toronto",
                "template3" => "Rio",
                "template4" => "London",
                "template5" => "Istanbul",
                "template6" => "Mumbai",
                "template7" => "Hong Kong",
                "template8" => "Tokyo",
                "template9" => "Sydney",
                "template10" => "Paris",
            ];
            $temp_key = $print_settings['invoice_template'] ?? 'template1';
            $templateKey = $temp_key;

            if (!$templateKey) {
                abort(404, __('Template not found.'));
            }

            return view('automatic-order-printing::template.' . $templateKey, compact(
                'order',
                'product_sum',
                'settings',
                'print_settings',
                'color',
                'font_color',
                'customerdetails',
                'subtotal',
                'totalquantity',
                'items'
            ))->with('is_Invoice', true);
        } catch (DecryptException $e) {
            return redirect()->back()->with('error', __('Something was wrong.'));
        }
    }

    public function printinglabel(Request $request, $id = null, $template = null, $color = null)
    {
        try {
            $id = Crypt::decrypt($id);
            $order = Order::order_detail($id);
            $settings = Utility::Setting();
            $print_settings = AutomaticOrderPrinting::getPrintSettings($user_id = null, $store_id = null);
            $product = [];
            $products = [];
            foreach ($order['product'] as $product_item) {
                if ($product_item['variant_id'] == '0') {
                    $product[] = Product::where('id', $product_item)->pluck('product_weight', 'id')->first();
                } else {
                    $products[] = ProductVariant::where('id', $product_item['variant_id'])->pluck('weight', 'id')->first();
                }
            }
            $newArray = [];
            foreach ($product as $key => $value) {
                $newArray[$key] = intval($value);
            }
            $product = array_merge($newArray, $products);
            $product_sum = array_sum($product);

            $subtotal = 0;
            foreach ($order['product'] as $product_item) {
                $subtotal += $product_item['orignal_price'] * ($product_item['qty'] ?? $product_item['quantity']);
            }
            $items = [];
            foreach ($order['product'] as $product_item) {
                $item = (object) $product_item;
                $items[] = $item;
            }

            $totalquantity = 0;
            foreach ($order['product'] as $product_item) {
                $totalquantity = ($product_item['qty'] ?? $product_item['quantity']);
            }

            $customerdetails = $order['delivery_informations'];

            if (!empty($order['message'])) {
                return redirect()->back()->with('error', __('Order Not Found.'));
            }

            $defaultTemplate = 'template1';
            $color = '#' . ltrim($print_settings['invoice_color'] ?? '003580', '#');
            $font_color = AutomaticOrderPrinting::getFontColor($color);

            $templateMap = [
                "template1" => "New York",
                "template2" => "Toronto",
                "template3" => "Rio",
                "template4" => "London",
                "template5" => "Istanbul",
                "template6" => "Mumbai",
                "template7" => "Hong Kong",
                "template8" => "Tokyo",
                "template9" => "Sydney",
                "template10" => "Paris",
            ];
            $temp_key = $print_settings['invoice_template'] ?? 'template1';
            $templateKey = $temp_key;

            if (!$templateKey) {
                abort(404, __('Template not found.'));
            }

            return view('automatic-order-printing::template.' . $templateKey, compact(
                'order',
                'product_sum',
                'settings',
                'print_settings',
                'color',
                'font_color',
                'customerdetails',
                'subtotal',
                'totalquantity',
                'items'
            ))->with('is_Invoice', true);
        } catch (DecryptException $e) {
            return redirect()->back()->with('error', __('Something was wrong.'));
        }
    }

    public function settingForm(Request $request)
    {
        try {
            $store = Store::find(getCurrentStore());
            $slug = $store->slug;
            $settings = AutomaticOrderPrinting::getPrintSettings($store->created_by, $store->id);
            $templateData = AutomaticOrderPrinting::templateData();
            $html = view('automatic-order-printing::pages.tab_form', compact('settings', 'slug', 'templateData'))->render();

            return response()->json([
                'is_success' => true,
                "msg" => __("Automatic order print form get successfully."),
                "data" => ['content' => $html]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'is_success' => false,
                "msg" => __('Something went wrong!'),
                "data" => ['content' => null]
            ]);
        }
    }
}
