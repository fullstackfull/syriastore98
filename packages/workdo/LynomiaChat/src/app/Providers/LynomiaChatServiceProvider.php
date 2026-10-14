<?php

namespace Workdo\LynomiaChat\app\Providers;

use Illuminate\Support\ServiceProvider;
use Workdo\LynomiaChat\app\Services\AccountLinker;
use Workdo\LynomiaChat\app\Services\AccountProvisioner;
use Workdo\LynomiaChat\app\Services\EventSubscriptionManager;
use Workdo\LynomiaChat\app\Services\IdentityProvisioner;
use Workdo\LynomiaChat\app\Services\MerchantInboxGateway;
use Workdo\LynomiaChat\app\Services\PlatformApiClient;
use Workdo\LynomiaChat\app\Services\SeatAllowanceSync;
use Workdo\LynomiaChat\app\Services\SsoLinkIssuer;

class LynomiaChatServiceProvider extends ServiceProvider
{
    protected string $moduleName = 'LynomiaChat';
    protected string $moduleNameLower = 'lynomia-chat';

    public function register(): void
    {
        $this->app->register(EventServiceProvider::class);

        // Merged rather than published, so a deployment cannot end up with a stale copy of
        // the module's own defaults in config/.
        $this->mergeConfigFrom(__DIR__ . '/../../config/lynomia.php', 'lynomia');

        // One client per request. Every service takes it by constructor injection, so a test
        // swaps the single binding and the whole module is exercised against a fake.
        $this->app->singleton(PlatformApiClient::class, fn () => PlatformApiClient::fromConfig());

        $this->app->bind(IdentityProvisioner::class, fn ($app) => new IdentityProvisioner($app->make(PlatformApiClient::class)));

        $this->app->bind(AccountProvisioner::class, fn ($app) => new AccountProvisioner(
            $app->make(PlatformApiClient::class),
            $app->make(IdentityProvisioner::class),
            $app->make(SeatAllowanceSync::class),
            $app->make(EventSubscriptionManager::class)
        ));
        $this->app->bind(AccountLinker::class, fn ($app) => new AccountLinker(
            $app->make(PlatformApiClient::class),
            $app->make(IdentityProvisioner::class),
            $app->make(SeatAllowanceSync::class)
        ));
        $this->app->bind(EventSubscriptionManager::class, fn ($app) => new EventSubscriptionManager(
            $app->make(MerchantInboxGateway::class)
        ));
        $this->app->bind(SeatAllowanceSync::class, fn ($app) => new SeatAllowanceSync(
            $app->make(PlatformApiClient::class)
        ));
        $this->app->bind(MerchantInboxGateway::class, fn ($app) => new MerchantInboxGateway(
            $app->make(PlatformApiClient::class),
            $app->make(IdentityProvisioner::class)
        ));
        $this->app->bind(SsoLinkIssuer::class, fn ($app) => new SsoLinkIssuer(
            $app->make(PlatformApiClient::class),
            $app->make(IdentityProvisioner::class)
        ));
    }

    public function boot(): void
    {
        // Single registration -- see the note in routes/web.php about why no module
        // RouteServiceProvider is registered.
        $this->loadRoutesFrom(__DIR__ . '/../../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'lynomia-chat');
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        $this->registerTranslations();
    }

    protected function registerTranslations(): void
    {
        $langPath = resource_path('lang/modules/' . $this->moduleNameLower);

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->moduleNameLower);
            $this->loadJsonTranslationsFrom($langPath);

            return;
        }

        $this->loadTranslationsFrom(__DIR__ . '/../../resources/lang', $this->moduleNameLower);
        $this->loadJsonTranslationsFrom(__DIR__ . '/../../resources/lang');
    }
}
