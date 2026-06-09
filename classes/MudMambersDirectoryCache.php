<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Grav;

final class MudMambersDirectoryCache
{
    private const TTL = 60;

    /** @return list<array<string, mixed>> */
    public static function cards(Grav $grav): array
    {
        $file = self::cacheFile($grav);
        if (is_file($file) && (time() - (int) filemtime($file)) < self::TTL) {
            $raw = file_get_contents($file);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $items = [];
        foreach (MudMambersAccounts::memberAccounts($grav) as $user) {
            if (!MudMambersProfile::isPublic($user)) {
                continue;
            }
            $items[] = self::cardPayload($grav, $user);
        }

        usort($items, static function (array $a, array $b): int {
            return strcasecmp((string) ($a['display_name'] ?? ''), (string) ($b['display_name'] ?? ''));
        });

        @file_put_contents($file, json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);

        return $items;
    }

    public static function bust(Grav $grav): void
    {
        $file = self::cacheFile($grav);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /** @return array<string, mixed> */
    private static function cardPayload(Grav $grav, \Grav\Common\User\Interfaces\UserInterface $user): array
    {
        $bio = trim((string) $user->get('profile_bio'));

        return [
            'username' => MudMambersProfile::usernameOf($user),
            'display_name' => MudMambersProfile::displayName($user),
            'avatar' => MudMambersProfile::avatarUrl($grav, $user),
            'cover' => MudMambersProfile::coverUrl($grav, $user),
            'bio_excerpt' => MudMambersProfile::excerpt($bio, 120),
            'tier' => (string) ($user->get('member_tier') ?: 'basic'),
            'profile_url' => MudMambersProfile::profilePageUrl($grav, MudMambersProfile::usernameOf($user)),
            'profile_bio' => $bio,
        ];
    }

    private static function cacheFile(Grav $grav): string
    {
        $dir = $grav['locator']->findResource('user-data://mambers/cache', true, true);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return rtrim((string) $dir, '/\\') . '/directory.json';
    }
}
