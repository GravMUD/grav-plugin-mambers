<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\User\Interfaces\UserInterface;

final class MudMambersPermissions
{
    /**
     * @param object|array<string, mixed> $data Registration payload (by reference shape).
     * @param array<string, mixed> $cfg
     */
    public static function applyTierToRegisterData($data, string $tier, array $cfg): void
    {
        $permissions = self::permissionsForTier($tier, $cfg);
        $access = self::normalizeAccess(self::readProp($data, 'access'));

        foreach ($permissions as $permission) {
            self::grantPermission($access, $permission);
        }

        self::writeProp($data, 'access', $access);
        self::writeProp($data, 'member_tier', $tier);
        if (!self::readProp($data, 'member_since')) {
            self::writeProp($data, 'member_since', gmdate('Y-m-d H:i:s'));
        }
    }

    /** @param array<string, mixed> $cfg */
    /** @return list<string> */
    public static function permissionsForTier(string $tier, array $cfg): array
    {
        $map = MudMambersConfig::tierPermissions($cfg);
        $permissions = $map[$tier] ?? $map['basic'] ?? ['site.login', 'site.member'];

        return array_values(array_unique(array_filter(array_map('strval', $permissions))));
    }

    public static function isMembershipExpired(UserInterface $user): bool
    {
        $expires = (string) ($user->get('member_expires') ?? '');
        if ($expires === '') {
            return false;
        }

        $ts = strtotime($expires);

        return $ts !== false && $ts < time();
    }

    /** @return array<string, mixed> */
    public static function whoamiPayload(UserInterface $user): array
    {
        $username = (string) ($user->username ?? '');
        $loggedIn = $username !== '' && $user->exists();

        if (!$loggedIn) {
            return [
                'ok' => true,
                'authenticated' => false,
                'username' => null,
                'tier' => 'none',
                'member_since' => null,
                'member_expires' => null,
                'permissions' => [],
            ];
        }

        $permissions = [];
        foreach (['site.login', 'site.member', 'site.member.pro', 'site.member.moderator'] as $perm) {
            if ($user->authorize($perm)) {
                $permissions[] = $perm;
            }
        }

        return [
            'ok' => true,
            'authenticated' => true,
            'username' => $username,
            'tier' => (string) ($user->get('member_tier') ?: 'basic'),
            'member_since' => $user->get('member_since'),
            'member_expires' => $user->get('member_expires'),
            'permissions' => $permissions,
        ];
    }

    /** @param array<string, mixed> $access */
    private static function grantPermission(array &$access, string $permission): void
    {
        if (!isset($access['site']) || !is_array($access['site'])) {
            $access['site'] = [];
        }

        if ($permission === 'site.login') {
            $access['site']['login'] = true;

            return;
        }

        if ($permission === 'site.member') {
            $access['site']['member'] = true;

            return;
        }

        // Sibling keys under site.* flatten to site.member.pro without clobbering site.member.
        if ($permission === 'site.member.pro') {
            $access['site']['member'] = true;
            $access['site']['member.pro'] = true;

            return;
        }

        if ($permission === 'site.member.moderator') {
            $access['site']['member'] = true;
            $access['site']['member.moderator'] = true;

            return;
        }
    }

    /** @return array<string, mixed> */
    private static function normalizeAccess(mixed $access): array
    {
        return is_array($access) ? $access : [];
    }

    /** @param object|array<string, mixed> $data */
    private static function readProp($data, string $key): mixed
    {
        if (is_array($data)) {
            return $data[$key] ?? null;
        }

        return $data->$key ?? null;
    }

    /** @param object|array<string, mixed> $data */
    private static function writeProp($data, string $key, mixed $value): void
    {
        if (is_array($data)) {
            $data[$key] = $value;

            return;
        }

        $data->$key = $value;
    }
}
