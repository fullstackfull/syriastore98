<?php

namespace Workdo\Marketing\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AppointmentDeatail extends Model
{
    private static $appData = null;
    use HasFactory, Cachable;
    protected $fillable = [
        'store_id',
        
        'name',
        'email',
        'phone',
        'date',
        'time',
        'status',
        'note',
        'created_by'
    ];

    public function getStoreName()
    {
        if (self::$appData == null) {
            $store = \App\Models\Store::find($this->store_id);
            if (!empty($store)) {
                self::$appData = $store->name;
            } else {
                return ' - ';
            }

        }
        return self::$appData;
    }
}
