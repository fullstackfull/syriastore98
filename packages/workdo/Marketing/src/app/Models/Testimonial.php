<?php

namespace Workdo\Marketing\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Testimonial extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    protected $table = 'marketingTestimonial'; 
    private static $cardtestimonialData = null;
    private static $cardflagTestimonial = false;
    protected $fillable = [
        'store_id',
        
        'content',
        'is_enabled',
        'created_by'
    ];
    public static function cardTestimonialData($id)
    {
        if(self::$cardtestimonialData == null) {
            if(self::$cardflagTestimonial === false){
                $testDetail=testimonial::where('store_id', $id)->first();
                self::$cardtestimonialData = $testDetail; 
                self::$cardflagTestimonial =  true;
            }       
        }
        return self::$cardtestimonialData;

    }
}