<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Utility;
use App\Models\WoocommerceConection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Codexshaper\WooCommerce\Facades\Category as WooCategory;
use Yajra\DataTables\Facades\DataTables;

class WoocomCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Woocommerce Category'))
        {
            $setting = getAdminAllsetting();
            if(isset($setting['woocommerce_setting_enabled']) && $setting['woocommerce_setting_enabled'] == 'on')
            {
                try{
                    $woocommerce_store_url =Utility::GetValueByName('woocommerce_store_url',  getCurrentStore());
                    $woocommerce_consumer_secret =Utility::GetValueByName('woocommerce_consumer_secret', getCurrentStore());
                    $woocommerce_consumer_key =Utility::GetValueByName('woocommerce_consumer_key', getCurrentStore());

                    config(['woocommerce.store_url' => $woocommerce_store_url]);
                    config(['woocommerce.consumer_key' => $woocommerce_consumer_key]);
                    config(['woocommerce.consumer_secret' => $woocommerce_consumer_secret]);

                    // Fetch all products using pagination
                    $jsonData = collect(); // Initialize an empty collection
                    $page = 1;
                    $perPage = 100;
                    do {
                        // Fetch products from WooCommerce API, with pagination
                        $categories = WooCategory::all(['per_page' => $perPage, 'page' => $page]);
                        // $categories = $categories->filter(function ($category) {
                        //     return $category->parent == 0;
                        // });
                        $jsonData = $jsonData->merge($categories); // Append the new page data to the collection
                        $page++;
                    } while (count($categories) > 0); // Continue fetching until no products are returned

                    // Check if the request is for datatable
                    if (request()->ajax()) {                       
                        $upddata = WoocommerceConection::where('store_id',getCurrentStore())->where('module','=','category')->get()->pluck('woocomerce_id')->toArray();
                        return DataTables::of($jsonData)
                            ->addColumn('cover_image', function ($data) {
                                $imgSrc = !empty($data->image) ? get_file($data->image->src) : asset(Storage::url('uploads/woocommerce.png'));
                                return '<img src="' . $imgSrc . '" alt="" width="100" class="cover_img">';
                            })
                            ->addColumn('action', function ($data) use ($upddata) {
                                return view('woocommerce.category_action', compact('data','upddata'));
                            })
                            ->rawColumns(['cover_image','name','action'])
                            ->make(true);
                    }
                   
                   
                    return view('woocommerce.category');

                }
                catch(\Exception $e){
                    return redirect()->back()->with('error' , __('Sometht ing wenwrong.'));
                }
            }else{
                return redirect()->back()->with('error', value: __('Permission denied.'));
            }
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $woocommerce_store_url =Utility::GetValueByName('woocommerce_store_url', getCurrentStore());
        $response = \Http::get($woocommerce_store_url . '/wp-json/wc/v3/products/categories');
        $jsonData = $response->json();
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request ,$id)
    {
         
        if (auth()->user() && auth()->user()->isAbleTo('Create Woocommerce Category'))
        {
            $woocommerce_store_url =Utility::GetValueByName('woocommerce_store_url', getCurrentStore());
            $woocommerce_consumer_secret =Utility::GetValueByName('woocommerce_consumer_secret', getCurrentStore());
            $woocommerce_consumer_key =Utility::GetValueByName('woocommerce_consumer_key', getCurrentStore());

            config(['woocommerce.store_url' => $woocommerce_store_url]);
            config(['woocommerce.consumer_key' => $woocommerce_consumer_key]);
            config(['woocommerce.consumer_secret' => $woocommerce_consumer_secret]);

            $jsonData = WooCategory::find($id);
            
            if(!empty($jsonData['image']->src)) {

                $url = $jsonData['image']->src;
                $file_type = config('files_types');

                foreach($file_type as $f){
                    $name = basename($url, ".".$f);
                }
                $file_size = 0;
                try{
                    $file_url = str_replace("\0", '', $url);
                    $get_file = \Illuminate\Support\Facades\Http::head($file_url);
                    $file_size = $get_file->header('Content-Length');
                } catch(\Exception $e)
                {
                    return redirect()->back()->with('error', $e);
                }
                $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $file_size);
                if ($result != 1) {
                    return redirect()->back()->with('error', $result);
                }
                $file2 = rand(10,100).'_'.time() . "_" . $name;
                $path = 'uploads/' . getCurrentStore();
                $uplaod =Utility::upload_woo_file($url,$file2,$path);
            }
            else{
                $url  = asset(Storage::url('uploads/woocommerce.png'));
                $name = 'woocommerce.png';
                $file2 = rand(10,100).'_'.time() . "_" . $name;
                $path = 'uploads/' . getCurrentStore();
                $uplaod =Utility::upload_woo_file($url,$file2,$path);


            }
            if (!empty($jsonData)) {

            $data               = new Category();
            $data->name         = $jsonData['name'];
            $data->slug         = 'collections/' . strtolower(preg_replace("/[^\w]+/", "-", $jsonData['name']));
            $data->image_url    = $uplaod['full_url'];
            $data->image_path   = $uplaod['url'];
            $data->icon_path    = $uplaod['url'];
            $data->trending     = 0;
            $data->status       = 1;
            $data->store_id     = getCurrentStore();
            $data->save();

            $connection                = new WoocommerceConection();
            $connection->store_id      = getCurrentStore();
            $connection->module        = 'category';
            $connection->woocomerce_id = $jsonData['id'];
            $connection->original_id   = $data->id;
            $connection->save();

            return redirect()->back()->with('success', __('Category successfully created.'));
            } else {
                return redirect()->back()->with('error', __('Category Not Found.'));
            }
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
         
        if (auth()->user() && auth()->user()->isAbleTo('Edit Woocommerce Category'))
        {
            $woocommerce_store_url =Utility::GetValueByName('woocommerce_store_url', getCurrentStore());
            $woocommerce_consumer_secret =Utility::GetValueByName('woocommerce_consumer_secret', getCurrentStore());
            $woocommerce_consumer_key =Utility::GetValueByName('woocommerce_consumer_key', getCurrentStore());

            config(['woocommerce.store_url' => $woocommerce_store_url]);
            config(['woocommerce.consumer_key' => $woocommerce_consumer_key]);
            config(['woocommerce.consumer_secret' => $woocommerce_consumer_secret]);

            $jsonData = WooCategory::find($id);
            
            $store_id = getStoreById(getCurrentStore());
            $upddata = WoocommerceConection::where('store_id',getCurrentStore())->where('module','=','category')->where('woocomerce_id' , $id)->first();
            $original_id = $upddata->original_id;
            $mainCategory = Category::find($original_id);

            if(!empty($jsonData['image']->src)) {

                $url = $jsonData['image']->src;
                $file_type = config('files_types');

                foreach($file_type as $f){
                    $name = basename($url, ".".$f);
                }
                $file_size = 0;
                try{
                    $file_url = str_replace("\0", '', $url);
                    $get_file = \Illuminate\Support\Facades\Http::head($file_url);
                    $file_size = $get_file->header('Content-Length');
                } catch(\Exception $e)
                {
                    return redirect()->back()->with('error', $e);
                }
                $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $file_size);
                if ($result != 1) {
                    return redirect()->back()->with('error', $result);
                }
                $file2 = rand(10,100).'_'.time() . "_" . $name;
                $path = 'uploads/' . getCurrentStore();
                $uplaod =Utility::upload_woo_file($url,$file2,$path);
            }
            else{
                $url  = asset(Storage::url('uploads/woocommerce.png'));
                $name = 'woocommerce.png';
                $file2 = rand(10,100).'_'.time() . "_" . $name;
                $path = 'uploads/' . getCurrentStore();
                $uplaod =Utility::upload_woo_file($url,$file2,$path);

            }

            if (!empty($jsonData)) {
                $mainCategory = new Category();
                $mainCategory->name         = $jsonData['name'];
                $mainCategory->slug         = 'collections/' . strtolower(preg_replace("/[^\w]+/", "-", $jsonData['name']));
                $mainCategory->image_url    = $uplaod['full_url'];
                $mainCategory->image_path   = $uplaod['url'];
                $mainCategory->icon_path    = $uplaod['url'];
                $mainCategory->trending     = 0;
                $mainCategory->status       = 1;
                $mainCategory->store_id     = getCurrentStore();
                $mainCategory->save();
                return redirect()->back()->with('success', __('Category update successfully.'));
            } else {
                return redirect()->back()->with('error', __('Category Not Found.'));
            }

        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
}
