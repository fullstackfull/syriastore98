<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderCouponDetail;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Models\Customer;
use App\Models\ProductBrand;
use App\Models\Utility;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Session;
use App\DataTables\SalesDownloadableProductDataTable;

class ReportController extends Controller
{
    /**
     * P1-SEC-09: every report handler is gated by the permission of the menu entry that links to it (App\Http\Middleware\ReportPermission),
     * one mechanism for the whole controller rather than a copy per handler. Declared here and not as HasMiddleware: see PlanCouponController.
     */
    public function __construct()
    {
        $this->middleware(\App\Http\Middleware\ReportPermission::class);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {

        return view('reports.index');
    }

    public function reports_chart(Request $request)
    {
        $store_id = getStoreById(getCurrentStore());
        if ($request->chart_data == 'last-month') {
            $data = 'last-month';
            $lastMonth = Carbon::now()->subMonth();
            $guest = Order::where('store_id', getCurrentStore())->where('is_guest', '=', 1)->whereYear('order_date', $lastMonth->format('Y'))->whereMonth('order_date', $lastMonth->format('m'))->get()->count();
            $customer = Order::where('store_id', getCurrentStore())->where('is_guest', '=', 0)->whereYear('order_date', $lastMonth->format('Y'))->whereMonth('order_date', $lastMonth->format('m'))->get()->count();

            $customer_total = Customer::where('store_id', getCurrentStore())->where('regiester_date', '!=', NULL)->whereYear('regiester_date', $lastMonth->format('Y'))->whereMonth('regiester_date', $lastMonth->format('m'))->get()->count();
            $totaluser = $guest + $customer;



            $user = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $prevMonth = strtotime("-1 month");
            $start = strtotime(date('Y-m-01', $prevMonth));
            $end = strtotime(date('Y-m-t', $prevMonth));
            $date = (int) date('j', strtotime($end));

            $user->where('order_date', '>=', date('Y-m-01', $start))->where('order_date', '<=', date('Y-m-t', $end))->where('is_guest', '=', 0)->where('store_id', getCurrentStore());
            $user = $user->get();

            $register_customer = Customer::selectRaw('customers.*,DATE(regiester_date) as DATE,MONTH(regiester_date) as month');
            $register_customer->where('regiester_date', '>=', date('Y-m-01', $start))->where('regiester_date', '<=', date('Y-m-t', $end))->where('regiester_date', '!=', NULL)->where('store_id', getCurrentStore());
            $register_customer = $register_customer->get();

            $new_guest = Customer::selectRaw('customers.*,DATE(last_active) as DATE,MONTH(last_active) as month');
            $new_guest->where('last_active', '>=', date('Y-m-01', $start))->where('last_active', '<=', date('Y-m-t', $end))->where('regiester_date', '=', NULL)->where('store_id', getCurrentStore());
            $new_guest = $new_guest->get();

            $guests = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $guests->where('order_date', '>=', date('Y-m-01', $start))->where('order_date', '<=', date('Y-m-t', $end))->where('is_guest', '=', 1)->where('store_id', getCurrentStore());
            $guests = $guests->get();

            $userTotalArray = [];
            $guestTotalArray = [];
            $registerTotalArray = [];
            $newguestTotalArray = [];
            foreach ($user as $users) {
                $day = (int) date('j', strtotime($users->DATE)); // Extract day of the month

                $userTotalArray[$day][] = $users->order_date;
            }

            foreach ($guests as $guestss) {
                $day = (int) date('j', strtotime($guestss->DATE)); // Extract day of the month
                $guestTotalArray[$day][] = $guestss->order_date;
            }

            foreach ($register_customer as $register_c) {
                $day = (int) date('j', strtotime($register_c->DATE)); // Extract day of the month
                $registerTotalArray[$day][] = $register_c->regiester_date;
            }
            foreach ($new_guest as $guest_new) {
                $day = (int) date('j', strtotime($guest_new->DATE)); // Extract day of the month
                $newguestTotalArray[$day][] = $guest_new->last_active;
            }
            $lastDayofMonth = Carbon::now()->subMonthNoOverflow()->endOfMonth();
            $lastday = date('j', strtotime($lastDayofMonth));

            for ($i = 1; $i <= $lastday; $i++) {
                $userTotal[] = array_key_exists($i, $userTotalArray) ? count($userTotalArray[$i]) : 0;
                $guestTotal[] = array_key_exists($i, $guestTotalArray) ? count($guestTotalArray[$i]) : 0;
                $registerTotal[] = array_key_exists($i, $registerTotalArray) ? count($registerTotalArray[$i]) : 0;
                $newguestTotal[] = array_key_exists($i, $newguestTotalArray) ? count($newguestTotalArray[$i]) : 0;
            }

            $monthList = $month = $this->getLastMonthDatesFormatted();
        } elseif ($request->chart_data == 'this-month') {

            $guest = Order::where('store_id', getCurrentStore())->where('is_guest', '=', 1)->whereYear('order_date', date('Y'))->whereMonth('order_date', date('m'))->get()->count();
            $customer = Order::where('store_id', getCurrentStore())->where('is_guest', '=', 0)->whereYear('order_date', date('Y'))->whereMonth('order_date', date('m'))->get()->count();
            $customer_total = Customer::where('store_id', getCurrentStore())->where('regiester_date', '!=', NULL)->whereYear('regiester_date', date('Y'))->whereMonth('regiester_date', date('m'))->get()->count();
            $totaluser = $guest + $customer;


            $user = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $start = strtotime(date('Y-m-01'));
            $end = strtotime(date('Y-m-t'));
            $day = (int) date('j', strtotime($end));

            $user->where('order_date', '>=', date('Y-m-01', $start))->where('order_date', '<=', date('Y-m-t', $end))->where('is_guest', '=', 0)->where('store_id', getCurrentStore());
            $user = $user->get();


            $register_customer = Customer::selectRaw('customers.*,DATE(regiester_date) as DATE,MONTH(regiester_date) as month');
            $register_customer->where('regiester_date', '>=', date('Y-m-01', $start))->where('regiester_date', '<=', date('Y-m-t', $end))->where('regiester_date', '!=', NULL)->where('store_id', getCurrentStore());
            $register_customer = $register_customer->get();

            $new_guest = Customer::selectRaw('customers.*,DATE(last_active) as DATE,MONTH(last_active) as month');
            $new_guest->where('last_active', '>=', date('Y-m-01', $start))->where('last_active', '<=', date('Y-m-t', $end))->where('regiester_date', '=', NULL)->where('store_id', getCurrentStore());
            $new_guest = $new_guest->get();

            $guests = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $guests->where('order_date', '>=', date('Y-m-01', $start))->where('order_date', '<=', date('Y-m-t', $end))->where('is_guest', '=', 1)->where('store_id', getCurrentStore());
            $guests = $guests->get();

            $userTotalArray = [];
            $guestTotalArray = [];
            $customerTotalArray = [];
            $newguestTotalArray = [];
            foreach ($user as $users) {
                $day = (int) date('j', strtotime($users->DATE));
                $userTotalArray[$day][] = $users->order_date;
            }
            foreach ($guests as $guestss) {
                $day = (int) date('j', strtotime($guestss->DATE));
                $guestTotalArray[$day][] = $guestss->order_date;
            }
            foreach ($register_customer as $register_c) {
                $day = (int) date('j', strtotime($register_c->DATE));
                $customerTotalArray[$day][] = $register_c->regiester_date;
            }
            foreach ($new_guest as $guest_new) {
                $day = (int) date('j', strtotime($guest_new->DATE));
                $newguestTotalArray[$day][] = $guest_new->last_active;
            }
            $lastDayofMonth = Carbon::now()->endOfMonth()->toDateString();
            $lastday = date('j', strtotime($lastDayofMonth));

            for ($i = 1; $i <= $lastday; $i++) {
                $userTotal[] = array_key_exists($i, $userTotalArray) ? count($userTotalArray[$i]) : 0;
                $guestTotal[] = array_key_exists($i, $guestTotalArray) ? count($guestTotalArray[$i]) : 0;
                $registerTotal[] = array_key_exists($i, $customerTotalArray) ? count($customerTotalArray[$i]) : 0;
                $newguestTotal[] = array_key_exists($i, $newguestTotalArray) ? count($newguestTotalArray[$i]) : 0;
            }
            $monthList = $month = $this->getCurrentMonthDates();
        } elseif ($request->chart_data == 'seven-day') {
            $startDate = now()->subDays(6);
            $guest = Order::where('store_id', getCurrentStore())->where('is_guest', 1)->where('order_date', '>', $startDate)->get()->count();
            $customer = Order::where('store_id', getCurrentStore())->where('is_guest', '=', 0)->where('order_date', '>', $startDate)->get()->count();
            $customer_total = Customer::where('store_id', getCurrentStore())->where('regiester_date', '!=', NULL)->where('regiester_date', '>', $startDate)->get()->count();
            $totaluser = $guest + $customer;


            $userTotal = [];
            $guestTotal = [];
            $monthList = [];
            $newguestTotal = [];
            $previous_week = strtotime("-1 week +1 day");

            for ($i = 0; $i <= 7 - 1; $i++) {
                $date = date('Y-m-d', $previous_week);
                $previous_week = strtotime(date('Y-m-d', $previous_week) . " +1 day");
                $monthList[] = __(date('d-M', strtotime($date)));
                $userTotal[] = Order::whereDate('order_date', $date)->where('is_guest', '=', 0)->where('store_id', getCurrentStore())->count();
                $guestTotal[] = Order::whereDate('order_date', $date)->where('is_guest', '=', 1)->where('store_id', getCurrentStore())->count();
                $registerTotal[] = Customer::whereDate('regiester_date', $date)->where('regiester_date', '!=', NULL)->where('store_id', getCurrentStore())->count();
                $newguestTotal[] = Customer::whereDate('last_active', $date)->where('regiester_date', '=', NULL)->where('store_id', getCurrentStore())->count();
            }
        } elseif ($request->chart_data == 'year') {

            $guest = Order::where('store_id', getCurrentStore())->where('is_guest', '=', 1)->whereYear('order_date', date('Y'))->get()->count();
            $customer = Order::where('store_id', getCurrentStore())->where('is_guest', '=', 0)->whereYear('order_date', date('Y'))->get()->count();
            $customer_total = Customer::where('store_id', getCurrentStore())->where('regiester_date', '!=', NULL)->whereYear('regiester_date', date('Y'))->get()->count();
            $totaluser = $guest + $customer;

            $user = Order::selectRaw('orders.*,MONTH(order_date) as month,YEAR(order_date) as year');
            $start = strtotime(date('Y-01'));
            $end = strtotime(date('Y-12'));
            $user->where('order_date', '>=', date('Y-m-01', $start))->where('order_date', '<=', date('Y-m-t', $end))->where('is_guest', '=', 0)->where('store_id', getCurrentStore());
            $user = $user->get();

            $guests = Order::selectRaw('orders.*,MONTH(order_date) as month,YEAR(order_date) as year');
            $guests->where('order_date', '>=', date('Y-m-01', $start))->where('order_date', '<=', date('Y-m-t', $end))->where('is_guest', '=', 1)->where('store_id', getCurrentStore());
            $guests = $guests->get();

            $register_customer = Customer::selectRaw('customers.*,MONTH(regiester_date) as month,YEAR(regiester_date) as year');
            $register_customer->where('regiester_date', '>=', date('Y-m-01', $start))->where('regiester_date', '<=', date('Y-m-t', $end))->where('regiester_date', '!=', NULL)->where('store_id', getCurrentStore());
            $register_customer = $register_customer->get();

            $new_guest = Customer::selectRaw('customers.*,MONTH(last_active) as month,YEAR(last_active) as year');
            $new_guest->where('last_active', '>=', date('Y-m-01', $start))->where('last_active', '<=', date('Y-m-t', $end))->where('regiester_date', '=', NULL)->where('store_id', getCurrentStore());
            $new_guest = $new_guest->get();


            $userTotalArray = [];
            $guestTotalArray = [];
            $registerTotalArray = [];
            $newguestTotalArray = [];
            foreach ($user as $user) {
                $userTotalArray[$user->month][] = $user->order_date;
            }

            foreach ($guests as $guests) {
                $guestTotalArray[$guests->month][] = $guests->order_date;
            }
            foreach ($register_customer as $register_c) {
                $registerTotalArray[$register_c->month][] = $register_c->regiester_date;
            }
            foreach ($new_guest as $guest_new) {
                $newguestTotalArray[$guest_new->month][] = $guest_new->last_active;
            }
            for ($i = 1; $i <= 12; $i++) {
                $userTotal[] = array_key_exists($i, $userTotalArray) ? count($userTotalArray[$i]) : 0;
                $guestTotal[] = array_key_exists($i, $guestTotalArray) ? count($guestTotalArray[$i]) : 0;
                $registerTotal[] = array_key_exists($i, $registerTotalArray) ? count($registerTotalArray[$i]) : 0;
                $newguestTotal[] = array_key_exists($i, $newguestTotalArray) ? count($newguestTotalArray[$i]) : 0;
            }
            $monthList = $month = $this->yearMonth();
        } else {
            if (str_contains($request->Date, ' to ')) {
                $date_range = explode(' to ', $request->Date);
                if (count($date_range) === 2) {
                    $form_date = date('Y-m-d', strtotime($date_range[0]));
                    $to_date = date('Y-m-d', strtotime($date_range[1]));
                } else {
                    $start_date = date('Y-m-d', strtotime($date_range[0]));
                    $end_date = date('Y-m-d', strtotime($date_range[0]));
                }
            } else {

                $form_date = date('Y-m-d', strtotime($request->Date));
                $to_date = date('Y-m-d', strtotime($request->Date));
            }
            $guest = Order::whereDate('order_date', '>=', $form_date)->whereDate('order_date', '<=', $to_date)->where('is_guest', '=', 1)->where('store_id', getCurrentStore())->count();
            $customer = Order::whereDate('order_date', '>=', $form_date)->whereDate('order_date', '<=', $to_date)->where('is_guest', '=', 0)->where('store_id', getCurrentStore())->count();

            $customer_total = Customer::whereDate('regiester_date', '>=', $form_date)->whereDate('regiester_date', '<=', $to_date)->where('regiester_date', '!=', NULL)->where('store_id', getCurrentStore())->count();

            $totaluser = $guest + $customer;

            $user = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $user->whereDate('order_date', '>=', $form_date)
                ->whereDate('order_date', '<=', $to_date)->where('is_guest', '=', 0)->where('store_id', getCurrentStore());
            $user = $user->get();

            $guests = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $guests->whereDate('order_date', '>=', $form_date)
                ->whereDate('order_date', '<=', $to_date)->where('store_id', getCurrentStore())->where('is_guest', '=', 1);
            $guests = $guests->get();

            $register_customer = Customer::selectRaw('customers.*,DATE(regiester_date) as DATE,MONTH(regiester_date) as month');
            $register_customer->whereDate('regiester_date', '>=', $form_date)
                ->whereDate('regiester_date', '<=', $to_date)->where('store_id', getCurrentStore())->where('regiester_date', '!=', NULL);
            $register_customer = $register_customer->get();

            $new_guest = Customer::selectRaw('customers.*,DATE(last_active) as DATE,MONTH(last_active) as month');
            $new_guest->whereDate('last_active', '>=', $form_date)
                ->whereDate('last_active', '<=', $to_date)->where('store_id', getCurrentStore())->where('regiester_date', '=', NULL);
            $new_guest = $new_guest->get();

            $userTotalArray = [];
            $guestTotalArray = [];
            $registerTotalArray = [];
            $newguestTotalArray = [];
            $data = [];
            $data_month = [];
            $guestTotal = [];
            $userTotal = [];
            $monthLists = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $monthLists = Order::whereDate('order_date', '>=', $form_date)
                ->whereDate('order_date', '<=', $to_date)->where('store_id', getCurrentStore());
            $monthLists = $monthLists->get();

            foreach ($monthLists as $monthLists_date) {
                $data[] = date('y-n-j', strtotime($monthLists_date->order_date));
                $data_month[] = date('Y-m-d', strtotime($monthLists_date->order_date));
            }

            $List = array_values(array_unique($data));
            $monthList_data = $List;
            $List_month = array_values(array_unique($data_month));
            $monthList = $List_month;
            foreach ($user as $users) {
                $day = date('y-n-j', strtotime($users->DATE));
                $userTotalArray[$day][] = date('y-n-j', strtotime($users->order_date));
            }
            foreach ($register_customer as $register_c) {
                $day = date('y-n-j', strtotime($register_c->DATE));
                $registerTotalArray[$day][] = date('y-n-j', strtotime($register_c->regiester_date));
            }

            foreach ($new_guest as $guest_new) {
                $day = date('y-n-j', strtotime($guest_new->DATE));
                $newguestTotalArray[$day][] = date('y-n-j', strtotime($guest_new->last_active));
            }
            foreach ($guests as $g) {
                $day = date('y-n-j', strtotime($g->DATE));
                $guestTotalArray[$day][] = date('y-n-j', strtotime($g->order_date));
            }
            foreach ($monthList_data as $month) {
                $userTotal[] = array_key_exists($month, $userTotalArray) ? count($userTotalArray[$month]) : 0;
                $guestTotal[] = array_key_exists($month, $guestTotalArray) ? count($guestTotalArray[$month]) : 0;
                $registerTotal[] = array_key_exists($month, $registerTotalArray) ? count($registerTotalArray[$month]) : 0;
                $newguestTotal[] = array_key_exists($month, $newguestTotalArray) ? count($newguestTotalArray[$month]) : 0;
            }
        }

        /* PRD-001. A figure with no period is the commonest way a report misleads: the same
           card reads "412 orders" for the year and for last week, and nothing on it says which.
           The window is decided by exactly the input this method already dispatched on, so it is
           named once here rather than in five branches — a per-branch label would be five places
           for the label and the data to drift apart.

           The column header travels with it for the same reason: the year view's buckets are
           MONTHS and every other view's are DAYS, and a table that says "Day" over twelve month
           names is wrong in a way no one notices until they read it aloud. */
        [$periodLabel, $columnLabel] = $this->chartPeriodLabels($request);

        $html = '';
        $html = view('reports.chart_data', compact(
            'customer', 'guest', 'totaluser', 'customer_total',
            'periodLabel', 'columnLabel', 'monthList'
        ) + [
            'userTotal' => $userTotal ?? [],
            'guestTotal' => $guestTotal ?? [],
            'registerTotal' => $registerTotal ?? [],
            'newguestTotal' => $newguestTotal ?? [],
        ])->render();

        $return['html'] = $html;
        $return['guestTotal'] = $guestTotal ?? [];
        $return['userTotal'] = $userTotal ?? [];
        $return['registerTotal'] = $registerTotal ?? [];
        $return['monthList'] = $monthList;
        $return['customer'] = $customer;
        $return['guest'] = $guest;
        $return['newguestTotal'] = $newguestTotal ?? [];

        Session::put('return', $return);


        return response()->json($return);
    }

    /**
     * The period a `reports_chart` answer covers, and what its buckets are called.
     *
     * Returns [period, column]. Both are already translated, because they go straight into a
     * heading and a table header. The caller dispatches on the same `chart_data` value, so this
     * cannot name a window the data does not cover — which is the whole point of deriving it
     * once instead of writing a literal into each branch.
     *
     * The default branch is a single date or a ` to ` range from the date picker, so it names the
     * actual dates rather than something vague: "12 Mar 2026" or "1 Mar 2026 – 31 Mar 2026".
     */
    private function chartPeriodLabels(Request $request): array
    {
        switch ($request->chart_data) {
            case 'last-month':
                return [__('Last month') . ' (' . date('F Y', strtotime('first day of last month')) . ')', __('Day')];
            case 'this-month':
                return [__('This month') . ' (' . date('F Y') . ')', __('Day')];
            case 'seven-day':
                return [__('Last 7 days'), __('Day')];
            case 'year':
                return [__('This year') . ' (' . date('Y') . ')', __('Month')];
        }

        $raw = (string) $request->Date;

        if ($raw === '') {
            return [__('Today') . ' (' . date('d M Y') . ')', __('Day')];
        }

        if (str_contains($raw, ' to ')) {
            $parts = explode(' to ', $raw);
            $from = date('d M Y', strtotime($parts[0]));
            $to = date('d M Y', strtotime($parts[count($parts) > 1 ? 1 : 0]));

            return [$from === $to ? $from : $from . ' – ' . $to, __('Day')];
        }

        return [date('d M Y', strtotime($raw)), __('Day')];
    }

    public function getCurrentMonthDates()
    {
        $currentYear = date('Y');
        $currentMonth = date('m');
        $daysInMonth = date('t', mktime(0, 0, 0, $currentMonth, 1, $currentYear));

        $dates = [];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $formattedDate = date('d-M', mktime(0, 0, 0, $currentMonth, $day, $currentYear));
            $dates[] = $formattedDate;
        }

        return $dates;
    }

    public function getLastMonthDatesFormatted()
    {
        $lastMonth = Carbon::now()->subMonth();
        $lastMonthYear = $lastMonth->format('Y');
        $lastMonthMonth = $lastMonth->format('m');
        $daysInLastMonth = $lastMonth->daysInMonth;

        $dates = [];

        for ($day = 1; $day <= $daysInLastMonth; $day++) {
            $formattedDate = $lastMonth->setDay($day)->format('d-M');
            $dates[] = $formattedDate;
        }

        return $dates;
    }
    public function yearMonth()
    {
        $month[] = __('January');
        $month[] = __('February');
        $month[] = __('March');
        $month[] = __('April');
        $month[] = __('May');
        $month[] = __('June');
        $month[] = __('July');
        $month[] = __('August');
        $month[] = __('September');
        $month[] = __('October');
        $month[] = __('November');
        $month[] = __('December');
        return $month;
    }

    public function export(Request $request)
    {
        $requests_data = Session::get('return');
        $return['monthList'] = $requests_data['monthList'];
        $return['userTotal'] = $requests_data['userTotal'];
        $return['registerTotal'] = $requests_data['registerTotal'];
        $return['guest'] = $requests_data['guestTotal'];
        $return['newguestTotal'] = $requests_data['newguestTotal'];
        return response()->json($return);
    }

    public function OrderReport(Request $request)
    {
        return view('reports.order_report');
    }

    public function order_reports_chart(Request $request)
    {
        $store_id = getStoreById(getCurrentStore());
        $currency = Utility::GetValueByName('CURRENCY');
        if ($request->chart_data == 'last-month') {
            $data = 'last-month';
            $lastMonth = Carbon::now()->subMonth();
            $prevMonth = strtotime("-1 month");
            $start = strtotime(date('Y-m-01', $prevMonth));
            $end = strtotime(date('Y-m-t', $prevMonth));

            $customer = Order::where('store_id', getCurrentStore())->whereYear('order_date', date('Y'))->get()->count();
            $customer_total = Customer::where('store_id', getCurrentStore())->where('regiester_date', '!=', NULL)->whereYear('regiester_date', date('Y'))->get()->count();
            $totaluser = 0;
            $guest = '';

            $lastDayofMonth = Carbon::now()->subMonthNoOverflow()->endOfMonth();
            $lastday = date('j', strtotime($lastDayofMonth));

            $orders = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $orders->where('order_date', '>=', date('Y-m-01', $start))->where('order_date', '<=', date('Y-m-t', $end))->where('store_id', getCurrentStore());
            $orders = $orders->get();
            $TotalOrder = $orders->count();

            $totalDuePurchaseorder = 0;
            $grossSaleTotalArray = [];
            $netSaleTotalArray = [];
            $CouponTotalArray = [];
            $ShippingTotalArray = [];
            $OrderTotalArray = [];
            $totalduepurchaseorderArray = [];
            $averageGrossSales = [];
            $PurchasedItemArray = [];
            $totalProductQuantity = 0;
            $PurchasedProductItemTotal = 0;
            $TotalgrossSale = 0;
            $TotalNetSale = 0;
            $TotalShippingCharge = 0;
            $TotalCouponAmount = 0;
            foreach ($orders as $order) {
                $day = (int) date('j', strtotime($order->DATE)); // Extract day of the month

                $netSaleTotalArray[$day][] = (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $grossSaleTotalArray[$day][] = (float) $order->final_price;
                $CouponTotalArray[$day][] = (float) $order['coupon_price'];
                $ShippingTotalArray[$day][] = (float) $order->delivery_price;
                $OrderTotalArray[$day][] = 1;
                $products = json_decode($order['product_json'], true);
                foreach ($products as $product) {
                    if (!isset($product['qty']) && isset($product['quantity'])) {
                        $product['qty'] = $product['quantity'];
                    }
                    $totalProductQuantity = intval($product['qty'] ?? 1);
                    $PurchasedItemArray[$day][] = $totalProductQuantity;
                    $PurchasedProductItemTotal += $totalProductQuantity;
                }
                $TotalgrossSale += (float) $order->final_price;
                $TotalNetSale += (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $TotalCouponAmount += (float) $order['coupon_price'];
                $TotalShippingCharge += (float) $order->delivery_price;
            }


            for ($i = 1; $i <= $lastday; $i++) {
                $GrossSaleTotal[] = array_key_exists($i, $grossSaleTotalArray) ? array_sum($grossSaleTotalArray[$i]) : 0;
                $NetSaleTotal[] = array_key_exists($i, $netSaleTotalArray) ? array_sum($netSaleTotalArray[$i]) : 0;
                $ShippingTotal[] = array_key_exists($i, $ShippingTotalArray) ? array_sum($ShippingTotalArray[$i]) : 0;
                $CouponTotal[] = array_key_exists($i, $CouponTotalArray) ? array_sum($CouponTotalArray[$i]) : 0;
                $TotalOrderCount[] = array_key_exists($i, $OrderTotalArray) ? count($OrderTotalArray[$i]) : 0;

                $PurchasedItemTotal[] = array_key_exists($i, $PurchasedItemArray) ? array_sum($PurchasedItemArray[$i]) : 0;

                $dailySales = array_key_exists($i, $grossSaleTotalArray) ? $grossSaleTotalArray[$i] : [];
                $averageGrossSales[] = count($dailySales) > 0 ? (array_sum($dailySales) / count($dailySales)) : 0;

                $dailyNetSales = array_key_exists($i, $netSaleTotalArray) ? $netSaleTotalArray[$i] : [];
                $averageNetSales[] = count($dailyNetSales) > 0 ? (array_sum($dailyNetSales) / count($dailyNetSales)) : 0;
            }

            $monthList = $month = $this->getLastMonthDatesFormatted();
        } elseif ($request->chart_data == 'this-month') {
            $start = strtotime(date('Y-m-01'));
            $end = strtotime(date('Y-m-t'));
            $day = (int) date('j', strtotime($end));

            $orders = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $orders->where('order_date', '>=', date('Y-m-01', $start))->where('order_date', '<=', date('Y-m-t', $end))->where('store_id', getCurrentStore());
            $orders = $orders->get();
            $TotalOrder = $orders->count();

            $totalDuePurchaseorder = 0;
            $grossSaleTotalArray = [];
            $netSaleTotalArray = [];
            $CouponTotalArray = [];
            $ShippingTotalArray = [];
            $OrderTotalArray = [];
            $totalduepurchaseorderArray = [];
            $averageGrossSales = [];
            $PurchasedItemArray = [];
            $totalProductQuantity = 0;
            $PurchasedProductItemTotal = 0;
            $TotalgrossSale = 0;
            $TotalNetSale = 0;
            $TotalShippingCharge = 0;
            $TotalCouponAmount = 0;

            foreach ($orders as $order) {
                $day = (int) date('j', strtotime($order->DATE));
                $userTotalArray[$day][] = $order->order_date;

                $netSaleTotalArray[$day][] = (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $grossSaleTotalArray[$day][] = (float) $order->final_price;
                $CouponTotalArray[$day][] = (float) $order['coupon_price'];
                $ShippingTotalArray[$day][] = (float) $order->delivery_price;
                $OrderTotalArray[$day][] = 1;
                $products = json_decode($order['product_json'], true);
                foreach ($products as $product) {
                    if (!isset($product['qty']) && isset($product['quantity'])) {
                        $product['qty'] = $product['quantity'];
                    }
                    $totalProductQuantity = intval($product['qty'] ?? 1);
                    $PurchasedItemArray[$day][] = $totalProductQuantity;
                    $PurchasedProductItemTotal += $totalProductQuantity;
                }
                $TotalgrossSale += (float) $order->final_price;
                $TotalNetSale += (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $TotalCouponAmount += (float) $order['coupon_price'];
                $TotalShippingCharge += (float) $order->delivery_price;
            }
            $lastDayofMonth = Carbon::now()->endOfMonth()->toDateString();
            $lastday = date('j', strtotime($lastDayofMonth));

            for ($i = 1; $i <= $lastday; $i++) {
                $GrossSaleTotal[] = array_key_exists($i, $grossSaleTotalArray) ? array_sum($grossSaleTotalArray[$i]) : 0;
                $NetSaleTotal[] = array_key_exists($i, $netSaleTotalArray) ? array_sum($netSaleTotalArray[$i]) : 0;
                $ShippingTotal[] = array_key_exists($i, $ShippingTotalArray) ? array_sum($ShippingTotalArray[$i]) : 0;
                $CouponTotal[] = array_key_exists($i, $CouponTotalArray) ? array_sum($CouponTotalArray[$i]) : 0;
                $TotalOrderCount[] = array_key_exists($i, $OrderTotalArray) ? count($OrderTotalArray[$i]) : 0;

                $PurchasedItemTotal[] = array_key_exists($i, $PurchasedItemArray) ? array_sum($PurchasedItemArray[$i]) : 0;

                $dailySales = array_key_exists($i, $grossSaleTotalArray) ? $grossSaleTotalArray[$i] : [];
                $averageGrossSales[] = count($dailySales) > 0 ? (array_sum($dailySales) / count($dailySales)) : 0;

                $dailyNetSales = array_key_exists($i, $netSaleTotalArray) ? $netSaleTotalArray[$i] : [];
                $averageNetSales[] = count($dailyNetSales) > 0 ? (array_sum($dailyNetSales) / count($dailyNetSales)) : 0;
            }
            $monthList = $month = $this->getCurrentMonthDates();
        } elseif ($request->chart_data == 'seven-day') {
            $startDate = now()->subDays(6);

            $TotalOrder = 0;
            $totalDuePurchaseorder = 0;
            $grossSaleTotalArray = [];
            $netSaleTotalArray = [];
            $CouponTotalArray = [];
            $ShippingTotalArray = [];
            $OrderTotalArray = [];
            $totalduepurchaseorderArray = [];
            $averageGrossSales = [];
            $PurchasedItemArray = [];
            $totalProductQuantity = 0;
            $PurchasedProductItemTotal = 0;
            $TotalgrossSale = 0;
            $TotalNetSale = 0;
            $TotalShippingCharge = 0;
            $TotalCouponAmount = 0;
            $monthList = [];
            $previous_week = strtotime("-1 week +1 day");

            for ($i = 0; $i <= 7 - 1; $i++) {
                $date = date('Y-m-d', $previous_week);
                $previous_week = strtotime(date('Y-m-d', $previous_week) . " +1 day");
                $monthList[] = __(date('d-M', strtotime($date)));

                $ordersForDate = Order::whereDate('order_date', $date)
                    ->where('store_id', getCurrentStore())
                    ->get();
                $TotalOrder += $ordersForDate->count();
                $totalPurchasedItemsForDate = 0;

                foreach ($ordersForDate as $order) {
                    $products = json_decode($order->product_json, true);

                    $totalProductQuantity = array_reduce($products, function ($carry, $product) {
                        if (!isset($product['qty']) && isset($product['quantity'])) {
                            $product['qty'] = $product['quantity'];
                        }
                        return $carry + intval($product['qty']);
                    }, 0);
                    $totalPurchasedItemsForDate += $totalProductQuantity;
                    $PurchasedProductItemTotal += $totalProductQuantity;
                }
                $PurchasedItemTotal[] = $totalPurchasedItemsForDate;

                $totalOrdersForDate = Order::whereDate('order_date', $date)
                    ->where('store_id', getCurrentStore())
                    ->count();

                $GrossSaleTotal[] = Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('final_price');

                $NetSaleTotal[] = Order::whereDate('order_date', $date)
                    ->where('store_id', getCurrentStore())
                    ->get()
                    ->sum(function ($order) {
                        return $order->final_price - $order->delivery_price - $order->tax_price;
                    });
                $CouponTotal[] = Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('coupon_price');
                $ShippingTotal[] = Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('delivery_price');
                $TotalOrderCount[] = $totalOrdersForDate;

                $averageGrossSales[] = $totalOrdersForDate > 0 ? ($GrossSaleTotal[count($GrossSaleTotal) - 1] / $totalOrdersForDate) : 0;
                $averageNetSales[] = $totalOrdersForDate > 0 ? ($NetSaleTotal[count($NetSaleTotal) - 1] / $totalOrdersForDate) : 0;

                $TotalgrossSale += Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('final_price');
                $TotalNetSale += Order::whereDate('order_date', $date)
                    ->where('store_id', getCurrentStore())
                    ->get()
                    ->sum(function ($order) {
                        return $order->final_price - $order->delivery_price - $order->tax_price;
                    });
                $TotalCouponAmount += Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('coupon_price');
                $TotalShippingCharge += Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('delivery_price');
                $TotalOrderCount[] = $totalOrdersForDate;
            }
        } elseif ($request->chart_data == 'year') {

            $TotalOrder = Order::where('store_id', getCurrentStore())->whereYear('order_date', date('Y'))->get()->count();

            $orders = Order::selectRaw('orders.*,MONTH(order_date) as month,YEAR(order_date) as year');
            $start = strtotime(date('Y-01'));
            $end = strtotime(date('Y-12'));
            $orders->where('order_date', '>=', date('Y-m-01', $start))->where('order_date', '<=', date('Y-m-t', $end))->where('store_id', getCurrentStore());
            $orders = $orders->get();
            $order = Order::where('store_id', getCurrentStore())
                ->whereYear('order_date', date('Y'))
                ->get()->count();

            $totalDuePurchaseorder = 0;
            $grossSaleTotalArray = [];
            $netSaleTotalArray = [];
            $CouponTotalArray = [];
            $ShippingTotalArray = [];
            $OrderTotalArray = [];
            $totalduepurchaseorderArray = [];
            $averageGrossSales = [];
            $PurchasedItemArray = [];
            $totalProductQuantity = 0;
            $PurchasedProductItemTotal = 0;
            $TotalgrossSale = 0;
            $TotalNetSale = 0;
            $TotalShippingCharge = 0;
            $TotalCouponAmount = 0;
            foreach ($orders as $order) {
                $netSaleTotalArray[$order->month][] = (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $grossSaleTotalArray[$order->month][] = (float) $order->final_price;
                $CouponTotalArray[$order->month][] = (float) $order['coupon_price'];
                $ShippingTotalArray[$order->month][] = (float) $order->delivery_price;
                $OrderTotalArray[$order->month][] = 1;
                $products = json_decode($order['product_json'], true);
                foreach ($products as $product) {
                    if (!isset($product['qty']) && isset($product['quantity'])) {
                        $product['qty'] = $product['quantity'];
                    }
                    $totalProductQuantity = intval($product['qty'] ?? 1);
                    $PurchasedItemArray[$order->month][] = $totalProductQuantity;
                    $PurchasedProductItemTotal += $totalProductQuantity;
                }
                $TotalgrossSale += (float) $order->final_price;
                $TotalNetSale += (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $TotalCouponAmount += (float) $order['coupon_price'];
                $TotalShippingCharge += (float) $order->delivery_price;
            }
            for ($i = 1; $i <= 12; $i++) {

                $GrossSaleTotal[] = array_key_exists($i, $grossSaleTotalArray) ? array_sum($grossSaleTotalArray[$i]) : 0;
                $NetSaleTotal[] = array_key_exists($i, $netSaleTotalArray) ? array_sum($netSaleTotalArray[$i]) : 0;
                $ShippingTotal[] = array_key_exists($i, $ShippingTotalArray) ? array_sum($ShippingTotalArray[$i]) : 0;
                $CouponTotal[] = array_key_exists($i, $CouponTotalArray) ? array_sum($CouponTotalArray[$i]) : 0;
                $TotalOrderCount[] = array_key_exists($i, $OrderTotalArray) ? count($OrderTotalArray[$i]) : 0;

                $PurchasedItemTotal[] = array_key_exists($i, $PurchasedItemArray) ? array_sum($PurchasedItemArray[$i]) : 0;

                $monthlySales = array_key_exists($i, $grossSaleTotalArray) ? $grossSaleTotalArray[$i] : [];
                $average = count($monthlySales) > 0 ? (array_sum($monthlySales) / count($monthlySales)) : 0;
                $averageGrossSales[] = $average;

                $monthlySales = array_key_exists($i, $netSaleTotalArray) ? $netSaleTotalArray[$i] : [];
                $netsaleaverage = count($monthlySales) > 0 ? (array_sum($monthlySales) / count($monthlySales)) : 0;
                $averageNetSales[] = $netsaleaverage;
            }
            $monthList = $month = $this->yearMonth();
        } else {
            if (str_contains($request->Date, ' to ')) {
                $date_range = explode(' to ', $request->Date);
                if (count($date_range) === 2) {
                    $form_date = date('Y-m-d', strtotime($date_range[0]));
                    $to_date = date('Y-m-d', strtotime($date_range[1]));
                } else {
                    $start_date = date('Y-m-d', strtotime($date_range[0]));
                    $end_date = date('Y-m-d', strtotime($date_range[0]));
                }
            } else {

                $form_date = date('Y-m-d', strtotime($request->Date));
                $to_date = date('Y-m-d', strtotime($request->Date));
            }
            $orders = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $orders->whereDate('order_date', '>=', $form_date)->whereDate('order_date', '<=', $to_date)->where('store_id', getCurrentStore());
            $orders = $orders->get();
            $TotalOrder = $orders->count();

            $totalDuePurchaseorder = 0;
            $grossSaleTotalArray = [];
            $netSaleTotalArray = [];
            $CouponTotalArray = [];
            $ShippingTotalArray = [];
            $OrderTotalArray = [];
            $totalduepurchaseorderArray = [];
            $averageGrossSales = [];
            $PurchasedItemArray = [];
            $totalProductQuantity = 0;
            $PurchasedProductItemTotal = 0;
            $TotalgrossSale = 0;
            $TotalNetSale = 0;
            $TotalShippingCharge = 0;
            $TotalCouponAmount = 0;

            $monthLists = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $monthLists = Order::whereDate('order_date', '>=', $form_date)
                ->whereDate('order_date', '<=', $to_date)->where('store_id', getCurrentStore());
            $monthLists = $monthLists->get();

            foreach ($monthLists as $monthLists_date) {
                $data[] = date('y-n-j', strtotime($monthLists_date->order_date));
                $data_month[] = date('Y-m-d', strtotime($monthLists_date->order_date));
            }
            if (!empty($data) && is_array($data)) {
                $List = array_values(array_unique($data));
                $monthList_data = $List;
                $List_month = array_values(array_unique($data_month));
                $monthList = $List_month;
            } else {
                $List = [];
                $monthList_data = [];
                $data_month[] = date('y-n-j');
                $List_month = array_values(array_unique($data_month));
                $monthList = $List_month;
            }

            foreach ($orders as $order) {
                $day = date('y-n-j', strtotime($order->DATE));
                $userTotalArray[$day][] = date('y-n-j', strtotime($order->order_date));

                $netSaleTotalArray[$day][] = (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $grossSaleTotalArray[$day][] = (float) $order->final_price;
                $CouponTotalArray[$day][] = (float) $order['coupon_price'];
                $ShippingTotalArray[$day][] = (float) $order->delivery_price;
                $OrderTotalArray[$day][] = 1;
                $products = json_decode($order['product_json'], true);
                foreach ($products as $product) {
                    if (!isset($product['qty']) && isset($product['quantity'])) {
                        $product['qty'] = $product['quantity'];
                    }
                    $totalProductQuantity = intval($product['qty'] ?? 1);
                    $PurchasedItemArray[$day][] = $totalProductQuantity;
                    $PurchasedProductItemTotal += $totalProductQuantity;
                }
                $TotalgrossSale += (float) $order->final_price;
                $TotalNetSale += (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $TotalCouponAmount += (float) $order['coupon_price'];
                $TotalShippingCharge += (float) $order->delivery_price;
            }

            if (!empty($data) && is_array($data)) {
                foreach ($monthList_data as $month) {
                    $GrossSaleTotal[] = array_key_exists($month, $grossSaleTotalArray) ? array_sum($grossSaleTotalArray[$month]) : 0;
                    $NetSaleTotal[] = array_key_exists($month, $netSaleTotalArray) ? array_sum($netSaleTotalArray[$month]) : 0;
                    $ShippingTotal[] = array_key_exists($month, $ShippingTotalArray) ? array_sum($ShippingTotalArray[$month]) : 0;
                    $CouponTotal[] = array_key_exists($month, $CouponTotalArray) ? array_sum($CouponTotalArray[$month]) : 0;
                    $TotalOrderCount[] = array_key_exists($month, $OrderTotalArray) ? count($OrderTotalArray[$month]) : 0;

                    $PurchasedItemTotal[] = array_key_exists($month, $PurchasedItemArray) ? array_sum($PurchasedItemArray[$month]) : 0;

                    $dailySales = array_key_exists($month, $grossSaleTotalArray) ? $grossSaleTotalArray[$month] : [];
                    $averageGrossSales[] = count($dailySales) > 0 ? (array_sum($dailySales) / count($dailySales)) : 0;

                    $dailyNetSales = array_key_exists($month, $netSaleTotalArray) ? $netSaleTotalArray[$month] : [];
                    $averageNetSales[] = count($dailyNetSales) > 0 ? (array_sum($dailyNetSales) / count($dailyNetSales)) : 0;
                }
            } else {
                $month = date('y-n-j');
                $GrossSaleTotal[] = array_key_exists($month, $grossSaleTotalArray) ? array_sum($grossSaleTotalArray[$month]) : 0;
                $NetSaleTotal[] = array_key_exists($month, $netSaleTotalArray) ? array_sum($netSaleTotalArray[$month]) : 0;
                $ShippingTotal[] = array_key_exists($month, $ShippingTotalArray) ? array_sum($ShippingTotalArray[$month]) : 0;
                $CouponTotal[] = array_key_exists($month, $CouponTotalArray) ? array_sum($CouponTotalArray[$month]) : 0;
                $TotalOrderCount[] = array_key_exists($month, $OrderTotalArray) ? count($OrderTotalArray[$month]) : 0;

                $PurchasedItemTotal[] = array_key_exists($month, $PurchasedItemArray) ? array_sum($PurchasedItemArray[$month]) : 0;

                $dailySales = array_key_exists($month, $grossSaleTotalArray) ? $grossSaleTotalArray[$month] : [];
                $averageGrossSales[] = count($dailySales) > 0 ? (array_sum($dailySales) / count($dailySales)) : 0;

                $dailyNetSales = array_key_exists($month, $netSaleTotalArray) ? $netSaleTotalArray[$month] : [];
                $averageNetSales[] = count($dailyNetSales) > 0 ? (array_sum($dailyNetSales) / count($dailyNetSales)) : 0;
            }
        }

        $html = '';
        [$periodLabel] = $this->chartPeriodLabels($request);

        /* The series go into the view too, so the charts can ship their numbers as tables. They
           were already being returned to the browser below; the view simply never saw them. */
        $html = view('reports.order_chart_data', compact(
            'TotalOrder', 'PurchasedProductItemTotal', 'TotalgrossSale', 'currency',
            'TotalNetSale', 'TotalCouponAmount', 'TotalShippingCharge', 'periodLabel'
        ) + [
            'labels' => $monthList ?? [],
            'grossSeries' => $GrossSaleTotal ?? [],
            'netSeries' => $NetSaleTotal ?? [],
            'orderSeries' => $TotalOrderCount ?? [],
        ])->render();

        $return['html'] = $html;

        $return['TotalOrderCount'] = $TotalOrderCount;
        $return['NetSaleTotal'] = $NetSaleTotal;
        $return['AverageNetSales'] = $averageNetSales;
        $return['GrossSaleTotal'] = $GrossSaleTotal;
        $return['AverageGrossSales'] = $averageGrossSales;
        $return['PurchasedItemTotal'] = $PurchasedItemTotal;
        $return['ShippingTotal'] = $ShippingTotal;
        $return['CouponTotal'] = $CouponTotal;
        $return['monthList'] = $monthList;
        Session::put('order_line_chart_report', $return);

        return response()->json($return);
    }

    public function order_report_export(Request $request)
    {

        $requests_data = Session::get('order_line_chart_report');
        $return['monthList'] = $requests_data['monthList'];
        $return['TotalOrderCount'] = $requests_data['TotalOrderCount'];
        $return['NetSaleTotal'] = $requests_data['NetSaleTotal'];
        $return['AverageNetSales'] = $requests_data['AverageNetSales'];
        $return['GrossSaleTotal'] = $requests_data['GrossSaleTotal'];
        $return['AverageGrossSales'] = $requests_data['AverageGrossSales'];
        $return['PurchasedItemTotal'] = $requests_data['PurchasedItemTotal'];
        $return['ShippingTotal'] = $requests_data['ShippingTotal'];
        $return['CouponTotal'] = $requests_data['CouponTotal'];
        return response()->json($return);
    }

    public function order_bar_report_export(Request $request)
    {

        $requests_data = Session::get('order_bar_chart_report');
        $return['monthList'] = $requests_data['monthList'];
        $return['NetSaleTotal'] = $requests_data['NetSaleTotal'];
        $return['AverageNetSales'] = $requests_data['AverageNetSales'];
        $return['GrossSaleTotal'] = $requests_data['GrossSaleTotal'];
        $return['AverageGrossSales'] = $requests_data['AverageGrossSales'];
        $return['ShippingTotal'] = $requests_data['ShippingTotal'];
        $return['CouponTotal'] = $requests_data['CouponTotal'];
        return response()->json($return);
    }

    public function BarChartOrderReport(Request $request)
    {
        return view('reports.bar_chart_order_report');
    }

    public function order_reports_chart_data(Request $request)
    {
        $store_id = getStoreById(getCurrentStore());
        $currency = Utility::GetValueByName('CURRENCY');
        if ($request->chart_data == 'last-month') {
            $data = 'last-month';
            $lastMonth = Carbon::now()->subMonth();
            $prevMonth = strtotime("-1 month");
            $start = strtotime(date('Y-m-01', $prevMonth));
            $end = strtotime(date('Y-m-t', $prevMonth));

            $TotalOrder = Order::where('store_id', getCurrentStore())->whereYear('order_date', date('Y'))->get()->count();
            $customer = Order::where('store_id', getCurrentStore())->whereYear('order_date', date('Y'))->get()->count();
            $customer_total = Customer::where('store_id', getCurrentStore())->where('regiester_date', '!=', NULL)->whereYear('regiester_date', date('Y'))->get()->count();
            $totaluser = 0;
            $guest = '';

            $lastDayofMonth = Carbon::now()->subMonthNoOverflow()->endOfMonth();
            $lastday = date('j', strtotime($lastDayofMonth));

            $orders = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $orders->where('order_date', '>=', date('Y-m-01', $start))->where('order_date', '<=', date('Y-m-t', $end))->where('store_id', getCurrentStore());
            $orders = $orders->get();
            $TotalOrder = $orders->count();
            $TotalOrderCount = Order::selectRaw('orders.*, MONTH(order_date) as month, YEAR(order_date) as year')
                ->whereBetween('order_date', [$lastMonth, $lastDayofMonth])
                ->where('store_id', getCurrentStore())
                ->count();

            $totalDuePurchaseorder = 0;
            $grossSaleTotalArray = [];
            $netSaleTotalArray = [];
            $CouponTotalArray = [];
            $ShippingTotalArray = [];
            $OrderTotalArray = [];
            $totalduepurchaseorderArray = [];
            $averageGrossSales = [];
            $PurchasedItemArray = [];
            $totalProductQuantity = 0;
            $PurchasedItemTotal = 0;
            $PurchasedProductItemTotal = 0;
            $TotalgrossSale = 0;
            $TotalNetSale = 0;
            $TotalShippingCharge = 0;
            $TotalCouponAmount = 0;
            foreach ($orders as $order) {
                $day = (int) date('j', strtotime($order->DATE)); // Extract day of the month

                $netSaleTotalArray[$day][] = (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $grossSaleTotalArray[$day][] = (float) $order->final_price;
                $CouponTotalArray[$day][] = (float) $order['coupon_price'];
                $ShippingTotalArray[$day][] = (float) $order->delivery_price;
                $OrderTotalArray[$day][] = 1;
                $products = json_decode($order['product_json'], true);
                foreach ($products as $product) {
                    $totalProductQuantity = intval($product['qty'] ?? 0);
                    $PurchasedItemArray[$day][] = $totalProductQuantity;
                    $PurchasedItemTotal += $totalProductQuantity;
                    $PurchasedProductItemTotal += $totalProductQuantity;
                }
                $TotalgrossSale += (float) $order->final_price;
                $TotalNetSale += (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $TotalCouponAmount += (float) $order['coupon_price'];
                $TotalShippingCharge += (float) $order->delivery_price;
            }

            for ($i = 1; $i <= $lastday; $i++) {
                $GrossSaleTotal[] = array_key_exists($i, $grossSaleTotalArray) ? array_sum($grossSaleTotalArray[$i]) : 0;
                $NetSaleTotal[] = array_key_exists($i, $netSaleTotalArray) ? array_sum($netSaleTotalArray[$i]) : 0;
                $ShippingTotal[] = array_key_exists($i, $ShippingTotalArray) ? array_sum($ShippingTotalArray[$i]) : 0;
                $CouponTotal[] = array_key_exists($i, $CouponTotalArray) ? array_sum($CouponTotalArray[$i]) : 0;

                $dailySales = array_key_exists($i, $grossSaleTotalArray) ? $grossSaleTotalArray[$i] : [];
                $averageGrossSales[] = count($dailySales) > 0 ? (array_sum($dailySales) / count($dailySales)) : 0;

                $dailyNetSales = array_key_exists($i, $netSaleTotalArray) ? $netSaleTotalArray[$i] : [];
                $averageNetSales[] = count($dailyNetSales) > 0 ? (array_sum($dailyNetSales) / count($dailyNetSales)) : 0;
            }

            $monthList = $month = $this->getLastMonthDatesFormatted();
        } elseif ($request->chart_data == 'this-month') {

            $guest = Order::where('store_id', getCurrentStore())->where('is_guest', '=', 1)->whereYear('order_date', date('Y'))->whereMonth('order_date', date('m'))->get()->count();
            $customer = Order::where('store_id', getCurrentStore())->where('is_guest', '=', 0)->whereYear('order_date', date('Y'))->whereMonth('order_date', date('m'))->get()->count();
            $customer_total = Customer::where('store_id', getCurrentStore())->where('regiester_date', '!=', NULL)->whereYear('regiester_date', date('Y'))->get()->count();
            $totaluser = 0;
            $guest = '';
            $start = strtotime(date('Y-m-01'));
            $end = strtotime(date('Y-m-t'));
            $day = (int) date('j', strtotime($end));

            $orders = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $orders->where('order_date', '>=', date('Y-m-01', $start))->where('order_date', '<=', date('Y-m-t', $end))->where('store_id', getCurrentStore());
            $orders = $orders->get();
            $TotalOrder = $orders->count();

            $TotalOrderCount = Order::selectRaw('orders.*, MONTH(order_date) as month, YEAR(order_date) as year')
                ->whereMonth('order_date', date('m'))
                ->where('store_id', getCurrentStore())
                ->count();

            $totalDuePurchaseorder = 0;
            $grossSaleTotalArray = [];
            $netSaleTotalArray = [];
            $CouponTotalArray = [];
            $ShippingTotalArray = [];
            $OrderTotalArray = [];
            $totalduepurchaseorderArray = [];
            $averageGrossSales = [];
            $PurchasedItemArray = [];
            $totalProductQuantity = 0;
            $PurchasedItemTotal = 0;
            $PurchasedProductItemTotal = 0;
            $TotalgrossSale = 0;
            $TotalNetSale = 0;
            $TotalShippingCharge = 0;
            $TotalCouponAmount = 0;

            foreach ($orders as $order) {
                $day = (int) date('j', strtotime($order->DATE));
                $netSaleTotalArray[$day][] = (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $grossSaleTotalArray[$day][] = (float) $order->final_price;
                $CouponTotalArray[$day][] = (float) $order['coupon_price'];
                $ShippingTotalArray[$day][] = (float) $order->delivery_price;
                $OrderTotalArray[$day][] = 1;
                $products = json_decode($order['product_json'], true);
                foreach ($products as $product) {
                    $totalProductQuantity = intval($product['qty'] ?? 0);
                    $PurchasedItemArray[$day][] = $totalProductQuantity;
                    $PurchasedItemTotal += $totalProductQuantity;
                    $PurchasedProductItemTotal += $totalProductQuantity;
                }
                $TotalgrossSale += (float) $order->final_price;
                $TotalNetSale += (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $TotalCouponAmount += (float) $order['coupon_price'];
                $TotalShippingCharge += (float) $order->delivery_price;
            }
            $lastDayofMonth = Carbon::now()->endOfMonth()->toDateString();
            $lastday = date('j', strtotime($lastDayofMonth));

            for ($i = 1; $i <= $lastday; $i++) {
                $GrossSaleTotal[] = array_key_exists($i, $grossSaleTotalArray) ? array_sum($grossSaleTotalArray[$i]) : 0;
                $NetSaleTotal[] = array_key_exists($i, $netSaleTotalArray) ? array_sum($netSaleTotalArray[$i]) : 0;
                $ShippingTotal[] = array_key_exists($i, $ShippingTotalArray) ? array_sum($ShippingTotalArray[$i]) : 0;
                $CouponTotal[] = array_key_exists($i, $CouponTotalArray) ? array_sum($CouponTotalArray[$i]) : 0;

                $dailySales = array_key_exists($i, $grossSaleTotalArray) ? $grossSaleTotalArray[$i] : [];
                $averageGrossSales[] = count($dailySales) > 0 ? (array_sum($dailySales) / count($dailySales)) : 0;

                $dailyNetSales = array_key_exists($i, $netSaleTotalArray) ? $netSaleTotalArray[$i] : [];
                $averageNetSales[] = count($dailyNetSales) > 0 ? (array_sum($dailyNetSales) / count($dailyNetSales)) : 0;
            }
            $monthList = $month = $this->getCurrentMonthDates();
        } elseif ($request->chart_data == 'seven-day') {
            $startDate = now()->subDays(6);

            $TotalOrderCount = Order::where('order_date', '>=', $startDate)
                ->where('order_date', '<=', now())
                ->where('store_id', getCurrentStore())
                ->count();
            $TotalOrder = 0;
            $totalDuePurchaseorder = 0;
            $grossSaleTotalArray = [];
            $netSaleTotalArray = [];
            $CouponTotalArray = [];
            $ShippingTotalArray = [];
            $OrderTotalArray = [];
            $totalduepurchaseorderArray = [];
            $averageGrossSales = [];
            $PurchasedItemArray = [];
            $totalProductQuantity = 0;
            $PurchasedItemTotal = 0;
            $PurchasedProductItemTotal = 0;
            $TotalgrossSale = 0;
            $TotalNetSale = 0;
            $TotalShippingCharge = 0;
            $TotalCouponAmount = 0;

            $monthList = [];
            $previous_week = strtotime("-1 week +1 day");

            for ($i = 0; $i <= 7 - 1; $i++) {
                $date = date('Y-m-d', $previous_week);
                $previous_week = strtotime(date('Y-m-d', $previous_week) . " +1 day");
                $monthList[] = __(date('d-M', strtotime($date)));

                $ordersForDate = Order::whereDate('order_date', $date)                    
                    ->where('store_id', getCurrentStore())
                    ->get();
                $TotalOrder += $ordersForDate->count();

                $totalPurchasedItemsForDate = 0;

                foreach ($ordersForDate as $order) {
                    $products = json_decode($order->product_json, true);

                    $totalProductQuantity = array_reduce($products, function ($carry, $product) {
                        return $carry + intval($product['qty'] ?? 0);
                    }, 0);
                    $PurchasedItemTotal += $totalProductQuantity;
                    $PurchasedProductItemTotal += $totalProductQuantity;
                }

                $totalOrdersForDate = Order::whereDate('order_date', $date)
                    
                    ->where('store_id', getCurrentStore())
                    ->count();

                $GrossSaleTotal[] = Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('final_price');
                $NetSaleTotal[] = Order::whereDate('order_date', $date)                    
                    ->where('store_id', getCurrentStore())
                    ->get()
                    ->sum(function ($order) {
                        return $order->final_price - $order->delivery_price - $order->tax_price;
                    });
                $CouponTotal[] = Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('coupon_price');
                $ShippingTotal[] = Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('delivery_price');

                $averageGrossSales[] = $totalOrdersForDate > 0 ? ($GrossSaleTotal[count($GrossSaleTotal) - 1] / $totalOrdersForDate) : 0;
                $averageNetSales[] = $totalOrdersForDate > 0 ? ($NetSaleTotal[count($NetSaleTotal) - 1] / $totalOrdersForDate) : 0;

                $TotalgrossSale += Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('final_price');
                $TotalNetSale += Order::whereDate('order_date', $date)
                    
                    ->where('store_id', getCurrentStore())
                    ->get()
                    ->sum(function ($order) {
                        return $order->final_price - $order->delivery_price - $order->tax_price;
                    });
                $TotalCouponAmount += Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('coupon_price');
                $TotalShippingCharge += Order::whereDate('order_date', $date)->where('store_id', getCurrentStore())->get()->sum('delivery_price');
            }
        } elseif ($request->chart_data == 'year') {
            $TotalOrder = Order::where('store_id', getCurrentStore())->whereYear('order_date', date('Y'))->get()->count();

            $orders = Order::selectRaw('orders.*,MONTH(order_date) as month,YEAR(order_date) as year');
            $start = strtotime(date('Y-01'));
            $end = strtotime(date('Y-12'));
            $orders->where('order_date', '>=', date('Y-m-01', $start))->where('order_date', '<=', date('Y-m-t', $end))->where('store_id', getCurrentStore());
            $orders = $orders->get();
            $TotalOrderCount = Order::selectRaw('orders.*, MONTH(order_date) as month, YEAR(order_date) as year')
                ->whereYear('order_date', date('Y')) // Filter by the current year
                
                ->where('store_id', getCurrentStore())
                ->get()->count();

            $totalgrossSale = 0;
            $totalDuePurchaseorder = 0;
            $grossSaleTotalArray = [];
            $netSaleTotalArray = [];
            $CouponTotalArray = [];
            $ShippingTotalArray = [];
            $OrderTotalArray = [];
            $totalduepurchaseorderArray = [];
            $averageGrossSales = [];
            $PurchasedItemArray = [];
            $PurchasedItemTotal = 0;
            $PurchasedProductItemTotal = 0;
            $TotalgrossSale = 0;
            $TotalNetSale = 0;
            $TotalShippingCharge = 0;
            $TotalCouponAmount = 0;

            foreach ($orders as $order) {
                $netSaleTotalArray[$order->month][] = (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $grossSaleTotalArray[$order->month][] = (float) $order->final_price;
                $CouponTotalArray[$order->month][] = (float) $order['coupon_price'];
                $ShippingTotalArray[$order->month][] = (float) $order->delivery_price;
                $OrderTotalArray[$order->month][] = 1;
                $products = json_decode($order['product_json'], true);
                foreach ($products as $product) {
                    $totalProductQuantity = intval($product['qty'] ?? 0);
                    $PurchasedItemArray[$order->month][] = $totalProductQuantity;
                    $PurchasedItemTotal += $totalProductQuantity;
                    $PurchasedProductItemTotal += $totalProductQuantity;
                }
                $TotalgrossSale += (float) $order->final_price;
                $TotalNetSale += (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $TotalCouponAmount += (float) $order['coupon_price'];
                $TotalShippingCharge += (float) $order->delivery_price;
            }
            for ($i = 1; $i <= 12; $i++) {

                $GrossSaleTotal[] = array_key_exists($i, $grossSaleTotalArray) ? array_sum($grossSaleTotalArray[$i]) : 0;
                $NetSaleTotal[] = array_key_exists($i, $netSaleTotalArray) ? array_sum($netSaleTotalArray[$i]) : 0;
                $ShippingTotal[] = array_key_exists($i, $ShippingTotalArray) ? array_sum($ShippingTotalArray[$i]) : 0;
                $CouponTotal[] = array_key_exists($i, $CouponTotalArray) ? array_sum($CouponTotalArray[$i]) : 0;

                $monthlySales = array_key_exists($i, $grossSaleTotalArray) ? $grossSaleTotalArray[$i] : [];
                $average = count($monthlySales) > 0 ? (array_sum($monthlySales) / count($monthlySales)) : 0;
                $averageGrossSales[] = $average;

                $monthlySales = array_key_exists($i, $netSaleTotalArray) ? $netSaleTotalArray[$i] : [];
                $netsaleaverage = count($monthlySales) > 0 ? (array_sum($monthlySales) / count($monthlySales)) : 0;
                $averageNetSales[] = $netsaleaverage;
            }
            $monthList = $month = $this->yearMonth();
        } else {
            if (str_contains($request->Date, ' to ')) {
                $date_range = explode(' to ', $request->Date);
                if (count($date_range) === 2) {
                    $form_date = date('Y-m-d', strtotime($date_range[0]));
                    $to_date = date('Y-m-d', strtotime($date_range[1]));
                } else {
                    $start_date = date('Y-m-d', strtotime($date_range[0]));
                    $end_date = date('Y-m-d', strtotime($date_range[0]));
                }
            } else {

                $form_date = date('Y-m-d', strtotime($request->Date));
                $to_date = date('Y-m-d', strtotime($request->Date));
            }

            $orders = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $orders->whereDate('order_date', '>=', $form_date)->whereDate('order_date', '<=', $to_date)->where('store_id', getCurrentStore());
            $orders = $orders->get();
            $TotalOrder = $orders->count();

            $guests = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $guests->whereDate('order_date', '>=', $form_date)
                ->whereDate('order_date', '<=', $to_date)->where('store_id', getCurrentStore())->where('is_guest', '=', 1);
            $guests = $guests->get();

            $register_customer = Customer::selectRaw('customers.*,DATE(regiester_date) as DATE,MONTH(regiester_date) as month');
            $register_customer->whereDate('regiester_date', '>=', $form_date)
                ->whereDate('regiester_date', '<=', $to_date)->where('store_id', getCurrentStore())->where('regiester_date', '!=', NULL);
            $register_customer = $register_customer->get();

            $new_guest = Customer::selectRaw('customers.*,DATE(last_active) as DATE,MONTH(last_active) as month');
            $new_guest->whereDate('last_active', '>=', $form_date)
                ->whereDate('last_active', '<=', $to_date)->where('store_id', getCurrentStore())->where('regiester_date', '=', NULL);
            $new_guest = $new_guest->get();


            $TotalOrderCount = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month')
                ->whereDate('order_date', '>=', $form_date)
                ->whereDate('order_date', '<=', $to_date)
                
                ->where('store_id', getCurrentStore())
                ->count();

            $totalDuePurchaseorder = 0;
            $grossSaleTotalArray = [];
            $netSaleTotalArray = [];
            $CouponTotalArray = [];
            $ShippingTotalArray = [];
            $OrderTotalArray = [];
            $totalduepurchaseorderArray = [];
            $averageGrossSales = [];
            $PurchasedItemArray = [];
            $totalProductQuantity = 0;
            $PurchasedItemTotal = 0;
            $PurchasedProductItemTotal = 0;
            $TotalgrossSale = 0;
            $TotalNetSale = 0;
            $TotalShippingCharge = 0;
            $TotalCouponAmount = 0;

            $monthLists = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $monthLists = Order::whereDate('order_date', '>=', $form_date)
                ->whereDate('order_date', '<=', $to_date)->where('store_id', getCurrentStore());
            $monthLists = $monthLists->get();

            foreach ($monthLists as $monthLists_date) {
                $data[] = date('y-n-j', strtotime($monthLists_date->order_date));
                $data_month[] = date('Y-m-d', strtotime($monthLists_date->order_date));
            }
            if (!empty($data) && is_array($data)) {
                $List = array_values(array_unique($data));
                $monthList_data = $List;
                $List_month = array_values(array_unique($data_month));
                $monthList = $List_month;
            } else {
                $List = [];
                $monthList_data = [];
                $data_month[] = date('y-n-j');
                $List_month = array_values(array_unique($data_month));
                $monthList = $List_month;
            }

            foreach ($orders as $order) {
                $day = date('y-n-j', strtotime($order->DATE));
                $userTotalArray[$day][] = date('y-n-j', strtotime($order->order_date));

                $netSaleTotalArray[$day][] = (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $grossSaleTotalArray[$day][] = (float) $order->final_price;
                $CouponTotalArray[$day][] = (float) $order['coupon_price'];
                $ShippingTotalArray[$day][] = (float) $order->delivery_price;
                $OrderTotalArray[$day][] = 1;
                $products = json_decode($order['product_json'], true);
                foreach ($products as $product) {
                    $totalProductQuantity = intval($product['qty'] ?? 0);
                    $PurchasedItemTotal += $totalProductQuantity;
                    $PurchasedProductItemTotal += $totalProductQuantity;
                }
                $TotalgrossSale += (float) $order->final_price;
                $TotalNetSale += (float) ($order->final_price - $order->delivery_price - $order->tax_price);
                $TotalCouponAmount += (float) $order['coupon_price'];
                $TotalShippingCharge += (float) $order->delivery_price;
            }

            if (!empty($data) && is_array($data)) {
                foreach ($monthList_data as $month) {
                    $GrossSaleTotal[] = array_key_exists($month, $grossSaleTotalArray) ? array_sum($grossSaleTotalArray[$month]) : 0;
                    $NetSaleTotal[] = array_key_exists($month, $netSaleTotalArray) ? array_sum($netSaleTotalArray[$month]) : 0;
                    $ShippingTotal[] = array_key_exists($month, $ShippingTotalArray) ? array_sum($ShippingTotalArray[$month]) : 0;
                    $CouponTotal[] = array_key_exists($month, $CouponTotalArray) ? array_sum($CouponTotalArray[$month]) : 0;

                    $dailySales = array_key_exists($month, $grossSaleTotalArray) ? $grossSaleTotalArray[$month] : [];
                    $averageGrossSales[] = count($dailySales) > 0 ? (array_sum($dailySales) / count($dailySales)) : 0;

                    $dailyNetSales = array_key_exists($month, $netSaleTotalArray) ? $netSaleTotalArray[$month] : [];
                    $averageNetSales[] = count($dailyNetSales) > 0 ? (array_sum($dailyNetSales) / count($dailyNetSales)) : 0;
                }
            } else {
                $month = date('y-n-j');
                $GrossSaleTotal[] = array_key_exists($month, $grossSaleTotalArray) ? array_sum($grossSaleTotalArray[$month]) : 0;
                $NetSaleTotal[] = array_key_exists($month, $netSaleTotalArray) ? array_sum($netSaleTotalArray[$month]) : 0;
                $ShippingTotal[] = array_key_exists($month, $ShippingTotalArray) ? array_sum($ShippingTotalArray[$month]) : 0;
                $CouponTotal[] = array_key_exists($month, $CouponTotalArray) ? array_sum($CouponTotalArray[$month]) : 0;

                $dailySales = array_key_exists($month, $grossSaleTotalArray) ? $grossSaleTotalArray[$month] : [];
                $averageGrossSales[] = count($dailySales) > 0 ? (array_sum($dailySales) / count($dailySales)) : 0;

                $dailyNetSales = array_key_exists($month, $netSaleTotalArray) ? $netSaleTotalArray[$month] : [];
                $averageNetSales[] = count($dailyNetSales) > 0 ? (array_sum($dailyNetSales) / count($dailyNetSales)) : 0;
            }
        }

        $html = '';
        [$periodLabel] = $this->chartPeriodLabels($request);

        $html = view('reports.order_bar_chart_data', compact(
            'TotalOrder', 'PurchasedProductItemTotal', 'TotalgrossSale', 'currency',
            'TotalNetSale', 'TotalCouponAmount', 'TotalShippingCharge', 'periodLabel'
        ) + [
            'labels' => $monthList ?? [],
            'grossSeries' => $GrossSaleTotal ?? [],
            'netSeries' => $NetSaleTotal ?? [],
            'orderSeries' => $TotalOrderCount ?? [],
            'itemSeries' => $PurchasedItemTotal ?? [],
        ])->render();

        $return['html'] = $html;

        $return['TotalOrderCount'] = $TotalOrderCount;
        $return['NetSaleTotal'] = $NetSaleTotal;
        $return['AverageNetSales'] = $averageNetSales;
        $return['GrossSaleTotal'] = $GrossSaleTotal;
        $return['AverageGrossSales'] = $averageGrossSales;
        $return['PurchasedItemTotal'] = $PurchasedItemTotal;
        $return['ShippingTotal'] = $ShippingTotal;
        $return['CouponTotal'] = $CouponTotal;
        $return['monthList'] = $monthList;
        Session::put('order_bar_chart_report', $return);

        return response()->json($return);
    }

    public function StockReport(Request $request)
    {
        $stock_active_tab = session()->get('stock_active_tab');

        if (empty($stock_active_tab)) {
            $stock_active_tab = 'pills-low-stock-tab';
        }

        return view('reports.stock_report', compact('stock_active_tab'));
    }


    public function OrderSaleProductReport(Request $request)
    {
        $store_id = getStoreById(getCurrentStore());
        $products = Product::where('store_id', getCurrentStore())->pluck('name', 'id');
        return view('reports.sale_by_product', compact('products'));
    }


    public function order_product_reports(Request $request)
    {
        $store_id = getStoreById(getCurrentStore());

        $currency = Utility::GetValueByName('CURRENCY');
        if ($request->chart_data == 'last-month') {
            $selectedProducts = $request->selectedProducts;

            $NetSaleTotal = array_fill(0, 31, 0);
            $PurchasedItemTotal = array_fill(0, 31, 0);
            if (empty($selectedProducts)) {
                /* `Product::all()->where(...)` loaded EVERY tenant's catalogue into memory and
                   filtered it in PHP. `Product` is not in `config/tenancy.php`'s `scoped_models`,
                   so `all()` really is every row in the table; the PHP filter then kept the right
                   ones, so nothing leaked — but a report on a store with ten products read the
                   whole platform's product table to find them. The predicate belongs in SQL. */
                $selectedProducts = Product::where('store_id', getCurrentStore())->pluck('id');
            }
            $PurchasedItem = 0;
            $Totalsale = 0;

            if ($selectedProducts) {
                foreach ($selectedProducts as $productId) {
                    $totalNetSalesArray = [];
                    $totalPurchasedItemsArray = [];

                    $currentYear = date('Y');
                    $previousMonth = date('n') - 1;
                    if ($previousMonth == 0) {
                        $currentYear -= 1;
                        $previousMonth = 12;
                    }

                    $daysInPreviousMonth = cal_days_in_month(CAL_GREGORIAN, $previousMonth, $currentYear);

                    for ($day = 1; $day <= $daysInPreviousMonth; $day++) {

                        $date = "$currentYear-$previousMonth-$day";

                        $startOfDay = date('Y-m-d 00:00:00', strtotime($date));
                        $endOfDay = date('Y-m-d 23:59:59', strtotime($date));

                        $orders = Order::selectRaw('orders.*, DATE(order_date) as DATE, MONTH(order_date) as month')
                            
                            ->where('store_id', getCurrentStore())
                            ->where('order_date', '>=', $startOfDay)
                            ->where('order_date', '<=', $endOfDay)
                            ->get();

                        $totalPurchasedItems = 0;
                        $totalNetSales = 0;

                        foreach ($orders as $order) {
                            $products = json_decode($order->product_json, true);
                            foreach ($products as $product) {
                                if ($product['product_id'] == $productId) {
                                    if (!isset($product['qty']) && isset($product['quantity'])) {
                                        $product['qty'] = $product['quantity'];
                                    }
                                    $totalPurchasedItems += intval($product['qty'] ?? 1);
                                    $PurchasedItem += intval($product['qty'] ?? 1);
                                    $totalNetSales += (float) ($product['final_price']);
                                    $Totalsale += (float) ($product['final_price']);
                                }
                            }
                        }

                        $totalPurchasedItemsArray[] = $totalPurchasedItems;
                        $totalNetSalesArray[] = $totalNetSales;
                    }

                    $PurchasedItemTotal = array_map(
                        function ($a, $b) {
                            return $a + $b;
                        },
                        $PurchasedItemTotal,
                        $totalPurchasedItemsArray
                    );

                    $NetSaleTotal = array_map(
                        function ($a, $b) {
                            return $a + $b;
                        },
                        $NetSaleTotal,
                        $totalNetSalesArray
                    );
                }
            }
            $monthList = $month = $this->getLastMonthDatesFormatted();
        } elseif ($request->chart_data == 'this-month') {
            $selectedProducts = $request->input('selectedProducts', []);

            $NetSaleTotal = array_fill(0, 31, 0);
            $PurchasedItemTotal = array_fill(0, 31, 0);
            $Totalsale = 0;
            $PurchasedItem = 0;
            if (empty($selectedProducts)) {
                /* Scoped in SQL, not in PHP — see the note at the first of these. */
                $selectedProducts = Product::where('store_id', getCurrentStore())->pluck('id');
            }
            if (!empty($selectedProducts)) {
                foreach ($selectedProducts as $productId) {
                    $currentYear = date('Y');
                    $currentMonth = date('n');
                    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $currentMonth, $currentYear);

                    for ($day = 1; $day <= $daysInMonth; $day++) {
                        $startOfDay = "$currentYear-$currentMonth-" . sprintf('%02d', $day);
                        $endOfDay = "$currentYear-$currentMonth-" . sprintf('%02d', $day) . ' 23:59:59';

                        $orders = Order::where('store_id', getCurrentStore())
                            ->whereBetween('order_date', [$startOfDay, $endOfDay])
                            ->get();

                        $totalPurchasedItems = 0;
                        $totalNetSales = 0;

                        foreach ($orders as $order) {
                            $products = json_decode($order->product_json, true);
                            foreach ($products as $product) {
                                if ($product['product_id'] == $productId) {
                                    if (!isset($product['qty']) && isset($product['quantity'])) {
                                        $product['qty'] = $product['quantity'];
                                    }
                                    $totalPurchasedItems += intval($product['qty'] ?? 1);
                                    $PurchasedItem += intval($product['qty'] ?? 1);
                                    $totalNetSales += (float) ($product['final_price']);
                                    $Totalsale += (float) ($product['final_price']);
                                }
                            }
                        }

                        $PurchasedItemTotal[$day - 1] += $totalPurchasedItems;
                        $NetSaleTotal[$day - 1] += $totalNetSales;
                    }
                }
            }

            $monthList = $month = $this->getCurrentMonthDates();
        } elseif ($request->chart_data == 'seven-day') {
            $selectedProducts = $request->selectedProducts;

            $NetSaleTotal = [];
            $PurchasedItemTotal = [];
            $monthList = [];
            $Totalsale = 0;
            $PurchasedItem = 0;
            if ($selectedProducts) {
                for ($i = 6; $i >= 0; $i--) {
                    $totalPurchasedItems = 0;
                    $totalNetSales = 0;

                    $currentDate = date('Y-m-d', strtotime("-$i days"));
                    $startOfDay = $currentDate . ' 00:00:00';
                    $endOfDay = $currentDate . ' 23:59:59';

                    $monthList[] = date('d-M', strtotime($currentDate));

                    $orders = Order::whereBetween('order_date', [$startOfDay, $endOfDay])
                        
                        ->where('store_id', getCurrentStore())
                        ->get();

                    foreach ($orders as $order) {
                        $products = json_decode($order->product_json, true);
                        foreach ($products as $product) {

                            if (in_array($product['product_id'], $selectedProducts)) {
                                if (!isset($product['qty']) && isset($product['quantity'])) {
                                    $product['qty'] = $product['quantity'];
                                }
                                $totalPurchasedItems += intval($product['qty'] ?? 1);
                                $PurchasedItem += intval($product['qty'] ?? 1);
                                $totalNetSales += (float) ($product['final_price']);
                                $Totalsale += (float) ($product['final_price']);
                            }
                        }
                    }
                    $PurchasedItemTotal[] = $totalPurchasedItems;
                    $NetSaleTotal[] = $totalNetSales;
                }
            } else {
                $Totalsale = 0;
                $PurchasedItem = 0;
                for ($i = 6; $i >= 0; $i--) {
                    $totalPurchasedItems = 0;
                    $totalNetSales = 0;

                    $currentDate = date('Y-m-d', strtotime("-$i days"));
                    $startOfDay = $currentDate . ' 00:00:00';
                    $endOfDay = $currentDate . ' 23:59:59';

                    $monthList[] = date('d-M', strtotime($currentDate));

                    $orders = Order::whereBetween('order_date', [$startOfDay, $endOfDay])
                        
                        ->where('store_id', getCurrentStore())
                        ->get();

                    foreach ($orders as $order) {
                        $products = json_decode($order->product_json, true);
                        foreach ($products as $product) {
                            if (!isset($product['qty']) && isset($product['quantity'])) {
                                $product['qty'] = $product['quantity'];
                            }
                            $totalPurchasedItems += intval($product['qty'] ?? 1);
                            $PurchasedItem += intval($product['qty'] ?? 1);
                            $totalNetSales += (float) ($product['final_price']);
                            $Totalsale += (float) ($product['final_price']);
                        }
                    }
                    $PurchasedItemTotal[] = $totalPurchasedItems;
                    $NetSaleTotal[] = $totalNetSales;
                }
            }
        } elseif ($request->chart_data == 'year') {
            $selectedProducts = $request->selectedProducts;

            $NetSaleTotal = [];
            $PurchasedItemTotal = [];
            if (empty($selectedProducts)) {
                /* Scoped in SQL, not in PHP — see the note at the first of these. */
                $selectedProducts = Product::where('store_id', getCurrentStore())->pluck('id');
            }
            $Totalsale = 0;
            $PurchasedItem = 0;
            if ($selectedProducts) {
                foreach ($selectedProducts as $productId) {
                    $totalNetSalesArray = [];
                    $totalPurchasedItemsArray = [];

                    for ($i = 1; $i <= 12; $i++) {
                        $totalPurchasedItems = 0;
                        $totalNetSales = 0;

                        $startOfMonth = date('Y-m-01', strtotime(date('Y') . '-' . $i . '-01'));
                        $endOfMonth = date('Y-m-t', strtotime(date('Y') . '-' . $i . '-01'));

                        $orders = Order::where('store_id', getCurrentStore())
                            ->where('order_date', '>=', $startOfMonth)
                            ->where('order_date', '<=', $endOfMonth)
                            ->get();

                        foreach ($orders as $order) {
                            $products = json_decode($order->product_json, true);
                            if (!is_array($products)) {
                                continue;
                            }
                            foreach ($products as $product) {
                                if (!is_array($product) || !isset($product['product_id'])) {
                                    continue;
                                }
                                if ($product['product_id'] == $productId) {
                                    if (!isset($product['qty']) && isset($product['quantity'])) {
                                        $product['qty'] = $product['quantity'];
                                    }
                                    $totalPurchasedItems += intval($product['qty'] ?? 1);
                                    $PurchasedItem += intval($product['qty'] ?? 1);
                                    $totalNetSales += (float) ($product['final_price']);
                                    $Totalsale += (float) ($product['final_price']);
                                }
                            }
                        }

                        $totalPurchasedItemsArray[] = $totalPurchasedItems;
                        $totalNetSalesArray[] = $totalNetSales;
                    }

                    $PurchasedItemTotal = array_map(
                        function ($a, $b) {
                            return $a + $b;
                        },
                        $PurchasedItemTotal,
                        $totalPurchasedItemsArray
                    );

                    $NetSaleTotal = array_map(
                        function ($a, $b) {
                            return $a + $b;
                        },
                        $NetSaleTotal,
                        $totalNetSalesArray
                    );
                }
            }
            $monthList = $month = $this->yearMonth();
        } else {
            $selectedProducts = $request->selectedProducts;

            // Parse date range from request
            if (str_contains($request->Date, ' to ')) {
                $date_range = explode(' to ', $request->Date);
                if (count($date_range) === 2) {
                    $from_date = date('Y-m-d', strtotime($date_range[0]));
                    $to_date = date('Y-m-d', strtotime($date_range[1]));
                } else {
                    $from_date = date('Y-m-d', strtotime($date_range[0]));
                    $to_date = date('Y-m-d', strtotime($date_range[0]));
                }
            } else {
                $from_date = date('Y-m-d', strtotime($request->Date));
                $to_date = date('Y-m-d', strtotime($request->Date));
            }

            $Totalsale = 0;
            $PurchasedItem = 0;

            // Fetch orders within the selected date range
            $orders = Order::selectRaw('orders.*, DATE(order_date) as DATE, MONTH(order_date) as month')
                ->whereDate('order_date', '>=', $from_date)
                ->whereDate('order_date', '<=', $to_date)
                
                ->where('store_id', getCurrentStore())
                ->get();

            $netSaleTotalArray = [];
            $PurchasedItemArray = [];
            $PurchasedProductItemTotal = 0;
            $TotalNetSale = 0;
            $monthList = [];

            // Fill $monthList with all days between $from_date and $to_date
            $interval = new \DateInterval('P1D');
            $startDate = new \DateTime($from_date);
            $endDate = new \DateTime($to_date);
            $period = new \DatePeriod($startDate, $interval, $endDate->modify('+1 day')); // Include end date

            // Initialize monthList with proper day-month format
            foreach ($period as $date) {
                $dayInMD = $date->format('j-M'); // Format as '1-Sep', '2-Sep', etc.
                $dayInYMD = $date->format('Y-m-d'); // Keep 'Y-m-d' for data access
                $monthList[$dayInYMD] = $dayInMD; // Store both formats
                $netSaleTotalArray[$dayInYMD] = 0; // Initialize net sale total for each date
                $PurchasedItemArray[$dayInYMD] = 0; // Initialize purchased item count for each date
            }

            foreach ($orders as $order) {
                $dayInYMD = date('Y-m-d', strtotime($order->DATE)); // Get the order date in Y-m-d format

                $products = json_decode($order->product_json, true);
                foreach ($products as $product) {
                    if (!$selectedProducts || in_array($product['product_id'], $selectedProducts)) {
                        if (!isset($product['qty']) && isset($product['quantity'])) {
                            $product['qty'] = $product['quantity'];
                        }
                        $totalProductQuantity = intval($product['qty'] ?? 1);
                        $PurchasedItemArray[$dayInYMD] += $totalProductQuantity; // Update purchased item count
                        $PurchasedProductItemTotal += $totalProductQuantity; // Update total purchased item count
                        $TotalNetSale += (float) $product['final_price']; // Update total net sale
                        $netSaleTotalArray[$dayInYMD] += (float) $product['final_price']; // Update net sale for the date
                        $Totalsale += (float) $product['final_price']; // Update grand total sale
                    }
                }
            }

            // Prepare data for charts
            $NetSaleTotal = [];
            $PurchasedItemTotal = [];

            // Use monthList for x-axis labels
            foreach ($monthList as $dayInYMD => $dayInMD) {
                $NetSaleTotal[] = $netSaleTotalArray[$dayInYMD] ?? 0; // Use Y-m-d for data access
                $PurchasedItemTotal[] = $PurchasedItemArray[$dayInYMD] ?? 0; // Use Y-m-d for data access
            }
            $monthListConvert = [];
            foreach ($period as $date) {
                // Format the date as "d-M"
                $formattedDate = $date->format('j-M'); // 'j' for day without leading zeros, 'M' for short month name
                $monthListConvert[] = strtolower($formattedDate); // Convert to lowercase
            }
            $monthList=$monthListConvert;

        }
        $html = '';
        [$periodLabel] = $this->chartPeriodLabels($request);
        /* The SERIES go into the view as well, so the chart can ship a real table instead of
           an ApexCharts container with nothing behind it. They were already being returned to
           the browser in the JSON below; the view simply never saw them. */
        $html = view('reports.order_product_chart', compact('currency', 'Totalsale', 'PurchasedItem', 'periodLabel')
            + ['labels' => $monthList ?? [], 'netSeries' => $NetSaleTotal ?? [], 'itemSeries' => $PurchasedItemTotal ?? []])->render();

        $return['html'] = $html;

        $return['NetSaleTotal'] = $NetSaleTotal;
        $return['PurchasedItemTotal'] = $PurchasedItemTotal;
        $return['monthList'] = $monthList;
        Session::put('product_order_report', $return);

        return response()->json($return);
    }

    public function product_order_export(Request $request)
    {

        $requests_data = Session::get('product_order_report');
        $return['monthList'] = $requests_data['monthList'];
        $return['NetSaleTotal'] = $requests_data['NetSaleTotal'];
        $return['PurchasedItemTotal'] = $requests_data['PurchasedItemTotal'];
        return response()->json($return);
    }

    public function OrderSaleCategoryReport(Request $request)
    {
        $store_id = getStoreById(getCurrentStore());
        $MainCategoryList = Category::where('status', 1)->where('store_id', getCurrentStore())->pluck('name', 'id');
        return view('reports.sale_by_category', compact('MainCategoryList'));
    }

    public function order_category_reports(Request $request)
    {
        $store_id = getStoreById(getCurrentStore());
        $currency = Utility::GetValueByName('CURRENCY');
        if ($request->chart_data == 'last-month') {
            $selectedCategory = $request->selectedCategory;
            $year = date('Y');
            $monthList = $this->yearMonth();
            $NetSaleTotal = [];

            $firstDayOfPreviousMonth = date('Y-m-d', strtotime('first day of last month'));
            $lastDayOfPreviousMonth = date('Y-m-d', strtotime('last day of last month'));
            $daysInPreviousMonth = date('j', strtotime('last day of last month'));

            if (empty($selectedCategory)) {
                /* Scoped in SQL, not in PHP — see the note at the first of these. */
                $selectedCategory = Category::where('store_id', getCurrentStore())->pluck('id');
            }

            $categoryNetSales = [];

            foreach ($selectedCategory as $categoryId) {
                $category = Category::find($categoryId);
                if ($category) {
                    $categoryNetSales[$category->name] = array_fill(0, $daysInPreviousMonth, 0);
                }
            }

            $orders = Order::where('store_id', getCurrentStore())
                ->whereYear('order_date', $year)
                ->whereBetween('order_date', [$firstDayOfPreviousMonth, $lastDayOfPreviousMonth])
                ->get();
            $NetSalesofcategory = [];
            foreach ($selectedCategory as $categoryId) {
                $category = Category::find($categoryId);
                if (!$category) {
                    continue;
                }

                foreach ($orders as $order) {
                    $orderDate = date('j', strtotime($order->order_date));

                    $products = json_decode($order->product_json, true);

                    foreach ($products as $product) {
                        $product_data = Product::find($product['product_id']);

                        if ($product_data && $product_data->category_id == $categoryId) {
                            $totalProductQuantity = intval(($product['qty'] ?? ($product['quantity'] ?? '1')));
                            $categoryNetSales[$category->name][$orderDate - 1] += (float) ($product['final_price']);
                        }
                    }
                }

                $NetSalesofcategory[$category->name] = array_sum($categoryNetSales[$category->name]);
            }

            $allCategoriesTotal = array_fill(0, $daysInPreviousMonth, 0);

            foreach ($categoryNetSales as $categoryName => $categoryArray) {
                $NetSaleTotal[] = [
                    'name' => $categoryName,
                    'data' => $categoryArray,
                ];

                $allCategoriesTotal = array_map(
                    function ($a, $b) {
                        return $a + $b;
                    },
                    $allCategoriesTotal,
                    $categoryArray
                );
            }
            if (!$request->selectedCategory) {
                $NetSaleTotal[] = [
                    'name' => 'All Categories',
                    'data' => $allCategoriesTotal,
                ];
            }
            $monthList = $month = $this->getLastMonthDatesFormatted();
        } elseif ($request->chart_data == 'this-month') {
            $selectedCategory = $request->selectedCategory;
            $year = date('Y');
            $monthList = $this->yearMonth();
            $NetSaleTotal = [];

            $daysInCurrentMonth = date('j', strtotime('last day of this month'));

            if (empty($selectedCategory)) {
                /* Scoped in SQL, not in PHP — see the note at the first of these. */
                $selectedCategory = Category::where('store_id', getCurrentStore())->pluck('id');
            }

            $categoryNetSales = [];

            foreach ($selectedCategory as $categoryId) {
                $category = Category::find($categoryId);
                if ($category) {
                    $categoryNetSales[$category->name] = array_fill(0, $daysInCurrentMonth, 0);
                }
            }

            $orders = Order::where('store_id', getCurrentStore())
                ->whereYear('order_date', $year)
                ->whereMonth('order_date', date('m'))
                ->get();

            foreach ($selectedCategory as $categoryId) {
                $category = Category::find($categoryId);
                if (!$category) {
                    continue;
                }

                foreach ($orders as $order) {
                    $orderDay = date('j', strtotime($order->order_date));

                    $products = json_decode($order->product_json, true);

                    foreach ($products as $product) {
                        $product_data = Product::find($product['product_id']);

                        if ($product_data && $product_data->category_id == $categoryId) {
                            $totalProductQuantity = intval(($product['qty'] ?? ($product['quantity'] ?? '1')));
                            $categoryNetSales[$category->name][$orderDay - 1] += (float) ($product['final_price']);
                        }
                    }
                }
            }

            $allCategoriesTotal = array_fill(0, $daysInCurrentMonth, 0);
            $NetSalesofcategory = [];
            foreach ($categoryNetSales as $categoryName => $categoryArray) {
                $NetSaleTotal[] = [
                    'name' => $categoryName,
                    'data' => $categoryArray,
                ];
                $totalNetSale = array_sum($categoryArray);
                $NetSalesofcategory[$categoryName] = $totalNetSale;
                $allCategoriesTotal = array_map(
                    function ($a, $b) {
                        return $a + $b;
                    },
                    $allCategoriesTotal,
                    $categoryArray
                );
            }

            if (!$request->selectedCategory) {
                $NetSaleTotal[] = [
                    'name' => 'All Categories',
                    'data' => $allCategoriesTotal,
                ];
            }

            $monthList = $month = $this->getCurrentMonthDates();
        } elseif ($request->chart_data == 'seven-day') {
            $selectedCategory = $request->selectedCategory;
            $year = date('Y');
            $NetSaleTotal = [];
            $currentDate = date('Y-m-d');
            $sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));

            $monthList = [];
            for ($i = 0; $i < 7; $i++) {
                $monthList[] = date('d-M', strtotime("-$i days", strtotime($currentDate)));
            }

            if (empty($selectedCategory)) {
                $selectedCategory = Category::where('store_id', getCurrentStore())
                    
                    ->pluck('id');
            }

            $categoryNetSales = [];

            foreach ($selectedCategory as $categoryId) {
                $category = Category::find($categoryId);
                if ($category) {
                    $categoryNetSales[$category->name] = array_fill(0, 7, 0);
                }
            }

            $orders = Order::where('store_id', getCurrentStore())
                ->whereDate('order_date', '>=', $sevenDaysAgo)
                ->get();

            foreach ($selectedCategory as $categoryId) {
                $category = Category::find($categoryId);
                if (!$category) {
                    continue;
                }

                foreach ($orders as $order) {
                    $orderDate = date('Y-m-d', strtotime($order->order_date));

                    $products = json_decode($order->product_json, true);

                    foreach ($products as $product) {
                        $product_data = Product::find($product['product_id']);

                        if ($product_data && $product_data->category_id == $categoryId) {
                            $totalProductQuantity = intval(($product['qty'] ?? ($product['quantity'] ?? '1')));
                            $daysAgo = date_diff(date_create($currentDate), date_create($orderDate))->days;

                            if ($daysAgo >= 0 && $daysAgo < 7) {
                                $categoryNetSales[$category->name][$daysAgo] += (float) ($product['final_price']);
                            }
                        }
                    }
                }
            }

            $allCategoriesTotal = array_fill(0, 7, 0);
            $NetSalesofcategory = [];
            foreach ($categoryNetSales as $categoryName => $categoryArray) {
                $NetSaleTotal[] = [
                    'name' => $categoryName,
                    'data' => $categoryArray,
                ];
                $totalNetSale = array_sum($categoryArray);

                $NetSalesofcategory[$categoryName] = $totalNetSale;
                $allCategoriesTotal = array_map(
                    function ($a, $b) {
                        return $a + $b;
                    },
                    $allCategoriesTotal,
                    $categoryArray
                );
            }

            if (!$request->selectedCategory) {
                $NetSaleTotal[] = [
                    'name' => 'All Categories',
                    'data' => $allCategoriesTotal,
                ];
            }
        } elseif ($request->chart_data == 'year') {

            $selectedCategory = $request->selectedCategory;
            $year = date('Y');
            $monthList = $this->yearMonth();
            $NetSaleTotal = [];
            $NetSalesofcategory = [];

            $totalNetSalesAllCategories = array_fill(0, count($monthList), 0);

            if (empty($selectedCategory)) {
                /* Scoped in SQL, not in PHP — see the note at the first of these. */
                $selectedCategory = Category::where('store_id', getCurrentStore())->pluck('id');
            }

            $categoryNetSales = [];

            foreach ($selectedCategory as $categoryId) {
                $category = Category::find($categoryId);
                if ($category) {
                    $categoryNetSales[$category->name] = array_fill(0, count($monthList), 0);
                }
            }

            $orders = Order::where('store_id', getCurrentStore())
                ->whereYear('order_date', $year)
                ->get();

            foreach ($monthList as $index => $month) {
                foreach ($selectedCategory as $categoryId) {
                    $category = Category::find($categoryId);
                    if (!$category) {
                        continue;
                    }

                    $totalNetSales = 0;

                    foreach ($orders as $order) {
                        $products = json_decode($order->product_json, true);
                        if (!is_array($products)) {
                            continue;
                        }
                        foreach ($products as $product) {
                            if (!is_array($product) || !isset($product['product_id'])) {
                                continue;
                            }
                            $product_data = Product::find($product['product_id']);

                            if ($product_data && $product_data->category_id == $categoryId) {
                                $orderDate = date('Y-m', strtotime($order->order_date));
                                if ($orderDate == date('Y-m', strtotime($year . '-' . ($index + 1)))) {
                                    // $totalProductQuantity = intval(($product['qty'] ?? ($product['quantity'] ?? '1')));
                                    $totalNetSales += (float) ($product['final_price']);
                                }
                            }
                        }
                    }

                    $categoryNetSales[$category->name][$index] = $totalNetSales;

                    $totalNetSalesAllCategories[$index] += $totalNetSales;

                    if (!isset($NetSalesofcategory[$category->name])) {
                        $NetSalesofcategory[$category->name] = 0;
                    }
                    $NetSalesofcategory[$category->name] += $totalNetSales;
                }
            }

            foreach ($categoryNetSales as $categoryName => $categoryArray) {
                $NetSaleTotal[] = [
                    'name' => $categoryName,
                    'data' => $categoryArray,
                ];
            }

            if (!$request->selectedCategory) {
                $NetSaleTotal[] = [
                    'name' => 'All Categories',
                    'data' => $totalNetSalesAllCategories,
                ];
            }
        } else {
            if (str_contains($request->Date, ' to ')) {
                $date_range = explode(' to ', $request->Date);
                if (count($date_range) === 2) {
                    $from_date = date('Y-m-d', strtotime($date_range[0]));
                    $to_date = date('Y-m-d', strtotime($date_range[1]));
                } else {
                    $from_date = date('Y-m-d', strtotime($date_range[0]));
                    $to_date = date('Y-m-d', strtotime($date_range[0]));
                }
            } else {
                $from_date = date('Y-m-d', strtotime($request->Date));
                $to_date = date('Y-m-d', strtotime($request->Date));
            }

            $selectedCategory = $request->selectedCategory;
            $year = date('Y');
            $NetSaleTotal = [];

            $startDate = new \DateTime($from_date);
            $endDate = new \DateTime($to_date);

            $interval = new \DateInterval('P1D');
            $period = new \DatePeriod($startDate, $interval, $endDate);

            $monthList = [];

            foreach ($period as $date) {
                $monthList[] = $date->format('d-M');
            }

            if (empty($selectedCategory)) {
                $selectedCategory = Category::where('store_id', getCurrentStore())
                    
                    ->pluck('id');
            }

            $categoryNetSales = [];

            foreach ($selectedCategory as $categoryId) {
                $category = Category::find($categoryId);
                if ($category) {
                    $categoryNetSales[$category->name] = array_fill(0, count($monthList), 0);
                }
            }

            $orders = Order::where('store_id', getCurrentStore())
                ->whereDate('order_date', '>=', $from_date)
                ->whereDate('order_date', '<=', $to_date)
                ->get();

            foreach ($selectedCategory as $categoryId) {
                $category = Category::find($categoryId);
                if (!$category) {
                    continue;
                }

                foreach ($orders as $order) {
                    $orderDate = date('d-M', strtotime($order->order_date));

                    $products = json_decode($order->product_json, true);

                    foreach ($products as $product) {
                        $product_data = Product::find($product['product_id']);

                        if ($product_data && $product_data->category_id == $categoryId) {
                            $totalProductQuantity = intval(($product['qty'] ?? ($product['quantity'] ?? '1')));
                            $monthIndex = array_search($orderDate, $monthList);

                            if ($monthIndex !== false) {
                                $categoryNetSales[$category->name][$monthIndex] += (float) ($product['final_price']);
                            }
                        }
                    }
                }
            }

            $allCategoriesTotal = array_fill(0, count($monthList), 0);
            $NetSalesofcategory = [];
            foreach ($categoryNetSales as $categoryName => $categoryArray) {
                $NetSaleTotal[] = [
                    'name' => $categoryName,
                    'data' => $categoryArray,
                ];

                $totalNetSale = array_sum($categoryArray);

                $NetSalesofcategory[$categoryName] = $totalNetSale;
                $allCategoriesTotal = array_map(
                    function ($a, $b) {
                        return $a + $b;
                    },
                    $allCategoriesTotal,
                    $categoryArray
                );
            }

            if (!$request->selectedCategory) {
                $NetSaleTotal[] = [
                    'name' => 'All Categories',
                    'data' => $allCategoriesTotal,
                ];
            }
        }
        $html = '';
        [$periodLabel] = $this->chartPeriodLabels($request);
        $html = view('reports.order_category_chart', compact('currency', 'NetSalesofcategory', 'periodLabel')
            + ['labels' => $monthList ?? [], 'netSeries' => $NetSaleTotal ?? []])->render();

        $return['html'] = $html;

        $return['NetSaleTotal'] = $NetSaleTotal;
        $return['monthList'] = $monthList;
        Session::put('category_order_report', $return);

        return response()->json($return);
    }

    public function category_order_export(Request $request)
    {
        $requests_data = Session::get('category_order_report');
        $return['monthList'] = $requests_data['monthList'] ?? '';
        $return['NetSaleTotal'] = $requests_data['NetSaleTotal'] ?? '';
        return response()->json($return);
    }

    public function OrderDownlodableReport(SalesDownloadableProductDataTable $dataTable, Request $request)
    {
        return $dataTable->render('reports.downloadable_product_report');
    }

    public function OrderSaleBrandReport(Request $request)
    {
        $store_id = getStoreById(getCurrentStore());
        $MainCategoryList = ProductBrand::where('status', 1)->where('store_id', getCurrentStore())->pluck('name', 'id');

        return view('reports.sale_by_brand', compact('MainCategoryList'));
    }

    public function order_brand_reports(Request $request)
    {
        $store_id = getStoreById(getCurrentStore());
        $currency = Utility::GetValueByName('CURRENCY');
        $selectedBrand = $request->selectedBrand;
        $year = date('Y');
        $monthList = $this->yearMonth();
        $NetSaleTotal = [];
        $NetSalesofbrand = []; // Initialize the variable here

        if ($request->chart_data == 'last-month') {
            $firstDayOfPreviousMonth = date('Y-m-01', strtotime('-1 month'));
            $lastDayOfPreviousMonth = date('Y-m-t', strtotime('-1 month'));
            $daysInPreviousMonth = date('j', strtotime($lastDayOfPreviousMonth));

            $selectedBrand = $selectedBrand ?? ProductBrand::where('store_id', getCurrentStore())
                
                ->pluck('id');

            $brandNetSales = [];

            foreach ($selectedBrand as $brandId) {
                $brand = ProductBrand::find($brandId);
                if ($brand) {
                    $brandNetSales[$brand->name] = array_fill(0, $daysInPreviousMonth, 0);
                }
            }

            $orders = Order::where('store_id', getCurrentStore())
                ->whereYear('order_date', date('Y', strtotime($firstDayOfPreviousMonth)))
                ->whereMonth('order_date', date('m', strtotime($firstDayOfPreviousMonth)))
                ->get();

            foreach ($selectedBrand as $brandId) {
                $brand = ProductBrand::find($brandId);
                if (!$brand) {
                    continue;
                }

                foreach ($orders as $order) {
                    $orderDate = date('j', strtotime($order->order_date));

                    $products = json_decode($order->product_json, true);

                    foreach ($products as $product) {
                        $product_data = Product::find($product['product_id']);

                        if ($product_data && $product_data->brand_id == $brandId) {
                            $brandNetSales[$brand->name][$orderDate - 1] += (float) $product['final_price'];
                        }
                    }
                }

                $NetSalesofbrand[$brand->name] = array_sum($brandNetSales[$brand->name]);
            }

            $allBrandsTotal = array_fill(0, $daysInPreviousMonth, 0);
            foreach ($brandNetSales as $brandName => $brandArray) {
                $NetSaleTotal[] = [
                    'name' => $brandName,
                    'data' => $brandArray,
                ];

                $allBrandsTotal = array_map(
                    function ($a, $b) {
                        return $a + $b;
                    },
                    $allBrandsTotal,
                    $brandArray
                );
            }

            if (!$request->selectedBrand) {
                $NetSaleTotal[] = [
                    'name' => 'All Brands',
                    'data' => $allBrandsTotal,
                ];
            }
            $NetSalesofbrand['All Brands'] = array_sum($allBrandsTotal);
            $monthList = $month = $this->getLastMonthDatesFormatted();
        } elseif ($request->chart_data == 'this-month') {
            $firstDayOfCurrentMonth = date('Y-m-01');
            $lastDayOfCurrentMonth = date('Y-m-t');
            $daysInPreviousMonth = date('j', strtotime($lastDayOfCurrentMonth));

            $selectedBrand = $selectedBrand ?? ProductBrand::where('store_id', getCurrentStore())
                
                ->pluck('id');

            $brandNetSales = [];

            foreach ($selectedBrand as $brandId) {
                $brand = ProductBrand::find($brandId);
                if ($brand) {
                    $brandNetSales[$brand->name] = array_fill(0, $daysInPreviousMonth, 0);
                }
            }

            $orders = Order::where('store_id', getCurrentStore())
                ->whereYear('order_date', date('Y'))
                ->whereMonth('order_date', date('m'))
                ->get();

            foreach ($selectedBrand as $brandId) {
                $brand = ProductBrand::find($brandId);
                if (!$brand) {
                    continue;
                }

                foreach ($orders as $order) {
                    $orderDate = date('j', strtotime($order->order_date));

                    $products = json_decode($order->product_json, true);

                    foreach ($products as $product) {
                        $product_data = Product::find($product['product_id']);

                        if ($product_data && $product_data->brand_id == $brandId) {
                            $brandNetSales[$brand->name][$orderDate - 1] += (float) $product['final_price'];
                        }
                    }
                }

                $NetSalesofbrand[$brand->name] = array_sum($brandNetSales[$brand->name]);
            }

            $allBrandsTotal = array_fill(0, $daysInPreviousMonth, 0);

            foreach ($brandNetSales as $brandName => $brandArray) {
                $NetSaleTotal[] = [
                    'name' => $brandName,
                    'data' => $brandArray,
                ];

                $allBrandsTotal = array_map(
                    function ($a, $b) {
                        return $a + $b;
                    },
                    $allBrandsTotal,
                    $brandArray
                );
            }
            if (!$request->selectedBrand) {
                $NetSaleTotal[] = [
                    'name' => 'All Brands',
                    'data' => $allBrandsTotal,
                ];
            }
            $NetSalesofbrand['All Brands'] = array_sum($allBrandsTotal);
            $monthList = $month = $this->getCurrentMonthDates();
        } elseif ($request->chart_data == 'year') {
            $selectedBrand = $request->selectedBrand;
            $year = date('Y');
            $monthList = $this->yearMonth(); // Assuming this method returns an array of month names or month start dates
            $NetSaleTotal = [];
            $NetSalesOfBrand = [];
            $totalNetSalesAllBrands = array_fill(0, count($monthList), 0);

            if (empty($selectedBrand)) {
                $selectedBrand = ProductBrand::where('store_id', getCurrentStore())
                    
                    ->pluck('id');
            }

            $brandNetSales = [];

            foreach ($selectedBrand as $brandId) {
                $brand = ProductBrand::find($brandId);
                if ($brand) {
                    $brandNetSales[$brand->name] = array_fill(0, count($monthList), 0);
                }
            }

            $orders = Order::where('store_id', getCurrentStore())
                ->whereYear('order_date', $year)
                ->get();

            foreach ($monthList as $index => $month) {
                foreach ($selectedBrand as $brandId) {
                    $brand = ProductBrand::find($brandId);
                    if (!$brand) {
                        continue;
                    }

                    $totalNetSales = 0;

                    foreach ($orders as $order) {
                        $orderDate = date('Y-m', strtotime($order->order_date));
                        if ($orderDate == date('Y-m', strtotime($year . '-' . ($index + 1)))) {
                            $products = json_decode($order->product_json, true);
                            if (!is_array($products)) {
                                continue;
                            }
                            foreach ($products as $product) {
                                if (!is_array($product) || !isset($product['product_id'])) {
                                    continue;
                                }
                                $product_data = Product::find($product['product_id']);

                                if ($product_data && $product_data->brand_id == $brandId) {
                                    $totalNetSales += (float) $product['final_price'];
                                }
                            }
                        }
                    }
                    $NetSalesofbrand[$brand->name] = array_sum($brandNetSales[$brand->name]);

                    $brandNetSales[$brand->name][$index] = $totalNetSales;
                    $totalNetSalesAllBrands[$index] += $totalNetSales;

                    if (!isset($NetSalesOfBrand[$brand->name])) {
                        $NetSalesOfBrand[$brand->name] = 0;
                    }
                    $NetSalesOfBrand[$brand->name] += $totalNetSales;
                }
            }
            foreach ($brandNetSales as $brandName => $brandArray) {
                $NetSaleTotal[] = [
                    'name' => $brandName,
                    'data' => $brandArray,
                ];

                $totalNetSalesAllBrands = array_map(
                    function ($a, $b) {
                        return $a + $b;
                    },
                    $totalNetSalesAllBrands,
                    $brandArray
                );
            }


            if (!$request->selectedBrand) {
                $NetSaleTotal[] = [
                    'name' => 'All Brands',
                    'data' => $totalNetSalesAllBrands,
                ];
            }

            $NetSalesofbrand['All Brands'] = array_sum($totalNetSalesAllBrands);
        } elseif ($request->chart_data == 'seven-day') {
            $selectedBrand = $request->selectedBrand;
            $currentDate = date('Y-m-d');
            $sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));

            // Generate a list of the last 7 days
            $dayList = [];
            for ($i = 6; $i >= 0; $i--) {
                $dayList[] = date('d-M', strtotime("-$i days", strtotime($currentDate)));
            }

            $NetSaleTotal = [];
            $NetSalesOfBrand = [];
            $totalNetSalesAllBrands = array_fill(0, 7, 0);

            if (empty($selectedBrand)) {
                $selectedBrand = ProductBrand::where('store_id', getCurrentStore())
                    
                    ->pluck('id');
            }

            $brandNetSales = [];

            foreach ($selectedBrand as $brandId) {
                $brand = ProductBrand::find($brandId);
                if ($brand) {
                    $brandNetSales[$brand->name] = array_fill(0, 7, 0);
                }
            }

            $orders = Order::where('store_id', getCurrentStore())
                ->whereDate('order_date', '>=', $sevenDaysAgo)
                ->get();

            foreach ($selectedBrand as $brandId) {
                $brand = ProductBrand::find($brandId);
                if (!$brand) {
                    continue;
                }

                foreach ($orders as $order) {
                    $orderDate = date('Y-m-d', strtotime($order->order_date));
                    $daysAgo = (strtotime($currentDate) - strtotime($orderDate)) / (60 * 60 * 24);

                    if ($daysAgo >= 0 && $daysAgo < 7) {
                        $products = json_decode($order->product_json, true);

                        foreach ($products as $product) {
                            $product_data = Product::find($product['product_id']);
                            if ($product_data && $product_data->brand_id == $brandId) {
                                $brandNetSales[$brand->name][$daysAgo] += (float) $product['final_price'];
                            }
                        }
                    }
                }

                $NetSalesofbrand[$brand->name] = array_sum($brandNetSales[$brand->name]);
            }

            foreach ($brandNetSales as $brandName => $brandArray) {
                $NetSaleTotal[] = [
                    'name' => $brandName,
                    'data' => array_reverse($brandArray), // Reverse to match the last 7 days order
                ];

                $totalNetSalesAllBrands = array_map(
                    function ($a, $b) {
                        return $a + $b;
                    },
                    $totalNetSalesAllBrands,
                    $brandArray
                );
            }

            if (empty($request->selectedBrand)) {
                $NetSaleTotal[] = [
                    'name' => 'All Brands',
                    'data' => array_reverse($totalNetSalesAllBrands), // Reverse to match the last 7 days order
                ];
            }
            $NetSalesofbrand['All Brands'] = array_sum($totalNetSalesAllBrands);
            $monthList = $dayList; // Use dayList to represent the last 7 days
        } else {
            if (str_contains($request->Date, ' to ')) {
                $date_range = explode(' to ', $request->Date);
                if (count($date_range) === 2) {
                    $from_date = date('Y-m-d', strtotime($date_range[0]));
                    $to_date = date('Y-m-d', strtotime($date_range[1]));
                } else {
                    $from_date = date('Y-m-d', strtotime($date_range[0]));
                    $to_date = date('Y-m-d', strtotime($date_range[0]));
                }
            } else {
                $from_date = date('Y-m-d', strtotime($request->Date));
                $to_date = date('Y-m-d', strtotime($request->Date));
            }

            $selectedBrand = $request->selectedBrand;
            $NetSaleTotal = [];
            $totalNetSalesAllBrands = [];

            $startDate = new \DateTime($from_date);
            $endDate = new \DateTime($to_date);
            $endDate->modify('+1 day'); // Include the end date

            $interval = new \DateInterval('P1D');
            $period = new \DatePeriod($startDate, $interval, $endDate);

            $dayList = [];
            foreach ($period as $date) {
                $dayList[] = $date->format('d-M');
            }

            if (empty($selectedBrand)) {
                $selectedBrand = ProductBrand::where('store_id', getCurrentStore())
                    
                    ->pluck('id');
            }

            $brandNetSales = [];
            foreach ($selectedBrand as $brandId) {
                $brand = ProductBrand::find($brandId);
                if ($brand) {
                    $brandNetSales[$brand->name] = array_fill(0, count($dayList), 0);
                }
            }

            $orders = Order::where('store_id', getCurrentStore())
                ->whereDate('order_date', '>=', $from_date)
                ->whereDate('order_date', '<=', $to_date)
                ->get();

            foreach ($selectedBrand as $brandId) {
                $brand = ProductBrand::find($brandId);
                if (!$brand) {
                    continue;
                }

                foreach ($orders as $order) {
                    $orderDate = date('d-M', strtotime($order->order_date));
                    $dayIndex = array_search($orderDate, $dayList);

                    if ($dayIndex !== false) {
                        $products = json_decode($order->product_json, true);

                        foreach ($products as $product) {
                            $product_data = Product::find($product['product_id']);
                            if ($product_data && $product_data->brand_id == $brandId) {
                                $brandNetSales[$brand->name][$dayIndex] += (float) $product['final_price'];
                            }
                        }
                    }
                }

                $NetSalesofbrand[$brand->name] = array_sum($brandNetSales[$brand->name]);
            }

            foreach ($brandNetSales as $brandName => $brandArray) {
                $NetSaleTotal[] = [
                    'name' => $brandName,
                    'data' => $brandArray,
                ];

                $totalNetSalesAllBrands = array_map(
                    function ($a, $b) {
                        return $a + $b;
                    },
                    $totalNetSalesAllBrands ?: array_fill(0, count($brandArray), 0),
                    $brandArray
                );
            }

            if (empty($request->selectedBrand)) {
                $NetSaleTotal[] = [
                    'name' => 'All Brands',
                    'data' => $totalNetSalesAllBrands,
                ];
            }
            $NetSalesofbrand['All Brands'] = array_sum($totalNetSalesAllBrands);
            $monthList = $dayList; // Use dayList to represent the selected date range
        }
        [$periodLabel] = $this->chartPeriodLabels($request);
        $html = view('reports.order_brand_chart', compact('currency', 'NetSalesofbrand', 'periodLabel')
            + ['labels' => $monthList ?? [], 'netSeries' => $NetSaleTotal ?? []])->render();

        $return = [
            'html' => $html,
            'NetSaleTotal' => $NetSaleTotal,
            'monthList' => $monthList
        ];

        Session::put('brand_order_report', $return);

        return response()->json($return);
    }

    public function brand_order_export(Request $request)
    {
        $requests_data = Session::get('brand_order_report');
        $return = [
            'monthList' => $requests_data['monthList'],
            'NetSaleTotal' => $requests_data['NetSaleTotal']
        ];
        return response()->json($return);
    }

    public function top_product(Request $request)
    {
        $productQuery = Product::where('store_id', getCurrentStore());
        $orderQuery = Order::where('store_id', getCurrentStore());

        [$productNames, $productCounts] = $this->topSellingProducts($orderQuery, $productQuery);

        $top_sales = Category::select(
            'categories.name as sale_name',
            'categories.image_path as sale_image_path',
            \DB::raw('COUNT(DISTINCT orders.id) as total_sale')
        )
            ->join('products', 'categories.id', '=', 'products.category_id')
            ->join('orders', function ($join) {
                $join->on('products.id', '=', \DB::raw("SUBSTRING_INDEX(SUBSTRING_INDEX(orders.product_id, ',', numbers.n), ',', -1)"))
                    ->crossJoin(\DB::raw("(SELECT 1 + a.N + b.N * 10 AS n FROM (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a CROSS JOIN (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS b) AS numbers"));
            })
            ->where('products.store_id', getCurrentStore())
            ->where('orders.store_id', getCurrentStore())
            ->where('categories.store_id', getCurrentStore())
            ->groupBy('categories.name')
            ->get();

        $top_brand_sales = ProductBrand::select('product_brands.name as sale_name', 'product_brands.logo as sale_image_path', \DB::raw('COUNT(DISTINCT orders.id) as total_sale'))
            ->join('products', 'product_brands.id', '=', 'products.brand_id')
            ->join('orders', function ($join) {
                $join->on('products.id', '=', \DB::raw("SUBSTRING_INDEX(SUBSTRING_INDEX(orders.product_id, ',', numbers.n), ',', -1)"))
                    ->crossJoin(\DB::raw("(SELECT 1 + a.N + b.N * 10 AS n FROM (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a CROSS JOIN (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS b) AS numbers"));
            })
            ->where('products.store_id', getCurrentStore())
            ->where('orders.store_id', getCurrentStore())
            ->where('product_brands.store_id', getCurrentStore())
            ->groupBy('product_brands.name')
            ->get();

        $new_orders = (clone $orderQuery)->orderBy('id', 'DESC')->get();

        // Aggregate payment methods
        $paymentMethods = $new_orders->groupBy('payment_type')->map(function ($orders) {
            return $orders->count();
        });
        return view('reports.top_5_reports', [
            'paymentMethods' => $paymentMethods,
            'top_brand_sales' => $top_brand_sales,
            'top_sales' => $top_sales,
            'productNames' => $productNames,
            'productCounts' => $productCounts,
            'periodLabel' => __('All time'),
        ]);
    }

    /**
     * The order-status breakdown.
     *
     * THREE THINGS WERE WRONG, and the first is the one that matters.
     *
     * 1. IT COUNTED SEVEN OF THE NINE STATUSES. The map was a fresh literal `0 => 'Pending' …
     *    6 => 'Shipped'`, while `delivered_status` carries nine values: `ORDER_STATUS_PARTIALLY_PAID`
     *    (7) and `ORDER_STATUS_PRE_ORDER` (8) existed and were simply absent, so for any store
     *    that uses them the pie, the bar and the line each showed a breakdown whose parts did
     *    not sum to the store's order count — and nothing on the screen said so. This is the
     *    same defect `HomeController::getOrderCounts()` already fixed for the dashboard, down to
     *    the `other` catch-all: a value outside 0-8 lands there and stays visible instead of
     *    disappearing. The labels now come from HomeController's constants rather than a second
     *    literal map, so the two cannot drift again.
     *
     * 2. IT STATED NO PERIOD. The query has no date filter at all — it is every order the store
     *    has ever taken — and the screen read as though it were current (PRD-001). It says
     *    "All time" now, because that is what it is.
     *
     * 3. THE LABELS WERE HARDCODED ENGLISH, so an Arabic merchant read English statuses on an
     *    otherwise Arabic page. They go through `__()` now.
     *
     * The wording of status 2 is deliberately `__('Cancel')` and not `__('Cancelled')`, which is
     * the better word: `Order::getDeliveredStatusStringAttribute()` says `Cancel`, and
     * `tests/Feature/ApiContract/OrderStatusTextTest.php` PINS that for the Flutter app. Using
     * the better word here would make the report disagree with the order list and with the app.
     * The wording is a contract question, recorded in the ledger, not this slice's to change.
     */
    /**
     * The five products appearing in the most orders, as two arrays the chart can consume.
     *
     * THE LABELS USED TO BE ATTACHED TO THE WRONG SLICES, and this is extracted so that can be
     * tested: the rest of `top_product()` joins with `SUBSTRING_INDEX`, which SQLite does not
     * have, so the screen cannot be rendered in the suite at all.
     *
     * What was wrong. The ids came out sorted by COUNT DESCENDING; the names then came from
     * `Product::whereIn('id', $ids)->get()->pluck('name')`, which returns rows in whatever order
     * the database gives — id order, in practice — while the counts stayed in count order. The
     * chart paired name[i] with count[j] for two different products, and nothing on the screen
     * could reveal it, because it was a pie in an empty div with no table to check against.
     *
     * Worse, the two arrays could be different LENGTHS: `whereIn` drops an id whose product has
     * been deleted or belongs to another store, while the counts array always had five, so every
     * slice after the gap shifted by one.
     *
     * Both are gone because the name and the count are carried together, keyed by id. The counts
     * are also computed from ONE fetch rather than re-fetching every order inside a loop over
     * five ids — six full table reads for one chart.
     *
     * `$productQuery` is already scoped to the caller's store, which matters: `Product` is NOT in
     * `config/tenancy.php`'s `scoped_models`, so an unscoped `Product::find()` reaches every
     * tenant's catalogue. An id that resolves to no product of this store is skipped rather than
     * named.
     *
     * @return array{0: list<string>, 1: list<int>} [names, counts], index-aligned
     */
    private function topSellingProducts($orderQuery, $productQuery): array
    {
        $countsById = (clone $orderQuery)->pluck('product_id')
            ->flatMap(fn ($ids) => explode(',', (string) $ids))
            ->map(fn ($id) => (int) trim($id))
            ->filter(fn ($id) => $id > 0)
            ->countBy()
            ->sortDesc()
            ->take(5);

        $namesById = (clone $productQuery)->whereIn('id', $countsById->keys()->all())
            ->pluck('name', 'id');

        $names = [];
        $counts = [];
        foreach ($countsById as $id => $count) {
            if (! isset($namesById[$id])) {
                continue;
            }
            $names[] = (string) $namesById[$id];
            $counts[] = (int) $count;
        }

        return [$names, $counts];
    }

    public function showOrderStatusReport()
    {
        $labels = [
            \App\Http\Controllers\HomeController::ORDER_STATUS_PENDING        => __('Pending'),
            \App\Http\Controllers\HomeController::ORDER_STATUS_DELIVERED      => __('Delivered'),
            \App\Http\Controllers\HomeController::ORDER_STATUS_CANCEL         => __('Cancel'),
            \App\Http\Controllers\HomeController::ORDER_STATUS_RETURN         => __('Return'),
            \App\Http\Controllers\HomeController::ORDER_STATUS_CONFIRMED      => __('Confirmed'),
            \App\Http\Controllers\HomeController::ORDER_STATUS_PICKED_UP      => __('Picked Up'),
            \App\Http\Controllers\HomeController::ORDER_STATUS_SHIPPED        => __('Shipped'),
            \App\Http\Controllers\HomeController::ORDER_STATUS_PARTIALLY_PAID => __('Partially Paid'),
            \App\Http\Controllers\HomeController::ORDER_STATUS_PRE_ORDER      => __('Pre Order'),
        ];

        $byStatus = Order::where('store_id', getCurrentStore())
            ->selectRaw('delivered_status, COUNT(*) as aggregate')
            ->groupBy('delivered_status')
            ->pluck('aggregate', 'delivered_status');

        $orderStatusCounts = [];
        foreach ($labels as $value => $label) {
            $orderStatusCounts[$label] = (int) ($byStatus[$value] ?? 0);
        }

        /* Anything outside 0-8. Shown only when it is non-zero, so a healthy store does not
           carry an empty bucket, but never silently dropped. */
        $accounted = array_sum($orderStatusCounts);
        $total = (int) $byStatus->sum();
        if ($total > $accounted) {
            $orderStatusCounts[__('Other')] = $total - $accounted;
        }

        return view('reports.orderStatusReport', [
            'orderStatusCounts' => $orderStatusCounts,
            'orderStatusTotal' => $total,
            'periodLabel' => __('All time'),
        ]);
    }

    public function showCountryOrderReport(Request $request)
    {
        // Country code mapping (this should ideally come from a more reliable source)
        $countryCodeMap = [
            'Afghanistan' => 'AF',
            'Albania' => 'AL',
            'Algeria' => 'DZ',
            'American Samoa' => 'AS',
            'Andorra' => 'AD',
            'Angola' => 'AO',
            'Anguilla' => 'AI',
            'Antarctica' => 'AQ',
            'Antigua and Barbuda' => 'AG',
            'Argentina' => 'AR',
            'Armenia' => 'AM',
            'Aruba' => 'AW',
            'Australia' => 'AU',
            'Austria' => 'AT',
            'Azerbaijan' => 'AZ',
            'Bahamas' => 'BS',
            'Bahrain' => 'BH',
            'Bangladesh' => 'BD',
            'Barbados' => 'BB',
            'Belarus' => 'BY',
            'Belgium' => 'BE',
            'Belize' => 'BZ',
            'Benin' => 'BJ',
            'Bermuda' => 'BM',
            'Bhutan' => 'BT',
            'Bolivia' => 'BO',
            'Bosnia and Herzegovina' => 'BA',
            'Botswana' => 'BW',
            'Brazil' => 'BR',
            'Brunei Darussalam' => 'BN',
            'Bulgaria' => 'BG',
            'Burkina Faso' => 'BF',
            'Burundi' => 'BI',
            'Cabo Verde' => 'CV',
            'Cambodia' => 'KH',
            'Cameroon' => 'CM',
            'Canada' => 'CA',
            'Cayman Islands' => 'KY',
            'Central African Republic' => 'CF',
            'Chad' => 'TD',
            'Chile' => 'CL',
            'China' => 'CN',
            'Colombia' => 'CO',
            'Comoros' => 'KM',
            'Congo' => 'CG',
            'Congo (Democratic Republic of the)' => 'CD',
            'Cook Islands' => 'CK',
            'Costa Rica' => 'CR',
            'Croatia' => 'HR',
            'Cuba' => 'CU',
            'Curaçao' => 'CW',
            'Cyprus' => 'CY',
            'Czech Republic' => 'CZ',
            'Denmark' => 'DK',
            'Djibouti' => 'DJ',
            'Dominica' => 'DM',
            'Dominican Republic' => 'DO',
            'Ecuador' => 'EC',
            'Egypt' => 'EG',
            'El Salvador' => 'SV',
            'Equatorial Guinea' => 'GQ',
            'Eritrea' => 'ER',
            'Estonia' => 'EE',
            'Eswatini' => 'SZ',
            'Ethiopia' => 'ET',
            'Fiji' => 'FJ',
            'Finland' => 'FI',
            'France' => 'FR',
            'Gabon' => 'GA',
            'Gambia' => 'GM',
            'Georgia' => 'GE',
            'Germany' => 'DE',
            'Ghana' => 'GH',
            'Greece' => 'GR',
            'Greenland' => 'GL',
            'Grenada' => 'GD',
            'Guam' => 'GU',
            'Guatemala' => 'GT',
            'Guernsey' => 'GG',
            'Guinea' => 'GN',
            'Guinea-Bissau' => 'GW',
            'Guyana' => 'GY',
            'Haiti' => 'HT',
            'Honduras' => 'HN',
            'Hong Kong' => 'HK',
            'Hungary' => 'HU',
            'Iceland' => 'IS',
            'India' => 'IN',
            'Indonesia' => 'ID',
            'Iran' => 'IR',
            'Iraq' => 'IQ',
            'Ireland' => 'IE',
            'Isle of Man' => 'IM',
            'Israel' => 'IL',
            'Italy' => 'IT',
            'Jamaica' => 'JM',
            'Japan' => 'JP',
            'Jersey' => 'JE',
            'Jordan' => 'JO',
            'Kazakhstan' => 'KZ',
            'Kenya' => 'KE',
            'Kiribati' => 'KI',
            'Korea (North)' => 'KP',
            'Korea (South)' => 'KR',
            'Kuwait' => 'KW',
            'Kyrgyzstan' => 'KG',
            'Laos' => 'LA',
            'Latvia' => 'LV',
            'Lebanon' => 'LB',
            'Lesotho' => 'LS',
            'Liberia' => 'LR',
            'Libya' => 'LY',
            'Liechtenstein' => 'LI',
            'Lithuania' => 'LT',
            'Luxembourg' => 'LU',
            'Macao' => 'MO',
            'Madagascar' => 'MG',
            'Malawi' => 'MW',
            'Malaysia' => 'MY',
            'Maldives' => 'MV',
            'Mali' => 'ML',
            'Malta' => 'MT',
            'Marshall Islands' => 'MH',
            'Mauritania' => 'MR',
            'Mauritius' => 'MU',
            'Mexico' => 'MX',
            'Micronesia' => 'FM',
            'Moldova' => 'MD',
            'Monaco' => 'MC',
            'Mongolia' => 'MN',
            'Montenegro' => 'ME',
            'Morocco' => 'MA',
            'Mozambique' => 'MZ',
            'Myanmar' => 'MM',
            'Namibia' => 'NA',
            'Nauru' => 'NR',
            'Nepal' => 'NP',
            'Netherlands' => 'NL',
            'New Zealand' => 'NZ',
            'Nicaragua' => 'NI',
            'Niger' => 'NE',
            'Nigeria' => 'NG',
            'North Macedonia' => 'MK',
            'Norway' => 'NO',
            'Oman' => 'OM',
            'Pakistan' => 'PK',
            'Palau' => 'PW',
            'Palestine' => 'PS',
            'Panama' => 'PA',
            'Papua New Guinea' => 'PG',
            'Paraguay' => 'PY',
            'Peru' => 'PE',
            'Philippines' => 'PH',
            'Poland' => 'PL',
            'Portugal' => 'PT',
            'Qatar' => 'QA',
            'Romania' => 'RO',
            'Russia' => 'RU',
            'Rwanda' => 'RW',
            'Saint Kitts and Nevis' => 'KN',
            'Saint Lucia' => 'LC',
            'Saint Vincent and the Grenadines' => 'VC',
            'Samoa' => 'WS',
            'San Marino' => 'SM',
            'Sao Tome and Principe' => 'ST',
            'Saudi Arabia' => 'SA',
            'Senegal' => 'SN',
            'Serbia' => 'RS',
            'Seychelles' => 'SC',
            'Sierra Leone' => 'SL',
            'Singapore' => 'SG',
            'Slovakia' => 'SK',
            'Slovenia' => 'SI',
            'Solomon Islands' => 'SB',
            'Somalia' => 'SO',
            'South Africa' => 'ZA',
            'South Sudan' => 'SS',
            'Spain' => 'ES',
            'Sri Lanka' => 'LK',
            'Sudan' => 'SD',
            'Suriname' => 'SR',
            'Sweden' => 'SE',
            'Switzerland' => 'CH',
            'Syria' => 'SY',
            'Taiwan' => 'TW',
            'Tajikistan' => 'TJ',
            'Tanzania' => 'TZ',
            'Thailand' => 'TH',
            'Timor-Leste' => 'TL',
            'Togo' => 'TG',
            'Tonga' => 'TO',
            'Trinidad and Tobago' => 'TT',
            'Tunisia' => 'TN',
            'Turkey' => 'TR',
            'Turkmenistan' => 'TM',
            'Tuvalu' => 'TV',
            'Uganda' => 'UG',
            'Ukraine' => 'UA',
            'United Arab Emirates' => 'AE',
            'United Kingdom' => 'GB',
            'United States of America' => 'US',
            'Uruguay' => 'UY',
            'Uzbekistan' => 'UZ',
            'Vanuatu' => 'VU',
            'Venezuela' => 'VE',
            'Vietnam' => 'VN',
            'Yemen' => 'YE',
            'Zambia' => 'ZM',
            'Zimbabwe' => 'ZW',
        ];

        $billingData = \DB::table('order_billing_details')
            ->join('countries', 'order_billing_details.country', '=', 'countries.id')
            ->join('orders', 'order_billing_details.order_id', '=', 'orders.id')->where('orders.store_id', getCurrentStore())
            ->select('countries.name as country_name', \DB::raw('COUNT(order_billing_details.id) as total_orders'))
            ->groupBy('countries.name')
            ->get();

        $total = Order::where('store_id', getCurrentStore())->count();

        /* TWO NUMBERS THAT COUNT DIFFERENT THINGS USED TO SIT SIDE BY SIDE WITH NO EXPLANATION.
           `$total` is every order the store has taken. `$billingData` counts only orders that
           have a billing-detail row whose country resolves — so the per-country figures sum to
           something at or below `$total`, and the card showed "Total Order Is: N" beside a chart
           that did not add up to N. A merchant reading that concludes either the chart is broken
           or their orders are missing. Both numbers are now named for what they are, and the
           difference is stated rather than left to be inferred. */
        $withCountry = (int) $billingData->sum('total_orders');

        $formattedData = [];
        $unmapped = 0;
        foreach ($billingData as $data) {
            $countryCode = $countryCodeMap[$data->country_name] ?? null;
            if ($countryCode) {
                $formattedData[$countryCode] = ['pageviews' => $data->total_orders];
                continue;
            }

            /* A country the hardcoded map does not know used to be dropped with no `else` at
               all, so its orders vanished from the map and from any sum taken off it. It is
               counted here instead: the map still cannot draw it, but the reader is told it
               exists. */
            $unmapped += (int) $data->total_orders;
        }

        return view('reports.order_country_Report', [
            'total' => $total,
            'withCountry' => $withCountry,
            'unmapped' => $unmapped,
            'billingData' => $billingData,
            'formattedData' => $formattedData,
            'periodLabel' => __('All time'),
        ]);
    }

    /*
     * P2-05-3. THE STOCK BADGE A REPORT ROW SHOWS, derived from the one shared rule.
     * ============================================================================
     *
     * MEASURED on the live report before this existed. The status column -- header
     * "Stock status" -- was EMPTY on the low-stock and most-stocked tabs for every row, and the
     * out-of-stock tab showed a raw Bootstrap `bg-light-danger` pill. The other two were blank
     * because the partials gated on `stock_order_status` being one of four values and the products
     * carry NULL; had it read `allow`, the LOW-STOCK report would have shown a badge reading
     * **"In stock"** on every low row. Either way the column answered a different question from
     * the tab it sits in.
     *
     * `stock_order_status` is the merchant's BACKORDER POLICY (allow / not_allow), not a stock
     * level. Reading it as a level is the same category error as the two predicates this slice
     * already corrected: the setting means one thing and the screen said another.
     *
     * So the state is computed HERE, once, by `ProductDataTable::trackedStockState()` -- the same
     * function the product list's badge and the list's own filter use -- and handed to the view as
     * `stock_state`. The view stays dumb. `stock_status` is left in the payload untouched, because
     * the datatables column definitions still reference it and this slice removes no functionality.
     *
     * `stock_effective_low` repeats the rule in a figure, so the badge's title can state the
     * threshold the verdict was reached against rather than leaving the merchant to infer it --
     * exactly what `ProductDataTable` does for its own badge.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function withStockState(array $row, int $stock, $lowThreshold, int $fallback): array
    {
        $row['stock_state'] = \App\DataTables\ProductDataTable::trackedStockState(
            $stock,
            $lowThreshold,
            $fallback
        );
        $row['stock_effective_low'] = (int) ($lowThreshold ?: 0) ?: $fallback;

        return $row;
    }

    public function getLowStockProducts(Request $request)
    {
        session()->put('stock_active_tab', 'pills-low-stock-tab');
        $products = Product::where('store_id', getCurrentStore())
            ->get();

        /*
         * P2-05-3. The fallback threshold, resolved FROM THE SAME STORE AS THE PRODUCTS ABOVE.
         * P1-SEC-26 recorded why the explicit store argument matters and the sibling
         * getOutOfStockProducts() already carries that fix: a no-argument getAdminAllSetting()
         * resolves from the CALLER, and for a staff member it walks up to created_by and reads the
         * OWNER's current_store -- so a staff member whose owner is in another store would judge
         * this store's stock by that other store's threshold. This report had no fallback at all,
         * so it had no such bug to fix; introducing the fallback introduces the hazard, and it is
         * closed in the same line that creates it.
         */
        $lowStockFallback = \App\DataTables\ProductDataTable::stockFallbackThreshold(
            getAdminAllSetting(null, getCurrentStore())
        );

        $low_stock_products = [];

        foreach ($products as $product) {
            if ($product->variant_product == 0) {
                /*
                 * P2-05-3. ONE RULE, SHARED WITH THE PRODUCT LIST. This was
                 * `$product->product_stock <= $product->low_stock_threshold` -- a direct
                 * comparison with NO FALLBACK -- while the product list treats a per-product
                 * threshold of 0 as UNSET and falls back to the store's out_of_stock_threshold
                 * (ProductDataTable::trackedStockState, shared with the list's own filter so the
                 * badge and the filter cannot disagree).
                 *
                 * MEASURED CONSEQUENCE, with low_stock_threshold = 0, stock = 3 and a store
                 * threshold of 5: the product list said LOW STOCK, this report OMITTED the product
                 * (3 <= 0 is false), and the out-of-stock report LISTED it as out. Three answers
                 * for one row of one table. StockReportPredicateTest drives all three.
                 */
                if ($product->track_stock == 1
                    && \App\DataTables\ProductDataTable::trackedStockState(
                        (int) $product->product_stock,
                        $product->low_stock_threshold,
                        $lowStockFallback
                    ) === 'low') {
                    $low_stock_product = [
                        'product_name' => $product->name,
                        'stock_status' => $product->stock_order_status,
                        'stock' => $product->product_stock,
                        'category' => $product->ProductData->name,
                        'product_id' => $product->id,
                    ];
                    $low_stock_products[] = $this->withStockState(
                        $low_stock_product,
                        (int) $product->product_stock,
                        $product->low_stock_threshold,
                        $lowStockFallback
                    );
                }
            } else {
                $product_stocks = ProductVariant::where('product_id', $product->id)->get();

                foreach ($product_stocks as $stock) {
                    $variationOptions = explode(',', $stock->variation_option);

                    /*
                     * P2-05-3, the variant twin. `manage_stock` in `variation_option` is the
                     * variant's equivalent of the parent's `track_stock`, so it stays; the
                     * comparison moves onto the shared rule with the SAME fallback. The product
                     * list renders "In Variant" for a variant product -- it makes no stock claim
                     * at the parent row and defers to the variant -- so the variant row is the
                     * authority here, judged by the same predicate.
                     */
                    if (in_array('manage_stock', $variationOptions)
                        && \App\DataTables\ProductDataTable::trackedStockState(
                            (int) $stock->stock,
                            $stock->low_stock_threshold,
                            $lowStockFallback
                        ) === 'low') {
                        $low_stock_product = [
                            'product_name' => $product->name . '(' . $stock->variant . ')',
                            'category' => $product->ProductData->name,
                            'product_id' => $product->id,
                        ];
                        if ($stock->stock_order_status == 'allow') {
                            $low_stock_product['stock_status'] = 'in_stock';
                        } elseif ($stock->stock_order_status == 'on_backorder') {
                            $low_stock_product['stock_status'] = 'out of stock';
                        }


                        $low_stock_product['stock'] = $stock->stock;

                        $low_stock_products[] = $this->withStockState(
                            $low_stock_product,
                            (int) $stock->stock,
                            $stock->low_stock_threshold,
                            $lowStockFallback
                        );
                    }
                }
            }
        }

        return datatables()->of($low_stock_products)
            ->addColumn('stock_status', function ($product) {
                return view('reports.low_stock_status', compact('product'));
            })->addColumn('action', function ($product) {
                return view('reports.low_stock_action', compact('product'));
            })
            ->rawColumns(['action'])->make(true);
    }

    public function getOutOfStockProducts(Request $request)
    {
        session()->put('stock_active_tab', 'pills-out-of-stock-tab');
        $products = Product::where('store_id', getCurrentStore())
            ->get();
        $out_of_stock_products = [];
        // P1-SEC-26: the THRESHOLD must come from the same store as the PRODUCTS above. A no-argument getAdminAllSetting() resolves it
        // from the caller instead, and for a STAFF member it walks up to created_by and then reads the OWNER's current_store -- so a
        // staff member whose owner is currently in another store judged this store's stock by that other store's threshold.
        $settings = getAdminAllSetting(null, getCurrentStore());

        /*
         * P2-05-3. The same fallback the product list uses, off the store-scoped settings above --
         * and IT DOES NOT AFFECT THIS REPORT'S VERDICT, which is worth stating so that nobody
         * later "fixes" an out-of-stock complaint by tuning this setting.
         *
         * `trackedStockState()` returns 'out' on `$stock <= 0` alone; the threshold only moves the
         * 'low' boundary. MEASURED: forcing this value to 99 changes nothing -- all five cases in
         * StockReportPredicateTest still pass -- while forcing the LOW report's fallback to 0 fails
         * it. So it is passed for one shared call shape across both reports, not because the out
         * verdict depends on it. The setting is a low-stock fallback; reading it as an
         * out-of-stock LEVEL is exactly the defect this slice removed.
         */
        $outStockFallback = \App\DataTables\ProductDataTable::stockFallbackThreshold($settings);

        foreach ($products as $product) {
            if ($product->variant_product == 0) {
                /*
                 * P2-05-3. THE SAME SHARED RULE, and this leg was wrong in the OPPOSITE direction
                 * to the low-stock report. It used the store's out_of_stock_threshold as the OUT
                 * boundary, so a product with 3 units against a threshold of 5 was reported out of
                 * stock -- while the product list calls a tracked product out only at ZERO and
                 * calls that one LOW. The setting is a LOW-stock fallback in the list's rule, not
                 * an out-of-stock level, and reading it as one is what produced a product listed
                 * simultaneously as low on the catalogue and out in the report.
                 */
                if ($product->track_stock == 1
                    && \App\DataTables\ProductDataTable::trackedStockState(
                        (int) $product->product_stock,
                        $product->low_stock_threshold,
                        $outStockFallback
                    ) === 'out') {
                    $out_of_stock_product = [
                        'product_name' => $product->name,
                        'stock_status' => $product->stock_order_status,
                        'stock' => $product->product_stock,
                        'category' => $product->ProductData->name,
                        'product_id' => $product->id,
                    ];
                    $out_of_stock_products[] = $this->withStockState(
                        $out_of_stock_product,
                        (int) $product->product_stock,
                        $product->low_stock_threshold,
                        $outStockFallback
                    );
                }
            } else {
                $product_stocks = ProductVariant::where('product_id', $product->id)->get();

                foreach ($product_stocks as $stock) {
                    $variationOptions = explode(',', $stock->variation_option);
                    /* P2-05-3, the variant twin of the leg above. Same rule, same fallback. */
                    if (in_array('manage_stock', $variationOptions)
                        && \App\DataTables\ProductDataTable::trackedStockState(
                            (int) $stock->stock,
                            $stock->low_stock_threshold,
                            $outStockFallback
                        ) === 'out') {
                        $out_of_stock_product = [
                            'product_name' => $product->name . '(' . $stock->variant . ')',
                            'stock_status' => $stock->stock_order_status,
                            'stock' => $stock->stock,
                            'category' => $product->ProductData->name,
                            'product_id' => $product->id,
                        ];
                        $out_of_stock_products[] = $this->withStockState(
                            $out_of_stock_product,
                            (int) $stock->stock,
                            $stock->low_stock_threshold,
                            $outStockFallback
                        );
                    }
                }
            }
        }


        return datatables()->of($out_of_stock_products)->editColumn('stock_status', function ($product) {
            return view('reports.out_stock_status', compact('product'));
        })->addColumn('action', function ($product) {
            return view('reports.out_stock_action', compact('product'));
        })
            ->rawColumns(['action'])->make(true);
    }

    public function getMostStockedProducts(Request $request)
    {
        session()->put('stock_active_tab', 'pills-most-stocked-tab');
        $products = Product::where('store_id', getCurrentStore())
            ->get();
        $most_stocked_products = [];
        $product_data = [];

        /*
         * P2-05-3. The same fallback the other two tabs and the product list use, resolved from the
         * SAME store as the products -- see getLowStockProducts() for why the explicit store
         * argument is load-bearing (P1-SEC-26).
         *
         * This report's MEMBERSHIP is deliberately NOT changed: it ranks by quantity and takes the
         * top ten, which is a different question from "is this low". Only the status BADGE moves
         * onto the shared rule, because that badge was empty for every row.
         */
        $mostStockedFallback = \App\DataTables\ProductDataTable::stockFallbackThreshold(
            getAdminAllSetting(null, getCurrentStore())
        );

        foreach ($products as $product) {
            if ($product->variant_product == 0) {
                if ($product->track_stock == 1 && $product->product_stock != 0 && $product->product_stock > 0) {
                    $product_data[] = $this->withStockState([
                        'product_name' => $product->name,
                        'stock_status' => $product->stock_order_status,
                        'stock' => $product->product_stock, // Use product_stock here
                        'category' => $product->ProductData->name,
                        'product_id' => $product->id,
                    ], (int) $product->product_stock, $product->low_stock_threshold, $mostStockedFallback);
                }
            } else {
                $product_stocks = ProductVariant::where('product_id', $product->id)->get();

                foreach ($product_stocks as $stock) {
                    $variationOptions = explode(',', $stock->variation_option);
                    if (in_array('manage_stock', $variationOptions) && $stock->stock != 0 && $stock->stock > 0) {
                        $product_data[] = [
                            'product_name' => $product->name . '(' . $stock->variant . ')',
                            'category' => $product->ProductData->name,
                            'product_id' => $product->id,
                        ];
                        if ($stock->stock_order_status == 'allow') {
                            $product_data[count($product_data) - 1]['stock_status'] = 'in_stock';
                        } elseif ($stock->stock_order_status == 'on_backorder' || $stock->stock_order_status == 'not_allow') {
                            $product_data[count($product_data) - 1]['stock_status'] = 'out_of_stock';
                        }

                        $product_data[count($product_data) - 1]['stock'] = $stock->stock; // Use stock here

                        /*
                         * P2-05-3. Decorated AFTER the index mutations above, not at the push: this
                         * leg builds the row and then adds `stock_status` and `stock` by index, so
                         * decorating at the push would hand the helper a row without its quantity
                         * and the badge's title would read a zero it never measured.
                         */
                        $product_data[count($product_data) - 1] = $this->withStockState(
                            $product_data[count($product_data) - 1],
                            (int) $stock->stock,
                            $stock->low_stock_threshold,
                            $mostStockedFallback
                        );
                    }
                }
            }
        }

        usort($product_data, function ($stocks, $stock_data) {
            return $stock_data['stock'] - $stocks['stock'];
        });

        $most_stocked_products = array_slice($product_data, 0, 10);

        return datatables()->of($most_stocked_products)->editColumn('stock_status', function ($product) {
            return view('reports.most_stock_status', compact('product'));
        })->addColumn('action', function ($product) {
            return view('reports.most_stock_action', compact('product'));
        })
            ->rawColumns(['action'])->make(true);
    }
}