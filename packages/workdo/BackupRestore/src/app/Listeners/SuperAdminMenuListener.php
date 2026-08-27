<?php

namespace Workdo\BackupRestore\app\Listeners;
use App\Events\SuperAdminMenuEvent;

class SuperAdminMenuListener
{
    /**
     * Handle the event.
     */
    public function handle(SuperAdminMenuEvent $event): void
    {
        if(module_is_active('BackupRestore'))
        {
            $module = 'BackupRestore';
            $menu = $event->menu;
            $menu->add([
                'category' => 'Operations',
                'title' => __('Backup & Restore'),
                'icon' => '3d-cube-sphere',
                'name' => 'backuprestore',
                'parent' => null,
                'order' => 250,
                'ignore_if' => [],
                'depend_on' => [],
                'route' => 'backuprestore.index',
                'module' => $module,
                'permission' => 'backup&restore manage'
            ]);
        }
    }
}
