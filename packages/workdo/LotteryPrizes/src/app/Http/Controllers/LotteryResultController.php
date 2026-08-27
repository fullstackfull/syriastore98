<?php

namespace Workdo\LotteryPrizes\app\Http\Controllers;

use App\Models\Customer;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Models\UserEmailTemplate;
use App\Models\Utility;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\LotteryPrizes\app\Models\LotteryCustomer;
use Workdo\LotteryPrizes\app\Models\LotterySetting;
use Workdo\LotteryPrizes\app\Models\PurchaseTicket;
use Illuminate\Support\Facades\Crypt;

class LotteryResultController extends Controller
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
        if (auth()->user()->isAbleTo('Manage Lottery Winner')) {
            $store = getStoreById(getCurrentStore());

            $enable_lottery_button = Utility::GetValueByName('enable_lottery_button', $store->id);

            if (isset($enable_lottery_button) && $enable_lottery_button == 'on') {
                $skipsetting = LotteryCustomer::where('store_id', getCurrentStore())->distinct()->pluck('lottery_id')->toArray();
                $lotterySettings = LotterySetting::where('store_id', getCurrentStore())
                    ->where('created_by', auth()->user()->id)
                    ->whereNotIn('id', $skipsetting)
                    ->get();
                return view('lottery-prizes::admin.lottery-result.index', compact('lotterySettings'));
            } else {
                return redirect()->route('dashboard')->with('error', __('Permission denied.'));
            }
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
        return view('lottery-prizes::create');
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
                'selected_codes' => 'required|string',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }
        $selectedCodes = explode(',', $request->input('selected_codes'));
        $takenCodes = LotteryCustomer::whereIn('lottery_code', $selectedCodes)->pluck('lottery_code')->toArray();

        if (!empty($takenCodes)) {
            $message = __('The following codes have already been used: ') . implode(', ', $takenCodes);
            return redirect()->back()->with('error', $message);
        }

        $purchased_tickets = PurchaseTicket::with(['lotterySetting', 'lotterySetting.product'])
            ->whereIn('lottery_code', $selectedCodes)
            ->get();

        $user = auth()->user();
        $store = Store::find($user->current_store);

        $lotteryCustomers = [];

        foreach ($purchased_tickets as $ticket) {
            $lottery_setting = $ticket->lotterySetting;
            $product = $lottery_setting->product;

            $UserEmailTemplate = UserEmailTemplate::where('user_id', $user->id)
                ->where('template_id', EmailTemplate::where('name', 'Lottery Win')->where('created_by', $user->id)->value('id'))
                ->first();

            if ($UserEmailTemplate) {
                $dArr = [
                    'product_name' => $product->name,
                    'product_price' => $lottery_setting->ticket_price,
                    'ticket_number' => $ticket->lottery_code,
                ];

                LotteryPrizesController::sendOwnerEmailTemplate('Lottery Win', $ticket->email, $ticket->firstname, $dArr, $user, $store);

                $lotteryCustomers[] = [
                    'ticket_id' => $ticket->id,
                    'lottery_id' => $lottery_setting->id,
                    'lottery_price' => $lottery_setting->ticket_price,
                    'lottery_code' => $ticket->lottery_code,
                    'customer_id' => $ticket->customer_id,
                    'status' => 'win',
                    'email_sent' => true,
                    'store_id' => $ticket->store_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $order = new Order();
                $order->fill([
                    'product_order_id' => '0' . date('YmdHis'),
                    'order_date' => now(),
                    'customer_id' => $ticket->customer_id ?: 0,
                    'is_guest' => auth('customers')->check() ? 0 : 1,
                    'product_id' => $product->id,
                    'product_json' => json_encode($product),
                    'product_price' => $product->price,
                    'final_price' => SetNumber($lottery_setting->ticket_price),
                    'payment_type' => 'Lottery Product',
                    'payment_status' => 'Paid',
                    'product_order_status' => 'lottery_order',
                    'lottery_code' => $ticket->lottery_code,
                    'reward_points' => SetNumber(1),
                    'store_id' => $store->id,
                ]);
                $order->save();
            }
            sleep(1);
        }

        if (!empty($lotteryCustomers)) {
            LotteryCustomer::insert($lotteryCustomers);
        }

        $fail_ticket = PurchaseTicket::whereNotIn('lottery_code', $selectedCodes)
            ->where('lottery_id', $lottery_setting->id)
            ->with('lotterySetting.product')
            ->get();

        $FailTemplate = EmailTemplate::where('name', 'Lottery Fail')->where('created_by', $user->id)->first();
        $FailEmailTemplate = UserEmailTemplate::where('user_id', $user->id)->where('template_id', $FailTemplate['id'])->first();

        $lotteryFailCustomers = [];
        foreach ($fail_ticket as $ticket) {
            $lottery_setting = $ticket->lotterySetting;
            $product = $lottery_setting->product;

            if ($FailEmailTemplate) {
                $dArr = [
                    'product_name' => $product->name,
                    'product_price' => $lottery_setting->ticket_price,
                    'ticket_number' => $ticket->lottery_code,
                ];
                LotteryPrizesController::sendOwnerEmailTemplate('Lottery Fail', $ticket->email, $ticket->firstname, $dArr, $user, $store);

                $lotteryFailCustomers[] = [
                    'ticket_id' => $ticket->id,
                    'lottery_id' => $lottery_setting->id,
                    'lottery_price' => $lottery_setting->ticket_price,
                    'lottery_code' => $ticket->lottery_code,
                    'customer_id' => $ticket->customer_id,
                    'status' => 'fail',
                    'email_sent' => true,
                    'store_id' => $ticket->store_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            sleep(1);
        }

        if (!empty($lotteryFailCustomers)) {
            LotteryCustomer::insert($lotteryFailCustomers);
        }

        return redirect()->back()->with('success', __('Result Announce successfully.'));
    }

    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('lottery-prizes::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('lottery-prizes::edit');
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
}
