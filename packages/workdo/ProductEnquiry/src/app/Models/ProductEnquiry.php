<?php

namespace Workdo\ProductEnquiry\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductEnquiry extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'first_name', 'last_name','email','contact','answers','comment','customer_id',
        'store_id',
        'product_id',
        'created_by'
    ];

    public function admin(){
        return $this->hasone('App\Models\User'  ,'id' , 'created_by');
    }
}
