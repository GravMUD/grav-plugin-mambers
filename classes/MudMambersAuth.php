<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Page;

final class MudMambersAuth
{
    /** @return 'login'|'register'|null */
    public static function authTypeForPath(Grav $grav): ?string
    {
        if (!$grav->offsetExists('login')) {
            return null;
        }

        $path = trim((string) $grav['uri']->path(), '/');
        if ($path === '') {
            return null;
        }

        /** @var \Grav\Plugin\Login\Login $login */
        $login = $grav['login'];

        $map = [
            trim((string) ($login->getRoute('login') ?? '/login'), '/') => 'login',
            trim((string) ($login->getRoute('register', true) ?? '/user_register'), '/') => 'register',
        ];

        return $map[$path] ?? null;
    }

    /** @return 'login'|'register'|null */
    public static function authTypeForRequest(Grav $grav): ?string
    {
        $byPath = self::authTypeForPath($grav);
        if ($byPath !== null) {
            return $byPath;
        }

        $page = $grav['page'] ?? null;
        if (!$page instanceof PageInterface) {
            return null;
        }

        $template = (string) $page->template();

        return in_array($template, ['login', 'register'], true) ? $template : null;
    }

    public static function ensureVirtualAuthPages(Grav $grav): void
    {
        $type = self::authTypeForPath($grav);
        if ($type === null) {
            return;
        }

        /** @var \Grav\Plugin\Login\Login $login */
        $login = $grav['login'];
        $route = '/' . trim((string) $grav['uri']->path(), '/');
        $page = self::resolveAuthPage($grav, $login, $type, $route);
        if (!$page instanceof PageInterface) {
            return;
        }

        unset($grav['page']);
        $grav['page'] = $page;
    }

    /** @param 'login'|'register' $type */
    public static function resolveAuthPage(Grav $grav, $login, string $type, string $route): ?PageInterface
    {
        /** @var \Grav\Common\Page\Pages $pages */
        $pages = $grav['pages'];
        $existing = $pages->find($route);
        if ($existing instanceof PageInterface && $existing->exists()) {
            $template = (string) $existing->template();
            if (in_array($template, ['login', 'register'], true)) {
                return $login->addPage($type, $route);
            }
        }

        $page = new Page();
        $page->init(new \SplFileInfo('plugin://login/pages/' . $type . '.md'));
        $page->route($route);
        $page->slug(basename(trim($route, '/')) ?: $type);

        if ($existing instanceof PageInterface && $existing->exists()) {
            $title = trim((string) $existing->title());
            if ($title !== '') {
                $page->title($title);
            }

            $content = trim((string) $existing->content());
            if ($content !== '') {
                $page->content($content);
            }
        }

        return $login->addPage($type, $route, $page);
    }

    /** @return 'login'|'register'|null */
    public static function applyAuthSkin(Grav $grav): ?string
    {
        if (!(bool) MudMambersConfig::get($grav, 'auth_skin', true)) {
            return null;
        }

        $type = self::authTypeForRequest($grav);
        if ($type === null) {
            return null;
        }

        self::ensureAuthTwigPaths($grav);

        $skins = [
            'register' => 'mambers-auth/register.html.twig',
            'login' => 'mambers-auth/login.html.twig',
        ];

        $grav['assets']->add('plugin://mambers/assets/mambers-auth.css');
        $grav['twig']->twig_vars['mambers_theme_layout'] = MudMambersTheme::resolveLayout($grav);
        $grav['twig']->template = $skins[$type];

        return $type;
    }

    public static function ensureAuthTwigPaths(Grav $grav): void
    {
        $locator = $grav['locator'];
        $candidates = [
            $locator->findResource('plugin://login/templates'),
            $locator->findResource('plugin://form/templates'),
        ];

        $twig = $grav['twig'];
        foreach ($candidates as $path) {
            if (!is_string($path) || $path === '' || !is_dir($path)) {
                continue;
            }
            if (!in_array($path, $twig->twig_paths, true)) {
                array_unshift($twig->twig_paths, $path);
            }
        }
    }
}
