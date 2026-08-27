<?php

namespace Workdo\SidebarCustomization\app\Models;

use GeneaLabs\LaravelModelCaching\Traits\Cachable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SideMenuOption extends Model
{
    use HasFactory, Cachable;

    protected $table = 'side_menus_option';
    protected $fillable = [];

    public static $menus = [
        'mainmenu' => 'Main Menu',
        'submenu' => 'Sub Menu'
    ];


    public static $links = [
        'hash' => 'Hash Link (#)',
        'external' => 'Out Site Link',
        'internal' => 'Site Link',
    ];

    public static $show_window = [
        'same_window' => 'Existing Window/Tab',
        'new_window' => 'Isolated Window/Tab',
        'iframe' => 'Embedded iFrame'
    ];

    public static $hrefs = [
        'http://' => 'http://',
        'https://' => 'https://'
    ];

    public function creatorName()
    {
        return $this->hasOne(User::class, 'id', 'created_by');
    }

    public function getParentIdByName()
    {
        return $this->hasOne(SideMenuOption::class, 'id', 'parent_id');
    }

    public function childs()
    {
        return $this->hasMany(SideMenuOption::class, 'parent_id', 'id')->orderBy('position');
    }

    public function children()
    {
        return $this->hasMany(SideMenuOption::class, 'parent_id', 'name');
    }
}
