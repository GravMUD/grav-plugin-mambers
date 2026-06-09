<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;

class MudMambersApi
{
    protected int $httpCode = 200;

    public function __construct(protected readonly Grav $grav) {}

    public function getBridgeHttpCode(): int
    {
        return $this->httpCode;
    }

    public function handle(string $action): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            $this->httpCode = 204;
            http_response_code(204);

            return;
        }

        $action = trim($action, '/');
        if ($action === '' || $action === 'whoami') {
            $this->whoami();

            return;
        }

        $this->respond(['ok' => false, 'error' => 'not_found'], 404);
    }

    protected function whoami(): void
    {
        /** @var UserInterface $user */
        $user = $this->grav['user'];
        $this->respond(MudMambersPermissions::whoamiPayload($user));
    }

    /** @param array<string, mixed> $payload */
    protected function respond(array $payload, int $code = 200): void
    {
        $this->httpCode = $code;
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
