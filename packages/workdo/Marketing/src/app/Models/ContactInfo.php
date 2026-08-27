<?php

namespace Workdo\Marketing\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ContactInfo extends Model
{
    use HasFactory, Cachable;

    /**
     * The attributes that are mass assignable.
     */
    protected $table = 'contact_infos'; 
    private static $cardcontactData = null;
    private static $cardflagContact = false;
    protected $fillable = [
        'store_id',
        
        'content',
        'is_enabled',
        'created_by'
    ];
    
    public static function cardContactData($id)
    {
        if (self::$cardcontactData == null) {
            if (self::$cardflagContact === false) {
                $contactDetail = ContactInfo::where('store_id', $id)->first();
                self::$cardcontactData = $contactDetail;
                self::$cardflagContact = true;
            }
        }
        return self::$cardcontactData;

    }
}