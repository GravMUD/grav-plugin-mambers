<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Common\Utils;

final class MudMambersActivity
{
    public static function isEnabled(Grav $grav): bool
    {
        return (bool) MudMambersConfig::get($grav, 'activity_enabled', true);
    }

    public static function maxImagesPerPost(Grav $grav): int
    {
        return max(1, min(8, (int) MudMambersConfig::get($grav, 'activity_images_per_post', 4)));
    }

    public static function maxImageBytes(Grav $grav): int
    {
        $mb = max(1, (int) MudMambersConfig::get($grav, 'activity_media_max_mb', 5));

        return $mb * 1024 * 1024;
    }

    public static function maxVideoBytes(Grav $grav): int
    {
        $mb = max(1, (int) MudMambersConfig::get($grav, 'activity_video_max_mb', 10));

        return $mb * 1024 * 1024;
    }

    public static function phpUploadMaxBytes(): int
    {
        return max(0, Utils::parseSize((string) ini_get('upload_max_filesize')));
    }

    public static function phpPostMaxBytes(): int
    {
        return max(0, Utils::parseSize((string) ini_get('post_max_size')));
    }

    public static function maxVideosPerPost(Grav $grav): int
    {
        return max(0, min(2, (int) MudMambersConfig::get($grav, 'activity_videos_per_post', 1)));
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int} */
    public static function listForUser(Grav $grav, string $username, ?UserInterface $viewer, int $page = 1, int $perPage = 20): array
    {
        require_once __DIR__ . '/MudMambersGraph.php';
        $posts = [];
        foreach (MudMambersActivityStorage::readUserPosts($grav, $username) as $post) {
            if (!self::canViewPost($grav, $post, $username, $viewer)) {
                continue;
            }
            if (MudMambersGraph::isEnabled($grav) && MudMambersGraph::shouldHideAuthorInFeed($grav, $username, $viewer)) {
                continue;
            }
            $posts[] = self::enrichPost($grav, $post, $username);
        }

        return self::paginate($posts, $page, $perPage);
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int} */
    public static function siteFeed(Grav $grav, ?UserInterface $viewer, int $page = 1, int $perPage = 20, string $scope = 'all'): array
    {
        require_once __DIR__ . '/MudMambersGraph.php';
        $scope = strtolower(trim($scope));
        $following = [];
        if ($scope === 'following' && $viewer !== null && $viewer->exists()) {
            $following = MudMambersGraph::followingUsernames($grav, MudMambersProfile::usernameOf($viewer));
            $following[] = MudMambersProfile::usernameOf($viewer);
        }

        $all = [];
        foreach (MudMambersAccounts::memberAccounts($grav) as $user) {
            $username = MudMambersProfile::usernameOf($user);
            if ($username === '') {
                continue;
            }
            if ($scope === 'following' && $following !== [] && !in_array($username, $following, true)) {
                continue;
            }
            if (MudMambersGraph::isEnabled($grav) && MudMambersGraph::shouldHideAuthorInFeed($grav, $username, $viewer)) {
                continue;
            }
            foreach (MudMambersActivityStorage::readUserPosts($grav, $username) as $post) {
                if (!self::canViewPost($grav, $post, $username, $viewer)) {
                    continue;
                }
                $post = self::enrichPost($grav, $post, $username, $user);
                $all[] = $post;
            }
        }

        usort($all, static fn (array $a, array $b): int => strcmp((string) ($b['created'] ?? ''), (string) ($a['created'] ?? '')));

        return self::paginate($all, $page, $perPage);
    }

    /** @param array<string, mixed> $input @param array<string, mixed> $files */
    public static function create(Grav $grav, UserInterface $user, array $input, array $files = []): array
    {
        if (!self::isEnabled($grav)) {
            throw new \RuntimeException('Activity feed is disabled.');
        }

        $username = MudMambersProfile::usernameOf($user);
        if ($username === '' || !MudMambersAccounts::isActiveMember($user)) {
            throw new \RuntimeException('Active member account required.');
        }

        $body = trim((string) ($input['body'] ?? ''));
        $bodyHtml = trim((string) ($input['body_html'] ?? ''));
        if ($bodyHtml !== '') {
            $bodyHtml = self::sanitizeBodyHtml($bodyHtml);
            if ($body === '') {
                $body = trim(html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }
        $visibility = self::normalizeVisibility((string) ($input['visibility'] ?? 'public'));
        $linkUrl = trim((string) ($input['link_url'] ?? ''));
        if ($linkUrl === '' && $body !== '') {
            $extracted = self::extractFirstUrl($body);
            if ($extracted !== null) {
                $linkUrl = $extracted;
            }
        }

        $uploadErr = self::uploadFieldError($files);
        if ($uploadErr !== null) {
            throw new \RuntimeException($uploadErr);
        }

        $media = self::storeUploads($grav, $username, $files);
        $media = array_merge($media, self::mediaFromGifUrls($input));

        if ($body === '' && $linkUrl === '' && $media === []) {
            if (self::hasMediaUpload($files)) {
                throw new \RuntimeException(
                    'Could not save media — use JPEG, PNG, WebP, GIF, or MP4/WebM within size limits.'
                );
            }

            throw new \RuntimeException('Post needs text, a link, or media.');
        }

        if (strlen($body) > 5000) {
            throw new \RuntimeException('Post text is too long.');
        }

        $link = null;
        if ($linkUrl !== '') {
            $link = MudMambersLinkPreview::fetch($grav, $linkUrl);
            if ($link === null) {
                $link = ['url' => $linkUrl, 'title' => $linkUrl, 'description' => '', 'image' => ''];
            }
        }

        $id = MudMambersActivityStorage::generateId();
        $post = [
            'id' => $id,
            'author' => $username,
            'created' => gmdate('c'),
            'updated' => gmdate('c'),
            'body' => $body,
            'visibility' => $visibility,
            'media' => $media,
            'link' => $link,
            'meta' => ['client' => (string) ($input['client'] ?? 'web')],
        ];
        if ($bodyHtml !== '') {
            $post['body_html'] = $bodyHtml;
        }

        MudMambersActivityStorage::appendPost($grav, $username, $post);

        return self::enrichPost($grav, $post, $username, $user);
    }

    /** @param array<string, mixed> $input */
    public static function update(Grav $grav, UserInterface $user, string $id, array $input): array
    {
        require_once __DIR__ . '/MudMambersLinkPreview.php';

        $username = MudMambersProfile::usernameOf($user);
        $author = MudMambersActivityStorage::resolveAuthor($grav, $id);
        if ($author === null || $author !== $username) {
            throw new \RuntimeException('Not allowed to edit this post.');
        }

        $existing = MudMambersActivityStorage::findPost($grav, $id);
        if ($existing === null) {
            throw new \RuntimeException('Post not found.');
        }

        if (array_key_exists('body_html', $input)) {
            $bodyHtml = trim((string) $input['body_html']);
            if ($bodyHtml !== '') {
                $bodyHtml = self::sanitizeBodyHtml($bodyHtml);
                $existing['body_html'] = $bodyHtml;
                if (!array_key_exists('body', $input)) {
                    $existing['body'] = trim(html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                }
            } else {
                unset($existing['body_html']);
            }
        }

        if (array_key_exists('body', $input)) {
            $body = trim((string) $input['body']);
            if ($body === '' && empty($existing['media']) && empty($existing['link'])) {
                throw new \RuntimeException('Post cannot be empty.');
            }
            $existing['body'] = $body;
        }

        if (array_key_exists('visibility', $input)) {
            $existing['visibility'] = self::normalizeVisibility((string) $input['visibility']);
        }

        $body = trim((string) ($existing['body'] ?? ''));
        $linkUrl = self::extractFirstUrl($body);
        if ($linkUrl !== null) {
            $link = MudMambersLinkPreview::fetch($grav, $linkUrl);
            $existing['link'] = $link ?? ['url' => $linkUrl, 'title' => $linkUrl, 'description' => '', 'image' => ''];
        } elseif (array_key_exists('body', $input) || array_key_exists('body_html', $input)) {
            $existing['link'] = null;
        }

        if (strlen($body) > 5000) {
            throw new \RuntimeException('Post text is too long.');
        }

        $existing['updated'] = gmdate('c');
        MudMambersActivityStorage::replacePost($grav, $username, $id, $existing);

        return self::enrichPost($grav, $existing, $username, $user);
    }

    public static function delete(Grav $grav, UserInterface $user, string $id): void
    {
        $username = MudMambersProfile::usernameOf($user);
        $author = MudMambersActivityStorage::resolveAuthor($grav, $id);
        if ($author === null || $author !== $username) {
            throw new \RuntimeException('Not allowed to delete this post.');
        }

        if (!MudMambersActivityStorage::deletePost($grav, $username, $id)) {
            throw new \RuntimeException('Post not found.');
        }
    }

    /** @return array<string, mixed>|null */
    public static function publicPost(Grav $grav, string $username, string $id, ?UserInterface $viewer): ?array
    {
        $post = MudMambersActivityStorage::findPost($grav, $id);
        if ($post === null || MudMambersActivityStorage::resolveAuthor($grav, $id) !== strtolower($username)) {
            return null;
        }

        if (!self::canViewPost($grav, $post, $username, $viewer)) {
            return null;
        }

        $user = MudMambersAccounts::load($grav, $username);
        if ($user !== null) {
            $post['author_name'] = MudMambersProfile::displayName($user);
            $post['author_avatar'] = MudMambersProfile::avatarUrl($grav, $user);
            $post['profile_url'] = MudMambersProfile::profilePageUrl($grav, $username);
        }
        return self::enrichPost($grav, $post, $username, $user);
    }

    /** @param array<string, mixed> $post */
    public static function enrichPost(Grav $grav, array $post, string $username, ?UserInterface $user = null): array
    {
        if ($user === null) {
            $user = MudMambersAccounts::load($grav, $username);
        }
        $post['author'] = $username;
        if ($user !== null) {
            $post['author_name'] = MudMambersProfile::displayName($user);
            $post['author_avatar'] = MudMambersProfile::avatarUrl($grav, $user);
            $post['profile_url'] = MudMambersProfile::profilePageUrl($grav, $username);
        }
        $id = (string) ($post['id'] ?? '');
        $post['post_url'] = $id !== '' ? self::postPageUrl($grav, $username, $id) : '';
        $post['og_image'] = self::postOgImage($post);
        if ($id !== '') {
            require_once __DIR__ . '/MudMambersActivityReactions.php';
            require_once __DIR__ . '/MudMambersSession.php';
            if (MudMambersActivityReactions::isEnabled($grav)) {
                $session = MudMambersSession::user($grav);
                $post['reactions'] = MudMambersActivityReactions::summaryForPost(
                    $grav,
                    $id,
                    $session->exists() ? $session : null
                );
            }
        }

        return $post;
    }

    public static function extractFirstUrl(string $text): ?string
    {
        if (!preg_match('#https?://[^\s<>"\']+#i', $text, $m)) {
            return null;
        }

        return rtrim((string) $m[0], '.,);]\'"');

    }

    public static function sanitizeBodyHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $allowed = '<p><br><strong><b><em><i><u><a><ul><ol><li>';
        $clean = strip_tags($html, $allowed);
        $clean = preg_replace('/\s*on\w+\s*=\s*("|\').*?\1/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\s*style\s*=\s*("|\').*?\1/iu', '', $clean) ?? $clean;
        $clean = preg_replace_callback(
            '/<a\s+([^>]*href\s*=\s*("|\')([^"\']+)\2[^>]*)>/iu',
            static function (array $m): string {
                $href = trim((string) $m[3]);
                if (!preg_match('#^https?://#i', $href)) {
                    return '<span>';
                }

                return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '" rel="noopener noreferrer" target="_blank">';
            },
            $clean
        ) ?? $clean;

        return trim($clean);
    }

    /** @param array<string, mixed> $input @return list<array<string, string>> */
    private static function mediaFromGifUrls(array $input): array
    {
        $urls = $input['gif_urls'] ?? $input['gif_url'] ?? [];
        if (is_string($urls)) {
            $urls = $urls !== '' ? [$urls] : [];
        }
        if (!is_array($urls)) {
            return [];
        }

        $out = [];
        foreach ($urls as $url) {
            $url = trim((string) $url);
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }
            if (!preg_match('#(giphy\.com|giphy\.gif|media\d*\.giphy)#i', $url) && !preg_match('#\.gif(\?|$)#i', $url)) {
                continue;
            }
            $out[] = ['type' => 'image', 'url' => $url, 'alt' => 'gif'];
            if (count($out) >= 2) {
                break;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $post */
    public static function postOgImage(array $post): ?string
    {
        $media = $post['media'] ?? [];
        if (is_array($media)) {
            foreach ($media as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $type = (string) ($item['type'] ?? '');
                $url = trim((string) ($item['url'] ?? ''));
                if ($url === '') {
                    continue;
                }
                if ($type === 'image') {
                    return $url;
                }
            }
        }
        $link = $post['link'] ?? null;
        if (is_array($link) && !empty($link['image'])) {
            return (string) $link['image'];
        }

        return null;
    }

    public static function postPageUrl(Grav $grav, string $username, string $id): string
    {
        $prefix = trim((string) MudMambersConfig::get($grav, 'profile_route_prefix', 'members'), '/');

        return MudMambersConfig::absoluteUrl(
            $grav,
            '/' . $prefix . '/' . rawurlencode($username) . '/post/' . rawurlencode($id)
        );
    }

    public static function feedPageUrl(Grav $grav): string
    {
        $prefix = trim((string) MudMambersConfig::get($grav, 'profile_route_prefix', 'members'), '/');

        return rtrim((string) $grav['base_url'], '/') . '/' . $prefix . '/feed';
    }

    /** @param array<string, mixed> $post */
    public static function canViewPost(Grav $grav, array $post, string $authorUsername, ?UserInterface $viewer): bool
    {
        $visibility = (string) ($post['visibility'] ?? 'public');
        $viewerName = ($viewer !== null && $viewer->exists()) ? MudMambersProfile::usernameOf($viewer) : '';

        if ($visibility === 'private') {
            return $viewerName !== '' && strcasecmp($viewerName, $authorUsername) === 0;
        }

        if ($visibility === 'members') {
            return $viewer !== null
                && $viewer->exists()
                && $viewer->authorize('site.login')
                && MudMambersPermissions::hasPermission($viewer, 'site.member');
        }

        return true;
    }

    /** @param list<array<string, mixed>> $items */
    private static function paginate(array $items, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $total = count($items);
        $pages = max(1, (int) ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($items, $offset, $perPage),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    private static function normalizeVisibility(string $visibility): string
    {
        $visibility = strtolower(trim($visibility));

        return in_array($visibility, ['public', 'members', 'private'], true) ? $visibility : 'public';
    }

    /** @param array<string, mixed> $files */
    private static function uploadFieldError(array $files): ?string
    {
        if (!isset($files['media']) || !is_array($files['media'])) {
            return null;
        }

        $errors = $files['media']['error'] ?? null;
        $names = $files['media']['name'] ?? null;
        if (!is_array($errors)) {
            $name = is_string($names) ? $names : '';

            return self::describeUploadError((int) $errors, $name);
        }

        foreach ($errors as $i => $error) {
            $name = is_array($names) ? (string) ($names[$i] ?? '') : '';
            $msg = self::describeUploadError((int) $error, $name);
            if ($msg !== null) {
                return $msg;
            }
        }

        return null;
    }

    private static function describeUploadError(int $error, string $name = ''): ?string
    {
        $label = $name !== '' ? $name . ': ' : '';

        return match ($error) {
            UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $label . 'Upload too large for PHP ('
                . ini_get('upload_max_filesize')
                . ' per file, '
                . ini_get('post_max_size')
                . ' total). Try fewer or smaller files.',
            UPLOAD_ERR_PARTIAL => $label . 'Upload interrupted — please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server upload folder missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write upload.',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by server.',
            default => $label . 'Upload failed.',
        };
    }

    /** @param array<string, mixed> $files */
    private static function hasMediaUpload(array $files): bool
    {
        if (!isset($files['media']) || !is_array($files['media'])) {
            return false;
        }

        $errors = $files['media']['error'] ?? null;
        if (!is_array($errors)) {
            return (int) $errors === UPLOAD_ERR_OK;
        }

        foreach ($errors as $error) {
            if ((int) $error === UPLOAD_ERR_OK) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $files @return list<array<string, string>> */
    private static function storeUploads(Grav $grav, string $username, array $files): array
    {
        require_once __DIR__ . '/MudMambersImageCompressor.php';

        if (!isset($files['media']) || !is_array($files['media'])) {
            return [];
        }

        $upload = $files['media'];
        $names = $upload['name'] ?? null;
        if (!is_array($names)) {
            $names = [$names];
            $upload = [
                'name' => $names,
                'type' => [$upload['type'] ?? ''],
                'tmp_name' => [$upload['tmp_name'] ?? ''],
                'error' => [$upload['error'] ?? UPLOAD_ERR_NO_FILE],
                'size' => [$upload['size'] ?? 0],
            ];
        }

        $imageTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $videoTypes = ['video/mp4' => 'mp4', 'video/webm' => 'webm'];
        $stored = [];
        $imagesLeft = self::maxImagesPerPost($grav);
        $videosLeft = self::maxVideosPerPost($grav);
        $prefix = trim((string) MudMambersConfig::get($grav, 'profile_route_prefix', 'members'), '/');
        $base = rtrim((string) $grav['base_url'], '/') . '/' . $prefix . '/activity/media/' . rawurlencode($username) . '/';

        for ($i = 0, $n = count($names); $i < $n; $i++) {
            if ((int) ($upload['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $mime = (string) ($upload['type'][$i] ?? '');
            $tmp = (string) ($upload['tmp_name'][$i] ?? '');
            if ($tmp === '' || (!is_uploaded_file($tmp) && !is_file($tmp))) {
                continue;
            }
            $size = (int) ($upload['size'][$i] ?? 0);
            if ($size <= 0) {
                continue;
            }

            $type = null;
            $ext = null;
            $maxBytes = 0;
            if (isset($imageTypes[$mime]) && $imagesLeft > 0) {
                $type = 'image';
                $ext = $imageTypes[$mime];
                $maxBytes = MudMambersImageCompressor::isEnabled($grav)
                    ? MudMambersImageCompressor::maxIngestBytes($grav)
                    : self::maxImageBytes($grav);
                $imagesLeft--;
            } elseif (isset($videoTypes[$mime]) && $videosLeft > 0) {
                $type = 'video';
                $ext = $videoTypes[$mime];
                $maxBytes = self::maxVideoBytes($grav);
                $videosLeft--;
            } else {
                continue;
            }

            if ($size > $maxBytes) {
                continue;
            }

            $id = MudMambersActivityStorage::generateId();
            $dir = MudMambersActivityStorage::mediaDir($grav, $username);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $filename = $id . '.' . $ext;
            $dest = $dir . '/' . $filename;
            $moved = is_uploaded_file($tmp)
                ? move_uploaded_file($tmp, $dest)
                : rename($tmp, $dest);
            if (!$moved) {
                continue;
            }

            if ($type === 'image') {
                $newExt = MudMambersImageCompressor::optimize($grav, $dest, $mime);
                if ($newExt !== null && $newExt !== $ext) {
                    $filename = $id . '.' . $newExt;
                    $dest = $dir . '/' . $filename;
                    $ext = $newExt;
                }
                if (!is_file($dest) || filesize($dest) > self::maxImageBytes($grav)) {
                    @unlink($dest);

                    continue;
                }
            }

            $stored[] = ['type' => $type, 'url' => $base . rawurlencode($filename), 'alt' => ''];
        }

        return $stored;
    }
}
