<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Config\Config;
use Grav\Common\Grav;
use Grav\Framework\Psr7\Response;
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
                'Access-Control-Allow-Methods' => 'GET, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type',
            ]);
        }

        $_SERVER['REQUEST_METHOD'] = $request->getMethod();

        $params = $request->getAttribute('route_params', []);
        $action = isset($params['subpath']) ? trim((string) $params['subpath'], '/') : '';

        require_once __DIR__ . '/MudMambersApi.php';
        $api = new MudMambersApi($this->grav);

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
        ], $output);
    }
}
