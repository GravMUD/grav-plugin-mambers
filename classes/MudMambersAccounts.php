<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;

final class MudMambersAccounts
{
    public static function isValidUsername(string $username): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9._-]{1,31}$/i', $username);
    }

    public static function load(Grav $grav, string $username): ?UserInterface
    {
        if (!self::isValidUsername($username)) {
            return null;
        }

        $user = $grav['accounts']->load($username);
        if (!$user instanceof UserInterface || !$user->exists()) {
            return null;
        }

        return $user;
    }

    /** @return list<UserInterface> */
    public static function memberAccounts(Grav $grav): array
    {
        $accountsDir = self::accountsDirectory($grav);
        if ($accountsDir === null) {
            return [];
        }

        $users = [];
        foreach (scandir($accountsDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.yaml')) {
                continue;
            }

            $username = basename($entry, '.yaml');
            if ($username === '' || $username[0] === '.') {
                continue;
            }

            $user = self::load($grav, $username);
            if ($user === null || !self::isActiveMember($user)) {
                continue;
            }

            $users[] = $user;
        }

        usort($users, static function (UserInterface $a, UserInterface $b): int {
            return strcasecmp(MudMambersProfile::displayName($a), MudMambersProfile::displayName($b));
        });

        return $users;
    }

    public static function isActiveMember(UserInterface $user): bool
    {
        if ($user->get('state') === 'disabled') {
            return false;
        }

        return MudMambersPermissions::hasPermission($user, 'site.member');
    }

    private static function accountsDirectory(Grav $grav): ?string
    {
        $locator = $grav['locator'];
        foreach ([
            $locator->findResource('account://'),
            $locator->findResource('account://', false, true),
            defined('GRAV_ROOT') ? GRAV_ROOT . '/user/accounts' : null,
        ] as $dir) {
            if (is_string($dir) && $dir !== '' && is_dir($dir)) {
                return $dir;
            }
        }

        return null;
    }
}
