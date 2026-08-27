<?php

namespace workdo\Slack\app\Providers;

use app;
use App\Socialite\Socialite;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\SocialiteServiceProvider as SocialiteParentServiceProvider;

class SocialiteServiceProvider extends SocialiteParentServiceProvider
{
   
    public function register(): void
    {
        $this->app->singleton('Laravel\Socialite\Contracts\Factory', function ($app) {
            return new Socialite($app);
        });
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [];
    }
}
