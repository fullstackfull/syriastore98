<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'setlocate' => \App\Http\Middleware\SetLocate::class,
            'themelanguage' => \App\Http\Middleware\ThemeLanguage::class,
            'APILog' => \App\Http\Middleware\APILog::class,
            'AdminApiLog' => \App\Http\Middleware\AdminApiLog::class,
            'xss' => \App\Http\Middleware\XSS::class,
            'activeTheme' => \App\Http\Middleware\ActiveTheme::class,
            'custom.auth' => \App\Http\Middleware\CustomAuth::class,
            'PlanModuleCheck' => \App\Http\Middleware\PlanModuleCheck::class,
            'verified' => \App\Http\Middleware\EmailIsVerified::class,
            'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
            '2fa' => \PragmaRX\Google2FALaravel\Middleware::class,
            'demo_mode' => \App\Http\Middleware\DemoModeMiddleware::class,
        ]);
        // Append middleware to the 'web' group
        $middleware->appendToGroup('web', \App\Http\Middleware\SetLocate::class);
        // Exclude specific routes from CSRF protection
        $middleware->validateCsrfTokens(
            except: [
                'iyzipay/callback/*',
                '/aamarpay/*',
                '*/aamarpay/callback?*',
                '*/get-payment-paytm',
                '*/get-payment-aamarpay?*',
                '*/get-payment-iyzico',
                'plan-get-phonepe-status',
                '*/store-payment-phonepe',
                '*/get-donation-payment-paytm',
                '*/get-donation-payment-iyzipay',
                '*/get-donation-payment-aamarpay',
                'plan-easebuzz-payment-notify*',
                '*get-payment-easebuzz*',
                '/login',
                '/duologin',
                'plan-get-Powertranz-status*',
                'store-get-Powertranz-status*',
                'campaigns-card-payment/',
                'campaigns-Powertranz',
                'plan-payfast-payment',
                '/plan/sslcommerz/status/*',
                '*/get-payment-sslcommerz',
                '*/checkout',
                '*/get-ticket-payment-paytm',
                '*/get-ticket-payment-aamarpay',
                'join_us/store/',
              'store/paymera/trigger/*',
              'delivery-syria/status',
            ] // Add your routes here
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();