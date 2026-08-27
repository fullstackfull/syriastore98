<?php

namespace Workdo\SidebarCustomization\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SideMenuOrder extends Model
{
    use HasFactory, Cachable;

    protected $table = 'sidemenu_order';
    protected $fillable = [
        'menu_id', 
        'position',
        'order',   
        'store_id',
        
        'created_by'
    ];

}
