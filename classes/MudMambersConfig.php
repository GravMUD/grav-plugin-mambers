<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Config\Config;

/**
 * Config accessor — plugins.mambers with legacy grav-mud-mambers fallback.
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
            $cfg = (array) $source->get('plugins.mambers', []);
            if ($cfg !== []) {
                return $cfg;
            }

            return (array) $source->get('plugins.grav-mud-mambers', []);
        }

        if (!isset($source['config'])) {
            return [];
        }

        $cfg = (array) $source['config']->get('plugins.mambers', []);
        if ($cfg !== []) {
            return $cfg;
        }

        return (array) $source['config']->get('plugins.grav-mud-mambers', []);
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
}
