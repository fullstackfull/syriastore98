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

class User extends Authenticatable implements LaratrustUser, MustVerifyEmail
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

        // If slug is empty after sanitization, use a fallback
        if(empty($slug)) {
            $slug = 'store-' . uniqid();
        }

        // Remove special characters
        $slug = preg_replace('/[^a-zA-Z0-9\s-]/', '', $slug);
        
        // Replace multiple spaces with a single hyphen
        $slug = preg_replace('/\s+/', '-', trim($slug));
        // Convert to lowercase
        $slug = strtolower($slug);

        $table = with(new Store)->getTable();
        $allSlugs = self::getRelatedSlugs($table, $slug ,$id = 0);

        if (!$allSlugs->contains('slug', $slug)) {
            return $slug;
        }
        for ($i = 1; $i <= 100; $i++) {
            $newSlug = $slug . '-' . $i;
            if (!$allSlugs->contains('slug', $newSlug)) {
                return $newSlug;

            }
        }
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

                if(!empty($user->active_module) && $oldplan->custom_plan == 1)
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
                    userActiveModule::where('user_id', $user->id)->where('module', $moduleName)->delete();
                }

                $this->active_module = implode(',',$user_module);

            }

            $this->save();
            $users    = User::where('created_by', '=', $this->id)->where('type', '!=', 'super admin')->get();
           
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

            $stores    = Store::where('created_by', '=', $this->id)->get();

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
}