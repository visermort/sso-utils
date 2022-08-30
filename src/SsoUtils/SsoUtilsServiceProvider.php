<?php

declare(strict_types=1);

namespace Erg\SsoUtils;

use Erg\Client\Sso\Auth\SsoUserProvider;
use Erg\Client\Sso\SsoServiceProvider;
use Erg\Client\SsoAuth\InternalAuthContext;
use Erg\Client\SsoAuth\UserAuthContext;
use GuzzleHttp\Client;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use function Erg\Client\Sso\Internal\getImpersonateId;
use function Erg\Client\Sso\Internal\getSelectedPersonnelId;
use function Erg\Client\Sso\Internal\getSelectedPositionId;



class SsoUtilsServiceProvider extends SsoServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register(): void
    {
        parent::register();

        $this->app->singleton('sso_utils', SsoUtils::class);

    }

    protected final function registerClient(): void
    {
        $this->app->singleton(SsoClient::class, function (Application $app) {
            $config = $app['config'];
            $ssoConfig = $config->get('services.sso');
            $url = $ssoConfig['url'] ?? 'http://erg-sso';
            $connectTimeout = $ssoConfig['connect_timeout'] ?? 2.0;
            $timeout = $ssoConfig['timeout'] ?? 5.0;

            $authContext = new InternalAuthContext(
                $ssoConfig['internal_api_login'] ?? '',
                $ssoConfig['internal_api_password'] ?? ''
            );

            $clientConfig = [
                'base_uri' => $url,
                'connect_timeout' => $connectTimeout,
                'timeout' => $timeout,
                'allow_redirects' => false
            ];

            if (App::environment('local')) {
                $clientConfig['verify'] = false;
            }

            $client = new Client($clientConfig);
            return new SsoClient($client, $authContext);
        });
    }

    protected final function setupAuthGuard(): void
    {
        /* @var AuthManager $authManager */
        $authManager = $this->app->get(AuthManager::class);

        $this->app->singleton(SsoUserProvider::class, function (Application $app) {
            $client = $app->get(SsoClient::class);

            return new SsoUserProvider($client);
        });

        // add custom SSO user provider to Laravel's auth
        $authManager->provider('sso', function (Application $app) {
            return $app->get(SsoUserProvider::class);
        });

        $authManager->viaRequest('sso', function (Request $request) {
            /* @var SsoUserProvider $userProvider */
            $userProvider = App::get(SsoUserProvider::class);

            $bearerToken = $request->bearerToken();
            if (empty($bearerToken)) {
                $bearerToken = $request->cookie('auth_token');
                if (empty($bearerToken)) {
                    return null;
                }
            }

            $impersonateId = getImpersonateId($request);
            $selectedPositionId = getSelectedPositionId($request);
            $selectedPersonnelId = getSelectedPersonnelId($request);

            $authContext = new UserAuthContext(
                $bearerToken,
                $impersonateId,
                $selectedPositionId,
                $selectedPersonnelId,
            );

            return $userProvider->retrieveByToken($authContext, $bearerToken);
        });
    }

}
