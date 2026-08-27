<?php

namespace Workdo\Marketing\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Gallery extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    private static $cardgalleryData = null;
    private static $cardflagGallery = false;
    protected $fillable = [
        'store_id',
        
        'content',
        'is_enabled',
        'created_by'
    ];
    
    public static $gallaryOption = [
        'video' => 'Video',
        'image' => 'Image',
        'custom_image_link' => 'Custom Image',
        'custom_video_link' => 'Custom Video',
    ];

    public static function cardGalleryData($id)
    {
        if(self::$cardgalleryData == null) {
            if(self::$cardflagGallery === false){
                $galleryDetail=Gallery::where('store_id', $id)->first();
                self::$cardgalleryData = $galleryDetail; 
                self::$cardflagGallery =  true;
            }       
        }
        return self::$cardgalleryData;

    }
}