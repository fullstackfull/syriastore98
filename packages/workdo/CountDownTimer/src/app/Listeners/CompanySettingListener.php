<?php

namespace Workdo\CountDownTimer\app\Listeners;
use App\Events\CompanySettingEvent;

class CompanySettingListener
{
    /**
     * Handle the event.
     */
    public function handle(CompanySettingEvent $event): void
    {
        $module = 'CountDownTimer';
        $methodName = 'index';
        $controllerClass = "Workdo\\CountDownTimer\\Http\\Controllers\\Company\\SettingsController";
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
