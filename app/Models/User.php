<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Laratrust\Contracts\LaratrustUser;
use Laratrust\Traits\HasRolesAndPermissions;
use DB;
use Carbon\Carbon;
use Lab404\Impersonate\Models\Impersonate;
use Laravel\Paddle\Billable;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Support\Facades\Cache;

class User extends Authenticatable implements LaratrustUser, MustVerifyEmail, \LaraBug\Concerns\Larabugable
{
    use Cachable {
        Cachable::flushCache as cachableFlushCache;
    }
    
    use HasApiTokens, HasFactory, Notifiable,HasRolesAndPermissions, Impersonate,Billable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'profile_image',
        'type',
        'email_verified_at',
        'mobile',
        'register_type',
        'is_assign_store',
        'current_store',
        'language',
        'default_language',
        'plan_id',
        'plan_expire_date',
        'plan_is_active',
        'requested_plan',
        'storage_limit',
        'is_active',
        'created_by',
        'deliverysyria_secret',
        'remember_token',
        'is_enable_login',
        'active_module'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // P1-SEC-16: secrets that rode toArray()/JSON. Hidden only affects serialisation; attribute access is unchanged.
        'google2fa_secret',
        'deliverysyria_secret',
    ];

    public static $defalut_theme = [
        'stylique', 'greentic', 'techzonix'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        // Set only by a merchant answering a code sent to that number, and cleared whenever the
        // number changes. Deliberately NOT in $fillable: nothing may mass-assign a claim that
        // somebody proved they hold a phone number.
        // See Workdo\LynomiaChat\app\Services\MerchantContactVerification.
        'mobile_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function getProfileImageAttribute($value) {
        if (!empty($value)) {
            if(check_file('storage/'.$value)){
                return 'storage/'.$value;
            } else {
                return $value;
            }
        } else {
            return null;
        }

    }

    public function creatorId()
    {
        if($this->type == 'admin' || $this->type == 'super admin')
        {
            return $this->id;
        }
        else
        {
            return $this->created_by;
        }
    }

    public static function slugs($data)
    {
        $slug = '';
        
        $slug = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $data); // Remove special chars
        // $slug = transliterator_transliterate('Any-Latin; Latin-ASCII', $slug); // Transliterate to Latin
        $slug = strtolower(trim($slug)); // Convert to lowercase and trim
        $slug = preg_replace('/\s+/', '-', $slug); // Replace spaces with hyphens
        $slug = preg_replace('/-+/', '-', $slug); // Replace multiple hyphens with single hyphen

        // Remove special characters
        $slug = preg_replace('/[^a-zA-Z0-9\s-]/', '', $slug);

        // Replace multiple spaces with a single hyphen
        $slug = preg_replace('/\s+/', '-', trim($slug));
        // Convert to lowercase
        $slug = strtolower($slug);

        /*
         * THE FALLBACK HAS TO RUN **AFTER** THE ASCII PASS, AND IT USED TO RUN BEFORE IT -- so it
         * was dead code for every Arabic store name.
         *
         * Two sanitisation passes happen above. The first is Unicode-aware
         * (`/[^\p{L}\p{N}\s-]/u`) and KEEPS Arabic; the second is ASCII-only
         * (`/[^a-zA-Z0-9\s-]/`, no `/u`) and STRIPS it. The `empty($slug)` check sat between them,
         * testing a string that was not yet empty, while the pass that emptied it ran afterwards.
         *
         * MEASURED, on names a Syrian merchant would really type:
         *
         *     متجر سوريا    -> `-`     (just the hyphen the space became)
         *     صيدلية الشام  -> `-`     so the SECOND Arabic store collided into `-1`, the third `-2`
         *     مطعم          -> ``      single word, no space: AN EMPTY SLUG
         *     مقهى 24       -> `-24`
         *     Store Syria   -> `store-syria`   unaffected
         *
         * `stores.slug` is the storefront's routing segment (`/{slug}/home`), so an empty slug
         * makes that `//home` and a slug of `-` gives every Arabic-named merchant the same URL.
         * On a platform whose first-class languages are Arabic and English that is the ordinary
         * case, not an edge one.
         *
         * `! preg_match('/[a-z0-9]/', ...)` RATHER THAN `empty()`, because `-` and `-24`... no:
         * `-24` keeps its digits and is left alone. The test is "holds no alphanumeric character
         * at all", which is exactly the unusable set: `''`, `-`, `--`. A slug that kept any part of
         * the merchant's name keeps it.
         *
         * NOTHING NEW IS INVENTED. `'store-' . uniqid()` is the fallback this function already
         * shipped for an unusable slug; the decision was made, it just could not fire. The
         * commented-out `transliterator_transliterate('Any-Latin; Latin-ASCII', ...)` above shows
         * the original intent was transliteration, which would give `mtjr-swrya` instead and read
         * better -- but it needs the `intl` extension and it is a product decision about what a
         * merchant's URL looks like, so engineering does not pick it here. Carried as an owner
         * question.
         *
         * Existing stores are untouched: this mints new slugs only.
         */
        if (! preg_match('/[a-z0-9]/', $slug)) {
            $slug = 'store-' . uniqid();
        }

        $table = with(new Store)->getTable();
        $allSlugs = self::getRelatedSlugs($table, $slug ,$id = 0);

        /* PHASE 03 M5/M6. A RESERVED SLUG IS TREATED EXACTLY LIKE A TAKEN ONE.
           ----------------------------------------------------------------------------------
           Why `platform` may not be a store's slug is explained once, on the model that owns the
           column: `Store::RESERVED_SLUGS`. Here is the MINT path, and it SUFFIXES rather than
           refuses -- the merchant gets `platform-1`, exactly what already happens when the name
           they chose is taken -- because nobody is watching a registration form's slug derivation
           and failing it would block the sign-up.

           THE CALLER INVENTORY, CORRECTED AFTER AN INDEPENDENT REVIEW OF M5. An earlier version of
           this comment called this "the ONE place store slugs are minted (StoreController :134,
           :283 and :563)". Both halves were wrong. It is not the only writer of `stores.slug` --
           `AppSettingController::store()` and the Flutter store-settings endpoint in
           `Api\DashboardController` each assign one straight from request input, which is why the
           reserved list is on the model and those two consult it as a VALIDATION rule. And the
           inventory was incomplete: `Auth\RegisteredUserController` calls this at :137 and :273
           for merchant self-registration. (`ShopifyProductController` :252 and :537 also call it,
           but for a `ProductAttribute` slug, not a store's -- the suffix is cosmetic there because
           `product_attributes.slug` is not routed.)

           `PlatformSiteRoutesTest` and `ReservedStoreSlugTest` drive the halves: `/platform`
           resolves to the platform controller through the real router, a store named "Platform"
           does not get the bare slug here, and neither rename path accepts it. */
        if (! Store::slugIsReserved($slug) && !$allSlugs->contains('slug', $slug)) {
            return $slug;
        }
        for ($i = 1; $i <= 100; $i++) {
            $newSlug = $slug . '-' . $i;
            /* `platform-1` is not itself reserved today, so this check cannot currently be false.
               It is kept so that adding a two-word reserved slug later cannot be handed out here. */
            if (! Store::slugIsReserved($newSlug) && !$allSlugs->contains('slug', $newSlug)) {
                return $newSlug;

            }
        }

        /*
         * P01-DOMAIN-IDENTITY-AUDIT section 3.2, STAGE 4 -- the loop used to fall off the end and
         * this function RETURNED NULL.
         *
         * The audit measured the consequence on MySQL 8.0.46 and corrected its own first draft
         * while doing so, which is why the remedy is shaped this way. An explicit NULL into the
         * `NOT NULL` `slug` column is refused under BOTH `strict => true` and `strict => false`
         * (non-strict mode waives implicit defaults and truncation, not a NOT NULL violation from
         * an explicit NULL), so the real consequence was not a silent `whereNull` fail-open -- it
         * was a HARD FAILURE: the 101st colliding store could not be created at all and the
         * merchant got a 500.
         *
         * The threshold was "101 ARABIC-NAMED stores, not 101 identically-named ones", because
         * section 3.1 collapsed every Arabic name to the same `-`. That half is fixed above, so
         * reaching this line now requires 101 stores whose names really do sanitise alike -- but
         * "harder to reach" is not "handled", and a merchant hitting it still gets a 500 rather
         * than a store.
         *
         * `uniqid()` and not a wider loop: a 101st suffix only moves the wall. The fallback is the
         * same one this function already uses for an unusable slug, so nothing new is invented,
         * and the result is guaranteed routable and unique.
         */
        return 'store-' . uniqid();
    }

    public function dateFormat($date)
    {
        $settings = Utility::Setting();

        return date($settings['site_date_format'], strtotime($date));
    }


    protected static function getRelatedSlugs($table, $slug, $id = 0)
    {
        return DB::table($table)->select()->where('slug', 'like', $slug . '%')->where('id', '<>', $id)->get();
    }

    public function stores()
    {
        return $this->hasMany(Store::class, 'created_by', 'id');
    }

    public function countStore()
    {
        return Store::where('created_by', '=', $this->creatorId())->count();
    }

    /**
     * The stores this user may switch INTO — P2-01-11's workspace chip.
     *
     * SCOPED BY `created_by = creatorId()` FOR EVERYONE, INCLUDING THE
     * OPERATOR — and the absence of a `type` branch here is the decision, not
     * an oversight.
     *
     * StoreController::changeStore():699 does branch, on the literal
     * `$user->type !== 'super admin'`. Two earlier attempts at this method
     * both went wrong:
     *
     *   1. Using UserPolicy::isOperator() here. The helper lowercases and
     *      rtrims, so for a row whose type reads 'Super Admin' it answers TRUE
     *      while changeStore()'s literal answers "apply the predicate". The
     *      chip would have listed EVERY STORE ON THE PLATFORM — other tenants'
     *      store names — to a principal the handler then 403s. A disclosure,
     *      even though no switch succeeds.
     *
     *   2. Copying the literal instead. That matched, exactly, the census
     *      pattern /type\s*[!=]==\s*'super admin'/ in
     *      OperatorCheckCensusTest, whose CEILING is an upper bound that may
     *      only FALL. Adding a site raises the count the project's own ratchet
     *      exists to drive down — and the comparison cannot be reworded to
     *      dodge the regex, because dodging it is the thing the ratchet is for.
     *
     * So the type question was not asked at all. For `admin` and `super admin`
     * creatorId() returns `$this->id` (creatorId(), above), so the operator's
     * chip lists the stores the OPERATOR created. That is strictly NARROWER
     * than changeStore() accepts, which is the direction the `is_active`
     * narrowing already established, and it cannot be broader for any shape of
     * `users.type` — null, a role name, 'admin', ' super admin', 'Super Admin'
     * — because every one of them took the same single branch.
     *
     * P1-SEC-67c CHANGED THAT, AND NARROWED IT FURTHER. changeStore() has asked
     * for a MERCHANT tenant root since P1-SEC-66, and for a principal rooted at
     * the platform operator (a merchant who demoted their own `type`, or staff
     * the operator created) creatorId() is the operator's id, so the chip listed
     * the OPERATOR'S stores and offered a control the handler refuses. The method
     * now asks the type question: the operator first (their own `creatorId()`
     * stores, exactly as before), then UserPolicy::merchantTenantRoot() for
     * everyone else, and an EMPTY list for a root that is not a merchant. The
     * first attempt's hazard above does not arise: the operator branch still
     * lists `creatorId()`'s stores, never every store, so a row typed
     * 'Super Admin' (isOperator() true, creatorId() = created_by) lists the
     * stores of that id -- none, in practice -- and cannot list another tenant's.
     * The census pattern is not matched either: no `type === 'super admin'`
     * literal is written here.
     *
     * It also bounds the query. The operator branch was
     * `Store::where('is_active', 1)->get()` with no limit: every store on the
     * platform hydrated into models on EVERY admin page render, with one
     * <a> and one route() call each. The operator keeps cross-tenant switching
     * through stores.index, which is where a list of every store belongs.
     *
     * `is_active` is the second deliberate narrowing. changeStore() looks the
     * store up first and only then refuses an inactive one with "Store is
     * locked", so an inactive store is reachable by its query but never
     * switchable. Offering it would be offering a guaranteed failure.
     *
     * Note `$this->stores()` is NOT used: that relation is
     * hasMany(Store, 'created_by', 'id') and keys on $this->id, so it returns
     * nothing at all for a staff member, whose stores belong to their admin.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Store>
     */
    public function switchableStores()
    {
        // P1-SEC-67c. This list "mirrors changeStore()'s predicate exactly", and changeStore() has asked for a MERCHANT tenant root since P1-SEC-66: for a
        // principal rooted at the platform operator (a merchant who demoted their own `type`, or staff the operator created) `creatorId()` is the operator's id,
        // so the chip printed the OPERATOR'S store names and offered a control changeStore() refuses. The operator is admitted first, as there, and keeps the
        // list of their own stores.
        $root = \App\Policies\UserPolicy::isOperator($this)
            ? (int) $this->creatorId()
            : \App\Policies\UserPolicy::merchantTenantRoot($this);

        if ($root === null || $root <= 0) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return Store::where('is_active', 1)
            ->where('created_by', $root)
            ->orderBy('name')
            ->get();
    }
    public function assignPlan($planID)
    {
        $user = \Auth::user();
        $plan = Plan::find($planID);
        $oldplan= Plan::where('id',$this->plan_id)->first();
        if($plan)
        {
            $this->plan_id = $plan->id;
            // The `;` that used to terminate this `if` made the block below unconditional.
            // Behaviour is unchanged by removing it -- clearing an already-null column is a
            // no-op, so both forms null the field in every reachable case -- but the guard
            // now means what it reads as, instead of being a trap for the next change.
            // trial_plan is deliberately NOT cleared here: it is the permanent
            // "has already trialled" marker PlanController::planTrial enforces against.
            if ($this->trial_expire_date != null) {
                $this->trial_expire_date = null;
            }
            if($plan->duration == 'Month')
            {
                $this->plan_expire_date = Carbon::now()->addMonths(1)->isoFormat('YYYY-MM-DD');
            }
            elseif($plan->duration == 'Year')
            {
                $this->plan_expire_date = Carbon::now()->addYears(1)->isoFormat('YYYY-MM-DD');
            }
            else if($plan->duration == 'Unlimited')
            {
                if (isset($plan->trial_days) && !empty($plan->trial_days) && $plan->price == 0) {
                    $this->plan_expire_date = Carbon::now()->addDays($plan->trial_days)->isoFormat('YYYY-MM-DD');
                } else {
                    $this->plan_expire_date = null;
                }
            }

            $modules = $plan->modules ?? null;
            if(!empty($modules))
            {
                $modules_array = explode(',',$modules);
                $currentActiveModules = userActiveModule::where('user_id', $this->id)->pluck('module')->toArray();

                /*
                 * `$oldplan?->` -- it is NULLABLE. `Plan::where('id', $this->plan_id)->first()`
                 * (above) returns null for a subject whose stored `plan_id` matches no row, and
                 * Laravel promotes the resulting warning to an `ErrorException`. That threw HERE,
                 * after `plan_id` and `plan_expire_date` were mutated in memory and BEFORE
                 * `$this->save()` below -- so on a gateway path the `PlanOrder` row was already
                 * written and the money captured while the entitlement was never stored. Null is
                 * falsy, so every case that does not currently throw is byte-identical: it takes
                 * the replace branch, which is what already happens.
                 *
                 * THE FIRST OPERAND IS DELIBERATELY LEFT READING `$user`, THE ACTING PRINCIPAL.
                 * Changing it to `$this` looks like the same correction as the delete below and is
                 * the opposite: every merchant who has held a plan has a non-empty
                 * `active_module`, so `$this` would make it true on exactly the operator-driven
                 * downgrade path, and the merge branch would then PRESERVE modules the new plan
                 * does not grant whenever the old plan was a custom plan. That re-opens the
                 * over-entitlement the delete below closes, and whether a custom plan keeps its
                 * extras through an operator-initiated change is an owner decision. Found by
                 * independent review, which named it as a trap rather than a follow-on.
                 */
                if(!empty($user->active_module) && $oldplan?->custom_plan == 1)
                {
                    $user_module = $currentActiveModules;
                    foreach ($modules_array as $module) {
                        if(!in_array($module,$user_module)){
                            array_push($user_module,$module);
                        }
                    }
                }
                else
                {
                    $user_module = $modules_array;
                }


                // Sidebar Performance Changes
                $newModules = array_diff($user_module, $currentActiveModules);
                $modulesToRemove = array_diff($currentActiveModules, $user_module);

                foreach ($newModules as $moduleName) {
                    userActiveModule::create([
                        'user_id' => $this->id,
                        'module' => $moduleName,
                    ]);
                }

                foreach ($modulesToRemove as $moduleName) {
                    /*
                     * `$this->id`, NOT `$user->id`. `$user` is `\Auth::user()` (read at the top of
                     * this method) -- the principal performing the change, which is NOT always the
                     * subject of it. `$modulesToRemove` was computed from `$this`'s own rows and the
                     * `create()` above already writes `$this->id`, so the delete reading a different
                     * user was a plain inconsistency inside one loop body, not a design.
                     *
                     * MEASURED on the operator-driven path, which is the one that diverges:
                     * `BankTransferController::orderapprove()` loads the buyer explicitly
                     * (`$user = User::find($order->user_id)`) and calls `assignPlan()` on them while
                     * the OPERATOR is signed in. Approving a downgrade therefore did both halves to
                     * the wrong people -- the merchant kept modules the new plan does not grant, so
                     * the downgrade did not downgrade, and the operator lost those modules from
                     * their own account. Both are pinned by
                     * `tests/Feature/Saas/PlanAssignmentTargetsTheRightUserTest.php`, in both
                     * directions, with the self-service path asserted byte-identical.
                     */
                    userActiveModule::where('user_id', $this->id)->where('module', $moduleName)->delete();
                }

                $this->active_module = implode(',',$user_module);

            }

            $this->save();

            /*
             * `created_by` IS NOT A TENANT, AND THESE TWO CASCADES READ IT AS ONE.
             *
             * Both select "the subject's own rows" as `created_by = $this->id`. That is right for
             * a merchant and catastrophic for the platform operator, because EVERY MERCHANT IS
             * CREATED WITH `created_by = 1` -- `Auth\RegisteredUserController:133` and `:286`,
             * `StoreController:280` -- and user 1 is the operator. With the operator as subject the
             * staff query therefore selects every merchant on the platform (`type != 'super admin'`
             * excludes only other operators), in `->get()` order with no `orderBy`, and deactivates
             * all of them past `$plan->max_users`. One request, platform-wide deactivation.
             *
             * Reachable: `StoreController::activePlan` and `PlanRequestController::acceptRequest`
             * both take the subject from input and are operator-gated, so an operator can name
             * themselves. Self-inflicted rather than an escalation, which is why this is a safety
             * guard and not a security report.
             *
             * This is the same trap CLAUDE.md records costing an account takeover once already:
             * "`created_by = 1` is NOT a tenant, and reading it as one was an account-takeover".
             *
             * NOTHING IS LOST BY SKIPPING THEM. `helper.php:1932-1933` gives a `super admin` every
             * active module regardless of any row, so an operator is not plan-limited in the first
             * place -- there are no operator seats or operator stores for a plan to cap. The
             * operator's own plan row above still updates exactly as before, so this does not
             * decide whether an operator may hold a plan; only that holding one cannot deactivate
             * other people's accounts.
             *
             * The guard is on the CASCADES rather than on the method's audience on purpose:
             * refusing `assignPlan()` outright for an operator subject would change whether an
             * operator can buy a plan at all, and that is a product question.
             */
            $subjectIsPlatformOperator = strtolower(trim((string) $this->type)) === 'super admin';

            $users    = $subjectIsPlatformOperator
                ? collect()
                : User::where('created_by', '=', $this->id)->where('type', '!=', 'super admin')->get();
           
            if($plan->max_users == -1)
            {
                foreach($users as $user)
                {
                    $user->is_active = 1;
                    $user->save();
                }
            }
            else
            {
                $userCount = 0;
                foreach($users as $user)
                {
                    $userCount++;
                    if($userCount <= $plan->max_users)
                    {
                        $user->is_active = 1;
                        $user->save();
                    }
                    else
                    {
                        $user->is_active = 0;
                        $user->save();
                    }
                }
            }

            // The twin of the query above, guarded for the same reason. Stores are created with
            // `created_by => $user->id` (the merchant), so an operator subject selects only stores
            // the operator personally created -- a far smaller blast radius than the staff query,
            // but the same wrong question, and leaving one of a pair guarded is how a guard rots.
            $stores    = $subjectIsPlatformOperator
                ? collect()
                : Store::where('created_by', '=', $this->id)->get();

            if ($plan->max_stores == -1) {
                foreach ($stores as $store) {
                    $store->is_active = 1;
                    $store->save();
                }
            } else {
                $storeCount = 0;
                foreach ($stores as $store) {
                    $storeCount++;
                    if ($storeCount <= $plan->max_stores) {
                        $store->is_active = 1;
                        $store->save();
                    } else {
                        $store->is_active = 0;
                        $store->save();
                    }
                }
            }

            return ['is_success' => true];
        }
        else
        {
            return [
                'is_success' => false,
                'error' => 'Plan is deleted.',
            ];
        }
    }

    public function currentPlan()
    {
        return $this->hasOne(Plan::class, 'id', 'plan_id');
    }

    public function countProducts()
    {
        return Product::where('created_by', '=', $this->creatorId())->where('store_id', getCurrentStore())->count();
    }

    public function countCompany()
    {
        return User::where('type', '=', 'admin')->where('created_by', '=', $this->creatorId())->count();
    }

    // Relationship with users created by the user
    public function createdAdmins()
    {
        return $this->hasMany(User::class, 'created_by')->where('type', 'admin');
    }

    public static $superadmin_activated_module = [
        'LandingPage'
    ];

    public function totalStoreUser($id)
    {
        return User::where('created_by', '=', $id)->count();
    }

    public function totalStoreCustomer($id)
    {
        return Customer::where('store_id', '=', $id)->count();
    }

    public function totalStoreVender($id)
    {
        //return Vender::where('created_by', '=', $id)->count();
        return 0;
    }

    /**
     * Override flushCache() to resolve conflicts between Cachable and Laratrust
     */
    public function flushCache(): void
    {
        $this->cachableFlushCache([]); // Call Cachable's flushCache()

        // Call Laratrust's cache flush if it exists
        if (method_exists($this, 'flushUsersCache')) {
            $this->flushUsersCache();
        }

        if (method_exists($this, 'flushRolesCache')) {
            $this->flushRolesCache();
        }
    }

    /**
     * SEC-F6 -- IMPERSONATION. Explicit overrides of the two methods supplied by
     * the Lab404\Impersonate\Models\Impersonate trait used at :25.
     *
     * These are written out rather than inherited because the trait's own
     * defaults could NOT be verified in this environment (vendor/lab404 is
     * empty), and a control whose behaviour cannot be verified is not a control.
     * Phase 0 reported those defaults as permissive.
     *
     * These overrides and the explicit $this->authorize('impersonate', $user)
     * in StoreController::LoginWithAdmin are BOTH required: either one alone is
     * a single upstream default change, or a single forgotten call site, away
     * from reopening a platform takeover.
     */
    public function canImpersonate(): bool
    {
        return $this->type === \App\Policies\UserPolicy::PLATFORM_ADMIN;
    }

    /**
     * A platform administrator can never be impersonated by anyone, so a lower
     * account can never assume platform root.
     */
    public function canBeImpersonated(): bool
    {
        return $this->type !== \App\Policies\UserPolicy::PLATFORM_ADMIN;
    }

    /**
     * P1-SEC-14: what the error tracker is told about the signed-in user. The default was `toArray()`: every non-hidden column -- the e-mail
     * address, and the plaintext `google2fa_secret` the GoogleAuthentication module's migration adds -- outside the tracker's key filter.
     */
    public function toLarabug()
    {
        return ['id' => $this->id, 'type' => $this->type, 'created_by' => $this->created_by];
    }
}
