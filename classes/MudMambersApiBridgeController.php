<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Framework\Psr7\Response;
use Grav\Plugin\Api\Auth\ApiKeyAuthenticator;
use Grav\Plugin\Api\Auth\JwtAuthenticator;
use Grav\Plugin\Api\Auth\SessionAuthenticator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class MudMambersApiBridgeController
{
    public function __construct(
        protected readonly Grav $grav,
        protected readonly Config $config,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() === 'OPTIONS') {
            return new Response(204, [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'GET, PATCH, POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, X-Members-Nonce',
            ]);
        }

        $_SERVER['REQUEST_METHOD'] = $request->getMethod();

        parse_str($request->getUri()->getQuery(), $query);
        foreach ($query as $key => $value) {
            if (is_string($key)) {
                $_GET[$key] = $value;
            }
        }

        $params = $request->getAttribute('route_params', []);
        $action = isset($params['subpath']) ? trim((string) $params['subpath'], '/') : '';

        require_once __DIR__ . '/MudMambersApi.php';
        $api = new MudMambersApi($this->grav);
        $apiUser = $this->optionalApiUser($request);
        if ($apiUser !== null) {
            $api->setApiUser($apiUser);
        }

        $parsed = $request->getParsedBody();
        if (is_array($parsed)) {
            $api->setJsonBody($parsed);
        } else {
            $raw = (string) $request->getBody();
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $api->setJsonBody($decoded);
                }
            }
        }

        $level = ob_get_level();
        ob_start();
        try {
            $api->handle($action);
        } finally {
            $output = (string) ob_get_clean();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        $code = $api->getBridgeHttpCode();

        return new Response($code, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Members-Nonce',
            'X-Content-Type-Options' => 'nosniff',
        ], $output);
    }

    private function optionalApiUser(ServerRequestInterface $request): ?UserInterface
    {
        if ($request->getHeaderLine('X-API-Token') === ''
            && !str_starts_with($request->getHeaderLine('Authorization'), 'Bearer ')) {
            $authenticators = [];
            if ($this->config->get('plugins.api.auth.session_enabled', true)) {
                $authenticators[] = new SessionAuthenticator($this->grav);
            }
            foreach ($authenticators as $authenticator) {
                $user = $authenticator->authenticate($request);
                if ($user !== null) {
                    return $user;
                }
            }

            return null;
        }

        $authenticators = [];
        if ($this->config->get('plugins.api.auth.api_keys_enabled', true)) {
            $authenticators[] = new ApiKeyAuthenticator($this->grav);
        }
        if ($this->config->get('plugins.api.auth.jwt_enabled', true)) {
            $authenticators[] = new JwtAuthenticator($this->grav, $this->config);
        }
        if ($this->config->get('plugins.api.auth.session_enabled', true)) {
            $authenticators[] = new SessionAuthenticator($this->grav);
        }

        foreach ($authenticators as $authenticator) {
            $user = $authenticator->authenticate($request);
            if ($user !== null) {
                return $user;
            }
        }

        return null;
    }
}
