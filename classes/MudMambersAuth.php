<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;

final class MudMambersAuth
{
    public static function ensureVirtualAuthPages(Grav $grav): void
    {
        if (!$grav->offsetExists('login')) {
            return;
        }

        $path = trim((string) $grav['uri']->path(), '/');
        if ($path === '') {
            return;
        }

        /** @var \Grav\Plugin\Login\Login $login */
        $login = $grav['login'];

        $map = [
            trim((string) ($login->getRoute('login') ?? '/login'), '/') => 'login',
            trim((string) ($login->getRoute('register', true) ?? '/user_register'), '/') => 'register',
        ];

        if (!isset($map[$path])) {
            return;
        }

        $type = $map[$path];
        $page = $login->addPage($type);
        if (!$page instanceof PageInterface) {
            return;
        }

        unset($grav['page']);
        $grav['page'] = $page;
    }
}
