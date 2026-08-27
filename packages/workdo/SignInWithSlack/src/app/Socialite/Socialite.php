<?php namespace App\Socialite;

use Laravel\Socialite\SocialiteManager;
use Laravel\Socialite\Two\SlackProvider;

class Socialite extends SocialiteManager
{
    public function createSlackDriver()
    {
        $config = $this->app['config']['services.slack'];

        return $this->buildProvider(SlackProvider::class, $config);
    }
}