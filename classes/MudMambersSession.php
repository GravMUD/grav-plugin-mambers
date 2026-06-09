<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;

final class MudMambersSession
{
    public static function user(Grav $grav): UserInterface
    {
        if ($grav->offsetExists('user')) {
            /** @var UserInterface $user */
            $user = $grav['user'];

            return $user;
        }

        if ($grav->offsetExists('session')) {
            $session = $grav['session'];
            $user = $session->user ?? null;
            if ($user instanceof UserInterface) {
                return $user;
            }
        }

        /** @var UserInterface $guest */
        $guest = $grav['accounts']->load('');

        return $guest;
    }
}
