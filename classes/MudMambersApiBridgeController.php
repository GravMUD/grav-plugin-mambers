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
use Psr\Http\Message\UploadedFileInterface;

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
                'Access-Control-Allow-Methods' => 'GET, PATCH, POST, DELETE, OPTIONS',
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

        $uploaded = $this->legacyUploadedFiles($request);
        if ($uploaded !== []) {
            $api->setUploadedFiles($uploaded);
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

    /** @return array<string, mixed> */
    private function legacyUploadedFiles(ServerRequestInterface $request): array
    {
        $tree = $request->getUploadedFiles();
        if ($tree === []) {
            return $_FILES;
        }

        $converted = $this->convertUploadTree($tree);

        return $converted !== [] ? array_replace_recursive($_FILES, $converted) : $_FILES;
    }

    /**
     * @param array<string, mixed> $tree
     * @return array<string, mixed>
     */
    private function convertUploadTree(array $tree): array
    {
        $out = [];
        foreach ($tree as $field => $node) {
            if ($node instanceof UploadedFileInterface) {
                $out[$field] = $this->legacyFileEntry($node);
                continue;
            }
            if (!is_array($node)) {
                continue;
            }

            $entries = [];
            foreach ($node as $item) {
                if ($item instanceof UploadedFileInterface) {
                    $entries[] = $this->legacyFileEntry($item);
                }
            }
            if ($entries !== []) {
                $out[$field] = $this->mergeLegacyEntries($entries);
            }
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $entries */
    private function mergeLegacyEntries(array $entries): array
    {
        $merged = [
            'name' => [],
            'type' => [],
            'tmp_name' => [],
            'error' => [],
            'size' => [],
        ];
        foreach ($entries as $entry) {
            $merged['name'][] = (string) ($entry['name'] ?? '');
            $merged['type'][] = (string) ($entry['type'] ?? '');
            $merged['tmp_name'][] = (string) ($entry['tmp_name'] ?? '');
            $merged['error'][] = (int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE);
            $merged['size'][] = (int) ($entry['size'] ?? 0);
        }

        return $merged;
    }

    /** @return array{name: string, type: string, tmp_name: string, error: int, size: int} */
    private function legacyFileEntry(UploadedFileInterface $file): array
    {
        $error = $file->getError();
        $tmp = '';
        if ($error === UPLOAD_ERR_OK) {
            $stream = $file->getStream();
            $meta = $stream->getMetadata('uri');
            if (is_string($meta) && $meta !== '' && is_file($meta)) {
                $tmp = $meta;
            } else {
                $tmp = (string) tempnam(sys_get_temp_dir(), 'mbr_');
                file_put_contents($tmp, (string) $stream);
            }
        }

        return [
            'name' => (string) ($file->getClientFilename() ?? ''),
            'type' => (string) ($file->getClientMediaType() ?? 'application/octet-stream'),
            'tmp_name' => $tmp,
            'error' => $error,
            'size' => (int) ($file->getSize() ?? 0),
        ];
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
