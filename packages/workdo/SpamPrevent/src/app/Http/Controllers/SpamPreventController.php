<?php

namespace Workdo\SpamPrevent\app\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Store;
use App\Models\Utility;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\SpamPrevent\app\Models\ProductComment;
use Workdo\SpamPrevent\DataTables\SpamPreventData;
use Illuminate\Support\Facades\Cache;

class SpamPreventController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index(SpamPreventData $dataTable)
    {
        $store = Store::find(getCurrentStore());
        $enable_spam_prevent = Utility::GetValueByName('enable_spam_prevent', $store->slug, $store->id);
        if (isset($enable_spam_prevent) && $enable_spam_prevent == 'on') {
            $comments = ProductComment::where('store_id', getCurrentStore())->get();
            $customerComments = $comments->groupBy('customer_id');
            $result = [];
            $customerComments->map(function ($comments, $customerId) use (&$result) {
                $customerCommentsdata = [];
                $customerProductName = [];
                $customer = Customer::find($customerId);
                    foreach ($comments as $comment) {
                    $customerCommentsdata[] = $comment->comment;
                    if ($comment->product_id !== null) {
                        $product = Product::find($comment->product_id);
                        $customerProductName[$product->id] = $product->name;
                    }
                
                }
            
            $result[$customerId] = [
                    'customer_name' => $customer->first_name,
                    'customer_id' => $customerId,
                    'comments' => $customerCommentsdata,
                    'product_name' => $customerProductName,
                ];
            });
            return $dataTable->render('spam-prevent::admin.index', compact('comments', 'result'));
        } else {
            return redirect()->route('dashboard')->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        return view('spam-prevent::create');
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request, $slug)
    {
        $validator = \Validator::make(
            $request->all(),
            [
                'comment' => 'required',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            $return['status'] = false;
            $return['message'] = $messages;
            return $return;
        }
        $store = getStore($slug);
        $comment = new ProductComment;
        $comment->product_id = $request->product_id;
        $comment->customer_id = $request->customer_id;
        $comment->comment = $request->comment;
        $comment->type = 'customer';
        $comment->store_id = $store->id;
        $comment->created_by    = $request->customer_id;
        $comment->save();
        $return['status'] = 'success';
        $return['message'] = $comment;
        return $return;
    }

    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('spam-prevent::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        $comments = ProductComment::where('customer_id', $id)->get();
        $customer_id = $id;
        $store = Store::find(getCurrentStore());
        return view('spam-prevent::admin.reply', compact('comments', 'customer_id', 'store'));
    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param int $id
     * @return Renderable
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     * @param int $id
     * @return Renderable
     */
    public function destroy($id)
    {
        ProductComment::where('customer_id', $id)->delete();
        return redirect()->back()->with('success', __('Comment delete successfully.'));
    }

    public function spam_admin_store(Request $request)
    {
        if (auth()->user()->isAbleTo('Reply SpamPrevent')) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'comment' => 'required',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                $return['status'] = false;
                $return['message'] = $messages;
                return $return;
            }
            $store = Store::find(getCurrentStore());
            $comment = new ProductComment;
            $comment->product_id = $request->product_id;
            $comment->customer_id = $request->customer_id;
            $comment->comment = $request->comment;
            $comment->type = 'admin';
            $comment->store_id = $store->id;
            $comment->created_by    = $request->customer_id;
            $comment->save();
            $return['status'] = 'success';
            $return['message'] = $comment;

            return $return;
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function get_comment(Request $request)
    {
        $store = Store::find($request->store_id);
        if ($store) {
            $comments = ProductComment::where('customer_id', $request->customer_id)->where('store_id', $store->id)->get();
        } else {
            return null;
        }
        return $comments;
    }
}
