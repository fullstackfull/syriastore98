<?php

namespace Workdo\ProductQuickView\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\Store;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

class ProductQuickViewController extends Controller
{
    public function productQuickViewModel(Request $request, $slug, $product_slug) {
        $store = getStore($slug);
        $product = Product::where('slug',$product_slug)->where('store_id', $store->id)->first();
        $return['html'] = view('product-quick-view::pages.product_quick_view', compact('slug','product','store'))->render();
        return response()->json($return);
    }
}
