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
        $locator = $grav['locator'];
        $accountsDir = $locator->findResource('account://', true);
        if (!$accountsDir || !is_dir($accountsDir)) {
            return [];
        }

        $users = [];
        foreach (glob($accountsDir . '/*.yaml') ?: [] as $file) {
            $username = basename((string) $file, '.yaml');
            if ($username === '' || $username === '.') {
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

        return $user->authorize('site.member') || $user->authorize('site.login');
    }
}
