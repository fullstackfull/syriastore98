<?php

namespace App\Http\Controllers;

use App\Models\{Addon, Plan, PlanCoupon, User, userActiveModule};
use App\Models\AuditLog;
use App\Models\PlanOrder;
use App\Models\Utility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Facades\ModuleFacade as Module;
use App\DataTables\PlanOrderDataTable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    /**
     * THE APPROVED PLAN DURATIONS, AS A VALIDATION RULE — decision H1, option A.
     * =============================================================================================
     *
     * `plans.duration` is `$table->string('duration')->nullable()`: free text. The rules in
     * `store()` and `update()` covered `name`, `price`, `max_stores`, `max_products`, `max_users`
     * and `storage_limit` and did not mention `duration` at all, and the form field is a
     * `Form::select` over `Plan::$arrDuration` -- which constrains the browser, not the request.
     *
     * WHAT THAT COST, MEASURED. `App\Services\SaasRevenueFigures` computes the platform's MRR from
     * this column. Before the READ side was hardened, a plan priced 1200 a year whose duration read
     * `Yearly` fell down the monthly branch at its whole price and reported MRR 1200 instead of 100
     * -- a twelvefold overstatement of the platform's own revenue, in the one figure that is meant
     * to be the honest one. The read side now refuses to guess and sends an unreadable duration to
     * a named bucket; this is the write side, so such a row can no longer be created.
     *
     * THE VOCABULARY HAS ONE DEFINITION. The set comes from `Plan::$arrDuration` -- the same
     * property `getDurationsArr()` renders the select from -- and is NOT spelled out again here. A
     * second literal list would drift from the form and from the figures the moment either moved,
     * and introducing a new billing period is an owner decision rather than an edit to a rule
     * string.
     */
    private function approvedDurationRule(): \Illuminate\Validation\Rules\In
    {
        return Rule::in(array_keys(Plan::$arrDuration));
    }

    /**
     * The rule for CREATE: a plan must declare how often it is paid, as one of the approved words.
     *
     * `required` IS LOAD-BEARING HERE AND IT WAS MEASURED. Dropping it and keeping only the `in`
     * rule lets a POST that carries no `duration` key at all through: `in` is not an implicit
     * rule, so the validator skips it for an absent attribute, and the row persists with a null
     * duration -- the column is nullable -- so every subscriber of that plan is uncountable.
     * Driven by `test_a_missing_duration_is_refused_on_create`, which is the one create case that
     * goes red when `required` is removed.
     */
    private function durationRulesForCreate(): array
    {
        return ['required', $this->approvedDurationRule()];
    }

    /**
     * THE WORD A ROW ALREADY CARRIES, WHEN IT IS NOT ONE OF THE THREE — or null.
     * =============================================================================================
     *
     * Decision H1 has two clauses and they pull in opposite directions at exactly one place: the
     * edit form of a plan whose duration predates the vocabulary. "Enforce the three words on
     * creation and update" and "do not silently rewrite historical values" are both satisfied for
     * a request that omits `duration` -- but the real form does not omit it, and MEASURED on the
     * rendered page, `Form::select('duration', $arrDuration, null, ...)` under `Form::model($plan)`
     * emitted NO `selected` attribute at all for a stored `Yearly`, because no option matched it.
     * A browser shows and submits the FIRST option. So an operator opening that form to change
     * `max_products` and pressing Save replaced `Yearly` with `Unlimited` -- a silent rewrite of a
     * historical value, performed by the very change that was meant to prevent one, and accepted
     * because `Unlimited` is approved.
     *
     * The three ways out were not equal. Rendering the legacy word as a fourth option for every
     * plan RE-OFFERS a withdrawn billing period, which the decision forbids. Auto-correcting on
     * save IS the silent rewrite, which the decision forbids by name. What is left is to show the
     * row its own stored value, marked as withdrawn, and to accept that one value back from that
     * one row: re-submitting the word already in the column writes nothing new, introduces no
     * billing period anywhere, and is the only shape under which an operator can edit an unrelated
     * field without either being blocked or destroying the value.
     *
     * NOTHING WIDENS UNLESS THE COLUMN ALREADY HOLDS IT. The extra value is read from the stored
     * row and never from the request, so no request can introduce one, and the allowance is
     * per-plan: a second plan carrying a different legacy word does not accept this one.
     */
    private function legacyDurationOf(Plan $plan): ?string
    {
        $stored = is_string($plan->duration) ? trim($plan->duration) : '';

        if ($stored === '' || array_key_exists($stored, Plan::$arrDuration)) {
            return null;
        }

        return $stored;
    }

    /**
     * The options the edit form offers for ONE plan: the three approved words, plus that row's own
     * historical value when it has one, labelled as withdrawn so the operator can see what it is.
     *
     * The label goes through `__()` with a replacement rather than string concatenation, so the
     * Arabic panel reads right; the key is in `resources/lang/{en,ar}.json`. The legacy word itself
     * is not translated -- it is stored data, and showing it verbatim is the point.
     *
     * `create()` keeps `Plan::getDurationsArr()` unchanged: there is no row yet, so there is no
     * historical value, and a new plan may only ever be one of the three.
     */
    private function durationOptionsFor(Plan $plan): array
    {
        $options = Plan::getDurationsArr();

        $legacy = $this->legacyDurationOf($plan);

        if ($legacy !== null) {
            $options[$legacy] = __(':duration (no longer offered)', ['duration' => $legacy]);
        }

        return $options;
    }

    /**
     * The rule for UPDATE, and the reason it is `sometimes` rather than a plain `required`.
     *
     * HISTORICAL VALUES ARE NOT REWRITTEN. A plan that already carries something else keeps it;
     * the owner's instruction is to refuse new writes, not to correct rows that exist. Two
     * measured reasons make `sometimes` the right shape, both pinned by
     * `tests/Feature/Saas/PlanDurationValidationTest`:
     *
     *   1. `resources/views/plans/edit.blade.php` wraps the price AND duration fields in
     *      `@if ($plan->id != 1)`, so an edit of the Free Plan -- present in every installation,
     *      and the downgrade target both `refund()` and `assignPlan(1)` depend on -- submits no
     *      `duration` key at all. A plain `required` would make that plan permanently un-editable
     *      through its own form.
     *   2. A `required` here would protect nothing. `update()` saves `$request->all()`, so a key
     *      the request did not carry is never written to the column: an absent `duration` cannot
     *      introduce an unapproved value. Refusing it would only block an edit to an unrelated
     *      field on a row whose duration is already unapproved.
     *
     * `required` STAYS BEHIND `sometimes`, AND IT IS BELT-AND-BRACES RATHER THAN THE CONTROL --
     * measured, so as not to be claimed as more than it is. A blank select arrives as null
     * (`ConvertEmptyStringsToNull` is in Laravel's global middleware stack and is not removed in
     * `bootstrap/app.php`), the attribute is still PRESENT, so the `in` rule runs on that null and
     * refuses it on its own: removing `required` from this array leaves
     * `test_a_blank_duration_is_refused_on_update` green. It is kept because the refusal then does
     * not rest on a global middleware staying where it is -- `in` is not an implicit rule, so with
     * the conversion out of the stack the validator skips it for `''` and
     * `$plan->update($request->all())` would carry that empty string into the column, over a good
     * value. `test_a_blank_duration_is_refused_even_if_empty_strings_are_not_converted` drives
     * exactly that and is the case that goes red when `required` is dropped.
     */
    private function durationRulesForUpdate(Plan $plan): array
    {
        $legacy = $this->legacyDurationOf($plan);

        $allowed = array_keys(Plan::$arrDuration);

        if ($legacy !== null) {
            $allowed[] = $legacy;
        }

        return ['sometimes', 'required', Rule::in($allowed)];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(PlanOrderDataTable $dataTable)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Plan')) {
            $setting = getSuperAdminAllSetting();
            if (auth()->user()->type == 'super admin') {
                $plans = Plan::get();
            } else {                
                $plans = Plan::where('is_disable', 1)->get();
            }
            return  $dataTable->render('plans.index', compact('plans','setting'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create Plan')) {
            $arrDuration = Plan::getDurationsArr();
            $theme = Addon::where('status','1')->get();
            $modules = Module::all();
            $modules = array_filter($modules, function($key) {
                return $key !== 'BackupRestore';
            }, ARRAY_FILTER_USE_KEY);

            $setting = getSuperAdminAllSetting();
            return view('plans.create', compact('arrDuration','theme','setting', 'modules'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create Plan'))
        {
            $validation = [];
            $validation['name'] = 'required|unique:plans';
            $validation['price'] = 'required|numeric|min:0';
            $validation['duration'] = $this->durationRulesForCreate();
            $validation['max_stores'] = 'required|numeric';
            $validation['max_products'] = 'required|numeric';
            $validation['max_users'] = 'required|numeric';
            $validation['storage_limit'] = 'required|numeric';
            $request->validate($validation);

            $post = $request->all();
            if(isset($request->trial))
            {
                $post['trial'] = 1;
            }
            if($request->has('themes')){
                $post['themes'] = implode(',',$request->themes);
            }

            if($request->has('modules')){
                $post['modules'] = implode(',',$request->modules);
            }

            if (Plan::create($post)) {
                return redirect()->route('plan.index')->with('success', __('Plan created Successfully!'));
            } else {
                return redirect()->back()->with('error', __('Something is wrong'));
            }
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    }

    /**
     * Display the specified resource.
     */
    public function show(Plan $plan)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Plan $plan)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Edit Plan'))
        {
            $arrDuration = $this->durationOptionsFor($plan);
            $theme = Addon::where('status','1')->get();
            $modules = Module::all();
            $setting = getSuperAdminAllSetting();
            return view('plans.edit',compact('plan','arrDuration', 'theme', 'setting', 'modules'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Plan $plan)
    {

        if (auth()->user() && auth()->user()->isAbleTo('Edit Plan'))
        {
            // if (\Auth::user()->type == 'super admin' || (isset($admin_payments_setting['is_stripe_enabled']) && $admin_payments_setting['is_stripe_enabled'] == 'on'))
            // {
                if ($plan) {
                    if ($plan->price > 0) {
                        $validator = \Validator::make(
                            $request->all(), [
                                'name' => 'required|unique:plans,name,' . $plan->id,
                                'price' => 'numeric|min:0',
                                'duration' => $this->durationRulesForUpdate($plan),
                                'max_stores' => 'required|numeric',
                                'max_products' => 'required|numeric',
                                'max_users' => 'required|numeric',
                                'storage_limit' => 'required|numeric',
                            ]
                        );
                    } else {
                        $validator = \Validator::make(
                            $request->all(), [
                                'name' => 'required|unique:plans,name,' . $plan->id,
                                // BOTH RULE SETS, not just the paid one. `$plan->price > 0` above
                                // picks between them, so this is the branch the Free Plan and every
                                // other zero-priced plan takes -- and `$plan->update($post)` writes
                                // `duration` just the same. A rule in only one of these two arrays
                                // would leave the whole defect reachable through the other.
                                'duration' => $this->durationRulesForUpdate($plan),
                                'max_stores' => 'required|numeric',
                                'max_products' => 'required|numeric',
                                'max_users' => 'required|numeric',
                                'image' => 'mimes:jpeg,png,jpg,gif,svg,pdf,doc|max:20480',
                            ]
                        );
                    }
                    {

                    }
                    if ($validator->fails()) {
                        $messages = $validator->getMessageBag();

                        return redirect()->back()->with('error', $messages->first());
                    }

                    $post = $request->all();

                    if (!isset($request->enable_domain)) {
                        $post['enable_domain'] = 'off';
                    }
                    if (!isset($request->enable_subdomain)) {
                        $post['enable_subdomain'] = 'off';
                    }
                    if (!isset($request->enable_chatgpt)) {
                        $post['enable_chatgpt'] = 'off';
                    }
                    if (!isset($request->pwa_store)) {
                        $post['pwa_store'] = 'off';
                    }

                    if (!isset($request->shipping_method)) {
                        $post['shipping_method'] = 'off';
                    }

                    if (!isset($request->enable_tax)) {
                        $post['enable_tax'] = 'off';
                    }
                    if(isset($request->trial))
                    {
                        $post['trial'] = 1;
                        $post['trial_days'] = $request->trial_days;
                    }
                    else
                    {
                        $post['trial'] = 0;
                        $post['trial_days'] = null;
                    }

                    if($request->has('themes')){
                        $post['themes'] = implode(',',$request->themes);
                    }
                    if($request->has('modules')){
                        $post['modules'] = implode(',', $request->modules);
                        $user_ids = User::where('plan_id', $plan->id)->pluck('id')->toArray();
                        userActiveModule::whereIn('user_id', $user_ids)->delete();
                        foreach ($user_ids as $user_id) {
                            foreach ($request->modules as $module) {
                                userActiveModule::create([
                                    'user_id' => $user_id,
                                    'module' => $module,
                                ]);
                            }
                        }
                    }else {
                        $post['modules'] = null;
                        $user_ids = User::where('plan_id', $plan->id)->pluck('id')->toArray();
                        userActiveModule::whereIn('user_id', $user_ids)->delete();
                    }

                    if(!$request->has('modules')){
                        $user_ids = User::where('plan_id', $plan->id)->pluck('id')->toArray();
                        userActiveModule::whereIn('user_id', $user_ids)->delete();
                    }

                    if ($plan->update($post)) {
                        /*
                         * F218 / D-29.1's census. `--except => 'cache'` IS NOT THE WHOLE FIX, AND
                         * THE FIRST VERSION OF THIS COMMENT SAID IT WAS.
                         *
                         * An independent review found it and I measured it: `$plan->update($post)`
                         * ON THE LINE ABOVE ALREADY FLUSHES THE WHOLE STORE. `App\Models\Plan` uses
                         * `Cachable`, so the write goes through laravel-model-caching's flush, and
                         * on production's non-taggable `database` store that flush degrades to a
                         * `Cache::flush()` over everything. Driven on a file store: two seeded
                         * payment claims, one `Plan` update, 0 of 2 survived -- before this line is
                         * reached at all.
                         *
                         * SO THIS NARROWING REMOVES ONE OF TWO FLUSHES, not the erasure. It is kept
                         * because it is right on its own terms -- `optimize:clear` is broader than a
                         * model flush and there is no reason for a plan save to clear the cache
                         * store -- and because the config, route, view and event caches are still
                         * cleared exactly as before, which is all a plan save needs.
                         *
                         * THE ERASURE ITSELF IS F194's, and only F194's: isolating the model cache
                         * onto its own store is the single change that stops a `Cachable` write
                         * reaching a payment key. It is implemented and INERT, and activating it is
                         * the owner's. This is the same conclusion F218's other half reached from
                         * the other direction -- the checkout's own stock decrement erases the claim
                         * it just took -- and together they are why F194's activation rests on
                         * "this will happen" rather than "this might".
                         *
                         * The flag uses the same idiom routes/web.php:817 already uses.
                         *
                         * The bare form runs `cache:clear`, and in production the default store is
                         * `database`, whose `flush()` is `$this->table()->delete()` -- the whole
                         * cache table, prefix ignored. The payment replay claims live on that store
                         * (`gateway-return-claim:*`, `paymera_claim_*`, `paymera_pending_*`, all
                         * written with the bare `Cache::` facade), so this call erased every
                         * in-flight claim in the installation -- as does the model write above it.
                         *
                         * WHAT MAKES THAT WORSE THAN A LOST CACHE ENTRY: the claims are taken with
                         * `Cache::add()`, which returns TRUE for a missing key. An erased claim is
                         * therefore an UN-TAKEN claim, not a detectable one -- the next gateway
                         * return takes it cleanly and the duplicate-capture guard reports success.
                         *
                         * `--except` is honoured before any task runs (OptimizeClearCommand rejects
                         * by task key, and `cache` is `cache:clear`'s key), so config, route, view
                         * and event caches are still cleared exactly as before -- which is all a
                         * plan save needs, since what changed is configuration and menus, not
                         * cached model rows.
                         */
                        Artisan::call('optimize:clear', ['--except' => 'cache']);
                        return redirect()->back()->with('success', __('Plan updated Successfully!'));
                    } else {
                        return redirect()->back()->with('error', __('Something is wrong'));
                    }
                } else {
                    return redirect()->back()->with('error', __('Plan not found'));
                }
            // } else {
            //     return redirect()->back()->with('error', __('Please set atleast one payment api key & secret key for add new plan'));
            // }
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    /*
     * P2.5-SEC-DESTRUCTIVE F-4. PRIVILEGE ESCALATION, not only cross-tenant reach.
     *
     * A Plan is PLATFORM data -- the subscription tiers every merchant buys. MEASURED: an
     * ordinary merchant sent DELETE /plan/{id} and the row was gone. The merchant role is granted
     * `Manage Plan` and NEVER Create or Edit (`Utility::addNewData()`'s `$companyNewPermission`),
     * so `destroy` outranked the view-only grant it was paired with.
     *
     * The only protection present was incidental: `User::where('plan_id', $id)` refuses a plan
     * somebody is subscribed to, so only unsubscribed tiers went. That is a referential-integrity
     * check, not an authorization one, and it is left exactly where it is below.
     *
     * OPERATOR ONLY. Platform data is the operator's, and this neither broadens what an operator
     * may do nor narrows any permission a merchant legitimately holds -- `Manage Plan` was always
     * a view grant.
     */
    public function destroy(Request $request, $id)
    {
        $actor = auth()->user();

        if (! $actor || ! \App\Policies\UserPolicy::isOperator($actor)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $userPlan = User::where('plan_id' , $id)->first();
        if($userPlan != null)
        {
            return redirect()->back()->with('error',__('The company has subscribed to this plan, so it cannot be deleted.'));
        }
        $plan = Plan::find($id);
        if($plan->id == $id)
        {
            $plan->delete();

            return redirect()->back()->with('success' , __('Plan deleted successfully'));
        }
        else
        {
            return redirect()->back()->with('error',__('Something went wrong'));
        }
    }


    public function planPrepareAmount(Request $request)
    {

        $plan = Plan::find(\Illuminate\Support\Facades\Crypt::decrypt($request->plan_id));

        if ($plan) {
            $original_price = number_format($plan->price);
            $coupons = PlanCoupon::where('code', strtoupper($request->coupon))->where('is_active', '1')->first();
            $coupon_id = null;
            if (!empty($coupons)) {
                $usedCoupun = $coupons->used_coupon();
                if ($coupons->limit == $usedCoupun) {
                } else {
                    $discount_value = ($plan->price / 100) * $coupons->discount;
                    $plan_price = $plan->price - $discount_value;
                    $price = $plan->price - $discount_value;
                    $discount_value = '-' . $discount_value;
                    $coupon_id = $coupons->id;
                    return response()->json(
                        [
                            'is_success' => true,
                            'discount_price' => $discount_value,
                            'final_price' => $price,
                            'price' => $plan_price,
                            'coupon_id' => $coupon_id,
                            'message' => __('Coupon code has applied successfully.'),
                        ]
                    );
                }
            } else {
                return response()->json(
                    [
                        'is_success' => true,
                        'final_price' => $original_price,
                        'coupon_id' => $coupon_id,
                        'price' => $plan->price,
                    ]
                );
            }
        }
    }

    /**
     * NEW-PLAN-DISABLE-UNGATED-01, second check. The control is on the route
     * (`PlatformOperatorOnly`, routes/web.php `plan.disable`); this is the defence-in-depth half
     * the SEC-F2 pattern established after relying on route grouping alone failed once.
     *
     * The check below is NOT the subscriber guard that follows it. That one refuses to withdraw a
     * plan somebody is paying for and answers HTTP 200 with a JSON error; it is a
     * customer-protection rule and it never looked at who was asking. A request for a plan with
     * no subscriber walked straight past it into the `update()`.
     */
    /**
     * REFUSE ANYTHING THAT IS NOT THE PLATFORM OPERATOR. GAP-307.
     *
     * One expression, used by both of this controller's operator-only writes, so the two cannot
     * drift -- which is the failure this family has already produced elsewhere (a second copy of a
     * gate that refused the store's own owner).
     *
     * `instanceof User` BEFORE `isOperator()`: the helper reads `->type` off whatever it is given,
     * and a `Customer` can carry that column. `abort()` rather than a returned response because
     * these are browser navigations and it is the same answer `PlatformOperatorOnly` gives.
     */
    private function refuseUnlessPlatformOperator()
    {
        $actor = auth()->user();

        if (! $actor instanceof \App\Models\User || ! \App\Policies\UserPolicy::isOperator($actor)) {
            abort(403, __('Permission denied.'));
        }

        return null;
    }

    public function planDisable(Request $request)
    {
        $actor = auth()->user();

        /*
         * GAP-307. `instanceof User` FIRST, THEN `isOperator()` -- the same order
         * `PlatformOperatorOnly:113` uses, and for the same two reasons.
         *
         * WHAT STOOD HERE: `$actor->type !== UserPolicy::PLATFORM_ADMIN`, which is class-BLIND. It
         * reads `->type` off whatever it is handed, and `'type'` is in `Customer::$fillable`, so a
         * `Customer` row carrying the word `super admin` satisfied it. MEASURED before the fix, by
         * calling this method directly with such a principal on the default guard: it answered
         * **200 `{"success":"Plan successfully unable."}`** and disabled the plan.
         *
         * LATENT RATHER THAN LIVE, and this comment says which: these are `web` routes behind
         * `auth`, so in production the default guard holds a `User` and the middleware -- which
         * already tests `instanceof` -- runs first. The defect is that this check is not a correct
         * control in its own right, and `PlatformOperatorOnly` is therefore the SOLE one. CLAUDE.md
         * records how that breaks: a later route declaration for the same method+URI replaces the
         * earlier one and drops its middleware silently.
         *
         * `isOperator()` RATHER THAN A RAW `!==`, which is the second half: that helper normalises
         * a trailing space to match MySQL's PAD SPACE comparison, so `'super admin '` is the
         * operator to the engine and was NOT the operator to this line.
         */
        if (! $actor instanceof \App\Models\User || ! \App\Policies\UserPolicy::isOperator($actor)) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $userPlan = User::where('plan_id' , $request->id)->first();
        if($userPlan != null && $request->id != 1 && $request->is_disable == 0)
        {
            return response()->json(['error' =>__('The company has subscribed to this plan, so it cannot be disabled.')]);
        }

        Plan::where('id', $request->id)->update(['is_disable' => $request->is_disable]);

        if ($request->is_disable == 1) {
            return response()->json(['success' => __('Plan successfully unable.')]);

        } else {
            return response()->json(['success' => __('Plan successfully disable.')]);
        }
    }

    public function planTrial(Request $request , $plan)
    {
        $objUser = \Auth::user();
        $planID  = \Illuminate\Support\Facades\Crypt::decrypt($plan);
        $plan    = Plan::find($planID);

        if($plan)
        {
            // SERVER-SIDE TRIAL ELIGIBILITY. These three conditions existed only in the
            // Blade template (resources/views/plans/index.blade.php:296), which decides
            // whether the button is rendered -- it does not stop anyone requesting the
            // route directly. Without them a merchant could re-activate a free trial of a
            // PAID plan indefinitely, each call re-running assignPlan() and pushing
            // plan_expire_date out again: unlimited free access to paid plans, and to every
            // module those plans grant.
            //
            // `trial_plan` is the durable "has already trialled" marker: it is written here
            // and never reset anywhere in the codebase (assignPlan() clears
            // trial_expire_date, not trial_plan), so `!= 0` is a permanent one-trial rule,
            // which is what the template intends.
            if ((int) $objUser->trial_plan !== 0) {
                return redirect()->back()->with('error', __('You have already used your free trial.'));
            }

            if ((int) $plan->trial !== 1 || empty($plan->trial_days)) {
                return redirect()->back()->with('error', __('This plan does not offer a free trial.'));
            }

            if($plan->price > 0)
            {
                $user = User::find($objUser->id);
                $user->trial_plan = $planID;
                $currentDate = date('Y-m-d');
                $numberOfDaysToAdd = $plan->trial_days;

                $newDate = date('Y-m-d', strtotime($currentDate . ' + ' . $numberOfDaysToAdd . ' days'));
                $user->trial_expire_date = $newDate;
                $user->save();

                $objUser->assignPlan($planID);

                return redirect()->route('plan.index')->with('success', __('Free trial plan successfully activated.'));
            }
            else
            {
                return redirect()->back()->with('error', __('Something is wrong.'));
            }
        }
        else
        {
            return redirect()->back()->with('error', __('Plan not found.'));
        }
    }

    /**
     * Mark a plan order refunded and drop its beneficiary to the free plan.
     *
     * Authorization is on the route (PlatformOperatorOnly, routes/web.php:525). The defects
     * fixed here are below that line:
     *
     *   - IT REFUNDED THE WRONG ORDER. The route is `/refund/{id}/{user_id}` but the body
     *     read `$request->id` -- a query/body parameter, not the route segment. With no `id`
     *     in the query string `$request->id` is null, so `where('id', null)` matched nothing:
     *     the order was never marked refunded while the user was still downgraded. With an
     *     `?id=` appended, any OTHER order could be flagged instead of the one in the URL.
     *   - The order and the user were never checked to exist, and were never checked to
     *     belong to each other, so `/refund/<orderA>/<userB>` downgraded an unrelated user.
     *   - `User::find()` returning null fataled on `->assignPlan(1)`.
     *   - Not idempotent, not transactional, not audited.
     *
     * Plan id 1 is kept as the downgrade target because that is the existing semantic
     * throughout this codebase; it is now verified to exist rather than assumed.
     */
    public function refund(Request $request, $id, $user_id)
    {
        /*
         * GAP-307. AN IN-HANDLER OPERATOR CHECK, so `PlatformOperatorOnly` is not the only thing
         * standing in front of a plan refund. Measured before this: of the four routes in that
         * gate's `plan/order actions` bucket, `plan-disable` was the ONLY one with any in-handler
         * check at all -- this method had none, and it uses no route-model binding either, so
         * `SubstituteBindings` could not answer 404 ahead of the gate.
         *
         * `abort(403)` deliberately matches what the middleware already answers, so no principal
         * that succeeds today sees a different response: everyone this refuses is already refused
         * by the middleware with the same status.
         */
        if ($refusal = $this->refuseUnlessPlatformOperator()) {
            return $refusal;
        }

        $order = PlanOrder::find($id);

        if (! $order) {
            return redirect()->back()->with('error', __('Order not found.'));
        }

        // Resource validation: the order must actually belong to the user being downgraded.
        if ((int) $order->user_id !== (int) $user_id) {
            return redirect()->back()->with('error', __('This order does not belong to that user.'));
        }

        if ((int) $order->is_refund === 1) {
            return redirect()->back()->with('error', __('This order has already been refunded.'));
        }

        $user = User::find($user_id);
        $freePlan = Plan::find(1);

        if (! $user || ! $freePlan) {
            return redirect()->back()->with('error', __('Something is wrong.'));
        }

        $previousPlanId = $user->plan_id;

        DB::transaction(function () use ($order, $user) {
            $order->is_refund = 1;
            $order->save();

            $user->assignPlan(1);
        });

        AuditLog::record('plan_order_refunded', $order, [
            'is_refund' => 0,
            'plan_id'   => $previousPlanId,
        ], [
            'is_refund'      => 1,
            'plan_id'        => 1,
            'beneficiary_id' => $user->id,
        ]);

        return redirect()->back()->with('success', __('We successfully planned a refund and assigned a free plan.'));
    }

}
