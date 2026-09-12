<?php

namespace Syifa\KeycloakSso;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Keycloak\Provider as KeycloakProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use Syifa\KeycloakSso\Http\Middleware\EnsureKeycloakRole;

class KeycloakSsoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/keycloak-sso.php', 'keycloak-sso');

        // Meneruskan konfigurasi ke config('services.keycloak.*'), yang dibaca oleh driver
        // Socialite 'keycloak' (socialiteproviders/keycloak). Dengan ini, aplikasi konsumen
        // cukup mengisi config/keycloak-sso.php pada satu tempat, tanpa perlu menduplikasi
        // konfigurasi di config/services.php.
        $this->app['config']->set('services.keycloak', [
            'client_id' => config('keycloak-sso.client_id'),
            'client_secret' => config('keycloak-sso.client_secret'),
            'redirect' => config('keycloak-sso.redirect_uri'),
            'base_url' => config('keycloak-sso.base_url'),
            'realms' => config('keycloak-sso.realm'),
        ]);
    }

    public function boot(Router $router): void
    {
        Event::listen(SocialiteWasCalled::class, function (SocialiteWasCalled $event): void {
            $event->extendSocialite('keycloak', KeycloakProvider::class);
        });

        $router->aliasMiddleware('keycloak.role', EnsureKeycloakRole::class);

        $this->publishes([
            __DIR__ . '/../config/keycloak-sso.php' => config_path('keycloak-sso.php'),
        ], 'keycloak-sso-config');

        Route::group([
            'prefix' => config('keycloak-sso.routes.prefix', 'auth/keycloak'),
            'middleware' => config('keycloak-sso.routes.middleware', ['web']),
        ], function (): void {
            $this->loadRoutesFrom(__DIR__ . '/routes.php');
        });
    }
}
