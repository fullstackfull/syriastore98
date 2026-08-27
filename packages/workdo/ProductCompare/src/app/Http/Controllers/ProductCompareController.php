<?php

namespace Workdo\ProductCompare\app\Http\Controllers;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\ProductCompare\app\Models\ProductCompare;
use Illuminate\Support\Facades\Cache;

class ProductCompareController extends Controller
{

    public function addProductCompare(Request $request, $slug ,$product_slug){
    
      
        $store = getStore($slug);
        $product = Product::where('slug',$product_slug)->where('id', $request->product_id)->first();
        if(auth()->guard('customers')->user() && $product)
        {
            $customer_id = auth()->guard('customers')->user()->id;

            $compare_list = ProductCompare::where('customer_id',$customer_id)->where('product_id',$product->id)->first();

            if($compare_list)
            {
                $msg['success'] = false;
                $msg['message'] = __('Item already added to compare');
                return $msg;
            } else {
                $compare = new ProductCompare();
                $compare->product_id = $product->id;
                $compare->customer_id = $customer_id;
                $compare->store_id = $store->id;
                $compare->save();

                $msg['success'] = true;
                $msg['message'] = __('Item added to compare');
                return $msg;
            }
        } else {
            $msg['success'] = false;
            $msg['message'] = __('You need to login first');
            return $msg;
        }
    }

    public function product_compare(Request $request, $slug){

        $store = getStore($slug);
        $currentTheme = $store->theme_id ?? null;
        if(auth()->guard('customers')->user())
        {
            $customer_id = auth()->guard('customers')->user()->id;

            $compare_list = ProductCompare::where('customer_id', $customer_id)->where('store_id', $store->id)->latest()->take(2)->get();
            
            $return['html'] = view('product-compare::pages.product_compare', compact('compare_list', 'store','currentTheme'))->render();
              return response()->json($return);
        }else{

            return redirect()->back()->with('error', __('You need to login first'));
        }
    }

    public function remove($slug,$id)
    {
        $productComparison = ProductCompare::findOrFail($id);
        $productComparison->delete();

        return redirect()->back()->with('success', __('Item removed from compare page'));

        return $msg;
    }
}
