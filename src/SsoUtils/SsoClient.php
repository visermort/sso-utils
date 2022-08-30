<?php

declare(strict_types=1);

namespace Erg\SsoUtils;


use Erg\Client\Sso\SsoClient as BaseSsoClient;
use Erg\Client\Sso\Request;
use Erg\Client\InternalAuth\InternalAuth;
use Erg\Client\SsoAuth\InternalAuthContext;
use GuzzleHttp\Client as HttpClient;
use App\Utils\Sso\Entities\Position;
use Erg\Client\Sso\Http\Middleware\UnauthorizedException;

/**
 * Class SsoChild
 * @package App\Utils
 */
class SsoClient extends BaseSsoClient
{
    protected InternalAuthContext $internalAuth;
    protected \Closure $unauthorizedUserHandler;
    protected \Closure $accessDeniedForUserHandler;

    public function __construct(HttpClient $transport, InternalAuthContext $internalAuth)
    {
        $this->client = $transport;
        $this->internalAuth = $internalAuth;

        $this->unauthorizedUserHandler = \Closure::fromCallable(static function () {
            throw UnauthorizedException::notLoggedIn();
        });
        $this->accessDeniedForUserHandler = \Closure::fromCallable(static function () {
            throw UnauthorizedException::forPermissions([]);
        });

        parent::__construct($transport, $internalAuth);
    }

    /**
     * Поиск штатных должностей по ID
     *
     * @param string[] $ids
     * @return Position[]
     */
    public function getPositionsInternal(array $ids = []): array
    {
        $options = [
            'auth' => $this->internalAuth->getInternalAuth()

        ];
        // Для поиска по ID используем POST, чтобы обойти ограничение по query string
        if ([] !== $ids) {
            $method = 'post';
            $options['json']['ids'] = $ids;
        } else {
            $method = 'get';
        }

        $request = new Request(
            $method,
            "/internal/positions",
            $options,
            [
                401 => $this->unauthorizedUserHandler,
            ]
        );

        $response = $this->sendRequest($request);

        return $this->decodeArrayOfEntities($response, new Position());
    }
}
