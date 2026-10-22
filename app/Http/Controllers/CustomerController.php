<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use App\Models\ActivityLog;
use App\Models\FlashSale;
use App\Models\Store;
use App\Models\Order;
use App\Models\OrderBillingDetail;
use App\DataTables\CustomerDataTable;

class CustomerController extends Controller
{
    /**
     * Display a listing of the resource.
     */
/**
 * CLAUDE B FINDING / P01B-SEC-2. THE ADD-CUSTOMER ACTION HAD NO PERMISSION CHECK AT ALL.
 *
 * Claude B reported the check as "removed for testing and still absent", and that is what the
 * code showed: store() went straight from the method signature into $request->validate(), while
 * its siblings in this same controller all gate -- index() and destroy() on 'Manage Customer',
 * show() on 'Show Customer'. Any authenticated user could create a customer.
 *
 * A HIDDEN BUTTON IS NOT A FIX, and the brief says so explicitly, so the gate is here on the
 * server action. The Blade visibility is a separate, secondary concern and is not what this
 * closes.
 *
 * GATED ON 'Manage Customer', NOT ON 'Create Customer'. That name does not exist: the only
 * customer rows Utility::addNewData() creates are 'Manage Customer', 'Show Customer' and
 * 'Status Customer' -- verified by counting both quote styles, and recorded already in
 * destroy()'s docblock below. Gating on an invented name would 403 every legitimate user on a
 * database built from source, which is the exact failure that locked out the finance surface.
 *
 * THE STORE FALLBACK IS ALSO CLOSED. The row was created with
 * `'store_id' => auth()->user()->current_store ?? 1`, so a user with no current_store silently
 * created the customer in STORE 1 -- another tenant's store. It now resolves through
 * getCurrentStore(), which returns 1 only for the platform operator (app/Helper/helper.php) and
 * null for a merchant with no store, and a null store is refused rather than defaulted.
 */
public function store(Request $request)
{
    $user = auth()->user();

    // Operator first: isAbleTo() has no super-admin bypass in this codebase, so testing the
    // permission alone would refuse the platform operator. Same order as destroy() below.
    $isOperator = $user && strtolower(trim((string) $user->type)) === 'super admin';

    if (!$user || (!$isOperator && !$user->isAbleTo('Manage Customer'))) {
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    $store = getCurrentStore();

    if (empty($store)) {
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    try {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:customers,email',
            'mobile' => 'nullable|string|max:20',
            'password' => 'required|string|min:8',
            'status' => 'required|in:0,1',
        ]);

        $customer = Customer::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'mobile' => $validated['mobile'] ?? null,
            'password' => \Hash::make($validated['password']),
            'type' => 'customer',
            'status' => $validated['status'],
            'store_id' => $store,
            'regiester_date' => now()->toDateString(),
            'last_active' => now()->toDateString(),
            'created_by' => auth()->id(),
            'email_verified_at' => now(),
        ]);

        return redirect()->route('customer.index')
            ->with('success', __('Customer created successfully.'));

    } catch (\Illuminate\Validation\ValidationException $e) {
        $errorMessages = $e->validator->errors()->all();
        \Log::error('Validation Error:', $errorMessages);
        
        return redirect()->back()
            ->withErrors($e->validator)
            ->withInput($request->except(['password', 'password_confirmation']))
            ->with('error', 'Validation failed: ' . implode(', ', $errorMessages));
            
    } catch (\Exception $e) {
        \Log::error('Customer Creation Error:', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        return redirect()->back()
            ->with('error', 'Error: ' . $e->getMessage())
            ->withInput($request->except(['password', 'password_confirmation']));
    }
}

public function create()
    {
        $user = auth()->user();
        $isOperator = $user && strtolower(trim((string) $user->type)) === 'super admin';

        if (!$user || (!$isOperator && !$user->isAbleTo('Manage Customer'))) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return view('customer.create');
    }

/**
 * R4-04 cross-tenant IDOR exemplar.
 *
 * This had NO checks at all -- no permission, no tenant scope -- while its two
 * siblings in this same controller both check: index() gates on
 * 'Manage Customer' and show() on 'Show Customer'. The parameter is route-model
 * bound, so Laravel resolved ANY customer id in the table and deleted it. Any
 * authenticated user could delete any other store's customer by id.
 *
 * Gated on 'Manage Customer', the permission index() already uses, and NOT on a
 * 'Delete Customer' permission. That name does not exist: the only
 * customer-related rows Utility::addNewData() creates are 'Manage Customer',
 * 'Show Customer' and 'Status Customer' (verified by counting both quote styles
 * in Utility.php -- there is no 'Delete Customer', 'Create Customer' or
 * 'Edit Customer'). Gating on an invented name would make this 403 for
 * everybody on a database built from source, which is exactly the defect that
 * locked out the finance surface.
 */
public function destroy(Customer $customer)
{
    $user = auth()->user();

    if (!$user) {
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    // Operator path first. This ORDER is a fix: isAbleTo() has no super-admin
    // bypass, so the previous `!$user->isAbleTo('Manage Customer')` ahead of
    // the exemption below denied the platform operator outright. Same shape as
    // AuthServiceProvider's `finance` gate (:80-83).
    if ($user->type !== \App\Policies\UserPolicy::PLATFORM_ADMIN
        && !$user->isAbleTo('Manage Customer')) {
        return redirect()->back()->with('error', __('Permission denied.'));
    }

    // Ownership. A super admin is exempt, matching OrderPolicy::allow and the
    // finance gate, so the operator is not locked out.
    if ($user->type !== \App\Policies\UserPolicy::PLATFORM_ADMIN) {
        $currentStore = getCurrentStore();

        if (
            empty($currentStore)
            || empty($customer->store_id)
            || (int) $customer->store_id !== (int) $currentStore
        ) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    $customer->delete();

    return redirect()->route('customer.index')
                     ->with('success', __('Customer archived successfully.'));}

    public function index(CustomerDataTable $dataTable, Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Customer')) {
            $customer_field = Customer::customer_field();
            return $dataTable->with('request', $request)->render('customer.index', compact('customer_field'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     */
    /**
     * NEW-customer-show-cross-tenant.
     *
     * The 'Show Customer' permission was checked; OWNERSHIP was not. Every
     * query below keys on the customer id alone, so a merchant holding a
     * permission every merchant holds could read another store's customer and
     * that customer's orders by changing the id in the URL.
     *
     * The ownership limb is added ahead of the queries, matching destroy().
     */
    public function show($id)
    {
        $actor = auth()->user();
        // Operator path first -- see customerStatus() above for why.
        $mayShow = $actor
            && ($actor->type === \App\Policies\UserPolicy::PLATFORM_ADMIN
                || $actor->isAbleTo('Show Customer'));

        if ($mayShow) {
            $user = $actor;
            $customer = Customer::find($id);

            if ($user->type !== \App\Policies\UserPolicy::PLATFORM_ADMIN) {
                $currentStore = getCurrentStore();

                if (
                    !$customer
                    || empty($currentStore)
                    || empty($customer->store_id)
                    || (int) $customer->store_id !== (int) $currentStore
                ) {
                    return redirect()->back()->with('error', __('Customer not found.'));
                }
            }

            $users_id = Order::where('customer_id', $id)->get();
            if ($users_id->count() == 0) {
                $customers = Customer::find($id);
                $orders_detail = OrderBillingDetail::where('email', $customers->email)->get()->toArray();
                $order_id = [];
                foreach ($orders_detail as $orders) {
                    $order_id[] = $orders['order_id'];
                }
                $orders = Order::whereIn('id', $order_id)->where('customer_id', 0)->get();
            } else {
                $orders = Order::where('customer_id', $id)->get();
            }
            return view('customer.view', compact('orders'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * NEW-customer-status-unauthorized.
     *
     * This had NO permission check, NO ownership check and NO null guard:
     *   $customer = Customer::find($request->customer_id);
     *   $customer->status = $request->status;
     *   $customer->save();
     * The route carries ['web','auth','xss','setlocate','verified'] (measured
     * from the real router, routes/web.php:950), so authentication was the only
     * control. Any logged-in web user -- including a staff member holding no
     * permissions at all -- could enable or DISABLE any customer account on the
     * platform, in any store, by sending one id. Disabling a customer locks a
     * shopper out of the store they bought from. The bare find() was also a
     * null dereference on an unmatched id.
     *
     * The predicate is copied from destroy() in this same controller rather
     * than invented, so there is one shape for customer ownership here:
     * 'Manage Customer' (a real seeded row -- app/Models/Utility.php:2821, NOT
     * an invented name), a platform-operator exemption so the operator is not
     * locked out, and customers.store_id compared against getCurrentStore().
     */
    public function customerStatus(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        // OPERATOR PATH FIRST, then the permission. The order is the fix, not a
        // style choice.
        //
        // Laratrust's isAbleTo() resolves purely through the role/permission
        // pivot tables and has NO super-admin bypass. makePlatformAdmin-shaped
        // operators hold no role, so checking the permission BEFORE the
        // operator exemption denies the platform operator outright -- which is
        // exactly the lockout the finance permissions suffered when all six
        // names resolved to "denied" for everybody. The seed-independent
        // operator path is the shape AuthServiceProvider's `finance` gate
        // (:80-83) and OrderPolicy::allow() already use.
        //
        // This also corrects destroy() below, which had the checks in the
        // other order and locked the operator out of customer deletion. Caught
        // by test_the_platform_operator_is_not_locked_out, which failed with
        // "The platform operator was locked out of customer administration."
        $isOperator = $user->type === \App\Policies\UserPolicy::PLATFORM_ADMIN;

        if (!$isOperator && !$user->isAbleTo('Manage Customer')) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $customer = Customer::find($request->customer_id);

        if (!$customer) {
            return response()->json(['error' => __('Customer not found.')], 404);
        }

        if (!$isOperator) {
            $currentStore = getCurrentStore();

            if (
                empty($currentStore)
                || empty($customer->store_id)
                || (int) $customer->store_id !== (int) $currentStore
            ) {
                // 404, not 403: a customer outside the caller's store is
                // indistinguishable from one that does not exist, so the
                // response does not confirm that an id is in use.
                return response()->json(['error' => __('Customer not found.')], 404);
            }
        }

        $customer->status = $request->status;
        $customer->save();

        return response()->json(['success' => __('Status change successfully.')]);
    }

    /**
     * NEW-customer-timeline-unauthorized.
     *
     * No permission check and no ownership check. Any logged-in web user could
     * read ANY customer's activity log, total spend and profile row in any
     * store by walking the id in the URL -- a cross-tenant disclosure of
     * shopper behaviour and lifetime value.
     *
     * 'Show Customer' is the permission this controller's own show() already
     * uses for the same data class, and it is a real seeded row
     * (app/Models/Utility.php:2822).
     */
    public function customerTimeline($ids)
    {
        $user = auth()->user();

        if (!$user) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        // Operator path first -- see customerStatus() above for why.
        $isOperator = $user->type === \App\Policies\UserPolicy::PLATFORM_ADMIN;

        if (!$isOperator && !$user->isAbleTo('Show Customer')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $customer = Customer::find($ids);

        if (!$customer) {
            return redirect()->back()->with('error', __('Customer not found.'));
        }

        if (!$isOperator) {
            $currentStore = getCurrentStore();

            if (
                empty($currentStore)
                || empty($customer->store_id)
                || (int) $customer->store_id !== (int) $currentStore
            ) {
                return redirect()->back()->with('error', __('Customer not found.'));
            }
        }

        $activityLog = ActivityLog::where('customer_id', $ids)
            ->groupBy('customer_id')
            ->first();

        $logs = ActivityLog::where('customer_id', $ids)->get();

        $totalSpend = ActivityLog::totalspend($ids);

        $customer_data = Customer::find($ids);


        return view('customer.timeline', compact('logs', 'activityLog', 'totalSpend', 'customer_data'));
    }


    public function CustomFilter(Request $request)
    {
        $id = $request->customer_field;
        $data = [];
        if ($id == 'Name' || $id == 'Email') {
            $data['condition'] = Customer::$fields_status;
            $data['message']  = __("Customer name and email search filed");
        } else if ($id == 'Last active') {
            $data['condition'] = Customer::$fields_status1;
            $data['message']  = __("Customer last active search filed");
        } else if ($id == 'Total Spend' || $id == 'No. of Orders' || $id == "AOV") {
            $data['condition'] = Customer::$fields_status2;
            $data['message']  = __("Customer last active search filed");
        } else {
            $data['condition'] = [];
            $data['message']  = __("Customer condition search filed");
        }

        if ($id == 'Name') {
            $data['field_type'] = 'text';
        } else if ($id == 'Last active') {
            $data['field_type'] = 'date';
        } else if ($id == 'Email') {
            $data['field_type'] = 'email';
        } else {
            $data['field_type'] = 'number';
        }
        return response()->json($data);
    }

    public function CustomFilterData(Request $request)
    {
        $activitylog = ActivityLog::groupBy('customer_id')->where('store_id', getCurrentStore())->get();

        $requestData = $request->all();

        $query = Customer::where('store_id', getCurrentStore());
        if ($requestData['customer_field'] == 'Name' || $requestData['customer_field'] == 'Email') {
            if (!empty($requestData['selected_name']) && !empty($requestData['text_field'])) {
                // check for name and email filtering
                if ($requestData['selected_name'] === 'Includes') {
                    $query->where(function ($query) use ($requestData) {
                        $query->where('first_name', 'like', '%' . $requestData['text_field'] . '%')
                            ->orWhere('email', 'like', '%' . $requestData['text_field'] . '%');
                    });
                } elseif ($requestData['selected_name'] === 'Excludes') {
                    $query->where(function ($query) use ($requestData) {
                        $query->where(function ($query) use ($requestData) {
                            $query->where('first_name', 'not like', '%' . $requestData['text_field'] . '%')
                                ->orWhere('email', 'not like', '%' . $requestData['text_field'] . '%');
                        });
                    });
                }
            }
        }

        // Check for last active filtering
        if ($requestData['customer_field'] == 'Last Active') {
            if (!empty($requestData['selected_name']) && !empty($requestData['text_field'])) {
                $dateValue = $requestData['text_field'];
                if ($requestData['selected_name'] === 'Before') {
                    $query->whereDate('last_active', '<', $dateValue);
                } elseif ($requestData['selected_name'] === 'After') {
                    $query->whereDate('last_active', '>', $dateValue);
                } else {
                    $query->whereDate('last_active', $dateValue);
                }
            }
        }

        // check for AOV filtering
        if ($requestData['customer_field'] == 'AOV') {
            $queryyy = Customer::where('store_id', getCurrentStore());
            if (!empty($requestData['selected_name']) && !empty($requestData['text_field'])) {
                $filteredCustomers = [];
                foreach ($queryyy->get() as $key => $value) {
                    $AOV = 0;
                    if ($value->total_spend() != 0 && $value->Ordercount() != 0) {
                        $AOV = number_format($value->total_spend() / $value->Ordercount(), 2);

                        // Check the condition and filter
                        $text_field_float = (float) $requestData['text_field'];
                        $AOV = (float) str_replace(',', '', $AOV);
                        if ($requestData['selected_name'] === 'Less Than' && $AOV < $text_field_float) {
                            $filteredCustomers[] = $value->id;
                        } elseif ($requestData['selected_name'] === 'More Than' && $AOV > $text_field_float) {
                            $filteredCustomers[] = $value->id;
                        } elseif ($requestData['selected_name'] === 'Equal' && $AOV = $text_field_float) {
                            $filteredCustomers[] = $value->id;
                        }
                    }
                }
                $query = $queryyy->whereIn('id', $filteredCustomers);
            }
        }

        // check for number of orders filtering
        if ($requestData['customer_field'] == 'No. of Orders') {
            $queryss = Customer::where('store_id', getCurrentStore());
            if (!empty($requestData['selected_name']) && !empty($requestData['text_field'])) {
                $filteredCustomers = [];

                $orderCountValue = (int) $requestData['text_field'];
                foreach ($queryss->get() as $key => $value) {
                    $counter = $value->Ordercount();

                    if ($requestData['selected_name'] === 'Less Than' && $counter < $orderCountValue) {
                        $filteredCustomers[] = $value->id;
                    } else if ($requestData['selected_name'] === 'Less Than' && $counter > $orderCountValue) {
                        $filteredCustomers[] = $value->id;
                    } else if ($requestData['selected_name'] === 'Equal' && $counter == $orderCountValue) {
                        $filteredCustomers[] = $value->id;
                    }
                }
                $query = $queryss->whereIn('id', $filteredCustomers);
            }
        }

        // check for Total Spend filtering
        if ($requestData['customer_field'] == 'Total Spend') {
            $queryes = Customer::where('store_id', getCurrentStore());
            if (!empty($requestData['selected_name']) && !empty($requestData['text_field'])) {
                $filteredCustomers = [];

                $orderCountValue = (int) $requestData['text_field'];
                foreach ($queryes->get() as $key => $value) {
                    $counter = $value->total_spend();

                    if ($requestData['selected_name'] === 'Less Than' && $counter < $orderCountValue) {
                        $filteredCustomers[] = $value->id;
                    } else if ($requestData['selected_name'] === 'Less Than' && $counter > $orderCountValue) {
                        $filteredCustomers[] = $value->id;
                    } else if ($requestData['selected_name'] === 'Equal' && $counter == $orderCountValue) {
                        $filteredCustomers[] = $value->id;
                    }
                }
                $query = $queryes->whereIn('id', $filteredCustomers);
            }
        }
        $customers = $query->get();

        $filter = view('customer.filter', compact('customers', 'activitylog'))->render();

        return response()->json($filter);
    }

    public function customer_grid_view(Request $request)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Manage Customer')) {
            $customers = Customer::where('store_id', getCurrentStore())->paginate(12);
            $customer_field = Customer::customer_field();
            return view('customer.grid', compact('customer_field','customers'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }
}