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
                    $shopify_store_url = \App\Services\ImportStoreUrl::shopifyLabel(\App\Models\Utility::GetValueByName('shopify_store_url', getCurrentStore()));
                    $shopify_access_token = \App\Models\Utility::GetValueByName('shopify_access_token', getCurrentStore());
                   
                    $response = \App\Services\ShopifyAdminApi::get($shopify_store_url, "admin/api/2023-07/custom_collections.json", $shopify_access_token);
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
                $shopify_store_url = \App\Services\ImportStoreUrl::shopifyLabel(\App\Models\Utility::GetValueByName('shopify_store_url', getCurrentStore()));
                $shopify_access_token = \App\Models\Utility::GetValueByName('shopify_access_token', getCurrentStore());
                
                $response = \App\Services\ShopifyAdminApi::get($shopify_store_url, "admin/api/2023-07/custom_collections.json?ids=$id", $shopify_access_token);
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
                                    $get_file = \App\Services\SafeRemoteFetch::head($file_url);
                                    $file_size = $get_file->header('Content-Length');
                                } catch(\Exception $e)
                                {
                                    return redirect()->back()->with('error', $e->getMessage());
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
                $shopify_store_url = \App\Services\ImportStoreUrl::shopifyLabel(\App\Models\Utility::GetValueByName('shopify_store_url', getCurrentStore()));
                $shopify_access_token = \App\Models\Utility::GetValueByName('shopify_access_token', getCurrentStore());
               
                $response = \App\Services\ShopifyAdminApi::get($shopify_store_url, "admin/api/2023-07/custom_collections.json?ids=$id", $shopify_access_token);
                if ($response == false) {
                    return redirect()->back()->with('error', 'Something went wrong.');
                } else {
                    $category = json_decode($response, true);

                    if (isset($category['errors'])) {

                        $errorMessage = $category['errors'];
                        return redirect()->back()->with('error', $errorMessage);
                    } else {
                        $upddata = ShopifyConection::where('store_id', getCurrentStore())->where('module', '=', 'category')->where('shopify_id', $id)->first();

                        /*
                         * P1-INV-13 / DATA-001. This read `$upddata->original_id` on a mapping that
                         * `->first()` can return as NULL, then located the row with a bare
                         * `Category::find($original_id)` and overwrote its name, slug and three image
                         * paths. `Category` is in `config/tenancy.php`'s `scoped_models`? It is NOT --
                         * CLAUDE.md measures that `Category` adds no predicate for ANY principal even
                         * though `getGlobalScopes()` answers `[StoreScope]` -- so that `find()` reached
                         * every tenant's categories and the write landed on whichever one the mapping
                         * named. `shopify_conections.original_id` carries no foreign key, so a mapping
                         * row scoped to THIS store can still name another store's category.
                         *
                         * The identical defect was fixed in `ShopifyProductController::edit` and the
                         * three Woo handlers; this handler was missed because the census that was
                         * supposed to cover "every re-sync handler" iterated a hardcoded list of four.
                         * See `ImportResyncOwnershipCompletenessTest`, which discovers the door instead.
                         *
                         * THE REFUSAL IS BEFORE THE FETCH, THE QUOTA CHARGE AND THE UPLOAD, which is
                         * the ordering that test pins for the product handler: a refused re-sync must
                         * not first cost the merchant storage quota and leave an orphan upload.
                         *
                         * BEHAVIOUR NOTE: a mapping whose category was DELETED previously fell through
                         * to the create branch below, which inserted a fresh category AND a SECOND
                         * mapping row for the same `shopify_id` -- so the next re-sync resolved the
                         * stale mapping again. It is refused here instead, exactly as the four sibling
                         * handlers refuse it. The create branch is left in place rather than deleted,
                         * because removing reachable-looking code is not a security fix's business.
                         */
                        $owned = \App\Services\ImportMappingOwnership::owned($upddata, Category::class, getCurrentStore());

                        if (!$owned) {
                            return redirect()->back()->with('error', __('Category Not Found.'));
                        }

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
                                $get_file = \App\Services\SafeRemoteFetch::head($file_url);
                                $file_size = $get_file->header('Content-Length');
                            } catch(\Exception $e)
                            {
                                return redirect()->back()->with('error', $e->getMessage());
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
                            /*
                             * `$owned` IS the row, already located and already ownership-checked
                             * above, so re-reading it with `Category::find($original_id)` would be
                             * a second query that reaches every tenant's categories to arrive at
                             * the row we are holding. Using the checked object keeps the
                             * authorization and the write on the SAME row -- a re-read is where a
                             * time-of-check/time-of-use gap would live.
                             */
                            $exist               = $owned;
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
