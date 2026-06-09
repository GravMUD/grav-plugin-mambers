<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Page;

final class MudMambersAuth
{
    private const DEFAULT_LOGIN_ROUTE = '/login';
    private const DEFAULT_REGISTER_ROUTE = '/user_register';

    public static function ensureLoginConfig(Grav $grav): void
    {
        if (!(bool) MudMambersConfig::get($grav, 'public_registration', true)) {
            return;
        }

        $grav['config']->set('plugins.login.user_registration.enabled', true);

        $registerRoute = $grav['config']->get('plugins.login.route_register');
        if (!is_string($registerRoute) || trim($registerRoute) === '') {
            $grav['config']->set('plugins.login.route_register', self::DEFAULT_REGISTER_ROUTE);
        }

        $loginRoute = $grav['config']->get('plugins.login.route');
        if (!is_string($loginRoute) || trim($loginRoute) === '') {
            $grav['config']->set('plugins.login.route', self::DEFAULT_LOGIN_ROUTE);
        }
    }

    public static function registrationOpen(Grav $grav): bool
    {
        if (!(bool) MudMambersConfig::get($grav, 'public_registration', true)) {
            return false;
        }

        self::ensureLoginConfig($grav);

        return (bool) $grav['config']->get('plugins.login.user_registration.enabled', false);
    }

    public static function loginRoute(Grav $grav): string
    {
        self::ensureLoginConfig($grav);

        if ($grav->offsetExists('login')) {
            $route = $grav['login']->getRoute('login');
            if (is_string($route) && trim($route) !== '') {
                return '/' . trim($route, '/');
            }
        }

        $route = $grav['config']->get('plugins.login.route');

        return is_string($route) && trim($route) !== ''
            ? '/' . trim($route, '/')
            : self::DEFAULT_LOGIN_ROUTE;
    }

    public static function registerRoute(Grav $grav): string
    {
        if (!self::registrationOpen($grav)) {
            return self::loginRoute($grav);
        }

        if ($grav->offsetExists('login')) {
            $route = $grav['login']->getRoute('register');
            if (is_string($route) && trim($route) !== '') {
                return '/' . trim($route, '/');
            }
        }

        $route = $grav['config']->get('plugins.login.route_register');

        return is_string($route) && trim($route) !== ''
            ? '/' . trim($route, '/')
            : self::DEFAULT_REGISTER_ROUTE;
    }

    public static function authUrl(Grav $grav, string $route): string
    {
        $base = rtrim((string) $grav['base_url_relative'], '/');
        $route = '/' . trim($route, '/');

        return ($base === '' ? '' : $base) . $route;
    }

    public static function publishTwigVars(Grav $grav): void
    {
        self::ensureLoginConfig($grav);

        $twig = $grav['twig'];
        $twig->twig_vars['mambers_login_url'] = self::authUrl($grav, self::loginRoute($grav));
        $twig->twig_vars['mambers_register_url'] = self::authUrl($grav, self::registerRoute($grav));
        $twig->twig_vars['mambers_claim_url'] = self::authUrl($grav, MudMambersProfile::profileMeRoute($grav));
        $twig->twig_vars['mambers_registration_open'] = self::registrationOpen($grav);
    }

    /** @return 'login'|'register'|null */
    public static function authTypeForPath(Grav $grav): ?string
    {
        $path = trim((string) $grav['uri']->path(), '/');
        if ($path === '') {
            return null;
        }

        $map = [
            trim(self::loginRoute($grav), '/') => 'login',
            trim(self::registerRoute($grav), '/') => 'register',
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
        if (!$grav->offsetExists('login')) {
            return;
        }

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

        self::prependAuthTwigLoaderPaths($grav);

        $skins = [
            'register' => 'mambers-auth/register.html.twig',
            'login' => 'mambers-auth/login.html.twig',
        ];

        $grav['assets']->add('plugin://mambers/assets/mambers-auth.css');
        $grav['twig']->twig_vars['mambers_theme_layout'] = MudMambersTheme::resolveLayout($grav);
        $grav['twig']->template = $skins[$type];

        return $type;
    }

    public static function registerAuthTwigTemplatePaths(Grav $grav): void
    {
        $locator = $grav['locator'];
        $paths = [
            $locator->findResource('plugin://form/templates'),
            $locator->findResource('plugin://login/templates'),
        ];

        $twig = $grav['twig'];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || !is_dir($path)) {
                continue;
            }
            if (!in_array($path, $twig->twig_paths, true)) {
                array_unshift($twig->twig_paths, $path);
            }
        }
    }

    public static function prependAuthTwigLoaderPaths(Grav $grav): void
    {
        $locator = $grav['locator'];
        $paths = [
            $locator->findResource('plugin://form/templates'),
            $locator->findResource('plugin://login/templates'),
        ];

        $twig = $grav['twig'];
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || !is_dir($path)) {
                continue;
            }
            try {
                $twig->prependPath($path);
            } catch (\Throwable) {
                // Loader not ready yet; onTwigTemplatePaths registration covers this case.
            }
        }
    }
}
