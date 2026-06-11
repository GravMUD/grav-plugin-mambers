<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\User\Interfaces\UserInterface;

/**
 * Grav 2 RC5 user field access — DataUser uses get(), not username() methods.
 */
final class MudMambersGravUser
{
    public static function username(UserInterface $user): string
    {
        return trim((string) ($user->get('username') ?? ''));
    }

    public static function fullname(UserInterface $user): string
    {
        return trim((string) ($user->get('fullname') ?? ''));
    }

    public static function displayName(UserInterface $user): string
    {
        $full = self::fullname($user);

        return $full !== '' ? $full : self::username($user);
    }

    public static function avatar(UserInterface $user): string
    {
        $stored = $user->get('avatar');
        if (is_string($stored) && trim($stored) !== '') {
            return trim($stored);
        }

        if (method_exists($user, 'getAvatarUrl')) {
            $url = trim((string) $user->getAvatarUrl());
            if ($url !== '') {
                return $url;
            }
        }

        return '🪐';
    }
}
