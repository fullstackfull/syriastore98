<?php

namespace Workdo\Campaigns\app\Listeners;

use App\Events\CompanyMenuEvent;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Models\Store;
use App\Models\User;
use App\Models\Plan;

class CompanyMenuListener
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(CompanyMenuEvent $event): void
    {
        if (module_is_active('Campaigns')) {
            $module = 'Campaigns';
            $menu = $event->menu;
            $menu->add([
                'title' => __('Campaigns'),
                'icon' => 'vector-triangle',
                'name' => 'campaigns',
                'parent' => 'cms',
                'order' => 80,
                'ignore_if' => [],
                'depend_on' => [],
                'route' => 'campaigns.index',
                'module' => $module,
                'permission' => 'Manage Campaigns'
            ]);
        }
    }
}
