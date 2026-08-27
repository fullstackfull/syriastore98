<?php

namespace Workdo\ProductPricing\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Product;
use App\Models\Customer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Workdo\ProductPricing\app\Models\ProductPricingRule;
use PhpParser\Node\Stmt\TryCatch;
use Yajra\DataTables\Facades\DataTables;

class ProductPricingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('product-pricing::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        if (\Auth::user()) {
            $pricing_methods    = ProductPricingRule::$pricing_methods;
            return view('product-pricing::admin.createOrUpdate',compact('pricing_methods'));
        } else {
            return redirect()->back()->with('error', __('Something want wrong.'));
        }
    }

    /**
     * Store a newly created resource in storage.
     */

    public function store(Request $request): RedirectResponse
    {
        session()->put('app_setting_tab', $request->app_setting_tab);
        $datas = [];
        if ($request->pricing_method == 'simple_pricing') {
            $datas['discount_type']     = $request->discount_type;
            $datas['discount_value']    = $request->discount_value;
            $data[]                     = $datas;
            $data                       = json_encode($data);
        } elseif ($request->pricing_method == 'bulk_pricing') {
            $data = [];
            foreach ($request->bulk_pricing_repeater as $key => $values) {
                $datas = [];
                foreach ($values as $valueKey => $value) {
                    $datas[$valueKey] = $value;
                }
                $data[] = $datas;
            }
            $data = json_encode($data);
        } elseif ($request->pricing_method == 'buy_x_get_x' || $request->pricing_method == 'buy_x_get_y') {
            $datas['buy_quantity']      = $request->buy_quantity;
            $datas['get_quantity']      = $request->get_quantity;
            $datas['discount_type']     = $request->discount_type;
            $datas['discount_value']    = $request->discount_value;
            $data[]                     = $datas;
            $data                       = json_encode($data);
        }

        if (isset($request->product_repeater)) {
            $productRepeaterData = [];
            foreach ($request->product_repeater as $key => $values) {
                $productRepeaterDatas = [];
                foreach ($values as $valueKey => $value) {
                    if ($valueKey == 'selected_products') {
                        $productRepeaterDatas[$valueKey] = implode(',',$value);
                    } else {
                        $productRepeaterDatas[$valueKey]= $value;
                    }
                }
                $productRepeaterData[] = $productRepeaterDatas;
            }
            $productRepeaterData = json_encode($productRepeaterData);
        }

        if (isset($request->product_get_repeater)) {
            $productGetRepeaterData = [];
            foreach ($request->product_get_repeater as $key => $values) {
                $productGetRepeaterDatas = [];
                foreach ($values as $valueKey => $value) {
                    if ($valueKey == 'get_selected_products') {
                        $productGetRepeaterDatas[$valueKey] = implode(',',$value);
                    } else {
                        $productGetRepeaterDatas[$valueKey]= $value;
                    }
                }
                $productGetRepeaterData[] = $productGetRepeaterDatas;
            }
            $productGetRepeaterData = json_encode($productGetRepeaterData);
        }
        if (isset($request->conditions_repeater)) {
            $conditionsRepeaterData = [];
            foreach ($request->conditions_repeater as $key => $values) {
                $conditionsRepeaterDatas = [];
                foreach ($values as $valueKey => $value) {
                    if ($valueKey == 'cart_items_products_selected' || $valueKey == 'cart_items_quantity_products_selected' || $valueKey == 'condition_customers') {
                        $conditionsRepeaterDatas[$valueKey] = implode(',',$value);
                    } else {
                        $conditionsRepeaterDatas[$valueKey] = $value;
                    }
                }
                $conditionsRepeaterData[] = $conditionsRepeaterDatas;
            }
            $conditionsRepeaterData = json_encode($conditionsRepeaterData);
        }

        $productPricingRule = ProductPricingRule::updateOrCreate(
                                    [
                                        'id'            => $request->productPricingRuleId ?? 0,
                                        'created_by'    => \Auth::user()->id,
                                        'store_id'      => getCurrentStore(),
                                    ],
                                    [
                                        'pricing_method'    => $request->pricing_method ?? null,
                                        'quantity_type'     => $request->quantity_type ?? null,
                                        'field_json'        => $data ?? null,
                                        'product_json'      => $productRepeaterData ?? null,
                                        'product_get_json'  => $productGetRepeaterData ?? null,
                                        'conditions'        => $conditionsRepeaterData ?? null,
                                    ]
                                );
        if ($request->productPricingRuleId == 0) {
            if ($productPricingRule) {
                return redirect()->route('app-setting.index')->with('success', __('Product pricing rule created successfully.'));
            } else {
                return redirect()->back()->with('error', __('Something want wrong.'));
            }
        } else {
            return redirect()->back()->with('success', __('Product pricing rule updated successfully.'));
        }

    }

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('product-pricing::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        if (\Auth::user()) {
            $pricing_methods    = ProductPricingRule::$pricing_methods;
            $productPricingRule = ProductPricingRule::where('id',$id)->where('store_id',getCurrentStore())->first();
            return view('product-pricing::admin.createOrUpdate',compact('pricing_methods','productPricingRule'));
        } else {
            return redirect()->back()->with('error', __('Something want wrong.'));
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id): RedirectResponse
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            ProductPricingRule::where('id', $id)->where('store_id',getCurrentStore())->delete();
            return redirect()->back()->with('success', __('Product pricing rule deleted successfully.'));
        } catch (\Throwable $th) {
            return redirect()->back()->with('error', __('Something want wrong.'));
        }
    }

    public function productPricingFields(Request $request)
    {
        if (isset($request->pricing_method) && !empty($request->pricing_method)) {
            $pricing_method         = $request->pricing_method;
            $ruleId                 = $request->ruleId;
            $quantity_types         = ProductPricingRule::$quantity_types;
            $discount_types         = ProductPricingRule::$discount_types;
            $product_types          = ProductPricingRule::$product_types;
            $inOrNotConditions      = ProductPricingRule::$inOrNotConditions;
            $condition_types        = ProductPricingRule::$condition_types;
            $products               = Product::where('store_id',getCurrentStore())->where('variant_product',0)->get()->pluck('name','id');
            $productPricingRule     = ProductPricingRule::find($ruleId ?? null);
            $decodeFieldJson        = json_decode($productPricingRule->field_json ?? null);

            $quantityFieldHTML = '';
            if ($pricing_method != 'simple_pricing') {
                $quantityFieldHTML .= '<label for="quantity_type" class="form-label">Quantity Type</label>';
                $quantityFieldHTML .= '<select name="quantity_type" class="form-control" required>';

                foreach ($quantity_types as $value => $quantity_type) {
                    $select = ($value == ($productPricingRule->quantity_type ?? '') ? 'selected' : '');
                    $quantityFieldHTML .= '<option value="' . $value . '" '.$select.'>' . $quantity_type . '</option>';
                }

                $quantityFieldHTML .= '</select>';
            }

            $returnHTML         = view('product-pricing::admin.fields', compact('pricing_method','discount_types','product_types','inOrNotConditions','products','condition_types','ruleId','productPricingRule','decodeFieldJson'))->render();
            $response = [
                'is_success'            => true,
                'message'               => '',
                'html'                  => $returnHTML,
                'quantityFieldHTML'     => $quantityFieldHTML,
            ];
            return response()->json($response);
        }

    }

    public function conditionFields(Request $request)
    {
        if (isset($request->condition_type) && !empty($request->condition_type)) {
            $condition_type             = $request->condition_type;
            $dataId                     = $request->dataId;
            $repeater_id                = $request->repeater_id;
            $conditionRuleId            = $request->conditionRuleId;
            $atLeastOrMoreConditions    = ProductPricingRule::$atLeastOrMoreConditions;
            $productItemConditions      = ProductPricingRule::$productItemConditions;
            $inOrNotConditions          = ProductPricingRule::$inOrNotConditions;
            $products                   = Product::where('store_id',getCurrentStore())->where('variant_product',0)->get()->pluck('name','id');
            $productPricingRule         = ProductPricingRule::find($conditionRuleId ?? null);
            $decodeConditions           = json_decode($productPricingRule->conditions ?? null);

            $customers = Customer::where('store_id', getCurrentStore())
                     ->get(['id', \DB::raw("CONCAT(first_name, ' ', last_name) AS full_name")])
                     ->pluck('full_name', 'id');
            $conditionFieldHTML         = view('product-pricing::admin.conditionFields', compact('condition_type','atLeastOrMoreConditions','productItemConditions','products','inOrNotConditions','customers','dataId','repeater_id','productPricingRule','decodeConditions'))->render();
            $response = [
                'is_success'            => true,
                'message'               => '',
                'conditionFieldHTML'    => $conditionFieldHTML,
            ];
            return response()->json($response);
        }
    }

    public function rulesCondition(Request $request)
    {
        if (isset($request->all_rules_condition)) {
            $post = $request->all();
            unset($post['_token']);
            $settingQuery = Setting::query();
            foreach ($post as $key => $data) {
                (clone $settingQuery)->updateOrCreate(
                    [
                        'name'          => $key,
                        'store_id'      => getCurrentStore()
                    ],
                    [
                        'value'         => $data,
                        'name'          => $key,
                        'store_id'      => getCurrentStore(),
                        'created_by'    => \Auth::user()->id,
                    ]
                );
            }
            $response = [
                'status'    => true,
                'message'       => __('Product pricing rules condition updated successfully.'),
            ];
        } else {
            $response = [
                'status'    => false,
                'message'       => __('Something went wrong.'),
            ];
        }

        return response()->json($response);
    }

    public function ruleCondition(Request $request)
    {
        if (isset($request->rule_condition) && isset($request->rule_id)) {
            $productPricingRule = ProductPricingRule::find($request->rule_id ?? 0);
            $productPricingRule->rule_status = $request->rule_condition ?? 'none';
            $productPricingRule->save();
            $response = [
                'status'    => true,
                'message'       => __('Product pricing rule condition updated successfully.'),
            ];
        } else {
            $response = [
                'status'    => false,
                'message'       => __('Something went wrong.'),
            ];
        }

        return response()->json($response);
    }

    public function getProductPricingRules(Request $request)
    {
        if ($request->ajax()) {
            // Fetch product pricing rules for the current store
            $productPricingRules = ProductPricingRule::where('store_id', getCurrentStore())
                ->orderBy('id', 'desc');

            return DataTables::of($productPricingRules)
                ->editColumn('pricing_method', function (ProductPricingRule $rule) {
                    $pricing_method         = ProductPricingRule::$pricing_methods;
                    return $pricing_method[$rule->pricing_method];
                })
                ->addColumn('conditions', function (ProductPricingRule $rule) {
                    $ruleConditions         = ProductPricingRule::$ruleConditions;
                    return view('product-pricing::admin.ruleConditionDropDown',compact('rule','ruleConditions'));
                })
                ->addColumn('action', function (ProductPricingRule $rule) {
                    return '<span class="d-flex gap-1 justify-content-end">
                                <a href="' . route('productpricing.edit', $rule->id) . '" class="btn btn-sm btn-info" data-bs-toggle="tooltip" title="'. __('Edit') .'">
                                    <i class="ti ti-pencil"></i>
                                </a>
                                <form action="' . route('productpricing.destroy', $rule->id) . '" method="POST" class="d-inline">
                                    ' . csrf_field() . '
                                    ' . method_field('DELETE') . '
                                    <button type="button" class="btn btn-sm btn-danger show_confirm" data-bs-toggle="tooltip" data-confirm="'. __('Are You Sure?') . '"
                                                                data-text="'. __('This action can not be undone. Do you want to continue?') .'" title="'. __('Delete') .'">
                                        <i class="ti ti-trash"></i>
                                    </button>
                                </form>
                            </span>';
                })
                ->filterColumn('pricing_method', function ($query, $keyword) {
                    $query->where('pricing_method', 'like', "%$keyword%");
                })
                ->rawColumns(['pricing_method', 'conditions', 'action'])
                ->make(true);
        }


        return view('product-pricing::admin.productPricingSettingDetails'); // Replace with your actual view file
    }

}
