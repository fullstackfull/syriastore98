<?php

namespace Workdo\ThemeCustomizer\app\Listeners;
use App\Events\SuperAdminSettingEvent;

class SuperAdminSettingListener
{
    /**
     * Handle the event.
     */
    public function handle(SuperAdminSettingEvent $event): void
    {
        $module = 'ThemeCustomizer';
        $methodName = 'index';
        $controllerClass = "Workdo\\ThemeCustomizer\\Http\\Controllers\\SuperAdmin\\SettingsController";
        if (class_exists($controllerClass)) {
            $controller = \App::make($controllerClass);
            if (method_exists($controller, $methodName)) {
                $html = $event->html;
                $settings = $html->getSettings();
                $output =  $controller->{$methodName}($settings);
                $html->add([
                    'html' => $output->toHtml(),
                    'order' => 1,
                    'module' => $module,
                    'permission' => 'manage-dashboard'
                ]);
            }
        }
    }
}
