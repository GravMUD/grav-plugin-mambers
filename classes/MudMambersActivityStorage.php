<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Grav;

/** JSONL activity posts under user-data://mambers/activity/ */
final class MudMambersActivityStorage
{
    /** @return list<array<string, mixed>> */
    public static function readUserPosts(Grav $grav, string $username): array
    {
        $file = self::userLogPath($grav, $username);
        if (!is_file($file)) {
            return [];
        }

        $posts = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (is_array($row) && isset($row['id'])) {
                $posts[] = $row;
            }
        }

        usort($posts, static fn (array $a, array $b): int => strcmp((string) ($b['created'] ?? ''), (string) ($a['created'] ?? '')));

        return $posts;
    }

    /** @param array<string, mixed> $post */
    public static function appendPost(Grav $grav, string $username, array $post): void
    {
        self::ensureDirs($grav, $username);
        $file = self::userLogPath($grav, $username);
        $line = json_encode($post, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        self::indexPost($grav, $username, (string) $post['id']);
    }

    /** @param array<string, mixed> $post */
    public static function replacePost(Grav $grav, string $username, string $id, array $post): bool
    {
        $posts = self::readUserPosts($grav, $username);
        $found = false;
        foreach ($posts as $i => $row) {
            if ((string) ($row['id'] ?? '') === $id) {
                $posts[$i] = $post;
                $found = true;
                break;
            }
        }

        if (!$found) {
            return false;
        }

        self::writeUserPosts($grav, $username, $posts);

        return true;
    }

    public static function deletePost(Grav $grav, string $username, string $id): bool
    {
        $posts = self::readUserPosts($grav, $username);
        $before = count($posts);
        $posts = array_values(array_filter($posts, static fn (array $row): bool => (string) ($row['id'] ?? '') !== $id));
        if (count($posts) === $before) {
            return false;
        }

        self::writeUserPosts($grav, $username, $posts);
        self::unindexPost($grav, $id);

        return true;
    }

    /** @return array<string, mixed>|null */
    public static function findPost(Grav $grav, string $id): ?array
    {
        $author = self::resolveAuthor($grav, $id);
        if ($author === null) {
            return null;
        }

        foreach (self::readUserPosts($grav, $author) as $post) {
            if ((string) ($post['id'] ?? '') === $id) {
                return $post;
            }
        }

        return null;
    }

    public static function resolveAuthor(Grav $grav, string $id): ?string
    {
        $index = self::readIndex($grav);

        return isset($index[$id]) ? (string) $index[$id] : null;
    }

    public static function userLogPath(Grav $grav, string $username): string
    {
        return self::activityRoot($grav) . '/' . strtolower($username) . '.jsonl';
    }

    public static function mediaDir(Grav $grav, string $username): string
    {
        return self::activityRoot($grav) . '/media/' . strtolower($username);
    }

    public static function linkCacheDir(Grav $grav): string
    {
        return self::activityRoot($grav) . '/link-cache';
    }

    public static function activityRoot(Grav $grav): string
    {
        $dir = $grav['locator']->findResource('user-data://mambers/activity', true, true);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return rtrim((string) $dir, '/\\');
    }

    public static function generateId(): string
    {
        return bin2hex(random_bytes(6));
    }

    /** @param list<array<string, mixed>> $posts */
    private static function writeUserPosts(Grav $grav, string $username, array $posts): void
    {
        self::ensureDirs($grav, $username);
        $file = self::userLogPath($grav, $username);
        $lines = [];
        foreach ($posts as $post) {
            $lines[] = json_encode($post, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        file_put_contents($file, implode("\n", $lines) . ($lines !== [] ? "\n" : ''), LOCK_EX);
    }

    public static function reactionsDir(Grav $grav): string
    {
        $dir = self::activityRoot($grav) . '/reactions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }

    private static function ensureDirs(Grav $grav, string $username): void
    {
        self::activityRoot($grav);
        $media = self::mediaDir($grav, $username);
        if (!is_dir($media)) {
            @mkdir($media, 0755, true);
        }
        $cache = self::linkCacheDir($grav);
        if (!is_dir($cache)) {
            @mkdir($cache, 0755, true);
        }
        self::reactionsDir($grav);
    }

    private static function indexPath(Grav $grav): string
    {
        return self::activityRoot($grav) . '/post-index.json';
    }

    /** @return array<string, string> */
    private static function readIndex(Grav $grav): array
    {
        $file = self::indexPath($grav);
        if (!is_file($file)) {
            return [];
        }
        $raw = file_get_contents($file);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private static function indexPost(Grav $grav, string $username, string $id): void
    {
        $index = self::readIndex($grav);
        $index[$id] = strtolower($username);
        file_put_contents(self::indexPath($grav), json_encode($index, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private static function unindexPost(Grav $grav, string $id): void
    {
        $index = self::readIndex($grav);
        unset($index[$id]);
        file_put_contents(self::indexPath($grav), json_encode($index, JSON_UNESCAPED_SLASHES), LOCK_EX);
    }
}
