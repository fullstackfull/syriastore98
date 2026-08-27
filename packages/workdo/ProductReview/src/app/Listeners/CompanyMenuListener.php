<?php

namespace Workdo\ProductReview\app\Listeners;

use App\Events\CompanyMenuEvent;

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
        if (module_is_active('ProductReview')) {
            $module = 'ProductReview';
            $menu = $event->menu;
            $menu->add([
                'title' => __('Product Review'),
                'icon' => 'home',
                'name' => 'product_review',
                'parent' => 'products',
                'order' => 33,
                'ignore_if' => [],
                'depend_on' => [],
                'route' => 'product-review.index',
                'module' => $module,
                'permission' => ''
            ]);
        }
    }
}
