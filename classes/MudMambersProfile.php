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

        return (string) $user->username();
    }

    public static function avatarUrl(Grav $grav, UserInterface $user): string
    {
        $avatar = trim((string) $user->get('avatar'));
        if ($avatar !== '') {
            return self::absoluteUrl($grav, $avatar);
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
        $username = (string) $user->username();

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
            'username' => (string) $user->username(),
            'display_name' => self::displayName($user),
            'avatar' => self::avatarUrl($grav, $user),
            'cover' => self::coverUrl($grav, $user),
            'bio' => $bio,
            'bio_excerpt' => self::excerpt($bio, 160),
            'links' => self::links($user),
            'tier' => (string) ($user->get('member_tier') ?: 'basic'),
            'member_since' => $user->get('member_since'),
            'profile_public' => self::isPublic($user),
            'profile_url' => self::profilePageUrl($grav, (string) $user->username()),
            'can_edit' => $canEdit,
            'max_links' => self::maxLinks($grav),
        ];
    }

    public static function profilePageUrl(Grav $grav, string $username): string
    {
        $prefix = trim((string) MudMambersConfig::get($grav, 'profile_route_prefix', 'members'), '/');

        return rtrim((string) $grav['base_url'], '/') . '/' . $prefix . '/' . rawurlencode($username);
    }

    public static function coversDir(Grav $grav): string
    {
        $dir = $grav['locator']->findResource('user://data/mambers/covers', true, true);
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

    public static function coverFilePath(Grav $grav, string $username): ?string
    {
        $user = MudMambersAccounts::load($grav, $username);
        if ($user === null) {
            return null;
        }

        $cover = trim((string) $user->get('profile_cover'));
        if ($cover === '') {
            return null;
        }

        if (str_starts_with($cover, 'http://') || str_starts_with($cover, 'https://')) {
            return null;
        }

        $path = $cover;
        if (!str_starts_with($path, '/')) {
            $path = GRAV_ROOT . '/' . ltrim($path, '/');
        } else {
            $path = GRAV_ROOT . $path;
        }

        return is_file($path) ? $path : null;
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

        return self::publicPayload($grav, $user, true);
    }

    public static function storeCoverUpload(Grav $grav, UserInterface $user, array $file): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Cover upload failed.');
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
            throw new \RuntimeException('Cover must be JPEG, PNG, WebP, or GIF.');
        }

        $username = (string) $user->username();
        $dir = self::coversDir($grav);
        $filename = $username . '.' . $extMap[$mime];
        $dest = $dir . '/' . $filename;

        foreach (glob($dir . '/' . $username . '.*') ?: [] as $old) {
            if (is_file($old) && $old !== $dest) {
                @unlink($old);
            }
        }

        if (!move_uploaded_file($tmp, $dest)) {
            throw new \RuntimeException('Could not save cover image.');
        }

        $relative = 'user/data/mambers/covers/' . $filename;
        $user->set('profile_cover', $relative);
        $user->save();

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
}
