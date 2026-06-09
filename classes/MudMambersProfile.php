<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;

final class MudMambersProfile
{
    public static function maxLinks(Grav $grav): int
    {
        return MudMambersConfig::isPro($grav) ? 50 : max(1, (int) MudMambersConfig::get($grav, 'lite_link_limit', 5));
    }

    public static function usernameOf(UserInterface $user): string
    {
        if (method_exists($user, 'username')) {
            return (string) $user->username();
        }

        return (string) ($user->username ?? $user->get('username') ?? '');
    }

    public static function isPublic(UserInterface $user): bool
    {
        $flag = $user->get('profile_public');
        if ($flag === null || $flag === '') {
            return true;
        }

        return (bool) $flag;
    }

    public static function displayName(UserInterface $user): string
    {
        $full = trim((string) $user->get('fullname'));
        if ($full !== '') {
            return $full;
        }

        return self::usernameOf($user);
    }

    public static function avatarUrl(Grav $grav, UserInterface $user): string
    {
        $stored = trim((string) $user->get('profile_avatar'));
        if ($stored !== '') {
            if (str_starts_with($stored, 'http://') || str_starts_with($stored, 'https://')) {
                return $stored;
            }

            $prefix = trim((string) MudMambersConfig::get($grav, 'profile_route_prefix', 'members'), '/');
            $username = self::usernameOf($user);

            return rtrim((string) $grav['base_url'], '/') . '/' . $prefix . '/avatar/' . rawurlencode($username);
        }

        $avatar = $user->get('avatar');
        if (is_string($avatar) && trim($avatar) !== '') {
            return self::absoluteUrl($grav, trim($avatar));
        }

        $name = rawurlencode(self::displayName($user));

        return 'https://www.gravatar.com/avatar/?d=mp&s=160&f=y&name=' . $name;
    }

    public static function coverUrl(Grav $grav, UserInterface $user): ?string
    {
        $cover = trim((string) $user->get('profile_cover'));
        if ($cover === '') {
            return null;
        }

        if (str_starts_with($cover, 'http://') || str_starts_with($cover, 'https://')) {
            return $cover;
        }

        $prefix = trim((string) MudMambersConfig::get($grav, 'profile_route_prefix', 'members'), '/');
        $username = MudMambersProfile::usernameOf($user);

        return rtrim((string) $grav['base_url'], '/') . '/' . $prefix . '/cover/' . rawurlencode($username);
    }

    /** @return list<array{title: string, url: string}> */
    public static function links(UserInterface $user): array
    {
        $raw = $user->get('profile_links');
        if (!is_array($raw)) {
            return [];
        }

        $links = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            $url = trim((string) ($row['url'] ?? ''));
            if ($title === '' || $url === '' || !self::isSafeHttpUrl($url)) {
                continue;
            }
            $links[] = ['title' => $title, 'url' => $url];
        }

        return $links;
    }

    /** @return array<string, mixed> */
    public static function publicPayload(Grav $grav, UserInterface $user, bool $canEdit = false): array
    {
        $bio = trim((string) $user->get('profile_bio'));

        return [
            'username' => MudMambersProfile::usernameOf($user),
            'display_name' => self::displayName($user),
            'avatar' => self::avatarUrl($grav, $user),
            'cover' => self::coverUrl($grav, $user),
            'bio' => $bio,
            'bio_excerpt' => self::excerpt($bio, 160),
            'links' => self::links($user),
            'tier' => (string) ($user->get('member_tier') ?: 'basic'),
            'member_since' => $user->get('member_since'),
            'profile_public' => self::isPublic($user),
            'profile_url' => self::profilePageUrl($grav, MudMambersProfile::usernameOf($user)),
            'can_edit' => $canEdit,
            'max_links' => self::maxLinks($grav),
        ];
    }

    public static function profilePageUrl(Grav $grav, string $username): string
    {
        $prefix = trim((string) MudMambersConfig::get($grav, 'profile_route_prefix', 'members'), '/');

        return rtrim((string) $grav['base_url'], '/') . '/' . $prefix . '/' . rawurlencode($username);
    }

    public static function profileMeUrl(Grav $grav): string
    {
        return rtrim((string) $grav['base_url'], '/') . self::profileMeRoute($grav);
    }

    public static function profileMeRoute(Grav $grav): string
    {
        $prefix = trim((string) MudMambersConfig::get($grav, 'profile_route_prefix', 'members'), '/');

        return '/' . $prefix . '/me';
    }

    public static function coversDir(Grav $grav): string
    {
        return self::mediaDir($grav, 'covers');
    }

    public static function avatarsDir(Grav $grav): string
    {
        return self::mediaDir($grav, 'avatars');
    }

    public static function avatarFilePath(Grav $grav, string $username): ?string
    {
        return self::confinedMediaFile($grav, $username, 'avatars');
    }

    public static function coverFilePath(Grav $grav, string $username): ?string
    {
        return self::confinedMediaFile($grav, $username, 'covers');
    }

    /** @param array<string, mixed> $patch
     *  @return array<string, mixed>
     */
    public static function updateOwn(Grav $grav, UserInterface $user, array $patch): array
    {
        if (!$user->authorize('site.login')) {
            throw new \RuntimeException('Login required.');
        }

        if (array_key_exists('profile_bio', $patch)) {
            $user->set('profile_bio', substr(trim((string) $patch['profile_bio']), 0, 500));
        }

        if (array_key_exists('profile_public', $patch)) {
            $user->set('profile_public', !empty($patch['profile_public']));
        }

        if (array_key_exists('profile_links', $patch)) {
            $user->set('profile_links', self::normalizeLinks($grav, $patch['profile_links']));
        }

        $user->save();
        require_once __DIR__ . '/MudMambersDirectoryCache.php';
        MudMambersDirectoryCache::bust($grav);

        return self::publicPayload($grav, $user, true);
    }

    public static function storeCoverUpload(Grav $grav, UserInterface $user, array $file): string
    {
        $relative = self::storeImageUpload($user, $file, self::coversDir($grav));
        $user->set('profile_cover', $relative);
        $user->save();
        require_once __DIR__ . '/MudMambersDirectoryCache.php';
        MudMambersDirectoryCache::bust($grav);

        return $relative;
    }

    public static function storeAvatarUpload(Grav $grav, UserInterface $user, array $file): string
    {
        $relative = self::storeImageUpload($user, $file, self::avatarsDir($grav));
        $user->set('profile_avatar', $relative);
        $user->save();
        require_once __DIR__ . '/MudMambersDirectoryCache.php';
        MudMambersDirectoryCache::bust($grav);

        return $relative;
    }

    /** @param mixed $raw
     *  @return list<array{title: string, url: string}>
     */
    public static function normalizeLinks(Grav $grav, $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $links = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $title = substr(trim((string) ($row['title'] ?? '')), 0, 48);
            $url = trim((string) ($row['url'] ?? ''));
            if ($title === '' || !self::isSafeHttpUrl($url)) {
                continue;
            }
            $links[] = ['title' => $title, 'url' => $url];
            if (count($links) >= self::maxLinks($grav)) {
                break;
            }
        }

        return $links;
    }

    public static function excerpt(string $text, int $max = 160): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '') {
            return '';
        }
        if (strlen($text) <= $max) {
            return $text;
        }

        return rtrim(substr($text, 0, $max - 1)) . '…';
    }

    private static function absoluteUrl(Grav $grav, string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim((string) $grav['base_url'], '/') . '/' . ltrim($path, '/');
    }

    private static function isSafeHttpUrl(string $url): bool
    {
        return (bool) filter_var($url, FILTER_VALIDATE_URL)
            && (str_starts_with($url, 'http://') || str_starts_with($url, 'https://'));
    }

    private static function mediaDir(Grav $grav, string $subdir): string
    {
        $dir = $grav['locator']->findResource('user://data/mambers/' . $subdir, true, true);
        if (!is_dir(dirname((string) $dir))) {
            $parent = $grav['locator']->findResource('user://data/mambers', true, true);
            if ($parent && !is_dir($parent)) {
                mkdir($parent, 0775, true);
            }
        }
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return (string) $dir;
    }

    private static function storeImageUpload(UserInterface $user, array $file, string $dir): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload failed.');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('Invalid upload.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        $extMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        if (!isset($extMap[$mime])) {
            throw new \RuntimeException('Image must be JPEG, PNG, WebP, or GIF.');
        }

        $username = self::usernameOf($user);
        $filename = $username . '.' . $extMap[$mime];
        $dest = $dir . '/' . $filename;

        foreach (glob($dir . '/' . $username . '.*') ?: [] as $old) {
            if (is_file($old) && $old !== $dest) {
                @unlink($old);
            }
        }

        if (!move_uploaded_file($tmp, $dest)) {
            throw new \RuntimeException('Could not save image.');
        }

        $folder = basename($dir);

        return 'user/data/mambers/' . $folder . '/' . $filename;
    }

    private static function confinedMediaFile(Grav $grav, string $username, string $subdir): ?string
    {
        $user = MudMambersAccounts::load($grav, $username);
        if ($user === null) {
            return null;
        }

        $field = $subdir === 'covers' ? 'profile_cover' : 'profile_avatar';
        $stored = trim((string) $user->get($field));
        if ($stored === '' || str_starts_with($stored, 'http://') || str_starts_with($stored, 'https://')) {
            return null;
        }

        $dir = realpath(self::mediaDir($grav, $subdir));
        if ($dir === false) {
            return null;
        }

        $basename = basename($stored);
        if ($basename === '' || str_contains($basename, "\0")) {
            return null;
        }

        if (strcasecmp(pathinfo($basename, PATHINFO_FILENAME), $username) !== 0) {
            return null;
        }

        $candidate = $dir . DIRECTORY_SEPARATOR . $basename;
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved)) {
            return null;
        }

        $prefix = $dir . DIRECTORY_SEPARATOR;
        if (!str_starts_with($resolved, $prefix)) {
            return null;
        }

        return self::isAllowedImageExt($resolved) ? $resolved : null;
    }

    private static function isAllowedImageExt(string $file): bool
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    }
}
