<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ProductBrand;
use App\Models\Utility;
use App\DataTables\ProductBrandDataTable;
use Illuminate\Support\Facades\Storage;

class ProductBrandController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(ProductBrandDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Product Brand')) {
            return $dataTable->render('product_brand.index');
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('product_brand.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create Product Brand')) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => 'required'
                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
            $slug = ProductBrand::slugs($request->name);

            $url = null;
            if($request->logo) {
                $dir        = 'uploads/' . getCurrentStore();
                $image_size = $request->file('logo')->getSize();
                $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $image_size);
                if ($result == 1)
                {
                    $fileName = rand(10,100).'_'.time() . "_" . $request->logo->getClientOriginalName();
                    $path = Utility::upload_file($request,'logo',$fileName,$dir,[]);
                    if ($path['flag'] == 1) {
                        $url = $path['url'];
                    } else {
                        return redirect()->back()->with('error', __($path['msg']));
                    }
                }
                else{
                    return redirect()->back()->with('error', $result);
                }
            }else{
                $url = Storage::url('uploads/default.jpg');
            }

            $productBrand                      = new ProductBrand();
            $productBrand->name                = $request->name;
            $productBrand->logo                = $url;
            $productBrand->slug                = $slug;
            $productBrand->status              = $request->status;
            $productBrand->is_popular          = $request->is_popular;
            $productBrand->store_id            = getCurrentStore();
            $productBrand->created_by          = auth()->user()->id;
            $productBrand->save();
            return redirect()->back()->with('success', __('Product Brand successfully created.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(ProductBrand $productBrand)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ProductBrand $productBrand)
    {
        return view('product_brand.edit', compact('productBrand'));

    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ProductBrand $productBrand)
    {

        if (auth()->user() && auth()->user()->isAbleTo('Edit Product Brand')) {

            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => 'required',
                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $url = null;
            if($request->logo) {
                $file_path = $productBrand->logo;
                $dir        = 'uploads/' . getCurrentStore();
                $image_size = $request->file('logo')->getSize();
                $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $image_size);
                if ($result == 1)
                {
                    Utility::changeStorageLimit(\Auth::user()->creatorId(), $file_path);
                    $fileName = rand(10,100).'_'.time() . "_" . $request->logo->getClientOriginalName();
                    $path = Utility::upload_file($request,'logo',$fileName,$dir,[]);
                    if ($path['flag'] == 1) {
                        $url = $path['url'];
                    } else {
                        return redirect()->back()->with('error', __($path['msg']));
                    }
                }
                else{
                    return redirect()->back()->with('error', $result);
                }
            }

            $productBrand->name                = $request->name;
            if ($url) {
                $productBrand->logo                = $url;
            }
            if (isset($request->status)) {
                $productBrand->status              = $request->status;
            }
            if (isset($request->is_popular)) {
                $productBrand->is_popular          = $request->is_popular;
            }
            $productBrand->store_id            = getCurrentStore();
            $productBrand->save();

            return redirect()->back()->with('success', __('Product Brand successfully updated.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ProductBrand $productBrand)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Delete Product Brand')) {
            if ($productBrand->logo !== '/storage/uploads/default.jpg' && \File::exists(base_path($productBrand->logo))) {
                Utility::changeStorageLimit(\Auth::user()->creatorId(), $productBrand->logo );
            }

            $productBrand->delete();
            return redirect()->back()->with('success', __('Product Brand delete successfully.'));

        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Responds with a welcome message with instructions
     *
     * @return \Illuminate\Http\Response
     */
    public function changeStatus(Request $request)
    {
        $productBrand = ProductBrand::find($request->id);
        if ($productBrand) {
            $productBrand->status = $request->status;
            $productBrand->save();
            $return['status'] = 'success';
            $return['message'] = __('Status change successfully.');
            return response()->json($return);
        } else {
            $return['status'] = 'error';
            $return['message'] = __('Something went wrong!!');
            return response()->json($return);
        }
    }

    /**
     * Responds with a welcome message with instructions
     *
     * @return \Illuminate\Http\Response
     */
    public function changePopular(Request $request)
    {
        $productBrand = ProductBrand::find($request->id);

        if ($productBrand) {
            $productBrand->is_popular = $request->is_popular;
            $productBrand->save();
            $return['status'] = 'success';
            $return['message'] = __('Status change successfully.');
            return response()->json($return);
        } else {
            $return['status'] = 'error';
            $return['message'] = __('Something went wrong!!');
            return response()->json($return);
        }
    }
}
