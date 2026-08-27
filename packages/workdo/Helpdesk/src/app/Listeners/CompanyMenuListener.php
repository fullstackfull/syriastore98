<?php

namespace Workdo\Helpdesk\app\Listeners;

use App\Events\CompanyMenuEvent;
use App\Models\Store;
use Illuminate\Support\Facades\Cache;

class CompanyMenuListener
{
    /**
     * Handle the event.
     */
    public function handle(CompanyMenuEvent $event): void
    {
        if(module_is_active('Helpdesk'))
        {
            $module = 'Helpdesk';
            $menu = $event->menu;
            $menu->add([
                'title' => __('Helpdesk'),
                'icon' => 'headphones',
                'name' => 'helpdesk',
                'parent' => null,
                'order' => 359,
                'ignore_if' => [],
                'depend_on' => [],
                'route' => 'helpdesk.ticket.index',
                'module' => $module,
                'permission' => 'Manage Helpdesk Ticket'
            ]);
        }
    }
}

