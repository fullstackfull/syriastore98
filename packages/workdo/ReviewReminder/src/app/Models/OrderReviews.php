<?php

namespace Workdo\ReviewReminder\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use workdo\ReviewReminder\Database\factories\OrderReviewsFactory;

class OrderReviews extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name',
        'email',
        'rating',
        'description',
        'order_id',
        'product_order_id',
        'store_id',
    ];

    protected static function newFactory(): OrderReviewsFactory
    {
        //return OrderReviewsFactory::new();
    }
}
