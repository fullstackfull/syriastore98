<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Testimonial extends Model
{
    use HasFactory, Cachable;

    protected $fillable = [
        'user_id', 'category_id', 'product_id', 'rating_no', 'title', 'description', 'status', 'avatar', 'username'
    ];

    public function MainCategoryData()
    {
        return $this->hasOne(Category::class, 'id', 'category_id');
    }

    public function ProductData()
    {
        return $this->hasOne(Product::class, 'id', 'product_id');
    }

    public function UserData()
    {
        return $this->hasOne(User::class, 'id', 'user_id');
    }

    public static function ProductReview($no = 2, $id)
    {
        $product_review = Testimonial::where('product_id',$id)->first();
        return view('front_end.sections.pages.product_review', compact('product_review'))->render();
    }

    public static function AvregeRating($product_id = 0)
    {
        $rating = Testimonial::where('product_id', $product_id)->get();        
        if($rating) {
            $rating = array_column($rating->toArray(), 'rating_no');            
            $user = count($rating);
            if($user > 0) {
                $rating_sum = array_sum($rating);                
                $avg_rating = $rating_sum/$user;

                $Product = Product::find($product_id);                
                $Product->average_rating = number_format($avg_rating,0);
                $Product->save();
            }
        }
    }
}
