<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;

class MudMambersApi
{
    protected int $httpCode = 200;

    /** @var array<string, mixed>|null */
    protected ?array $jsonBody = null;

    public function __construct(protected readonly Grav $grav) {}

    public function getBridgeHttpCode(): int
    {
        return $this->httpCode;
    }

    /** @param array<string, mixed>|null $body */
    public function setJsonBody(?array $body): void
    {
        $this->jsonBody = $body;
    }

    public function handle(string $action): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($method === 'OPTIONS') {
            $this->httpCode = 204;

            return;
        }

        header('Content-Type: application/json; charset=UTF-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, PATCH, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');

        $action = trim($action, '/');

        if ($action === '' || $action === 'whoami') {
            $this->requireMethod($method, 'GET');
            $this->whoami();

            return;
        }

        if ($action === 'members') {
            $this->requireMethod($method, 'GET');
            $this->members();

            return;
        }

        if ($action === 'profile') {
            if ($method === 'GET') {
                $this->ownProfile();

                return;
            }
            if ($method === 'PATCH' || $method === 'POST') {
                $this->patchOwnProfile();

                return;
            }
            $this->respond(['ok' => false, 'error' => 'method_not_allowed'], 405);

            return;
        }

        if ($action === 'profile/cover') {
            $this->requireMethod($method, 'POST');
            $this->uploadCover();

            return;
        }

        if (preg_match('#^profile/([a-z0-9][a-z0-9._-]{1,31})$#i', $action, $m)) {
            $this->requireMethod($method, 'GET');
            $this->publicProfile(strtolower($m[1]));

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

    protected function members(): void
    {
        $search = trim((string) ($_GET['search'] ?? $_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 24)));
        $listing = MudMambersDirectory::listing($this->grav, $search, $page, $limit);
        $this->respond(['ok' => true] + $listing);
    }

    protected function ownProfile(): void
    {
        /** @var UserInterface $user */
        $user = $this->grav['user'];
        if (!$user->exists() || !MudMambersProfile::usernameOf($user)) {
            $this->respond(['ok' => false, 'error' => 'login_required'], 401);

            return;
        }

        $this->respond([
            'ok' => true,
            'profile' => MudMambersProfile::publicPayload($this->grav, $user, true),
        ]);
    }

    protected function publicProfile(string $username): void
    {
        $user = MudMambersAccounts::load($this->grav, $username);
        if ($user === null || !MudMambersAccounts::isActiveMember($user)) {
            $this->respond(['ok' => false, 'error' => 'not_found'], 404);

            return;
        }

        /** @var UserInterface $sessionUser */
        $sessionUser = $this->grav['user'];
        $canEdit = $sessionUser->exists()
            && MudMambersProfile::usernameOf($sessionUser) === $username
            && $sessionUser->authorize('site.login');

        if (!MudMambersProfile::isPublic($user) && !$canEdit) {
            $this->respond(['ok' => false, 'error' => 'not_found'], 404);

            return;
        }

        $this->respond([
            'ok' => true,
            'profile' => MudMambersProfile::publicPayload($this->grav, $user, $canEdit),
        ]);
    }

    protected function patchOwnProfile(): void
    {
        /** @var UserInterface $user */
        $user = $this->grav['user'];
        if (!$user->exists() || !MudMambersProfile::usernameOf($user)) {
            $this->respond(['ok' => false, 'error' => 'login_required'], 401);

            return;
        }

        $body = $this->readJsonBody();
        try {
            $profile = MudMambersProfile::updateOwn($this->grav, $user, $body);
        } catch (\Throwable $e) {
            $this->respond(['ok' => false, 'error' => $e->getMessage()], 400);

            return;
        }

        $this->respond(['ok' => true, 'profile' => $profile]);
    }

    protected function uploadCover(): void
    {
        /** @var UserInterface $user */
        $user = $this->grav['user'];
        if (!$user->exists() || !MudMambersProfile::usernameOf($user)) {
            $this->respond(['ok' => false, 'error' => 'login_required'], 401);

            return;
        }

        try {
            $path = MudMambersProfile::storeCoverUpload($this->grav, $user, $_FILES['profile_cover'] ?? []);
        } catch (\Throwable $e) {
            $this->respond(['ok' => false, 'error' => $e->getMessage()], 400);

            return;
        }

        $this->respond([
            'ok' => true,
            'profile_cover' => $path,
            'cover' => MudMambersProfile::coverUrl($this->grav, $user),
        ]);
    }

    /** @return array<string, mixed> */
    protected function readJsonBody(): array
    {
        if ($this->jsonBody !== null) {
            return $this->jsonBody;
        }

        $raw = (string) file_get_contents('php://input');
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function requireMethod(string $actual, string $expected): void
    {
        if ($actual !== $expected) {
            $this->respond(['ok' => false, 'error' => 'method_not_allowed'], 405);
            exit;
        }
    }

    /** @param array<string, mixed> $payload */
    protected function respond(array $payload, int $code = 200): void
    {
        $this->httpCode = $code;
        http_response_code($code);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
