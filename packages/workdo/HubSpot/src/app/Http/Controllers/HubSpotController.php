<?php

namespace Workdo\HubSpot\app\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductBrand;
use App\Models\ProductLabel;
use App\Models\Shipping;
use App\Models\Tag;
use App\Models\Tax;
use GuzzleHttp\Client;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use App\Models\Setting;
use Illuminate\Pagination\LengthAwarePaginator;
use Workdo\HubSpot\app\Models\HubSpotProductService;
use Illuminate\Support\Facades\Http;


class HubSpotController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function setting(Request $request)
    {
        // if (Auth::user()->isAbleTo('hubspot manage')) {
            if ($request->has('hubspot_is_enable')) {
                $validator = Validator::make(
                    $request->all(),
                    [
                        'hubspot_access_token' => 'required',
                    ]
                );
                if ($validator->fails()) {
                    $messages = $validator->getMessageBag();
                    return redirect()->back()->with('error', $messages->first());
                }
            }

            $post['hubspot_access_token'] = $request->hubspot_access_token;
            $post['hubspot_is_enable'] = $request->hubspot_is_enable;

            $settingQuery = Setting::query();
            foreach ($post as $key => $data) {
                $status = (clone $settingQuery)->updateOrCreate(
                    [
                        'name' => $key,
                        'store_id' => getCurrentStore()
                    ],
                    [
                        'value' => $data,
                        'name' => $key,
                        'store_id' => getCurrentStore(),
                        'created_by' => auth()->user()->id,
                    ]
                );
            }

            if (isset($status)) {
                return redirect()->back()->with('success', __('HubSpot settings updated successfully.'));
            } else {
                return redirect()->back()->with('error', __('Something is wrong'));
            }
        // } else {
        //     return redirect()->back()->with('error', __('Permission denied.'));
        // }
    }

    public function settingForm(Request $request)
    {
        try {
            $setting = getAdminAllSetting(auth()->user()->id, getCurrentStore());
            $html = view('hub-spot::pages.tab_form', compact('setting'))->render();

            // Return JSON response
            return response()->json([
                'is_success' => true,
                "msg" => __("HubSpot product form get successfully."),
                "data" => ['content' => $html]
            ]);
        } catch (\Exception $e) {
            // Return JSON response
            return response()->json([
                'is_success' => false,
                "msg" => __('Something went wrong!'),
                "data" => ['content' => null]
            ]);
        }
    }

    public function index($module)
    {
        $setting = getAdminAllSetting(auth()->user()->id, getCurrentStore());
        if (isset($setting['hubspot_is_enable']) && $setting['hubspot_is_enable'] == 'on') {
            if (!empty($setting['hubspot_access_token'])) {

                if (($module == 'product' || $module == 'products-list') && (Auth::user()->isAbleTo('hubspot product sync'))) {
                    return redirect()->route('hubspot.product');
                } else {
                    return redirect()->back()->with('error', __('Permission Denied.'));
                }
            } else {
                return redirect()->back()->with('error', __('Please Set Access Token.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    public function product()
    {
        // if (Auth::user()->isAbleTo('hubspot product sync')) {
            $setting = getAdminAllSetting(auth()->user()->id, getCurrentStore());
            try {
                $client = new Client();
                $response = $client->request('GET', 'https://api.hubapi.com/crm/v3/objects/products?properties=hs_sku&properties=name&properties=description&properties=hs_cost_of_goods_sold&properties=price&properties=hs_product_type&properties=hs_images', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $setting['hubspot_access_token'],
                    ],
                ]);
                $statusCode = $response->getStatusCode();
                $body = $response->getBody();
                $response_data = json_decode($body);

                if ($statusCode == 200) {
                    $productServices = collect($response_data->results);

                    foreach ($productServices as $productService) {
                        $hubspotProduct = HubSpotProductService::where('hubspot_product_service_id', $productService->id)
                            ->where('store_id', getCurrentStore())
                            ->first();

                        $productService->status = 0; // Default to not synchronized
                        $productService->has_changes = false; // Default to no changes

                        if ($hubspotProduct) {
                            $product = Product::where('id', $hubspotProduct->product_service_id)->first();

                            if ($product) {
                                $productService->status = 1; // Synchronized

                                // Check for changes
                                $hasChanges = $product->name !== $productService->properties->name ||
                                    $product->price != $productService->properties->price ||
                                    $product->sale_price != $productService->properties->hs_cost_of_goods_sold ||
                                    $product->description !== $productService->properties->description;

                                $productService->has_changes = $hasChanges;
                            }
                        }
                    }

                    $productServicesCollection = collect($productServices);


                    $currentPage = LengthAwarePaginator::resolveCurrentPage();
                    $perPage = 10;
                    $currentPageItems = $productServicesCollection->slice(($currentPage - 1) * $perPage, $perPage)->values();

                    $productServices = new LengthAwarePaginator(
                        $currentPageItems,
                        $productServicesCollection->count(),
                        $perPage,
                        $currentPage,
                        ['path' => LengthAwarePaginator::resolveCurrentPath()]
                    );

                    return view('hub-spot::product.index', compact('productServices'));
                } else {
                    if (isset($response_data->status) && $response_data->status == 'error') {
                        return redirect()->back()->with('error', $response_data->message);
                    }
                }
            } catch (\Throwable $th) {
                return redirect()->back()->with('error', __('Something went wrong.'));
            }
        // } else {
        //     return redirect()->back()->with('error', __('Permission denied.'));
        // }
    }

    public function create(Request $request)
    {
        $product_services = json_decode($request->productData, true);
        $product_service = $product_services['properties'];

        $main_category = Category::where('store_id', getCurrentStore())->pluck('name', 'id')->toArray();
        $Tax = Tax::where('store_id', getCurrentStore())->pluck('name', 'id');
        $brands = ProductBrand::where('status', 1)->where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Brand', '');
        $labels = ProductLabel::where('status', 1)->where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Label', '');
        $Tax_status = Tax::Taxstatus();
        $Shipping = Shipping::where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Shipping', '');
        $tag = Tag::where('store_id', getCurrentStore())->pluck('name', 'id');
        $theme_name = !empty(APP_THEME()) ? APP_THEME() : env('DATA_INSERT_APP_THEME');
        $stock_management = \App\Models\Utility::GetValueByName('stock_management', $theme_name);
        $low_stock_threshold = \App\Models\Utility::GetValueByName('low_stock_threshold', $theme_name);
        $ProductAttribute = ProductAttribute::where('store_id', getCurrentStore())->pluck('name', 'id');

        $preview_type = [
            'Video File' => 'Video File',
            'Video Url' => 'Video Url',
            'iFrame' => 'iFrame'
        ];
        $compact = ['product_service', 'product_services', 'main_category', 'Tax', 'brands', 'labels', 'Tax_status', 'Shipping', 'tag', 'preview_type', 'stock_management', 'theme_name', 'low_stock_threshold', 'ProductAttribute'];
        return view('hub-spot::product.create', compact($compact));
    }

    public function syncProduct($productId)
    {
        $setting = getAdminAllSetting(auth()->user()->id, getCurrentStore());

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $setting['hubspot_access_token'],
            ])->get('https://api.hubapi.com/crm/v3/objects/products/' . $productId, [
                        'properties' => 'hs_sku,name,description,hs_cost_of_goods_sold,price,hs_product_type,hs_images',
                    ]);

            if ($response->successful()) {
                $hubspotProduct = $response->json();

                $dbProduct = HubSpotProductService::where('hubspot_product_service_id', $hubspotProduct['id'])
                    ->where('store_id', getCurrentStore())
                    
                    ->first();

                if ($dbProduct) {
                    $productService = Product::where('id', $dbProduct->product_service_id)->first();

                    if ($productService) {
                        $changes = [];
                        if ($productService->name !== $hubspotProduct['properties']['name']) {
                            $changes['name'] = $hubspotProduct['properties']['name'];
                        }
                        if ($productService->price != $hubspotProduct['properties']['price']) {
                            $changes['price'] = $hubspotProduct['properties']['price'];
                        }
                        if ($productService->sale_price != $hubspotProduct['properties']['hs_cost_of_goods_sold']) {
                            $changes['sale_price'] = $hubspotProduct['properties']['hs_cost_of_goods_sold'];
                        }
                        if ($productService->description !== $hubspotProduct['properties']['description']) {
                            $changes['description'] = $hubspotProduct['properties']['description'];
                        }

                        // Update the database if changes exist
                        if (!empty($changes)) {
                            $productService->update($changes);
                        }
                    }
                }

                return redirect()->back()->with('success', __('HubSpot data synced successfully.'));
            }

            return redirect()->back()->with('error', __('Failed to fetch data from HubSpot.'));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('An error occurred: ') . $e->getMessage());
        }
    }

}

