<?php

namespace Workdo\ProductAffiliate\app\Http\Controllers;

use App\Models\Customer;
use App\Models\Store;
use App\Models\Utility;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\ProductAffiliate\app\Models\AffiliateSetting;
use Workdo\ProductAffiliate\app\Models\AffiliateTransaction;
use Workdo\ProductAffiliate\app\Models\TransactionOrder;
use Yajra\DataTables\Facades\DataTables;
use Illuminate\Support\Facades\Cache;

class ProductAffiliateController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index()
    {
        if (!auth()->user()) {
            return redirect()->route('login');
        }
        if (auth()->user()->isAbleTo('Manage Affiliate Product')) {
            $store = Store::find(getCurrentStore());
            $enable_affiliate_product = \App\Models\Utility::GetValueByName('enable_affiliate_product', $store->theme_id, $store->id);
            if (isset($enable_affiliate_product) && $enable_affiliate_product == 'on') {
                if (auth()->user()) {
                    $setting = AffiliateSetting::where('created_by', auth()->user()->id)->where('store_id', getCurrentStore())->first();
                } else {
                    $setting = AffiliateSetting::where('store_id', getCurrentStore())->first();
                }

                return view('product-affiliate::index', compact('setting'));
            } else {
                return redirect()->route('dashboard')->with('error', __('Permission denied.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function transactionsData()
    {
        $transactions = AffiliateTransaction::with(['getCompany', 'getUser', 'getOrder'])
            ->where('store_id', getCurrentStore())
            ->get();
            return DataTables::of($transactions)
            ->addIndexColumn()
            ->editColumn('company_name', function ($transaction) {
                return $transaction->getCompany ? $transaction->getCompany->name : '-';
            })
            ->editColumn('affiliate_customer_name', function ($transaction) {
                return $transaction->getUser ? $transaction->getUser->name : '-';
            })
            ->editColumn('order_id', function ($transaction) {
                return $transaction->getOrder ? $transaction->getOrder->product_order_id : '-';
            })
            ->editColumn('final_price', function ($transaction) {
                $currency = Utility::GetValueByName('CURRENCY', getCurrentStore());
                return $currency . $transaction->final_price;
            })
            ->editColumn('commission_amount', function ($transaction) {
                $currency = Utility::GetValueByName('CURRENCY', getCurrentStore());
                return $currency .($transaction->final_price * $transaction->commission) / 100;
            })
            ->make(true);
    }

    public function payRequestsData()
    {
        $payRequests = TransactionOrder::with('getCompany')
            ->where('status', 1)
            ->where('store_id', getCurrentStore())
            ->get();

        return DataTables::of($payRequests)
            ->addIndexColumn()
            ->editColumn('company_name', function ($payRequest) {
                return $payRequest->getCompany ? $payRequest->getCompany->name : '-';
            })
            ->editColumn('requested_amount', function ($payRequest) {
                $currency = Utility::GetValueByName('CURRENCY', getCurrentStore());
                return $currency . $payRequest->req_amount;
            })
            ->addColumn('action', function (TransactionOrder $payRequest) {
                return view('product-affiliate::action', compact('payRequest'));
            })
            ->rawColumns(['action'])
            ->make(true);
    }
    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        return view('product-affiliate::create');
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        $validator = \Validator::make(
            $request->all(),
            [
                'percentage' => 'required',
                'minimum_threshold_amount' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();

            return redirect()->back()->with('error', $messages->first());
        }

        if ($request->has('is_enable') && $request->is_enable == 'on') {
            $is_enable = 1;
        } else {
            $is_enable = 0;
        }

        $setting = AffiliateSetting::where('created_by', \Auth::user()->id)->where('store_id', getCurrentStore())->first();

        if ($setting == null) {
            $setting = new AffiliateSetting();
        }
        $setting->percentage = $request->percentage;
        $setting->minimum_threshold_amount = $request->minimum_threshold_amount;
        $setting->is_enable = $is_enable;
        $setting->store_id = getCurrentStore();
        $setting->created_by = \Auth::user()->creatOrId();
        $setting->save();

        return redirect()->route('productaffiliate.index')->with('success', __('Affiliate Setting successfully Updated.'));
    }

    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('product-affiliate::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('product-affiliate::edit');
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
        //
    }

    public function requestedAmountSent($id)
    {
        if (auth()->guard('customers')->user()) {
            try {
                $store = Store::find(auth()->guard('customers')->user()->store_id);
                $id = \Illuminate\Support\Facades\Crypt::decrypt($id);
                $paidAmount = TransactionOrder::where('req_user_id', auth()->guard('customers')->user()->id)->where('store_id', $store->id)->where('status', 2)->sum('req_amount');
                $user = Customer::find(auth()->guard('customers')->user()->id);

                $netAmount = $user->commission_amount - $paidAmount;
                return view('product-affiliate::pages.request_amount', compact('id', 'netAmount'));
            } catch (DecryptException $e) {
                return redirect()->back()->with('error', __('Something was wrong.'));
            } catch (\Exception $e) {
                return redirect()->back()->with('error', __('Something was wrong.'));
            }
        }
    }

    public function requestedAmountStore(Request $request, $id)
    {
        if (auth()->guard('customers')->user()) {
            $store = Store::find(auth()->guard('customers')->user()->store_id);
            $slug = $store->slug;
            if ($request->request_amount > 0) {
                $order = new TransactionOrder();
                $order->req_amount = $request->request_amount;
                $order->req_user_id = auth()->guard('customers')->user()->id;
                $order->status = 1;
                $order->date = date('Y-m-d');
                $order->store_id = $store->id;
                $order->save();

                return redirect()->back()->with('success', __('Request Send Successfully.'));
            } else {
                return redirect()->back()->with('error', __('Your amount must be greater than 0.'));
            }
        }
    }

    public function requestedAmount($id, $status)
    {
        $objUser = \Auth::user()->id;
        $setting = AffiliateSetting::where('created_by', $objUser)->where('store_id', getCurrentStore())->first();

        $transaction = TransactionOrder::find($id);
        if ($transaction) {
            $paidAmount = TransactionOrder::where('req_user_id', $transaction->req_user_id)->where('store_id', getCurrentStore())->where('status', 2)->sum('req_amount');
            $user = Customer::find($transaction->req_user_id);

            $netAmount = $user->commission_amount - $paidAmount;

            $minAmount = isset($setting) ? $setting->minimum_threshold_amount : 0;
            if ($status == 0) {
                $transaction->status = 0;
                $transaction->save();

                return redirect()->route('productaffiliate.index')->with('error', __('Request Rejected Successfully.'));
            } elseif ($transaction->req_amount > $netAmount) {
                $transaction->status = 0;
                $transaction->save();

                return redirect()->route('productaffiliate.index')->with('error', __('This request cannot be accepted because it exceeds the commission amount.'));
            } elseif ($transaction->req_amount <= $minAmount) {
                $transaction->status = 0;
                $transaction->save();
                return redirect()->route('productaffiliate.index')->with('error', __('This request cannot be accepted because it less than the threshold amount.'));
            } else {
                $transaction->status = 2;
                $transaction->save();
                return redirect()->route('productaffiliate.index')->with('success', __('Request Aceepted Successfully.'));
            }
        }
    }

    public function requestCancel($id)
    {
        if (auth()->guard('customers')->user()) {
            $store = Store::find(auth()->guard('customers')->user()->store_id);
            $transaction = TransactionOrder::where('req_user_id', $id)->where('store_id', $store->id)->orderBy('id', 'desc')->first();
            if ($transaction) {
                $transaction->delete();
            }
            return redirect()->back()->with('success', __('Request Cancel Successfully.'));
        } else {
            return redirect()->back()->with('error', __('Permission Denied'));
        }
    }
}
