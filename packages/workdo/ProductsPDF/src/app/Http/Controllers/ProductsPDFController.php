<?php

namespace Workdo\ProductsPDF\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cache;

class ProductsPDFController extends Controller
{
    public function addProductPdf($slug, $id)
    {
        try {
            $id = Crypt::decrypt($id);
            $store = getStore($slug);
            $user = User::find($store->created_by);
            $currantLang = \Cookie::get('LANGUAGE') ?? $store->default_language;
            $setting = getAdminAllSetting($user->id, $store->id);
            $product = Product::find($id);
            $product_images = ProductImage::where('product_id', $product->id)->get();
            if (isset($product)) {
                return view('products-pdf::pdf.product_pdf', compact('store', 'user', 'product', 'product_images', 'setting', 'currantLang'));
            } else {
                return redirect()->back()->with('error', __('Product Not Found.'));
            }
        } catch (\Exception $e) {
            return redirect()->back()->with('error', __('Something was wrong.'));
        }
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('products-pdf::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('products-pdf::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        //
    }

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('products-pdf::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('products-pdf::edit');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id): RedirectResponse
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        //
    }
}
