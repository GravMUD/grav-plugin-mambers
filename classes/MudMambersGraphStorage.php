<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Grav;

/** Flat-file social graph edges under user-data://mambers/graph/ */
final class MudMambersGraphStorage
{
    public static function graphRoot(Grav $grav): string
    {
        $dir = $grav['locator']->findResource('user-data://mambers/graph', true, true);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return rtrim((string) $dir, '/\\');
    }

    public static function userDir(Grav $grav, string $username): string
    {
        $dir = self::graphRoot($grav) . '/' . strtolower($username);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir;
    }

    /** @return list<array<string, string>> */
    public static function readEdges(Grav $grav, string $username, string $file): array
    {
        $path = self::userDir($grav, $username) . '/' . $file;
        if (!is_file($path)) {
            return [];
        }

        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (!is_array($row) || empty($row['user'])) {
                continue;
            }
            $rows[] = [
                'user' => strtolower((string) $row['user']),
                'at' => (string) ($row['at'] ?? ''),
            ];
        }

        return $rows;
    }

    public static function appendEdge(Grav $grav, string $username, string $file, string $target): void
    {
        $target = strtolower($target);
        foreach (self::readEdges($grav, $username, $file) as $row) {
            if ($row['user'] === $target) {
                return;
            }
        }

        $line = json_encode(['user' => $target, 'at' => gmdate('c')], JSON_UNESCAPED_SLASHES);
        file_put_contents(self::userDir($grav, $username) . '/' . $file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    public static function removeEdge(Grav $grav, string $username, string $file, string $target): void
    {
        $target = strtolower($target);
        $kept = array_values(array_filter(
            self::readEdges($grav, $username, $file),
            static fn (array $row): bool => $row['user'] !== $target
        ));
        self::writeEdges($grav, $username, $file, $kept);
    }

    public static function hasEdge(Grav $grav, string $username, string $file, string $target): bool
    {
        $target = strtolower($target);
        foreach (self::readEdges($grav, $username, $file) as $row) {
            if ($row['user'] === $target) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, string>> $rows */
    private static function writeEdges(Grav $grav, string $username, string $file, array $rows): void
    {
        $path = self::userDir($grav, $username) . '/' . $file;
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = json_encode($row, JSON_UNESCAPED_SLASHES);
        }
        file_put_contents($path, implode("\n", $lines) . ($lines !== [] ? "\n" : ''), LOCK_EX);
    }
}
