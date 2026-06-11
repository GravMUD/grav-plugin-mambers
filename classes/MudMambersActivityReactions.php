<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;

/** Emoji reactions per activity post (JSON under user-data://mambers/activity/reactions/). */
final class MudMambersActivityReactions
{
    /** @var list<string> */
    public const EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '😠'];

    public static function isEnabled(Grav $grav): bool
    {
        return (bool) MudMambersConfig::get($grav, 'activity_reactions_enabled', true);
    }

    /** @return array{counts: array<string, int>, mine: string|null, total: int} */
    public static function summaryForPost(Grav $grav, string $postId, ?UserInterface $viewer): array
    {
        $postId = strtolower($postId);
        $data = self::read($grav, $postId);
        $counts = [];
        $mine = null;
        $viewerName = ($viewer !== null && $viewer->exists()) ? MudMambersProfile::usernameOf($viewer) : '';
        $total = 0;

        foreach (self::EMOJIS as $emoji) {
            $users = $data[$emoji] ?? [];
            if (!is_array($users)) {
                $users = [];
            }
            $users = array_values(array_unique(array_map('strval', $users)));
            $n = count($users);
            if ($n > 0) {
                $counts[$emoji] = $n;
                $total += $n;
            }
            if ($viewerName !== '' && in_array($viewerName, $users, true)) {
                $mine = $emoji;
            }
        }

        return ['counts' => $counts, 'mine' => $mine, 'total' => $total];
    }

    /** @return array{counts: array<string, int>, mine: string|null, total: int} */
    public static function toggle(Grav $grav, UserInterface $user, string $postId, string $emoji): array
    {
        if (!self::isEnabled($grav)) {
            throw new \RuntimeException('Reactions are disabled.');
        }

        if (MudMambersActivityStorage::resolveAuthor($grav, $postId) === null) {
            throw new \RuntimeException('Post not found.');
        }

        $emoji = self::normalizeEmoji($emoji);
        if ($emoji === null) {
            throw new \RuntimeException('Invalid reaction.');
        }

        $username = MudMambersProfile::usernameOf($user);
        if ($username === '') {
            throw new \RuntimeException('Login required.');
        }

        $postId = strtolower($postId);
        $data = self::read($grav, $postId);

        $onThis = isset($data[$emoji]) && is_array($data[$emoji])
            && in_array($username, $data[$emoji], true);

        foreach (self::EMOJIS as $e) {
            if (!isset($data[$e]) || !is_array($data[$e])) {
                $data[$e] = [];
            }
            $data[$e] = array_values(array_filter(
                $data[$e],
                static fn ($u): bool => strcasecmp((string) $u, $username) !== 0
            ));
        }

        if (!$onThis) {
            $data[$emoji][] = $username;
            $data[$emoji] = array_values(array_unique($data[$emoji]));
        }

        self::write($grav, $postId, $data);

        return self::summaryForPost($grav, $postId, $user);
    }

    /** @return array{counts: array<string, int>, mine: string|null, total: int} */
    public static function remove(Grav $grav, UserInterface $user, string $postId): array
    {
        $username = MudMambersProfile::usernameOf($user);
        if ($username === '') {
            throw new \RuntimeException('Login required.');
        }

        $postId = strtolower($postId);
        $data = self::read($grav, $postId);
        foreach (self::EMOJIS as $emoji) {
            if (!isset($data[$emoji]) || !is_array($data[$emoji])) {
                continue;
            }
            $data[$emoji] = array_values(array_filter(
                $data[$emoji],
                static fn ($u): bool => strcasecmp((string) $u, $username) !== 0
            ));
        }
        self::write($grav, $postId, $data);

        return self::summaryForPost($grav, $postId, $user);
    }

    private static function normalizeEmoji(string $emoji): ?string
    {
        $emoji = trim($emoji);
        return in_array($emoji, self::EMOJIS, true) ? $emoji : null;
    }

    /** @return array<string, list<string>> */
    private static function read(Grav $grav, string $postId): array
    {
        $file = self::path($grav, $postId);
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($file), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, list<string>> $data */
    private static function write(Grav $grav, string $postId, array $data): void
    {
        $dir = MudMambersActivityStorage::reactionsDir($grav);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents(
            self::path($grav, $postId),
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    private static function path(Grav $grav, string $postId): string
    {
        return MudMambersActivityStorage::reactionsDir($grav) . '/' . preg_replace('/[^a-f0-9]/', '', strtolower($postId)) . '.json';
    }
}
