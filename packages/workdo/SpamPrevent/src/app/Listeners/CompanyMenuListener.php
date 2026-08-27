<?php

namespace Workdo\SpamPrevent\app\Listeners;

use App\Events\CompanyMenuEvent;
use App\Models\Store;
use App\Models\Utility;
use Illuminate\Support\Facades\Cache;

class CompanyMenuListener
{
    /**
     * Handle the event.
     */
    public function handle(CompanyMenuEvent $event): void
    {
        if(module_is_active('SpamPrevent'))
        {
            $enable_spam_prevent = Utility::GetValueByName('enable_spam_prevent', getCurrentStore());
            if (isset($enable_spam_prevent) && $enable_spam_prevent == 'on') {
                $module = 'SpamPrevent';
                $menu = $event->menu;
                $menu->add([
                    'title' => __('Comments'),
                    'icon' => 'message-circle',
                    'name' => 'comment',
                    'parent' => null,
                    'order' => 125,
                    'ignore_if' => [],
                    'depend_on' => [],
                    'route' => 'spamprevent.index',
                    'module' => $module,
                    'permission' => 'Manage SpamPrevent'
                ]);
            }
        }
    }
}
