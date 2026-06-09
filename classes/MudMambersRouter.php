<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Grav;

final class MudMambersRouter
{
    public function __construct(private readonly Grav $grav) {}

    public function maybeHandle(): bool
    {
        if (!(bool) MudMambersConfig::get($this->grav, 'profiles_enabled', true)) {
            return false;
        }

        $prefix = trim((string) MudMambersConfig::get($this->grav, 'profile_route_prefix', 'members'), '/');
        if ($prefix === '') {
            return false;
        }

        $path = trim((string) $this->grav['uri']->path(), '/');
        if ($path !== $prefix && !str_starts_with($path, $prefix . '/')) {
            return false;
        }

        $rest = $path === $prefix ? '' : trim(substr($path, strlen($prefix)), '/');

        if ($rest === 'cover' || str_starts_with($rest, 'cover/')) {
            $this->serveCover(substr($rest, strlen('cover/')));

            return true;
        }

        if ($rest === 'avatar' || str_starts_with($rest, 'avatar/')) {
            $this->serveAvatar(substr($rest, strlen('avatar/')));

            return true;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#^([a-z0-9][a-z0-9._-]{1,31})/save$#i', $rest, $m)) {
            $this->handleSave(strtolower($m[1]));

            return true;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#^([a-z0-9][a-z0-9._-]{1,31})/cover$#i', $rest, $m)) {
            $this->handleCoverUpload(strtolower($m[1]));

            return true;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && preg_match('#^([a-z0-9][a-z0-9._-]{1,31})/avatar$#i', $rest, $m)) {
            $this->handleAvatarUpload(strtolower($m[1]));

            return true;
        }

        if ($rest === '' || $rest === 'index') {
            $this->renderDirectory();

            return true;
        }

        if (strtolower($rest) === 'me') {
            $this->redirectMe();

            return true;
        }

        if (MudMambersAccounts::isValidUsername($rest)) {
            $this->renderProfile(strtolower($rest));

            return true;
        }

        $this->renderNotFound();

        return true;
    }

    private function redirectMe(): void
    {
        $user = MudMambersSession::user($this->grav);
        if (!$user->exists() || !MudMambersProfile::usernameOf($user)) {
            $login = (string) MudMambersConfig::get($this->grav, 'redirect_anonymous_to', '/login');
            $this->grav->redirect($login, 302);
        }

        $this->grav->redirect(
            MudMambersProfile::profilePageUrl($this->grav, MudMambersProfile::usernameOf($user)),
            302
        );
    }

    private function renderDirectory(): void
    {
        $search = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $listing = MudMambersDirectory::listing($this->grav, $search, $page);
        $prefix = trim((string) MudMambersConfig::get($this->grav, 'profile_route_prefix', 'members'), '/');

        $this->renderTwig('directory.html.twig', [
            'page_title' => 'Members',
            'meta_description' => 'Member directory — one identity across the village.',
            'listing' => $listing,
            'search' => $search,
            'directory_url' => rtrim((string) $this->grav['base_url'], '/') . '/' . $prefix,
            'og_image' => null,
        ]);
    }

    private function renderProfile(string $username): void
    {
        $user = MudMambersAccounts::load($this->grav, $username);
        if ($user === null || !MudMambersAccounts::isActiveMember($user)) {
            $this->renderNotFound();

            return;
        }

        $sessionUser = MudMambersSession::user($this->grav);
        $canEdit = $sessionUser->exists()
            && MudMambersProfile::usernameOf($sessionUser) === $username
            && $sessionUser->authorize('site.login');

        if (!MudMambersProfile::isPublic($user) && !$canEdit) {
            $this->renderNotFound();

            return;
        }

        $profile = MudMambersProfile::publicPayload($this->grav, $user, $canEdit);
        $cover = $profile['cover'] ?? null;
        $bioExcerpt = (string) ($profile['bio_excerpt'] ?? '');

        $vars = [
            'page_title' => $profile['display_name'] . ' · Members',
            'meta_description' => $bioExcerpt !== '' ? $bioExcerpt : ('Member profile for ' . $profile['display_name']),
            'profile' => $profile,
            'og_image' => is_string($cover) ? $cover : MudMambersProfile::avatarUrl($this->grav, $user),
            'og_title' => $profile['display_name'],
            'saved' => !empty($_GET['saved']),
            'cover_saved' => !empty($_GET['cover']),
            'avatar_saved' => !empty($_GET['avatar']),
            'directory_url' => rtrim((string) $this->grav['base_url'], '/') . '/' . trim((string) MudMambersConfig::get($this->grav, 'profile_route_prefix', 'members'), '/'),
        ];
        if ($canEdit) {
            require_once __DIR__ . '/MudMambersCsrf.php';
            $vars['profile_write_nonce'] = MudMambersCsrf::token($this->grav);
        }

        $this->renderTwig('profile.html.twig', $vars);
    }

    private function handleSave(string $username): void
    {
        $sessionUser = MudMambersSession::user($this->grav);
        if (!$sessionUser->exists() || MudMambersProfile::usernameOf($sessionUser) !== $username) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }

        require_once __DIR__ . '/MudMambersCsrf.php';
        try {
            MudMambersCsrf::assertValid($this->grav, (string) ($_POST['nonce'] ?? ''));
        } catch (\Throwable $e) {
            http_response_code(403);
            echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            exit;
        }

        try {
            MudMambersProfile::updateOwn($this->grav, $sessionUser, [
                'profile_bio' => (string) ($_POST['profile_bio'] ?? ''),
                'profile_public' => !empty($_POST['profile_public']),
                'profile_links' => $this->linksFromPost(),
            ]);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            exit;
        }

        header('Location: ' . MudMambersProfile::profilePageUrl($this->grav, $username) . '?saved=1', true, 302);
        exit;
    }

    private function handleCoverUpload(string $username): void
    {
        $sessionUser = MudMambersSession::user($this->grav);
        if (!$sessionUser->exists() || MudMambersProfile::usernameOf($sessionUser) !== $username) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }

        require_once __DIR__ . '/MudMambersCsrf.php';
        try {
            MudMambersCsrf::assertValid($this->grav, (string) ($_POST['nonce'] ?? ''));
        } catch (\Throwable $e) {
            http_response_code(403);
            echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            exit;
        }

        try {
            MudMambersProfile::storeCoverUpload($this->grav, $sessionUser, $_FILES['profile_cover'] ?? []);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            exit;
        }

        header('Location: ' . MudMambersProfile::profilePageUrl($this->grav, $username) . '?cover=1', true, 302);
        exit;
    }

    private function handleAvatarUpload(string $username): void
    {
        $sessionUser = MudMambersSession::user($this->grav);
        if (!$sessionUser->exists() || MudMambersProfile::usernameOf($sessionUser) !== $username) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }

        require_once __DIR__ . '/MudMambersCsrf.php';
        try {
            MudMambersCsrf::assertValid($this->grav, (string) ($_POST['nonce'] ?? ''));
        } catch (\Throwable $e) {
            http_response_code(403);
            echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            exit;
        }

        try {
            MudMambersProfile::storeAvatarUpload($this->grav, $sessionUser, $_FILES['profile_avatar'] ?? []);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            exit;
        }

        header('Location: ' . MudMambersProfile::profilePageUrl($this->grav, $username) . '?avatar=1', true, 302);
        exit;
    }

    private function serveCover(string $username): void
    {
        $username = strtolower(trim($username, '/'));
        if (!MudMambersAccounts::isValidUsername($username)) {
            http_response_code(404);
            exit;
        }

        $file = MudMambersProfile::coverFilePath($this->grav, $username);
        if ($file === null) {
            http_response_code(404);
            exit;
        }

        $this->sendImageFile($file);
    }

    private function serveAvatar(string $username): void
    {
        $username = strtolower(trim($username, '/'));
        if (!MudMambersAccounts::isValidUsername($username)) {
            http_response_code(404);
            exit;
        }

        $file = MudMambersProfile::avatarFilePath($this->grav, $username);
        if ($file === null) {
            http_response_code(404);
            exit;
        }

        $this->sendImageFile($file);
    }

    private function sendImageFile(string $file): void
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mimes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
        ];

        if (!isset($mimes[$ext])) {
            http_response_code(404);
            exit;
        }

        header('Content-Type: ' . $mimes[$ext]);
        header('Cache-Control: public, max-age=86400');
        readfile($file);
        exit;
    }

    /** @return list<array{title: string, url: string}> */
    private function linksFromPost(): array
    {
        $titles = (array) ($_POST['link_title'] ?? []);
        $urls = (array) ($_POST['link_url'] ?? []);
        $rows = [];
        $count = max(count($titles), count($urls));
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'title' => (string) ($titles[$i] ?? ''),
                'url' => (string) ($urls[$i] ?? ''),
            ];
        }

        return MudMambersProfile::normalizeLinks($this->grav, $rows);
    }

    /** @param array<string, mixed> $vars */
    private function renderTwig(string $template, array $vars): void
    {
        $siteTitle = (string) $this->grav['config']->get('site.title', 'Grav');
        $pageTitle = (string) ($vars['page_title'] ?? 'Members');
        $metaDescription = (string) ($vars['meta_description'] ?? 'Member profiles on Grav');

        $vars['site_title'] = $siteTitle;
        $vars['base_url'] = rtrim((string) $this->grav['base_url'], '/');
        $vars['css_url'] = $vars['base_url'] . '/user/plugins/mambers/assets/mambers-profiles.css';
        $vars['linkz_cta_url'] = (string) MudMambersConfig::get($this->grav, 'linkz_cta_url', '');
        $vars['header'] = [
            'title' => $pageTitle,
            'metadata' => ['description' => $metaDescription],
        ];
        $vars['mambers_theme_layout'] = MudMambersTheme::resolveLayout($this->grav);

        MudMambersTheme::hydrateContext($this->grav, $pageTitle, $metaDescription);

        $twig = $this->grav['twig'];
        $twig->twig_vars['page_title'] = $pageTitle;
        $twig->twig_vars['meta_description'] = $metaDescription;

        $html = (string) $twig->processTemplate('mambers/' . $template, $vars);
        $html = MudMambersTheme::finalizeHtml($this->grav, $html);
        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }

    private function renderNotFound(): void
    {
        http_response_code(404);
        $this->renderTwig('not-found.html.twig', [
            'page_title' => 'Member not found',
            'meta_description' => 'That member profile is not available.',
            'og_image' => null,
            'directory_url' => rtrim((string) $this->grav['base_url'], '/') . '/' . trim((string) MudMambersConfig::get($this->grav, 'profile_route_prefix', 'members'), '/'),
        ]);
    }
}
