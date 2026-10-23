<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Concerns\BelongsToStore;

class DeliveryBoy extends Authenticatable
{
    use Cachable, HasFactory,HasApiTokens, BelongsToStore;
	
	protected $guard = 'deliveryboy';
    protected $guard_name = 'web';

protected $fillable = [
    'name', 'email', 'profile_image', 'type',
    'password', 'contact', 'commission_percentage', 
    'is_commission_enabled', 
    'created_by', 'store_id'
];

    /**
     * P1-PLT-08 FOLLOW-UP. The model declared NO $hidden, so `DeliveryBoy::...->get()` in
     * Api\DeliveryBoyApiController::index() serialised every driver's bcrypt `password` hash and login
     * e-mail into the JSON -- the same omission P1-SEC-16 closed on User and Customer. Hiding affects
     * serialisation only; attribute access is unchanged, so the guards that read ->password still do.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];
	
	public function creatorId()
    {
    return $this->created_by;
    }

    public function getProfileImageAttribute($value) {
        if (!empty($value)) {
            return 'storage/'.$value;
        } else {
            return null;
        }
        
    }
}