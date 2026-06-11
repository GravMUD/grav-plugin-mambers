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

        if (str_starts_with($rest, 'activity/media/')) {
            $this->serveActivityMedia(substr($rest, strlen('activity/media/')));

            return true;
        }

        if (strtolower($rest) === 'feed') {
            $this->renderFeed();

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

        if (preg_match('#^([a-z0-9][a-z0-9._-]{1,31})/post/([a-f0-9]{12})$#i', $rest, $m)) {
            $this->renderPost(strtolower($m[1]), strtolower($m[2]));

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
            $this->grav['session']->redirect_after_login = MudMambersProfile::profileMeRoute($this->grav);
            $login = MudMambersAuth::authUrl($this->grav, MudMambersAuth::loginRoute($this->grav));
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

        require_once __DIR__ . '/MudMambersGraph.php';
        if (!MudMambersGraph::canViewProfile(
            $this->grav,
            $username,
            $sessionUser->exists() ? $sessionUser : null
        )) {
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
        require_once __DIR__ . '/MudMambersCsrf.php';
        if ($canEdit) {
            $vars['profile_write_nonce'] = MudMambersCsrf::token($this->grav);
        }
        if ($sessionUser->exists() && MudMambersProfile::usernameOf($sessionUser)) {
            $vars['graph_nonce'] = MudMambersCsrf::token($this->grav);
        }

        require_once __DIR__ . '/MudMambersActivity.php';
        $vars['activity_enabled'] = MudMambersActivity::isEnabled($this->grav);
        $vars['activity_api'] = MudMambersConfig::apiUrl($this->grav);
        $vars['feed_url'] = MudMambersActivity::feedPageUrl($this->grav);
        $vars = array_merge($vars, $this->activityFeatureVars());
        $vars['graph_enabled'] = MudMambersGraph::isEnabled($this->grav);
        if ($vars['graph_enabled']) {
            $vars['graph'] = MudMambersGraph::statsForViewer(
                $this->grav,
                $username,
                $sessionUser->exists() ? $sessionUser : null
            );
        }

        $this->renderTwig('profile.html.twig', $vars);
    }

    private function renderFeed(): void
    {
        require_once __DIR__ . '/MudMambersActivity.php';
        if (!MudMambersActivity::isEnabled($this->grav)) {
            $this->renderNotFound();

            return;
        }

        require_once __DIR__ . '/MudMambersGraph.php';
        $prefix = trim((string) MudMambersConfig::get($this->grav, 'profile_route_prefix', 'members'), '/');
        $sessionUser = $this->grav['user'];
        $vars = [
            'page_title' => 'Member feed · Members',
            'meta_description' => 'Recent activity from the village.',
            'og_image' => null,
            'directory_url' => rtrim((string) $this->grav['base_url'], '/') . '/' . $prefix,
            'activity_enabled' => true,
            'activity_api' => MudMambersConfig::apiUrl($this->grav),
            'feed_mode' => 'site',
            'graph_enabled' => MudMambersGraph::isEnabled($this->grav),
        ] + $this->activityFeatureVars();
        if ($sessionUser->exists() && MudMambersProfile::usernameOf($sessionUser)) {
            require_once __DIR__ . '/MudMambersCsrf.php';
            $vars['feed_nonce'] = MudMambersCsrf::token($this->grav);
        }
        $this->renderTwig('feed.html.twig', $vars);
    }

    private function renderPost(string $username, string $id): void
    {
        require_once __DIR__ . '/MudMambersActivity.php';
        if (!MudMambersActivity::isEnabled($this->grav)) {
            $this->renderNotFound();

            return;
        }

        $user = MudMambersAccounts::load($this->grav, $username);
        if ($user === null || !MudMambersAccounts::isActiveMember($user)) {
            $this->renderNotFound();

            return;
        }

        $sessionUser = MudMambersSession::user($this->grav);
        $post = MudMambersActivity::publicPost(
            $this->grav,
            $username,
            $id,
            $sessionUser->exists() ? $sessionUser : null
        );
        if ($post === null) {
            $this->renderNotFound();

            return;
        }

        $ogImage = null;
        if (!empty($post['media'][0]['url'])) {
            $ogImage = MudMambersConfig::absoluteUrl($this->grav, (string) $post['media'][0]['url']);
        } elseif (!empty($post['link']['image'])) {
            $ogImage = MudMambersConfig::absoluteUrl($this->grav, (string) $post['link']['image']);
        } else {
            $cover = MudMambersProfile::coverUrl($this->grav, $user);
            $ogImage = $cover !== null ? MudMambersConfig::absoluteUrl($this->grav, $cover) : null;
        }

        $prefix = trim((string) MudMambersConfig::get($this->grav, 'profile_route_prefix', 'members'), '/');
        $bodyExcerpt = trim((string) ($post['body'] ?? ''));
        if (strlen($bodyExcerpt) > 160) {
            $bodyExcerpt = substr($bodyExcerpt, 0, 157) . '…';
        }

        require_once __DIR__ . '/MudMambersCsrf.php';
        $postCanEdit = $sessionUser->exists()
            && strcasecmp(MudMambersProfile::usernameOf($sessionUser), $username) === 0;
        $postVars = [
            'page_title' => ($post['author_name'] ?? $username) . ' · Post',
            'meta_description' => $bodyExcerpt !== '' ? $bodyExcerpt : 'Member post',
            'og_image' => $ogImage,
            'og_title' => (string) ($post['author_name'] ?? $username),
            'og_url' => MudMambersActivity::postPageUrl($this->grav, $username, $id),
            'directory_url' => rtrim((string) $this->grav['base_url'], '/') . '/' . $prefix,
            'post' => $post,
            'post_can_edit' => $postCanEdit,
            'profile_url' => MudMambersProfile::profilePageUrl($this->grav, $username),
            'activity_enabled' => true,
            'activity_api' => MudMambersConfig::apiUrl($this->grav),
        ] + $this->activityFeatureVars();
        if ($sessionUser->exists() && MudMambersProfile::usernameOf($sessionUser)) {
            $postVars['graph_nonce'] = MudMambersCsrf::token($this->grav);
        }
        if ($postCanEdit) {
            $postVars['profile_write_nonce'] = MudMambersCsrf::token($this->grav);
        }
        $this->renderTwig('post.html.twig', $postVars);
    }

    /** @return array<string, mixed> */
    private function activityFeatureVars(): array
    {
        require_once __DIR__ . '/MudMambersActivity.php';
        require_once __DIR__ . '/MudMambersActivityReactions.php';
        require_once __DIR__ . '/MudMambersImageCompressor.php';

        $phpUploadBytes = MudMambersActivity::phpUploadMaxBytes();
        $phpPostBytes = MudMambersActivity::phpPostMaxBytes();

        return [
            'activity_giphy_enabled' => MudMambersConfig::giphyEnabled($this->grav),
            'activity_reactions_enabled' => MudMambersActivityReactions::isEnabled($this->grav),
            'activity_image_compress_enabled' => MudMambersImageCompressor::isEnabled($this->grav),
            'activity_max_image_mb' => (int) (MudMambersImageCompressor::maxIngestBytes($this->grav) / 1048576),
            'activity_stored_image_mb' => (int) (MudMambersActivity::maxImageBytes($this->grav) / 1048576),
            'activity_max_video_mb' => (int) (MudMambersActivity::maxVideoBytes($this->grav) / 1048576),
            'activity_max_images' => MudMambersActivity::maxImagesPerPost($this->grav),
            'activity_max_videos' => MudMambersActivity::maxVideosPerPost($this->grav),
            'activity_php_upload_mb' => max(1, (int) ceil($phpUploadBytes / 1048576)),
            'activity_php_post_mb' => max(1, (int) ceil($phpPostBytes / 1048576)),
        ];
    }

    private function serveActivityMedia(string $path): void
    {
        $parts = explode('/', trim($path, '/'), 2);
        if (count($parts) !== 2) {
            http_response_code(404);
            exit;
        }

        $username = strtolower($parts[0]);
        $filename = basename($parts[1]);
        if (!MudMambersAccounts::isValidUsername($username)
            || !preg_match('/^[a-f0-9]{12}\.(jpe?g|png|webp|gif|mp4|webm)$/i', $filename)) {
            http_response_code(404);
            exit;
        }

        require_once __DIR__ . '/MudMambersActivityStorage.php';
        $file = MudMambersActivityStorage::mediaDir($this->grav, $username) . '/' . $filename;
        if (!is_file($file)) {
            http_response_code(404);
            exit;
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($ext, ['mp4', 'webm'], true)) {
            $this->sendVideoFile($file, $ext);

            return;
        }

        $this->sendImageFile($file);
    }

    private function sendVideoFile(string $file, string $ext): void
    {
        $mimes = ['mp4' => 'video/mp4', 'webm' => 'video/webm'];
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . (string) filesize($file));
        readfile($file);
        exit;
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
                'profile_bio_html' => (string) ($_POST['profile_bio_html'] ?? ''),
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
        $prefix = trim((string) MudMambersConfig::get($this->grav, 'profile_route_prefix', 'members'), '/');
        if (!isset($vars['directory_url'])) {
            $vars['directory_url'] = $vars['base_url'] . '/' . $prefix;
        }
        require_once __DIR__ . '/MudMambersActivity.php';
        if (!isset($vars['activity_enabled'])) {
            $vars['activity_enabled'] = MudMambersActivity::isEnabled($this->grav);
        }
        if ($vars['activity_enabled'] && !isset($vars['feed_url'])) {
            $vars['feed_url'] = MudMambersActivity::feedPageUrl($this->grav);
        }
        $vars['css_url'] = $vars['base_url'] . '/user/plugins/mambers/assets/mambers-profiles.css';
        $vars['activity_css_url'] = $vars['base_url'] . '/user/plugins/mambers/assets/mambers-activity.css';
        $vars['activity_js_url'] = $vars['base_url'] . '/user/plugins/mambers/assets/mambers-activity.js';
        $vars['linkz_cta_url'] = (string) MudMambersConfig::get($this->grav, 'linkz_cta_url', '');
        $vars['header'] = [
            'title' => $pageTitle,
            'metadata' => ['description' => $metaDescription],
        ];
        $vars['mambers_theme_layout'] = MudMambersTheme::resolveLayout($this->grav);

        MudMambersTheme::hydrateContext($this->grav, $pageTitle, $metaDescription);

        MudMambersAuth::publishTwigVars($this->grav);

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
