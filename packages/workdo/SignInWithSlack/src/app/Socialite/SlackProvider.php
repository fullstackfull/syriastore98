<?php

namespace workdo\Slack\app\Providers;

use Laravel\Socialite\Two\User;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;


class SlackProvider extends AbstractProvider implements ProviderInterface
{
   
    
    public function register(): void
    {
        
    }

    public function provides(): array
    {
        return [];
    }


    protected $scopes = [
        'identity.basic',
        'identity.team',
        'identity.email',
        'identity.avatar'
    ];

    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase('https://slack.com/oauth/authorize', $state);
    }

    /**
     * {@inheritdoc}
     */
    protected function getTokenUrl()
    {
        return 'https://slack.com/api/oauth.access';
    }

    /**
     * {@inheritdoc}
     */
    protected function getUserByToken($token)
    {
        $options = ['headers' => ['Accept' => 'application/json']];
        $endpoint = 'https://slack.com/api/users.identity?token='.$token;
        $response = $this->getHttpClient()->get($endpoint, $options)->getBody()->getContents();

        return json_decode($response, true);
    }

    /**
     * {@inheritdoc}
     */
    protected function mapUserToObject(array $user)
    {
        return (new User)->setRaw($user)->map([
            'id'        => $user['user']['id'],
            'name'      => $user['user']['name'],
            'email'     => $user['user']['email'],
        ]);
    }
}
