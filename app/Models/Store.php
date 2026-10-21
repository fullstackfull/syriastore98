<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Store extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'theme_id',
        'slug',
        'default_language',
        'created_by',
        'is_active',
        'enable_pwa_store',
        'duo_setting_enabled',
        'duo_api_host_name',
        'duo_secret_key',
        'duo_integration_key'
    ];

    /**
     * Columns that must never leave the server inside a serialised Store. P1-SEC-20.
     *
     * The Duo2FA module adds the first two to `stores`, and the Donation and LotteryPrizes payment
     * handlers return `'store' => $store` as JSON to ANONYMOUS callers, so without this every column of
     * the row went out.
     */
    public const NEVER_SERIALISED = ['duo_secret_key', 'duo_integration_key'];

    /**
     * Strip them where the array form is built, rather than through `$hidden`.
     *
     * `$hidden` is object STATE, and Eloquent's `__sleep()` serialises every property -- including
     * `hidden`. `Store` is Cachable, so with a non-taggable cache store (CACHE_STORE=database) a Store
     * cached before this change comes back from `unserialize()` carrying the OLD, empty `$hidden` and
     * keeps leaking until the cache is flushed (found by the SEC-20 review; the test suite's array cache
     * holds live objects and cannot show it). A method comes from the class, not from the cached state.
     *
     * Serialisation only: every reader of the values (LaravelDuo, Duo2FAController, the module's settings
     * form) uses property access, which this does not touch. `toArray()`, `toJson()` and `jsonSerialize()`
     * all pass through here.
     */
    public function attributesToArray()
    {
        return \Illuminate\Support\Arr::except(parent::attributesToArray(), self::NEVER_SERIALISED);
    }

    public static function pwa_store($slug)
    {
        $store = getStore($slug);
        try {

            $pwa_data = \File::get(storage_path('uploads/customer_app/store_' . $store->id . '/manifest.json'));

            $pwa_data = json_decode($pwa_data);
        } catch (\Throwable $th) {
            $pwa_data = [];
        }
        return $pwa_data;
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
