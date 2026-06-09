<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;

final class MudMambersTheme
{
    /** @return list<string> */
    private static function layoutCandidates(): array
    {
        return [
            'default.html.twig',
            'partials/base.html.twig',
            'mambers/layout.html.twig',
        ];
    }

    public static function resolveLayout(Grav $grav): string
    {
        if (!(bool) MudMambersConfig::get($grav, 'profiles_theme_shell', true)) {
            return 'mambers/layout.html.twig';
        }

        $locator = $grav['locator'];
        foreach (self::layoutCandidates() as $template) {
            if ($template === 'mambers/layout.html.twig') {
                return $template;
            }

            $path = $locator->findResource('theme://templates/' . $template);
            if (is_string($path) && $path !== '' && is_file($path)) {
                return $template;
            }
        }

        return 'mambers/layout.html.twig';
    }

    public static function usesPluginFallback(string $layout): bool
    {
        return $layout === 'mambers/layout.html.twig';
    }

    public static function hydrateContext(Grav $grav, string $title, string $description): void
    {
        $twig = $grav['twig'];
        $pages = $grav['pages'];
        $page = self::contextPage($grav);
        $page->title($title);

        $header = $page->header();
        $metadata = (array) ($header->metadata ?? []);
        $metadata['description'] = $description;
        $header->metadata = $metadata;

        $twig->twig_vars['pages'] = $pages->root();
        $twig->twig_vars['page'] = $page;
        $twig->twig_vars['header'] = $header;
        $twig->twig_vars['home_url'] = $pages->homeUrl();
        $twig->twig_vars['user'] = MudMambersSession::user($grav);
        $twig->twig_vars['uri'] = $grav['uri'];

        $grav->fireEvent('onTwigSiteVariables');
    }

    public static function finalizeHtml(Grav $grav, string $html): string
    {
        if (!str_contains($html, '</body>') || !self::messengerBubbleEnabled($grav)) {
            return $html;
        }

        $goggrav = (array) ($grav['twig']->twig_vars['grav_mud_goggrav'] ?? []);
        if (!empty($goggrav['mudSite'])) {
            if (!str_contains($html, 'goggrav-messenger.js')) {
                $snippet = '<script src="/assets/goggrav-messenger.js"></script>';
                $html = preg_replace('/<\/body>/i', $snippet . "\n</body>", $html, 1) ?? $html;
            }

            return $html;
        }

        if (str_contains($html, 'mud-messenger-root') || str_contains($html, 'mud-messenger.js')) {
            return $html;
        }

        try {
            $base = rtrim((string) $grav['base_url'], '/');
            $esc = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $launcher = (string) $grav['twig']->processTemplate('partials/mud-messenger-launcher.html.twig');
            $snippet = '<link rel="stylesheet" href="' . $esc($base . '/user/plugins/messenger/assets/mud-messenger.css') . '">'
                . "\n" . $launcher
                . "\n" . '<script src="' . $esc($base . '/user/plugins/messenger/assets/mud-messenger.js') . '" defer></script>';
            $html = preg_replace('/<\/body>/i', $snippet . "\n</body>", $html, 1) ?? $html;
        } catch (\Throwable) {
            return $html;
        }

        return $html;
    }

    private static function messengerBubbleEnabled(Grav $grav): bool
    {
        $cfg = (array) $grav['config']->get('plugins.messenger', []);
        if ($cfg === []) {
            $cfg = (array) $grav['config']->get('plugins.grav-mud-messenger', []);
        }

        if (array_key_exists('enabled', $cfg) && $cfg['enabled'] === false) {
            return false;
        }

        return !empty($cfg['float_bubble']);
    }

    private static function contextPage(Grav $grav): PageInterface
    {
        $prefix = trim((string) MudMambersConfig::get($grav, 'profile_route_prefix', 'members'), '/');
        $candidates = array_filter([
            '/' . $prefix,
            '/members',
        ]);

        foreach ($candidates as $route) {
            $found = $grav['pages']->find($route);
            if ($found instanceof PageInterface && $found->exists()) {
                return $found;
            }
        }

        $homeAlias = trim((string) $grav['config']->get('system.home.alias', '/home'), '/');
        foreach (['/' . $homeAlias, '/'] as $route) {
            $found = $grav['pages']->find($route);
            if ($found instanceof PageInterface && $found->exists()) {
                return $found;
            }
        }

        /** @var PageInterface $root */
        $root = $grav['pages']->root();

        return $root;
    }
}
