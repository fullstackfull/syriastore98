<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Auth\Passwords\CanResetPassword;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Paddle\Billable;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\SoftDeletes;  
use App\Models\Concerns\BelongsToStore;
class Customer extends Authenticatable implements CanResetPasswordContract
{

    use Cachable, Notifiable, CanResetPassword,HasApiTokens,Billable,SoftDeletes, BelongsToStore;

    protected $guard = 'customers';
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'profile_image',
        'type',
        'email_verified_at',
        'mobile',
        'register_type',
        'regiester_date',
        'last_active',
        'status',
        'date_of_birth',
        'firebase_token',
        'device_type',
        'google_id',
        'apple_id',
        'facebook_id',
        'password_otp',
        'password_otp_datetime',
        
        'store_id',
        'affiliate_code',
        'used_affiliate_code',
        'commission_amount',
        'remember_token',
        'created_by',
        'social_id',
        'social_type'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'password_otp',   // P1-SEC-16: the reset one-time code / hash rode toArray()/JSON
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    // *********************************
    protected $appends = ["demo_field", "name", "address", "postcode"];

    public function getDemoFieldAttribute()
    {
        return 'demo_field';
    }

    public function getNameAttribute()
    {
        return $this->first_name.' '.$this->last_name;
    }

    public function getAddressAttribute()
    {
        $address  = '';
        if(!empty(auth('customers')->user())) {
            $DeliveryAddress = DeliveryAddress::where('customer_id', auth('customers')->user()->id)->where('default_address', 1)->first();
            if(!empty($DeliveryAddress)) {
                $address = $DeliveryAddress->address;
            } else {
                $DeliveryAddress = DeliveryAddress::where('customer_id', auth('customers')->user()->id)->where('title', 'main')->first();
                if(!empty($DeliveryAddress)) {
                    $address = $DeliveryAddress->address;
                }
            }
        }
        return $address;
    }

    public function getPostcodeAttribute()
    {
        $address = '';
        if(!empty(auth('customers')->user())) {
            $DeliveryAddress = DeliveryAddress::where('customer_id', auth('customers')->user()->id)->where('default_address', 1)->first();
            $address  = '';
            if(!empty($DeliveryAddress)) {
                $address = $DeliveryAddress->postcode;
            } else {
                $DeliveryAddress = DeliveryAddress::where('customer_id', auth('customers')->user()->id)->where('title',
                'main')->first();
                if(!empty($DeliveryAddress)) {
                    $address = $DeliveryAddress->postcode;
                }
            }
        }
        return $address;
    }

    // *********************************

    public function UserAdditionalDetail()
    {
        return $this->hasOne(UserAdditionalDetail::class, 'user_id',
        'id');
    }

    public static function dateFormat($date)
    {
        $settings = Utility::GetValueByName();

        return date($settings['site_date_format'], strtotime($date));
    }



    protected static function getRelatedSlugs($table, $slug, $id = 0)
    {
        return DB::table($table)->select()->where('slug', 'like', $slug . '%')->where('id', '<>', $id)->get();
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

    /**
     * P2.5-PKG-C (F-C-07). THE ORDERS THAT BELONG TO THIS CUSTOMER *IN THIS CUSTOMER'S OWN STORE*.
     *
     * ONE HELPER, BECAUSE THE DEFECT WAS THE DUPLICATION. `Ordercount()` and `total_spend()` were
     * the same five lines twice and BOTH were missing the same predicate, so fixing one would have
     * left the other leaking. They now share this query.
     *
     * WHAT WAS WRONG, MEASURED rather than argued (CustomerOrderAggregateTenancyTest):
     * none of the three queries carried a store predicate, and `Order` is absent from
     * `config/tenancy.php`'s `scoped_models`, so `StoreScope` adds none for any principal.
     *
     *     store A customer, one $100 guest order in A and one $900 guest order in B,
     *     both billed to the same email address:
     *         Ordercount()   -> 2     (should be 1)
     *         total_spend()  -> 1000  (should be 100)
     *
     * So store A's merchant was shown STORE B'S REVENUE attributed to their own customer. The
     * direct branch leaked the same way: `orders.customer_id` carries no composite key with
     * `store_id`, so the same id on an order in another store was counted too.
     *
     * THE GUEST BRANCH IS THE LIVE ONE, not an edge case. It runs whenever no order carries the
     * customer's own id -- the ordinary state for someone who checked out as a guest -- and it
     * matched `order_billing_details` BY EMAIL ACROSS EVERY STORE. That table has no `store_id`
     * column at all (measured), so the only route to a tenant is through `order_id` ->
     * `orders.store_id`, which is what the predicate below does.
     *
     * SERVER-DERIVED, never from request input: the store comes from this customer's own row, and
     * `Customer` is store-scoped, so the row itself was already resolved within a tenant.
     *
     * FAILS CLOSED on a customer with no store: such a row matches no store's orders, and
     * answering with every store's would be the defect inverted.
     *
     * Also no longer loads every matching row twice just to count them.
     */
    private function ownStoreOrders()
    {
        $storeId = (int) ($this->store_id ?? 0);

        if ($storeId <= 0) {
            return Order::whereRaw('1 = 0');
        }

        $direct = Order::where('store_id', $storeId)->where('customer_id', $this->id);

        if ($direct->clone()->exists()) {
            return $direct;
        }

        // The guest fallback, scoped through the order rather than the billing row, because the
        // billing table cannot be scoped on its own.
        return Order::where('store_id', $storeId)
            ->where('customer_id', 0)
            ->whereIn('id', OrderBillingDetail::where('email', $this->email)->pluck('order_id'));
    }

    public function Ordercount()
    {
        return $this->ownStoreOrders()->count();
    }

    /**
     * THE KEY THIS ACCOUNT'S PASSWORD-RESET TOKENS ARE STORED UNDER, and it is deliberately NOT
     * an email address.
     *
     * THE DEFECT THIS CLOSES. config/auth.php declares two password brokers over the SAME table:
     *
     *     'users'     => ['provider' => 'users',     'table' => 'password_reset_tokens', ...]
     *     'customers' => ['provider' => 'customers', 'table' => 'password_reset_tokens', ...]
     *
     * Laravel's DatabaseTokenRepository keys every row on the value this method returns, and
     * exists() matches on that value plus the token hash alone -- nothing records which broker
     * minted the row. While this returned $this->email, a row minted for a CUSTOMER with address E
     * was therefore accepted, verbatim, by the `users` broker for the USER with address E:
     *
     *   mint   Customer\Auth\CustomerLoginController:845-853  Password::broker('customers')->sendResetLink()
     *   accept Auth\NewPasswordController:41                   Password::reset()  -- the DEFAULT broker,
     *                                                          config/auth.php:18 'passwords' => 'users'
     *                                                          on the guest route routes/auth.php:36
     *
     * And the customer reset mail leaves through PER-STORE SMTP that a merchant sets themselves
     * (CustomerLoginController:826-837 reads MAIL_HOST/USERNAME/PASSWORD from that store's Settings,
     * which SettingController writes). Customer registration only rejects a duplicate email WITHIN
     * one store and never consults `users` (CustomerLoginController:728-737). So a merchant could:
     * register a customer in their own store carrying the platform operator's address, request a
     * customer password reset for it, read the token off their own relay, and POST it to
     * /reset-password -- which resets the OPERATOR. A full platform takeover from a merchant
     * account.
     *
     * Three things are closed by namespacing the key rather than by a schema change:
     *   1. a customers-broker token can no longer be found by the users broker -- the dangerous
     *      direction, and the takeover above;
     *   2. nor the reverse, a users-broker token spending itself on a customer account;
     *   3. DatabaseTokenRepository::create() deletes by this same key, so a customer-side request
     *      no longer silently invalidates an operator's in-flight reset (and vice versa), and two
     *      customers who share an address across stores no longer share one token row.
     *
     * WHY IT IS SAFE FOR THE EXISTING FLOW. retrieveByCredentials() resolves the account from the
     * plain `email` in the request, so the reset form is unchanged; only the token LOOKUP uses this
     * key. The reset URL is built from $this->email below, not from here, so the link a customer
     * receives is byte-identical. App\Notifications\CustomResetPasswordNotification does call
     * getEmailForPasswordReset() when building its URL, but it is only ever sent to a User
     * (Auth\PasswordResetLinkController:87), which is untouched.
     *
     * ONE-TIME COST AT DEPLOY: customer reset links already in flight stop validating, because
     * their rows are stored under the old key. The window is the brokers' 60-minute expiry, and
     * the customer simply requests another link.
     */
    public function getEmailForPasswordReset()
    {
        // Never a parsable address, so it cannot collide with a `users` row. Keyed on the id so
        // the token is bound to this one account rather than to an address two accounts may share.
        return 'customer:' . ($this->getKey() ?? 'unsaved:' . $this->email);
    }

    public function sendPasswordResetNotification($token)
    {
        $url = url("/{$this->storeSlug}/reset-password?token={$token}&email={$this->email}");

        $this->notify(new ResetPasswordNotification($url));
    }

    public function getaddress()
    {
        return $this->hasOne(DeliveryAddress::class, 'customer_id', 'id');
    }

    /**
     * P2.5-PKG-C (F-C-07). See `ownStoreOrders()`. This SUMS money, so it was the sharper half of
     * that defect: another store's `final_price` appeared in this store's figure, and AOV --
     * `total_spend() / Ordercount()` at `CustomerDataTable:96,202` and `CustomerController:560` --
     * was computed from two wrong inputs.
     */
    public function total_spend(){
        return $this->ownStoreOrders()->sum('final_price');
    }

    public function getTotal()
    {

        $total = $this->last_active;

        return $total;
    }

    public static function customer_field()
    {
        $fields = [
            'Name' => 'Name',
            'Email' => 'Email',
            'Last active' => 'Last active',
            'AOV' => 'AOV',
            'No. of Orders' => 'No. of Orders',
            'Total Spend' => 'Total Spend',
        ];
        return $fields;
    }

    public static $fields_status = [
        'Includes',
        'Excludes',
    ];

    public static $fields_status1 = [
        'Before',
        'After',
        'Equal',
    ];

    public static $fields_status2 = [
        'Less Than',
        'More Than',
        'Equal',
    ];
}