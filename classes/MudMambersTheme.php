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
        $grav->fireEvent('onTwigSiteVariables');

        $twig = $grav['twig'];
        $pages = $grav['pages'];
        $twig->twig_vars['pages'] = $pages->root();

        $page = self::contextPage($grav);
        $page->title($title);

        $header = $page->header();
        $metadata = (array) ($header->metadata ?? []);
        $metadata['description'] = $description;
        $header->metadata = $metadata;

        $twig->twig_vars['page'] = $page;
        $twig->twig_vars['header'] = $header;
        $twig->twig_vars['home_url'] = $pages->homeUrl();
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
