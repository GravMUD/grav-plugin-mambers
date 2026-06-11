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

    /** @var array<string, mixed> */
    protected array $uploadedFiles = [];

    private ?UserInterface $apiUser = null;

    public function __construct(protected readonly Grav $grav) {}

    public function setApiUser(?UserInterface $user): void
    {
        $this->apiUser = $user;
    }

    public function getBridgeHttpCode(): int
    {
        return $this->httpCode;
    }

    /** @param array<string, mixed>|null $body */
    public function setJsonBody(?array $body): void
    {
        $this->jsonBody = $body;
    }

    /** @param array<string, mixed> $files */
    public function setUploadedFiles(array $files): void
    {
        $this->uploadedFiles = $files;
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
        header('Access-Control-Allow-Methods: GET, PATCH, POST, DELETE, OPTIONS');
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

        if ($action === 'feed') {
            $this->requireMethod($method, 'GET');
            $this->siteFeed();

            return;
        }

        if ($action === 'link-preview') {
            $this->requireMethod($method, 'POST');
            $this->linkPreview();

            return;
        }

        if ($action === 'giphy/search') {
            $this->requireMethod($method, 'GET');
            $this->giphySearch();

            return;
        }

        if ($action === 'activity') {
            $this->requireMethod($method, 'POST');
            $this->createActivity();

            return;
        }

        if (preg_match('#^activity/([a-f0-9]{12})/react$#i', $action, $m)) {
            $id = strtolower($m[1]);
            if ($method === 'POST') {
                $this->reactActivity($id);

                return;
            }
            if ($method === 'DELETE') {
                $this->unreactActivity($id);

                return;
            }
            $this->respond(['ok' => false, 'error' => 'method_not_allowed'], 405);

            return;
        }

        if (preg_match('#^activity/([a-f0-9]{12})$#i', $action, $m)) {
            $id = strtolower($m[1]);
            if ($method === 'PATCH') {
                $this->patchActivity($id);

                return;
            }
            if ($method === 'DELETE') {
                $this->deleteActivity($id);

                return;
            }
            $this->respond(['ok' => false, 'error' => 'method_not_allowed'], 405);

            return;
        }

        if (preg_match('#^activity/([a-z0-9][a-z0-9._-]{1,31})$#i', $action, $m)) {
            $this->requireMethod($method, 'GET');
            $this->userActivity(strtolower($m[1]));

            return;
        }

        if (preg_match('#^graph/stats/([a-z0-9][a-z0-9._-]{1,31})$#i', $action, $m)) {
            $this->requireMethod($method, 'GET');
            $this->graphStats(strtolower($m[1]));

            return;
        }

        if (preg_match('#^graph/followers/([a-z0-9][a-z0-9._-]{1,31})$#i', $action, $m)) {
            $this->requireMethod($method, 'GET');
            $this->graphList(strtolower($m[1]), 'followers');

            return;
        }

        if (preg_match('#^graph/following/([a-z0-9][a-z0-9._-]{1,31})$#i', $action, $m)) {
            $this->requireMethod($method, 'GET');
            $this->graphList(strtolower($m[1]), 'following');

            return;
        }

        if (preg_match('#^graph/follow/([a-z0-9][a-z0-9._-]{1,31})$#i', $action, $m)) {
            $target = strtolower($m[1]);
            if ($method === 'POST') {
                $this->graphFollow($target);

                return;
            }
            if ($method === 'DELETE') {
                $this->graphUnfollow($target);

                return;
            }
            $this->respond(['ok' => false, 'error' => 'method_not_allowed'], 405);

            return;
        }

        if (preg_match('#^graph/block/([a-z0-9][a-z0-9._-]{1,31})$#i', $action, $m)) {
            $target = strtolower($m[1]);
            if ($method === 'POST') {
                $this->graphBlock($target);

                return;
            }
            if ($method === 'DELETE') {
                $this->graphUnblock($target);

                return;
            }
            $this->respond(['ok' => false, 'error' => 'method_not_allowed'], 405);

            return;
        }

        $this->respond(['ok' => false, 'error' => 'not_found'], 404);
    }

    protected function whoami(): void
    {
        $user = $this->sessionUser();
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
        $user = $this->sessionUser();
        if (!$user->exists() || !MudMambersProfile::usernameOf($user)) {
            $this->respond(['ok' => false, 'error' => 'login_required'], 401);

            return;
        }

        require_once __DIR__ . '/MudMambersCsrf.php';
        $this->respond([
            'ok' => true,
            'profile' => MudMambersProfile::publicPayload($this->grav, $user, true),
            'write_nonce' => MudMambersCsrf::token($this->grav),
        ]);
    }

    protected function publicProfile(string $username): void
    {
        $user = MudMambersAccounts::load($this->grav, $username);
        if ($user === null || !MudMambersAccounts::isActiveMember($user)) {
            $this->respond(['ok' => false, 'error' => 'not_found'], 404);

            return;
        }

        $sessionUser = $this->sessionUser();
        $canEdit = $sessionUser->exists()
            && MudMambersProfile::usernameOf($sessionUser) === $username
            && $sessionUser->authorize('site.login');

        if (!MudMambersProfile::isPublic($user) && !$canEdit) {
            $this->respond(['ok' => false, 'error' => 'not_found'], 404);

            return;
        }

        require_once __DIR__ . '/MudMambersGraph.php';
        if (!MudMambersGraph::canViewProfile($this->grav, $username, $sessionUser->exists() ? $sessionUser : null)) {
            $this->respond(['ok' => false, 'error' => 'not_found'], 404);

            return;
        }

        $payload = [
            'ok' => true,
            'profile' => MudMambersProfile::publicPayload($this->grav, $user, $canEdit),
        ];
        if (MudMambersGraph::isEnabled($this->grav)) {
            $payload['graph'] = MudMambersGraph::statsForViewer(
                $this->grav,
                $username,
                $sessionUser->exists() ? $sessionUser : null
            );
        }

        $this->respond($payload);
    }

    protected function patchOwnProfile(): void
    {
        $user = $this->sessionUser();
        if (!$user->exists() || !MudMambersProfile::usernameOf($user)) {
            $this->respond(['ok' => false, 'error' => 'login_required'], 401);

            return;
        }

        $body = $this->readJsonBody();
        require_once __DIR__ . '/MudMambersCsrf.php';
        try {
            MudMambersCsrf::assertValid(
                $this->grav,
                (string) ($body['nonce'] ?? $this->requestHeader('X-Members-Nonce'))
            );
        } catch (\Throwable $e) {
            $this->respond(['ok' => false, 'error' => $e->getMessage()], 403);

            return;
        }

        try {
            $profile = MudMambersProfile::updateOwn($this->grav, $user, $body);
        } catch (\Throwable $e) {
            $this->respond(['ok' => false, 'error' => $e->getMessage()], 400);

            return;
        }

        $this->respond(['ok' => true, 'profile' => $profile]);
    }

    protected function siteFeed(): void
    {
        require_once __DIR__ . '/MudMambersActivity.php';
        if (!MudMambersActivity::isEnabled($this->grav)) {
            $this->respond(['ok' => false, 'error' => 'activity_disabled'], 404);

            return;
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = max(1, min(50, (int) ($_GET['limit'] ?? 20)));
        $viewer = $this->sessionUser();
        $scope = trim((string) ($_GET['scope'] ?? 'all'));
        $feed = MudMambersActivity::siteFeed($this->grav, $viewer->exists() ? $viewer : null, $page, $limit, $scope);
        $this->respond(['ok' => true, 'scope' => $scope] + $feed);
    }

    protected function userActivity(string $username): void
    {
        require_once __DIR__ . '/MudMambersActivity.php';
        if (!MudMambersActivity::isEnabled($this->grav)) {
            $this->respond(['ok' => false, 'error' => 'activity_disabled'], 404);

            return;
        }

        $user = MudMambersAccounts::load($this->grav, $username);
        if ($user === null || !MudMambersAccounts::isActiveMember($user)) {
            $this->respond(['ok' => false, 'error' => 'not_found'], 404);

            return;
        }

        $viewer = $this->sessionUser();
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = max(1, min(50, (int) ($_GET['limit'] ?? 20)));
        $listing = MudMambersActivity::listForUser(
            $this->grav,
            $username,
            $viewer->exists() ? $viewer : null,
            $page,
            $limit
        );
        $this->respond(['ok' => true, 'username' => $username] + $listing);
    }

    protected function createActivity(): void
    {
        require_once __DIR__ . '/MudMambersActivity.php';
        require_once __DIR__ . '/MudMambersCsrf.php';

        $user = $this->sessionUser();
        if (!$user->exists() || !MudMambersProfile::usernameOf($user)) {
            $this->respond(['ok' => false, 'error' => 'login_required'], 401);

            return;
        }

        $body = $this->requestFormBody();

        try {
            MudMambersCsrf::assertValid(
                $this->grav,
                (string) ($body['nonce'] ?? $_POST['nonce'] ?? $this->requestHeader('X-Members-Nonce'))
            );
        } catch (\Throwable $e) {
            $this->respond(['ok' => false, 'error' => $e->getMessage()], 403);

            return;
        }

        try {
            $post = MudMambersActivity::create($this->grav, $user, $body, $this->requestUploadFiles());
        } catch (\Throwable $e) {
            $this->respond(['ok' => false, 'error' => $e->getMessage()], 400);

            return;
        }

        $this->respond(['ok' => true, 'post' => $post], 201);
    }

    protected function patchActivity(string $id): void
    {
        require_once __DIR__ . '/MudMambersActivity.php';
        require_once __DIR__ . '/MudMambersCsrf.php';

        $user = $this->sessionUser();
        if (!$user->exists() || !MudMambersProfile::usernameOf($user)) {
            $this->respond(['ok' => false, 'error' => 'login_required'], 401);

            return;
        }

        $body = $this->readJsonBody();
        try {
            MudMambersCsrf::assertValid(
                $this->grav,
                (string) ($body['nonce'] ?? $this->requestHeader('X-Members-Nonce'))
            );
            $post = MudMambersActivity::update($this->grav, $user, $id, $body);
        } catch (\Throwable $e) {
            $this->respond(['ok' => false, 'error' => $e->getMessage()], 400);

            return;
        }

        $this->respond(['ok' => true, 'post' => $post]);
    }

    protected function deleteActivity(string $id): void
    {
        require_once __DIR__ . '/MudMambersActivity.php';
        require_once __DIR__ . '/MudMambersCsrf.php';

        $user = $this->sessionUser();
        if (!$user->exists() || !MudMambersProfile::usernameOf($user)) {
            $this->respond(['ok' => false, 'error' => 'login_required'], 401);

            return;
        }

        $body = $this->readJsonBody();
        try {
            MudMambersCsrf::assertValid(
                $this->grav,
                (string) ($body['nonce'] ?? $this->requestHeader('X-Members-Nonce'))
            );
            MudMambersActivity::delete($this->grav, $user, $id);
        } catch (\Throwable $e) {
            $this->respond(['ok' => false, 'error' => $e->getMessage()], 400);

            return;
        }

        $this->respond(['ok' => true]);
    }

    protected function graphStats(string $username): void
    {
        require_once __DIR__ . '/MudMambersGraph.php';
        if (!MudMambersGraph::isEnabled($this->grav)) {
            $this->respond(['ok' => false, 'error' => 'graph_disabled'], 404);

            return;
        }

        $user = MudMambersAccounts::load($this->grav, $username);
        if ($user === null || !MudMambersAccounts::isActiveMember($user)) {
            $this->respond(['ok' => false, 'error' => 'not_found'], 404);

            return;
        }

        $viewer = $this->sessionUser();
        $this->respond([
            'ok' => true,
            'username' => $username,
            'graph' => MudMambersGraph::statsForViewer($this->grav, $username, $viewer->exists() ? $viewer : null),
        ]);
    }

    protected function graphList(string $username, string $type): void
    {
        require_once __DIR__ . '/MudMambersGraph.php';
        if (!MudMambersGraph::isEnabled($this->grav)) {
            $this->respond(['ok' => false, 'error' => 'graph_disabled'], 404);

            return;
        }

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = max(1, min(50, (int) ($_GET['limit'] ?? 24)));
        $listing = MudMambersGraph::listEdges($this->grav, $username, $type, $page, $limit);
        $this->respond(['ok' => true, 'username' => $username, 'type' => $type] + $listing);
    }

    protected function graphFollow(string $target): void
    {
        require_once __DIR__ . '/MudMambersGraph.php';
        require_once __DIR__ . '/MudMambersCsrf.php';
        $user = $this->sessionUser();
        if (!$user->exists()) {
            $this->respond(['ok' => false, 'error' => 'login_required'], 401);

            return;
        }

        try {
            MudMambersCsrf::assertValid($this->grav, $this->readJsonBody()['nonce'] ?? $this->requestHeader('X-Members-Nonce'));
            MudMambersGraph::follow($this->grav, $user, $target);
        } catch (\Throwable $e) {
            $this->respond(['ok' => false, 'error' => $e->getMessage()], 400);

            return;
        }

        $this->respond(['ok' => true, 'graph' => MudMambersGraph::statsForViewer($this->grav, $target, $user)]);
    }

    protected function graphUnfollow(string $target): void
    {
        require_once __DIR__ . '/MudMambersGraph.php';
        $user = $this->sessionUser();
        if (!$user->exists()) {
            $this->respond(['ok' => false, 'error' => 'login_required'], 401);

            return;
        }

        try {
            MudMambersGraph::unfollow($this->grav, $user, $target);
        } catch (\Throwable $e) {
            $this->respond(['ok' => false, 'error' => $e->getMessage()], 400);

            return;
        }

        $this->respond(['ok' => true, 'graph' => MudMambersGraph::statsForViewer($this->grav, $target, $user)]);
    }

    protected function graphBlock(string $target): void
    {
        require_once __DIR__ . '/MudMambersGraph.php';
        require_once __DIR__ . '/MudMambersCsrf.php';
        $user = $this->sessionUser();
        if (!$user->exists()) {
            $this->respond(['ok' => false, 'error' => 'login_required'], 401);

            return;
        }

        try {
            MudMambersCsrf::assertValid($this->grav, $this->readJsonBody()['nonce'] ?? $this->requestHeader('X-Members-Nonce'));
            MudMambersGraph::block($this->grav, $user, $target);
        } catch (\Throwable $e) {
            $this->respond(['ok' => false, 'error' => $e->getMessage()], 400);

            return;
        }

        $this->respond(['ok' => true]);
    }

    protected function graphUnblock(string $target): void
    {
        require_once __DIR__ . '/MudMambersGraph.php';
        $user = $this->sessionUser();
        if (!$user->exists()) {
            $this->respond(['ok' => false, 'error' => 'login_required'], 401);

            return;
        }

        try {
            MudMambersGraph::unblock($this->grav, $user, $target);
        } catch (\Throwable $e) {
            $this->respond(['ok' => false, 'error' => $e->getMessage()], 400);

            return;
        }

        $this->respond(['ok' => true]);
    }

    protected function giphySearch(): void
    {
        $user = $this->sessionUser();
        if (!$user->exists() || !MudMambersProfile::usernameOf($user)) {
            $this->respond(['ok' => false, 'error' => 'login_required'], 401);

            return;
        }

        $key = MudMambersConfig::giphyApiKey($this->grav);
        if ($key === '') {
            $this->respond(['ok' => false, 'error' => 'giphy_disabled'], 404);

            return;
        }

        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q === '') {
            $this->respond(['ok' => false, 'error' => 'q_required'], 400);

            return;
        }

        $url = 'https://api.giphy.com/v1/gifs/search?' . http_build_query([
            'api_key' => $key,
            'q' => $q,
            'limit' => max(1, min(24, (int) ($_GET['limit'] ?? 12))),
            'rating' => 'pg-13',
        ]);

        $ctx = stream_context_create(['http' => ['timeout' => 8, 'header' => "Accept: application/json\r\n"]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            $this->respond(['ok' => false, 'error' => 'giphy_failed'], 502);

            return;
        }

        $decoded = json_decode($raw, true);
        $items = [];
        foreach ((array) ($decoded['data'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $images = $row['images'] ?? [];
            $downsized = is_array($images) ? ($images['downsized_medium'] ?? $images['fixed_width'] ?? []) : [];
            $gifUrl = is_array($downsized) ? trim((string) ($downsized['url'] ?? '')) : '';
            if ($gifUrl === '') {
                continue;
            }
            $items[] = [
                'id' => (string) ($row['id'] ?? ''),
                'title' => (string) ($row['title'] ?? 'gif'),
                'url' => $gifUrl,
                'preview' => trim((string) (is_array($images['fixed_width_small'] ?? null) ? ($images['fixed_width_small']['url'] ?? '') : '')),
            ];
        }

        $this->respond(['ok' => true, 'items' => $items]);
    }

    protected function reactActivity(string $id): void
    {
        require_once __DIR__ . '/MudMambersActivityReactions.php';
        require_once __DIR__ . '/MudMambersCsrf.php';

        $user = $this->sessionUser();
        if (!$user->exists() || !MudMambersProfile::usernameOf($user)) {
            $this->respond(['ok' => false, 'error' => 'login_required'], 401);

            return;
        }

        try {
            $body = $this->readJsonBody();
            MudMambersCsrf::assertValid(
                $this->grav,
                (string) ($body['nonce'] ?? $this->requestHeader('X-Members-Nonce'))
            );
            $emoji = (string) ($body['emoji'] ?? '👍');
            $summary = MudMambersActivityReactions::toggle($this->grav, $user, $id, $emoji);
        } catch (\Throwable $e) {
            $this->respond(['ok' => false, 'error' => $e->getMessage()], 400);

            return;
        }

        $this->respond(['ok' => true, 'reactions' => $summary]);
    }

    protected function unreactActivity(string $id): void
    {
        require_once __DIR__ . '/MudMambersActivityReactions.php';
        require_once __DIR__ . '/MudMambersCsrf.php';

        $user = $this->sessionUser();
        if (!$user->exists() || !MudMambersProfile::usernameOf($user)) {
            $this->respond(['ok' => false, 'error' => 'login_required'], 401);

            return;
        }

        try {
            MudMambersCsrf::assertValid(
                $this->grav,
                (string) ($this->readJsonBody()['nonce'] ?? $this->requestHeader('X-Members-Nonce'))
            );
            $summary = MudMambersActivityReactions::remove($this->grav, $user, $id);
        } catch (\Throwable $e) {
            $this->respond(['ok' => false, 'error' => $e->getMessage()], 400);

            return;
        }

        $this->respond(['ok' => true, 'reactions' => $summary]);
    }

    protected function linkPreview(): void
    {
        require_once __DIR__ . '/MudMambersLinkPreview.php';

        $user = $this->sessionUser();
        if (!$user->exists() || !MudMambersProfile::usernameOf($user)) {
            $this->respond(['ok' => false, 'error' => 'login_required'], 401);

            return;
        }

        $body = $this->readJsonBody();
        $url = trim((string) ($body['url'] ?? ''));
        if ($url === '') {
            $this->respond(['ok' => false, 'error' => 'url_required'], 400);

            return;
        }

        $preview = MudMambersLinkPreview::fetch($this->grav, $url);
        if ($preview === null) {
            $this->respond(['ok' => false, 'error' => 'preview_failed', 'url' => $url], 422);

            return;
        }

        $this->respond(['ok' => true, 'link' => $preview]);
    }

    protected function uploadCover(): void
    {
        $user = $this->sessionUser();
        if (!$user->exists() || !MudMambersProfile::usernameOf($user)) {
            $this->respond(['ok' => false, 'error' => 'login_required'], 401);

            return;
        }

        require_once __DIR__ . '/MudMambersCsrf.php';
        try {
            MudMambersCsrf::assertValid(
                $this->grav,
                (string) ($_POST['nonce'] ?? $this->requestHeader('X-Members-Nonce'))
            );
        } catch (\Throwable $e) {
            $this->respond(['ok' => false, 'error' => $e->getMessage()], 403);

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
    /** @return array<string, mixed> */
    protected function requestFormBody(): array
    {
        $body = $this->readJsonBody();
        if ($body !== []) {
            return $body;
        }

        return $_POST !== [] ? $_POST : [];
    }

    /** @return array<string, mixed> */
    protected function requestUploadFiles(): array
    {
        if ($this->uploadedFiles !== []) {
            return $this->uploadedFiles;
        }

        return $_FILES;
    }

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

    protected function sessionUser(): UserInterface
    {
        if ($this->apiUser !== null) {
            return $this->apiUser;
        }

        return MudMambersSession::user($this->grav);
    }

    protected function requestHeader(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return trim((string) ($_SERVER[$key] ?? ''));
    }
}
