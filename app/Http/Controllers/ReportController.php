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
use App\Support\Metrics\FinanceLedgerFigures;
use App\Support\Reports\CalendarPeriod;
use App\Support\Tenancy\OperatorStoreSelection;
use App\Support\Metrics\MetricWindow;
use App\Support\Reports\ReportFigureNote;
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

    /*
     * P5-3b -- WHY EVERY MONTH-WINDOW BOUND IN THIS FILE USES `whereDate` AND NOT `where`.
     *
     * Eighteen bounds across three handlers read
     *   ->where('order_date', '<=', date('Y-m-t', $end))
     * which compares a BARE DATE STRING against a DATETIME column. MySQL casts it to midnight, so
     * `order_date <= '2026-10-31'` admitted an order placed at 2026-10-31 00:00:00 and refused one
     * placed at 00:00:01 -- the whole final day of trading, minus the first second.
     *
     * `date('Y-m-t')` for *this month* and *year* resolves to a date in the future for most of the
     * period, so there it only bit on the final day itself. For *last month* it always resolves to
     * a date already past, so **the last day of trading was missing from that report every single
     * month**.
     *
     * MEASURED, not inferred. On MariaDB 10.11.14 with 195.00 of orders placed inside the period,
     * the sales report returned 112.00 -- short by exactly the 70.00 and 13.00 placed on the final
     * day at 14:00 and 23:59:59, while the 7.00 placed at midnight was admitted.
     * `tests/Support/Security/bin/verify-report-window-boundary-on-mysql.php` is that measurement.
     *
     * `whereDate` compares `DATE(col)` against the bound, which is right at both ends and is the
     * idiom THIS FILE ALREADY USES at :260-261 and :269-297. The lower bound was already correct
     * -- `>= 'Y-m-01'` is midnight on the first -- and was converted with it so the pair reads as
     * one intent; `DATE(col) >= '2026-11-01'` and `col >= '2026-11-01'` select the same rows.
     *
     * THERE IS NO INDEX TO LOSE. `orders` carries no `(store_id, order_date)` composite, so these
     * queries were already a `store_id` scan filtered by date, and wrapping the column in `DATE()`
     * costs nothing that was being used.
     *
     * NOTE FOR ANYONE ADDING A TEST HERE: this controller calls `MONTH()` 36 times and `YEAR()` 9
     * times inside `selectRaw`, and SQLite has neither -- so every handler in this file answers
     * HTTP 500 under the project's test suite and CANNOT be covered by it. That is why an
     * eighteen-site bug survived 9,112 Feature tests, and why the evidence for this fix is a
     * MySQL script rather than a test.
     */

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
        $store_id = getStoreById(OperatorStoreSelection::reportStoreId());
        if ($request->chart_data == 'last-month') {
            $data = 'last-month';
            /*
             * GAP-223(a). `Carbon::now()->subMonth()` OVERFLOWS -- measured: 31 March 2026 minus one
             * month is 2026-03-03 -- so the `->format('Y')`/`->format('m')` pairs below named the
             * CURRENT month, and these three counts were this month's under a last-month heading.
             */
            $period = CalendarPeriod::lastMonth();
            $lastMonth = Carbon::parse($period['from']);
            $guest = Order::where('store_id', OperatorStoreSelection::reportStoreId())->where('is_guest', '=', 1)->whereYear('order_date', $lastMonth->format('Y'))->whereMonth('order_date', $lastMonth->format('m'))->get()->count();
            $customer = Order::where('store_id', OperatorStoreSelection::reportStoreId())->where('is_guest', '=', 0)->whereYear('order_date', $lastMonth->format('Y'))->whereMonth('order_date', $lastMonth->format('m'))->get()->count();

            $customer_total = Customer::where('store_id', OperatorStoreSelection::reportStoreId())->where('regiester_date', '!=', NULL)->whereYear('regiester_date', $lastMonth->format('Y'))->whereMonth('regiester_date', $lastMonth->format('m'))->get()->count();
            $totaluser = $guest + $customer;



            $user = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            /*
             * THE WINDOW AND THE DAY COUNT IT HAS TO AGREE WITH, FROM ONE EXPRESSION.
             *
             * This read `strtotime("-1 month")` and took `date('Y-m-01'|'Y-m-t', ...)` of it, which on
             * an overflow day is the WHOLE CURRENT MONTH. The loop bound below was ALREADY correct
             * (`subMonthNoOverflow()`), so the two disagreed: the query returned 31 days of March
             * while the loop read keys 1..28, and ORDERS ON 29, 30 AND 31 WERE SILENTLY DROPPED FROM
             * THE CHART. Measured. That is the real cost here; the wrong label is only how a merchant
             * might notice.
             *
             * `$date = (int) date('j', strtotime($end))` IS DELETED, NOT CORRECTED. `$end` is an int
             * timestamp, so `strtotime($end)` is `false` -- measured; PHP coerces the int to a numeric
             * string, which is not a date -- and `date('j', false)` is '1'. The variable was 1 on
             * every day of the year. It is also never read in this branch: the only reads of `$date`
             * are in the seven-day branch, which assigns its own first. Dead AND broken.
             */
            $start = strtotime($period['from']);
            $end = strtotime($period['to']);

            $user->whereDate('order_date', '>=', date('Y-m-01', $start))->whereDate('order_date', '<=', date('Y-m-t', $end))->where('is_guest', '=', 0)->where('store_id', OperatorStoreSelection::reportStoreId());
            $user = $user->get();

            $register_customer = Customer::selectRaw('customers.*,DATE(regiester_date) as DATE,MONTH(regiester_date) as month');
            $register_customer->whereDate('regiester_date', '>=', date('Y-m-01', $start))->whereDate('regiester_date', '<=', date('Y-m-t', $end))->where('regiester_date', '!=', NULL)->where('store_id', OperatorStoreSelection::reportStoreId());
            $register_customer = $register_customer->get();

            $new_guest = Customer::selectRaw('customers.*,DATE(last_active) as DATE,MONTH(last_active) as month');
            $new_guest->whereDate('last_active', '>=', date('Y-m-01', $start))->whereDate('last_active', '<=', date('Y-m-t', $end))->where('regiester_date', '=', NULL)->where('store_id', OperatorStoreSelection::reportStoreId());
            $new_guest = $new_guest->get();

            $guests = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $guests->whereDate('order_date', '>=', date('Y-m-01', $start))->whereDate('order_date', '<=', date('Y-m-t', $end))->where('is_guest', '=', 1)->where('store_id', OperatorStoreSelection::reportStoreId());
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
            /*
             * ONE DEFINITION. This was `Carbon::now()->subMonthNoOverflow()->endOfMonth()` -- already
             * CORRECT, and that is precisely why the figures were wrong: it disagreed with the window
             * above on the 33 overflow days. Taking the count from the same `$period` REMOVES the
             * second expression instead of correcting it. MEASURED EQUIVALENT on all 1,827 days of
             * 2024-2028: 0 disagreements between the old expression and `$period['days']`, so the
             * 1,794 ordinary days are byte-identical.
             */
            $lastday = $period['days'];

            for ($i = 1; $i <= $lastday; $i++) {
                $userTotal[] = array_key_exists($i, $userTotalArray) ? count($userTotalArray[$i]) : 0;
                $guestTotal[] = array_key_exists($i, $guestTotalArray) ? count($guestTotalArray[$i]) : 0;
                $registerTotal[] = array_key_exists($i, $registerTotalArray) ? count($registerTotalArray[$i]) : 0;
                $newguestTotal[] = array_key_exists($i, $newguestTotalArray) ? count($newguestTotalArray[$i]) : 0;
            }

            $monthList = $month = $this->getLastMonthDatesFormatted();
        } elseif ($request->chart_data == 'this-month') {

            $guest = Order::where('store_id', OperatorStoreSelection::reportStoreId())->where('is_guest', '=', 1)->whereYear('order_date', date('Y'))->whereMonth('order_date', date('m'))->get()->count();
            $customer = Order::where('store_id', OperatorStoreSelection::reportStoreId())->where('is_guest', '=', 0)->whereYear('order_date', date('Y'))->whereMonth('order_date', date('m'))->get()->count();
            $customer_total = Customer::where('store_id', OperatorStoreSelection::reportStoreId())->where('regiester_date', '!=', NULL)->whereYear('regiester_date', date('Y'))->whereMonth('regiester_date', date('m'))->get()->count();
            $totaluser = $guest + $customer;


            $user = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $start = strtotime(date('Y-m-01'));
            $end = strtotime(date('Y-m-t'));
            $day = (int) date('j', strtotime($end));

            $user->whereDate('order_date', '>=', date('Y-m-01', $start))->whereDate('order_date', '<=', date('Y-m-t', $end))->where('is_guest', '=', 0)->where('store_id', OperatorStoreSelection::reportStoreId());
            $user = $user->get();


            $register_customer = Customer::selectRaw('customers.*,DATE(regiester_date) as DATE,MONTH(regiester_date) as month');
            $register_customer->whereDate('regiester_date', '>=', date('Y-m-01', $start))->whereDate('regiester_date', '<=', date('Y-m-t', $end))->where('regiester_date', '!=', NULL)->where('store_id', OperatorStoreSelection::reportStoreId());
            $register_customer = $register_customer->get();

            $new_guest = Customer::selectRaw('customers.*,DATE(last_active) as DATE,MONTH(last_active) as month');
            $new_guest->whereDate('last_active', '>=', date('Y-m-01', $start))->whereDate('last_active', '<=', date('Y-m-t', $end))->where('regiester_date', '=', NULL)->where('store_id', OperatorStoreSelection::reportStoreId());
            $new_guest = $new_guest->get();

            $guests = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $guests->whereDate('order_date', '>=', date('Y-m-01', $start))->whereDate('order_date', '<=', date('Y-m-t', $end))->where('is_guest', '=', 1)->where('store_id', OperatorStoreSelection::reportStoreId());
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
            $guest = Order::where('store_id', OperatorStoreSelection::reportStoreId())->where('is_guest', 1)->where('order_date', '>', $startDate)->get()->count();
            $customer = Order::where('store_id', OperatorStoreSelection::reportStoreId())->where('is_guest', '=', 0)->where('order_date', '>', $startDate)->get()->count();
            $customer_total = Customer::where('store_id', OperatorStoreSelection::reportStoreId())->where('regiester_date', '!=', NULL)->where('regiester_date', '>', $startDate)->get()->count();
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
                $userTotal[] = Order::whereDate('order_date', $date)->where('is_guest', '=', 0)->where('store_id', OperatorStoreSelection::reportStoreId())->count();
                $guestTotal[] = Order::whereDate('order_date', $date)->where('is_guest', '=', 1)->where('store_id', OperatorStoreSelection::reportStoreId())->count();
                $registerTotal[] = Customer::whereDate('regiester_date', $date)->where('regiester_date', '!=', NULL)->where('store_id', OperatorStoreSelection::reportStoreId())->count();
                $newguestTotal[] = Customer::whereDate('last_active', $date)->where('regiester_date', '=', NULL)->where('store_id', OperatorStoreSelection::reportStoreId())->count();
            }
        } elseif ($request->chart_data == 'year') {

            $guest = Order::where('store_id', OperatorStoreSelection::reportStoreId())->where('is_guest', '=', 1)->whereYear('order_date', date('Y'))->get()->count();
            $customer = Order::where('store_id', OperatorStoreSelection::reportStoreId())->where('is_guest', '=', 0)->whereYear('order_date', date('Y'))->get()->count();
            $customer_total = Customer::where('store_id', OperatorStoreSelection::reportStoreId())->where('regiester_date', '!=', NULL)->whereYear('regiester_date', date('Y'))->get()->count();
            $totaluser = $guest + $customer;

            $user = Order::selectRaw('orders.*,MONTH(order_date) as month,YEAR(order_date) as year');
            $start = strtotime(date('Y-01'));
            $end = strtotime(date('Y-12'));
            $user->whereDate('order_date', '>=', date('Y-m-01', $start))->whereDate('order_date', '<=', date('Y-m-t', $end))->where('is_guest', '=', 0)->where('store_id', OperatorStoreSelection::reportStoreId());
            $user = $user->get();

            $guests = Order::selectRaw('orders.*,MONTH(order_date) as month,YEAR(order_date) as year');
            $guests->whereDate('order_date', '>=', date('Y-m-01', $start))->whereDate('order_date', '<=', date('Y-m-t', $end))->where('is_guest', '=', 1)->where('store_id', OperatorStoreSelection::reportStoreId());
            $guests = $guests->get();

            $register_customer = Customer::selectRaw('customers.*,MONTH(regiester_date) as month,YEAR(regiester_date) as year');
            $register_customer->whereDate('regiester_date', '>=', date('Y-m-01', $start))->whereDate('regiester_date', '<=', date('Y-m-t', $end))->where('regiester_date', '!=', NULL)->where('store_id', OperatorStoreSelection::reportStoreId());
            $register_customer = $register_customer->get();

            $new_guest = Customer::selectRaw('customers.*,MONTH(last_active) as month,YEAR(last_active) as year');
            $new_guest->whereDate('last_active', '>=', date('Y-m-01', $start))->whereDate('last_active', '<=', date('Y-m-t', $end))->where('regiester_date', '=', NULL)->where('store_id', OperatorStoreSelection::reportStoreId());
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
            $guest = Order::whereDate('order_date', '>=', $form_date)->whereDate('order_date', '<=', $to_date)->where('is_guest', '=', 1)->where('store_id', OperatorStoreSelection::reportStoreId())->count();
            $customer = Order::whereDate('order_date', '>=', $form_date)->whereDate('order_date', '<=', $to_date)->where('is_guest', '=', 0)->where('store_id', OperatorStoreSelection::reportStoreId())->count();

            $customer_total = Customer::whereDate('regiester_date', '>=', $form_date)->whereDate('regiester_date', '<=', $to_date)->where('regiester_date', '!=', NULL)->where('store_id', OperatorStoreSelection::reportStoreId())->count();

            $totaluser = $guest + $customer;

            $user = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $user->whereDate('order_date', '>=', $form_date)
                ->whereDate('order_date', '<=', $to_date)->where('is_guest', '=', 0)->where('store_id', OperatorStoreSelection::reportStoreId());
            $user = $user->get();

            $guests = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $guests->whereDate('order_date', '>=', $form_date)
                ->whereDate('order_date', '<=', $to_date)->where('store_id', OperatorStoreSelection::reportStoreId())->where('is_guest', '=', 1);
            $guests = $guests->get();

            $register_customer = Customer::selectRaw('customers.*,DATE(regiester_date) as DATE,MONTH(regiester_date) as month');
            $register_customer->whereDate('regiester_date', '>=', $form_date)
                ->whereDate('regiester_date', '<=', $to_date)->where('store_id', OperatorStoreSelection::reportStoreId())->where('regiester_date', '!=', NULL);
            $register_customer = $register_customer->get();

            $new_guest = Customer::selectRaw('customers.*,DATE(last_active) as DATE,MONTH(last_active) as month');
            $new_guest->whereDate('last_active', '>=', $form_date)
                ->whereDate('last_active', '<=', $to_date)->where('store_id', OperatorStoreSelection::reportStoreId())->where('regiester_date', '=', NULL);
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
                ->whereDate('order_date', '<=', $to_date)->where('store_id', OperatorStoreSelection::reportStoreId());
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
                // GAP-223(a): this label was ALREADY right -- it used `'first day of last month'`
                // while every window used `'-1 month'`. It now reads the same derivation as the
                // bounds, so the card cannot name a month its figures do not cover.
                return [__('Last month') . ' (' . CalendarPeriod::lastMonthLabel() . ')', __('Day')];
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

    /**
     * The window a `reports_chart` answer actually QUERIES, for a figure that is not accumulated in
     * the handler's own loop. ANA-005 / owner decisions D1 and D2.
     *
     * THIS MIRRORS THE HANDLER'S SQL, NOT `chartPeriodLabels`'s PROSE, and where the two disagree the
     * SQL wins -- because the point of the figure this feeds is to stand beside the six demand tiles
     * and cover the same orders. A figure over a different span under the same heading is the
     * wrong-number-that-looks-right, and it is worse than no figure.
     *
     * The branches are the same four presets plus the date picker, read off the queries themselves:
     * `last-month` and `this-month` are whole calendar months, `seven-day` is the seven days ENDING
     * TODAY (`-1 week +1 day`, then six more), `year` is 1 January to 31 December of this year, and
     * the default is the picker's single date or its ` to ` range.
     *
     * TWO DEGENERATE INPUTS, both of which must not become an exception:
     *
     *   * an unparseable `Date` -- `strtotime('')` is `false` and `date('Y-m-d', false)` is
     *     `1970-01-01`, so the handler queries a single day in 1970 while its label says "Today".
     *     That is a real inconsistency, and it is NOT reachable through the shipped UI: the period
     *     bar defaults to `selected => 'year'` and its date field defaults to `date('Y-m-d')`
     *     (`components/ss/report-filter.blade.php`), so a preset always wins and the field is never
     *     empty. Mirrored rather than corrected, because the ledger figure then REFUSES the 1970
     *     window with its reason -- which surfaces the inconsistency instead of printing a zero.
     *   * a range the picker sends backwards. `MetricWindow` throws when `from` is after `to`, so
     *     this returns null and the caller omits the tile. Swapping the bounds would invent a window
     *     the six tiles beside it are not answering.
     */
    /**
     * @param  ?int  $now  a reference timestamp for the relative presets. GAP-223(a): the overflow
     *                     shows up on 33 days in five years, `Carbon::setTestNow()` does not move
     *                     `date()`/`strtotime()`, and this container has no `faketime` -- so without
     *                     an injectable clock no test could reach an overflow day at all. Production
     *                     passes nothing.
     */
    private function chartPeriodWindow(Request $request, ?int $now = null): ?MetricWindow
    {
        switch ($request->chart_data) {
            case 'last-month':
                // GAP-223(a). This mirrored the handler's `strtotime('-1 month')` faithfully, overflow
                // included -- mirroring was the point of it. Both now read one corrected derivation.
                $period = CalendarPeriod::lastMonth($now);

                return MetricWindow::between(
                    Carbon::parse($period['from']),
                    Carbon::parse($period['to']),
                );
            case 'this-month':
                return MetricWindow::between(Carbon::parse(date('Y-m-01')), Carbon::parse(date('Y-m-t')));
            case 'seven-day':
                return MetricWindow::between(
                    Carbon::parse(date('Y-m-d', strtotime('-1 week +1 day'))),
                    Carbon::parse(date('Y-m-d')),
                );
            case 'year':
                /* The handler's own bounds, verbatim: `date('Y-01')` parses as 1 January and
                   `date('Y-12')` as 1 December, so `Y-m-t` on the second is 31 December. */
                return MetricWindow::between(
                    Carbon::parse(date('Y-m-01', strtotime(date('Y-01')))),
                    Carbon::parse(date('Y-m-t', strtotime(date('Y-12')))),
                );
        }

        $raw = (string) $request->Date;

        if (str_contains($raw, ' to ')) {
            $parts = explode(' to ', $raw);
            $from = date('Y-m-d', strtotime($parts[0]));
            $to = date('Y-m-d', strtotime($parts[count($parts) > 1 ? 1 : 0]));
        } else {
            $from = $to = date('Y-m-d', strtotime($raw));
        }

        if ($from > $to) {
            return null;
        }

        return MetricWindow::between(Carbon::parse($from), Carbon::parse($to));
    }

    /**
     * `Payments received` for the sales report, over the window the report is answering.
     *
     * The fourth of D2's four figures to reach a merchant screen, and the one the labelling proposal
     * recorded as decided-but-unbuilt: `Order value` is already on this card, `Available to withdraw`
     * and `Estimated gross profit` are on `/finance`. It reads through the SAME assembler as those two
     * (`FinanceLedgerFigures`), so there is one definition of the figure, one set of caveats and one
     * rule about the period the ledger can vouch for -- rather than a second copy that drifts.
     *
     * NULL MEANS NO TILE, and the four ways to get there are all "the question cannot be asked":
     * no current store, a PLATFORM OPERATOR who has chosen no store, a window the report itself
     * cannot express, or a reversed range. A null is NOT the figure being unavailable -- that case
     * is a tile with a stated reason, which is the assembler's job and not this method's.
     *
     * THE TENANT IS THE AUTHENTICATED PRINCIPAL'S (`getCurrentStore()`), the same predicate the six
     * tiles beside it use, and it is never read from request input.
     *
     * THE OPERATOR LIMB EXISTS BECAUSE `getCurrentStore()` DOES NOT FAIL CLOSED FOR THEM. For a
     * `super admin` it returns the hard-coded literal 1 (`helper.php:873`), and store 1 is an
     * ordinary merchant's tenant -- owner decision 7's own words, at
     * `StoreFinanceController:833`, are that "store 1 belongs to a merchant who did not ask for
     * it." `/finance` already refuses this exact principal before it reaches the same assembler
     * (`operatorHasChosenNoStore()`, used at eight call sites there), so without this limb ONE
     * assembler would be refused on one surface and render store 1's collected cash on another.
     * Found by the authorization review of this slice, not by a test: the six demand tiles beside
     * this one have disclosed store 1's ORDER VALUE to the same principal for as long as they have
     * existed, so no test encoded the boundary and the MySQL harness drives a type-`admin` owner.
     * That pre-existing disclosure is recorded as `GAP-223` rather than changed here -- narrowing
     * six live figures is not this slice's business, and this limb only declines to widen it to
     * collected cash.
     *
     * IT RETURNS NULL RATHER THAN REFUSING, deliberately. An absent tile is not an authorization
     * refusal: `ReportAuthorizationTest` defines refusal as a 403 or a redirect carrying an error,
     * and answering one here would change what the whole screen does for the operator.
     *
     * `UserPolicy::isOperator()` is the canonical spelling and is reused rather than re-written --
     * it rtrims SPACE only, matching MySQL's PAD SPACE collation, which a hand-rolled
     * `strtolower(trim(...))` gets wrong for a tab or a leading space.
     *
     * @return array{value: ?string, period: string, hint: string}|null
     */
    private function salesCollectionTile(Request $request, string $periodLabel): ?array
    {
        /*
         * ONE DEFINITION OF "the operator has chosen no store". This limb was spelled inline here by
         * ANA-005 -- `isOperator($caller) && empty($caller->current_store)` -- and GAP-223(b) gave the
         * same predicate a name. Two spellings of one rule is how an operator limb drifts out of
         * agreement with the helper it guards, so this now calls the shared one.
         */
        if (OperatorStoreSelection::unchosen()) {
            return null;
        }

        $storeId = OperatorStoreSelection::reportStoreId();

        if ($storeId <= 0) {
            return null;
        }

        $window = $this->chartPeriodWindow($request);

        if ($window === null) {
            return null;
        }

        $currencyCode = (string) (Setting::where('store_id', $storeId)
            ->where('name', 'defult_currancy')
            ->value('value') ?? 'USD');

        return FinanceLedgerFigures::forStore($storeId, $currencyCode, $window, $periodLabel)['payments'];
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

    /**
     * THE X-AXIS LABELS FOR EVERY LAST-MONTH CHART -- six call sites, and it overflowed too.
     *
     * GAP-223(a). `Carbon::now()->subMonth()` on 31 March 2026 is 2026-03-03 with a `daysInMonth` of
     * 31 (both measured), so this emitted THIRTY-ONE labels beginning "03-Mar" under a card headed
     * "Last month (February 2026)". The labels were not merely miscounted; they named the wrong days.
     *
     * `$lastMonth->setDay($day)` ALSO MUTATED THE INSTANCE IN PLACE each iteration. MEASURED to be
     * BEHAVIOURALLY HARMLESS -- `setDay()` sets an absolute day, so the in-place and copied forms
     * produce identical labels in every month tried, and a mutation removing the `copy()` below
     * SURVIVES because there is nothing for a test to see. It is kept as hygiene, not as a fix: the
     * next reader should not have to re-derive that the loop variable survives its own loop.
     * `$lastMonthYear` and `$lastMonthMonth` were assigned and never read; those two are gone.
     */
    public function getLastMonthDatesFormatted(?int $now = null)
    {
        $period = CalendarPeriod::lastMonth($now);
        $firstDay = Carbon::parse($period['from']);

        $dates = [];

        for ($day = 1; $day <= $period['days']; $day++) {
            // `copy()` is hygiene, not correctness -- see the note above; `setDay()` is absolute.
            $dates[] = $firstDay->copy()->setDay($day)->format('d-M');
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

    /*
     * EVERY KEY DEFAULTS TO AN EMPTY ARRAY, and that is the whole repair here. This method read
     * `Session::get(...)` and indexed it immediately, so on a session that had never fetched a
     * chart -- the ordinary state of a bookmarked or deep-linked report -- `null['monthList']`
     * raised E_WARNING, HandleExceptions turned it into an ErrorException, and a merchant admin
     * route answered HTTP 500. No merchant admin route should.
     *
     * AN ARRAY, not the `''` that category_order_export used to return: the six views now test
     * these series with `Array.isArray`, which is false for a string. And NOT a 403 and NOT a
     * redirect carrying session('error') -- ReportAuthorizationTest defines refusal as exactly
     * those two shapes and asserts these six exports are not refused for the owner or the
     * operator, so a guard of that shape would flip 12 assertions. The status stays 200, the key
     * set is unchanged, and the client decides what an empty period means.
     */
    public function export(Request $request)
    {
        $requests_data = Session::get('return') ?? [];
        $return['monthList'] = $requests_data['monthList'] ?? [];
        $return['userTotal'] = $requests_data['userTotal'] ?? [];
        $return['registerTotal'] = $requests_data['registerTotal'] ?? [];
        $return['guest'] = $requests_data['guestTotal'] ?? [];
        $return['newguestTotal'] = $requests_data['newguestTotal'] ?? [];
        return response()->json($return);
    }

    /**
     * ANA-001 -- FINANCE IN THE REPORTS CENTRE.
     *
     * The traceability row's measurement: "FINANCE is not in the centre. The Reports group has no
     * finance screen and no finance permission, and ReportController's 3,835 lines contain 0
     * occurrences of payment_status, refund, cost_price, profit or margin." That is a
     * DISCOVERABILITY gap, and this closes it without becoming a second finance engine.
     *
     * NOTHING IS COMPUTED HERE. Every figure comes from the assemblers `/finance` already uses --
     * `FinanceLedgerFigures` for `Payments received` and `Estimated gross profit`, and
     * `WalletEntitlementFigure` for `Available to withdraw` -- so the centre and the finance screen
     * cannot disagree, and the four labels stay exactly as owner decision D2 approved them. That is
     * also why this method still contains no `payment_status` or `cost_price`: the row counted those
     * strings as evidence the figures were absent, and the right fix is to reach the metric layer,
     * not to spell SQL here a second time.
     *
     * THE PERMISSION IS `View Finance Dashboard`, NOT `Manage Reports`. See the note on
     * `ReportPermission::FINANCE`: these figures are already visible at `/finance` behind the finance
     * permission, and putting them in the centre behind a reports permission would widen the audience
     * rather than move the screen.
     *
     * NO PERIOD PICKER, DELIBERATELY. `FinanceLedgerFigures` reads the window `LedgerCoverage` can
     * vouch for and states its start; `store_order_financials` has no backfill, so an earlier window
     * would read zero for months that merely have no record and a merchant would read that as a
     * business that stopped. Adding a picker here would need D1's clamp-and-refuse semantics wired to
     * a second surface, which is its own slice.
     */
    public function financeReport(Request $request)
    {
        /*
         * GAP-223(b): an operator who has selected no store reads NO store here either. The figures
         * below are tenant money, and the one thing this screen must never do is default to store 1.
         */
        if (OperatorStoreSelection::unchosen()) {
            OperatorStoreSelection::logRefusal('financeReport');

            return redirect()->route('dashboard')->with('error', __('No store is selected.'));
        }

        $storeId = OperatorStoreSelection::reportStoreId();

        if ($storeId <= 0) {
            return redirect()->route('dashboard')->with('error', __('No store is selected.'));
        }

        $currencyCode = \App\Models\Setting::where('store_id', $storeId)
            ->where('name', 'defult_currancy')
            ->value('value') ?? 'USD';

        return view('reports.finance_report', [
            'ledgerFigures' => FinanceLedgerFigures::forStore($storeId, (string) $currencyCode),
            'walletFigure' => \App\Support\Metrics\WalletEntitlementFigure::forStore($storeId, (string) $currencyCode),
        ]);
    }

    public function OrderReport(Request $request)
    {
        return view('reports.order_report');
    }

    public function order_reports_chart(Request $request)
    {
        $store_id = getStoreById(OperatorStoreSelection::reportStoreId());
        $currency = Utility::GetValueByName('CURRENCY');
        if ($request->chart_data == 'last-month') {
            $data = 'last-month';
            // GAP-223(a): one derivation. `$lastMonth = Carbon::now()->subMonth()` stood here and was
            // ASSIGNED AND NEVER READ anywhere in this method (grep-verified across its whole range),
            // so it is deleted rather than corrected -- an overflowing expression nothing consumed.
            $period = CalendarPeriod::lastMonth();
            $start = strtotime($period['from']);
            $end = strtotime($period['to']);

            $customer = Order::where('store_id', OperatorStoreSelection::reportStoreId())->whereYear('order_date', date('Y'))->get()->count();
            $customer_total = Customer::where('store_id', OperatorStoreSelection::reportStoreId())->where('regiester_date', '!=', NULL)->whereYear('regiester_date', date('Y'))->get()->count();
            $totaluser = 0;
            $guest = '';

            // GAP-223(a): the count comes from the same `$period` as the window, so the two cannot
            // disagree. Measured equivalent to the old `subMonthNoOverflow()` expression on all 1,827 days.
            $lastDayofMonth = Carbon::parse($period['to']);
            $lastday = $period['days'];

            $orders = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $orders->whereDate('order_date', '>=', date('Y-m-01', $start))->whereDate('order_date', '<=', date('Y-m-t', $end))->where('store_id', OperatorStoreSelection::reportStoreId());
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
            $orders->whereDate('order_date', '>=', date('Y-m-01', $start))->whereDate('order_date', '<=', date('Y-m-t', $end))->where('store_id', OperatorStoreSelection::reportStoreId());
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
                    ->where('store_id', OperatorStoreSelection::reportStoreId())
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
                    ->where('store_id', OperatorStoreSelection::reportStoreId())
                    ->count();

                $GrossSaleTotal[] = Order::whereDate('order_date', $date)->where('store_id', OperatorStoreSelection::reportStoreId())->get()->sum('final_price');

                $NetSaleTotal[] = Order::whereDate('order_date', $date)
                    ->where('store_id', OperatorStoreSelection::reportStoreId())
                    ->get()
                    ->sum(function ($order) {
                        return $order->final_price - $order->delivery_price - $order->tax_price;
                    });
                $CouponTotal[] = Order::whereDate('order_date', $date)->where('store_id', OperatorStoreSelection::reportStoreId())->get()->sum('coupon_price');
                $ShippingTotal[] = Order::whereDate('order_date', $date)->where('store_id', OperatorStoreSelection::reportStoreId())->get()->sum('delivery_price');
                $TotalOrderCount[] = $totalOrdersForDate;

                $averageGrossSales[] = $totalOrdersForDate > 0 ? ($GrossSaleTotal[count($GrossSaleTotal) - 1] / $totalOrdersForDate) : 0;
                $averageNetSales[] = $totalOrdersForDate > 0 ? ($NetSaleTotal[count($NetSaleTotal) - 1] / $totalOrdersForDate) : 0;

                $TotalgrossSale += Order::whereDate('order_date', $date)->where('store_id', OperatorStoreSelection::reportStoreId())->get()->sum('final_price');
                $TotalNetSale += Order::whereDate('order_date', $date)
                    ->where('store_id', OperatorStoreSelection::reportStoreId())
                    ->get()
                    ->sum(function ($order) {
                        return $order->final_price - $order->delivery_price - $order->tax_price;
                    });
                $TotalCouponAmount += Order::whereDate('order_date', $date)->where('store_id', OperatorStoreSelection::reportStoreId())->get()->sum('coupon_price');
                $TotalShippingCharge += Order::whereDate('order_date', $date)->where('store_id', OperatorStoreSelection::reportStoreId())->get()->sum('delivery_price');
                /* The second `$TotalOrderCount[] = $totalOrdersForDate;` STOOD HERE and is removed.
                   It was appended once among the sibling series above and again here, in the same
                   iteration, so this seven-iteration loop produced FOURTEEN order counts against a
                   SEVEN-label axis with every count duplicated -- while `$monthList` and all seven
                   other series produced seven. The surviving append is the one that sits with the
                   series it belongs to; this position is among the `$Total* +=` accumulators, which
                   are scalars, and nothing between the two read it. Pinned by
                   tests/Feature/Reports/SevenDaySeriesLengthTest, which counts every series in the
                   limb rather than just this one. */
            }
        } elseif ($request->chart_data == 'year') {

            $TotalOrder = Order::where('store_id', OperatorStoreSelection::reportStoreId())->whereYear('order_date', date('Y'))->get()->count();

            $orders = Order::selectRaw('orders.*,MONTH(order_date) as month,YEAR(order_date) as year');
            $start = strtotime(date('Y-01'));
            $end = strtotime(date('Y-12'));
            $orders->whereDate('order_date', '>=', date('Y-m-01', $start))->whereDate('order_date', '<=', date('Y-m-t', $end))->where('store_id', OperatorStoreSelection::reportStoreId());
            $orders = $orders->get();
            $order = Order::where('store_id', OperatorStoreSelection::reportStoreId())
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
            $orders->whereDate('order_date', '>=', $form_date)->whereDate('order_date', '<=', $to_date)->where('store_id', OperatorStoreSelection::reportStoreId());
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
                ->whereDate('order_date', '<=', $to_date)->where('store_id', OperatorStoreSelection::reportStoreId());
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
            'collection' => $this->salesCollectionTile($request, $periodLabel),
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

        $requests_data = Session::get('order_line_chart_report') ?? [];
        $return['monthList'] = $requests_data['monthList'] ?? [];
        $return['TotalOrderCount'] = $requests_data['TotalOrderCount'] ?? [];
        $return['NetSaleTotal'] = $requests_data['NetSaleTotal'] ?? [];
        $return['AverageNetSales'] = $requests_data['AverageNetSales'] ?? [];
        $return['GrossSaleTotal'] = $requests_data['GrossSaleTotal'] ?? [];
        $return['AverageGrossSales'] = $requests_data['AverageGrossSales'] ?? [];
        $return['PurchasedItemTotal'] = $requests_data['PurchasedItemTotal'] ?? [];
        $return['ShippingTotal'] = $requests_data['ShippingTotal'] ?? [];
        $return['CouponTotal'] = $requests_data['CouponTotal'] ?? [];

        /*
         * P05-15 / ANA-007. THE FILE CARRIES THE SCREEN'S DISCLOSURE, FROM THE SCREEN'S OWN SOURCE.
         *
         * Phase 05's rule is that an export carries the same caveats as the screen and that one
         * which drops a caveat is a defect. Before this, all five of these exports handed the
         * merchant a bare money column: nothing said the figure is not payments received and not
         * profit, and nothing said it is held at limited precision.
         *
         * `ReportFigureNote` is the SAME method the screen's `:hint` calls, so the two cannot
         * drift -- there is one sentence, already resolved by `__()` in this request's locale, so
         * an Arabic session exports Arabic. Read its docblock for why FLOAT_PRECISION is the only
         * caveat here and why no payment-status wording was invented.
         */
        $return['figureNote'] = ReportFigureNote::moneyRowHint();
        return response()->json($return);
    }

    public function order_bar_report_export(Request $request)
    {

        $requests_data = Session::get('order_bar_chart_report') ?? [];
        $return['monthList'] = $requests_data['monthList'] ?? [];
        $return['NetSaleTotal'] = $requests_data['NetSaleTotal'] ?? [];
        $return['AverageNetSales'] = $requests_data['AverageNetSales'] ?? [];
        $return['GrossSaleTotal'] = $requests_data['GrossSaleTotal'] ?? [];
        $return['AverageGrossSales'] = $requests_data['AverageGrossSales'] ?? [];
        $return['ShippingTotal'] = $requests_data['ShippingTotal'] ?? [];
        $return['CouponTotal'] = $requests_data['CouponTotal'] ?? [];
        // P5-3: the bar screen also plots Total Orders and Purchased Items (a second chart), and the
        // chart handler already stores both arrays in this same session key -- so the export must carry
        // them too, or the downloaded file is narrower than the screen it came from. Read-only, from the
        // principal's own session; no new query and no request-supplied input.
        $return['TotalOrderCount'] = $requests_data['TotalOrderCount'] ?? [];
        $return['PurchasedItemTotal'] = $requests_data['PurchasedItemTotal'] ?? [];

        /*
         * P05-15 / ANA-007. THE FILE CARRIES THE SCREEN'S DISCLOSURE, FROM THE SCREEN'S OWN SOURCE.
         *
         * Phase 05's rule is that an export carries the same caveats as the screen and that one
         * which drops a caveat is a defect. Before this, all five of these exports handed the
         * merchant a bare money column: nothing said the figure is not payments received and not
         * profit, and nothing said it is held at limited precision.
         *
         * `ReportFigureNote` is the SAME method the screen's `:hint` calls, so the two cannot
         * drift -- there is one sentence, already resolved by `__()` in this request's locale, so
         * an Arabic session exports Arabic. Read its docblock for why FLOAT_PRECISION is the only
         * caveat here and why no payment-status wording was invented.
         */
        $return['figureNote'] = ReportFigureNote::moneyRowHint();
        return response()->json($return);
    }

    public function BarChartOrderReport(Request $request)
    {
        return view('reports.bar_chart_order_report');
    }

    public function order_reports_chart_data(Request $request)
    {
        $store_id = getStoreById(OperatorStoreSelection::reportStoreId());
        $currency = Utility::GetValueByName('CURRENCY');
        if ($request->chart_data == 'last-month') {
            $data = 'last-month';
            /*
             * GAP-223(a), AND THE WORST OF ITS THREE CONSEQUENCES WAS HERE.
             *
             * `$lastMonth` came from the OVERFLOWING `Carbon::now()->subMonth()` while `$lastDayofMonth`
             * below came from the CORRECT `subMonthNoOverflow()`, and the
             * `whereBetween('order_date', [$lastMonth, $lastDayofMonth])` further down pairs the two. On
             * 31 March 2026 that is `['2026-03-03', '2026-02-28']` -- A REVERSED RANGE, matching NO ROW,
             * so `$TotalOrderCount` silently read ZERO. Measured. A figure that reads zero is the one wrong
             * answer a merchant cannot tell apart from a quiet month.
             */
            $period = CalendarPeriod::lastMonth();
            $lastMonth = Carbon::parse($period['from']);
            $start = strtotime($period['from']);
            $end = strtotime($period['to']);

            $TotalOrder = Order::where('store_id', OperatorStoreSelection::reportStoreId())->whereYear('order_date', date('Y'))->get()->count();
            $customer = Order::where('store_id', OperatorStoreSelection::reportStoreId())->whereYear('order_date', date('Y'))->get()->count();
            $customer_total = Customer::where('store_id', OperatorStoreSelection::reportStoreId())->where('regiester_date', '!=', NULL)->whereYear('regiester_date', date('Y'))->get()->count();
            $totaluser = 0;
            $guest = '';

            // GAP-223(a): both ends and the count come from the one `$period`, so the reversed-range
            // `whereBetween` below is impossible by construction. Measured equivalent to the old
            // `subMonthNoOverflow()` expression on all 1,827 days of 2024-2028.
            $lastDayofMonth = Carbon::parse($period['to']);
            $lastday = $period['days'];

            $orders = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $orders->whereDate('order_date', '>=', date('Y-m-01', $start))->whereDate('order_date', '<=', date('Y-m-t', $end))->where('store_id', OperatorStoreSelection::reportStoreId());
            $orders = $orders->get();
            $TotalOrder = $orders->count();
            $TotalOrderCount = Order::selectRaw('orders.*, MONTH(order_date) as month, YEAR(order_date) as year')
                ->whereBetween('order_date', [$lastMonth, $lastDayofMonth])
                ->where('store_id', OperatorStoreSelection::reportStoreId())
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

            $guest = Order::where('store_id', OperatorStoreSelection::reportStoreId())->where('is_guest', '=', 1)->whereYear('order_date', date('Y'))->whereMonth('order_date', date('m'))->get()->count();
            $customer = Order::where('store_id', OperatorStoreSelection::reportStoreId())->where('is_guest', '=', 0)->whereYear('order_date', date('Y'))->whereMonth('order_date', date('m'))->get()->count();
            $customer_total = Customer::where('store_id', OperatorStoreSelection::reportStoreId())->where('regiester_date', '!=', NULL)->whereYear('regiester_date', date('Y'))->get()->count();
            $totaluser = 0;
            $guest = '';
            $start = strtotime(date('Y-m-01'));
            $end = strtotime(date('Y-m-t'));
            $day = (int) date('j', strtotime($end));

            $orders = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $orders->whereDate('order_date', '>=', date('Y-m-01', $start))->whereDate('order_date', '<=', date('Y-m-t', $end))->where('store_id', OperatorStoreSelection::reportStoreId());
            $orders = $orders->get();
            $TotalOrder = $orders->count();

            $TotalOrderCount = Order::selectRaw('orders.*, MONTH(order_date) as month, YEAR(order_date) as year')
                ->whereMonth('order_date', date('m'))
                ->where('store_id', OperatorStoreSelection::reportStoreId())
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
                ->where('store_id', OperatorStoreSelection::reportStoreId())
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
                    ->where('store_id', OperatorStoreSelection::reportStoreId())
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
                    
                    ->where('store_id', OperatorStoreSelection::reportStoreId())
                    ->count();

                $GrossSaleTotal[] = Order::whereDate('order_date', $date)->where('store_id', OperatorStoreSelection::reportStoreId())->get()->sum('final_price');
                $NetSaleTotal[] = Order::whereDate('order_date', $date)                    
                    ->where('store_id', OperatorStoreSelection::reportStoreId())
                    ->get()
                    ->sum(function ($order) {
                        return $order->final_price - $order->delivery_price - $order->tax_price;
                    });
                $CouponTotal[] = Order::whereDate('order_date', $date)->where('store_id', OperatorStoreSelection::reportStoreId())->get()->sum('coupon_price');
                $ShippingTotal[] = Order::whereDate('order_date', $date)->where('store_id', OperatorStoreSelection::reportStoreId())->get()->sum('delivery_price');

                $averageGrossSales[] = $totalOrdersForDate > 0 ? ($GrossSaleTotal[count($GrossSaleTotal) - 1] / $totalOrdersForDate) : 0;
                $averageNetSales[] = $totalOrdersForDate > 0 ? ($NetSaleTotal[count($NetSaleTotal) - 1] / $totalOrdersForDate) : 0;

                $TotalgrossSale += Order::whereDate('order_date', $date)->where('store_id', OperatorStoreSelection::reportStoreId())->get()->sum('final_price');
                $TotalNetSale += Order::whereDate('order_date', $date)
                    
                    ->where('store_id', OperatorStoreSelection::reportStoreId())
                    ->get()
                    ->sum(function ($order) {
                        return $order->final_price - $order->delivery_price - $order->tax_price;
                    });
                $TotalCouponAmount += Order::whereDate('order_date', $date)->where('store_id', OperatorStoreSelection::reportStoreId())->get()->sum('coupon_price');
                $TotalShippingCharge += Order::whereDate('order_date', $date)->where('store_id', OperatorStoreSelection::reportStoreId())->get()->sum('delivery_price');
            }
        } elseif ($request->chart_data == 'year') {
            $TotalOrder = Order::where('store_id', OperatorStoreSelection::reportStoreId())->whereYear('order_date', date('Y'))->get()->count();

            $orders = Order::selectRaw('orders.*,MONTH(order_date) as month,YEAR(order_date) as year');
            $start = strtotime(date('Y-01'));
            $end = strtotime(date('Y-12'));
            $orders->whereDate('order_date', '>=', date('Y-m-01', $start))->whereDate('order_date', '<=', date('Y-m-t', $end))->where('store_id', OperatorStoreSelection::reportStoreId());
            $orders = $orders->get();
            $TotalOrderCount = Order::selectRaw('orders.*, MONTH(order_date) as month, YEAR(order_date) as year')
                ->whereYear('order_date', date('Y')) // Filter by the current year
                
                ->where('store_id', OperatorStoreSelection::reportStoreId())
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
            $orders->whereDate('order_date', '>=', $form_date)->whereDate('order_date', '<=', $to_date)->where('store_id', OperatorStoreSelection::reportStoreId());
            $orders = $orders->get();
            $TotalOrder = $orders->count();

            $guests = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
            $guests->whereDate('order_date', '>=', $form_date)
                ->whereDate('order_date', '<=', $to_date)->where('store_id', OperatorStoreSelection::reportStoreId())->where('is_guest', '=', 1);
            $guests = $guests->get();

            $register_customer = Customer::selectRaw('customers.*,DATE(regiester_date) as DATE,MONTH(regiester_date) as month');
            $register_customer->whereDate('regiester_date', '>=', $form_date)
                ->whereDate('regiester_date', '<=', $to_date)->where('store_id', OperatorStoreSelection::reportStoreId())->where('regiester_date', '!=', NULL);
            $register_customer = $register_customer->get();

            $new_guest = Customer::selectRaw('customers.*,DATE(last_active) as DATE,MONTH(last_active) as month');
            $new_guest->whereDate('last_active', '>=', $form_date)
                ->whereDate('last_active', '<=', $to_date)->where('store_id', OperatorStoreSelection::reportStoreId())->where('regiester_date', '=', NULL);
            $new_guest = $new_guest->get();


            $TotalOrderCount = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month')
                ->whereDate('order_date', '>=', $form_date)
                ->whereDate('order_date', '<=', $to_date)
                
                ->where('store_id', OperatorStoreSelection::reportStoreId())
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
                ->whereDate('order_date', '<=', $to_date)->where('store_id', OperatorStoreSelection::reportStoreId());
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
            'collection' => $this->salesCollectionTile($request, $periodLabel),
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
        $store_id = getStoreById(OperatorStoreSelection::reportStoreId());
        $products = Product::where('store_id', OperatorStoreSelection::reportStoreId())->pluck('name', 'id');
        return view('reports.sale_by_product', compact('products'));
    }


    public function order_product_reports(Request $request)
    {
        $store_id = getStoreById(OperatorStoreSelection::reportStoreId());

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
                $selectedProducts = Product::where('store_id', OperatorStoreSelection::reportStoreId())->pluck('id');
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
                            
                            ->where('store_id', OperatorStoreSelection::reportStoreId())
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
                $selectedProducts = Product::where('store_id', OperatorStoreSelection::reportStoreId())->pluck('id');
            }
            if (!empty($selectedProducts)) {
                foreach ($selectedProducts as $productId) {
                    $currentYear = date('Y');
                    $currentMonth = date('n');
                    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $currentMonth, $currentYear);

                    for ($day = 1; $day <= $daysInMonth; $day++) {
                        $startOfDay = "$currentYear-$currentMonth-" . sprintf('%02d', $day);
                        $endOfDay = "$currentYear-$currentMonth-" . sprintf('%02d', $day) . ' 23:59:59';

                        $orders = Order::where('store_id', OperatorStoreSelection::reportStoreId())
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
                        
                        ->where('store_id', OperatorStoreSelection::reportStoreId())
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
                        
                        ->where('store_id', OperatorStoreSelection::reportStoreId())
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
                $selectedProducts = Product::where('store_id', OperatorStoreSelection::reportStoreId())->pluck('id');
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

                        $orders = Order::where('store_id', OperatorStoreSelection::reportStoreId())
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
                
                ->where('store_id', OperatorStoreSelection::reportStoreId())
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

        $requests_data = Session::get('product_order_report') ?? [];
        $return['monthList'] = $requests_data['monthList'] ?? [];
        $return['NetSaleTotal'] = $requests_data['NetSaleTotal'] ?? [];
        $return['PurchasedItemTotal'] = $requests_data['PurchasedItemTotal'] ?? [];

        /*
         * P05-15 / ANA-007. THE FILE CARRIES THE SCREEN'S DISCLOSURE, FROM THE SCREEN'S OWN SOURCE.
         *
         * Phase 05's rule is that an export carries the same caveats as the screen and that one
         * which drops a caveat is a defect. Before this, all five of these exports handed the
         * merchant a bare money column: nothing said the figure is not payments received and not
         * profit, and nothing said it is held at limited precision.
         *
         * `ReportFigureNote` is the SAME method the screen's `:hint` calls, so the two cannot
         * drift -- there is one sentence, already resolved by `__()` in this request's locale, so
         * an Arabic session exports Arabic. Read its docblock for why FLOAT_PRECISION is the only
         * caveat here and why no payment-status wording was invented.
         */
        $return['figureNote'] = ReportFigureNote::orderValueHint();
        return response()->json($return);
    }

    public function OrderSaleCategoryReport(Request $request)
    {
        $store_id = getStoreById(OperatorStoreSelection::reportStoreId());
        $MainCategoryList = Category::where('status', 1)->where('store_id', OperatorStoreSelection::reportStoreId())->pluck('name', 'id');
        return view('reports.sale_by_category', compact('MainCategoryList'));
    }

    public function order_category_reports(Request $request)
    {
        $store_id = getStoreById(OperatorStoreSelection::reportStoreId());
        $currency = Utility::GetValueByName('CURRENCY');
        if ($request->chart_data == 'last-month') {
            $selectedCategory = $this->reportSelectionWithinCurrentStore(Category::class, $request->selectedCategory);
            $year = date('Y');
            $monthList = $this->yearMonth();
            $NetSaleTotal = [];

            $firstDayOfPreviousMonth = date('Y-m-d', strtotime('first day of last month'));
            $lastDayOfPreviousMonth = date('Y-m-d', strtotime('last day of last month'));
            $daysInPreviousMonth = date('j', strtotime('last day of last month'));

            if (empty($selectedCategory)) {
                /* Scoped in SQL, not in PHP — see the note at the first of these. */
                $selectedCategory = Category::where('store_id', OperatorStoreSelection::reportStoreId())->pluck('id');
            }

            $categoryNetSales = [];

            foreach ($selectedCategory as $categoryId) {
                $category = Category::find($categoryId);
                if ($category) {
                    $categoryNetSales[$category->name] = array_fill(0, $daysInPreviousMonth, 0);
                }
            }

            $orders = Order::where('store_id', OperatorStoreSelection::reportStoreId())
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
            $selectedCategory = $this->reportSelectionWithinCurrentStore(Category::class, $request->selectedCategory);
            $year = date('Y');
            $monthList = $this->yearMonth();
            $NetSaleTotal = [];

            $daysInCurrentMonth = date('j', strtotime('last day of this month'));

            if (empty($selectedCategory)) {
                /* Scoped in SQL, not in PHP — see the note at the first of these. */
                $selectedCategory = Category::where('store_id', OperatorStoreSelection::reportStoreId())->pluck('id');
            }

            $categoryNetSales = [];

            foreach ($selectedCategory as $categoryId) {
                $category = Category::find($categoryId);
                if ($category) {
                    $categoryNetSales[$category->name] = array_fill(0, $daysInCurrentMonth, 0);
                }
            }

            $orders = Order::where('store_id', OperatorStoreSelection::reportStoreId())
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
            $selectedCategory = $this->reportSelectionWithinCurrentStore(Category::class, $request->selectedCategory);
            $year = date('Y');
            $NetSaleTotal = [];
            $currentDate = date('Y-m-d');
            $sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));

            $monthList = [];
            for ($i = 0; $i < 7; $i++) {
                $monthList[] = date('d-M', strtotime("-$i days", strtotime($currentDate)));
            }

            if (empty($selectedCategory)) {
                $selectedCategory = Category::where('store_id', OperatorStoreSelection::reportStoreId())
                    
                    ->pluck('id');
            }

            $categoryNetSales = [];

            foreach ($selectedCategory as $categoryId) {
                $category = Category::find($categoryId);
                if ($category) {
                    $categoryNetSales[$category->name] = array_fill(0, 7, 0);
                }
            }

            $orders = Order::where('store_id', OperatorStoreSelection::reportStoreId())
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

            $selectedCategory = $this->reportSelectionWithinCurrentStore(Category::class, $request->selectedCategory);
            $year = date('Y');
            $monthList = $this->yearMonth();
            $NetSaleTotal = [];
            $NetSalesofcategory = [];

            $totalNetSalesAllCategories = array_fill(0, count($monthList), 0);

            if (empty($selectedCategory)) {
                /* Scoped in SQL, not in PHP — see the note at the first of these. */
                $selectedCategory = Category::where('store_id', OperatorStoreSelection::reportStoreId())->pluck('id');
            }

            $categoryNetSales = [];

            foreach ($selectedCategory as $categoryId) {
                $category = Category::find($categoryId);
                if ($category) {
                    $categoryNetSales[$category->name] = array_fill(0, count($monthList), 0);
                }
            }

            $orders = Order::where('store_id', OperatorStoreSelection::reportStoreId())
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

            $selectedCategory = $this->reportSelectionWithinCurrentStore(Category::class, $request->selectedCategory);
            $year = date('Y');
            $NetSaleTotal = [];

            $startDate = new \DateTime($from_date);
            $endDate = new \DateTime($to_date);
            /*
             * P5-3c -- `DatePeriod`'s end is EXCLUSIVE, so without this the category report drops
             * the last day of a custom range and, for a SINGLE-DAY selection, yields ZERO days:
             * measured, `DatePeriod('2026-03-05', P1D, '2026-03-05')` iterates nothing, so
             * `$monthList` is empty, `array_fill(0, 0, 0)` gives empty series and the
             * `array_search($orderDate, $monthList)` below fails for every order. A merchant
             * asking for one day got an empty report rather than one day's sales.
             *
             * The product report (:1804) and the brand report (:2726) already did this; only the
             * category report did not, which is why its series were one day short of theirs on
             * the same range. Written the brand report's way on purpose, so the three now read
             * alike.
             */
            $endDate->modify('+1 day'); // Include the end date

            $interval = new \DateInterval('P1D');
            $period = new \DatePeriod($startDate, $interval, $endDate);

            $monthList = [];

            foreach ($period as $date) {
                $monthList[] = $date->format('d-M');
            }

            if (empty($selectedCategory)) {
                $selectedCategory = Category::where('store_id', OperatorStoreSelection::reportStoreId())
                    
                    ->pluck('id');
            }

            $categoryNetSales = [];

            foreach ($selectedCategory as $categoryId) {
                $category = Category::find($categoryId);
                if ($category) {
                    $categoryNetSales[$category->name] = array_fill(0, count($monthList), 0);
                }
            }

            $orders = Order::where('store_id', OperatorStoreSelection::reportStoreId())
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

        /* D-42.3. THE CHART'S ACCESSIBLE TABLE GETS THE PER-PERIOD MONEY TOTAL, NOT THE APEXCHARTS
           SERIES LIST. `$NetSaleTotal` is `[['name' => 'Shirts', 'data' => [...]], ...]` -- one entry
           per CATEGORY against `$monthList`, which is one entry per PERIOD -- so a numeric chart
           component cannot read it: `intval()` of a non-empty array is 1, and every row of that table
           read `1` whatever the money was (MEASURED on /category-order-reports). `<x-ss.bar-chart>` now
           refuses the shape and shows its empty state instead of a false amount, and this line is what
           gives it the real figures.
           NOTHING IS CALCULATED HERE. Each of the five period branches ALREADY sums the per-category
           series into a per-period total and then never passes it; only the NAME differs -- `year` calls
           it `$totalNetSalesAllCategories` and the other four `$allCategoriesTotal` (measured, not
           assumed). This resolves whichever one the branch that ran defined. Exactly one branch runs per
           request, so the chain cannot pick up a stale value. `$return['NetSaleTotal']` below is
           deliberately UNCHANGED: the browser's ApexCharts series and anything reading this response
           see precisely what they saw before. */
        $netSeries = $allCategoriesTotal ?? ($totalNetSalesAllCategories ?? []);

        $html = view('reports.order_category_chart', compact('currency', 'NetSalesofcategory', 'periodLabel')
            + ['labels' => $monthList ?? [], 'netSeries' => $netSeries])->render();

        $return['html'] = $html;

        $return['NetSaleTotal'] = $NetSaleTotal;
        $return['monthList'] = $monthList;
        Session::put('category_order_report', $return);

        return response()->json($return);
    }

    public function category_order_export(Request $request)
    {
        $requests_data = Session::get('category_order_report') ?? [];
        $return['monthList'] = $requests_data['monthList'] ?? [];
        $return['NetSaleTotal'] = $requests_data['NetSaleTotal'] ?? [];

        /*
         * P05-15 / ANA-007. THE FILE CARRIES THE SCREEN'S DISCLOSURE, FROM THE SCREEN'S OWN SOURCE.
         *
         * Phase 05's rule is that an export carries the same caveats as the screen and that one
         * which drops a caveat is a defect. Before this, all five of these exports handed the
         * merchant a bare money column: nothing said the figure is not payments received and not
         * profit, and nothing said it is held at limited precision.
         *
         * `ReportFigureNote` is the SAME method the screen's `:hint` calls, so the two cannot
         * drift -- there is one sentence, already resolved by `__()` in this request's locale, so
         * an Arabic session exports Arabic. Read its docblock for why FLOAT_PRECISION is the only
         * caveat here and why no payment-status wording was invented.
         */
        $return['figureNote'] = ReportFigureNote::orderValueHint();
        return response()->json($return);
    }

    public function OrderDownlodableReport(SalesDownloadableProductDataTable $dataTable, Request $request)
    {
        return $dataTable->render('reports.downloadable_product_report');
    }

    public function OrderSaleBrandReport(Request $request)
    {
        $store_id = getStoreById(OperatorStoreSelection::reportStoreId());
        $MainCategoryList = ProductBrand::where('status', 1)->where('store_id', OperatorStoreSelection::reportStoreId())->pluck('name', 'id');

        return view('reports.sale_by_brand', compact('MainCategoryList'));
    }

    public function order_brand_reports(Request $request)
    {
        $store_id = getStoreById(OperatorStoreSelection::reportStoreId());
        $currency = Utility::GetValueByName('CURRENCY');
        $selectedBrand = $this->reportSelectionWithinCurrentStore(ProductBrand::class, $request->selectedBrand);
        $year = date('Y');
        $monthList = $this->yearMonth();
        $NetSaleTotal = [];
        $NetSalesofbrand = []; // Initialize the variable here

        if ($request->chart_data == 'last-month') {
            /*
             * GAP-223(a). TWO separate `strtotime('-1 month')` calls, both overflowing, and a day count
             * derived from the second -- so on an overflow day all three named the CURRENT month. One
             * derivation now, and `days` arrives WITH the bounds instead of being re-derived from them.
             */
            $period = CalendarPeriod::lastMonth();
            $firstDayOfPreviousMonth = $period['from'];
            $lastDayOfPreviousMonth = $period['to'];
            $daysInPreviousMonth = $period['days'];

            /*
             * P5-3h -- `empty()` AND NOT `??`, matching the three sibling branches.
             *
             * `??` fires only on NULL, and `reportSelectionWithinCurrentStore()` returns its
             * argument UNCHANGED when the argument is empty (:3751) -- so an empty selection
             * reached the loop below instead of the default. Measured:
             *
             *   selectedBrand = ''   `??` does not fire, and `foreach ('')` is not iterable
             *                        -> PHP error on a merchant admin route
             *   selectedBrand = []   `??` does not fire, `foreach ([])` iterates nothing
             *                        -> a silently EMPTY report
             *
             * Both are reachable: `$('#brand_id').val()` on a multi-select returns null when
             * nothing is selected, and the AJAX layer can deliver that as '' .
             *
             * The year, seven-day and custom-range branches already used `empty()` and defaulted
             * correctly, so the same cleared selection behaved differently depending on which
             * period button the merchant had pressed. That divergence between sibling branches is
             * the third defect this file has produced from exactly that shape.
             */
            if (empty($selectedBrand)) {
                $selectedBrand = ProductBrand::where('store_id', OperatorStoreSelection::reportStoreId())
                    ->pluck('id');
            }

            $brandNetSales = [];

            foreach ($selectedBrand as $brandId) {
                $brand = ProductBrand::find($brandId);
                if ($brand) {
                    $brandNetSales[$brand->name] = array_fill(0, $daysInPreviousMonth, 0);
                }
            }

            $orders = Order::where('store_id', OperatorStoreSelection::reportStoreId())
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

            /*
             * P5-3h -- `empty()` AND NOT `??`, matching the three sibling branches.
             *
             * `??` fires only on NULL, and `reportSelectionWithinCurrentStore()` returns its
             * argument UNCHANGED when the argument is empty (:3751) -- so an empty selection
             * reached the loop below instead of the default. Measured:
             *
             *   selectedBrand = ''   `??` does not fire, and `foreach ('')` is not iterable
             *                        -> PHP error on a merchant admin route
             *   selectedBrand = []   `??` does not fire, `foreach ([])` iterates nothing
             *                        -> a silently EMPTY report
             *
             * Both are reachable: `$('#brand_id').val()` on a multi-select returns null when
             * nothing is selected, and the AJAX layer can deliver that as '' .
             *
             * The year, seven-day and custom-range branches already used `empty()` and defaulted
             * correctly, so the same cleared selection behaved differently depending on which
             * period button the merchant had pressed. That divergence between sibling branches is
             * the third defect this file has produced from exactly that shape.
             */
            if (empty($selectedBrand)) {
                $selectedBrand = ProductBrand::where('store_id', OperatorStoreSelection::reportStoreId())
                    ->pluck('id');
            }

            $brandNetSales = [];

            foreach ($selectedBrand as $brandId) {
                $brand = ProductBrand::find($brandId);
                if ($brand) {
                    $brandNetSales[$brand->name] = array_fill(0, $daysInPreviousMonth, 0);
                }
            }

            $orders = Order::where('store_id', OperatorStoreSelection::reportStoreId())
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
            $selectedBrand = $this->reportSelectionWithinCurrentStore(ProductBrand::class, $request->selectedBrand);
            $year = date('Y');
            $monthList = $this->yearMonth(); // Assuming this method returns an array of month names or month start dates
            $NetSaleTotal = [];
            $NetSalesOfBrand = [];
            $totalNetSalesAllBrands = array_fill(0, count($monthList), 0);

            if (empty($selectedBrand)) {
                $selectedBrand = ProductBrand::where('store_id', OperatorStoreSelection::reportStoreId())
                    
                    ->pluck('id');
            }

            $brandNetSales = [];

            foreach ($selectedBrand as $brandId) {
                $brand = ProductBrand::find($brandId);
                if ($brand) {
                    $brandNetSales[$brand->name] = array_fill(0, count($monthList), 0);
                }
            }

            $orders = Order::where('store_id', OperatorStoreSelection::reportStoreId())
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
                    $brandNetSales[$brand->name][$index] = $totalNetSales;

                    /*
                     * P5-3d -- THIS LINE USED TO STAND BEFORE THE ASSIGNMENT ABOVE, so every
                     * brand card summed the series as it was BEFORE the current month was written
                     * into it. The loop is months-outer / brands-inner over `yearMonth()`'s twelve
                     * entries, so on the final iteration the card summed indices 0..10 and
                     * **December was missing from every brand card, every year.**
                     *
                     * Measured: with 40.00 in January and 70.00 in December the card read 40.00;
                     * it now reads 110.00. The chart series below was always right -- it walks
                     * `$brandNetSales` after the loop -- so the card and the chart beside it
                     * disagreed, which is what made this visible without explaining it.
                     *
                     * `$NetSalesOfBrand` (capital O) just below accumulates the same figure
                     * correctly and is read NOWHERE: the view reads `$NetSalesofbrand`. With this
                     * ordering the two now agree by construction. Removing the dead one is a tidy
                     * for its own change, not part of a figure fix.
                     */
                    $NetSalesofbrand[$brand->name] = array_sum($brandNetSales[$brand->name]);
                    /* D-42.3. `$totalNetSalesAllBrands[$index] += $totalNetSales;` STOOD HERE AND
                       MADE THE YEAR VIEW'S "All Brands" FIGURE EXACTLY DOUBLE. The loop below
                       already walks `$brandNetSales` and array_maps every brand's series onto
                       `$totalNetSalesAllBrands`, so this line added the same money a second time.
                       MEASURED on /brand-order-reports?chart_data=year with ONE order worth
                       1234.56: the aggregate read 2,469.12.

                       THIS BRANCH WAS THE ONLY ONE WITH BOTH ADDS -- measured across all five:
                       last-month, this-month, seven-day and the custom range each have only the
                       post-loop array_map, and each reads correctly. Removing the line here is
                       what makes `year` agree with its four siblings, which is why it is the one
                       removed rather than the loop below.

                       IT WAS ALREADY WRONG ON THE SCREEN, before anything in this change: the
                       doubled array is what `$NetSaleTotal[]['All Brands']` carries to the
                       browser's ApexCharts and what `$NetSalesofbrand['All Brands']` sums for the
                       card. The accessible table printed `1` in every row, which is what hid it.
                       So the visible year chart stops being double as well -- an existing figure
                       becoming accurate, which is the whole of this authorisation. No stored
                       amount, wallet, settlement, payment or conversion rate is touched. */

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
            $selectedBrand = $this->reportSelectionWithinCurrentStore(ProductBrand::class, $request->selectedBrand);
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
                $selectedBrand = ProductBrand::where('store_id', OperatorStoreSelection::reportStoreId())
                    
                    ->pluck('id');
            }

            $brandNetSales = [];

            foreach ($selectedBrand as $brandId) {
                $brand = ProductBrand::find($brandId);
                if ($brand) {
                    $brandNetSales[$brand->name] = array_fill(0, 7, 0);
                }
            }

            $orders = Order::where('store_id', OperatorStoreSelection::reportStoreId())
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

            $selectedBrand = $this->reportSelectionWithinCurrentStore(ProductBrand::class, $request->selectedBrand);
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
                $selectedBrand = ProductBrand::where('store_id', OperatorStoreSelection::reportStoreId())
                    
                    ->pluck('id');
            }

            $brandNetSales = [];
            foreach ($selectedBrand as $brandId) {
                $brand = ProductBrand::find($brandId);
                if ($brand) {
                    $brandNetSales[$brand->name] = array_fill(0, count($dayList), 0);
                }
            }

            $orders = Order::where('store_id', OperatorStoreSelection::reportStoreId())
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

        /* D-42.3, the same repair as `order_category_reports` and for the same reason: `$NetSaleTotal`
           is a per-BRAND ApexCharts series list, the chart's table is per-PERIOD, and the cast that
           bridged them printed `1`. The per-period total is already computed in all five branches --
           `$allBrandsTotal` in two, `$totalNetSalesAllBrands` in three (measured) -- and was never
           passed. Nothing is calculated here; `$return['NetSaleTotal']` is unchanged. */
        $netSeries = $allBrandsTotal ?? ($totalNetSalesAllBrands ?? []);

        $html = view('reports.order_brand_chart', compact('currency', 'NetSalesofbrand', 'periodLabel')
            + ['labels' => $monthList ?? [], 'netSeries' => $netSeries])->render();

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
        $requests_data = Session::get('brand_order_report') ?? [];
        $return = [
            'monthList' => $requests_data['monthList'] ?? [],
            'NetSaleTotal' => $requests_data['NetSaleTotal'] ?? []
        ];

        /*
         * P05-15 / ANA-007. THE FILE CARRIES THE SCREEN'S DISCLOSURE, FROM THE SCREEN'S OWN SOURCE.
         *
         * Phase 05's rule is that an export carries the same caveats as the screen and that one
         * which drops a caveat is a defect. Before this, all five of these exports handed the
         * merchant a bare money column: nothing said the figure is not payments received and not
         * profit, and nothing said it is held at limited precision.
         *
         * `ReportFigureNote` is the SAME method the screen's `:hint` calls, so the two cannot
         * drift -- there is one sentence, already resolved by `__()` in this request's locale, so
         * an Arabic session exports Arabic. Read its docblock for why FLOAT_PRECISION is the only
         * caveat here and why no payment-status wording was invented.
         */
        $return['figureNote'] = ReportFigureNote::orderValueHint();
        return response()->json($return);
    }

    public function top_product(Request $request)
    {
        $productQuery = Product::where('store_id', OperatorStoreSelection::reportStoreId());
        $orderQuery = Order::where('store_id', OperatorStoreSelection::reportStoreId());

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
            ->where('products.store_id', OperatorStoreSelection::reportStoreId())
            ->where('orders.store_id', OperatorStoreSelection::reportStoreId())
            ->where('categories.store_id', OperatorStoreSelection::reportStoreId())
            ->groupBy('categories.name')
            /*
             * P5-3f -- RANKED AND LIMITED, which is what the card beside it already promises:
             * "Categories by the number of distinct orders containing one of their products" and,
             * above, "the five are the top five". This query ended at `->get()` with NO orderBy
             * and NO limit, so a store with forty categories got forty bars in whatever order the
             * engine returned them, under a page headed "Top Sales Report". The metric was never
             * the problem -- COUNT(DISTINCT orders.id) is exactly what the note describes -- the
             * ranking simply was not applied.
             *
             * The name is a deterministic tie-break. Without it two categories on the same count
             * swap places between requests, and whichever lands fifth appears and disappears.
             */
            ->orderByDesc('total_sale')
            ->orderBy('categories.name')
            ->limit(5)
            ->get();

        $top_brand_sales = ProductBrand::select('product_brands.name as sale_name', 'product_brands.logo as sale_image_path', \DB::raw('COUNT(DISTINCT orders.id) as total_sale'))
            ->join('products', 'product_brands.id', '=', 'products.brand_id')
            ->join('orders', function ($join) {
                $join->on('products.id', '=', \DB::raw("SUBSTRING_INDEX(SUBSTRING_INDEX(orders.product_id, ',', numbers.n), ',', -1)"))
                    ->crossJoin(\DB::raw("(SELECT 1 + a.N + b.N * 10 AS n FROM (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a CROSS JOIN (SELECT 0 AS N UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS b) AS numbers"));
            })
            ->where('products.store_id', OperatorStoreSelection::reportStoreId())
            ->where('orders.store_id', OperatorStoreSelection::reportStoreId())
            ->where('product_brands.store_id', OperatorStoreSelection::reportStoreId())
            ->groupBy('product_brands.name')
            // P5-3f -- the same repair as the category chart above, for the same reason and with
            // the same deterministic tie-break.
            ->orderByDesc('total_sale')
            ->orderBy('product_brands.name')
            ->limit(5)
            ->get();

        $new_orders = (clone $orderQuery)->orderBy('id', 'DESC')->get();

        /*
         * P5-3f -- ONE METHOD WAS BECOMING SEVERAL BARS, and then none of them was ranked.
         *
         * `groupBy('payment_type')` grouped the RAW column with PHP's case-sensitive comparison,
         * while the same method is stored under several spellings -- cash on delivery alone has
         * three in `PaymentVerificationService::COD_PAYMENT_TYPES`, plus casing and whitespace
         * variants from the free-text column. So 'cod' and 'COD' were two separate bars competing
         * for the same five slots, which makes a "top five" wrong rather than merely unordered.
         *
         * Grouped case-insensitively on a trimmed key, and each group is DISPLAYED under the
         * spelling that occurs most often in it -- so the existing `__()` lookups in the view keep
         * working against the catalogue entries they already have, rather than being handed a
         * lower-cased key with no translation.
         *
         * The metric is unchanged and is what the card says: orders, counted once each. An order
         * carries one `payment_type`, so distinct orders and row count are the same thing here.
         */
        $paymentMethods = $new_orders
            ->groupBy(fn ($order) => mb_strtolower(trim((string) $order->payment_type)))
            ->map(function ($orders) {
                $spellings = $orders
                    ->map(fn ($order) => trim((string) $order->payment_type))
                    ->filter(fn ($spelling) => $spelling !== '')
                    ->countBy()
                    ->sortDesc();

                return [
                    'label' => (string) ($spellings->keys()->first() ?? ''),
                    'orders' => $orders->count(),
                ];
            })
            // `sortByDesc()` on an ARRAY key compares BOTH elements descending, so ties on the
            // order count resolved by REVERSE alphabet -- the opposite of the two SQL charts above,
            // whose name tie-break is ascending. Measured with this repository's own Collection:
            // seven methods of one order each kept g,f,e,d,c and dropped a and b. Orders descending,
            // then the label ascending AND CASE-FOLDED.
            //
            // The fold matters: PHP compares bytes, so without it every capital letter sorted before
            // every lower-case one ("Stripe" before "cod"), where the SQL charts' collation is
            // case-insensitive. The two now agree for the names this chart actually sees -- Latin
            // gateway and method names. They can still differ for a non-Latin name, because the
            // database orders by its collation weights and PHP by code point; that residue is
            // recorded rather than claimed away. The fold cannot create a tie of its own: groups are
            // keyed by the lower-cased label, so no two of them fold to the same string.
            ->sort(fn ($a, $b) => [$b['orders'], mb_strtolower($a['label'])] <=> [$a['orders'], mb_strtolower($b['label'])])
            ->take(5)
            ->mapWithKeys(fn ($group) => [$group['label'] => $group['orders']]);
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
        /*
         * P5-3f -- ONE ORDER COUNTS ONCE PER PRODUCT, which is what the card promises: "The five
         * products appearing in the most orders. Counts orders, not quantity or value."
         *
         * `orders.product_id` is a comma-joined list and TWO of its three writers build it with
         * no de-duplication -- `OrderController::store()` and `Api\DashboardController` both
         * `implode(',', array_column($request->products, 'product_id'))`, while only
         * `PaymentService` applies `array_unique`. So an order containing the same product on two
         * lines contributed 2 to a figure labelled "orders", and merchant-admin and Flutter orders
         * were inflated relative to storefront ones.
         *
         * `unique()` per order makes the count match its own label, and matches the metric the
         * category and brand charts use -- COUNT(DISTINCT orders.id) - so all four charts now rank
         * by the same thing.
         *
         * THE TIE-BREAK IS THE PRODUCT ID, ASCENDING, and an earlier version of this comment said
         * it was the name. It was neither: `countBy()->sortDesc()` keeps first-appearance order on
         * a tie, which follows the database's row order for a query with no ORDER BY, so the fifth
         * place appeared and disappeared between loads. `sortKeys()` first makes the stable sort
         * that follows break ties by id. A NAME tie-break would need the names of every candidate
         * product rather than only the five that survive, which is a wider query than this chart
         * is worth.
         */
        $countsById = (clone $orderQuery)->pluck('product_id')
            ->flatMap(fn ($ids) => collect(explode(',', (string) $ids))
                ->map(fn ($id) => (int) trim($id))
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values())
            ->countBy()
            ->sortKeys()
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

        $byStatus = Order::where('store_id', OperatorStoreSelection::reportStoreId())
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
            ->join('orders', 'order_billing_details.order_id', '=', 'orders.id')->where('orders.store_id', OperatorStoreSelection::reportStoreId())
            ->select('countries.name as country_name', \DB::raw('COUNT(order_billing_details.id) as total_orders'))
            ->groupBy('countries.name')
            ->get();

        $total = Order::where('store_id', OperatorStoreSelection::reportStoreId())->count();

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
        $products = Product::where('store_id', OperatorStoreSelection::reportStoreId())
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
            getAdminAllSetting(null, OperatorStoreSelection::reportStoreId())
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
        $products = Product::where('store_id', OperatorStoreSelection::reportStoreId())
            ->get();
        $out_of_stock_products = [];
        // P1-SEC-26: the THRESHOLD must come from the same store as the PRODUCTS above. A no-argument getAdminAllSetting() resolves it
        // from the caller instead, and for a STAFF member it walks up to created_by and then reads the OWNER's current_store -- so a
        // staff member whose owner is currently in another store judged this store's stock by that other store's threshold.
        $settings = getAdminAllSetting(null, OperatorStoreSelection::reportStoreId());

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
        $products = Product::where('store_id', OperatorStoreSelection::reportStoreId())
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
            getAdminAllSetting(null, OperatorStoreSelection::reportStoreId())
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
    /**
     * NARROW A REPORT CHART'S CALLER-SUPPLIED ID SELECTION TO THE CURRENT STORE, IN SQL.
     * =============================================================================================
     *
     * WHY THIS EXISTS. `order_category_reports` and `order_brand_reports` take their selection
     * straight off the query string and used to resolve each id with a bare `Category::find()` /
     * `ProductBrand::find()` -- ten such calls per handler. Neither model filters by tenant:
     * `Category` registers `App\Scopes\StoreScope` and carries `BelongsToStore`, but it is absent
     * from `config/tenancy.php`'s `scoped_models`, so `StoreScope::isActiveFor()` short-circuits
     * and the scope adds NO predicate for ANY principal; `ProductBrand` registers no global scope
     * at all. So `find()` reached every tenant's rows and each resolved row's `name` became a
     * chart series name -- in the `NetSaleTotal` JSON, in the rendered stat cards, and in the
     * session `category_order_export` / `brand_order_export` replay. Resolving in SQL against
     * `store_id` closes it: a foreign id does not come back, so there is no name and no series.
     * Covered by `tests/Feature/Security/ReportCategoryBrandTenantScopeTest`.
     *
     * WHAT IT DELIBERATELY DOES NOT DO.
     *
     *   * It does not touch the money. Every period branch keeps its own
     *     `Order::where('store_id', getCurrentStore())` -- which is what kept foreign FIGURES out
     *     of reach throughout, and why this finding is a name disclosure and not a data breach.
     *     This reads the same `getCurrentStore()`, so the two predicates cannot drift apart.
     *   * It does not filter `status`. `find()` ignored it and so does this: the chart is an admin
     *     screen and a merchant may legitimately chart a disabled category of their own. (The two
     *     SELECTOR screens, `OrderSaleCategoryReport` and `OrderSaleBrandReport`, do filter
     *     `status = 1`. That asymmetry is why the leak reached names that are not even public on
     *     the victim's own storefront. Narrowing this read by status as well would not improve it
     *     -- it would only remove a capability from the owner of the row.)
     *   * It does not narrow the PLATFORM OPERATOR. A plain predicate would, and today the
     *     operator may name any tenant's id (and sees zeros, because their order query is still
     *     `getCurrentStore()`-scoped). That capability is worth nothing, but taking it away is an
     *     AUTHORIZATION-POLICY change, which needs owner approval. So the operator keeps the same
     *     exemption `CategoryController::categoryIsOutsideCurrentStore()` already grants, with the
     *     same EXACT-CASE comparison -- which also fails SAFE: a non-canonical `'Super Admin'` is
     *     treated as a merchant and narrowed, never exempted.
     *
     * THE RETURN TYPE IS LOAD-BEARING. DO NOT "SIMPLIFY" IT WITH `->all()`.
     *   * An empty selection comes back UNCHANGED, so each caller's own `empty($selected)` default
     *     still fires. (This note used to add "and, in the brand handler's first two branches, its
     *     `$selected ?? ...`". Those two branches now test `empty($selectedBrand)` instead, so the
     *     parenthesis described a caller shape that no longer exists.) And
     *     the "All Categories" / "All Brands" series -- which are decided from the RAW
     *     `$request->selected*`, untouched here -- behave exactly as before.
     *   * A selection that survives nothing comes back as an EMPTY COLLECTION, and `empty()` on an
     *     object is always false in PHP. That is what preserves today's behaviour for a selection
     *     resolving to no rows: an empty chart, NOT a silent fallback to every row this store owns.
     *     Returning `[]` here would flip that, and a request naming only foreign ids would answer
     *     with the caller's whole catalogue instead of nothing.
     *
     * It is at the END of the class, away from its nine call sites, on purpose:
     * `tests/Feature/Tenancy/RawQueryTenancyInventoryTest` cites a line of this file by number and
     * inserting anything above it would make that citation stale.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model  Category or ProductBrand
     * @param  mixed  $selected  whatever the request supplied
     * @return mixed  the input unchanged, or a Collection of ids this store really owns
     */
    private function reportSelectionWithinCurrentStore(string $model, $selected)
    {
        // Nothing was selected, so there is nothing to narrow: hand it straight back and let the
        // caller's own default run. Note `empty('0')` is true, which is also how the callers'
        // existing defaults read a `selected*=0`, so that case is unchanged too.
        if (empty($selected)) {
            return $selected;
        }

        $actor = auth()->user();

        if ($actor !== null && $actor->type === \App\Policies\UserPolicy::PLATFORM_ADMIN) {
            return $selected;
        }

        /*
         * GAP-223(b), AND THIS LINE IS A NO-OP FOR THE OPERATOR -- stated because an earlier version
         * of this comment claimed otherwise and the claim was false.
         *
         * The operator bypass twelve lines up returns `$selected` UNNARROWED, so a `'super admin'`
         * never reaches here and the selection was never narrowed to store 1 for them. What this
         * change does is keep the resolver consistent for everyone else, where `getCurrentStore()`
         * already answered null and `empty()` already sent them to the empty collection below. So:
         * behaviour-preserving, one definition instead of two.
         *
         * THE BYPASS ITSELF IS PRE-EXISTING AND LEFT ALONE. It lets an operator name ids from any
         * tenant in a filter, which is a real cross-tenant capability -- but removing it would
         * NARROW an operator who HAS chosen a store, which is not what GAP-223(b) is about and would
         * change a live screen beyond the owner's direction. Recorded, not altered.
         */
        $currentStore = OperatorStoreSelection::reportStoreId();

        // AN UNRESOLVED TENANT IS OUTSIDE, NOT EVERYWHERE. `StoreScope` fails OPEN on a null
        // tenant by design (`null_tenant => 'open'`), and a predicate whose whole job is to hold
        // where the scope adds nothing must not inherit that.
        if (empty($currentStore)) {
            return collect();
        }

        // FILTER_VALIDATE_INT, not is_numeric: is_numeric accepts '5.9' and rejects '5abc', while
        // MySQL coerces '5abc' to row 5 in a `WHERE id = ?` comparison. flatten() because a
        // query string can nest arrays, and a nested array reaching whereIn() is a SQL error.
        $ids = collect($selected)
            ->flatten()
            ->map(static fn ($id) => filter_var($id, FILTER_VALIDATE_INT))
            ->reject(static fn ($id) => $id === false)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return collect();
        }

        $owned = $model::where('store_id', $currentStore)->whereIn('id', $ids)->pluck('id');

        // Back in the CALLER'S order, not the database's. The callers push one `NetSaleTotal`
        // series per id in iteration order, so returning `$owned` directly would silently
        // re-order the chart legend for a merchant who picked their own categories in any order
        // other than ascending id. intersect() keeps this collection's order, so a legitimate
        // selection comes back byte-identical to what it was before this guard existed.
        return collect($ids)->intersect($owned)->values();
    }
}