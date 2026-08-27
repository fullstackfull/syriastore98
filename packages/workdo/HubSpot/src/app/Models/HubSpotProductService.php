<?php

namespace Workdo\HubSpot\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class HubSpotProductService extends Model
{
    use HasFactory, Cachable;

    protected $table = 'hubspot_product_services';
    protected $fillable = [
        'product_service_id',
        'hubspot_product_service_id',
        'store_id',
        
        'created_by',
    ];

    /**
     * Handle the event.
     *
     * @param  object  $Product
     * @return void
     */
    public static function saveData($Product, $request)
    {
        if (module_is_active('HubSpot')) {
            $setting = getAdminAllSetting(auth()->user()->id, getCurrentStore());
            if (isset($setting['hubspot_is_enable']) && $setting['hubspot_is_enable'] == 'on') {
                $store_id = getCurrentStore();
                $hubspot = new HubSpotProductService;
                $hubspot->product_service_id = $Product->id;
                $hubspot->hubspot_product_service_id = isset($request['hs_object_id']) ? $request['hs_object_id'] : '';
                $hubspot->store_id = $store_id;
                $hubspot->created_by = \Auth::user()->id;
                $hubspot->save();
                return redirect()->back()->with('success', __('Product Create succefully.'));
            }
        }
    }

    protected static function newFactory()
    {
        return \Workdo\HubSpot\Database\factories\HubSpotProductServiceFactory::new();
    }
}
