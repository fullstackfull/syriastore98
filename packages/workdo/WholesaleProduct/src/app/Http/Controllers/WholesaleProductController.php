<?php

namespace Workdo\WholesaleProduct\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\Product;
use App\Models\Store;
use Workdo\WholesaleProduct\app\Models\WholesaleProduct;
use Illuminate\Support\Facades\Cache;

class WholesaleProductController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('wholesale-product::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('wholesale-product::create');
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
        return view('wholesale-product::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('wholesale-product::edit');
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

    public function wholesale_pricing($slug , $id)
    {
        $product = Product::find($id);
        $store = getStore($slug);
        if($id)
        {
            $wholesale_data = WholesaleProduct::where('product_id',$id)->where('store_id',$store->id)->get();
            $return['html'] = view('wholesale-product::pages.product_pricing',compact('wholesale_data','slug'))->render();
            return response()->json($return);
        }
    }
}
