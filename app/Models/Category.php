<?php

namespace App\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Category extends Model
{

    use HasFactory, Cachable;

    protected $fillable = [
        'name',
        'slug',
        'image_url',
        'image_path',
        'icon_path',
        'parent_id',
        'trending',
        'status',
        'store_id'
    ];

    protected $hidden = [
        'image_url'
    ];

    protected $appends = ["demo_field", "total_product","image_path_full_url","icon_path_full_url"];

    /* ********************************
            Field Append Start
    ******************************** */

    public function getDemoFieldAttribute()
    {
        return 'demo_field';
    }

    public function product_details() {
        return $this->hasMany(Product::class, 'category_id', 'id');
    }

    public function getMaincategoryIdAttribute() {
        return $this->id;
    }

    public function getTotalProductAttribute() {
        $count = $this->product_details()->count();
        return $count ?? 0;
    }

    public function getIconImgPathAttribute($value)
    {
        $icon_path = 'uploads/require/dot.png';
        if(!empty($this->icon_path)) {
            $icon_path = $this->icon_path;
        }
        return $icon_path;
    }

    public function getImagePathFullUrlAttribute() {
        $image = [];
        if(!empty($this->image_path)) {
            return get_file($this->image_path);
        }
        return $image;
    }

    public function getIconPathFullUrlAttribute() {
		$image = [];
        if(!empty($this->icon_path)) {
        	return get_file($this->icon_path);
		}
        return $image;
    }

    public static function homePageCategory($themeId, $slug, $section, $no = 2)
    {
        $currentTheme = $themeId;
        $storeId = getCurrenctStoreId($slug);
        $best_seller_category = Category::where('status', 1)->where('store_id',$storeId)->limit($no)->get();

        return view('front_end.sections.homepage.category_slider', compact('slug','best_seller_category', 'themeId', 'section', 'currentTheme'))->render();
    }

    public static function homePageBestCategory($themeId, $slug, $section, $no = 2)
    {
        $currentTheme = $themeId;
        $storeId = getCurrenctStoreId($slug);
        $cat = Product::select('category_id')->where('store_id', $storeId)->groupBy('category_id')->pluck('category_id');
        $best_seller_category =  Category::whereIn('id', $cat->toArray())->limit($no)->get();

        return view('front_end.sections.homepage.category_slider', compact('slug', 'best_seller_category', 'themeId', 'section','currentTheme'))->render();
    }

    public static function categoryImageDelete($mainCategory)
    {
        if ($mainCategory->image_path !== '/storage/uploads/default.jpg' && \File::exists(base_path($mainCategory->image_path))) {
            Utility::changeStorageLimit(\Auth::user()->creatorId(), $mainCategory->image_path );
        }

        if ($mainCategory->icon_path !== '/storage/uploads/default.jpg' && \File::exists(base_path($mainCategory->icon_path))) {
            Utility::changeStorageLimit(\Auth::user()->creatorId(), $mainCategory->icon_path );
        }
    }

    public function parent_category()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function menuItems()
    {
        return $this->morphMany(MenuItem::class, 'menu_itemable');
    }
}
