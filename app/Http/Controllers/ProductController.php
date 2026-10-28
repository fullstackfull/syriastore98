<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Product;
use App\Models\Category;
use App\Models\Shipping;
use App\Models\Tax;
use App\Models\Tag;
use App\Models\Setting;
use App\Models\User;
use App\Models\Plan;
use App\Models\Utility;
use App\Models\Store;
use App\Models\ProductAttributeOption;
use App\Models\ProductAttribute;
use App\Models\ProductVariant;
use App\Models\ProductImage;
use App\Models\ProductBrand;
use App\Models\ProductLabel;
use Illuminate\Support\Facades\File;
use App\Models\NotifyUser;
use App\Models\ShopifyConection;
use App\Models\WoocommerceConection;
use App\DataTables\ProductDataTable;
use App\Models\FlashSale;
use Illuminate\Support\Facades\Cache;
use App\Models\TaxOption;
use Illuminate\Http\Request;

class ProductController extends Controller
{

    /**
     * F75 -- gallery images the SERVER refused during this request, as `filename => reason`.
     *
     * A PROPERTY RATHER THAN A LOCAL, for a reason worth keeping. The three gallery loops sit in
     * different branches (`store()` variant and non-variant, and `update()`), while the message the
     * merchant actually receives is composed in ONE place per method, far below them -- `store()`
     * at "Product saved successfully." and `update()` at "Product update successfully." -- each of
     * which OVERWRITES anything set earlier. The first version of this fix appended the warning
     * next to the loop and measured nothing, twice over: the guard it used (`isset($msg['msg'])`)
     * was false there, because the earlier assignment only runs `if ($webhook)`, and even when that
     * did run the final assignment replaced it. Measured: the response was
     * `{"flag":"success","msg":"Product saved successfully."}` with the warning nowhere in it.
     */
    private array $ssRejectedImages = [];

    /**
     * F75 -- add the refusals to a SUCCESS response, because the product really was saved.
     *
     * `flag` stays `success` and `msg` keeps its existing text; what changes is that the merchant
     * learns which files did not make it and why, in the same `text-danger` shape the webhook
     * warning already uses. `rejected_images` is additive and machine-readable for the editor.
     */
    private function ssAppendRejectedImages(array $msg): array
    {
        if ($this->ssRejectedImages === []) {
            return $msg;
        }

        $lines = [];
        foreach ($this->ssRejectedImages as $name => $why) {
            $lines[] = e((string) $name) . ' — ' . e((string) $why);
        }

        $msg['msg'] = (string) ($msg['msg'] ?? '')
            . '<br> <span class="text-danger">'
            . e(__('These images were not uploaded:')) . ' '
            . implode('; ', $lines) . '</span>';
        $msg['rejected_images'] = $this->ssRejectedImages;

        return $msg;
    }

    /**
     * D-20. REFUSE A NARROWING THAT WOULD DESTROY VARIANTS, NAME WHAT WOULD BE LOST, AND MAKE THE
     * DELETE A SEPARATE, CONFIRMED ACT.
     *
     * Removing a term from an attribute makes the posted combination set narrower than the rows
     * that exist, and the rebuild then hard-deleted every uncovered row — not soft-deleted, so a
     * raw table read could not see it either. Measured in the browser: a product with 8 variants
     * saved with a 4-combination option set came back with 4 rows, the other 4 gone with their
     * prices, SKUs and stock. The owner's ruling is to refuse, name the data, and require an
     * independent confirmation.
     *
     * CONDITION 4 — "check the references that could make a delete dangerous" — IS THE PART THAT
     * CANNOT BE FULLY SATISFIED, and the honest answer shapes the whole design. Only `carts` and
     * `wishlists` carry a `variant_id` COLUMN. An order records what it contained in
     * `orders.product_json`, and the storefront writer's line shape has **no `variant_id` key at
     * all** — measured on the development fixture, all 14 order lines carry
     * `final_price, name, price, product_id, qty, total_orignal_price` and nothing else. So
     * "this variant appears in no order" is **not provable**, and by condition 5 an unprovable
     * delete must be refused rather than performed silently.
     *
     * Hence two different refusals, and the difference matters to the merchant:
     *   - a reference we CAN see (a cart or wishlist row, or an order line that does carry the
     *     id) is a hard refusal — confirming does not make it safe;
     *   - otherwise the merchant is told exactly which variants would go and what they carry, and
     *     must come back with an explicit confirmation. The confirmation is not a rubber stamp:
     *     it is the acknowledgement that order history may reference what is about to be deleted
     *     and cannot be checked.
     *
     * It returns the handler's own `['flag' => 'error', 'msg' => ...]` shape, which
     * `edit.blade.php`'s success handler renders, and `null` to proceed.
     *
     * @param  \Illuminate\Support\Collection  $removed  the variant rows the posted set no longer covers
     * @return array<string,mixed>|null
     */
    private function ssRefuseVariantRemoval(Request $request, $product, $removed): ?array
    {
        if ($removed->isEmpty()) {
            return null;
        }

        $names = $removed->pluck('variant')->map(fn ($v) => (string) $v)->all();
        $ids = $removed->pluck('id')->map(fn ($v) => (int) $v)->all();

        /* --- condition 4: the references that ARE checkable ------------------------------- */
        $blockers = [];

        $inCarts = \App\Models\Cart::withoutGlobalScopes()->whereIn('variant_id', $ids)->count();
        if ($inCarts > 0) {
            $blockers[] = __(':count shopper cart line(s)', ['count' => $inCarts]);
        }

        /* The Eloquent model, not `DB::table('wishlists')`. The raw form works, but
           `RawQueryTenancyInventoryTest` counts raw entry points against tenant-discriminated
           tables and a new one fails three of its assertions — correctly, because a raw query is
           exactly where a tenant predicate goes missing. `Wishlist` carries no StoreScope either,
           so this is not a scoping claim; it is staying inside the shape the inventory can see. */
        $inWishlists = \App\Models\Wishlist::withoutGlobalScopes()->whereIn('variant_id', $ids)->count();
        if ($inWishlists > 0) {
            $blockers[] = __(':count wishlist entry(ies)', ['count' => $inWishlists]);
        }

        /* An order line only sometimes carries the VARIANT id; when it does, it is a real
           reference and this finds it. The PRODUCT id, by contrast, is on every line — measured,
           14 of 14 in the fixture — and that asymmetry is what makes a provable case possible
           at all. See $ordersNamingProduct below. */
        $inOrders = 0;
        foreach (\App\Models\Order::withoutGlobalScopes()
            ->where('store_id', getCurrentStore())
            ->whereNotNull('product_json')
            ->pluck('product_json') as $json) {
            $lines = json_decode((string) $json, true);

            if (! is_array($lines)) {
                continue;
            }

            foreach ($lines as $line) {
                if (is_array($line) && ! empty($line['variant_id']) && in_array((int) $line['variant_id'], $ids, true)) {
                    $inOrders++;
                    break;
                }
            }
        }

        if ($inOrders > 0) {
            $blockers[] = __(':count order(s)', ['count' => $inOrders]);
        }

        if ($blockers !== []) {
            return [
                'flag' => 'error',
                'msg' => __('These variants cannot be removed because they are still referenced: :names. Referenced by: :refs. Remove or complete those references first.', [
                    'names' => implode(', ', $names),
                    'refs' => implode('; ', $blockers),
                ]),
            ];
        }

        /*
         * --- THE OWNER'S CORRECTION, 2026-10-27: CONFIRMATION DOES NOT SUBSTITUTE FOR PROOF ----
         *
         * > لا أوافق على الحذف النهائي لمتغير قد تكون له مراجع تاريخية غير قابلة للتحقق، حتى مع
         * > تأكيد التاجر. التأكيد شرط للتغييرات المسموحة، لكنه لا يلغي شرط إثبات السلامة.
         *
         * The first version of this guard let an explicit confirmation carry a delete whose safety
         * was unprovable. That was wrong: a merchant agreeing to a warning does not make order
         * history checkable, and what is lost is commercial data.
         *
         * WHAT MAKES A PROVABLE CASE POSSIBLE AT ALL is an asymmetry in the order line shape. The
         * VARIANT id is absent from the storefront writer's lines entirely, so "no order used this
         * variant" cannot be established. The PRODUCT id is on every line — measured, 14 of 14
         * across the fixture's 7 orders — so "no order ever contained this PRODUCT" CAN be
         * established, and that entails no order contained any of its variants.
         *
         * So two genuinely different situations, kept apart rather than blurred into one warning:
         *
         *   - NO order names this product  -> deletion is provably safe; an explicit confirmation
         *                                     may carry it (condition 3, below).
         *   - ANY order names this product -> the variant may be in that history and nothing can
         *                                     show otherwise. HARD BLOCK, and no confirmation is
         *                                     offered, because there is nothing a merchant could
         *                                     usefully confirm.
         */
        $ordersNamingProduct = 0;

        foreach (\App\Models\Order::withoutGlobalScopes()
            ->where('store_id', getCurrentStore())
            ->whereNotNull('product_json')
            ->pluck('product_json') as $json) {
            $lines = json_decode((string) $json, true);

            if (! is_array($lines)) {
                continue;
            }

            foreach ($lines as $line) {
                if (is_array($line) && (int) ($line['product_id'] ?? 0) === (int) $product->id) {
                    $ordersNamingProduct++;
                    break;
                }
            }
        }

        if ($ordersNamingProduct > 0) {
            return [
                'flag' => 'error',
                'msg' => __('These variants cannot be deleted: :names. This product appears in :count past order(s), and an order line does not record which variant was bought — so nothing can show that these variants are not part of that history. Deleting them would destroy their prices, SKUs and stock with no way to recover what an order referred to. Nothing has been changed. To stop selling them while keeping the record, set them out of stock instead.', [
                    'names' => implode(', ', $names),
                    'count' => $ordersNamingProduct,
                ]),
                'ss_variant_removal' => [
                    'variants' => $names,
                    'count' => count($names),
                    'blocked' => 'unverifiable_order_history',
                    'orders_naming_product' => $ordersNamingProduct,
                ],
            ];
        }

        /* --- condition 3: a deliberate delete is a separate, confirmed act ----------------- */
        if (! $request->boolean('ss_confirm_variant_removal')) {
            /* Condition 2: the names, the count, and what each one carries, so the merchant is
               deciding about real data rather than about a number. */
            $detail = $removed->map(function ($v) {
                return trim(sprintf(
                    '%s (%s)',
                    (string) $v->variant,
                    implode(', ', array_filter([
                        $v->sku !== null && $v->sku !== '' ? __('SKU :sku', ['sku' => $v->sku]) : null,
                        $v->variation_price !== null ? __('price :price', ['price' => $v->variation_price]) : null,
                        __('stock :stock', ['stock' => (int) $v->stock]),
                    ]))
                ));
            })->all();

            return [
                'flag' => 'error',
                'msg' => __('This change would permanently delete :count variant(s): :detail. Their prices, SKUs and stock would be lost. No past order names this product, so nothing in order history refers to them. Nothing has been changed. To proceed, confirm the removal explicitly.', [
                    'count' => count($names),
                    'detail' => implode(' | ', $detail),
                ]),
                'ss_variant_removal' => [
                    'variants' => $names,
                    'count' => count($names),
                    'confirm_with' => 'ss_confirm_variant_removal',
                ],
            ];
        }

        return null;
    }

    /**
     * What a per-variant value column should become on a save -- owner decision D-09 (A1).
     *
     * The product edit page renders the variant values panel on load, with no interaction, and
     * the panel echoes a BLANK field for every column that is currently NULL. The writer this
     * replaces read that blank through `!empty()` and wrote the column's DEFAULT, so an ordinary
     * save turned NULL into 0 across prices, stock, weight and thresholds on variants the
     * merchant had not touched (ledger F105), and made a deliberate "0" impossible to store at
     * all, because `!empty("0")` is false (F83).
     *
     * The rule, in one place so the gate and the writer cannot disagree about it:
     *
     *   - key ABSENT            -> an existing row keeps its value; a new row takes $default
     *   - key PRESENT and blank -> the same, UNLESS $blankIsAValue, which is set for the columns
     *                              where a blank is a real choice a merchant can make: a cleared
     *                              description, the shipping select's prepended "Same as parent",
     *                              and the stock-status select's "Select option"
     *   - key PRESENT           -> written exactly as posted, "0" included
     *
     * Note it does NOT validate. The posted value reaches the column as the request supplied it,
     * which is what the writer did before; typing is enforced by the column, and on SQLite that
     * means it is not enforced at all, so a numeric guarantee here would be a false one. See
     * `tests/Support/Security/bin/` for the MySQL-backed pattern this project uses instead.
     *
     * @param  mixed  $current  the column's value on the row being updated
     * @param  mixed  $default  what a newly created row gets when the key is absent
     * @return mixed
     */
    private function ssVariantValue(Request $request, string $key, $current, $default, bool $isNew, bool $blankIsAValue = false)
    {
        if (! $request->has($key)) {
            return $isNew ? $default : $current;
        }

        $posted = $request->input($key);

        /* `$posted === []` is here for parity with the `!empty()` it replaced: an empty array is
           not expressible in a form body, but a JSON caller can send one, and letting it through
           binds an array to a numeric column and fails at PDO. Everything else the old idiom
           passed through -- a non-empty array included -- still passes through, so this narrows
           nothing a real caller relies on. */
        if (! $blankIsAValue && (is_null($posted) || $posted === []
            || (is_string($posted) && trim($posted) === ''))) {
            return $isNew ? $default : $current;
        }

        return $posted;
    }

    /**
     * P2-03-4. The field names `:attribute` substitutes into a validation message.
     * =========================================================================
     *
     * MEASURED on the live form with `lang=ar`: an empty name rendered
     * `"الحقل name مطلوب."` -- an Arabic sentence with the raw COLUMN name in it. Laravel
     * resolves `:attribute` from `validation.attributes.<field>` and, finding nothing, falls
     * back to the humanised key, which is the English column name.
     *
     * WHY THE VALIDATOR'S FOURTH ARGUMENT AND NOT `validation.attributes`. That map is global:
     * an entry for `name` renames the field in every message on every form in the platform --
     * category, brand, tax, coupon, shipping. Most would read correctly, but "most" is not a
     * basis for a platform-wide change, and `ArabicValidationMessagesTest` asserts the map stays
     * empty for exactly that reason. Passed here instead, the names apply to the product form
     * and nowhere else.
     *
     * EACH LABEL IS THE ONE THE FORM ITSELF SHOWS, taken from the `Form::label` calls in
     * `product/edit.blade.php` (:70, :132, :139, :154, :163, :169, :178) so that the message
     * names the field the merchant is looking at. `__()` keeps English unchanged -- an absent
     * `ar.json` key returns the key, which IS the English label.
     *
     * `variant_product` cannot fail from a browser (a hidden `0` partner sits beside the
     * checkbox, edit.blade.php:294) but carries `required`, so it is named for the case where a
     * non-browser client omits it.
     *
     * @return array<string,string>
     */
    private function ssProductFieldNames(): array
    {
        return [
            'name' => __('Name'),
            'category_id' => __('Category'),
            'status' => __('Status'),
            'variant_product' => __('Display Variants'),
            'brand_id' => __('Brand'),
            'label_id' => __('Label'),
            'product_weight' => __('Weight'),
            'price' => __('Price'),
            'sale_price' => __('Sale Price'),
            'cost_price' => __('Cost Price'),
        ];
    }
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request,ProductDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Products')) {
            /*
             * P2-06-5 — the merchant's filter selection survives navigation and reload.
             *
             * INSIDE THE PERMISSION GATE, deliberately: a caller who may not see this list never
             * reads or writes the memory, and never learns from a redirect that one exists.
             *
             * The restore is a 302 rather than a client-side form fill, because the DataTable's
             * `ajax.url` is `url()->full()` evaluated when the page is RENDERED. Filling the
             * controls, or rewriting the query with `history.replaceState`, would leave the
             * table's second leg still requesting the unfiltered url — the page would look
             * filtered and the rows would not be. A redirect makes a restored request
             * byte-identical to a typed one.
             */
            if ($request->has(\App\Services\TableFilterMemory::CLEAR)) {
                \App\Services\TableFilterMemory::forget('product');

                return redirect()->route('product.index');
            }

            if (\App\Services\TableFilterMemory::shouldRestore($request)) {
                $remembered = \App\Services\TableFilterMemory::recall('product');

                if ($remembered !== []) {
                    return redirect()->route('product.index', $remembered)
                        ->with('ss_filters_restored', true);
                }
            }

            \App\Services\TableFilterMemory::remember($request, 'product');

            $products = Product::where('product_type', null)->where('store_id', getCurrentStore())->orderBy('id', 'desc')->get();
            $settings = Setting::where('store_id', getCurrentStore())->pluck('value', 'name')->toArray();

            /*
             * P2-03-2. The category options for the list's filter bar.
             *
             * EXPLICITLY STORE-FILTERED. `Category` is not in `config/tenancy.php`'s
             * `scoped_models`, so it adds no predicate for any principal -- without this
             * `where` the filter dropdown would list every tenant's category NAMES, which is a
             * disclosure in a select box rather than in a table.
             *
             * The caller already holds `Manage Products` to be here, which is the permission
             * the list itself gates on; the categories are shown only as filter options for
             * that list.
             */
            $filterCategories = \App\Models\Category::where('store_id', getCurrentStore())
                ->orderBy('name')
                ->pluck('name', 'id');

            if ($request->id == 1) {
                $msg = __('Product Successfully Created');
                return $dataTable->render('product.index', compact('products', 'settings', 'msg', 'filterCategories'));
            } elseif ($request->id == 2) {
                $msg = __('Product Successfully Updated');
                return $dataTable->render('product.index', compact('products', 'settings', 'msg', 'filterCategories'));
            } else {
                $msg = 0;
                return $dataTable->render('product.index', compact('products', 'settings', 'msg', 'filterCategories'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        /*
         * F160. THIS FORM HAD NO PERMISSION CHECK OF ANY KIND, AND IT 500s WITH NO STORE.
         *
         * Found while scoping D-24a, whose approval requires checking "employee permission and
         * current store, both on render and on request" -- so the target had to be read, and the
         * target checked neither. `store()` below is gated on `Create Product`, so nothing could be
         * WRITTEN; but a form is a read, and this one renders the store's categories, taxes,
         * shipping methods, brands, labels, tags and product attributes. Any authenticated
         * merchant-side principal could open it. The same shape as F157 on
         * `CouponController::create()`, which is why the sweep continued past that controller.
         *
         * `Create Product`, DELIBERATELY THE SAME PERMISSION `store()` USES, and with no operator
         * branch for the same reason `store()` has none: an operator could not submit this form
         * either way, so offering them one that refuses on submit would be worse than refusing it
         * here. F157's lesson is that a read path must not allow what the write path forbids;
         * identical gates are the narrowest way to satisfy it and invent nothing.
         *
         * AND THE STORE CHECK IS NOT BELT-AND-BRACES. `getCurrentStore()` returns `current_store`,
         * else the hardcoded literal 1 for a `super admin`, else
         * `Store::where('created_by', $user->id)->first()?->id` -- NULL for a staff member who owns
         * no store, which is the ordinary state of a newly added employee. `getStoreById(null)` is
         * then null and `$store->slug` throws. Without this, the permission gate alone would leave
         * a 500 reachable by anyone holding `Create Product` without a resolvable store.
         */
        $user = auth()->user();

        if ($user === null || ! $user->isAbleTo('Create Product')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $store = getStoreById(getCurrentStore());

        if ($store === null) {
            return redirect()->back()->with('error', __('No store is selected.'));
        }

        $link = env('APP_URL') . ($store->slug ? ('/'.$store->slug) : '').'/product/';
        $categories = Category::where('store_id',getCurrentStore())->get();
        $categoryTree = buildCategoryTree($categories);
        $Tax = Tax::where('store_id', getCurrentStore())->pluck('name', 'id');
        $Tax_status = Tax::Taxstatus();

        $Shipping = Shipping::where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Shipping', '');
        $brands = ProductBrand::where('status', 1)->where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Brand', '');
        $labels = ProductLabel::where('status', 1)->where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Label', '');

        $preview_type = [
            'Video File' => 'Video File',
            'Video Url' => 'Video Url',
            'iFrame' => 'iFrame'
        ];
        $tag = Tag::where('store_id', getCurrentStore())->pluck('name', 'id');
        $ProductAttribute = ProductAttribute::where('store_id', getCurrentStore())->pluck('name', 'id');
        
        
        return view('product.create', compact('link', 'categoryTree', 'ProductAttribute', 'Tax', 'Tax_status', 'Shipping', 'preview_type', 'tag', 'brands', 'labels'));
    }









  public function store(Request $request)
    {
        
        if (auth()->user() && auth()->user()->isAbleTo('Create Product')) {
            try {
                $user = \Auth::user();
                $creator = User::find($user->creatorId());
                $total_products = $user->countProducts();
                $plan = Plan::find($creator->plan_id);
                $store_id = getStoreById(getCurrentStore());

                if ($request->variant_product == 0) {
                    $rules = [
                        'name' => 'required',
                        'category_id' => 'required',
                        'cover_image' => 'required',
                        'product_image' => 'required',
                        'status' => 'required',
                        'variant_product' => 'required',
                    'price' => 'required|numeric|min:0',
'sale_price' => 'nullable|numeric|min:0|lt:price',
'cost_price' => 'required|numeric|min:0|lte:price',
                        'brand_id' => 'nullable',
                        'label_id' => 'nullable',
                        'product_weight' => 'nullable|numeric|min:0',   // F70, as in update()

                    ];
                } else {
                    $rules = [
                        'name' => 'required',
                        'category_id' => 'required',
                        'cover_image' => 'required',
                        'product_image' => 'required',
                        'status' => 'required',
                        'variant_product' => 'required',
                        'brand_id' => 'nullable',
                        'label_id' => 'nullable',
                        'product_weight' => 'nullable|numeric|min:0',   // F70, as in update()
                    ];
                }

                $validator = \Validator::make($request->all(), $rules,[
                    'sale_price.lt' => __('The sale price must be less than the regular price.'),
    'cost_price.required' => __('Cost price is required.'),
    'cost_price.lte' => __('Cost price must not be greater than the regular price.'),
    'product_weight.numeric' => __('The weight must be a number.'),
    'product_weight.min' => __('The weight cannot be negative.')
                ], $this->ssProductFieldNames());
                $request->sale_price = $request->sale_price ? $request->sale_price : 0;
                if ($validator->fails()) {
                    $messages = $validator->getMessageBag();
                    $msg['flag'] = 'error';
                    $msg['msg'] = $messages->first();
                    /* F70. `errors` is ADDED, and `flag`/`msg` are untouched.
                     *
                     * The editor posts by AJAX and this response was the only thing it got back:
                     * a flag and the FIRST message. With no field names the client could not
                     * mark the offending control, could not render a summary, and -- the part
                     * the owner asked for -- could not OPEN the collapsed section the failed
                     * field lives in. So the section stayed shut over an error inside it.
                     *
                     * ADDITIVE ON PURPOSE, because the owner's approval requires Flutter and the
                     * existing API contracts to keep working. Two independent reasons this is
                     * safe: adding a key cannot break a reader that looks up `flag` and `msg`;
                     * and the shipped app does not reach this handler at all -- it posts product
                     * writes to `api/admin/addproduct` and `/updateproduct`
                     * (`StoreSyria_app98/lib/utils/base_api.dart:3,57,85`), which are
                     * `Api\DashboardController`, a different handler on a different route. The
                     * web routes here are reached only by this editor's own jQuery.
                     */
                    $msg['errors'] = $messages->toArray();

                    return $msg;
                }

                $totalImageSize = 0;
                if ($request->hasFile('cover_image')) {
                    $totalImageSize += $request->file('cover_image')->getSize();
                }
                if ($request->hasFile('preview_video')) {
                    $totalImageSize += $request->file('preview_video')->getSize();
                }
                if ($request->hasFile('downloadable_product')) {
                    $totalImageSize += $request->file('downloadable_product')->getSize();
                }
                if (!empty($request->product_image)) {
                    foreach ($request->product_image as $key => $image) {
                        $totalImageSize += File::size($image);
                    }
                }
                $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $totalImageSize);
                if ($result != 1) {
                    $msg['flag'] = 'error';
                    $msg['msg'] = $result;

                    return $msg;
                }
                $dir = 'uploads/' . $store_id->id;
                if ($request->variant_product == 0) {
                    if ($total_products < $plan->max_products || $plan->max_products == -1) {
                        $input = $request->all();
                        $input['attribute_options'] = [];
                        if ($request->has('attribute_no')) {
                            foreach ($request->attribute_no as $key => $no) {
                                $str = 'attribute_options_' . $no;
                                $enable_option = $input['visible_attribute_' . $no];
                                $variation_option = $input['for_variation_' . $no];
                                $item['attribute_id'] = $no;

                                if (!empty($request[$str])) {
                                    $item['values'] = explode(',', implode('|', $request[$str]));
                                } else {
                                    $item['values'] = [];
                                }

                                $item['visible_attribute_' . $no] = $enable_option;
                                $item['for_variation_' . $no] = $variation_option;
                                array_push($input['attribute_options'], $item);
                            }
                        }



                        if (!empty($request->attribute_no)) {
                            $input['product_attributes'] = implode(',', $request->attribute_no);
                        } else {
                            $input['product_attributes'] = 0;
                        }
                        $input['attribute_options'] = json_encode($input['attribute_options']);
                        $Product = new Product();
                        $Product->name = $request->name;
                        if (isset($request->slug) && !empty($request->slug)) {
                            $Product->slug = $request->slug;
                        } else {
                            $Product->slug = Product::slugs($request->name);
                        }

                        $Product->category_id = $request->category_id;
                        $Product->brand_id = $request->brand_id ?? null;
                        $Product->label_id = $request->label_id ?? null;
                        if (!empty($request->tax_id)) {
                            $Product->tax_id = implode(',', $request->tax_id);
                        } else {
                            $tax = Tax::where('store_id', getCurrentStore())->first();
                            if (isset($tax)) {
                                $Product->tax_id = $tax->id;
                            }
                        }

                        if ($request->cover_image) {

                            if ($result == 1) {
                                $fileName = rand(10, 100) . '_' . time() . "_" . $request->cover_image->getClientOriginalName();
                                $path = Utility::upload_file($request, 'cover_image', $fileName, $dir, []);
                                if ($path['flag'] == 0) {
                                    $msg['flag'] = 'error';
                                    $msg['msg'] =  $path['msg'];

                                    return $msg;
                                }
                            } else {

                                $msg['flag'] = 'error';
                                $msg['msg'] = $result;

                                return $msg;
                            }

                            $Product->cover_image_path = $path['url'] ?? null;
                            $Product->cover_image_url = $path['full_url'] ?? null;
                        }

                        $Product->preview_type = $request->preview_type;

                        if (!empty($request->preview_video)) {

                            $ext = $request->file('preview_video')->getClientOriginalExtension();
                            $fileName = 'video_' . time() . rand() . '.' . $ext;
                            $dir_video = 'uploads/' . $store_id->id. '/video';
                            if ($result == 1) {
                                $path_video = Utility::upload_file($request, 'preview_video', $fileName, $dir_video, []);
                                if ($path_video['flag'] == 1) {
                                    $url = $path_video['url'] ?? '';
                                } else {
                                    $msg['flag'] = 'error';
                                    $msg['msg'] = $path_video['msg'];

                                    return $msg;
                                }
                            } else {

                                $msg['flag'] = 'error';
                                $msg['msg'] =  $result;

                                return $msg;
                            }
                            $Product->preview_content = $path_video['url'] ?? null;
                        }
                        if (!empty($request->video_url)) {
                            $Product->preview_content = $request->video_url;
                        }
                        if (!empty($request->preview_iframe)) {
                            $Product->preview_content = $request->preview_iframe;
                        }
                        $Product->tax_status = $request->tax_status;
                        $Product->shipping_id = $request->shipping_id;
                        $Product->product_weight = $request->product_weight;
                        $Product->variant_product = $request->variant_product;
                        // F61. `trending` is NOT in store()'s rules, and on an INSERT an
                        // explicit NULL into a NOT NULL column is ER_BAD_NULL_ERROR on MySQL
                        // whatever the sql_mode -- a column default does not rescue it, and
                        // unlike update()'s UPDATE there is no silent coercion to fall back on.
                        // The create form always posts it (hidden `trending=0` partner at
                        // create.blade.php:270), so this is latent, not live. 0 is both the
                        // column's default and what that partner sends.
                        $Product->trending = $request->trending ?? 0;
                        $Product->status = $request->status;
                        $Product->description = $request->description;
                        $Product->specification = $request->specification;
                        $Product->detail = $request->detail;
                        $Product->price = $request->price;
                        // F61. `sale_price` is `float NOT NULL DEFAULT 0` and is validated
                        // `nullable`, so an omitted or empty value reached a NOT NULL column.
                        // On this INSERT path an explicit NULL is ER_BAD_NULL_ERROR on MySQL
                        // whatever the sql_mode. 0 is the column's own default.
                        $Product->sale_price = $request->sale_price ?? 0;
$Product->cost_price = $request->cost_price;
                        // F61, and this is the INSERT twin of the update() case. `stock_status`
                        // is `string NOT NULL DEFAULT '0'` and is in NEITHER rule set, so a
                        // request without it wrote NULL -- a hard error here rather than the
                        // silent coercion the UPDATE path got. `in_stock` is chosen because it
                        // is exactly what the create form sends: create.blade.php renders the
                        // three radios with `checked` on in_stock, so every browser caller
                        // already posts this value and the handler now agrees with its only
                        // real caller. Only a non-browser client reaches the coalesce.
                        $Product->stock_status = $request->stock_status ?? 'in_stock';
                        $Product->attribute_id = $input['product_attributes'] ?? null;
                        $Product->product_attribute = $input['attribute_options'];

                        $Product->product_stock = !empty($request->product_stock) ? $request->product_stock : 0;
                        if ($request->track_stock == 1) {
                            $Product->track_stock = $request->track_stock ?? 0;
                            $Product->stock_order_status = $request->stock_order_status;
                            $Product->low_stock_threshold = !empty($request->low_stock_threshold) ? $request->low_stock_threshold :  '';
                        } else {
                            $Product->track_stock = !empty($request->track_stock) ? $request->track_stock : 0;
                            $Product->stock_order_status = '';
                            $Product->low_stock_threshold = !empty($request->low_stock_threshold) ? $request->low_stock_threshold :  '';
                        }
                       

                        if (!empty($request->downloadable_product)) {

                            if ($result == 1) {
                                $fileName = rand(10, 100) . '_' . time() . "_" . $request->downloadable_product->getClientOriginalName();
                                $path = Utility::upload_file($request, 'downloadable_product', $fileName, $dir, []);
                                if ($path['flag'] == 0) {
                                    $msg['flag'] = 'error';
                                    $msg['msg'] =  $path['msg'];

                                    return $msg;
                                }
                            } else {
                                $msg['flag'] = 'error';
                                $msg['msg'] =  $result;

                                return $msg;
                            }
                            $Product->downloadable_product = $path['url'];
                        }

                        $tag_data_id = [];
                        $tag_ids = [];

                        if (isset($request->tag_id)) {

                            foreach ($request->tag_id as $tag) {
                                $tags = Tag::where('id', $tag)->where('store_id', getCurrentStore())->first();
                                if (!empty($tags)) {
                                    $tag_id = $tags->id;
                                    $tag_ids[] = $tag_id;
                                } else {
                                    $tag_id = 0;
                                }
                                if ($tag_id != $tag) {
                                    $tag_data = new Tag();
                                    $tag_data->name = $tag;
                                    $tag_data->slug = Tag::slugs($tag);
                                    $tag_data->store_id = getCurrentStore();
                                    $tag_data->created_by = \Auth::user()->id;
                                    $tag_data->save();

                                    $tag_data_id[] = $tag_data->id;
                                }
                            }
                        }
                        $tag_product_id = array_merge($tag_data_id, $tag_ids);
                        if (!empty($tag_product_id)) {

                            $Product->tag_id =  implode(',', $tag_product_id);
                        }
                        $Product->store_id = getCurrentStore();
                        $Product->created_by = \Auth::user()->id;
                        if (module_is_active('CartQuantityControl')) {
                            \Workdo\CartQuantityControl\app\Models\CartQuantityControl::saveData($Product, $request->minimum_quantity, $request->maximum_quantity);
                        }

                        $Product->save();
                        if (module_is_active('PartialPayments')) {
                            \Workdo\PartialPayments\app\Models\PartialPayments::saveData($Product,$request->all());
                        }
                        if (module_is_active('SizeGuideline')) {
                            \Workdo\SizeGuideline\app\Models\SizeGuideline::saveData($Product, $request->size_chart_title, $request->size_chart_information,$request->all());
                        }
                        if (module_is_active('WholesaleProduct')) {
                            \Workdo\WholesaleProduct\app\Models\WholesaleProduct::saveData($Product, $request->wholesale_repeater_basic);
                        }
                        if (module_is_active('ProductBarCode')) {
                            \Workdo\ProductBarCode\app\Models\ProductBarCode::saveData($Product);
                        }

                        if (module_is_active('HubSpot')) {
                            $setting = getAdminAllSetting(auth()->user()->id, getCurrentStore());
                            if (isset($setting['hubspot_is_enable']) && $setting['hubspot_is_enable'] == 'on') {
                                \Workdo\HubSpot\app\Models\HubSpotProductService::saveData($Product, $request->all());
                            }
                        }

                       
                        if (module_is_active('AdditionalCustomFields')) {
                            $settingController = new \Workdo\AdditionalCustomFields\app\Http\Controllers\SettingController();
                            $settingController->saveData($Product, $request->all());
                        }
                       
                        if (!empty($Product))
                        {
                            //webhook
                            $module = 'New Product';
                            $webhook =  Utility::webhook($module, $store_id->id);
                            if ($webhook) {
                                $parameter = json_encode($Product);
                                // 1 parameter is  URL , 2 parameter is data , 3 parameter is method
                                $status = Utility::WebhookCall($webhook['url'], $parameter, $webhook['method']);

                                if ($status != true) {
                                    $msgs  = 'Webhook call failed.';
                                }

                                $msg['flag'] = 'success';
                                $msg['msg']  = __('Product Successfully Created') . ((isset($msgs)) ? '<br> <span class="text-danger">' . $msgs . '</span>' : '');
                                // $msg['msg']  = __('Product Successfully Created');
                            }
                        }
                        foreach ($request->product_image as $key => $image) {
                            $theme_image = $image;

                            if ($result == 1) {
                                $fileName = rand(10, 100) . '_' . time() . "_" . $image->getClientOriginalName();
                                $pathss = Utility::keyWiseUpload_file($request, 'product_image', $fileName, $dir, $key, []);
                                /* F75. A REJECTED GALLERY IMAGE USED TO VANISH IN SILENCE. This helper returns
                                   `['flag' => 0, 'msg' => ...]` when its `mimes:`/`max:` rules refuse the file, and
                                   those rules come from platform settings -- defaulting to `jpg,jpeg,png,csv,svg,pdf`
                                   -- so a GIF, a WebP or a mislabelled file is refused. The COVER image path checks
                                   that flag and returns an error; this loop asked only `isset($pathss['url'])` and
                                   SKIPPED the refusal. MEASURED: two files queued, one row written, no error and no
                                   toast. The product IS created by then, so the refusals are collected and reported
                                   beside the success rather than aborting a save that has already happened. */
                                if (! isset($pathss['url'])) {
                                    $this->ssRejectedImages[(string) $image->getClientOriginalName()] =
                                        $pathss['msg'] ?? __('The file was not accepted.');
                                }
                            } else {
                                $msg['flag'] = 'error';
                                $msg['msg'] =  $result;

                                return $msg;
                            }

                            if (isset($pathss['url'])) {
                                $ProductImage = new ProductImage();
                                $ProductImage->product_id = $Product->id;
                                $ProductImage->image_path = $pathss['url'];
                                $ProductImage->image_url  = $pathss['full_url'];
                                $ProductImage->store_id   = getCurrentStore();
                                $ProductImage->save();
                            }
                        }
                    } else {
                        $msg['flag'] = 'error';
                        $msg['msg'] =   __('Your Product limit is over, Please upgrade plan');

                        return $msg;
                    }
                } else {

                    $input = $request->all();

                    $input['choice_options'] = [];
                    $input['attribute_options'] = [];
                    if ($request->has('choice_no')) {
                        foreach ($request->choice_no as $key => $no) {
                            $str = 'choice_options_' . $no;

                            $item['attribute_id'] = $no;
                            $item['values'] = explode(',', implode('|', $request[$str]));
                            array_push($input['choice_options'], $item);
                        }
                    }

                    if (!empty($request->choice_no)) {
                        $input['attributes'] = json_encode($request->choice_no);
                    } else {
                        $input['attributes'] = json_encode([]);
                    }

                    $input['choice_options'] = json_encode($input['choice_options']);
                    $input['slug'] = $input['name'];

                    if ($request->has('attribute_no')) {
                        foreach ($request->attribute_no as $key => $no) {
                            $str = 'attribute_options_' . $no;
                            $enable_option = $input['visible_attribute_' . $no];
                            $variation_option = $input['for_variation_' . $no];

                            $item['attribute_id'] = $no;
                            if (!empty($request[$str])) {
                                $item['values'] = explode(',', implode('|', $request[$str]));
                            } else {
                                $item['values'] = [];
                            }
                            $item['visible_attribute_' . $no] = $enable_option;
                            $item['for_variation_' . $no] = $variation_option;

                            array_push($input['attribute_options'], $item);
                        }
                    }
                    if (!empty($request->attribute_no)) {
                        $input['product_attributes'] = implode(',', $request->attribute_no);
                    }
                    $input['attribute_options'] = json_encode($input['attribute_options']);

                    $theme_name = APP_THEME();
                    if ($request->cover_image) {

                        if ($result == 1) {
                            $fileName = rand(10, 100) . '_' . time() . "_" . $request->cover_image->getClientOriginalName();
                            $path = Utility::upload_file($request, 'cover_image', $fileName, $dir, []);
                            if ($path['flag'] == 0) {
                                $msg['flag'] = 'error';
                                $msg['msg'] =  $path['msg'];

                                return $msg;
                            }
                        } else {
                            $msg['flag'] = 'error';
                            $msg['msg'] =  $result;

                            return $msg;
                        }
                    }
                    if ($total_products < $plan->max_products || $plan->max_products == -1) {


                        $product = new Product();
                        $product->name = $request->name;
                        if (isset($request->slug) && !empty($request->slug)) {
                            $product->slug = $request->slug;
                        } else {
                            $product->slug = Product::slugs($request->name);
                        }
                        $product->category_id = $request->category_id;
                        if (!empty($request->tax_id)) {
                            $product->tax_id = implode(',', $request->tax_id);
                        } else {
                            $tax = Tax::where('store_id', getCurrentStore())->first();
                            if (isset($tax)) {
                                $product->tax_id = $tax->id;
                            }
                        }
                        $product->tax_status = $request->tax_status;
                        $product->shipping_id = $request->shipping_id;
                        $product->status = $request->status;
                        $product->description = $request->description;
                        $product->specification = $request->specification;
                        $product->detail = $request->detail;
                        $tag_id = $request->tag;
                        $product->cover_image_path = $path['url'] ?? null;
                        $product->cover_image_url = $path['full_url'] ?? null;
                        $product->attribute_id = $input['product_attributes'] ?? null;
                        $product->product_attribute = $input['attribute_options'];


                        $product->preview_type = $request->preview_type;
                        if (!empty($request->video_url)) {
                            $product->preview_content = $request->video_url;
                        }
                        if (!empty($request->preview_video)) {
                            $ext = $request->file('preview_video')->getClientOriginalExtension();
                            $fileName = 'video_' . time() . rand() . '.' . $ext;

                            $dir_video = 'uploads/' .getCurrentStore() . '/video';

                            if ($result == 1) {
                                $path_video = Utility::upload_file($request, 'preview_video', $fileName, $dir_video, []);
                                if ($path_video['flag'] == 1) {
                                    $url = $path_video['url'];
                                } else {
                                    $msg['flag'] = 'error';
                                    $msg['msg'] =  $path_video['msg'];

                                    return $msg;
                                }
                            } else {
                                $msg['flag'] = 'error';
                                $msg['msg'] =  $result;

                                return $msg;
                            }
                            $product->preview_content = $path_video['url'];
                        }
                        if (!empty($request->preview_iframe)) {
                            $product->preview_content = $request->preview_iframe;
                        }
                        if (!empty($request->downloadable_product)) {

                            if ($result == 1) {
                                $fileName = rand(10, 100) . '_' . time() . "_" . $request->downloadable_product->getClientOriginalName();
                                $path = Utility::upload_file($request, 'downloadable_product', $fileName, $dir, []);
                                if ($path['flag'] == 0) {
                                    $msg['flag'] = 'error';
                                    $msg['msg'] =  $path['msg'];

                                    return $msg;
                                }
                            } else {
                                $msg['flag'] = 'error';
                                $msg['msg'] =  $result;

                                return $msg;
                            }
                            $product->downloadable_product = $path['url'];
                        }
                        $product->product_stock = !empty($request->product_stock) ? $request->product_stock : 0;
                        $product->variant_product = $request->variant_product;
                        $product->trending = $request->trending ?? 0;   // F61, as above
                        if (isset($request->track_stock) && $request->track_stock == 1) {
                            $product->track_stock = $request->track_stock;
                            $product->stock_order_status = $request->stock_order_status;
                            $product->low_stock_threshold = !empty($request->low_stock_threshold) ? $request->low_stock_threshold : 0;
                        } else {
                            $product->track_stock = isset($request->track_stock) ? $request->track_stock : 0;
                            $product->stock_order_status = '';
                            $product->low_stock_threshold = !empty($request->low_stock_threshold) ? $request->low_stock_threshold : 0;
                        }


                        $product->attribute_id = $input['product_attributes'] ?? null;
                        $product->product_attribute = $input['attribute_options'];
                        // $product->product_option = json_encode($option_array);
                        // $product->product_option_api = json_encode($option_array_api);
                        $product->shipping_id = $request->shipping_id;
                        $product->store_id = getCurrentStore();
                        $product->created_by = \Auth::user()->id;
                        if ($request->custom_field_status == '1') {
                            $product->custom_field_status = '1';
                            $product->custom_field = json_encode($request->custom_field_repeater_basic);
                        }
                        $tag_data_id = [];
                        $tag_ids = [];

                        if (isset($request->tag_id)) {
                            foreach ($request->tag_id as $tag) {
                                $tags = Tag::where('id', $tag)->where('store_id', getCurrentStore())->first();
                                if (!empty($tags)) {
                                    $tag_id = $tags->id;
                                    $tag_ids[] = $tag_id;
                                } else {
                                    $tag_id = 0;
                                }
                                if ($tag_id != $tag) {
                                    $tag_data = new Tag();
                                    $tag_data->name = $tag;
                                    // F62. `tags.slug` is NOT NULL with no default, and the two
                                    // non-variant siblings of this block (store():356,
                                    // update():1556) already set it. These two variant branches
                                    // never did, so a merchant-typed tag inserted here omitted
                                    // the column: a hard 500 on SQLite and on strict MySQL, and
                                    // on production (strict off) a row silently stored with
                                    // slug = ''. NOTHING READS `tags.slug` anywhere in app/,
                                    // resources/, themes/, routes/ or packages/workdo/ -- the
                                    // column is write-only today and its only consumer is
                                    // Tag::slugs()'s own uniqueness probe -- so what the ''
                                    // rows cost is a broken invariant, not a wrong read. The
                                    // fix also closes a PARTIAL WRITE: here the Tag insert
                                    // happens AFTER $product->save() and the ProductVariant
                                    // saves, and update() has no transaction, so the 500 left
                                    // the variants written while $product->tag_id and
                                    // product_stock never were. Reachable from the UI only
                                    // since the F16 asset fix let select2 load, which turned
                                    // the tag field into a free-text `tags: true` control.
                                    $tag_data->slug = Tag::slugs($tag);
                                    $tag_data->store_id = getCurrentStore();
                                    $tag_data->created_by = \Auth::user()->id;
                                    $tag_data->save();

                                    $tag_data_id[] = $tag_data->id;
                                }
                            }
                        }
                        $tag_product_id = array_merge($tag_data_id, $tag_ids);
                        if (!empty($tag_product_id)) {

                            $product->tag_id =  implode(',', $tag_product_id);
                        }
                        $product->save();
                        if (module_is_active('PartialPayments')) {
                            \Workdo\PartialPayments\app\Models\PartialPayments::saveData($product,$request->all());
                        }
                        if (module_is_active('SizeGuideline')) {

                            \Workdo\SizeGuideline\app\Models\SizeGuideline::saveData($product, $request->size_chart_title,$request->size_chart_information,$request->all());
                        }
                        if (module_is_active('WholesaleProduct')) {
                            \Workdo\WholesaleProduct\app\Models\WholesaleProduct::saveData($product, $request->wholesale_repeater_basic);
                        }
                        if (module_is_active('ProductBarCode')) {

                            \Workdo\ProductBarCode\app\Models\ProductBarCode::saveData($product);
                        }
                  
                        if (module_is_active('AdditionalCustomFields')) {
                            $settingController = new \Workdo\AdditionalCustomFields\app\Http\Controllers\SettingController();
                            $settingController->saveData($product, $request->all());
                        }
                  
                
                        if (!empty($product))
                        {
                            //webhook
                            $module = 'New Product';
                            $webhook =  Utility::webhook($module, $store_id->id);
                            if ($webhook) {
                            $parameter = json_encode($product);
                            // 1 parameter is  URL , 2 parameter is data , 3 parameter is method
                            $status = Utility::WebhookCall($webhook['url'], $parameter, $webhook['method']);

                            if ($status != true) {
                                $msgs  = 'Webhook call failed.';
                            }

                            $msg['flag'] = 'success';
                                    // $msg['msg']  = __('Product Successfully Created') . ((isset($msgs)) ? '<br> <span class="text-danger">' . $msgs . '</span>' : '');
                                    $msg['msg']  = __('Product Successfully Created');
                            }
                        } else {
                            $msg['flag'] = 'error';
                            $msg['msg']  = __('Product Created Failed');

                            return redirect()->back()->with($msg['flag'], $msg['msg']);
                        }

                        foreach ($request->product_image as $key => $image) {
                            $theme_image = $image;

                            if ($result == 1) {
                                $fileName = rand(10, 100) . '_' . time() . "_" . $image->getClientOriginalName();
                                $pathss = Utility::keyWiseUpload_file($request, 'product_image', $fileName, $dir, $key, []);
                                /* F75. A REJECTED GALLERY IMAGE USED TO VANISH IN SILENCE. This helper returns
                                   `['flag' => 0, 'msg' => ...]` when its `mimes:`/`max:` rules refuse the file, and
                                   those rules come from platform settings -- defaulting to `jpg,jpeg,png,csv,svg,pdf`
                                   -- so a GIF, a WebP or a mislabelled file is refused. The COVER image path checks
                                   that flag and returns an error; this loop asked only `isset($pathss['url'])` and
                                   SKIPPED the refusal. MEASURED: two files queued, one row written, no error and no
                                   toast. The product IS created by then, so the refusals are collected and reported
                                   beside the success rather than aborting a save that has already happened. */
                                if (! isset($pathss['url'])) {
                                    $this->ssRejectedImages[(string) $image->getClientOriginalName()] =
                                        $pathss['msg'] ?? __('The file was not accepted.');
                                }
                            } else {
                                $msg['flag'] = 'error';
                                $msg['msg'] =  $result;

                                return $msg;
                            }

                            if (isset($pathss['url'])) {
                                $ProductImage = new ProductImage();
                                $ProductImage->product_id = $product->id;
                                $ProductImage->image_path = $pathss['url'];
                                $ProductImage->image_url  = $pathss['full_url'];
                                $ProductImage->store_id   = getCurrentStore();
                                $ProductImage->save();
                            }
                        }

                        $options = [];
                        $a_option = [];
                        if ($request->has('choice_no')) {
                            foreach ($request->choice_no as $key => $no) {
                                $name = 'choice_options_' . $no;
                                $my_str = implode('|', $request[$name]);
                                array_push($options, explode(',', $my_str));
                            }
                        }

                        $combinations = $this->combinations($options);
                        foreach ($request->attribute_no as $key => $no) {
                            $forVariationName = 'for_variation_' . $no;
                            if ($request->has($forVariationName) && $request->input($forVariationName) == 1) {
                                $name = 'attribute_options_' . $no;
                                $options = 'options';
                                $for_variation = isset($request->{'for_variation_' . $no}) ? $request->{'for_variation_' . $no} : 0;
                                if ($for_variation == 1) {
                                    if ($request->has($options) && is_array($request[$options])) {
                                        $my_str = $request[$options];
                                        $optionValues = [];

                                        foreach ($request[$options] as $term) {
                                            $optionValues[] = $term;
                                        }
                                        array_push($a_option, $my_str);
                                    }
                                }
                            }
                        }

                        $default_variant_id = 0;
                        if (!empty($a_option[0])) {
                            if (count($a_option[0]) > 0) {
                                $product->variant_product = 1;
                                $is_in_stock = false;
                                foreach ($a_option as $key => $com) {
                                    $str = '';
                                    foreach ($com as $key => $item) {

                                        $str = $item;

                                        $product_stock = ProductVariant::where('product_id', $product->id)->where('variant', $str)->first();
                                        if ($product_stock == null) {
                                            $product_stock = new ProductVariant;
                                            $product_stock->product_id = $product->id;
                                        }

                                        $theme_name = APP_THEME();
                                        if ($request['downloadable_product_' . str_replace('.', '_', $str)]) {
                                            $fileName = rand(10, 100) . '_' . time() . "_" . $request->file('downloadable_product_' . $str)->getClientOriginalName();

                                            $path1 = Utility::upload_file($request, 'downloadable_product_' . $str, $fileName, $dir, []);
                                            if ($path1['flag'] == 0) {
                                                $msg['flag'] = 'error';
                                                $msg['msg'] =  $path1['msg'];
            
                                                return $msg;
                                            }
                                            $product_stock->downloadable_product = $path1['url'];
                                        }


                                        $var_option = "";
                                        $variation_option = !empty($request['variation_option_' . str_replace('.', '_', $str)]) ? $request['variation_option_' . str_replace('.', '_', $str)] : '';
                                        if (is_array($variation_option)) {
                                            foreach ($variation_option as $option) {
                                                $var_option .= $option . ",";
                                            }
                                        }

                                        $sku = str_replace(' ', '_', $request->name) . $request['product_sku_' . str_replace('.', '_', $str)];


                                        $product_stock->variant = $str;

                                        $product_stock->variation_option = $var_option;

                                        $product_stock->price = !empty($request['product_sale_price_' . str_replace('.', '_', $str)]) ? $request['product_sale_price_' . str_replace('.', '_', $str)] : 0;

                                        $product_stock->variation_price = !empty($request['product_variation_price_' . str_replace('.', '_', $str)]) ? $request['product_variation_price_' . str_replace('.', '_', $str)] : 0;
                                        $product_stock->sku = $sku;

                                        $product_stock->stock_status = !empty($request['stock_status_' . str_replace('.', '_', $str)]) ? $request['stock_status_' . str_replace('.', '_', $str)] : '';
                                        if ($variation_option) {
                                            if (in_array('manage_stock', $variation_option)) {
                                                $product_stock->stock_order_status = !empty($request['stock_order_status_' . str_replace('.', '_', $str)]) ? $request['stock_order_status_' . str_replace('.', '_', $str)] : 0;

                                                $product_stock->stock = !empty($request['product_stock_' . str_replace('.', '_', $str)]) ? $request['product_stock_' . str_replace('.', '_', $str)] : 0;
                                                $product_stock->low_stock_threshold = !empty($request['low_stock_threshold_' . str_replace('.', '_', $str)]) ? $request['low_stock_threshold_' . str_replace('.', '_', $str)] : 0;
                                            } else {

                                                $product_stock->stock = 0;
                                                $product_stock->stock_order_status = '';
                                                $product_stock->low_stock_threshold = !empty($request['low_stock_threshold_' . str_replace('.', '_', $str)]) ? $request['low_stock_threshold_' . str_replace('.', '_', $str)] : 0;
                                            }
                                        }
                                        $product_stock->weight = !empty($request['product_weight_' . str_replace('.', '_', $str)]) ? $request['product_weight_' . str_replace('.', '_', $str)] : 0;

                                        $product_stock->description = !empty($request['product_description_' . str_replace('.', '_', $str)]) ? $request['product_description_' . str_replace('.', '_', $str)] : '';

                                        $product_stock->shipping = !empty($request['shipping_id_' . str_replace('.', '_', $str)]) ? $request['shipping_id_' . str_replace('.', '_', $str)] : 'same_as_parent';

                                        $product_stock->store_id = getCurrentStore();

                                        if (module_is_active('CartQuantityControl')) {
                                            \Workdo\CartQuantityControl\app\Models\CartQuantityControl::variantSaveData($request, $product_stock, $str);
                                        }

                                        $product_stock->save();

                                        if ($request->default_variant ==  '-' . $str) {
                                            $product_update = Product::find($product->id);
                                            $product_update->default_variant_id = $product_stock->id;
                                            $product_update->save();
                                        }
                                        if ($product_stock->stock == 'in_stock') {
                                            $is_in_stock = true;
                                        }
                                    }
                                }
                                if (!$is_in_stock) {
                                    $product->stock = 'out_of_stock';
                                }
                            } else {
                                $product->variant_product = 0;
                            }
                        } else {
                            $product->variant_product = 0;
                        }
                    } else {
                        $msg['flag'] = 'error';
                        $msg['msg'] =  __('Your Product limit is over, Please upgrade plan');

                        return $msg;
                    }
                }

                $msg['flag'] = 'success';
                $msg['msg'] =  __('Product saved successfully.');

                return $this->ssAppendRejectedImages($msg);   // F75
            } catch (\Exception $e) {
                \Log::info(['error' => $e]);
                $msg['flag'] = 'error';
                $msg['msg'] = $e->getMessage();
                return $msg;
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }





















    /**
     * Display the specified resource.
     */
    /**
     * P2-03-6. THE PRODUCT QUICK PREVIEW -- a read-only fragment for the side panel.
     *
     * THE SAME SHAPE AS `OrderController::order_preview`, DELIBERATELY. A second idiom for
     * "authorise a read of one record" is how two surfaces drift apart, and this codebase has
     * the scars: `show()`, `update()` and `destroy()` above all carry the SAME ownership block
     * for exactly that reason (P1-INV-01). This adds a fourth instance of it, not a fifth
     * approach.
     *
     * `Product` is NOT in `config/tenancy.php`'s `scoped_models` and registers no effective
     * global scope, so `Product::find()` reaches EVERY tenant's catalogue -- the predicate
     * below is the only control, and the S19 cross-tenant stock drain came from forgetting
     * exactly that. The store is derived from the authenticated principal, with the id passed
     * explicitly because the no-argument `getCurrentStore()` reads the MUTABLE default guard.
     *
     * THE ID IS RAW. `Crypt::encrypt()` is an encoding here, not a control: APP_KEY is in git
     * history and unrotated (owner decision D7), so an encrypted id in a uri is forgeable for
     * any id. A new surface shipping one would imply a protection that does not exist.
     *
     * JSON FOR A REFUSAL, A FRAGMENT FOR A HIT -- and 403/404, never 401. The shipped Flutter
     * app force-logs-out on any 401, and the rule is applied uniformly even on a web-only route.
     */
    public function product_preview(Request $request, $id)
    {
        $user = auth()->user();

        // OPERATOR FIRST, in the one expression. Laratrust has no super-admin bypass, so
        // `isAbleTo()` alone refuses the one principal who legitimately reads every store's
        // catalogue -- and reversing these two limbs is a lockout, not a tightening.
        $isOperator = $user !== null && \App\Policies\UserPolicy::isOperator($user);

        if ($user === null || (! $isOperator && ! $user->isAbleTo('Manage Products'))) {
            return response()->json(['message' => __('Permission denied.')], 403);
        }

        // `filter_var`, not `is_numeric`: is_numeric accepts '5.9' and rejects '5abc', while
        // MySQL coerces '5abc' to row 5 in a `WHERE id = ?` comparison.
        $productId = filter_var($id, FILTER_VALIDATE_INT);

        if ($productId === false || $productId <= 0) {
            return response()->json(['message' => __('Product not found.')], 404);
        }

        $product = Product::find($productId);

        if ($product === null) {
            return response()->json(['message' => __('Product not found.')], 404);
        }

        if (! $isOperator) {
            $currentStore = getCurrentStore($user->id);

            // Fail closed on either side being absent. A product with a NULL store_id is
            // invisible to every store predicate in the application and must not become
            // visible here.
            if (empty($currentStore) || empty($product->store_id)
                || (int) $product->store_id !== (int) $currentStore) {
                return response()->json(['message' => __('Product not found.')], 404);
            }
        }

        return response()->view('product.partials.preview', [
            'product' => $product,
            // The threshold that decides "low stock" comes from the SAME store whose row is
            // being shown, not from the caller's own settings -- the two differ for a staff
            // member, and P1-SEC-26 is the row that records why that matters.
            'outOfStockThreshold' => getAdminAllSetting(null, $product->store_id)['out_of_stock_threshold'] ?? 0,
        ]);
    }

    public function show(Product $product)
    {
        /*
         * OWNERSHIP ON THE BINDING. P1-INV-01 / S37-1.
         *
         * `show()` is reached with a route-model-bound `Product $product` and had no ownership
         * check at all, so another store's product rendered into this merchant's form -- name,
         * prices, cost, stock and variant rows. A read rather than a write, and the same binding
         * the write used. Same shape as `destroy()` and `update()`: platform operator exempt,
         * then `getCurrentStore()` against the product's own `store_id`.
         */
        $actor = auth()->user();
        /*
         * F160, same pair. `show()` carried the ownership check and no permission check, so a
         * colleague with no product right could read one of this store's products in full.
         *
         * `Manage Products` OPERATOR-FIRST, which is `product_preview()` (:1369) -- the other
         * single-product READ in this controller, and therefore the precedent rather than a choice
         * of mine. Operator-first and not `isAbleTo()` alone because, as that site's own comment
         * puts it, `isAbleTo()` alone refuses the one principal who legitimately reads every
         * store's catalogue, and reversing the two limbs is a lockout rather than a tightening.
         */
        $isOperatorActor = $actor !== null && \App\Policies\UserPolicy::isOperator($actor);

        if ($actor === null || (! $isOperatorActor && ! $actor->isAbleTo('Manage Products'))) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ($actor === null || $actor->type !== \App\Policies\UserPolicy::PLATFORM_ADMIN) {
            $currentStore = getCurrentStore();

            if (
                empty($currentStore)
                || empty($product->store_id)
                || (int) $product->store_id !== (int) $currentStore
            ) {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        }

        $store = getStoreById(getCurrentStore());
        $link = env('APP_URL') . ($store->slug ? ('/'.$store->slug) : '').'/product/';
        $Category = Category::where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Category', '');
        $Tax = Tax::where('store_id', getCurrentStore())->pluck('name', 'id');
        $Tax_status = Tax::Taxstatus();
        $Shipping = Shipping::where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Shipping', '');
        $preview_type = [
            'Video File' => 'Video File',
            'Video Url' => 'Video Url',
            'iFrame' => 'iFrame'
        ];
        $ProductAttribute = ProductAttribute::where('store_id', getCurrentStore())->pluck('name', 'id');
        $product_image = ProductImage::where('product_id', $product->id)->where('store_id', getCurrentStore())->get();
        $get_tax = explode(',', $product->tax_id);
        $get_datas = explode(',', $product->attribute_id);
        $tag = Tag::where('store_id', getCurrentStore())->pluck('name', 'id');
        $get_tags = explode(',', $product->tag_id);

        $brands = ProductBrand::where('status', 1)->where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Brand', '');
        $labels = ProductLabel::where('status', 1)->where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Label', '');

        $compact = ['link', 'product', 'Category', 'Tax', 'Tax_status', 'Shipping', 'preview_type', 'ProductAttribute', 'product_image', 'get_tax', 'get_datas', 'tag', 'get_tags', 'brands', 'labels'];
        return view('product.show', compact($compact));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Product $product)
    {
        /*
         * OWNERSHIP ON THE BINDING. P1-INV-01 / S37-1.
         *
         * `edit()` is reached with a route-model-bound `Product $product` and had no ownership
         * check at all, so another store's product rendered into this merchant's form -- name,
         * prices, cost, stock and variant rows. A read rather than a write, and the same binding
         * the write used. Same shape as `destroy()` and `update()`: platform operator exempt,
         * then `getCurrentStore()` against the product's own `store_id`.
         */
        $actor = auth()->user();
        /*
         * F160. THE BLOCK BELOW IS A TENANCY CHECK, NOT A PERMISSION CHECK, and until now this form
         * had only the first. P1-INV-01 closed "another store's product renders into this merchant's
         * form"; it left "a colleague with no product rights renders THIS store's product into the
         * form" -- name, prices, cost, stock and every variant row. Exactly the pair F157 found on
         * `CouponController::edit()`, which also had the tenant check and no permission check, for
         * the same reason: the submission was refused, so nobody noticed the form was not.
         *
         * `Edit Products` WITH NO OPERATOR BRANCH, because that is precisely what `update()` does
         * (:1541): there the permission is required of everyone and the operator exemption applies
         * only to the OWNERSHIP question below. Adding an operator bypass here would let the
         * operator open a form `update()` will refuse to accept.
         */
        if ($actor === null || ! $actor->isAbleTo('Edit Products')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ($actor === null || $actor->type !== \App\Policies\UserPolicy::PLATFORM_ADMIN) {
            $currentStore = getCurrentStore();

            if (
                empty($currentStore)
                || empty($product->store_id)
                || (int) $product->store_id !== (int) $currentStore
            ) {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        }

        $store = getStoreById(getCurrentStore());
        $link = env('APP_URL') . ($store->slug ? ('/'.$store->slug) : '').'/product/';
        $categories = Category::where('store_id', getCurrentStore())->get();
        $categoryTree = buildCategoryTree($categories);
        $Tax = Tax::where('store_id', getCurrentStore())->pluck('name', 'id');
        $Tax_status = Tax::Taxstatus();
        $Shipping = Shipping::where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Shipping', '');
        $preview_type = [
            'Video File' => 'Video File',
            'Video Url' => 'Video Url',
            'iFrame' => 'iFrame'
        ];
        $ProductAttribute = ProductAttribute::where('store_id', getCurrentStore())->pluck('name', 'id');
        $product_image = ProductImage::where('product_id', $product->id)->where('store_id', getCurrentStore())->get();
        $get_tax = explode(',', $product->tax_id);
        $get_datas = explode(',', $product->attribute_id);
        $tag = Tag::where('store_id', getCurrentStore())->pluck('name', 'id');
        $get_tags = explode(',', $product->tag_id);

        $brands = ProductBrand::where('status', 1)->where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Brand', '');
        $labels = ProductLabel::where('status', 1)->where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Select Label', '');
       
        $compact = ['link', 'product', 'categories', 'categoryTree', 'Tax', 'Tax_status', 'Shipping', 'preview_type', 'ProductAttribute', 'product_image', 'get_tax', 'get_datas', 'tag', 'get_tags', 'brands', 'labels'];
        return view('product.edit', compact($compact));
    }



























 public function update(Request $request, Product $product)
    {
       
        if (auth()->user() && auth()->user()->isAbleTo('Edit Products')) {
            $actor = auth()->user();

            /*
             * OWNERSHIP, BEFORE ANYTHING IS WRITTEN. P1-INV-01 / S37-1.
             *
             * `isAbleTo('Edit Products')` answers "may this user edit products" and never "may
             * this user edit THIS product". The target is a route-model-bound `Product $product`,
             * `App\Models\Product` is not in config/tenancy.php's `scoped_models` and registers
             * no effective global scope (CLAUDE.md), so the binding resolves across every tenant.
             *
             * REPRODUCED BY EXECUTION before this guard was written: a store-A merchant with
             * `Edit Products` on their own store sent `PUT /product/{store B id}` and renamed the
             * victim's product and set its `product_stock` 50 -> 7.
             *
             * The shape is `destroy()`'s, deliberately — same method, same order, same message —
             * rather than a second idiom for one question: `isAbleTo` first, the platform operator
             * exempt via UserPolicy::PLATFORM_ADMIN (matching OrderPolicy::allow), then
             * `getCurrentStore()` against the product's own `store_id`. An empty current store or
             * an unowned product is refused: `getCurrentStore()` returns null for a user whose
             * `current_store` is empty and who owns no store, and a null store must never read as
             * "matches".
             */
            if ($actor->type !== \App\Policies\UserPolicy::PLATFORM_ADMIN) {
                $currentStore = getCurrentStore();

                if (
                    empty($currentStore)
                    || empty($product->store_id)
                    || (int) $product->store_id !== (int) $currentStore
                ) {
                    return redirect()->back()->with('error', __('Permission denied.'));
                }
            }

            /*
             * P2-03-5. CONCURRENT-EDIT CONFLICT DETECTION, BEFORE ANYTHING IS WRITTEN.
             *
             * THE DEFECT IT CLOSES: two people editing the same product is a last-writer-wins
             * race with no signal. The second save silently discards the first person's work --
             * including a PRICE or a STOCK figure -- and both of them see a success message. On a
             * screen with a dozen fields, the loser usually never learns anything happened.
             *
             * `updated_at` IS THE TOKEN, which needs no migration and no new column. The edit
             * form emits the value it loaded; on save, a row whose `updated_at` has moved since
             * then is refused rather than overwritten. Workstream G's rule is to avoid schema
             * changes unless clearly necessary, and here one is not.
             *
             * COMPARED AS AN INSTANT, not as a string. `->eq()` on Carbon, after parsing, so a
             * difference in format or timezone rendering is not read as a conflict -- that
             * would refuse saves nobody is racing. A malformed or absent token is treated as
             * "not supplied" and the save proceeds: this is a data-loss guard, not an
             * authorization control, and failing closed on it would break every client that
             * posts the form without the field, including the API and the Flutter app.
             *
             * THE REFUSAL SPEAKS THE SHAPE THE PAGE READS. `resources/views/product/edit.blade.php`
             * submits this route with `$.ajax` in both of its branches and its `success` handler
             * reads `data.flag` and `data.msg` -- it never navigates. A `redirect()->back()`
             * here is followed by jQuery, so the handler would receive the edit page's own HTML,
             * find `data.flag` undefined, and call `show_toastr('Error', undefined)`: a BLANK
             * error toast, and the merchant is told nothing. `withInput()` would also be inert,
             * because nothing reloads to read the flash. So the refusal returns the same array
             * every other refusal in this method returns, which is the one the page understands.
             */
            $seenAt = $request->input('ss_last_seen_at');

            if (is_string($seenAt) && $seenAt !== '' && $product->updated_at !== null) {
                try {
                    $seen = \Carbon\Carbon::parse($seenAt);

                    if (! $product->updated_at->eq($seen)) {
                        return [
                            'flag' => 'error',
                            'msg' => __('Someone else saved this product while you were editing it. Your changes have not been saved. Reload the page to see the current version, then re-apply your changes.'),
                        ];
                    }
                } catch (\Throwable $e) {
                    // Unparseable token: treat as not supplied. See the note above.
                }
            }

            $dir        = 'uploads/' .getCurrentStore();
            if ($request->variant_product == 0) {
                $rules = [
                    'name' => 'required',
                    'category_id' => 'required',
                    'status' => 'required',
                    'variant_product' => 'required',
                    'brand_id' => 'nullable',
                    'label_id' => 'nullable',
                    /* F70. `product_weight` had NO rule, like the other five fields the
                     * P2-03-8 disclosure holds -- which is why a validation error could
                     * never open that section: nothing in it could fail. The column is
                     * nullable, so `nullable` keeps an empty weight valid exactly as
                     * before; what changes is that "abc" is now refused with a message
                     * instead of being stored verbatim in a column the reporting path
                     * reads as a number. */
                    'product_weight' => 'nullable|numeric|min:0',
                  'price' => 'required|numeric|min:0',
'sale_price' => 'nullable|numeric|min:0|lt:price',
'cost_price' => 'required|numeric|min:0|lte:price',                ];
            }else
            {
                $rules = [
                    'name' => 'required',
                    'category_id' => 'required',
                    'status' => 'required',
                    'variant_product' => 'required',
                    'brand_id' => 'nullable',
                    'label_id' => 'nullable',
                    /* F70. `product_weight` had NO rule, like the other five fields the
                     * P2-03-8 disclosure holds -- which is why a validation error could
                     * never open that section: nothing in it could fail. The column is
                     * nullable, so `nullable` keeps an empty weight valid exactly as
                     * before; what changes is that "abc" is now refused with a message
                     * instead of being stored verbatim in a column the reporting path
                     * reads as a number. */
                    'product_weight' => 'nullable|numeric|min:0',
                ];
            }

            $validator = \Validator::make($request->all(), $rules,[
                 'sale_price.lt' => __('The sale price must be less than the regular price.'),
    'cost_price.required' => __('Cost price is required.'),
    'cost_price.lte' => __('Cost price must not be greater than the regular price.'),
    'product_weight.numeric' => __('The weight must be a number.'),
    'product_weight.min' => __('The weight cannot be negative.')
            ], $this->ssProductFieldNames());
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                $msg['flag'] = 'error';
                $msg['msg'] =  $messages->first();
                $msg['errors'] = $messages->toArray();   // F70, see store() for the reasoning
                return $msg;
            }
            $request->sale_price = $request->sale_price ? $request->sale_price : 0;
            $totalImageSize = 0;
            if ($request->hasFile('cover_image')) {
                $totalImageSize += $request->file('cover_image')->getSize();
            }
            if ($request->hasFile('preview_video')) {
                $totalImageSize += $request->file('preview_video')->getSize();
            }
            if ($request->hasFile('downloadable_product')) {
                $totalImageSize += $request->file('downloadable_product')->getSize();
            }
            if (!empty($request->product_image)) {
                foreach ($request->product_image as $key => $image) {
                    $totalImageSize += File::size($image);
                }
            }
            $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $totalImageSize);
            if ($result != 1) {
                $msg['flag'] = 'error';
                $msg['msg'] = $result;

                return $msg;
            }

            if ($request->cover_image) {
                $file_path =  $product->cover_image_path;

                if ($result == 1) {
                    Utility::changeStorageLimit(\Auth::user()->creatorId(), $file_path);

                    $fileName = rand(10, 100) . '_' . time() . "_" . $request->cover_image->getClientOriginalName();
                    $path = Utility::upload_file($request, 'cover_image', $fileName, $dir, []);
                    if ($path['flag'] == 0) {
                        $msg['flag'] = 'error';
                        $msg['msg'] =  $path['msg'];

                        return $msg;
                    }
                    \App\Services\StoredUpload::delete($product->cover_image_path, \Auth::user()->creatorId());
                } else {
                    $msg['flag'] = 'error';
                    $msg['msg'] = $result;

                    return $msg;
                }
                $product->cover_image_path = $path['url'];
                $product->cover_image_url = $path['full_url'];
            }



            $product->name = $request->name;
            if (isset($request->slug) && !empty($request->slug)) {
                $product->slug = $request->slug;
            } else {
                $product->slug = Product::slugs($request->name);
            }
            $product->description = $request->description;
            $product->specification = $request->specification;
            $product->detail = $request->detail;
            // F61, the same class as F59. `products.stock_status` is `string NOT NULL
            // DEFAULT '0'` and is NOT in the validator, and product/edit.blade.php renders
            // the three radios with no `checked` fallback -- so a product created by a path
            // that does not post it (ImportExport, Shopify, Api\DashboardController) opens
            // with none selected, and a merchant who saves without clicking one posted
            // nothing and wrote NULL into the column. A column default does not rescue an
            // explicit NULL. Coalescing to the stored value cannot lose data: the radio
            // group has no empty option, so absent means "not posted", never "cleared".
            $product->stock_status = $request->stock_status ?? $product->stock_status;
            $product->product_weight = $request->product_weight;
            $tag_id = $request->tag;
            $product->category_id = $request->category_id;
            if ($request->brand_id) {
                $product->brand_id = $request->brand_id ?? null;
            }
            if ($request->label_id) {
                $product->label_id = $request->label_id ?? null;
            }

            $product->tax_status = $request->tax_status;

            if (!empty($request->tax_id)) {
                $product->tax_id = implode(',', $request->tax_id);
            } elseif(empty($request->tax_id)){
                $product->tax_id = null;
            }else {
                $tax = Tax::where('store_id', getCurrentStore())->first();
                if (isset($tax)) {
                    $product->tax_id = $tax->id;
                }
            }
            $product->preview_type = $request->preview_type;
            if (!empty($request->video_url)) {
                $product->preview_content = $request->video_url;
            }
            if (!empty($request->preview_video)) {
                $ext = $request->file('preview_video')->getClientOriginalExtension();
                $fileName = 'video_' . time() . rand() . '.' . $ext;

                $dir_video = 'uploads/' .getCurrentStore() . '/video';

                $file_paths = $product->preview_video;
                if ($result == 1) {
                    Utility::changeStorageLimit(\Auth::user()->creatorId(), $file_paths);
                    $path_video = Utility::upload_file($request, 'preview_video', $fileName, $dir_video, []);
                    if ($path_video['flag'] == 1) {
                        $url = $path_video['url'];
                    } else {
                        $msg['flag'] = 'error';
                        $msg['msg'] = $path_video['msg'];
                        return $msg;
                    }
                } else {
                    $msg['flag'] = 'error';
                    $msg['msg'] = $result;
                    return $msg;
                }



                $product->preview_content = $path_video['url'];
            }

            if (!empty($request->preview_iframe)) {
                $product->preview_content = $request->preview_iframe;
            }
            $product->variant_product = $request->variant_product;
            $product->shipping_id = $request->shipping_id;
            $product->status = $request->status;
            // F61, third of its kind. `products.trending` is `integer NOT NULL DEFAULT '0'`
            // and is in neither rule set. The edit form always posts it -- a hidden
            // `trending=0` sits ahead of the checkbox, in a card outside both
            // stock_management guards -- so the live path is covered by the VIEW and not by
            // this handler, which is exactly why it survived F59 and F61. A hand-made PUT
            // without the key wrote NULL. Coalesced to the stored value for the same reason
            // as stock_status: the control is a checkbox with a hidden partner, so an absent
            // key means "not posted", never "cleared".
            $product->trending = $request->trending ?? $product->trending;


            /*
             * `?? 0` / `!empty()`, taking the same SHAPE as store():309-316 -- not the same
             * bytes. The two still diverge on `low_stock_threshold`, which store() sets to ''
             * on both branches and this sets to 0; that divergence is pre-existing, and 0 is
             * the correct value for an `integer NOT NULL DEFAULT 0` column, so it is left as
             * it is rather than copied wrong for the sake of symmetry. The `?? 0` in the
             * `== 1` branch below is likewise store()'s shape and not a guard: if
             * `track_stock == 1` it cannot be null, so the coalesce never fires.
             *
             * `products.track_stock` is NOT NULL, and this assignment was unguarded on BOTH
             * branches while `store()` guards both. The `else` branch is reached precisely when
             * `track_stock` is not 1 -- which, on a store whose `stock_management` setting is off,
             * means the control is never rendered and the request carries NOTHING. So every update
             * on such a store wrote NULL into a NOT NULL column.
             *
             * MEASURED, end to end, by editing a product on a store with stock management off:
             *
             *     POST /product/32  ->  500
             *     SQLSTATE[23000]: NOT NULL constraint failed: products.track_stock
             *
             * and `update()` has no try/catch, so the merchant got a bare 500 rather than a
             * message. It is invisible on production today because `config/database.php:55` sets
             * `'strict' => false`, and MySQL's UPDATE -- which is what this path issues, on a
             * route-bound existing model -- then stores the column's implicit default, 0, for a
             * NULL into NOT NULL, with a warning. That is the same 0 this guard now writes
             * explicitly, so it changes no value production stores; it stops a crash on every
             * connection that does not coerce (SQLite here, and any strict-mode MySQL, which is
             * Laravel's own default).
             *
             * THAT IS TRUE OF UPDATE AND NOT OF INSERT. A single-row INSERT of NULL into a
             * NOT NULL column fails with ER_BAD_NULL_ERROR whatever the sql_mode, which is
             * presumably why store() was guarded from the start. Do not generalise the
             * sentence above into "this connection coerces NULLs".
             *
             * The value written where a real one EXISTS is untouched.
             */
            if ($request->track_stock == 1) {
                $product->track_stock = $request->track_stock ?? 0;
                $product->stock_order_status = $request->stock_order_status;
                $product->low_stock_threshold = !empty($request->low_stock_threshold) ? $request->low_stock_threshold : 0;
            } else {
                $product->track_stock = !empty($request->track_stock) ? $request->track_stock : 0;
                $product->stock_order_status = '';
                $product->low_stock_threshold = !empty($request->low_stock_threshold) ? $request->low_stock_threshold : 0;
            }

            if ($request->custom_field_status == '1') {
                $product->custom_field_status = '1';
                if (isset($request->custom_field_repeater_basic)) {
                    // Create a new array to hold the filtered data
                    $filteredCustomData = [];

                    // Iterate through the 'custom_field_repeater_basic' array
                    foreach ($request->custom_field_repeater_basic as $info) {
                        // Check for the 'custom_field' and 'custom_value' keys and if they are null
                        if (!(array_key_exists('custom_field', $info) && $info['custom_field'] === null && array_key_exists('custom_value', $info) && $info['custom_value'] === null)) {
                            // Add the info to the filtered data array
                            $filteredCustomData[] = $info;
                        }
                    }

                    if (count($filteredCustomData) > 0) {
                        $product->custom_field = json_encode($filteredCustomData);
                    } else {
                        $product->custom_field = null;
                    }

                } else {
                    $product->custom_field = null;
                    $product->custom_field_status = '0';
                }
            } else {
                $product->custom_field = NULL;
            }

            if (!empty($request->downloadable_product)) {
                $file_paths = $product->downloadable_product;

                if ($result == 1) {
                    Utility::changeStorageLimit(\Auth::user()->creatorId(), $file_paths);

                    $fileName = rand(10, 100) . '_' . time() . "_" . $request->downloadable_product->getClientOriginalName();
                    $path = Utility::upload_file($request, 'downloadable_product', $fileName, $dir, []);
                    if ($path['flag'] == 0) {
                        $msg['flag'] = 'error';
                        $msg['msg'] =  $path['msg'];

                        return $msg;
                    }
                    \App\Services\StoredUpload::delete($product->downloadable_product, \Auth::user()->creatorId());
                } else {

                    $msg['flag'] = 'error';
                    $msg['msg'] = $result;
                    return $msg;
                }
                $product->downloadable_product = $path['url'];
            }


            if (!empty($request->product_image)) {

                foreach ($request->product_image as $key => $image) {
                    $theme_image = $image;

                    if ($result == 1) {
                        $fileName = rand(10, 100) . '_' . time() . "_" . $image->getClientOriginalName();
                        $pathss = Utility::keyWiseUpload_file($request, 'product_image', $fileName, $dir, $key, []);
                        /* F75. A REJECTED GALLERY IMAGE USED TO VANISH IN SILENCE. This helper returns
                           `['flag' => 0, 'msg' => ...]` when its `mimes:`/`max:` rules refuse the file, and
                           those rules come from platform settings -- defaulting to `jpg,jpeg,png,csv,svg,pdf`
                           -- so a GIF, a WebP or a mislabelled file is refused. The COVER image path checks
                           that flag and returns an error; this loop asked only `isset($pathss['url'])` and
                           SKIPPED the refusal. MEASURED: two files queued, one row written, no error and no
                           toast. The product IS created by then, so the refusals are collected and reported
                           beside the success rather than aborting a save that has already happened. */
                        if (! isset($pathss['url'])) {
                            $this->ssRejectedImages[(string) $image->getClientOriginalName()] =
                                $pathss['msg'] ?? __('The file was not accepted.');
                        }
                    } else {
                        $msg['flag'] = 'error';
                        $msg['msg'] =  $result;

                        return $msg;
                    }

                    if (isset($pathss['url'])) {
                        $ProductImage = new ProductImage();
                        $ProductImage->product_id = $product->id;
                        $ProductImage->image_path = $pathss['url'];
                        $ProductImage->image_url  = $pathss['full_url'];
                        $ProductImage->store_id   = getCurrentStore();
                        $ProductImage->save();
                    }
                }
            }

            if ($request->variant_product == 0) {


                $product->price = $request->price;
                // F61. This was kept non-NULL only by `$request->sale_price = $request->sale_price
                // ? ... : 0;` higher up, which works because neither Illuminate's Request nor
                // Symfony's defines __set -- so it creates a dynamic property that later reads
                // find before __get, and on PHP 8.3 that is a deprecation. The guard belongs on
                // the line that writes the NOT NULL column, not three hundred lines away.
                $product->sale_price = $request->sale_price ?? 0;


                if ($request->track_stock == 0) {
                    $product->product_stock = 0;
                } else {
                    // F61, as above: `integer NOT NULL DEFAULT 0`. A browser posts '' here,
                    // which coerces, but a client that omits the key with track_stock=1
                    // wrote NULL.
                    $product->product_stock = $request->product_stock ?? 0;
                }


                $input = $request->all();

                $input['attribute_options'] = [];
                if ($request->has('attribute_no')) {
                    foreach ($request->attribute_no as $key => $no) {
                        $str = 'attribute_options_' . $no;
                        $enable_option = $input['visible_attribute_' . $no];
                        $variation_option = $input['for_variation_' . $no];

                        $item['attribute_id'] = $no;

                        $optionValues = [];
                        if (isset($request[$str])) {
                            foreach ($request[$str] as $fValue) {
                                $option = ProductAttributeOption::where('terms', $fValue)->first();
                                if ($option) {
                                    $optionValues[] = $option->id;
                                }
                            }
                        }

                        $item['values'] = explode(',', implode('|', $optionValues));
                        $item['visible_attribute_' . $no] = $enable_option;
                        $item['for_variation_' . $no] = $variation_option;
                        array_push($input['attribute_options'], $item);
                    }
                }

                if (!empty($request->attribute_no)) {
                    $input['product_attributes'] = implode(',', $request->attribute_no);
                } else {
                    $input['product_attributes'] = 0;
                }
                $input['attribute_options'] = json_encode($input['attribute_options']);
                $product->attribute_id = $input['product_attributes'];
                $product->product_attribute = $input['attribute_options'];
                $tag_data_id = [];
                $tag_ids = [];

                if (isset($request->tag_id)) {

                    foreach ($request->tag_id as $tag) {

                        $tags = Tag::where('id', $tag)->where('store_id', getCurrentStore())->first();

                        if (!empty($tags)) {
                            $tag_id = $tags->id;
                            $tag_ids[] = $tag_id;
                        } else {
                            $tag_id = 0;
                        }
                        if ($tag_id != $tag) {
                            $tag_data = new Tag();
                            $tag_data->name = $tag;
                            $tag_data->slug = Tag::slugs($tag);
                            $tag_data->store_id = getCurrentStore();
                            $tag_data->created_by = \Auth::user()->id;
                            $tag_data->save();

                            $tag_data_id[] = $tag_data->id;
                        }
                    }
                    $tag_product_id = array_merge($tag_data_id, $tag_ids);

                    if (!empty($tag_product_id)) {
                        $product->tag_id =  implode(',', $tag_product_id);
                    }
                }

                if (module_is_active('CartQuantityControl')) {
                    \Workdo\CartQuantityControl\app\Models\CartQuantityControl::saveData($product, $request->minimum_quantity, $request->maximum_quantity);
                }
                $product->save();
            } else {

                $input = $request->all();
                $input['choice_options'] = [];
                $input['attribute_options'] = [];
                if ($request->has('choice_no')) {
                    foreach ($request->choice_no as $key => $no) {
                        $str = 'choice_options_' . $no;

                        $item['attribute_id'] = $no;
                        $item['values'] = explode(',', implode('|', $request[$str]));
                        array_push($input['choice_options'], $item);
                    }
                }

                if (!empty($request->choice_no)) {
                    $input['attributes'] = json_encode($request->choice_no);
                } else {
                    $input['attributes'] = json_encode([]);
                }

                $input['choice_options'] = json_encode($input['choice_options']);
                $input['slug'] = $input['name'];

                if ($request->has('attribute_no')) {
                    foreach ($request->attribute_no as $key => $no) {
                        $str = 'attribute_options_' . $no;
                        $enable_option = $input['visible_attribute_' . $no];
                        $variation_option = $input['for_variation_' . $no];

                        $item['attribute_id'] = $no;
                        $optionValues = [];
                        if (isset($request[$str])) {
                            foreach ($request[$str] as $fValue) {
                                $option = ProductAttributeOption::where('terms', $fValue)->first();
                                if ($option) {
                                    $optionValues[] = $option->id;
                                }
                            }
                        }
                        $item['values'] = explode(',', implode('|', $optionValues));
                        $item['visible_attribute_' . $no] = $enable_option;
                        $item['for_variation_' . $no] = $variation_option;
                        array_push($input['attribute_options'], $item);
                    }
                }

                if (!empty($request->attribute_no)) {
                    $input['product_attributes'] = implode(',', $request->attribute_no);
                } else {
                    $input['product_attributes'] = json_encode([]);
                }
                $input['attribute_options'] = json_encode($input['attribute_options']);

                $product->price = 0;
                $product->product_stock = 0;
                $product->attribute_id = $input['product_attributes'] ?? null;
                $product->product_attribute = $input['attribute_options'];

                $product->preview_type = $request->preview_type;
                if (!empty($request->video_url)) {
                    $product->preview_content = $request->video_url;
                }
                if (!empty($request->preview_video)) {
                    $ext = $request->file('preview_video')->getClientOriginalExtension();
                    $fileName = 'video_' . time() . rand() . '.' . $ext;

                    $dir_video = 'uploads/' .getCurrentStore() . '/video';
                    $file_paths = $product->preview_video;
                    if ($result == 1) {
                        $path_video = Utility::upload_file($request, 'preview_video', $fileName, $dir_video, []);
                        if ($path_video['flag'] == 1) {
                            $url = $path_video['url'];
                        } else {
                            return redirect()->back()->with('error', __($path_video['msg']));
                        }
                    } else {
                        return redirect()->back()->with('error', $result);
                    }
                    $product->preview_content = $path_video['url'];
                }
                if (!empty($request->preview_iframe)) {
                    $product->preview_content = $request->preview_iframe;
                }
                $product->shipping_id = $request->shipping_id;
                if ($request->custom_field_status == '1') {
                    $product->custom_field = json_encode($request->custom_field_repeater_basic);
                } else {
                    $product->custom_field = NULL;
                }
                $product->save();

                $options = [];
                if ($request->has('choice_no')) {
                    foreach ($request->choice_no as $key => $no) {
                        $name = 'choice_options_' . $no;
                        $my_str = implode('|', $request[$name]);
                        array_push($options, explode(',', $my_str));
                    }
                }

                $sku_array = [];
                $total_stock = 0;
                $combinations = $this->combinations($options);
                if (count($combinations[0]) > 0) {
                    $product->variant_product = 1;
                    foreach ($combinations as $key => $combination) {
                        $str = '';
                        foreach ($combination as $key => $item) {
                            if ($key > 0) {
                                $str .= '-' . str_replace(' ', '', $item);
                            } else {
                                $str .= str_replace(' ', '', $item);
                            }
                        }

                        $product_stock = ProductVariant::where('product_id', $product->id)->where('variant', $str)->first();
                        if ($product_stock == null) {
                            $product_stock = new ProductVariant;
                            $product_stock->product_id = $product->id;
                        }
                        array_push($sku_array, $str);

                        $sku = str_replace(' ', '_', $request->name) . $request['sku_' . str_replace('.', '_', $str)];
                        $total_stock += $request['stock_' . str_replace('.', '_', $str)];
                        $product_stock->variant = $str;
                        $product_stock->price = $request['price_' . str_replace('.', '_', $str)];
                        $product_stock->sku = $sku;
                        $product_stock->stock = $request['stock_' . str_replace('.', '_', $str)];
                        
                        $product_stock->save();


                        if ($request->default_variant == '-' . $str) {
                            $product_update = Product::find($product->id);
                            $product_update->default_variant_id = $product_stock->id;
                            $product_update->save();
                        }
                    }
                    ProductVariant::where('product_id', $product->id)->whereNotIn('variant', $sku_array)->delete();
                    $product->product_stock = $total_stock;
                    $tag_data_id = [];
                    $tag_ids = [];
                    if (isset($request->tag_id)) {

                        foreach ($request->tag_id as $tag) {
                            $tags = Tag::where('id', $tag)->where('store_id', getCurrentStore())->first();
                            if (!empty($tags)) {
                                $tag_id = $tags->id;
                                $tag_ids[] = $tag_id;
                            } else {
                                $tag_id = 0;
                            }
                            if ($tag_id != $tag) {
                                $tag_data = new Tag();
                                $tag_data->name = $tag;
                                $tag_data->slug = Tag::slugs($tag);   // F62, as above
                                $tag_data->store_id = getCurrentStore();
                                $tag_data->created_by = \Auth::user()->id;
                                $tag_data->save();

                                $tag_data_id[] = $tag_data->id;
                            }
                        }
                    }
                    $tag_product_id = array_merge($tag_data_id, $tag_ids);
                    if (!empty($tag_product_id)) {

                        $product->tag_id =  implode(',', $tag_product_id);
                    }
                    $product->save();
                } else {
                    $product->variant_product = 0;
                }

                $attribute_option = [];
                if ($request->attribute_no) {
                    foreach ($request->attribute_no as $key => $no) {
                        $forVariationName = 'for_variation_' . $no;
                        if ($request->has($forVariationName) && $request->input($forVariationName) == 1) {
                            $name = 'attribute_options_' . $no;
                            $options_data = 'options_datas';
                            $for_variation = isset($request->{'for_variation_' . $no}) ? $request->{'for_variation_' . $no} : 0;
                            if ($for_variation == 1) {
                                if ($request->has($options_data) && is_array($request[$options_data])) {
                                    $my_str = $request[$options_data];
                                    $optionValues = [];

                                    foreach ($request[$options_data] as $term) {

                                        $optionValues[] = $term;
                                    }

                                    array_push($attribute_option, $my_str);
                                }
                            }
                        }
                    }
                }
                if ($attribute_option) {
                    if (count($attribute_option[0]) > 0) {
                        /*
                         * D-20. THE REMOVAL CHECK RUNS BEFORE ANY VARIANT IS WRITTEN, which is the
                         * owner's condition 6 ("no data loss on failure") taken literally: a
                         * refusal here leaves every variant row exactly as it was, rather than
                         * half-upserting the surviving rows and then refusing.
                         *
                         * The existing set is read HERE, before the upsert loop, and that
                         * placement is load-bearing. An earlier version of a different guard read
                         * it AFTER the loop, by which time every posted combination already had a
                         * row — so the posted set was always a subset of the existing one and half
                         * the comparison was dead code.
                         */
                        $ssPostedCombinations = [];

                        foreach ($attribute_option as $ssCom) {
                            foreach ((array) $ssCom as $ssItem) {
                                $ssPostedCombinations[] = (string) $ssItem;
                            }
                        }

                        $ssPostedCombinations = array_values(array_unique($ssPostedCombinations));

                        $ssRemovedVariants = ProductVariant::where('product_id', $product->id)
                            ->where('store_id', getCurrentStore())
                            ->get(['id', 'variant', 'sku', 'variation_price', 'price', 'stock'])
                            ->reject(function ($ssRow) use ($ssPostedCombinations) {
                                return in_array((string) $ssRow->variant, $ssPostedCombinations, true);
                            })
                            ->values();

                        $ssRemovalRefusal = $this->ssRefuseVariantRemoval($request, $product, $ssRemovedVariants);

                        if ($ssRemovalRefusal !== null) {
                            return $ssRemovalRefusal;
                        }

                        $product->variant_product = 1;
                        $is_in_stock = false;
                        foreach ($attribute_option as $key => $com) {
                            $str = '';
                            foreach ($com as $key => $item) {
                                $str = $item;

                                $product_stock = ProductVariant::where('product_id', $product->id)->where('variant', $str)->first();
                                $ssIsNewVariant = ($product_stock == null);
                                if ($ssIsNewVariant) {
                                    $product_stock = new ProductVariant;
                                    $product_stock->product_id = $product->id;
                                }

                                $theme_name = APP_THEME();
                                if ($request['downloadable_product_' . str_replace('.', '_', $str)]) {
                                    $fileName = rand(10, 100) . '_' . time() . "_" . $request->file('downloadable_product_' . $str)->getClientOriginalName();

                                    $path1 = Utility::upload_file($request, 'downloadable_product_' . $str, $fileName, $dir, []);
                                    if ($path1['flag'] == 0) {
                                        $msg['flag'] = 'error';
                                        $msg['msg'] =  $path1['msg'];
    
                                        return $msg;
                                    }
                                    $product_stock->downloadable_product = $path1['url'];
                                }


                                /* P2-03-4 / ledger F105, F82, F83 -- owner decision D-09 (A1).
                                   EVERY COLUMN BELOW USED TO BE WRITTEN THROUGH
                                   `!empty($request[k]) ? $request[k] : DEFAULT`, and that one
                                   idiom produced three separate defects.
                                   The product edit page renders the values panel ON LOAD, with no
                                   interaction, and the panel echoes a BLANK field for every column
                                   that is currently NULL. So an ordinary save -- a merchant
                                   changing a description and pressing Save -- re-posts a blank for
                                   each of them, `!empty('')` is false, and the column is written
                                   its default: NULL became 0 on prices, stock, weight and
                                   thresholds, and a NULL `stock_status` became ''. That is F105,
                                   measured on 12 variants the merchant had not touched. The same
                                   idiom makes a deliberate zero unstorable, because `!empty('0')`
                                   is false (F83), and it is why a price typed into this panel
                                   reported success and did not persist (F82).
                                   `ssVariantValue()` replaces it with the rule the owner set: an
                                   absent or blank key leaves an EXISTING column alone and only
                                   takes the default on a row being created, and a posted "0" is
                                   written as 0. */
                                $ssKey = str_replace('.', '_', $str);
                                $product_stock->variant = $str;

                                /* `variation_option` is four checkboxes, so when none is checked
                                   the key is absent -- which previously wrote "" over whatever was
                                   stored. */
                                $ssVariationOption = $this->ssVariantValue($request, 'variation_option_' . $ssKey, null, '', $ssIsNewVariant);
                                if (is_array($ssVariationOption)) {
                                    $var_option = "";
                                    foreach ($ssVariationOption as $option) {
                                        $var_option .= $option . ",";
                                    }
                                    $product_stock->variation_option = $var_option;
                                } elseif ($ssIsNewVariant) {
                                    $product_stock->variation_option = '';
                                }

                                /* `track_stock` is read off the model but `product_variants` has no
                                   such column (ledger F107), so this is always the else branch.
                                   Left standing rather than removed: it is the shape the WorkDo
                                   writer has, and deleting a dead branch is not this slice. */
                                if ($product_stock->track_stock == 1) {
                                    $product_stock->stock_status = '';
                                } else {
                                    /* The panel renders this select only when the row's
                                       stock_status is non-NULL, so a NULL row posts no key at all;
                                       and a blank IS a value here, the select's "Select option". */
                                    $product_stock->stock_status = $this->ssVariantValue($request, 'stock_status_' . $ssKey, $product_stock->stock_status, '', $ssIsNewVariant, true);
                                }

                                $product_stock->price = $this->ssVariantValue($request, 'product_sale_price_' . $ssKey, $product_stock->price, 0, $ssIsNewVariant);
                                $product_stock->variation_price = $this->ssVariantValue($request, 'product_variation_price_' . $ssKey, $product_stock->variation_price, 0, $ssIsNewVariant);
                                $product_stock->stock = $this->ssVariantValue($request, 'product_stock_' . $ssKey, $product_stock->stock, 0, $ssIsNewVariant);
                                $product_stock->low_stock_threshold = $this->ssVariantValue($request, 'low_stock_threshold_' . $ssKey, $product_stock->low_stock_threshold, 0, $ssIsNewVariant);
                                $product_stock->weight = $this->ssVariantValue($request, 'product_weight_' . $ssKey, $product_stock->weight, 0, $ssIsNewVariant);
                                $product_stock->stock_order_status = $this->ssVariantValue($request, 'stock_order_status_' . $ssKey, $product_stock->stock_order_status, 0, $ssIsNewVariant);

                                /* A description is text a merchant may legitimately clear, so a
                                   posted blank IS the new value here. */
                                $product_stock->description = $this->ssVariantValue($request, 'product_description_' . $ssKey, $product_stock->description, '', $ssIsNewVariant, true);

                                /* The shipping select's first entry is the prepended '' meaning
                                   "Same as parent", so a blank is a real choice here too -- it just
                                   stores as the literal the rest of the application reads. */
                                $ssShipping = $this->ssVariantValue($request, 'shipping_id_' . $ssKey, $product_stock->shipping, 'same_as_parent', $ssIsNewVariant, true);
                                $product_stock->shipping = ($ssShipping === '' || $ssShipping === null) ? 'same_as_parent' : $ssShipping;

                                /* THE SKU IS THE ONE COLUMN THIS WRITER CHANGED ON EVERY SINGLE
                                   SAVE, whatever the panel echoed. It is built from the PRODUCT
                                   NAME plus the posted token, and the on-load panel posts
                                   `'-' . $variant` as that token -- so a merchant's hand-typed
                                   `SKU-001` was silently replaced by `Scarf-S-Red` the first time
                                   they pressed Save for any reason at all. That also makes it the
                                   detector the tests assert on, because it is the only column that
                                   moves on a rebuild regardless of what was echoed back.
                                   An existing row now keeps its SKU unless the merchant actually
                                   typed one, i.e. unless the posted token differs from the panel's
                                   own echo. */
                                $ssPostedSku = $request->input('product_sku_' . $ssKey);
                                if ($ssIsNewVariant) {
                                    $product_stock->sku = str_replace(' ', '_', $request->name) . $ssPostedSku;
                                } elseif ($request->has('product_sku_' . $ssKey)
                                    && trim((string) $ssPostedSku) !== ''
                                    && (string) $ssPostedSku !== '-' . $str) {
                                    $product_stock->sku = str_replace(' ', '_', $request->name) . $ssPostedSku;
                                }

                                $product_stock->store_id = getCurrentStore();

                                if (module_is_active('CartQuantityControl')) {
                                    \Workdo\CartQuantityControl\app\Models\CartQuantityControl::variantSaveData($request, $product_stock, $str);
                                }

                                $product_stock->save();

                                if ($request->default_variant ==  '-' . $str) {
                                    $product_update = Product::find($product->id);
                                    $product_update->default_variant_id = $product_stock->id;
                                    $product_update->save();
                                }

                                if ($product_stock->stock == 'in_stock') {
                                    $is_in_stock = true;
                                }
                            }
                            /* P2-03-4 / ledger F105, F113 -- owner decision D-09.
                               THIS DELETE IS UNCHANGED, AND THE TWO GUARDS THAT WERE TRIED HERE
                               WERE BOTH REMOVED BECAUSE REVIEW PROVED THEM WORSE THAN NOTHING.
                               They are recorded rather than silently dropped, because both look
                               obviously right and the second looked like plain hygiene.

                               A SET-DIFFERENCE GATE IN FRONT OF THIS DELETE CANNOT CHANGE ITS
                               OUTCOME. `whereNotIn('variant', $com)` already removes exactly the
                               rows in `array_diff(existing, posted)`, so "the posted set differs
                               from the stored one" is only a restatement of "this delete has
                               something to delete". The version that shipped first also read the
                               existing set AFTER the upsert loop, which made the other half of
                               the comparison dead code as well. A guard that cannot change an
                               outcome is untestable by outcomes and reads as a protection that
                               is not there -- that is the defect, not the wasted query.

                               `->where('store_id', getCurrentStore())` ON THE DELETE IS A
                               REGRESSION, not hardening. It buys nothing: the delete is already
                               confined by `product_id`, and the product has passed the
                               operator-first ownership check at the top of `update()`, so no
                               caller can reach another store's variants through it. What it
                               COSTS is real. `getCurrentStore()` returns the literal 1 for a
                               platform admin with no `current_store` (helper.php:636), so an
                               operator editing a merchant's product would match no row and the
                               cleanup would silently stop -- leaving the removed combinations
                               live and purchasable on that merchant's storefront, because
                               `HomeController`'s price range and the cart's variant check read
                               them without a store filter. It would also spare any row whose
                               `store_id` is NULL, which `product_variants` permits and which the
                               `choice_no` path above creates.

                               WHAT ACTUALLY PROTECTS AN ORDINARY SAVE is therefore not here. It
                               is (a) the writer above, which no longer overwrites a column the
                               merchant did not send, (b) the values panel being built FROM the
                               variant rows, so an ordinary save posts the set that already
                               exists and this delete matches nothing, and (c) the existing
                               `ss_last_seen_at` conflict guard for the stale-form race.
                               WHAT A DELIBERATE NARROWING DOES IS NOW DECIDED (D-20) AND IS
                               ENFORCED ABOVE, NOT HERE. `ssRefuseVariantRemoval()` runs BEFORE the
                               upsert loop and returns early, so by the time this delete is
                               reached either nothing is uncovered or the merchant has explicitly
                               confirmed the removal and no checkable reference blocks it. This
                               statement therefore deletes only what the merchant agreed to lose.
                               Keeping the check up there rather than wrapping this line is the
                               owner's "no data loss on failure": a refusal must leave every row
                               untouched, not half-upsert the survivors first. */
                            ProductVariant::where('product_id', $product->id)
                                ->whereNotIn('variant', $com)
                                ->delete();
                        }
                        if (!$is_in_stock) {
                            $product->stock = 'out_of_stock';
                        }
                    } else {
                        $product->variant_product = 0;
                    }
                }
            }


            $firebase_enabled = Utility::GetValueByName('firebase_enabled');
            if (!empty($firebase_enabled) && $firebase_enabled == 'on') {
                $fcm_Key = Utility::GetValueByName('fcm_Key');
                if (!empty($fcm_Key)) {
                    $NotifyUsers = NotifyUser::where('product_id', $product->id)->get();
                    if (!empty($NotifyUsers)) {
                        foreach ($NotifyUsers as $key => $value) {
                            $User_data = User::find($value->user_id);
                            if (!empty($User_data->firebase_token)) {
                                $device_id = $User_data->firebase_token;
                                $message = 'now ' . $product->name . ' is available in stock';
                                Utility::sendFCM($device_id, $fcm_Key, $message);
                                NotifyUser::where('product_id', $product->id)->where('user_id', $User_data->id)->delete();
                            }
                        }
                    }
                }
            }
            if (module_is_active('SizeGuideline')) {
                \Workdo\SizeGuideline\app\Models\SizeGuideline::saveUpdateData($product, $request->size_chart_title,$request->size_chart_information,$request->all());
            }
            if (module_is_active('WholesaleProduct')) {
                \Workdo\WholesaleProduct\app\Models\WholesaleProduct::saveUpdateData($product, $request->wholesale_repeater_basic);
            }
            if (module_is_active('ProductBarCode')) {
                \Workdo\ProductBarCode\app\Models\ProductBarCode::saveData($product);
            }
            if (module_is_active('PartialPayments')) {
                \Workdo\PartialPayments\app\Models\PartialPayments::saveData($product,$request->all());
            }
            if (module_is_active('AdditionalCustomFields')) {
                $settingController = new \Workdo\AdditionalCustomFields\app\Http\Controllers\SettingController();
                $settingController->saveData($product, $request->all());
            }
            
            $msg['flag'] = 'success';
            $msg['msg'] =__('Product update successfully.');

            return $this->ssAppendRejectedImages($msg);   // F75
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    /**
     * R4-04 cross-tenant IDOR exemplar.
     *
     * The permission check here was already correct -- 'Delete Products' IS
     * created by Utility::addNewData() (verified, double-quoted at Utility.php
     * :2680, which is why a single-quoted grep misses it) -- but it was the ONLY
     * check. The parameter is route-model bound, so Laravel resolved any product
     * id in the table, and holding 'Delete Products' in your own store was
     * enough to destroy a product in someone else's.
     *
     * That made it worse than a database-only IDOR: before any row is deleted
     * this method unlinks files from disk (File::delete on each ProductImage
     * image_path, the cover image, the downloadable product and a video
     * preview), so a cross-tenant call destroyed another merchant's uploaded
     * media irreversibly, and called Utility::changeproductStorageLimit against
     * the CALLER's creatorId(), mis-accounting the victim's storage against the
     * attacker.
     *
     * The ownership check is therefore placed before any deletion or unlink.
     */
    public function destroy(Product $product)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Delete Products')) {
            $actor = auth()->user();

            // Ownership, before anything is removed from the database or disk.
            // A super admin is exempt, matching OrderPolicy::allow.
            if ($actor->type !== \App\Policies\UserPolicy::PLATFORM_ADMIN) {
                $currentStore = getCurrentStore();

                if (
                    empty($currentStore)
                    || empty($product->store_id)
                    || (int) $product->store_id !== (int) $currentStore
                ) {
                    return redirect()->back()->with('error', __('Permission denied.'));
                }
            }

            /*
             * P1-INV-25. MOVED to App\Services\ProductMediaReclaimer, same calls in the same
             * order, because the two store-wide wipes need this block and may NOT hold the
             * `Delete Products` permission this method is gated on -- so they were delegating
             * their media cleanup to a handler that silently refused them. The gate above is
             * unchanged: this handler keeps it, and the wipes, which have already proved the
             * caller owns the store, call the service directly.
             */
            \App\Services\ProductMediaReclaimer::reclaim($product, \Auth::user()->creatorId());



            if (module_is_active('HubSpot')) {
                $setting = getAdminAllSetting(auth()->user()->id, getCurrentStore());
                if (isset($setting['hubspot_is_enable']) && $setting['hubspot_is_enable'] == 'on') {
                    /*
                     * `$product`, NOT `$Product`. The extraction above removed the
                     * `$Product = Product::find($product->id)` this line was reading, so for one
                     * revision it was an UNDEFINED VARIABLE -- a PHP 8 warning, `->id` on null,
                     * and a delete that silently matched nothing. Introduced by this package and
                     * caught by its review; the route-bound `$product` is the same row without
                     * the redundant re-read.
                     */
                    \Workdo\HubSpot\app\Models\HubSpotProductService::where('product_service_id', $product->id)->delete();
                }
            }
            ProductVariant::where('product_id', $product->id)->delete();

            Cart::where('product_id', $product->id)->delete();

            $Product = Product::find($product->id);
            if (!empty($Product)) {
                // image remove from description json
                $description_json = $Product->other_description_api;
                if (!empty($description_json)) {
                    $description_json = json_decode($Product->other_description_api, true);
                    foreach ($description_json['product-other-description'] as $key => $value) {
                        if ($value['field_type'] == 'photo upload') {
                            \App\Services\StoredUpload::delete($value['value'], \Auth::user()->creatorId());
                        }
                    }
                }

                // image remove from cover image
                \App\Services\StoredUpload::delete($Product->cover_image_path, \Auth::user()->creatorId());

                WoocommerceConection::where('module', 'product')->where('original_id', $product->id)->delete();

                ShopifyConection::where('module', 'product')->where('original_id', $product->id)->delete();

                Product::where('id', $product->id)->delete();
            }
            return redirect()->back()->with('success', __('Product delete successfully.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function get_slug(Request $request)
    {
        $result = Product::slugs($request->value);
        return response()->json(['result' => $result]);
    }

    public function attribute_option(Request $request)
    {
        $Attribute_option = ProductAttributeOption::where('attribute_id', $request->attribute_id)->where('store_id', getCurrentStore())
            ->get()->pluck('terms', 'id')->toArray();

        return response()->json($Attribute_option);
    }

    public function attribute_combination(Request $request)
    {

        /*
         * THE TENANT, RESOLVED ONCE. P1-INV-10 (c).
         *
         * The option lookup below read `ProductAttributeOption::where('id', $id)->first()` with
         * no store predicate at all, while its own sibling `attribute_option()` three methods up
         * (:1841) and `ProductAttributeOptionController::ownedOption()` (:54-61) both carry one --
         * so a merchant who posted another tenant's option ids got that tenant's `terms` echoed
         * back into the combination table. `ProductAttributeOption` registers no StoreScope and
         * is not in config/tenancy.php's `scoped_models`, so nothing else narrowed it.
         *
         * Not a privilege escalation and not a write: the leak is the option TERM strings ("Red",
         * "XL") of another store's attributes. It is recorded as the read it is.
         *
         * AN EMPTY CURRENT STORE CONTRIBUTES NOTHING, and the explicit check is not redundant.
         * Laravel's `where($column, null)` becomes `whereNull`, so `where('store_id', null)`
         * would match every STORE-LESS option row rather than nothing -- a fail-OPEN edge that
         * `ProductAttributeOptionController::ownedOption()` already guards against for this same
         * model, with a docblock that happens to misstate the mechanism ("would match nothing")
         * while getting the guard right. This copies the guard, not the explanation.
         */
        $currentStore = getCurrentStore();

        $options = array();
        $unit_price = !empty($request->price) ? $request->price : 0;
        $product_name = !empty($request->sku) ? $request->sku : '';
        $stock = !empty($request->product_stock) ? $request->product_stock : 0;
        $input = $request->all();

        foreach ($request->attribute_no as $key => $no) {
            $forVariationName = 'for_variation_' . $no;
            $for_variation = isset($request->{'for_variation_' . $no}) ? $request->{'for_variation_' . $no} : 0;

            if ($request->has($forVariationName) && $request->input($forVariationName) == 1) {
                $name = 'attribute_options_' . $no;
                $value = 'options_' . $no;
                if ($for_variation == 1) {
                    if ($request->has($name) && is_array($request[$name])) {
                        $my_str = $request[$name];
                        $optionValues = [];

                        foreach ($request[$name] as $id) {
                            $option = empty($currentStore)
                                ? null
                                : ProductAttributeOption::where('id', $id)
                                    ->where('store_id', $currentStore)
                                    ->first();

                            if ($option) {
                                $optionValues[] = $option->terms;
                            }
                        }

                        /*
                         * AN ATTRIBUTE THAT RESOLVED NO OPTIONS CONTRIBUTES NOTHING, rather than
                         * contributing an empty group. P1-INV-10 (c), found by the (c) negative
                         * test and PRE-EXISTING -- the store predicate above only made it
                         * reachable with a foreign id.
                         *
                         * `array_push($options, [])` put an empty group into the cartesian
                         * product, and `combination([[]])` returns `[]`, so
                         * attribute_combinations.blade.php:1 -- `count($combinations[0]) > 0` --
                         * raised `Undefined array key 0`: a ViewException, a 500. It fired for
                         * ANY option id that did not resolve (a deleted option would do it) long
                         * before this task; narrowing the lookup to the caller's store would have
                         * turned a cross-tenant leak into a cross-tenant 500, which is not a
                         * clean refusal.
                         *
                         * With the group omitted, `$options` stays empty, `combination([])`
                         * returns `[[]]`, `$combinations[0]` is `[]` and the view's own guard
                         * renders nothing -- a 200 with an empty combination table, which is what
                         * "these options are not yours" should look like. A legitimate post that
                         * mixes one attribute with options and one without now builds the
                         * combinations of the first instead of 500ing on both.
                         */
                        if ($optionValues !== []) {
                            array_push($options, $optionValues);
                        }
                    }
                }
            }
        }

        $combinations = $this->combination($options);

        $Shipping = Shipping::where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Same as Parent', '');

        return view('product.attribute_combinations', compact('combinations', 'input', 'unit_price', 'product_name', 'stock', 'Shipping'));
    }

    public function sku_combination(Request $request)
    {
        $options = array();
        $unit_price = !empty($request->price) ? $request->price : 0;
        $product_name = !empty($request->sku) ? $request->sku : '';
        $stock = !empty($request->product_stock) ? $request->product_stock : 0;

        if ($request->has('choice_no')) {
            foreach ($request->choice_no as $key => $no) {
                $name = 'choice_options_' . $no;
                $my_str = implode('', $request[$name]);
                array_push($options, explode(',', $my_str));
            }
        }
        $combinations = $this->combinations($options);
        return view('product.sku_combinations', compact('combinations', 'unit_price', 'product_name', 'stock'));
    }

    public function attribute_combination_data(Request $request)
    {
        $product_stock = ProductVariant::where('product_id', $request->id)->where('store_id', getCurrentStore())
            ->get();
        $Shipping = Shipping::where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Same as parent', '');
        return view('product.attribute_combinations_data', compact('product_stock', 'Shipping'));
    }

    public function combinations($arrays)
    {

        $result = array(array());
        foreach ($arrays as $property => $property_values) {
            $tmp = array();
            foreach ($result as $result_item) {
                foreach ($property_values as $property_value) {
                    $tmp[] = array_merge($result_item, array($property => $property_value));
                }
            }
            $result = $tmp;
        }
        return $result;
    }

    public function combination($arrays)
    {
        $result = array(array());
        foreach ($arrays as $property => $property_values) {
            $tmp = array();
            foreach ($result as $result_item) {
                foreach ($property_values as $property_value) {
                    $tmp[] = array_merge($result_item, array($property => $property_value));
                }
            }
            $result = $tmp;
        }
        return $result;
    }

    /**
     * Is this product outside the store the signed-in user is working in?
     *
     * P1-INV-07. The ownership decision `update()`, `edit()`, `show()` and `destroy()` each make
     * inline, extracted once for the three sites this task guards rather than copied a fifth,
     * sixth and seventh time. The four existing copies are left alone deliberately: changing them
     * would be a refactor of working, removal-checked code, and this task is a security fix.
     *
     * A MISSING PRODUCT IS OUTSIDE. `ProductImage` and `ProductVariant` both carry a nullable
     * `store_id` of their own, and neither is in config/tenancy.php's `scoped_models`, so the
     * authoritative tie is through the PRODUCT -- the same reasoning as S35/S36: scoping the
     * product does not scope the variant, and the variant's own column cannot be trusted to carry
     * the answer. If the product cannot be resolved there is nothing to own, and refusing is the
     * fail-closed direction.
     *
     * THE PLATFORM OPERATOR IS EXEMPT, matching `destroy()` and `OrderPolicy::allow`. Note that in
     * methods wrapped in `isAbleTo(...)` that exemption cannot fire at all -- Laratrust has no
     * super-admin bypass (CLAUDE.md), measured in
     * tests/Support/Security/bin/verify-product-ownership-on-mysql.php. These three methods have
     * no permission wrapper, so here it is live.
     */
    /**
     * Does the signed-in user hold NONE of these product permissions?
     *
     * P1-INV-10 (e). `file_delete()`, `attribute_combination_edit()` and
     * `product_attribute_delete()` called `isAbleTo` NOWHERE -- zero occurrences in all three
     * method bodies, recorded by MerchantProductOwnershipTest's own docblock. P1-INV-07 gave
     * those three OWNERSHIP, which answers *whose row is this*, and never *may this user touch
     * product media and variants at all*: any authenticated user of any role reached all three.
     * Extracted once for exactly the three methods `productIsOutsideCurrentStore()` already
     * serves, rather than copied a fourth, fifth and sixth time.
     *
     * THE OPERATOR LIMB COMES FIRST, and that ordering is the whole point. Laratrust runs with
     * `permissions_as_gates => false` and there is NO super-admin bypass (CLAUDE.md), so
     * `isAbleTo()` alone REFUSES the platform operator -- an `isAbleTo`-only limb here would be a
     * lockout, not a tightening. `App\Http\Middleware\FinancePermission` records that happening
     * once already, for six unseeded finance names. `update()` (:1014) and `destroy()` (:1751)
     * are wrapped `isAbleTo`-FIRST and therefore do exclude the operator; that is pre-existing
     * and measured in tests/Support/Security/bin/verify-product-ownership-on-mysql.php, and this
     * task does not change it.
     *
     * The comparison is normalised rather than the bare `===` one method below, matching
     * `Api\DashboardController::resolveOwnedStore()` and `PlatformOperatorOnly::handle()`: an
     * operator row whose `type` is `Super Admin` or carries whitespace must not be locked out by
     * a string-equality accident.
     *
     * ANY of the named permissions suffices, and only one caller passes more than one.
     * `attribute_combination_edit()` RENDERS -- it returns a view and writes nothing -- and it is
     * reached from the read-only show screen as well as the edit screen
     * (resources/views/product/show.blade.php:730 calls `update_attribute()`, which PUTs to it).
     * Neither screen carries a permission wrapper of its own: `show()` (:881) and `edit()` (:934)
     * call `isAbleTo` nowhere, so there is no screen permission to inherit. Gating that render on
     * `Edit Products` alone would take the variations panel away from a staff role that holds
     * `Manage Products` for the catalogue list and nothing more. The destructive endpoints BEHIND
     * the render are gated on `Edit Products` on their own, so render-on-Manage /
     * mutate-on-Edit gives up no control.
     *
     * Both names are seeded: `Utility::addNewData()` creates `Manage Products` and
     * `Edit Products` and grants both to the `admin` role (:2679/:2681) and to the `super admin`
     * role (:3008/:3010), so a DEFAULT merchant is unaffected in either direction. A permission
     * no row exists for can be held by no role and would make this a permanent 403 -- that is why
     * the seeding was checked before the limb was written, not after.
     *
     * @param list<string> $permissions
     */
    private function lacksProductPermission(array $permissions): bool
    {
        $actor = auth()->user();

        if ($actor === null) {
            return true;
        }

        if (strtolower(trim((string) $actor->type)) === \App\Policies\UserPolicy::PLATFORM_ADMIN) {
            return false;
        }

        foreach ($permissions as $permission) {
            if ($actor->isAbleTo($permission)) {
                return false;
            }
        }

        return true;
    }

    private function productIsOutsideCurrentStore($product): bool
    {
        $actor = auth()->user();

        if ($actor !== null && $actor->type === \App\Policies\UserPolicy::PLATFORM_ADMIN) {
            return false;
        }

        $currentStore = getCurrentStore();

        return empty($product)
            || empty($currentStore)
            || empty($product->store_id)
            || (int) $product->store_id !== (int) $currentStore;
    }

    public function file_delete($id)
    {
        /*
         * PERMISSION, BEFORE THE ROW IS EVEN LOOKED UP. P1-INV-10 (e).
         *
         * `Edit Products` alone: this unlinks media from an EXISTING product, and the only live
         * caller is the edit screen. create.blade.php:650 binds `.deleteRecord` with a direct
         * (non-delegated) handler and that template renders no `.deleteRecord` element -- the
         * thumbnail+delete block lives only in edit.blade.php:351-365 -- so the create-screen
         * binding matches nothing and a `Create Product`-only role loses nothing here.
         *
         * Before the id is resolved, so a caller without the permission cannot learn from a 404
         * whether an image id exists.
         */
        if ($this->lacksProductPermission(['Edit Products'])) {
            return response()->json([
                'flag' => 'error',
                'msg' => __('Permission denied.'),
            ], 403);
        }

        $product_img_id = ProductImage::find($id);
        if ($product_img_id) {
            /*
             * OWNERSHIP BEFORE ANYTHING LEAVES THE DISK. P1-INV-07.
             *
             * This method had NO permission check and NO ownership check, and it unlinks a file:
             * `ProductImage::find($id)` took any id, and `File::delete(base_path(...))` removed
             * another store's uploaded media IRREVERSIBLY. Reported live by an adversarial review
             * of P1-INV-01, which asked whether that fix had guarded one door and left another
             * open. It had.
             *
             * The tie is through the product, not through `product_images.store_id`: that column
             * is nullable and the model is not in `scoped_models`, so it cannot be trusted to
             * carry the answer.
             */
            $owner = Product::find($product_img_id->product_id);

            if ($this->productIsOutsideCurrentStore($owner)) {
                \Illuminate\Support\Facades\Log::warning(
                    'product image delete refused: the image belongs to another store',
                    [
                        'image_id' => $product_img_id->id,
                        'product_id' => $product_img_id->product_id,
                        'product_store_id' => $owner->store_id ?? null,
                        'current_store' => getCurrentStore(),
                        'user_id' => optional(auth()->user())->id,
                    ]
                );

                /*
                 * 403, not a 200 with an error body. The caller
                 * (resources/views/product/edit.blade.php:851-856) hides the thumbnail inside its
                 * `success` handler REGARDLESS of the payload, so a 200 would tell the user the
                 * image was deleted when it was not. No 401 is introduced -- that is the code the
                 * shipped Flutter app force-logs-out on, and this is a web route besides.
                 */
                return response()->json([
                    'flag' => 'error',
                    'msg' => __('Permission denied.'),
                ], 403);
            }

            /*
             * AND THE PATH GOES THROUGH THE ONE SHARED DELETER. `image_path` is a database string
             * handed to the filesystem; ownership says WHOSE row this is, not WHERE the string
             * points.
             *
             * P1-INV-09 acceptance criterion 1: a single shared containment helper is the only
             * thing that authorises an unlink of a stored media path. This site used to carry its
             * OWN containment -- a private `isInsideUploadsRoot()` plus `File::delete(realpath())`
             * -- and `StoredFilePathSafetyTest`'s census listed it as the one exemption, with the
             * reason that it is "confined to uploads/ and to a row whose product the caller owns,
             * but WITHOUT StoredUpload's owner-folder rule". That is the whole of the gap: two
             * implementations of one control, and the weaker of them leaning on the WRITE-side
             * policy to supply the property it lacks. One layer standing in for another is what
             * this project's own rule refuses -- never let one check be the only one -- so the
             * second implementation is gone and this path now gets rule 3, the owner-folder
             * check, as well.
             *
             * `creatorId()` is the right owner to ask with: the caller has already been proved to
             * own the product above, `creatorId()` resolves a staff user to their merchant, and
             * StoredUpload exempts the platform operator by `users.type`. Nothing is CHARGED
             * here, so this is not the "which user is debited" question P1-INV-25 answered
             * differently.
             *
             * A path that does not resolve, resolves outside uploads/, or names another tenant's
             * folder is not unlinked -- and the row is still removed, which is the user's actual
             * intent and what P1-INV-07 established: a bad stored value cannot make an image
             * undeletable.
             */
            if (! \App\Services\StoredUpload::delete((string) $product_img_id->image_path, \Auth::user()->creatorId())
                && ! empty($product_img_id->image_path)) {
                \Illuminate\Support\Facades\Log::warning(
                    'product image row deleted but the file was not unlinked: StoredUpload refused'
                    . ' the stored path -- it does not resolve, is outside uploads/, or names'
                    . " another tenant's folder",

                    [
                        'image_id' => $product_img_id->id,
                        'image_path' => (string) $product_img_id->image_path,
                    ]
                );
            }

            $product_img_id->delete();
        }

        $msg['flag'] = 'success';
        $msg['msg'] = __('Image delete Successfully');
        return $msg;
    }

    public function attribute_combination_edit(Request $request)
    {
        /*
         * PERMISSION. P1-INV-10 (e). `Manage Products` OR `Edit Products` -- see
         * lacksProductPermission() for why a render reached from the ungated show screen cannot
         * be gated on `Edit Products` alone.
         *
         * `redirect()->back()->with('error', ...)`, the shape the ownership refusal below already
         * uses, so a permission denial and an ownership denial are indistinguishable to the
         * caller and neither confirms that the product id exists.
         */
        if ($this->lacksProductPermission(['Manage Products', 'Edit Products'])) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $product = Product::find($request->id);

        /*
         * OWNERSHIP ON A PRODUCT NAMED BY REQUEST INPUT. P1-INV-07.
         *
         * `$request->id` -- not a route-model binding -- so P1-INV-01's guard on `update()`,
         * `edit()` and `show()` does not apply here, and nothing else did: the foreign product was
         * passed straight into the view. The same read leak those three closed, through a
         * different parameter.
         */
        if ($this->productIsOutsideCurrentStore($product)) {
            \Illuminate\Support\Facades\Log::warning(
                'attribute combination edit refused: the product belongs to another store',
                [
                    'product_id' => $request->id,
                    'product_store_id' => $product->store_id ?? null,
                    'current_store' => getCurrentStore(),
                    'user_id' => optional(auth()->user())->id,
                ]
            );

            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $options = array();
        $product_name = !empty($request->sku) ? $request->sku : '';
        $unit_price = !empty($request->price) ? $request->price : 0;

        foreach ($request->attribute_no as $key => $no) {
            $forVariationName = 'for_variation_' . $no;
            $for_variation = isset($request->{'for_variation_' . $no}) ? $request->{'for_variation_' . $no} : 0;
            if ($for_variation == 1) {
                if ($request->has($forVariationName) && $request->input($forVariationName) == 1) {
                    $name = 'attribute_options_' . $no;

                    if ($request->has($name) && is_array($request[$name])) {
                        $my_str = $request[$name];
                        $optionValues = [];
                        array_push($options, $my_str);
                    }
                }
            }
        }

        $combinations = $this->combination($options);
        $Shipping = Shipping::where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Same as parent', '');
        return view('product.attribute_combinations_edit', compact('combinations', 'unit_price', 'product_name', 'product', 'Shipping'));
    }

    public function product_attribute_delete($id)
    {
        /*
         * PERMISSION, BEFORE findOrFail. P1-INV-10 (e).
         *
         * `Edit Products`: this deletes a variant row and the stock it carries. Placed ahead of
         * `findOrFail($id)` deliberately -- otherwise a caller with no permission at all would
         * still learn from 404-vs-403 which variant ids exist. A caller who HOLDS the permission
         * still gets the 404 that `findOrFail` has always produced for a genuinely missing id.
         */
        if ($this->lacksProductPermission(['Edit Products'])) {
            return response()->json([
                'flag' => 'error',
                'msg' => __('Permission denied.'),
            ], 403);
        }

        $attribute = ProductVariant::findOrFail($id);

        /*
         * OWNERSHIP BEFORE A DESTRUCTIVE WRITE. P1-INV-07.
         *
         * This method had no permission check and no ownership check: `findOrFail($id)` took any
         * variant id and deleted it -- another store's variant row AND the stock it carries.
         * `ProductVariant` carries no StoreScope and is not in `scoped_models`, so nothing
         * narrowed it. `findOrFail` is kept so a genuinely missing id still answers 404 rather
         * than being reported as a permission problem.
         *
         * Resolved through the variant's PRODUCT, for the reason S36 recorded: the variant's own
         * nullable `store_id` is not the authority on who owns it.
         */
        $owner = Product::find($attribute->product_id);

        if ($this->productIsOutsideCurrentStore($owner)) {
            \Illuminate\Support\Facades\Log::warning(
                'product variant delete refused: the variant belongs to another store',
                [
                    'variant_id' => $attribute->id,
                    'product_id' => $attribute->product_id,
                    'product_store_id' => $owner->store_id ?? null,
                    'current_store' => getCurrentStore(),
                    'user_id' => optional(auth()->user())->id,
                ]
            );

            /*
             * 403 rather than a body. BOTH callers
             * (resources/views/product/attribute_combinations_edit.blade.php:319-324 and
             * resources/views/product/attribute_combinations_data.blade.php:389-394) announce
             * "Variant Deleted Successfully!" and remove the row from the DOM on ANY 2xx, without
             * reading the response, so returning "false" with a 200 would lie to the user.
             *
             * Line numbers and the second caller corrected by an adversarial review of this fix:
             * the first version cited one caller at the wrong lines.
             */
            return response()->json(['message' => __('Permission denied.')], 403);
        }

        $attribute->delete();

        return "true";
    }

    public function collectionAll($storeSlug, Request $request, $list)
    {
    }

    public function product_price(Request $request, $slug)
    {
        $store = getStore($slug);
       
        $settings = Setting::where('store_id', $store->id)->pluck('value', 'name')->toArray();

        $varint = $request->varint;
        $qty = $request->qty ?? $request->quantity;
        $product_id = $request->product_id;
        $return['qty'] = $qty;
        $return['variant_id'] = 0;
        $product = Product::find($product_id);
        $return['variant_product'] = $product->variant_product ?? 0;
        if (!empty($product)) {
            if (module_is_active('WholesaleProduct')) {
                if (!module_is_active('SizeGuideline')) {
                    if (module_is_active('WholesaleProduct')) {
                        $return = \Workdo\WholesaleProduct\app\Models\WholesaleProduct::Wholesale_Product_price($product, $request->all(),$store);
                    }else{
                        if ($product->variant_product == 0) {
                            // no varint
                            if (isset($settings['out_of_stock_threshold']) && ($product->product_stock < $settings['out_of_stock_threshold']) && $product->stock_order_status == 'not_allow') {
                                $return['status'] = 'error';
                                $return['stock_status'] = 'out_of_stock';
                                $return['stock_message'] = __('Out Of Stock');
                                $return['message'] = __('Product has been reached max quantity.');
                            } else {
                                $product_original_price = $product->original_price * $qty;
                                $price = FlashSale::saleDiscount($store, $product, $product_id, 0, ($product->sale_price ?? $product->final_price));
                                $product_final_price = ($price) * $qty;
                                // $product_final_price = ($product->sale_price ?? $product->final_price) * $qty;

                                $data['store_id'] = $store->id;
                                $data['product_tax_id'] = $product->tax_id;
                                $data['sub_total'] = $product_final_price;
                                $data['product_original_price'] = $product_original_price;
                                $cart_array  = Tax::TaxCount($data);

                                $return['sub_total'] = $product_final_price;
                                $return['product_original_price'] = $product_original_price;
                                $return['variant_id'] = 0;
                                $return['original_price'] = $cart_array['original_price'];
                                $return['final_price'] = $cart_array['final_price'];
                                $return['final_price_text'] = currency_format_with_sym($cart_array['final_price'],  $store->id);
                                $return['currency_name'] = $cart_array['currency_name'];
                                $return['currency'] = $cart_array['currency'];
                                $return['stock_message'] = __('In Stock');
                                $return['total_tax_price'] = $cart_array['total_tax_price'];
                                return response()->json($return);
                            }
                        } elseif ($product->variant_product == 1) {
                            // has varint
                            if (is_array($varint)) {
                                $variant_name = implode('-', $varint);
                            } else {
                                $variant_name = $varint;
                            }

                            $product->setAttribute('variantName', $variant_name);
                            $ProductStock = ProductVariant::where('product_id', $product_id)
                                ->whereRaw("REPLACE(variant, ' ', '') LIKE ?", [str_replace(' ', '', trim($variant_name))])
                                ->first();
                                if ($ProductStock)
                                {
                                    $stock = !empty($ProductStock->stock) ? $ProductStock->stock : $product->product_stock;
                                    $variationOptions = explode(',', $ProductStock->variation_option);
                                    $variant_enable = in_array('enabled', $variationOptions);
                                    $option = in_array('manage_stock', $variationOptions);

                                    if ($option == true) {
                                        $stock_status = $ProductStock->stock_order_status;
                                    } else {
                                        $stock_status = $product->stock_order_status;
                                    }

                                    if (($variant_enable == false) || ($stock < $qty && $stock_status == 'not_allow')) {
                                        $return['status'] = 'error';
                                        $return['variant_id'] = $ProductStock->id;
                                        if($variant_enable == false){
                                            $return['stock_status'] = 'not_available';
                                            $return['stock_message'] = __('Not Available');
                                            $return['message'] = __('Product variant is not available.');
                                        }else{
                                            $return['stock_status'] = 'out_of_stock';
                                            $return['stock_message'] = __('Out Of Stock');
                                            $return['message'] = __('Product has been reached max quantity.');
                                        }
                                    } else {
                                        $sale_price = !empty($ProductStock->price) ? $ProductStock->price : $ProductStock->variation_price;

                                        $variation_price = !empty($ProductStock->variation_price) ? $ProductStock->variation_price : $ProductStock->price;

                                        $var_price = !empty($sale_price) ? $sale_price : 0;

                                        $product_original_price = $variation_price * $qty;
                                        $price = FlashSale::saleDiscount($store, $product, $product_id, $ProductStock->id, $sale_price);
                                        $product_final_price = $price * $qty;
                                        // $product_final_price = $sale_price * $qty;

                                        if ($option == true) {
                                            $variat_stock = !empty($ProductStock->stock) ? $ProductStock->stock : 0;
                                        }else{
                                            $variat_stock = !empty($ProductStock->stock) ? $ProductStock->stock : $product->product_stock;
                                        }
                                        $data['store_id'] = $store->id;
                                        $data['product_tax_id'] = $product->tax_id;
                                        $data['sub_total'] = $product_final_price;
                                        $data['product_original_price'] = $product_original_price;
                                        $cart_array  = Tax::TaxCount($data);

                                        $return['sub_total'] = $product_final_price;
                                        $return['product_original_price'] = $product_original_price;
                                        $return['variant_id'] = $ProductStock->id;
                                        $return['original_price'] = $cart_array['original_price'];
                                        $return['final_price'] = $cart_array['final_price'];
                                        $return['final_price_text'] = currency_format_with_sym($cart_array['final_price'],  $store->id);
                                        $return['currency_name'] = $cart_array['currency_name'];
                                        $return['currency'] = $cart_array['currency'];
                                        $return['total_tax_price'] = $cart_array['total_tax_price'];
                                        $return['enable_option_data'] = !empty($option) ? $option : '';
                                        $return['stock'] = !empty($variat_stock) ? $variat_stock : 0;
                                        $return['stock_status'] = !empty($ProductStock->stock_status) ? $ProductStock->stock_status : '';
                                        $return['stock_message'] = __('In Stock');
                                        $return['description'] = !empty($ProductStock->description) ? $ProductStock->description : $product->descripion;
                                        $return['variant_name'] = !empty($variant_name) ? $variant_name : '';
                                        if(module_is_active('PreOrder')){
                                            if(auth('customers')->check()){
                                                $return['customer_login'] = auth('customers')->check() ?? null;
                                            }
                                            $pre_order_detail = \Workdo\PreOrder\app\Models\PreOrder::where('store_id', $store->id)->first();
                                            if($return['stock'] <= 0 && isset($pre_order_detail) && $pre_order_detail->enable_pre_order == 'on'){
                                                $return['enable_pre_order'] = 'on';
                                                $return['pre_order_name'] = $pre_order_detail->name ?? 'Pre Order';
                                                $return['pre_order_message'] = $pre_order_detail->message ?? '';
                                            }
                                        }
                                        return response()->json($return);
                                    }
                                }


                        } else {
                        }
                    }
                } else {
                    if (module_is_active('WholesaleProduct')) {
                        $return = \Workdo\WholesaleProduct\app\Models\WholesaleProduct::Wholesale_Product_price($product, $request->all(),$store);
                    } elseif (module_is_active('SizeGuideline')) {
                        $return = \Workdo\SizeGuideline\app\Models\SizeGuideline::Size_Product_price($product, $request->all(),$store);
                    }
                }
            } elseif (module_is_active('SizeGuideline')) {
                if (module_is_active('SizeGuideline')) {
                    $return = \Workdo\SizeGuideline\app\Models\SizeGuideline::Size_Product_price($product, $request->all(),$store);
                }
                else{
                    if ($product->variant_product == 0) {
                        // no varint
                        if (isset($settings['out_of_stock_threshold']) && ($product->product_stock < $settings['out_of_stock_threshold']) && $product->stock_order_status == 'not_allow') {
                            $return['status'] = 'error';
                            $return['stock_status'] = 'out_of_stock';
                            $return['stock_message'] = __('Out Of Stock');
                            $return['message'] = __('Product has been reached max quantity.');
                        } else {
                            $product_original_price = $product->original_price * $qty;
                            $price = FlashSale::saleDiscount($store, $product, $product_id, 0, ($product->sale_price ?? $product->final_price));
                            $product_final_price = ($price) * $qty;
                            // $product_final_price = ($product->sale_price ?? $product->final_price) * $qty;

                            $data['store_id'] = $store->id;
                            $data['product_tax_id'] = $product->tax_id;
                            $data['sub_total'] = $product_final_price;
                            $data['product_original_price'] = $product_original_price;
                            $cart_array  = Tax::TaxCount($data);

                            $return['sub_total'] = $product_final_price;
                            $return['product_original_price'] = $product_original_price;
                            $return['variant_id'] = 0;
                            $return['original_price'] = $cart_array['original_price'];
                            $return['final_price'] = $cart_array['final_price'];
                            $return['final_price_text'] = currency_format_with_sym($cart_array['final_price'],  $store->id);
                            $return['currency_name'] = $cart_array['currency_name'];
                            $return['currency'] = $cart_array['currency'];
                            $return['stock_message'] = __('In Stock');
                            $return['total_tax_price'] = $cart_array['total_tax_price'];
                            return response()->json($return);
                        }
                    } elseif ($product->variant_product == 1) {
                        // has varint
                        if (is_array($varint)) {
                            $variant_name = implode('-', $varint);
                        } else {
                            $variant_name = $varint;
                        }

                        $product->setAttribute('variantName', $variant_name);
                        $ProductStock = ProductVariant::where('product_id', $product_id)
                            ->whereRaw("REPLACE(variant, ' ', '') LIKE ?", [str_replace(' ', '', trim($variant_name))])
                            ->first();
                            if ($ProductStock)
                            {
                                $stock = !empty($ProductStock->stock) ? $ProductStock->stock : $product->product_stock;
                                $variationOptions = explode(',', $ProductStock->variation_option);
                                $variant_enable = in_array('enabled', $variationOptions);
                                $option = in_array('manage_stock', $variationOptions);

                                if ($option == true) {
                                    $stock_status = $ProductStock->stock_order_status;
                                } else {
                                    $stock_status = $product->stock_order_status;
                                }

                                if (($variant_enable == false) || ($stock < $qty && $stock_status == 'not_allow')) {
                                    $return['status'] = 'error';
                                    $return['variant_id'] = $ProductStock->id;
                                    if($variant_enable == false){
                                        $return['stock_status'] = 'not_available';
                                        $return['stock_message'] = __('Not Available');
                                        $return['message'] = __('Product variant is not available.');
                                    }else{
                                        $return['stock_status'] = 'out_of_stock';
                                        $return['stock_message'] = __('Out Of Stock');
                                        $return['message'] = __('Product has been reached max quantity.');
                                    }
                                } else {
                                    $sale_price = !empty($ProductStock->price) ? $ProductStock->price : $ProductStock->variation_price;

                                    $variation_price = !empty($ProductStock->variation_price) ? $ProductStock->variation_price : $ProductStock->price;

                                    $var_price = !empty($sale_price) ? $sale_price : 0;

                                    $product_original_price = $variation_price * $qty;
                                    $price = FlashSale::saleDiscount($store, $product, $product_id, $ProductStock->id, $sale_price);
                                    $product_final_price = ($price) * $qty;
                                    // $product_final_price = ($sale_price) * $qty;

                                    // $product_original_price = $variation_price * $qty;
                                    // $product_final_price = $var_price * $qty;
                                    if ($option == true) {
                                        $variat_stock = !empty($ProductStock->stock) ? $ProductStock->stock : 0;
                                    }else{
                                        $variat_stock = !empty($ProductStock->stock) ? $ProductStock->stock : $product->product_stock;
                                    }

                                    $data['store_id'] = $store->id;
                                    $data['product_tax_id'] = $product->tax_id;
                                    $data['sub_total'] = $product_final_price;
                                    $data['product_original_price'] = $product_original_price;
                                    $cart_array  = Tax::TaxCount($data);

                                    $return['sub_total'] = $product_final_price;
                                    $return['product_original_price'] = $product_original_price;
                                    $return['variant_id'] = $ProductStock->id;
                                    $return['original_price'] = $cart_array['original_price'];
                                    $return['final_price'] = $cart_array['final_price'];
                                    $return['final_price_text'] = currency_format_with_sym($cart_array['final_price'],  $store->id);
                                    $return['currency_name'] = $cart_array['currency_name'];
                                    $return['currency'] = $cart_array['currency'];
                                    $return['total_tax_price'] = $cart_array['total_tax_price'];
                                    $return['enable_option_data'] = !empty($option) ? $option : '';
                                    $return['stock'] = !empty($variat_stock) ? $variat_stock : 0;
                                    $return['stock_status'] = !empty($ProductStock->stock_status) ? $ProductStock->stock_status : '';
                                    $return['stock_message'] = __('In Stock');
                                    $return['description'] = !empty($ProductStock->description) ? $ProductStock->description : $product->descripion;
                                    $return['variant_name'] = !empty($variant_name) ? $variant_name : '';
                                    if(module_is_active('PreOrder')){
                                        if(auth('customers')->check()){
                                            $return['customer_login'] = auth('customers')->check() ?? null;
                                        }
                                        $pre_order_detail = \Workdo\PreOrder\app\Models\PreOrder::where('store_id', $store->id)->first();
                                        if($return['stock'] <= 0 && isset($pre_order_detail) && $pre_order_detail->enable_pre_order == 'on'){
                                            $return['enable_pre_order'] = 'on';
                                            $return['pre_order_name'] = $pre_order_detail->name ?? 'Pre Order';
                                            $return['pre_order_message'] = $pre_order_detail->message ?? '';
                                        }
                                    }
                                    return response()->json($return);
                                }
                            }


                    } else {
                    }
                }
            } else {
                if ($product->variant_product == 0) {
                    // no varint
                    if (isset($settings['out_of_stock_threshold']) && ($product->product_stock < $settings['out_of_stock_threshold']) && $product->stock_order_status == 'not_allow') {
                        $return['status'] = 'error';
                        $return['stock_status'] = 'out_of_stock';
                        $return['stock_message'] = __('Out Of Stock');
                        $return['message'] = __('Product has been reached max quantity.');
                    } else {
                        $product_original_price = $product->original_price * $qty;

                        $price = FlashSale::saleDiscount($store, $product, $product_id, 0, ($product->sale_price ?? $product->final_price));
                        $product_final_price = ($price) * $qty;
                        // $product_final_price = ($product->sale_price ?? $product->final_price) * $qty;

                        $data['store_id'] = $store->id;
                        $data['product_tax_id'] = $product->tax_id;
                        $data['sub_total'] = $product_final_price;
                        $data['product_original_price'] = $product_original_price;
                        $cart_array  = Tax::TaxCount($data);

                        $return['sub_total'] = $product_final_price;
                        $return['product_original_price'] = $product_original_price;
                        $return['variant_id'] = 0;
                        $return['original_price'] = $cart_array['original_price'];
                        $return['final_price'] = $cart_array['final_price'];
                        $return['final_price_text'] = currency_format_with_sym($cart_array['final_price'],  $store->id);
                        $return['currency_name'] = $cart_array['currency_name'];
                        $return['currency'] = $cart_array['currency'];
                        $return['stock_message'] = __('In Stock');
                        $return['total_tax_price'] = $cart_array['total_tax_price'];
                        return response()->json($return);
                    }
                } elseif ($product->variant_product == 1) {
                    // has varint
                    if (is_array($varint)) {
                        $variant_name = implode('-', $varint);
                    } else {
                        $variant_name = $varint;
                    }

                    $product->setAttribute('variantName', $variant_name);
                    $ProductStock = ProductVariant::where('product_id', $product_id)
                        ->whereRaw("REPLACE(variant, ' ', '') LIKE ?", [str_replace(' ', '', trim($variant_name))])
                        ->first();
                    if ($ProductStock) {
                        $stock = !empty($ProductStock->stock) ? $ProductStock->stock : $product->product_stock;
                        $variationOptions = explode(',', $ProductStock->variation_option);
                        $variant_enable = in_array('enabled', $variationOptions);
                        $option = in_array('manage_stock', $variationOptions);

                        if ($option == true) {
                            $stock_status = $ProductStock->stock_order_status;
                        } else {
                            $stock_status = $product->stock_order_status;
                        }

                        if (($variant_enable == false) || ($stock < $qty && $stock_status == 'not_allow')) {
                            $return['status'] = 'error';
                            $return['variant_id'] = $ProductStock->id;
                            if($variant_enable == false){
                                $return['stock_status'] = 'not_available';
                                $return['stock_message'] = __('Not Available');
                                $return['message'] = __('Product variant is not available.');
                            }else{
                                $return['stock_status'] = 'out_of_stock';
                                $return['stock_message'] = __('Out Of Stock');
                                $return['message'] = __('Product has been reached max quantity.');
                            }
                        } else {
                            $sale_price = !empty($ProductStock->price) ? $ProductStock->price : $ProductStock->variation_price;

                            $variation_price = !empty($ProductStock->variation_price) ? $ProductStock->variation_price : $ProductStock->price;

                            $var_price = !empty($sale_price) ? $sale_price : 0;

                            $product_original_price = $variation_price * $qty;

                            $price = FlashSale::saleDiscount($store, $product, $product_id, $ProductStock->id, $sale_price);

                            $product_final_price = ($price) * $qty;
                            // $product_final_price = ($sale_price) * $qty;

                            // $product_original_price = $variation_price * $qty;
                            // $product_final_price = $var_price * $qty;
                            if ($option == true) {
                                $variat_stock = !empty($ProductStock->stock) ? $ProductStock->stock : 0;
                            } else {
                                $variat_stock = !empty($ProductStock->stock) ? $ProductStock->stock : $product->product_stock;
                            }
                            $data['store_id'] = $store->id;
                            $data['product_tax_id'] = $product->tax_id;
                            $data['sub_total'] = $product_final_price;
                            $data['product_original_price'] = $product_original_price;
                            $cart_array  = Tax::TaxCount($data);

                            $return['sub_total'] = $product_final_price;
                            $return['product_original_price'] = $product_original_price;
                            $return['variant_id'] = $ProductStock->id;
                            $return['original_price'] = $cart_array['original_price'];
                            $return['final_price'] = $cart_array['final_price'];
                            $return['final_price_text'] = currency_format_with_sym($cart_array['final_price'],  $store->id);
                            $return['currency_name'] = $cart_array['currency_name'];
                            $return['currency'] = $cart_array['currency'];
                            $return['total_tax_price'] = $cart_array['total_tax_price'];
                            $return['enable_option_data'] = !empty($option) ? $option : '';
                            $return['stock'] = !empty($variat_stock) ? $variat_stock : 0;
                            $return['stock_status'] = !empty($ProductStock->stock_status) ? $ProductStock->stock_status : '';
                            $return['stock_message'] = __('In Stock');
                            $return['description'] = !empty($ProductStock->description) ? $ProductStock->description : $product->descripion;
                            $return['variant_name'] = !empty($variant_name) ? $variant_name : '';
                            if(module_is_active('PreOrder')){
                                if(auth('customers')->check()){
                                    $return['customer_login'] = auth('customers')->check() ?? null;
                                }
                                $pre_order_detail = \Workdo\PreOrder\app\Models\PreOrder::where('store_id', $store->id)->first();
                                if($return['stock'] <= 0 && isset($pre_order_detail) && $pre_order_detail->enable_pre_order == 'on'){
                                    $return['enable_pre_order'] = 'on';
                                    $return['pre_order_name'] = $pre_order_detail->name ?? 'Pre Order';
                                    $return['pre_order_message'] = $pre_order_detail->message ?? '';
                                }
                            }
                            return response()->json($return);
                        }
                    }
                } else {
                }
            }
        } else {
            $return['status'] = 'error';
            $return['message'] = __('Whoops! Something went wrong.');
        }
        return response()->json($return);
    }

    public function searchProducts(Request $request)
    {
        $lastsegment = $request->session_key;
        $store_id = getStoreById(getCurrentStore());
        if ($request->ajax() && isset($lastsegment) && !empty($lastsegment)) {
            $output = "";
            if ($request->cat_id !== '' && $request->search == '') {
                if ($request->cat_id == '0') {
                    $products = Product::where('store_id', getCurrentStore())->get();
                } else {
                    $products = Product::where('category_id', $request->cat_id)->where('store_id', getCurrentStore())->get();
                }
            } else {
                if ($request->cat_id == '0') {
                    $products = Product::where('name', 'LIKE', "%{$request->search}%")->where('store_id', getCurrentStore())->get();
                } else {
                    $products = Product::where('name', 'LIKE', "%{$request->search}%")->where('store_id', getCurrentStore())->Where('category_id', $request->cat_id)->get();
                }
            }
            $productId = null;
            if (count($products) > 0) {
                foreach ($products as $key => $product) {
                    $productId = $product->id;
                    
                    // Handle digital product images
                    if ($product->product_type === 'digital' && module_is_active('DigitalProduct')) {
                        if (class_exists('\Workdo\DigitalProduct\app\Models\DigitalProductImage')) {
                            $digitalImage = \Workdo\DigitalProduct\app\Models\DigitalProductImage::where('product_id', $product->id)->first();
                            if ($digitalImage && !empty($digitalImage->image_path)) {
                                $image_url = get_file($digitalImage->image_path);
                            } else {
                                $image_url = !empty($product->cover_image_path) ? get_file($product->cover_image_path) : get_file('uploads/cover_image_path/default.jpg');
                            }
                        } else {
                            $image_url = !empty($product->cover_image_path) ? get_file($product->cover_image_path) : get_file('uploads/cover_image_path/default.jpg');
                        }
                    } else {
                        $image_url = !empty($product->cover_image_path) ? get_file($product->cover_image_path) : get_file('uploads/cover_image_path/default.jpg');
                    }

                    if ($product->variant_product != '1') {
                        if ($product->track_stock == 0) {
                            $quantity = $product->stock_status;
                            if ($product->stock_status == 'in_stock') {
                                $quantity = 'In Stock';
                            } elseif ($product->stock_status == 'on_backorder') {
                                $quantity = 'On Backorder';
                            } else {
                                $quantity = 'Out of Stock';
                            }
                        } else {
                            $quantity = $product->product_stock . ' Qty';
                        }

                        if ($request->session_key == 'purchases') {
                            $productprice = getProductActualPrice($product);
                        } else if ($request->session_key == 'pos_' . getCurrentStore()) {
                            $productprice = getProductActualPrice($product);
                        } else {
                            $productprice = getProductActualPrice($product);
                        }

                        $productprice = currency_format_with_sym($productprice, $store_id->id);
                        $output .= ' <div class="col-xl-4 col-lg-6 col-sm-6 col-xs-6 col-12">
                                    <div class="tab-pane fade show active toacart w-100" data-url="' . url('/addToCart/' . $product->id . '/' . $lastsegment) . '">
                                        <div class="btn-primary position-relative card">
                                            <img alt="Image placeholder" src="' . asset($image_url) . '" class="card-image avatar hover-shadow-lg" style=" height: 6rem; width: 100%;">
                                            <div class="p-0 custom-card-body card-body d-flex ">
                                                <div class="card-body my-2 p-2 text-center card-bottom-content">
                                                <h6 class="mb-2 text-dark product-title-name">' . $product->name . '</h6>
                                                <p class="mb-2 text-dark product-title-name small">' . $product->slug . '</p>
                                                <small class="text-primary">' . $productprice . '</small>
                                                <small class="top-badge p-2 badge-danger mb-0">' . $quantity . '</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div> ';
                    } else {

                        $output .= ' <div class="col-xl-4 col-lg-6 col-sm-6 col-xs-6 col-12">
                                <div class="tab-pane fade show active toacart w-100" data-url="' . url('/pos/product-variant/' . $product->id . '/' . $lastsegment) . '" data-ajax-popup="true" data-size="lg" data-align="centered" data-title="Product Variant">
                                    <div class="btn-primary position-relative card">
                                        <img alt="Image placeholder" src="' . asset($image_url) . '" class="card-image avatar hover-shadow-lg" style=" height: 6rem; width: 100%;">
                                        <div class="p-0 custom-card-body card-body d-flex ">
                                            <div class="card-body my-2 p-2 text-center card-bottom-content">
                                                <h6 class="mb-2 text-dark product-title-name">' . $product->name . '</h6>
                                                <p class="mb-2 text-dark product-title-name small">' . $product->slug . '</p>
                                                <small class="text-primary">In Variant</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div> ';
                    }
                }
                //return Response($output);
            } else {
                $output = '<div class="card card-body col-12 text-center">
                    <h5>' . __("No Product Available") . '</h5>
                    </div>';
               // return Response($output);
            }

            $in_cart = false;
            $cart = session()->get('pos_' . getCurrentStore());
            if (!empty($productId) && isset($cart[$productId])) {
                $in_cart = true;
            }
            return response()->json(
                [
                    'code' => 200,
                    'status' => 'Success',
                    'success' => __('Product get successfully!'),
                    'in_cart' => $in_cart,
                    'html' => $output,
                ]
            );
        }
    }

    public function searchProductsSku(Request $request)
    {
        $lastsegment = $request->session_key;
        $store_id = getStoreById(getCurrentStore());
        if ($request->ajax() && isset($lastsegment) && !empty($lastsegment)) {
            $output = "";
            if ($request->cat_id !== '' && $request->search == '') {
                if ($request->cat_id == '0') {
                    $products = Product::where('store_id', getCurrentStore())->get();
                } else {
                    $products = Product::where('category_id', $request->cat_id)->where('store_id', getCurrentStore())->get();
                }
            } else {
                if ($request->cat_id == '0') {
                    $products = Product::where('slug', 'LIKE', "%{$request->search}%")->where('store_id', getCurrentStore())->get();
                } else {
                    $products = Product::where('slug', 'LIKE', "%{$request->search}%")->where('store_id', getCurrentStore())->Where('category_id', $request->cat_id)->get();
                }
            }
            $productId = null;
            if (count($products) > 0) {
                foreach ($products as $key => $product) {
                    $productId = $product->id;
                    
                    // Handle digital product images
                    if ($product->product_type === 'digital' && module_is_active('DigitalProduct')) {
                        if (class_exists('\Workdo\DigitalProduct\app\Models\DigitalProductImage')) {
                            $digitalImage = \Workdo\DigitalProduct\app\Models\DigitalProductImage::where('product_id', $product->id)->first();
                            if ($digitalImage && !empty($digitalImage->image_path)) {
                                $image_url = get_file($digitalImage->image_path);
                            } else {
                                $image_url = !empty($product->cover_image_path) ? get_file($product->cover_image_path) : get_file('uploads/cover_image_path/default.jpg');
                            }
                        } else {
                            $image_url = !empty($product->cover_image_path) ? get_file($product->cover_image_path) : get_file('uploads/cover_image_path/default.jpg');
                        }
                    } else {
                        $image_url = !empty($product->cover_image_path) ? get_file($product->cover_image_path) : get_file('uploads/cover_image_path/default.jpg');
                    }

                    if ($product->variant_product != '1') {
                        if ($product->track_stock == 0) {
                            $quantity = $product->stock_status;
                            if ($product->stock_status == 'in_stock') {
                                $quantity = 'In Stock';
                            } elseif ($product->stock_status == 'on_backorder') {
                                $quantity = 'On Backorder';
                            } else {
                                $quantity = 'Out of Stock';
                            }
                        } else {
                            $quantity = $product->product_stock . ' Qty';
                        }

                        if ($request->session_key == 'purchases') {
                            $productprice = getProductActualPrice($product);
                        } else if ($request->session_key == 'pos_' . getCurrentStore()) {
                            $productprice = getProductActualPrice($product);
                        } else {
                            $productprice = getProductActualPrice($product);
                        }

                        $productprice = currency_format_with_sym($productprice, $store_id->id);
                        $output .= ' <div class="col-xl-4 col-lg-6 col-sm-6 col-xs-6 col-12">
                                    <div class="tab-pane fade show active toacart w-100" data-url="' . url('/addToCart/' . $product->id . '/' . $lastsegment) . '">
                                        <div class="btn-primary position-relative card">
                                            <img alt="Image placeholder" src="' . asset($image_url) . '" class="card-image avatar hover-shadow-lg" style=" height: 6rem; width: 100%;">
                                            <div class="p-0 custom-card-body card-body d-flex ">
                                                <div class="card-body my-2 p-2 text-center card-bottom-content">
                                                <h6 class="mb-2 text-dark product-title-name">' . $product->name . '</h6>
                                                <p class="mb-2 text-dark product-title-name small">' . $product->slug . '</p>
                                                <small class="text-primary">' . $productprice . '</small>
                                                <small class="top-badge badge p-2 badge-danger mb-0">' . $quantity . '</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div> ';
                    } else {

                        $output .= ' <div class="col-xl-4 col-lg-6 col-sm-6 col-xs-6 col-12">
                                <div class="tab-pane fade show active toacart w-100" data-url="' . url('/pos/product-variant/' . $product->id . '/' . $lastsegment) . '" data-ajax-popup="true" data-size="lg" data-align="centered" data-title="Product Variant">
                                    <div class="btn-primary position-relative card">
                                        <img alt="Image placeholder" src="' . asset($image_url) . '" class="card-image avatar hover-shadow-lg" style=" height: 6rem; width: 100%;">
                                        <div class="p-0 custom-card-body card-body d-flex ">
                                            <div class="card-body my-2 p-2 text-center card-bottom-content">
                                                <h6 class="mb-2 text-dark product-title-name">' . $product->name . '</h6>
                                                <p class="mb-2 text-dark product-title-name small">' . $product->slug . '</p>
                                                <small class="text-primary">In Variant</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div> ';
                    }
                }
                // return Response($output);
            } else {
                $output = '<div class="card card-body col-12 text-center">
                    <h5>' . __("No Product Available") . '</h5>
                    </div>';
               // return Response($output);
            }

            $in_cart = false;
            $cart = session()->get('pos_' . getCurrentStore());
            if (!empty($productId) && isset($cart[$productId])) {
                $in_cart = true;
            }
            return response()->json(
                [
                    'code' => 200,
                    'status' => 'Success',
                    'success' => __('Product get successfully!'),
                    'in_cart' => $in_cart,
                    'html' => $output,
                ]
            );
        }
    }

    public function addToCart(Request $request, $id, $session_key, $variant_id = 0)
    {
        $store = $store_id = getStoreById(getCurrentStore());

        /*
         * P1-INV-03. `Product::find($id)` HAD NO STORE PREDICATE, and `Product` is not in
         * config/tenancy.php's `scoped_models`, so `StoreScope` adds nothing: this resolved any
         * tenant's product. It is the feeder that puts a line into the POS session, and
         * `PosController::store()` then writes that line's stock -- so an unscoped resolve here is
         * how another store's product reached a till's sale in the first place.
         *
         * Scoped to the caller's own store, the foreign product is simply "not found", which is
         * the answer this handler already has for an id that does not exist and needs no new
         * response shape. Guarding only the sale would have left the foreign row in the cart,
         * priced and displayed to the cashier; guarding only the feeder would have left the sale
         * trusting whatever a session already held. Both are guarded, and each is pinned by its
         * own test.
         */
        $product = Product::where('id', $id)
            ->where('store_id', $store->id)
            ->first();
        $settings = Utility::Setting($store->id);
        if (!$product) {
            return response()->json(
                [
                    'code' => 404,
                    'status' => 'Error',
                    'error' => __('This product is not found!'),
                ],
                404
            );
        }

        $productname = $product->name;

        $variant = null;
        $productquantity = $productprice = 0;
        if (isset($request->variants)) {
            $variant = ProductVariant::where('product_id', $id)->where('variant', $request->variants)->first();
            if ($variant) {
                $productquantity = $variant->stock;
                if ($session_key ==  'pos_' . getCurrentStore() && $productquantity <= 0) {
                    return response()->json(
                        [
                            'code' => 404,
                            'status' => 'Error',
                            'error' => __('This product is out of stock!'),
                        ],
                        404
                    );
                }

                if ($session_key == 'pos_' . getCurrentStore()) {

                    $productprice = getProductActualPrice($product, $variant);
                } else {
                    $productprice = 0;
                }
            }
        } else {
            if (
                $product->track_stock == 0 && $product->stock_status == 'out_of_stock' ||
                ($product->track_stock != 0 && isset($settings['out_of_stock_threshold']) && ($product->product_stock < $settings['out_of_stock_threshold']) && $product->stock_order_status == 'not_allow') ||
                (!$product || ($session_key != 'pos_' . getCurrentStore()))
            ) {
                return response()->json(
                    [
                        'code' => 404,
                        'status' => 'Error',
                        'error' => __('This product is out of stock!'),
                    ],
                    404
                );
            }

            $productquantity = $product->product_stock;
            if ($session_key == 'pos_' . getCurrentStore()) {

                $productprice = getProductActualPrice($product);
            } else {
                $productprice = 0;
            }
        }

        $originalquantity = (int) $productquantity;

        $tax_option = TaxOption::where('store_id', $store_id->id)
		->pluck('value', 'name')->toArray();

        $Tax = Tax::where('id', $product->tax_id)->where('store_id', $store_id->id)->first();
        $tax_price = 0;
        $product_tax = '';
        if((isset($tax_option['price_type']) && $tax_option['price_type'] != 'inclusive') && (isset($tax_option['shop_price']) && $tax_option['shop_price'] != 'including'))
        {
            if ($Tax && count($Tax->tax_methods()) > 0) {
                foreach ($Tax->tax_methods() as $mkey => $method) {
                    $amount = $method->tax_rate * $productprice / 100;
                    $tax_price += $amount;
                    $cart_array['tax_info'][$mkey]["tax_name"] = $method->name;
                    $cart_array['tax_info'][$mkey]["tax_type"] = $method->tax_rate;
                    $cart_array['tax_info'][$mkey]["tax_amount"] = $amount;
                    $product_tax .= !empty($method) ? "<span class='badge bg-primary'>" . $method->name . ' (' . $method->tax_rate . '%)' . "</span><br>" : '';
                    $cart_array['tax_info'][$mkey]["id"] = $method->id;
                    $cart_array['tax_info'][$mkey]["tax_price"] = SetNumber($amount);
                }
            }
        }
        $subtotal = $productprice + $tax_price;
        $cart            = session()->get($session_key);
        // Handle digital product images
        if ($product->product_type === 'digital' && module_is_active('DigitalProduct')) {
            if (class_exists('\Workdo\DigitalProduct\app\Models\DigitalProductImage')) {
                $digitalImage = \Workdo\DigitalProduct\app\Models\DigitalProductImage::where('product_id', $product->id)->first();
                if ($digitalImage && !empty($digitalImage->image_path)) {
                    $image_url = get_file($digitalImage->image_path);
                } else {
                    $image_url = !empty($product->cover_image_path) ? get_file($product->cover_image_path) : ('uploads/is_cover_image') . '/default.jpg';
                }
            } else {
                $image_url = !empty($product->cover_image_path) ? get_file($product->cover_image_path) : ('uploads/is_cover_image') . '/default.jpg';
            }
        } else {
            if (!empty($product->cover_image_path)) {
                $image_url = get_file($product->cover_image_path);
            } else {
                $image_url = ('uploads/is_cover_image') . '/default.jpg';
            }
        }

        $model_delete_id = 'delete-form-' . $id;

        $carthtml = '';

        $carthtml .= '<tr data-product-id="' . $id . '" id="product-id-' . $id . '">
                        <td class="cart-images">
                            <img alt="Image placeholder" src="' . ($image_url) . '" class="card-image avatar shadow hover-shadow-lg">
                        </td>

                        <td class="name">' . $productname . '</td>

                        <td class="">
                                <span class="quantity buttons_added">
                                        <input type="button" value="-" class="minus bg-primary">
                                        <input type="number" step="1" min="1" max="" name="quantity" title="' . __('Quantity') . '" class="input-number" size="4" data-url="' . url('update-cart/') . '" data-id="' . $id . '" style="width:50px;">
                                        <input type="button" value="+" class="plus bg-primary">
                                </span>
                        </td>
                        <td class="tax">' . $product_tax . '</td>

                        <td class="price">' . (currency_format_with_sym($productprice, $store_id->id) ?? SetNumberFormat($productprice)) . '</td>

                        <td class="total_orignal_price">' . (currency_format_with_sym($subtotal, $store_id->id) ?? SetNumberFormat($subtotal)) . '</td>

                        <td class="">
                            <form method="post" class="mb-0" action="' . route('remove-from-cart') . '"  accept-charset="UTF-8" id="' . $model_delete_id . '">
                            <button type="button" class="show_confirm btn btn-sm btn-danger" data-confirm="' . __('Are You Sure?') . '" data-text="' . __('This action can not be undone. Do you want to continue?') . '">
                            <span class=""><i class="ti ti-trash"></i></span>
                            </button>
                                <input name="_method" type="hidden" value="DELETE">
                                <input name="_token" type="hidden" value="' . csrf_token() . '">
                                <input type="hidden" name="session_key" value="' . $session_key . '">
                                <input type="hidden" name="id" value="' . $id . '">
                            </form>
                        </td>
                    </td>';
        // if cart is empty then this the first product

        // Get the appropriate image path for cart storage
        $cart_image_path = $product->cover_image_path;
        if ($product->product_type === 'digital' && module_is_active('DigitalProduct')) {
            if (class_exists('\Workdo\DigitalProduct\app\Models\DigitalProductImage')) {
                $digitalImage = \Workdo\DigitalProduct\app\Models\DigitalProductImage::where('product_id', $product->id)->first();
                if ($digitalImage && !empty($digitalImage->image_path)) {
                    $cart_image_path = $digitalImage->image_path;
                }
            }
        }

        if (!$cart) {
            $cart = [
                $id => [
                    "product_id" => $product->id,
                    "name" => $productname,
                    "image" => $cart_image_path,
                    "quantity" => 1,
                    "orignal_price" => $productprice,
                    "per_product_discount_price" => $product->discount_amount,
                    "discount_price" => $product->discount_amount,
                    "final_price" => $subtotal,
                    "id" => $id,
                    "tax" => $tax_price,
                    "total_orignal_price" => $subtotal,
                    "originalquantity" => $originalquantity,
                    'variant_id' => $variant->id ?? 0,
                    "variant_name" => $product->variant_attribute,
                    "return" => 0,
                ],
            ];

            if ((($product->track_stock != 0 && $originalquantity < $cart[$id]['quantity']) || ($product->track_stock == 0 && $product->stock_status == 'out_of_stock')) && $session_key != 'pos_' . getCurrentStore()) {
                return response()->json(
                    [
                        'code' => 404,
                        'status' => 'Error',
                        'error' => __('This product is out of stock!'),
                    ],
                    404
                );
            }

            session()->put($session_key, $cart);

            return response()->json(
                [
                    'code' => 200,
                    'status' => 'Success',
                    'success' => $productname . __(' added to cart successfully!'),
                    'product' => $cart[$id],
                    'carthtml' => $carthtml,
                ]
            );
        }

        // if cart not empty then check if this product exist then increment quantity
        if (isset($cart[$id])) {
            $cartProduct = Product::find($id);
            $cart[$id]['quantity']++;
            $cart[$id]['id'] = $id;

            $subtotal = $cart[$id]["orignal_price"] * $cart[$id]["quantity"];
            $tax = 0;
            $taxes            = !empty($cart[$id]["tax"]) ? $cart[$id]["tax"] : '';

            $Tax = Tax::where('id', $cartProduct->tax_id)->where('store_id', $store_id->id)->first();
            $tax_price = 0;
            $product_tax = '';
            $price = $cart[$id]["orignal_price"] * $cart[$id]["quantity"];
            if((isset($tax_option['price_type']) && $tax_option['price_type'] != 'inclusive') && (isset($tax_option['shop_price']) && $tax_option['shop_price'] != 'including'))
            {
                if ($Tax && count($Tax->tax_methods()) > 0) {
                    foreach ($Tax->tax_methods() as $mkey => $method) {
                        $amount = $method->tax_rate * $price / 100;
                        $tax_price += $amount;
                        $cart_array['tax_info'][$mkey]["tax_name"] = $method->name;
                        $cart_array['tax_info'][$mkey]["tax_type"] = $method->tax_rate;
                        $cart_array['tax_info'][$mkey]["tax_amount"] = $amount;
                        $product_tax .= !empty($method) ? "<span class='badge bg-primary'>" . $method->name . ' (' . $method->tax_rate . '%)' . "</span><br>" : '';
                        $cart_array['tax_info'][$mkey]["id"] = $method->id;
                        $cart_array['tax_info'][$mkey]["tax_price"] = SetNumber($amount);
                    }
                }
            }

            if (!empty($taxes)) {
                $productprice          = $cart[$id]["orignal_price"] *  (float)$cart[$id]["quantity"];
                $subtotal = $productprice +  $tax_price;
            } else {

                $productprice          = $cart[$id]["orignal_price"] ?? 0;
                $subtotal = $productprice  *  (float)$cart[$id]["quantity"];
            }
            $cart[$id]["total_orignal_price"] = $subtotal;

            $cart[$id]["total_orignal_price"]         = $subtotal + $tax;
            $cart[$id]["originalquantity"] = $originalquantity;
            $cart[$id]["tax"]      = $tax_price;
            if ((($product->track_stock != 0 && $originalquantity < $cart[$id]['quantity']) || ($product->track_stock == 0 && $product->stock_status == 'out_of_stock')) && $session_key != 'pos_' . getCurrentStore()) {
                return response()->json(
                    [
                        'code' => 404,
                        'status' => 'Error',
                        'error' => __('This product is out of stock!'),
                    ],
                    404
                );
            }

            session()->put($session_key, $cart);

            return response()->json(
                [
                    'code' => 200,
                    'status' => 'Success',
                    'success' => $productname . __(' added to cart successfully!'),
                    'product' => $cart[$id],
                    'carttotal' => $cart,
                ]
            );
        }

        // if item not exist in cart then add to cart with quantity = 1
        $cart[$id] = [
            "product_id" => $product->id,
            "name" => $productname,
            "image" => $cart_image_path,
            "quantity" => 1,
            "orignal_price" => $productprice,
            "per_product_discount_price" => $product->discount_amount,
            "discount_price" => $product->discount_amount,
            "final_price" => $subtotal,
            "id" => $id,
            "tax" => $tax_price,
            "total_orignal_price" => $subtotal,
            "originalquantity" => $originalquantity,
            'variant_id' => $variant->id ?? 0,
            "variant_name" => $product->variant_attribute,
            "return" => 0,
        ];
        if ((($product->track_stock != 0 && $originalquantity < $cart[$id]['quantity']) || ($product->track_stock == 0 && $product->stock_status == 'out_of_stock')) && $session_key != 'pos_' . getCurrentStore()) {
            return response()->json(
                [
                    'code' => 404,
                    'status' => 'Error',
                    'error' => __('This product is out of stock!'),
                ],
                404
            );
        }

        session()->put($session_key, $cart);
        return response()->json(
            [
                'code' => 200,
                'status' => 'Success',
                'success' => $productname . __(' added to cart successfully!'),
                'product' => $cart[$id],
                'carthtml' => $carthtml,
                'carttotal' => $cart,
            ]
        );
    }

    public function updateCart(Request $request)
    {
        $id          = $request->id;
        $quantity    = $request->quantity;
        $discount    = $request->discount;
        $session_key = $request->session_key;
        $store_id = getStoreById(getCurrentStore());

        if ($request->ajax() && isset($id) && !empty($id) && isset($session_key) && !empty($session_key)) {
            $cart = session()->get($session_key);

            if (isset($cart[$id]) && $quantity == 0) {
                unset($cart[$id]);
            }

            if ($quantity && !empty($quantity)) {

                $cart[$id]["quantity"] = $quantity;
                $taxes            = !empty($cart[$id]["tax"]) ? $cart[$id]["tax"] : '';

                $price = ($cart[$id]["orignal_price"] ?? 0) * $quantity;

                $tax_option = TaxOption::where('store_id', $store_id->id)
                            ->pluck('value', 'name')->toArray();

                /*
                 * P1-INV-03. STORE-SCOPED, like addToCart's twin above. This resolved any
                 * tenant's product -- `Product` is not in `scoped_models` -- and it is the second
                 * feeder of the POS session, so an unscoped resolve here revises a cart line for
                 * a product the caller does not own.
                 *
                 * A product that does not resolve is REFUSED rather than tolerated: the next line
                 * reads `$product->tax_id`, so a null would be a fatal, and "this is not your
                 * product" is not a condition to continue past.
                 */
                $product = Product::where('id', $id)
                    ->where('store_id', $store_id->id)
                    ->first();

                if (empty($product)) {
                    return response()->json(
                        [
                            'code' => 404,
                            'status' => 'Error',
                            'error' => __('This product is not found!'),
                        ],
                        404
                    );
                }

                $Tax = Tax::where('store_id', $store_id->id)->where('id', $product->tax_id)->first();
                $tax_price = 0;
                $product_tax = '';
                if((isset($tax_option['price_type']) && $tax_option['price_type'] != 'inclusive') && (isset($tax_option['shop_price']) && $tax_option['shop_price'] != 'including'))
                {
                    if ($Tax) {
                        if (count($Tax->tax_methods()) > 0) {
                            foreach ($Tax->tax_methods() as $mkey => $method) {
                                $tax_price += $method->tax_rate * $price / 100;
                                $product_tax .= !empty($Tax) ? "<span class='badge bg-primary'>" . $method->name . ' (' . $method->tax_rate . '%)' . "</span><br>" : '';
                            }
                        }
                    }
                }

                $subtotal = $price + $tax_price;
                $cart[$id]["tax"] = $tax_price;
                $producttax = 0;
                if (!empty($taxes)) {
                    $productprice          = $cart[$id]["orignal_price"] *  (float)$quantity;
                    $subtotal = $productprice +  $tax_price;
                } else {
                    $productprice          = $cart[$id]["orignal_price"] ?? 0;
                    $subtotal = ($productprice  *  (float) $quantity) + $tax_price;
                }

                $cart[$id]["total_orignal_price"] = $subtotal;
            }

            /*
             * P1-INV-03. THIS GUARD COULD NEVER FIRE, and the reason is one character of
             * precedence. It read:
             *
             *     isset($cart[$id]["originalquantity"]) < $cart[$id]['quantity']
             *
             * `isset()` yields a BOOL, and PHP 8 compares bool-to-int by casting the INT to bool
             * -- so for every positive quantity the test is `true < true`, which is false.
             * CONFIRMED BY EXECUTION, not by reading: with originalquantity 5 and quantity 999 the
             * shipped expression returns false and the intended one returns true.
             *
             * So the POS cart accepted ANY quantity. That mattered more once the sale became a
             * real decrement (PosController::store): the posted quantity is what leaves the shelf,
             * so an update of 999 against a stock of 5 takes recorded stock to -994. The absolute
             * write it replaced was differently wrong rather than safer -- neither bounded it.
             *
             * The comparison now reads the VALUE, which is what was meant. `isset()` is kept as a
             * separate conjunct so a line without the key is still skipped rather than compared
             * against null.
             */
            if (isset($cart[$id])
                && isset($cart[$id]["originalquantity"])
                && $cart[$id]["originalquantity"] < $cart[$id]['quantity']
                && $session_key == 'pos_' . getCurrentStore()) {
                return response()->json(
                    [
                        'code' => 404,
                        'status' => 'Error',
                        'error' => __('This product is out of stock!'),
                    ],
                    404
                );
            }

            $subtotal = array_sum(array_column($cart, 'total_orignal_price'));
            $discount = $request->discount;
            $total = $subtotal - (float)$discount;
            $totalDiscount = currency_format_with_sym($total, $store_id->id) ?? SetNumberFormat($total);
            $discount = $totalDiscount;

            session()->put($session_key, $cart);
            return response()->json(
                [
                    'code' => 200,
                    'success' => __('Cart updated successfully!'),
                    'product' => $cart,
                    'discount' => $discount,
                ]
            );
        } else {
            return response()->json(
                [
                    'code' => 404,
                    'status' => 'Error',
                    'error' => __('This Product is not found!'),
                ],
                404
            );
        }
    }

    public function removeFromCart(Request $request)
    {
        $id          = $request->id;
        $session_key = $request->session_key;
        if (isset($id) && !empty($id) && isset($session_key) && !empty($session_key)) {
            $cart = session()->get($session_key);
            if (isset($cart[$id])) {
                unset($cart[$id]);
                session()->put($session_key, $cart);
            }
            return redirect()->back()->with('success', __('Product removed from cart!'));
        } else {
            return redirect()->back()->with('error', __('This Product is not found!'));
        }
    }

    public function emptyCart(Request $request)
    {
        $session_key = $request->session_key;

        if (isset($session_key) && !empty($session_key)) {
            $cart = session()->get($session_key);
            if (isset($cart) && count($cart) > 0) {
                session()->forget($session_key);
            }

            return redirect()->back()->with('success', __('Cart is empty!'));
        } else {
            return redirect()->back()->with('error', __('Cart cannot be empty!.'));
        }
    }

    public function productVariant(Request $request, $id, $session_key)
    {
        $product = Product::where('id', $id)->first();
        $product_variant_names = ProductVariant::where('product_id', $product->id)->get();

        return view('pos.product_variant', compact('product', 'product_variant_names', 'session_key'));
    }

    public function getProductsVariantQuantity(Request $request)
    {
        $session_key = $request->session_key;
        $cart = session()->get($session_key);
        $status = false;
        $quantity = $variant_id = 0;
        $quantityHTML = '<strong>' . __('Please select variants to get available quantity.') . '</strong>';
        $priceHTML = '';
        $product = Product::find($request->product_id);
        $price = currency_format_with_sym($product->price, getCurrentStore());
        $status = false;

        if ($product && $request->variants != '') {
            $variant = ProductVariant::where('product_id', $product['id'])->where('variant', $request->variants)->first();
            if ($variant) {
                $status = true;
                $quantity = $variant->stock - (isset($cart[$variant->id]['quantity']) ? $cart[$variant->id]['quantity'] : 0);
                $store = getStoreById(getCurrentStore());
                $price = currency_format_with_sym(Product::ProductPrice($store->slug, $product->id,$variant->id));
                $variant_id = $variant->id;
            }
        }

        return response()->json(
            [
                'status' => $status,
                'price' => $price,
                'quantity' => $quantity,
                'variant_id' => $variant_id
            ]
        );
    }

    public function VariantDelete(Request $request, $id, $product_id)
    {
        if (auth()->user() && auth()->user()->can('Delete Variants')) {
            $product = Product::find($product_id);
            if (!empty($product->variants_json) && ProductVariantOption::find($id)->exists()) {
                $var_json = json_decode($product->variants_json, true);

                $i = 0;
                foreach ($var_json[0] as $key => $value) {
                    $var_ops = explode(' : ', ProductVariantOption::find($id)->name);
                    $count = ProductVariantOption::where('product_id', $product->id)->where('name', 'LIKE', '%' . $var_ops[0] . '%')->count();
                    if ($count == 1 && $i == 0) {
                        $unsetIndex = array_search($var_ops[0], $var_json[0]['variant_options'], true);
                        unset($var_json[0]['variant_options'][$unsetIndex]);
                    }
                    $i++;
                }
                $variants = ProductVariantOption::where('product_id', $product->id)->count();
                if ($variants == 1) {
                    $product->variants_json = '{}';
                    $product->update();
                } else {
                    $product->variants_json = json_encode($var_json);
                    $product->update();
                }
            }
            ProductVariantOption::find($id)->delete();
            return redirect()->back()->with('success', __('Variant successfully deleted.'));
        } else {
            return redirect()->back()->with('error', 'Permission denied.');
        }
    }

    public function list(Request $request)
    {
        if(auth()->user() && auth()->user()->isAbleTo('Manage Products')) {
            $products = Product::where('product_type', null)->where('store_id', getCurrentStore())->orderBy('id', 'desc')->paginate(11);
            $settings = Setting::where('store_id', getCurrentStore())->pluck('value', 'name')->toArray();

            if ($request->id == 1) {
                $msg = __('Product Successfully Created');
                return view('product.list', compact('products', 'settings', 'msg', 'filterCategories'));
            } elseif ($request->id == 2) {
                $msg = __('Product Successfully Updated');
                return view('product.list', compact('products', 'settings', 'msg', 'filterCategories'));
            } else {
                $msg = 0;
                return view('product.list', compact('products', 'settings', 'msg', 'filterCategories'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function formatPrice(Request $request)
    {
        $price = $request->price;
        $store_id = $request->store_id;
        
        return response()->json([
            'formatted_price' => currency_format_with_sym($price, $store_id)
        ]);
    }
}