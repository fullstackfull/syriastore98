<?php

namespace App\Http\Controllers;

use App\Models\FlashSale;
use App\Models\FlashSaleCondition;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Store;
use Illuminate\Http\Request;
use App\DataTables\FlashSaleDataTable;
use App\Policies\UserPolicy;

/**
 * F266 — the coupon sibling's defect, on the resource the earlier pass did not reach.
 *
 * `FlashSale` is ABSENT from `config/tenancy.php`'s `scoped_models` — only `FlashSaleCondition`
 * is allowlisted (`:168`) — so `StoreScope` adds no predicate to the sale itself, and every
 * handler below resolved one by bare id or by route-model binding, which is the same thing.
 *
 *   create()   had NO CHECK OF ANY KIND. `index()` requires `Manage Flash Sale` and `store()`
 *              requires `Create Flash Sale`; the form between them was open to any authenticated
 *              member of the tenant.
 *   edit($id)  `FlashSale::find($id)` with no predicate — another tenant's sale, its discount and
 *              its product list, rendered on the caller's form. It also 500d on an unknown id.
 *   update()   route-model-bound on the same unscoped model, so another tenant's discount is
 *              writable by anyone holding `Edit Flash Sale` in their OWN store.
 *   destroy()  the same, AND `FlashSaleCondition::where('flashsale_id', …)->delete()` carried no
 *              store predicate, so it deleted the victim's conditions with the sale.
 *
 * `CouponController` records the identical gap as F157 and already carries the fix.
 *
 * THE EXISTING `isAbleTo()` BLOCKS ARE LEFT IN PLACE. The refusals below run first and check the
 * same permission, so the original condition is now redundant rather than wrong — and leaving it
 * keeps this diff to inserted lines. Re-indenting three several-hundred-line method bodies to
 * remove a redundant `if` is exactly the kind of churn that hides a real change inside it.
 *
 * ON THE OPERATOR: reads admit, writes refuse, following the coupon decision (F157, owner
 * decision 2026-10-28 — the platform operator reads and exports, and does not rewrite a
 * merchant's commercial settings). A flash sale is a discount on a merchant's own catalogue, the
 * same kind of setting. No owner decision names flash sales, so this is an analogy and recorded
 * as one: a single early-return to reverse.
 */
class FlashSaleController extends Controller
{
    /**
     * Who may OPEN a flash-sale form. Null means permitted. Admits the operator.
     *
     * @return \Illuminate\Http\RedirectResponse|null
     */
    private function flashSaleReadRefusal(string $permission)
    {
        $user = auth()->user();

        if (! $user) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $isOperator = UserPolicy::isOperator($user);

        if (! $isOperator && ! $user->isAbleTo($permission)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        // `getCurrentStore()` hands an operator with no `current_store` the hardcoded literal 1,
        // and `getStoreById(1)` normally resolves, so without this they would be served store 1's
        // sales by default. The coupon read gate carries the same guard.
        if ($isOperator && empty($user->current_store)) {
            return redirect()->back()->with('error', __('No store is selected.'));
        }

        return $this->storeMustResolve();
    }

    /**
     * Who may WRITE a flash sale. Null means permitted. Refuses the operator explicitly.
     *
     * @return \Illuminate\Http\RedirectResponse|null
     */
    private function flashSaleWriteRefusal(string $permission)
    {
        $user = auth()->user();

        if (! $user) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (UserPolicy::isOperator($user)) {
            // Stated, not an accident. A bare `isAbleTo()` refuses the operator too, but only
            // because Laratrust has no super-admin bypass -- so the next person adding an
            // operator limb "for consistency" would hand them every merchant's discounts.
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (! $user->isAbleTo($permission)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return $this->storeMustResolve();
    }

    /** @return \Illuminate\Http\RedirectResponse|null */
    private function storeMustResolve()
    {
        if (getStoreById(getCurrentStore()) === null) {
            return redirect()->back()->with('error', __('No store is selected.'));
        }

        return null;
    }

    /**
     * This store's flash sale, or null.
     *
     * The ONLY control: `FlashSale` registers no global scope. `empty($storeId)` returns before a
     * query is built, because `where('store_id', null)` compiles to `IS NULL` and would match an
     * orphaned row rather than nothing.
     */
    private function scopedFlashSale($id): ?FlashSale
    {
        $storeId = getCurrentStore();

        if (empty($storeId)) {
            return null;
        }

        return FlashSale::where('store_id', $storeId)->find($id);
    }
    /**
     * Display a listing of the resource.
     */
    public function index(FlashSaleDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Flash Sale')) {
            return $dataTable->render('flash-sale.index');
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // NO CHECK AT ALL before this -- `index()` and `store()` both gate, the form between them
        // did not.
        if ($refusal = $this->flashSaleReadRefusal('Create Flash Sale')) {
            return $refusal;
        }

        return view('flash-sale.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create Flash Sale')) {

            if ($request->discount_type == 'percentage') {
                $validator = \Validator::make($request->all(), [
                    'discount_amount' => 'required|numeric|min:1|max:100',
                ]);
                if ($validator->fails()) {
                    $messages = $validator->getMessageBag();

                    return redirect()->back()->with('error', $messages->first());
                }
            }
            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => 'required',
                ]
            );

            if ($validator->fails()) {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }

            $flashsale = new FlashSale();
            $flashsale->name = $request->name;
            $flashsale->start_date = $request->start_date;
            $flashsale->end_date = $request->end_date;
            $flashsale->start_time = $request->start_time;
            $flashsale->end_time = $request->end_time;
            if ($request->has('discount_type')) {
                $flashsale->discount_amount = $request->discount_amount;
                $flashsale->discount_type = $request->discount_type;
            }
            $flashsale->created_by = \Auth::user()->id;
            $flashsale->store_id = getCurrentStore();

            $flashsale->save();

            if ($request->fields) {
                $flashsale_condition = new FlashSaleCondition();
                $flashsale_condition->flashsale_id = $flashsale->id;
                $flashsale_condition->condition = json_encode($request->fields);
                $flashsale_condition->store_id = getCurrentStore();
                $flashsale_condition->save();
            }

            $sale_product = Product::where('store_id', getCurrentStore())->get();

            // Initialize an array to store the IDs of matching products
            $matchingProductIds = [];
            foreach ($sale_product as $product) {
                $flashsale_status = true;

                foreach ($request->fields as $item) {
                    if ($item['condition_option'] == 0) {
                        // Check if store_id matches
                        if ($product->store_id != getCurrentStore()) {
                            $flashsale_status = false;
                            break; // No need to check further conditions
                        }
                    } elseif ($item['condition_option'] == 1) {
                        // Check if product ID is in the condition list
                        $value = implode(',', $item['conditionlist']);
                        $idsArray = explode(',', $value);
                        if ($item['condition'] == 0) {
                            // Condition: Product ID should be in the list
                            if (! in_array($product->id, $idsArray)) {
                                $flashsale_status = false;
                                break; // No need to check further conditions
                            }
                        } else {
                            // Condition: Product ID should NOT be in the list
                            if (in_array($product->id, $idsArray)) {
                                $flashsale_status = false;
                                break; // No need to check further conditions
                            }
                        }
                    } elseif ($item['condition_option'] == 2) {
                        // Check if category_id is in the condition list
                        $value = implode(',', $item['conditionlist']);
                        $idsArray = explode(',', $value);
                        if ($item['condition'] == 0) {
                            // Condition: Category ID should be in the list
                            if (! in_array($product->category_id, $idsArray)) {
                                $flashsale_status = false;
                                break; // No need to check further conditions
                            }
                        } else {
                            // Condition: Category ID should NOT be in the list
                            if (in_array($product->category_id, $idsArray)) {
                                $flashsale_status = false;
                                break; // No need to check further conditions
                            }
                        }
                    } elseif ($item['condition_option'] == 3) {
                        // Check price conditions
                        $priceMatched = false;
                        if ($product->variant_product == 0) {
                            $salePrice = $product->price;
                            switch ($item['condition']) {
                                case 0: // Equals
                                    if ($salePrice == $item['conditionlist'][0]) {
                                        $priceMatched = true;
                                    }
                                    break;
                                case 1: // Not equals
                                    if ($salePrice != $item['conditionlist'][0]) {
                                        $priceMatched = true;
                                    }
                                    break;
                                case 2: // Greater than
                                    if ($salePrice > $item['conditionlist'][0]) {
                                        $priceMatched = true;
                                    }
                                    break;
                                case 3: // Less than
                                    if ($salePrice < $item['conditionlist'][0]) {
                                        $priceMatched = true;
                                    }
                                    break;
                                case 4: // Greater than or equal
                                    if ($salePrice >= $item['conditionlist'][0]) {
                                        $priceMatched = true;
                                    }
                                    break;
                                case 5: // Less than or equal
                                    if ($salePrice <= $item['conditionlist'][0]) {
                                        $priceMatched = true;
                                    }
                                    break;
                            }
                        } else {
                            $product_variant_data = ProductVariant::where('product_id', $product->id)->get();
                            foreach ($product_variant_data as $variant) {
                                $salePrice = $variant->price;

                                switch ($item['condition']) {
                                    case 0: // Equals
                                        if ($salePrice == $item['conditionlist'][0]) {
                                            $priceMatched = true;
                                        }
                                        break;
                                    case 1: // Not equals
                                        if ($salePrice != $item['conditionlist'][0]) {
                                            $priceMatched = true;
                                        }
                                        break;
                                    case 2: // Greater than
                                        if ($salePrice > $item['conditionlist'][0]) {
                                            $priceMatched = true;
                                        }
                                        break;
                                    case 3: // Less than
                                        if ($salePrice < $item['conditionlist'][0]) {
                                            $priceMatched = true;
                                        }
                                        break;
                                    case 4: // Greater than or equal
                                        if ($salePrice >= $item['conditionlist'][0]) {
                                            $priceMatched = true;
                                        }
                                        break;
                                    case 5: // Less than or equal
                                        if ($salePrice <= $item['conditionlist'][0]) {
                                            $priceMatched = true;
                                        }
                                        break;
                                }
                                if ($priceMatched) {
                                    break;
                                }
                            }
                        }

                        if (! $priceMatched) {
                            $flashsale_status = false;
                            break; // No need to check further conditions
                        }
                    }
                }

                // If all conditions match, add the product ID to the matchingProductIds array
                if ($flashsale_status) {
                    $matchingProductIds[] = $product->id;
                }
            }
            $flashsale->sale_product = json_encode($matchingProductIds);

            // Save the FlashSale model and other updates
            $flashsale->save();

            return redirect()->back()->with('success', __('Flashsale successfully created.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(FlashSale $flashSale)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        if ($refusal = $this->flashSaleReadRefusal('Edit Flash Sale')) {
            return $refusal;
        }

        $flashsale = $this->scopedFlashSale($id);

        if ($flashsale === null) {
            // Also the 500 fix: `$flashsale->id` on null was fatal for an unknown id.
            return redirect()->back()->with('error', __('Flash sale not found.'));
        }

        $sale_condition = FlashSaleCondition::where('flashsale_id', $flashsale->id)->where('store_id', getCurrentStore())->first();

        return view('flash-sale.edit', compact('flashsale', 'sale_condition'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, FlashSale $flashSale)
    {
        if ($refusal = $this->flashSaleWriteRefusal('Edit Flash Sale')) {
            return $refusal;
        }

        /* ROUTE-MODEL BINDING IS NOT A TENANT CHECK. `FlashSale` carries no global scope, so the
           bound instance is whatever row the id named. */
        if ($this->scopedFlashSale($flashSale->id) === null) {
            return redirect()->back()->with('error', __('Flash sale not found.'));
        }

        if (auth()->user() && auth()->user()->isAbleTo('Edit Flash Sale')) {
            if ($request->discount_type == 'percentage') {
                $validator = \Validator::make($request->all(), [
                    'discount_amount' => 'required|numeric|min:1|max:100',
                ]);
                if ($validator->fails()) {
                    $messages = $validator->getMessageBag();

                    return redirect()->back()->with('error', $messages->first());
                }
            }
            $validator = \Validator::make(
                $request->all(),
                [
                    'name' => 'required',
                ]
            );

            if ($validator->fails()) {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }
            $flashsale = FlashSale::find($flashSale->id);
            $flashsale->name = $request->name;
            $flashsale->start_date = $request->start_date;
            $flashsale->end_date = $request->end_date;
            $flashsale->start_time = $request->start_time;
            $flashsale->end_time = $request->end_time;
            if ($request->has('discount_type')) {
                $flashsale->discount_amount = $request->discount_amount;
                $flashsale->discount_type = $request->discount_type;
            }
            $flashsale->created_by = \Auth::user()->id;
            $flashsale->store_id = getCurrentStore();

            $flashsale->save();

            if ($request->fields) {
                $flashsale_condition = FlashSaleCondition::where('flashsale_id', $flashSale->id)->first();
                if ($flashsale_condition == null) {
                    $flashsale_condition = new FlashSaleCondition;
                    $flashsale_condition->flashsale_id = $flashSale->id;
                }
                $flashsale_condition->condition = json_encode($request->fields);
                $flashsale_condition->store_id = getCurrentStore();
                $flashsale_condition->save();
            }

            $sale_product = Product::where('store_id', getCurrentStore())->get();

            // Initialize an array to store the IDs of matching products
            $matchingProductIds = [];
            foreach ($sale_product as $product) {
                $flashsale_status = true;
                if (isset($request->fields)) {
                    foreach ($request->fields as $item) {
                        if ($item['condition_option'] == 0) {
                            if ($product->store_id != getCurrentStore()) {
                                $flashsale_status = false;
                                break;
                            }
                        } elseif ($item['condition_option'] == 1) {

                            $value = implode(',', $item['conditionlist']);
                            $idsArray = explode(',', $value);
                            if ($item['condition'] == 0) {

                                if (! in_array($product->id, $idsArray)) {
                                    $flashsale_status = false;
                                    break;
                                }
                            } else {

                                if (in_array($product->id, $idsArray)) {
                                    $flashsale_status = false;
                                    break;
                                }
                            }
                        } elseif ($item['condition_option'] == 2) {

                            $value = implode(',', $item['conditionlist']);
                            $idsArray = explode(',', $value);
                            if ($item['condition'] == 0) {

                                if (! in_array($product->category_id, $idsArray)) {
                                    $flashsale_status = false;
                                    break;
                                }
                            } else {

                                if (in_array($product->category_id, $idsArray)) {
                                    $flashsale_status = false;
                                    break;
                                }
                            }
                        } elseif ($item['condition_option'] == 3) {

                            $priceMatched = false;
                            if ($product->variant_product == 0) {
                                $salePrice = $product->price;
                                switch ($item['condition']) {
                                    case 0:
                                        if ($salePrice == $item['conditionlist'][0]) {
                                            $priceMatched = true;
                                        }
                                        break;
                                    case 1:
                                        if ($salePrice != $item['conditionlist'][0]) {
                                            $priceMatched = true;
                                        }
                                        break;
                                    case 2:
                                        if ($salePrice > $item['conditionlist'][0]) {
                                            $priceMatched = true;
                                        }
                                        break;
                                    case 3:
                                        if ($salePrice < $item['conditionlist'][0]) {
                                            $priceMatched = true;
                                        }
                                        break;
                                    case 4:
                                        if ($salePrice >= $item['conditionlist'][0]) {
                                            $priceMatched = true;
                                        }
                                        break;
                                    case 5:
                                        if ($salePrice <= $item['conditionlist'][0]) {
                                            $priceMatched = true;
                                        }
                                        break;
                                }
                            } else {
                                $product_variant_data = ProductVariant::where('product_id', $product->id)->get();
                                foreach ($product_variant_data as $variant) {
                                    $salePrice = $variant->price;

                                    switch ($item['condition']) {
                                        case 0: // Equals
                                            if ($salePrice == $item['conditionlist'][0]) {
                                                $priceMatched = true;
                                            }
                                            break;
                                        case 1: // Not equals
                                            if ($salePrice != $item['conditionlist'][0]) {
                                                $priceMatched = true;
                                            }
                                            break;
                                        case 2: // Greater than
                                            if ($salePrice > $item['conditionlist'][0]) {
                                                $priceMatched = true;
                                            }
                                            break;
                                        case 3: // Less than
                                            if ($salePrice < $item['conditionlist'][0]) {
                                                $priceMatched = true;
                                            }
                                            break;
                                        case 4: // Greater than or equal
                                            if ($salePrice >= $item['conditionlist'][0]) {
                                                $priceMatched = true;
                                            }
                                            break;
                                        case 5: // Less than or equal
                                            if ($salePrice <= $item['conditionlist'][0]) {
                                                $priceMatched = true;
                                            }
                                            break;
                                    }
                                    if ($priceMatched) {
                                        break;
                                    }
                                }
                            }

                            if (! $priceMatched) {
                                $flashsale_status = false;
                                break; // No need to check further conditions
                            }
                        }
                    }
                }

                // If all conditions match, add the product ID to the matchingProductIds array
                if ($flashsale_status) {
                    $matchingProductIds[] = $product->id;
                }
            }
            $flashsale->sale_product = json_encode($matchingProductIds);

            // Save the FlashSale model and other updates
            $flashsale->save();

            return redirect()->back()->with('success', __('Flashsale successfully updated.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(FlashSale $flashSale)
    {
        if ($refusal = $this->flashSaleWriteRefusal('Delete Flash Sale')) {
            return $refusal;
        }

        if ($this->scopedFlashSale($flashSale->id) === null) {
            return redirect()->back()->with('error', __('Flash sale not found.'));
        }

        if (auth()->user() && auth()->user()->isAbleTo('Delete Flash Sale')) {
            $flashsale = $this->scopedFlashSale($flashSale->id);
            /* SCOPED. This was `where('flashsale_id', …)->delete()` with no store predicate, so
               it deleted the victim's conditions along with their sale. */
            $condition = FlashSaleCondition::where('flashsale_id', $flashSale->id)
                ->where('store_id', getCurrentStore())
                ->delete();
            $flashsale->delete();

            return redirect()->back()->with('success', __('Flashsale delete successfully.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function get_options(Request $request)
    {
        $id = $request->id;
        $condition_value = $request->condition_value;
        $data = [];

        if ($id == '1') {
            $product = Product::where('store_id', getCurrentStore())->pluck('id', 'name');
            if (! empty($product)) {
                foreach ($product as $key => $value) {
                    $data['product'][] = [
                        'id' => $value,
                        'name' => $key,
                    ];
                }
            }
            $data['condition'] = FlashSale::$condition;
            $data['condition_value'] = $condition_value;
        } elseif ($id == '2') {
            $category = Category::where('store_id', getCurrentStore())->pluck('id', 'name');
            foreach ($category as $key => $value) {
                $data['product'][] = [
                    'id' => $value,
                    'name' => $key,
                ];
            }
            $data['condition'] = FlashSale::$condition;
            $data['condition_value'] = $condition_value;
        } elseif ($id == '0') {
            $data['message'] = 'campaign for shop';
        } elseif ($id == '3') {
            $data['condition'] = FlashSale::$price_condition;
            $data['message'] = 'campaign for price';
            $data['condition_value'] = $condition_value;
        }

        return response()->json($data);
    }

    public function updateStatus(Request $request)
    {
        $flashSaleId = $request->input('flashsaleId');
        $isActivated = $request->input('isActivated');
        $flashSale = FlashSale::findOrFail($flashSaleId);
        if ($isActivated == 'true') {
            $flashSale->is_active = 1;
        } else {
            $flashSale->is_active = 0;
        }
        $flashSale->save();
        $response = [
            'status'    => true,
            'message'   => __('Status Updated Successfully!'),
        ];
        return response()->json($response);
    }
}
