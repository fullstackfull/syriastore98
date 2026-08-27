<?php

namespace Workdo\ProductReview\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Workdo\ProductReview\app\Models\ProductReview;
use App\Models\Product;
use App\Models\Store;
use App\Models\Category;
use Workdo\ProductReview\DataTables\ProductReviewDataTable;
use Illuminate\Support\Facades\Cache;

class ProductReviewController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(ProductReviewDataTable $dataTable)
    {
        if (!auth()->user()) {
			return redirect()->route('login');
		}
        return $dataTable->render('product-review::product-review.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $categories = Category::where('store_id',getCurrentStore())->get();
        $categoryTree = buildCategoryTree($categories);
        return view('product-review::product-review.create', compact('categories', 'categoryTree'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        if (auth()->user()->isAbleTo('Create Product Review'))
        {
            $validator = \Validator::make(
                $request->all(), [
                    'category_id' => 'required',
                    'product_id' => 'required',
                    'rating_no' => 'required',
                    'title' => 'required',
                    'description' => 'required',
                    'status' => 'required',
                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $productReview = new ProductReview();
            $productReview->category_id = $request->category_id;
            $productReview->product_id = $request->product_id;
            $productReview->rating_no = $request->rating_no;
            $productReview->title = $request->title;
            $productReview->description = $request->description;
            $productReview->status = $request->status;
            $productReview->store_id = getCurrentStore();
            $productReview->save();


            return redirect()->back()->with('success', __('Product Review create successfully.'));

        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        $review = ProductReview::find($id);
        $categories = Category::where('store_id', getCurrentStore())->get();
        $categoryTree = buildCategoryTree($categories);
        $product = Product::where('category_id',$review->category_id)->where('store_id',getCurrentStore())->pluck('name', 'id')->prepend('Select Product', '');
        return view('product-review::product-review.edit', compact( 'categoryTree', 'review', 'product'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id): RedirectResponse
    {
        if (auth()->user()->isAbleTo('Edit Product Review'))
        {
            $validator = \Validator::make(
                $request->all(), [
                    'category_id' => 'required',
                    'product_id' => 'required',
                    'rating_no' => 'required',
                    'title' => 'required',
                    'description' => 'required',
                    'status' => 'required',
                ]
            );

            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            $review = ProductReview::find($id);
            $review->category_id = $request->category_id;
            $review->product_id = $request->product_id;
            $review->rating_no = $request->rating_no;
            $review->title = $request->title;
            $review->description = $request->description;
            $review->status = $request->status;
            $review->save();


            return redirect()->back()->with('success', __('Product Review update successfully.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        if (auth()->user()->isAbleTo('Delete Product Review'))
        {
            $review = ProductReview::find($id);
            $review->delete();
            return redirect()->back()->with('success', __('Product Review delete successfully.'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function productReview(Request $request,$slug ,$id)
    {
        return view('product-review::pages.product_review',compact('id','slug') );
    }

    public function productReviewForm(Request $request, $slug, $product_slug) {
        $store = getStore($slug);
        $product_slug = $request->segment(3);
        $product = Product::where('slug',$product_slug)->first();
        $return['html'] = view('product-review::pages.product_review',compact('slug', 'product', 'store') )->render();
        return response()->json($return);
    }
    public function addProductReview(Request $request ,$storeSlug)
    {
        $validator = \Validator::make(
            $request->all(),
            [
                'category_id' => 'nullable',
                'product_id'      => 'nullable',
                'rating_no'       => 'nullable',
                'title'           => 'nullable',
                'description'     => 'required',
                'status'          => 'nullable',
            ]
        );
        if($validator->fails())
        {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }
        $review            = new ProductReview();
        $review->category_id = $request->category_id ?? null;
        $review->product_id = $request->product_id  ?? null;
        $review->rating_no = $request->rating_no  ?? null;
        $review->title = $request->title  ?? null;
        $review->description = $request->description  ?? null;
        $review->status = $request->status  ?? null;
        $review->store_id = $request->store_id;
        $review->save();

        return redirect()->back()->with('success', __('Thanks! You rated this '.$request->rating_no.' stars.'));
    }
}
