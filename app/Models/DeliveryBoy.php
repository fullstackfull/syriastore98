<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Laravel\Sanctum\HasApiTokens;

class DeliveryBoy extends Authenticatable
{
    use Cachable, HasFactory,HasApiTokens;
	
	protected $guard = 'deliveryboy';
    protected $guard_name = 'web';

    protected $fillable = [
        'name', 'email', 'profile_image', 'type', 'password', 'contact','created_by','store_id'
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
