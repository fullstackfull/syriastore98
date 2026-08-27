<?php

namespace Workdo\PartialPayments\app\Listeners;

use App\Events\CompanyMenuEvent;

class CompanyMenuListener
{
    /**
     * Handle the event.
     */
    public function handle(CompanyMenuEvent $event): void
    {
        if (module_is_active('PartialPayments'))
        {
            $module = 'PartialPayments';
            $menu = $event->menu;
            $menu->add([
                'title' => __('Partial Payments'),
                'icon' => 'coin',
                'name' => 'manage_partial_payment',
                'parent' => 'orders',
                'order' => 40,
                'ignore_if' => [],
                'depend_on' => [],
                'route' => 'partialpayments.index',
                'module' => $module,
                'permission' => 'Manage Partial Payment'
            ]);
        }
    }
}
