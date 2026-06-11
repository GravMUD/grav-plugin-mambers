<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Config\Config;

/**
 * Config accessor — plugins.mambers.
 */
class MudMambersConfig
{
    /** @param \Grav\Common\Grav|Config $source */
    public static function all($source): array
    {
        if (!is_object($source)) {
            return [];
        }

        if ($source instanceof Config) {
            return (array) $source->get('plugins.mambers', []);
        }

        if (!isset($source['config'])) {
            return [];
        }

        return (array) $source['config']->get('plugins.mambers', []);
    }

    /** @param \Grav\Common\Grav|Config $source */
    /** @param mixed $default */
    public static function get($source, string $key, $default = null)
    {
        $cfg = self::all($source);

        return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
    }

    /** @param \Grav\Common\Grav|Config $source */
    public static function edition($source): string
    {
        return (string) self::get($source, 'edition', 'lite');
    }

    /** @param \Grav\Common\Grav|Config $source */
    public static function isPro($source): bool
    {
        return self::edition($source) === 'pro';
    }

    /** @param \Grav\Common\Grav|Config $source */
    public static function defaultTier($source): string
    {
        return (string) self::get($source, 'default_tier', 'basic');
    }

    /** @param \Grav\Common\Grav|Config $source */
    /** @return array<string, list<string>> */
    public static function tierPermissions($source): array
    {
        $map = self::get($source, 'tier_permissions', []);
        if (!is_array($map) || $map === []) {
            return [
                'basic' => ['site.login', 'site.member'],
                'pro' => ['site.login', 'site.member', 'site.member.pro'],
            ];
        }

        return $map;
    }

    /** @param \Grav\Common\Grav|Config $source */
    public static function isEnabled($source): bool
    {
        return (bool) self::get($source, 'enabled', false);
    }

    /** Route segment registered with Grav API (e.g. mud-mambers). */
    /** @param \Grav\Common\Grav|Config $source */
    public static function apiRouteSegment($source): string
    {
        $route = trim((string) self::get($source, 'api_route', 'mud-mambers'), '/');
        if ($route === '') {
            return 'mud-mambers';
        }

        $parts = explode('/', $route);
        $last = end($parts);

        return is_string($last) && $last !== '' ? $last : 'mud-mambers';
    }

    /** Browser-facing Mambers API base URL (relative path by default). */
    public static function apiUrl(\Grav\Common\Grav $grav): string
    {
        return self::publicApiPath($grav);
    }

    /** Relative Mambers JSON API path (default: /members/api). */
    public static function publicApiPath(\Grav\Common\Grav $grav): string
    {
        if ((string) self::get($grav, 'public_api_route', 'members') === 'grav_api') {
            return '/api/v1/' . self::apiRouteSegment($grav);
        }

        $prefix = trim((string) self::get($grav, 'profile_route_prefix', 'members'), '/');
        if ($prefix === '') {
            return '/api/v1/' . self::apiRouteSegment($grav);
        }

        return '/' . $prefix . '/api';
    }

    /** Ensure share/OG URLs include scheme + host (Grav base_url is often path-only in dev). */
    public static function absoluteUrl(\Grav\Common\Grav $grav, string $urlOrPath): string
    {
        $urlOrPath = trim($urlOrPath);
        if ($urlOrPath === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $urlOrPath)) {
            return $urlOrPath;
        }

        $base = rtrim((string) $grav['base_url'], '/');
        if ($base !== '' && preg_match('#^https?://#i', $base)) {
            return $base . '/' . ltrim($urlOrPath, '/');
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $path = str_starts_with($urlOrPath, '/') ? $urlOrPath : '/' . $urlOrPath;

        return $scheme . '://' . $host . $path;
    }

    /** Mambers key first, then Messenger `giphy_api_key` when empty. */
    public static function giphyApiKey(\Grav\Common\Grav $grav): string
    {
        $key = trim((string) self::get($grav, 'activity_giphy_api_key', ''));
        if ($key !== '') {
            return $key;
        }

        if (!isset($grav['config'])) {
            return '';
        }

        $messenger = trim((string) $grav['config']->get('plugins.messenger.giphy_api_key', ''));
        if ($messenger !== '') {
            return $messenger;
        }

        return trim((string) $grav['config']->get('plugins.grav-mud-messenger.giphy_api_key', ''));
    }

    public static function giphyEnabled(\Grav\Common\Grav $grav): bool
    {
        if (self::giphyApiKey($grav) === '') {
            return false;
        }

        $mambersKey = trim((string) self::get($grav, 'activity_giphy_api_key', ''));
        if ($mambersKey !== '') {
            return true;
        }

        if (!isset($grav['config'])) {
            return false;
        }

        return (bool) $grav['config']->get('plugins.messenger.giphy_enabled', true);
    }
}
