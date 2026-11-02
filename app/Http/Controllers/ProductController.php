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
use App\Support\UploadRules;
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
     * F195 / D-28c -- A ONE-LINE DELEGATE TO `VariantRemovalPolicy::refuse()`.
     *
     * The checks themselves, and the long account of why each one is shaped the way it is, moved
     * into `App\Services\VariantRemovalPolicy` so that `Api\DashboardController::DeleteVariant()`
     * asks the SAME question rather than a second copy of it -- the owner's *"no duplicated
     * reference-check implementation"*. The wrapper stays because sixteen tests drive it through
     * `update()` and because the call sites read better naming the local concern.
     *
     * @param  \Illuminate\Support\Collection  $removed  the variant rows the posted set no longer covers
     * @return array<string,mixed>|null
     */
    private function ssRefuseVariantRemoval($product, $removed, bool $confirmed, ?array $consent = null): ?array
    {
        return \App\Services\VariantRemovalPolicy::refuse($product, $removed, $confirmed, $consent);
    }

    /**
     * THE COMBINATION NAMES A POSTED `choice_no` / `choice_options_N` PAYLOAD COVERS. (F196)
     *
     * Extracted verbatim from `update()`'s choice branch so the PREFLIGHT and the in-place check cannot
     * disagree about what is being removed. Two copies of this arithmetic is how a guard and the delete
     * it guards end up asking different questions -- which this file has already recorded once, when
     * the attribute path's detection used `getCurrentStore()` while its delete used none.
     *
     * @return list<string>
     */
    private function ssChoiceCombinationNames(Request $request): array
    {
        $options = [];

        if ($request->has('choice_no')) {
            foreach ((array) $request->choice_no as $no) {
                $name = 'choice_options_' . $no;

                if (! $request->has($name)) {
                    continue;
                }

                $options[] = explode(',', implode('|', (array) $request[$name]));
            }
        }

        if ($options === []) {
            return [];
        }

        $names = [];

        foreach ($this->combinations($options) as $combination) {
            $name = '';

            foreach ((array) $combination as $index => $item) {
                $name .= ($index > 0 ? '-' : '') . str_replace(' ', '', (string) $item);
            }

            /*
             * F199 -- THE EMPTY NAME IS KEPT, AND THAT IS A SECURITY FIX RATHER THAN TIDYING.
             *
             * The code this was extracted from filtered it out (`if ($ssName !== '')`, and the same
             * filter is in ba34fce8, so this defect PRE-DATES F196 rather than arriving with it).
             * The filter made detection and deletion disagree about the same payload:
             *
             *   choice_options_1[] = ''   ->  implode('|', ['']) = ''  ->  explode(',', '') = ['']
             *                             ->  $options = [['']]        ->  combinations = [[0 => '']]
             *
             *   the GUARD built the name '', dropped it, and saw an EMPTY covered set -- which means
             *   "nothing was posted, nothing to check", because that is how the ordinary
             *   no-`choice_no` save is skipped;
             *
             *   the DELETE, meanwhile, is gated on `count($combinations[0]) > 0` -- computed from the
             *   UNFILTERED build, so 1 -- and ran `whereNotIn('variant', [''])`, which matches every
             *   real row.
             *
             * THE MEASURED OUTCOME is HTTP 200 with `{"flag":"success","msg":"Product update
             * successfully."}` and the product's four variants -- prices, SKUs and stock --
             * replaced by one row named ''. On SQLite and on MariaDB 10.11 alike. D-20 condition 5
             * is "no silent hard delete"; this was one.
             *
             * ONE HYPOTHESIS ABOUT THE MECHANISM WAS WRONG AND IS RECORDED SO IT IS NOT RE-RUN.
             * The first reading of this was that it needed production's `'strict' => false`
             * (`config/database.php:55`) to coerce a NULL `price` into the NOT NULL `double`.
             * MEASURED ON MariaDB 10.11: an explicit NULL into a NOT NULL column is refused as
             * error 1048 on BOTH settings -- non-strict mode coerces out-of-range values, not
             * explicit NULLs -- so that route 500s and deletes nothing. The real mechanism needs no
             * engine quirk: the payload simply also supplies the CHOICE path's own `sku_`, `price_`
             * and `stock_` keys, whose suffix for an empty combination name is empty. The insert
             * then succeeds normally and the delete follows.
             *
             * Keeping the name makes the covered set mirror `$sku_array` EXACTLY, so the guard and
             * the delete answer the same question: a payload posting one blank combination is read
             * as posting one blank combination, every other row is in the removal set, and the
             * merchant gets the refusal naming them. The ordinary "no `choice_no` posted" case is
             * still skipped, because it is distinguished by `$options === []` above -- by nothing
             * having been posted, not by what was posted being blank.
             *
             * Found by the mandated independent review of the F196 reordering.
             */
            $names[] = $name;
        }

        return $names;
    }

    /**
     * THE COMBINATION NAMES A POSTED ATTRIBUTE / `options_datas` PAYLOAD COVERS. (F196)
     *
     * The same extraction, for the other rebuild. `options_datas` is a flat list of combination names
     * and the loop over `attribute_no` only decides WHETHER it counts -- a term is included when its
     * `for_variation_<no>` is 1 -- so the result is the unique set of posted names.
     *
     * @return list<string>
     */
    private function ssAttributeCombinationNames(Request $request): array
    {
        $posted = [];

        if ($request->attribute_no) {
            foreach ((array) $request->attribute_no as $no) {
                if ($request->input('for_variation_' . $no) != 1) {
                    continue;
                }

                if (! $request->has('options_datas') || ! is_array($request['options_datas'])) {
                    continue;
                }

                foreach ($request['options_datas'] as $item) {
                    $posted[] = (string) $item;
                }
            }
        }

        return array_values(array_unique($posted));
    }

    /**
     * F195 / D-28c -- a one-line delegate to `VariantRemovalPolicy::variantsBeingRemoved()`.
     *
     * `$product->store_id` AND NOT `getCurrentStore()`, for the reason the policy records: the
     * helper hands a `super admin` with an empty `current_store` the hardcoded literal 1, so for
     * an operator editing a store-7 product the query found no variants, the removal set came back
     * empty, the refusal returned null -- and the delete, which carries no store predicate of its
     * own, removed the real rows anyway. Detection and deletion now ask one question in one place.
     *
     * @param  list<string>  $coveredNames
     */
    private function ssVariantsBeingRemoved($product, array $coveredNames)
    {
        return \App\Services\VariantRemovalPolicy::variantsBeingRemoved($product, $coveredNames);
    }

    /**
     * F196 / D-28b -- THE REFUSAL NOW HAPPENS BEFORE ANYTHING IS PERSISTED OR UPLOADED.
     *
     * WHAT WAS WRONG, measured in `update()`'s attribute path:
     *
     *   1. the merchant posts a narrower term set;
     *   2. the attribute branch assigns the rebuilt json to `$product->product_attribute` and,
     *      unconditionally, `$product->price = 0` and `$product->product_stock = 0`;
     *   3. `$product->save()` persists all of it;
     *   4. only then does the removal guard refuse -- saying *"Nothing has been changed."*, which
     *      was FALSE about seven measured columns: `price` 10 -> 0, `product_stock` 7 -> 0,
     *      `description` 'x' -> NULL, plus the slug, the category, a re-encoded
     *      `product_attribute`, and `updated_at`;
     *   5. the merchant confirms, and the form still carries the `ss_last_seen_at` it was rendered
     *      with;
     *   6. the optimistic-lock guard compares `$product->updated_at` to that token, finds they differ
     *      BECAUSE OF THE MERCHANT'S OWN REFUSED SAVE, and answers *"Someone else saved this product
     *      while you were editing it"* -- blaming a person who does not exist.
     *
     * So F184's confirm-and-retry was not merely unbuilt, it was impossible, and the merchant was told
     * two false things on the way. The owner's D-28b approves moving the check ahead of persistence and
     * REJECTS the alternative of leaving the ordering and issuing a fresh token.
     *
     * WHY A SEPARATE PREFLIGHT AND NOT A MOVED LINE, which the owner's §3.2 asks about directly: the
     * uploads come FIRST. `update()` replaces `cover_image_path` -- and the same branch calls
     * `StoredUpload::delete()` on the OLD path, so it destroys a file -- then `preview_video`, then
     * `downloadable_product`, then creates a `ProductImage` row, and only then reaches either
     * variant branch; `$product->save()` runs inside each branch. Moving one guard up a few lines
     * would still leave a refusal standing behind four uploads and a file deletion. This runs
     * before all of them.
     *
     * NO LINE NUMBERS IN THIS BLOCK ON PURPOSE: adding these helpers moved every line in `update()`
     * by about 190, and a comment citing a line is wrong the moment anyone edits above it. The
     * ordering is asserted against comment-stripped source instead, by
     * ProductUpdateRefusalPersistsNothingTest::test_the_removal_preflight_runs_before_every_upload_and_save_in_update().
     *
     * BOTH REBUILDS ARE COVERED, not just the one D-20 named. Each posted set is checked independently
     * against the rows it would remove, which is exactly what the two in-place guards do -- and those
     * guards STAY, as defence in depth and because the sixteen tests that drive them through `update()`
     * keep passing unchanged. A preflight that silently replaced them would be a bigger change with no
     * extra safety.
     *
     * THE OPTIMISTIC LOCK IS UNTOUCHED AND IS CHECKED FIRST. This runs after it, so another person's
     * edit is still refused as another person's edit; the owner's condition that `ss_last_seen_at` must
     * not be bypassed to make a confirmation succeed is met by not touching it at all. With the
     * preflight in place `updated_at` no longer moves on a refused save, so the retry meets the lock
     * only when somebody really did save.
     */
    /**
     * F205 — WHETHER THIS REQUEST CARRIED A VALID SERVER-ISSUED CONSENT.
     *
     * Written by `ssVariantRemovalPreflight()`, which is the authority: it runs BEFORE any write
     * (that is F196's whole point), and the token is **single use**, so it is consumed there and
     * cannot be verified a second time further down. The two in-place guards read this rather than
     * re-verifying — not because re-checking would be wrong in principle, but because a second
     * `verify()` on a consumed token would answer false and refuse a save the server had already
     * authorised.
     *
     * Default false, so a path that somehow reaches a guard without the preflight having run is
     * treated as unconfirmed rather than as permitted.
     */
    private bool $ssRemovalConsented = false;

    private function ssVariantRemovalPreflight(Request $request, $product): ?array
    {
        if ($request->variant_product == 0) {
            return null;
        }

        /*
         * F205 [2026-10-30] -- A SERVER-ISSUED CONSENT, NOT `$request->boolean()`.
         *
         * The boolean was satisfied by '1', 'true', 'yes' or 'on', so the one door that deletes
         * MANY variants at once had the weakest signal of the three: the two per-variant doors
         * already require the variant NAME echoed back, which a caller can only produce by having
         * read the row. The owner's decision closes that asymmetry.
         *
         * The token is verified against THIS principal, THIS product and THIS exact removal set,
         * so the check has to happen per covered set rather than once up front -- a token issued
         * for the choice path must not authorise the attribute path's different set.
         */
        $ssConsent = $request->input(\App\Services\VariantRemovalConsent::FIELD);
        $ssActor = auth()->user();

        $ssSets = [];

        foreach ([
            $this->ssChoiceCombinationNames($request),
            $this->ssAttributeCombinationNames($request),
        ] as $covered) {
            if ($covered === []) {
                continue;
            }

            $ssSets[] = $this->ssVariantsBeingRemoved($product, $covered);
        }

        /*
         * THE CONSENT BINDS THE UNION, NOT ONE PATH'S SET — and that is a correction a test found
         * rather than a refinement.
         *
         * A request can carry BOTH a choice set and an attribute set, and each path's removal set
         * contains rows the other keeps. Binding a token to the first set meant the retry was
         * verified against the choice path, matched, and was then refused by the attribute path,
         * whose set the token did not cover — so a legitimate confirmed save could never complete.
         * `test_both_posted_sets_with_a_confirmation_are_still_allowed_through` is the case.
         *
         * The union is also the honest reading of the owner's *"the variant IDs that will be
         * deleted"*: what the merchant is agreeing to is the effect of the whole request, not of
         * one branch of it. Keyed by id so a row reached by both paths is counted once.
         */
        $ssUnion = collect($ssSets)
            ->flatten(1)
            ->keyBy(static fn ($v) => (int) $v->id)
            ->values();

        $ssConsented = $ssUnion->isNotEmpty()
            && \App\Services\VariantRemovalConsent::verify($ssConsent, $ssActor, $product, $ssUnion);

        $this->ssRemovalConsented = $ssConsented;

        foreach ($ssSets as $ssRemoved) {
            $refusal = $this->ssRefuseVariantRemoval($product, $ssRemoved, $ssConsented);

            if ($refusal !== null) {
                /*
                 * ISSUE A CONSENT ONLY FOR THE BRANCH CONSENT CAN ANSWER. A hard block -- a cart
                 * or wishlist reference, an order naming the product, an unresolvable store --
                 * carries `blocked`, and handing those a token would say the merchant could
                 * proceed by confirming. They cannot: D-20's blocks refuse regardless of consent,
                 * and `refuse()` reaches the consent branch only after every one of them.
                 *
                 * Issuing here rather than inside the policy keeps `refuse()` free of side
                 * effects: it answers a question, it does not write a record.
                 */
                if (empty($refusal['ss_variant_removal']['blocked'])) {
                    $refusal['ss_variant_removal'] = array_merge(
                        $refusal['ss_variant_removal'] ?? [],
                        \App\Services\VariantRemovalConsent::issue($ssActor, $product, $ssUnion)
                    );
                }

                return $refusal;
            }
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
                                $path_video = Utility::upload_file($request, 'preview_video', $fileName, $dir_video, UploadRules::for('preview_video'));
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
                                $path = Utility::upload_file($request, 'downloadable_product', $fileName, $dir, UploadRules::for('downloadable_product'));
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
                                $path_video = Utility::upload_file($request, 'preview_video', $fileName, $dir_video, UploadRules::for('preview_video'));
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
                                $path = Utility::upload_file($request, 'downloadable_product', $fileName, $dir, UploadRules::for('downloadable_product'));
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

                                            $path1 = Utility::upload_file($request, 'downloadable_product_' . $str, $fileName, $dir, UploadRules::for('downloadable_product'));
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
       
        /*
         * F195 / D-28c -- THE PERMISSION IS READ AGAINST THE TARGET PRODUCT'S STORE.
         *
         * THIS GATE WAS `auth()->user()->isAbleTo('Edit Products')`, AND THAT IS A UNION ACROSS
         * EVERY STORE THE USER HOLDS A ROLE IN. `isAbleTo()` resolves through Laratrust's
         * `allPermissions()` -- `$this->roles()->with('permissions')->get()` flat-mapped -- with no
         * store predicate anywhere in it, because the team feature that would scope it is disabled.
         * So a staff member whose only `Edit Products` grant is a role stamped with store B
         * (`RoleController` stamps new roles with `getCurrentStore()`) answered TRUE for store A,
         * and the ownership check below could not catch them when their `current_store` really WAS
         * store A. They could then post a narrower option set and hard-delete store A's variants,
         * subject only to the D-20 reference refusals -- which are not an authorization control and
         * say so.
         *
         * FOUND BY THE MANDATED REVIEW OF THE F195 COMPLETION, which also found that the test I had
         * written for this door could not fail: its payload omitted `category_id`, which is
         * `required` in both rule branches, so the validator refused it before authorization
         * decided anything and the case was green against the live defect.
         *
         * `MerchantPermissions::allowsAny()` reads the permission against THIS product's store and
         * answers the platform operator with the wildcard, which `isAbleTo()` alone refuses. That
         * changes the operator's treatment on this door from "must hold a role granting
         * Edit Products" to "may edit", which is this codebase's standing operator rule and what
         * `product_attribute_delete()` and the API door already do.
         */
        $ssActor = auth()->user();

        /*
         * F206 [2026-10-30] -- THE PLATFORM OPERATOR DOES NOT SAVE A MERCHANT'S PRODUCT.
         *
         * This is the door F195 opened: replacing `isAbleTo()` (which refuses the operator
         * outright, there being no super-admin bypass in Laratrust) with the store-scoped
         * `MerchantPermissions` read made the `PLATFORM_ADMIN` exemption in the ownership block
         * below REACHABLE for the first time. The repository's own MariaDB script recorded that as
         * a capability change rather than an accident, and the owner has now decided the
         * capability: read and diagnose, yes; write, no.
         *
         * Measured before the fix, driving the real route: `{"flag":"success"}`, the name
         * rewritten, and the **price rewritten from 100 to 1**.
         *
         * THE SHAPE MATTERS AS MUCH AS THE REFUSAL. `edit.blade.php` submits by `$.ajax` and its
         * success handler reads `data.flag` / `data.msg` WITHOUT navigating, so a
         * `redirect()->back()` reaches the merchant as a blank toast. The conflict-detection
         * refusal further down this method already documents the same constraint.
         */
        if (\App\Services\OperatorCatalogueWrite::refuses($ssActor)) {
            return \App\Services\OperatorCatalogueWrite::ajaxRefusal();
        }

        if (
            $ssActor !== null
            && \App\Services\MerchantPermissions::allowsAny($ssActor, ['Edit Products'], $product->store_id)
        ) {
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

            /*
             * F196 / D-28b. THE VARIANT-REMOVAL REFUSAL, BEFORE ANY UPLOAD AND BEFORE ANY SAVE.
             *
             * THE ORDER HERE IS THE WHOLE FIX, and each neighbour is deliberate:
             *
             *   - AFTER the ownership and permission checks, so a caller who may not edit this
             *     product is still refused as unauthorised and learns nothing about its variants;
             *   - AFTER the `ss_last_seen_at` conflict guard, so another person's edit is still
             *     refused as another person's edit. The owner's condition is that the token must
             *     not be bypassed to make a confirmation succeed, and it is not touched at all --
             *     what changes is that the merchant's own REFUSED save no longer moves
             *     `updated_at` and so no longer manufactures the conflict;
             *   - AFTER validation, so a malformed payload is answered with its validation error
             *     rather than with a removal refusal;
             *   - and BEFORE everything that writes: the storage-limit reservation, the four
             *     uploads, the old cover image's deletion from storage, the `ProductImage` row,
             *     and both `$product->save()` calls.
             *
             * MEASURED, not assumed. Before this, a refused narrowing persisted seven columns of
             * `products` -- `price` 10 -> 0, `product_stock` 7 -> 0, `description` 'x' -> NULL,
             * the slug, the category, a re-encoded `product_attribute` and `updated_at` -- and
             * then returned a message saying *"Nothing has been changed."* Two more side effects
             * are outside that row and so outside what a row comparison can see:
             * `Utility::updateStorageLimit()` does `$user->storage_limit += $size; $user->save()`,
             * charging the merchant's quota for a file they never got to keep, and the cover-image
             * branch calls `StoredUpload::delete()` on the OLD path -- a file no rollback
             * recovers. Both were found by the mandated review rather than by the comparison.
             */
            $ssPreflightRefusal = $this->ssVariantRemovalPreflight($request, $product);

            if ($ssPreflightRefusal !== null) {
                return $ssPreflightRefusal;
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
                    $path_video = Utility::upload_file($request, 'preview_video', $fileName, $dir_video, UploadRules::for('preview_video'));
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
                    $path = Utility::upload_file($request, 'downloadable_product', $fileName, $dir, UploadRules::for('downloadable_product'));
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
                                /*
                                 * F168 -- THE TERM RESOLVES WITHIN THIS PRODUCT'S OWN STORE.
                                 *
                                 * `ProductAttributeOption::where('terms', $fValue)->first()` was
                                 * the whole predicate. The model registers no `StoreScope` and is
                                 * not in `config/tenancy.php`'s `scoped_models`, so that matched
                                 * the WHOLE TABLE, and with no `orderBy` an unordered `first()`
                                 * returns the lowest id -- the OLDEST option. The resolved ids are
                                 * then PERSISTED, as
                                 * `$product->product_attribute = json_encode(...)`.
                                 *
                                 * So the older store won. Attribute terms are generic by nature --
                                 * `Red`, `M`, `Natural` -- so two stores sharing one is the normal
                                 * case, not a collision, and whichever store created its option
                                 * first owned the id every other store's products were written
                                 * with. MEASURED: with a foreign `Red` created before the
                                 * merchant's own, the save stored the FOREIGN id.
                                 *
                                 * It is not invisible either. The edit screen reads those ids back
                                 * as `whereIn('id', $idsArray)->pluck('terms')`
                                 * (edit.blade.php:544-550), so while the words agree the page
                                 * looks right -- and then the other tenant renames their option
                                 * and this merchant's product shows the other store's word, or
                                 * deletes it and the attribute disappears from this product's
                                 * form. A tenant's catalogue structure should not depend on a row
                                 * in another tenant's catalogue.
                                 *
                                 * The sibling `attribute_combination()` already carries this
                                 * predicate for this same model (P1-INV-10 (c)); these two sites
                                 * were missed because they resolve the other way round -- term to
                                 * id rather than id to term.
                                 *
                                 * THE PRODUCT'S STORE, NOT `getCurrentStore()`: `update()` has
                                 * already proved the caller owns this product (P1-INV-01), and an
                                 * option linked to a product must be its own store's. The helper
                                 * hands a `super admin` with an empty `current_store` the
                                 * hardcoded literal `1`, so an operator editing another store's
                                 * product would resolve against store 1 -- the F157 trap.
                                 *
                                 * An empty `store_id` resolves NOTHING rather than everything:
                                 * `where('store_id', null)` becomes `whereNull`, which matches
                                 * every store-less option row.
                                 *
                                 * A term that does not resolve contributes no id, which is the
                                 * shape this loop already produced for any unresolved term.
                                 */
                                $option = empty($product->store_id)
                                    ? null
                                    : ProductAttributeOption::where('terms', $fValue)
                                        ->where('store_id', $product->store_id)
                                        ->first();

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
                                /*
                                 * F168 -- THE TERM RESOLVES WITHIN THIS PRODUCT'S OWN STORE.
                                 *
                                 * `ProductAttributeOption::where('terms', $fValue)->first()` was
                                 * the whole predicate. The model registers no `StoreScope` and is
                                 * not in `config/tenancy.php`'s `scoped_models`, so that matched
                                 * the WHOLE TABLE, and with no `orderBy` an unordered `first()`
                                 * returns the lowest id -- the OLDEST option. The resolved ids are
                                 * then PERSISTED, as
                                 * `$product->product_attribute = json_encode(...)`.
                                 *
                                 * So the older store won. Attribute terms are generic by nature --
                                 * `Red`, `M`, `Natural` -- so two stores sharing one is the normal
                                 * case, not a collision, and whichever store created its option
                                 * first owned the id every other store's products were written
                                 * with. MEASURED: with a foreign `Red` created before the
                                 * merchant's own, the save stored the FOREIGN id.
                                 *
                                 * It is not invisible either. The edit screen reads those ids back
                                 * as `whereIn('id', $idsArray)->pluck('terms')`
                                 * (edit.blade.php:544-550), so while the words agree the page
                                 * looks right -- and then the other tenant renames their option
                                 * and this merchant's product shows the other store's word, or
                                 * deletes it and the attribute disappears from this product's
                                 * form. A tenant's catalogue structure should not depend on a row
                                 * in another tenant's catalogue.
                                 *
                                 * The sibling `attribute_combination()` already carries this
                                 * predicate for this same model (P1-INV-10 (c)); these two sites
                                 * were missed because they resolve the other way round -- term to
                                 * id rather than id to term.
                                 *
                                 * THE PRODUCT'S STORE, NOT `getCurrentStore()`: `update()` has
                                 * already proved the caller owns this product (P1-INV-01), and an
                                 * option linked to a product must be its own store's. The helper
                                 * hands a `super admin` with an empty `current_store` the
                                 * hardcoded literal `1`, so an operator editing another store's
                                 * product would resolve against store 1 -- the F157 trap.
                                 *
                                 * An empty `store_id` resolves NOTHING rather than everything:
                                 * `where('store_id', null)` becomes `whereNull`, which matches
                                 * every store-less option row.
                                 *
                                 * A term that does not resolve contributes no id, which is the
                                 * shape this loop already produced for any unresolved term.
                                 */
                                $option = empty($product->store_id)
                                    ? null
                                    : ProductAttributeOption::where('terms', $fValue)
                                        ->where('store_id', $product->store_id)
                                        ->first();

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
                        $path_video = Utility::upload_file($request, 'preview_video', $fileName, $dir_video, UploadRules::for('preview_video'));
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

                /*
                 * THE SECOND NARROWING PATH, and until now it ran with NO guard at all.
                 *
                 * `update()` has two complete variant rebuilds. The attribute path below is the
                 * one D-20 governs. THIS one -- driven by `choice_no` / `choice_options_N` --
                 * deleted every uncovered row with no store predicate, no reference check, no
                 * confirmation and no message. `edit.blade.php` posts no `choice_no`, so no
                 * browser reaches it; nothing validates it away either, so a merchant holding
                 * `Edit Products` can hand-post the fields and destroy their own product's
                 * variants with the guard never running.
                 *
                 * The owner's D-20 condition 5 is "if the delete's safety is not established,
                 * refuse with a clear message. No silent hard delete." A silent hard delete is
                 * exactly what this was. Confined to the caller's own tenant by the ownership
                 * check at the top of `update()`, which is why it was a guard bypass rather than
                 * a cross-tenant hole.
                 *
                 * IT RUNS BEFORE THE UPSERT LOOP, like the attribute path's, and for the owner's
                 * own reason: a refusal must leave every row exactly as it was rather than
                 * half-upserting the survivors and then refusing. The combination names are known
                 * here -- `$combinations` is already built -- so the removal set can be computed
                 * before anything is written.
                 *
                 * Found by the mandated review of the attribute path's store-predicate fix.
                 */
                /* F196: one copy of the arithmetic, shared with the preflight above. */
                $ssChoiceCovered = $this->ssChoiceCombinationNames($request);

                if ($ssChoiceCovered !== []) {
                    $ssChoiceRemoved = $this->ssVariantsBeingRemoved($product, $ssChoiceCovered);

                    /* F205: the preflight's verdict, not a second verification — the token is
                       single use and was consumed there, so re-verifying would answer false and
                       refuse a save the server had already authorised. The preflight is the
                       authority because it is the only check that runs before any write. */
                    $ssChoiceRefusal = $this->ssRefuseVariantRemoval($product, $ssChoiceRemoved, $this->ssRemovalConsented);

                    if ($ssChoiceRefusal !== null) {
                        return $ssChoiceRefusal;
                    }
                }

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
                    /* F205: the references are re-checked with the rows LOCKED, inside one
                       transaction with the delete — see VariantRemovalPolicy::deleteGuarded().
                       The consent token binds the variants' own state and cannot see a cart row
                       arriving after the merchant confirmed, so that window is closed here rather
                       than assumed away. A reference that appears REFUSES; nothing is destroyed. */
                    $ssDoomed = ProductVariant::withoutGlobalScopes()
                        ->where('product_id', $product->id)
                        ->where('store_id', $product->store_id)
                        ->whereNotIn('variant', $sku_array)
                        ->pluck('id')->map(fn ($id) => (int) $id)->all();

                    $ssGuarded = \App\Services\VariantRemovalPolicy::deleteGuarded(
                        $product,
                        $ssDoomed,
                        function () use ($product, $sku_array) {
                            ProductVariant::where('product_id', $product->id)
                                ->where('store_id', $product->store_id)
                                ->whereNotIn('variant', $sku_array)
                                ->delete();
                        }
                    );

                    if ($ssGuarded !== null) {
                        return $ssGuarded;
                    }

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
                        /* F196: one copy of the arithmetic, shared with the preflight above. */
                        $ssPostedCombinations = $this->ssAttributeCombinationNames($request);

                        /*
                         * THE PRODUCT'S STORE, NOT THE CALLER'S — and this one decided whether
                         * the refusal ran at all. `getCurrentStore()` returns the HARDCODED
                         * LITERAL 1 for a `super admin` with an empty `current_store`
                         * (`helper.php:636`), so for an operator editing a store-7 product this
                         * query found NO variants, `$ssRemovedVariants` came back empty, the
                         * refusal returned null — and the delete below, which carries no store
                         * predicate of its own, removed the real rows anyway.
                         *
                         * Detection and deletion were asking different questions. They now ask the
                         * same one. Measured by driving an operator with an emptied current_store
                         * against a product in a second tenant.
                         */
                        $ssRemovedVariants = $this->ssVariantsBeingRemoved($product, $ssPostedCombinations);

                        /* F205: the preflight's verdict, as the choice guard above. */
                        $ssRemovalRefusal = $this->ssRefuseVariantRemoval($product, $ssRemovedVariants, $this->ssRemovalConsented);

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

                                    $path1 = Utility::upload_file($request, 'downloadable_product_' . $str, $fileName, $dir, UploadRules::for('downloadable_product'));
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

                                /*
                                 * THE PRODUCT'S STORE, NOT THE CALLER'S -- and this one WRITES, so it is the more
                                 * serious half of the same defect the detection query had.
                                 *
                                 * The upsert above resolves the row by `product_id` + `variant` with no store
                                 * predicate, so this line REWRITES `product_variants.store_id` on EXISTING rows. For
                                 * an operator -- `getCurrentStore()` returns the hardcoded literal 1 for a
                                 * `super admin` with an empty `current_store` (`helper.php:636`) -- an ordinary no-op
                                 * save of a store-7 product stamped every one of its variants with store 1 while
                                 * `products.store_id` stayed 7.
                                 *
                                 * From that point the variants disagree with their product and every store-scoped
                                 * reader stops seeing them: the D-20 detection above, `Api\DashboardController::
                                 * UpdateVariant`'s `where('store_id', $store->id)`, and the store teardown's
                                 * `ProductVariant::where('store_id', ...)->delete()` -- so a drifted row survives the
                                 * deletion of its own store.
                                 *
                                 * Found by the mandated review of the detection fix. `store()`'s own equivalent at
                                 * :1332 is NOT changed: there the product is being created by this caller, so the
                                 * caller's store IS the product's. Writing the product's store here also self-heals a
                                 * drifted row on the next save of any covered combination.
                                 */
                                $product_stock->store_id = $product->store_id;

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
                            /* The same predicate the detection above uses, so the rows that were
                               examined are exactly the rows that are removed. `ProductVariant` is
                               not in `config/tenancy.php`'s `scoped_models`, so this `where` is
                               the only control there is. */
                            /* F205: as the choice path above — locked re-check and delete in one
                               transaction, refusing rather than destroying if a reference
                               appeared after the merchant confirmed. */
                            $ssDoomed = ProductVariant::withoutGlobalScopes()
                                ->where('product_id', $product->id)
                                ->where('store_id', $product->store_id)
                                ->whereNotIn('variant', $com)
                                ->pluck('id')->map(fn ($id) => (int) $id)->all();

                            $ssGuarded = \App\Services\VariantRemovalPolicy::deleteGuarded(
                                $product,
                                $ssDoomed,
                                function () use ($product, $com) {
                                    ProductVariant::where('product_id', $product->id)
                                        ->where('store_id', $product->store_id)
                                        ->whereNotIn('variant', $com)
                                        ->delete();
                                }
                            );

                            if ($ssGuarded !== null) {
                                return $ssGuarded;
                            }
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
        /*
         * F175 -- PERMISSION, WHICH THIS METHOD DID NOT HAVE AT ALL.
         *
         * It is the renderer the product editor injects from `$(document).ready` and the one the
         * SHOW screen loads, so it is the variant panel a merchant sees FIRST -- and it read
         * `ProductVariant::where('product_id', $request->id)` with no gate above it. Every
         * sibling on this controller carries one: `attribute_combination_edit()` takes
         * `Manage Products` OR `Edit Products`, `product_attribute_delete()` and `file_delete()`
         * take `Edit Products`. This one was missed.
         *
         * WHAT THAT EXPOSED is a merchant's commercial data -- every variant's price, sale price,
         * stock, SKU, low-stock threshold and shipping, rendered in full -- to any authenticated
         * principal in the `web` group, including a staff member hired for support or content
         * with no product permission whatsoever.
         *
         * `Manage Products` OR `Edit Products`, AND THE REASON IS IN THE TWO CALLERS rather than
         * in a preference. `resources/views/product/show.blade.php:743` loads this endpoint and
         * `ProductController::show()` is gated on `Manage Products`, so gating the data it needs
         * on `Edit Products` alone would break the read-only screen for a role that is allowed to
         * use it. The sibling records the same reasoning for the same pair of screens. This grants
         * nobody a new capability: both names already exist and both already open a screen that
         * displays this data.
         *
         * THE REFUSAL RENDERS AN EMPTY PANEL, 200, AND THAT IS A CONTRACT DECISION. Both callers
         * do `$('...').html(data)` with NO `error` handler (`edit.blade.php:1034`,
         * `show.blade.php:743`), and `$('#loader').fadeOut()` lives only in `success` -- so a 403
         * would leave the container untouched and a loader spinning for ever. An empty render
         * keeps the contract exactly, discloses nothing, and is indistinguishable from "this
         * product has no variants", which is the safe direction for an unauthorised reader to be
         * unable to tell apart. The refusal is recorded in the log instead, as every other
         * ownership refusal on this controller is.
         */
        $refuse = fn (): \Illuminate\Contracts\View\View => view('product.attribute_combinations_data', [
            'product_stock' => collect(),
            'Shipping' => collect()->prepend('Same as parent', ''),
            'variantImages' => collect(),
        ]);

        if ($this->lacksProductPermission(['Manage Products', 'Edit Products'])) {
            return $refuse();
        }

        /*
         * OWNERSHIP ON A PRODUCT NAMED BY REQUEST INPUT, resolved through the PRODUCT -- not by
         * filtering variants on `product_variants.store_id`, which is what this did.
         *
         * That column is nullable and S36 records that it "is not the authority on who owns it",
         * and the predicate it fed had two further edges. For a principal with no store
         * `getCurrentStore()` returns null and `where('store_id', null)` becomes `whereNull`,
         * matching every STORE-LESS variant row rather than nothing -- fail-open. And for the
         * platform operator the helper returns the hardcoded literal `1` (helper.php:636-637), so
         * the operator was served STORE 1's variants for any product id, which is the F157 trap:
         * not a leak, but a confident wrong answer about somebody's catalogue.
         *
         * Resolving the product first and then scoping variants by `product_id` fixes all three
         * at once, and is also what makes the response contain only the requested product's
         * variants.
         */
        $product = Product::find($request->id);

        if (empty($product) || $this->productIsOutsideCurrentStore($product)) {
            \Illuminate\Support\Facades\Log::warning(
                'variant panel refused: the product is missing or belongs to another store',
                [
                    'product_id' => $request->id,
                    'product_store_id' => $product->store_id ?? null,
                    'current_store' => getCurrentStore(),
                    'user_id' => optional(auth()->user())->id,
                ]
            );

            return $refuse();
        }

        $product_stock = ProductVariant::where('product_id', $product->id)->get();
        $Shipping = Shipping::where('store_id', $product->store_id)->pluck('name', 'id')->prepend('Same as parent', '');

        /*
         * D-26 -- each variant's one image, resolved once and keyed by variant id.
         *
         * KEYED RATHER THAN LOOKED UP IN THE VIEW, which the sibling views do for attribute
         * options. A per-panel query would be one round trip per variant, and this renderer
         * routinely draws eight or more; the edit page injects it from `$(document).ready`, so
         * the cost lands on every page load rather than on an interaction.
         *
         * Narrowed by the VARIANT IDS ALREADY RESOLVED above, not by `$request->id`: those ids
         * belong to a product whose ownership has been proved, so the media lookup inherits that
         * rather than repeating a weaker version of it.
         */
        $variantImages = ProductImage::whereIn('variant_id', $product_stock->pluck('id'))
            ->whereNotNull('variant_id')
            ->get()
            ->keyBy('variant_id');

        return view('product.attribute_combinations_data', compact('product_stock', 'Shipping', 'variantImages'));
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
        /*
         * F206 [2026-10-30] -- the platform operator does not write a merchant's catalogue media.
         *
         * Placed at the WRITER rather than inside `lacksProductPermission()` or
         * `productIsOutsideCurrentStore()`, both of which carry operator limbs: the read paths the
         * owner wants preserved (`attribute_combination_data`, `attribute_combination_edit`,
         * `show`, `product_preview`) share those two helpers with these three writers, so
         * narrowing a helper would have closed the diagnosis surface too.
         *
         * These are irreversible disk writes — `StoredUpload` unlinks the file. Whether catalogue
         * MEDIA falls under the owner's "products, prices, stock, variants" is a judgement call,
         * and it is made toward blocking and recorded as such in the ledger, because deleting a
         * merchant's image is not diagnosis. It is a one-line reversal if the owner wants it.
         */
        if (\App\Services\OperatorCatalogueWrite::refuses(auth()->user())) {
            return response()->json([
                'flag' => 'error',
                'msg' => \App\Services\OperatorCatalogueWrite::message(),
            ], 403);
        }

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

    /**
     * D-26 — THE ONE IMAGE A PRODUCT VARIANT MAY CARRY.
     *
     * Owner-approved for Phase 02: one image per variant, reusing the existing media model, the
     * Gallery Upload path and `UploadLimits`; add, replace and remove; bound to the correct
     * variant and the correct store; without losing a variant's price, stock or SKU and without
     * changing its identity.
     *
     * NOTHING HERE TOUCHES `product_variants`. The image lives on `product_images.variant_id`,
     * so a media operation cannot write to the row that holds the variant's money and stock —
     * which is the whole reason the column went there. The variant row is READ, to establish
     * ownership, and never written.
     *
     * ONE IMAGE PER VARIANT IS ENFORCED BY REPLACING, not by a unique index: see the migration's
     * docblock for why an index that Phase 03 would have to drop is not cheap.
     */
    public function variant_image_store(Request $request)
    {
        /*
         * PERMISSION FIRST, BEFORE THE VARIANT IS LOOKED UP. P1-INV-10 (e). `Edit Products`,
         * matching `file_delete()`: this writes media onto an EXISTING product's variant and the
         * only caller is the edit screen. Placed ahead of the lookup so a caller without the
         * permission cannot learn from the response whether a variant id exists.
         */
        /*
         * F206 [2026-10-30] -- the platform operator does not write a merchant's catalogue media.
         *
         * Placed at the WRITER rather than inside `lacksProductPermission()` or
         * `productIsOutsideCurrentStore()`, both of which carry operator limbs: the read paths the
         * owner wants preserved (`attribute_combination_data`, `attribute_combination_edit`,
         * `show`, `product_preview`) share those two helpers with these three writers, so
         * narrowing a helper would have closed the diagnosis surface too.
         *
         * These are irreversible disk writes — `StoredUpload` unlinks the file. Whether catalogue
         * MEDIA falls under the owner's "products, prices, stock, variants" is a judgement call,
         * and it is made toward blocking and recorded as such in the ledger, because deleting a
         * merchant's image is not diagnosis. It is a one-line reversal if the owner wants it.
         */
        if (\App\Services\OperatorCatalogueWrite::refuses(auth()->user())) {
            return response()->json([
                'flag' => 'error',
                'msg' => \App\Services\OperatorCatalogueWrite::message(),
            ], 403);
        }

        if ($this->lacksProductPermission(['Edit Products'])) {
            return response()->json([
                'flag' => 'error',
                'msg' => __('Permission denied.'),
            ], 403);
        }

        $variant = ProductVariant::find($request->variant_id);

        /*
         * OWNERSHIP THROUGH THE VARIANT'S PRODUCT, NEVER THROUGH THE VARIANT'S OWN `store_id`.
         *
         * `ProductVariant` registers no `StoreScope` and is not in `config/tenancy.php`'s
         * `scoped_models`, so `ProductVariant::find($id)` reaches every tenant's variants and
         * nothing narrows it — the same shape as S19's measured cross-tenant stock drain, and the
         * same reason `product_attribute_delete()` resolves through the product. The variant's
         * own `store_id` is nullable and is not the authority on who owns it.
         *
         * The predicate cannot be `where('product_id', $request->product_id)` either: that
         * compares the caller's own two values, which CLAUDE.md records as having produced four
         * separate defects.
         */
        $owner = $variant ? Product::find($variant->product_id) : null;

        if ($variant === null || $this->productIsOutsideCurrentStore($owner)) {
            \Illuminate\Support\Facades\Log::warning(
                'variant image upload refused: the variant is missing or belongs to another store',
                [
                    'variant_id' => $request->variant_id,
                    'product_id' => $variant->product_id ?? null,
                    'product_store_id' => $owner->store_id ?? null,
                    'current_store' => getCurrentStore(),
                    'user_id' => optional(auth()->user())->id,
                ]
            );

            /*
             * 403 and the same body for both cases, so a caller cannot distinguish "no such
             * variant" from "not yours". Not a 401: that is the status the shipped Flutter app
             * force-logs-out on, and this is a web route besides.
             */
            return response()->json([
                'flag' => 'error',
                'msg' => __('Permission denied.'),
            ], 403);
        }

        if (! $request->hasFile('variant_image')) {
            return response()->json([
                'flag' => 'error',
                'msg' => __('Please select an image.'),
            ], 422);
        }

        /*
         * THE STORE IS THE PRODUCT'S, NOT `getCurrentStore()`. Ownership has just been proved, and
         * the helper hands a `super admin` with an empty `current_store` the hardcoded literal `1`
         * (helper.php:636-637) — so an operator acting on another store's product would stamp the
         * media row with store 1. That is the F157 trap, and F168 found the same shape two methods
         * away. The three existing gallery writers use `getCurrentStore()`; they are not changed
         * here, but a new writer should not copy an edge it does not need.
         */
        $storeId = (int) $owner->store_id;

        /*
         * THE STORE'S OWN FOLDER, `uploads/<store_id>` — which is what the gallery writes to
         * (:598, `'uploads/' . $store_id->id`) and what `StoredPathPolicy::productImage()`
         * enforces on save. A plausible-looking `uploads/pro_image` is REFUSED: the first draft
         * of this handler used it and every upload came back
         * "The file path is not accepted." from the P1-SEC-28 saving hook, because the policy
         * judges a stored path against the owning store's folder rather than against a
         * well-known directory name. Inheriting that hook is one of the three reasons the column
         * went on `product_images`, so it would be perverse to then write a path it rejects.
         */
        $dir = 'uploads/' . $storeId;

        $image = $request->file('variant_image');

        /*
         * THE PLAN'S STORAGE LIMIT, CHARGED TO THE SAME PRINCIPAL THE GALLERY CHARGES.
         * `Utility::updateStorageLimit(creatorId(), $bytes)` returns `1` or a message, and
         * `creatorId()` resolves a staff user to their merchant — so an employee's upload counts
         * against the merchant's plan, not against a staff account with no plan. That is the
         * existing idiom at :1758 and :591, copied rather than reinvented; P1-INV-25 records that
         * "which user is debited" is a question this codebase has already answered.
         */
        $result = \App\Models\Utility::updateStorageLimit(\Auth::user()->creatorId(), $image->getSize());

        if ($result != 1) {
            return response()->json([
                'flag' => 'error',
                'msg' => is_string($result) ? $result : __('The file was not accepted.'),
            ], 422);
        }

        $fileName = rand(10, 100) . '_' . time() . '_' . $image->getClientOriginalName();

        /*
         * `upload_file`, NOT `keyWiseUpload_file`, AND THE DIFFERENCE IS NOT COSMETIC. The
         * gallery's helper does `$request->file($key)[$data_key]` — it indexes into an ARRAY of
         * files, because the gallery is a multi-file loop. This is one file, so that helper would
         * index a single `UploadedFile` and fail. `upload_file` is its single-file sibling and is
         * what the product's COVER image already uses.
         *
         * They share the rules, which is what "reuse Gallery Upload and `UploadLimits`" actually
         * requires: both read `mimes:`/`max:` from the same storage settings
         * (`local_storage_validation` / `s3_storage_validation` / `wasabi_storage_validation`)
         * and both return `['flag' => 0, 'msg' => ...]` on refusal. So the accepted set is the one
         * configured set, not a second one.
         *
         * The refusal is reported BY NAME, the F75 shape: a rejected image that vanishes in
         * silence is the defect that fix closed, and this is a new upload surface that could
         * easily have reintroduced it.
         */
        $path = \App\Models\Utility::upload_file($request, 'variant_image', $fileName, $dir, []);

        if (! isset($path['url'])) {
            return response()->json([
                'flag' => 'error',
                'msg' => $path['msg'] ?? __('The file was not accepted.'),
                'file' => (string) $image->getClientOriginalName(),
            ], 422);
        }

        /*
         * REPLACE, AND THE OLD FILE GOES WITH THE ROW. Deleting the previous row through the same
         * path `file_delete()` uses means the unlink is authorised by `StoredUpload` — rule 3,
         * the owner-folder check — rather than by a second containment implementation. A stored
         * path that does not resolve still loses its row: a bad stored value must not make an
         * image unreplaceable, which is what P1-INV-07 established.
         */
        $existing = ProductImage::where('product_id', $owner->id)
            ->where('variant_id', $variant->id)
            ->get();

        foreach ($existing as $old) {
            if (! \App\Services\StoredUpload::delete((string) $old->image_path, \Auth::user()->creatorId())
                && ! empty($old->image_path)) {
                \Illuminate\Support\Facades\Log::warning(
                    'variant image row replaced but the old file was not unlinked: StoredUpload'
                    . ' refused the stored path',
                    ['image_id' => $old->id, 'image_path' => (string) $old->image_path]
                );
            }

            $old->delete();
        }

        $productImage = new ProductImage();
        $productImage->product_id = $owner->id;
        $productImage->variant_id = $variant->id;
        $productImage->image_path = $path['url'];
        $productImage->image_url = $path['full_url'];
        $productImage->store_id = $storeId;
        $productImage->save();

        return response()->json([
            'flag' => 'success',
            'msg' => __('Image uploaded successfully.'),
            'id' => $productImage->id,
            'url' => get_file($productImage->image_path),
        ]);
    }

    /**
     * D-26 — remove a variant's image. Add, replace and remove were approved together.
     */
    public function variant_image_destroy(Request $request)
    {
        /*
         * F206 [2026-10-30] -- the platform operator does not write a merchant's catalogue media.
         *
         * Placed at the WRITER rather than inside `lacksProductPermission()` or
         * `productIsOutsideCurrentStore()`, both of which carry operator limbs: the read paths the
         * owner wants preserved (`attribute_combination_data`, `attribute_combination_edit`,
         * `show`, `product_preview`) share those two helpers with these three writers, so
         * narrowing a helper would have closed the diagnosis surface too.
         *
         * These are irreversible disk writes — `StoredUpload` unlinks the file. Whether catalogue
         * MEDIA falls under the owner's "products, prices, stock, variants" is a judgement call,
         * and it is made toward blocking and recorded as such in the ledger, because deleting a
         * merchant's image is not diagnosis. It is a one-line reversal if the owner wants it.
         */
        if (\App\Services\OperatorCatalogueWrite::refuses(auth()->user())) {
            return response()->json([
                'flag' => 'error',
                'msg' => \App\Services\OperatorCatalogueWrite::message(),
            ], 403);
        }

        if ($this->lacksProductPermission(['Edit Products'])) {
            return response()->json([
                'flag' => 'error',
                'msg' => __('Permission denied.'),
            ], 403);
        }

        $variant = ProductVariant::find($request->variant_id);
        $owner = $variant ? Product::find($variant->product_id) : null;

        if ($variant === null || $this->productIsOutsideCurrentStore($owner)) {
            \Illuminate\Support\Facades\Log::warning(
                'variant image delete refused: the variant is missing or belongs to another store',
                [
                    'variant_id' => $request->variant_id,
                    'product_id' => $variant->product_id ?? null,
                    'product_store_id' => $owner->store_id ?? null,
                    'current_store' => getCurrentStore(),
                    'user_id' => optional(auth()->user())->id,
                ]
            );

            return response()->json([
                'flag' => 'error',
                'msg' => __('Permission denied.'),
            ], 403);
        }

        /*
         * SCOPED BY BOTH THE PRODUCT AND THE VARIANT. The variant id alone would be enough given
         * the ownership check above, but the product predicate means a `variant_id` that somehow
         * points across products cannot reach another product's media row.
         */
        $images = ProductImage::where('product_id', $owner->id)
            ->where('variant_id', $variant->id)
            ->get();

        foreach ($images as $image) {
            if (! \App\Services\StoredUpload::delete((string) $image->image_path, \Auth::user()->creatorId())
                && ! empty($image->image_path)) {
                \Illuminate\Support\Facades\Log::warning(
                    'variant image row deleted but the file was not unlinked: StoredUpload refused'
                    . ' the stored path',
                    ['image_id' => $image->id, 'image_path' => (string) $image->image_path]
                );
            }

            $image->delete();
        }

        return response()->json([
            'flag' => 'success',
            'msg' => __('Image delete Successfully'),
        ]);
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

        /*
         * A MISSING PRODUCT IS REFUSED HERE, not left to the view. F165.
         *
         * `productIsOutsideCurrentStore()` returns false for the platform operator BEFORE its
         * `empty($product)` test, so for an operator a non-existent `id` reached the view with
         * `$product === null` and the view's own `$product->ProductVariant($str)` raised
         * "Call to a member function on null" -- a 500. The same refusal shape as above, so a
         * missing id and a foreign id stay indistinguishable to the caller.
         */
        if (empty($product)) {
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
                        /*
                         * THE VALUE IS USED VERBATIM, AND THAT IS CORRECT HERE — the two
                         * endpoints take the SAME parameter name with DIFFERENT vocabularies.
                         *
                         * This is the trap in this pair of handlers, and it is written down
                         * because it produced a wrong "fix" that a pre-existing test caught:
                         *
                         *   - the CREATE page fills its select from `attribute_option()`, which
                         *     returns `pluck('terms', 'id')`, and its JavaScript emits
                         *     `<option value="<id>">` (create.blade.php:833). So
                         *     `attribute_options_N[]` carries option **IDS**, and
                         *     `attribute_combination()` (:2745) resolves each id to its `terms`
                         *     — with a store predicate, which is P1-INV-10 (c).
                         *
                         *   - the EDIT page's select is Blade, and its `<option>` carries NO
                         *     `value` attribute (edit.blade.php:632-636), so the browser submits
                         *     the option's TEXT. That text is `$option`, which is
                         *     `ProductAttributeOption::...->pluck('terms')` (edit.blade.php:552).
                         *     So the same parameter carries option **TERMS** here, and the terms
                         *     are already what the variant key must be.
                         *
                         * Resolving them as ids therefore produced `ProductAttributeOption::
                         * where('id', 'Red')`, which matches nothing, so the combination table
                         * came back EMPTY for every real request — and because the merchant's own
                         * variant row then went unrendered,
                         * `MerchantProductOwnershipTest::test_a_merchant_can_open_their_own_attribute_combination`
                         * failed on its positive control. There is no cross-tenant read to guard
                         * here precisely BECAUSE nothing is looked up: the handler echoes strings
                         * the caller already had.
                         *
                         * `VariantCombinationVocabularyTest` pins both shapes so the two cannot
                         * be "unified" by inspection again.
                         */
                        $my_str = $request[$name];
                        array_push($options, $my_str);
                    }
                }
            }
        }

        $combinations = $this->combination($options);
        $Shipping = Shipping::where('store_id', getCurrentStore())->pluck('name', 'id')->prepend('Same as parent', '');

        /*
         * D-26 -- each variant's one image, keyed by variant id. Scoped to THIS product, whose
         * ownership was proved above, so the lookup cannot reach another tenant's media.
         */
        $variantImages = ProductImage::where('product_id', $product->id)
            ->whereNotNull('variant_id')
            ->get()
            ->keyBy('variant_id');

        return view('product.attribute_combinations_edit', compact('combinations', 'unit_price', 'product_name', 'product', 'Shipping', 'variantImages'));
    }

    public function product_attribute_delete(Request $request, $id)
    {
        /*
         * PERMISSION AND TENANT OWNERSHIP, THROUGH THE SHARED POLICY. P1-INV-10 (e), F195 / D-28c.
         *
         * `Edit Products`: this deletes a variant row and the stock it carries.
         *
         * THE DEFECT D-28c CLOSES HERE, measured: a staff member whose only `Edit Products` grant
         * belongs to ANOTHER of their merchant's stores, but whose `current_store` is THIS one,
         * deleted this store's variant -- HTTP 200, and the panel announced *"Variant Deleted
         * Successfully!"*. `lacksProductPermission()` resolves through `isAbleTo()`, which unions
         * every role the user holds with no store predicate anywhere in it (Laratrust's team
         * feature is disabled), so a grant in store B answers TRUE for store A. The ownership check
         * below could not catch it, because `current_store` really was this store.
         *
         * `VariantRemovalPolicy::authorize()` reads the permission against the PRODUCT's store and
         * keeps the operator limb that `isAbleTo()` alone would lock out. It also answers the
         * TENANT-ownership question; the check below answers the CURRENT-STORE one, and the web
         * doors are deliberately the narrower of the two, so the pair is not redundant.
         *
         * `find()` RATHER THAN `findOrFail()`, BECAUSE THE PERMISSION CANNOT BE READ BEFORE THE
         * PRODUCT IS KNOWN -- there is no store to read it against until then. So the load moves
         * ahead of the authorization check.
         *
         * BUT THE 404/403 PAIR THE PREVIOUS ORDERING PRODUCED IS PRESERVED, and that is a
         * deliberate reversal of my first attempt. I had collapsed both cases into one 403, on the
         * reasoning that it removes a platform-wide existence oracle over `product_variants.id`
         * (403 means "exists but not yours", 404 means "exists nowhere") for any caller holding
         * `Edit Products` anywhere. The review agreed it was an improvement. It also broke
         * `MerchantProductOwnershipTest::test_a_permitted_caller_still_gets_404_for_a_variant_that_does_not_exist`,
         * whose docblock records the project WEIGHING this exact trade and choosing both answers:
         * *"the cost of that ordering would be turning every missing id into a 403 for everybody,
         * which would read as a permission problem when it is not"*. A merchant double-clicking the
         * trash button on an already-deleted row would be told "Permission denied."
         *
         * Narrowing that disclosure is not what D-28c asked for, and quietly overriding a decision
         * the project made and tested is not a security fix. So the pair stays and the trade is
         * recorded as its own finding for the owner to settle.
         *
         * `lacksProductPermission()` BELOW CHOOSES 404-vs-403 FOR A ROW THAT DOES NOT EXIST, AND
         * NOTHING ELSE. It is the cross-store union read, which is wrong for authorising a write --
         * that is the whole point of this slice -- but right here, because there is no store to
         * scope to when the row is absent and the question is only "is this caller the kind who
         * edits products". No write follows it.
         *
         * Ownership is resolved through the variant's PRODUCT, for the reason S36 recorded: the
         * variant's own nullable `store_id` is not the authority on who owns it.
         */
        $attribute = ProductVariant::withoutGlobalScopes()->whereKey($id)->first();

        if ($attribute === null) {
            if ($this->lacksProductPermission(['Edit Products'])) {
                return response()->json([
                    'flag' => 'error',
                    'msg' => __('Permission denied.'),
                ], 403);
            }

            abort(404);
        }

        $owner = \App\Services\VariantRemovalPolicy::parentProduct($attribute);

        $ssDenied = \App\Services\VariantRemovalPolicy::authorize(auth()->user(), $owner);

        if ($ssDenied !== null) {
            return response()->json([
                'flag' => 'error',
                'msg' => __('Permission denied.'),
            ], 403);
        }

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

        /*
         * F195 -- THE SAME D-20 BLOCK THE SAVE PATH APPLIES, ON THE DOOR THAT HAD NONE.
         *
         * This handler had `Edit Products` and ownership and then deleted the row. The reference
         * checks lived only in `ssRefuseVariantRemoval()`, reached from two call sites both inside
         * `update()` -- so a merchant could delete, in two clicks from the trash button in either
         * variant panel, the exact variant the Save path hard-blocks because an order names its
         * product. A control with a button next to it that skips it is not a control.
         *
         * `true` for the confirmation: see the guard's own docblock. This endpoint IS D-20
         * condition 3's separate deliberate operation, so it takes the blocks and not the
         * confirmation refusal.
         *
         * 409 AND NOT 200, AND THAT IS LOAD-BEARING. The guard's refusal array is what `update()`
         * returns with HTTP 200 and `flag: 'error'`, because the product form reads `data.flag`.
         * BOTH callers of THIS endpoint announce "Variant Deleted Successfully!" and remove the row
         * from the DOM on ANY 2xx without reading the body -- the comment above says so, and it is
         * still true -- so returning the guard's shape here would tell the merchant the variant was
         * deleted, hide it from the panel, and leave it in the database. 409 Conflict is the honest
         * code: the delete conflicts with references that exist.
         *
         * The message is carried so the new `error:` handlers in both panels can show it. Without
         * those handlers a non-2xx is SILENT -- neither caller had one, which is why they are part
         * of this change rather than a follow-up.
         */
        /*
         * F184 / D-28c -- THE CONSENT IS VERIFIED HERE, NOT ASSUMED FROM THE CLICK.
         *
         * THIS WAS A HARDCODED `true`, and the reasoning behind it has been superseded by the
         * owner. D-20 read this endpoint as itself the "separate, deliberate operation" its
         * condition 3 asks for -- a dedicated per-variant route reached by its own button, not a
         * side effect of a Save -- so it took the reference BLOCKS and skipped the confirmation
         * refusal. The owner's F184 condition is the opposite and explicit: *"the trash click
         * itself is not the confirmation and must not start the delete."*
         *
         * SO THE CALLER MUST ECHO THE VARIANT'S OWN NAME. A bare boolean would be worthless here:
         * anything that can send `confirm=1` can send it blind, so it would record that a
         * parameter was set rather than that a person saw what they were destroying. The name is
         * a value the caller can only supply by having read the row, which is what makes the
         * signal verifiable rather than decorative -- and it is exactly what the dialog puts in
         * front of the merchant before the request is made.
         *
         * STRICT, AND TRIMMED ON BOTH SIDES. `variant` is merchant free text, so the comparison
         * is on the exact stored string with surrounding whitespace removed -- not a loose
         * comparison, which would let `0` confirm a variant named `0`. A mismatch is not a special
         * case: it falls through as "not confirmed" and the merchant gets the ordinary refusal
         * naming what would be lost.
         *
         * THE BLOCKS DO NOT DEPEND ON IT. `refuse()` applies the cart, wishlist, store-less and
         * order-history checks before it ever looks at `$confirmed`, so a correct consent token
         * still cannot carry a delete past a hard block. That is the owner's condition that no
         * confirmation may override one, and it holds by construction rather than by this call
         * site being careful.
         */
        $ssConsent = $request->input('ss_confirm_variant');

        /* One comparison, shared with the API door and with the views that render the button —
           `trim()` on this side against a middleware-`Str::trim()`-ed echo on the other could
           never match a name with an invisible edge character, and an empty stored name had no
           token at all. See `VariantRemovalPolicy::consentToken()`. */
        $ssConfirmed = \App\Services\VariantRemovalPolicy::consentSatisfied($ssConsent, $attribute);

        $ssRemovalRefusal = $this->ssRefuseVariantRemoval(
            $owner,
            collect([$attribute]),
            $ssConfirmed,
            ['key' => 'ss_confirm_variant', 'value' => 'variant_name']
        );

        if ($ssRemovalRefusal !== null) {
            \Illuminate\Support\Facades\Log::warning(
                'product variant delete refused: the variant is still referenced',
                [
                    'variant_id' => $attribute->id,
                    'product_id' => $attribute->product_id,
                    'blocked' => $ssRemovalRefusal['ss_variant_removal']['blocked'] ?? null,
                    'user_id' => optional(auth()->user())->id,
                ]
            );

            return response()->json([
                'flag' => 'error',
                'message' => $ssRemovalRefusal['msg'],
                'msg' => $ssRemovalRefusal['msg'],
            ], 409);
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