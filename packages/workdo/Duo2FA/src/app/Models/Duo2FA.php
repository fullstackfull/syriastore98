<?php

namespace Workdo\Duo2FA\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Workdo\Duo2FA\Database\factories\Duo2FAFactory;
use App\Models\{Plan, Store};
use App\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Cache;

class Duo2FA extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];

    protected static function newFactory(): Duo2FAFactory
    {
        //return Duo2FAFactory::new();
    }

    public static function duo2FAVerify($user)
    {
        $plan = Plan::where('id', $user->plan_id)->first();
        if ($plan && strpos($plan->modules, 'Duo2FA') !== false) {
            $store = getStoreById(getCurrentStore());
            if ($store) {
                request()->session()->put('duo2fa_user_id', $user->id);
                return redirect()->route('duo2fa.form');
            }
        }
        return redirect()->intended(RouteServiceProvider::HOME);
    }

}
