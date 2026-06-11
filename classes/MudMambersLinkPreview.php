<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Grav;

final class MudMambersLinkPreview
{
    /** @return array<string, mixed>|null */
    public static function fetch(Grav $grav, string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || !self::isSafeUrl($url)) {
            return null;
        }

        $hash = hash('sha256', $url);
        $cacheFile = MudMambersActivityStorage::linkCacheDir($grav) . '/' . $hash . '.json';
        if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < 86400) {
            $raw = file_get_contents($cacheFile);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $html = self::fetchHtml($url);
        if ($html === null) {
            return null;
        }

        $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        $preview = [
            'url' => $url,
            'title' => self::meta($html, 'og:title') ?: self::titleTag($html) ?: $url,
            'description' => self::meta($html, 'og:description') ?: self::meta($html, 'description') ?: '',
            'image' => self::meta($html, 'og:image') ?: '',
            'site_name' => self::meta($html, 'og:site_name') ?: $host,
            'fetched_at' => gmdate('c'),
        ];

        if (!is_dir(dirname($cacheFile))) {
            @mkdir(dirname($cacheFile), 0755, true);
        }
        @file_put_contents($cacheFile, json_encode($preview, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);

        return $preview;
    }

    public static function isSafeUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return !self::isPrivateIp($host);
        }

        $resolved = gethostbyname($host);
        if ($resolved !== $host && self::isPrivateIp($resolved)) {
            return false;
        }

        return true;
    }

    private static function isPrivateIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6)) {
            return true;
        }

        return !filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    private static function fetchHtml(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'follow_location' => 1,
                'max_redirects' => 3,
                'user_agent' => 'MambersLinkPreview/1.0 (+https://mambers.gravmud.site)',
                'header' => "Accept: text/html\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $html = @file_get_contents($url, false, $context, 0, 512000);

        return is_string($html) && $html !== '' ? $html : null;
    }

    private static function meta(string $html, string $property): string
    {
        $patterns = [
            '/<meta[^>]+(?:property|name)=["\']' . preg_quote($property, '/') . '["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']' . preg_quote($property, '/') . '["\']/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $m)) {
                return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }

        return '';
    }

    private static function titleTag(string $html): string
    {
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }
}
