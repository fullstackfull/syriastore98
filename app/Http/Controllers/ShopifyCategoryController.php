<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ShopifyConection;
use App\Models\Utility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ShopifyCategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Shopify Category')) {
            $setting = getAdminAllsetting();
            if(isset($setting['shopify_setting_enabled']) && $setting['shopify_setting_enabled'] == 'on')
            {
                try {
                    $shopify_store_url = \App\Models\Utility::GetValueByName('shopify_store_url', getCurrentStore());
                    $shopify_access_token = \App\Models\Utility::GetValueByName('shopify_access_token', getCurrentStore());
                   
                    $curl = curl_init();
                    curl_setopt_array($curl, array(
                        CURLOPT_URL => "https://$shopify_store_url.myshopify.com/admin/api/2023-07/custom_collections.json",
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_ENCODING => '',
                        CURLOPT_MAXREDIRS => 10,
                        CURLOPT_TIMEOUT => 0,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                        CURLOPT_CUSTOMREQUEST => 'GET',
                        CURLOPT_HTTPHEADER => array(
                            "X-Shopify-Access-Token: $shopify_access_token"
                        ),
                    ));

                    $response = curl_exec($curl);
                    curl_close($curl);
                    if ($response == false) {
                        return redirect()->back()->with('error', 'Something went wrong.');
                    } else {
                        $category = json_decode($response, true);
                        if (isset($category['errors'])) {
                            $errorMessage = $category['errors'];
                            return redirect()->back()->with('error', $errorMessage);
                        } else {
                            if (isset($category) && !empty($category)) {
                                $upddata = ShopifyConection::where('store_id',getCurrentStore())->where('module','=','category')->pluck('shopify_id')->toArray();
                                return  view('shopify.category', compact('category', 'upddata'));
                            }
                        }
                    }
                } catch (\Exception $e) {
                    return redirect()->back()->with('error', 'Something went wrong.');
                }
            } else {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        
        if (auth()->user() && auth()->user()->isAbleTo('Create Shopify Category')) {
            try {
                $shopify_store_url = \App\Models\Utility::GetValueByName('shopify_store_url', getCurrentStore());
                $shopify_access_token = \App\Models\Utility::GetValueByName('shopify_access_token', getCurrentStore());
                
                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_URL => "https://$shopify_store_url.myshopify.com/admin/api/2023-07/custom_collections.json?ids=$id",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'GET',
                    CURLOPT_HTTPHEADER => array(
                        "X-Shopify-Access-Token: $shopify_access_token"
                    ),
                ));

                $response = curl_exec($curl);
                curl_close($curl);
                if ($response == false) {
                    return redirect()->back()->with('error', 'Something went wrong.');
                } else {
                    $category = json_decode($response, true);

                    if (isset($category['errors'])) {

                        $errorMessage = $category['errors'];
                        return redirect()->back()->with('error', $errorMessage);
                    } else {
                        if (isset($category) && !empty($category)) {
                            if (!empty($category['custom_collections'][0]['image']['src'])) {

                                $ImageUrl = $category['custom_collections'][0]['image']['src'];
                                $file_type = config('files_types');
                                $url = strtok($ImageUrl, '?');

                                foreach ($file_type as $f) {

                                    $name = basename($url, "." . $f);
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
                                $file2 = rand(10, 100) . '_' . time() . "_" . $name;
                                $path = 'uploads/' .getCurrentStore();
                                $uplaod = Utility::upload_woo_file($url, $file2, $path);
                            } else {
                                $url  = asset(Storage::url('uploads/shopify.png'));
                                $name = 'shopify.png';
                                $file2 = rand(10, 100) . '_' . time() . "_" . $name;
                                $path = 'uploads/' .getCurrentStore();
                                $uplaod = Utility::upload_woo_file($url, $file2, $path);
                            }
                            if (!empty($category)) {
                                $data               = new Category();
                                $data->name         = $category['custom_collections'][0]['title'];
                                $data->slug         = 'collections/' . strtolower(preg_replace("/[^\w]+/", "-", $category['custom_collections'][0]['title']));
                                $data->image_url    = $uplaod['full_url'] ?? null;
                                $data->image_path   = $uplaod['url'] ?? null;
                                $data->icon_path    = $uplaod['url'] ?? null;
                                $data->trending     = 0;
                                $data->status       = 1;
                                $data->store_id     = getCurrentStore();
                                $data->save();

                                $connection              = new ShopifyConection();
                                $connection->store_id    = getCurrentStore();
                                $connection->module      = 'category';
                                $connection->shopify_id  = $category['custom_collections'][0]['id'];
                                $connection->original_id = $data->id;

                                $connection->save();

                                return redirect()->back()->with('success', __('Category successfully add.'));
                            } else {
                                return redirect()->back()->with('error', __('Category Not Found.'));
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                return redirect()->back()->with('error', __('Something went wrong.'));
            }
        } else {
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
        
        if (auth()->user() && auth()->user()->isAbleTo('Edit Shopify Category')) {
            try {
                $shopify_store_url = \App\Models\Utility::GetValueByName('shopify_store_url', getCurrentStore());
                $shopify_access_token = \App\Models\Utility::GetValueByName('shopify_access_token', getCurrentStore());
               
                $curl = curl_init();
                curl_setopt_array($curl, array(
                    CURLOPT_URL => "https://$shopify_store_url.myshopify.com/admin/api/2023-07/custom_collections.json?ids=$id",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'GET',
                    CURLOPT_HTTPHEADER => array(
                        "X-Shopify-Access-Token: $shopify_access_token"
                    ),
                ));

                $response = curl_exec($curl);
                curl_close($curl);
                if ($response == false) {
                    return redirect()->back()->with('error', 'Something went wrong.');
                } else {
                    $category = json_decode($response, true);

                    if (isset($category['errors'])) {

                        $errorMessage = $category['errors'];
                        return redirect()->back()->with('error', $errorMessage);
                    } else {
                        $upddata = ShopifyConection::where('store_id', getCurrentStore())->where('module', '=', 'category')->where('shopify_id', $id)->first();
                        $original_id = $upddata->original_id;

                        if (!empty($category['custom_collections'][0]['image']['src'])) {

                            $ImageUrl = $category['custom_collections'][0]['image']['src'];
                            $file_type = config('files_types');
                            $url = strtok($ImageUrl, '?');

                            foreach ($file_type as $f) {

                                $name = basename($url, "." . $f);
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
                            $file2 = rand(10, 100) . '_' . time() . "_" . $name;
                            $path = 'uploads/' . getCurrentStore();
                            $uplaod = Utility::upload_woo_file($url, $file2, $path);
                        } else {
                            $url  = asset(Storage::url('uploads/shopify.png'));
                            $name = 'shopify.png';
                            $file2 = rand(10, 100) . '_' . time() . "_" . $name;
                            $path = 'uploads/' . getCurrentStore();
                            $uplaod = Utility::upload_woo_file($url, $file2, $path);
                        }
                        if (!empty($category)) {
                            $exist               = Category::find($original_id);
                            if ($exist) {
                                $exist->name         = $category['custom_collections'][0]['title'];
                                $exist->slug         = 'collections/' . strtolower(preg_replace("/[^\w]+/", "-", $category['custom_collections'][0]['title']));
                                $exist->image_url    = $uplaod['full_url'] ?? null;
                                $exist->image_path   = $uplaod['url'] ?? null;
                                $exist->icon_path    = $uplaod['url'] ?? null;
                                $exist->trending     = 0;
                                $exist->status       = 1;
                                $exist->save();
                            } else {
                                $data               = new Category();
                                $data->name         = $category['custom_collections'][0]['title'];
                                $data->slug         = 'collections/' . strtolower(preg_replace("/[^\w]+/", "-", $category['custom_collections'][0]['title']));
                                $data->image_url    = $uplaod['full_url'] ?? null;
                                $data->image_path   = $uplaod['url'] ?? null;
                                $data->icon_path    = $uplaod['url'] ?? null;
                                $data->trending     = 0;
                                $data->status       = 1;
                                $data->store_id     = getCurrentStore();
                                $data->save();

                                $connection                   = new ShopifyConection();
                                $connection->store_id         = getCurrentStore();
                                $connection->module           = 'category';
                                $connection->shopify_id       = $category['custom_collections'][0]['id'];
                                $connection->original_id  = $data->id;

                                $connection->save();
                            }

                            return redirect()->back()->with('success', __('Category successfully update.'));
                        } else {
                            return redirect()->back()->with('error', __('Category Not Found.'));
                        }
                    }
                }
            } catch (\Exception $e) {
                return redirect()->back()->with('error', __('Something went wrong.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
