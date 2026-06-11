<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;

final class MudMambersGraph
{
    public static function isEnabled(Grav $grav): bool
    {
        return (bool) MudMambersConfig::get($grav, 'graph_enabled', true);
    }

    public static function follow(Grav $grav, UserInterface $actor, string $target): void
    {
        self::assertActor($actor);
        $actorName = MudMambersProfile::usernameOf($actor);
        $target = strtolower($target);
        self::assertTarget($grav, $target);
        if ($actorName === $target) {
            throw new \RuntimeException('Cannot follow yourself.');
        }
        if (self::isBlockedEitherWay($grav, $actorName, $target)) {
            throw new \RuntimeException('Not allowed.');
        }

        MudMambersGraphStorage::appendEdge($grav, $actorName, 'following.jsonl', $target);
        MudMambersGraphStorage::appendEdge($grav, $target, 'followers.jsonl', $actorName);
    }

    public static function unfollow(Grav $grav, UserInterface $actor, string $target): void
    {
        self::assertActor($actor);
        $actorName = MudMambersProfile::usernameOf($actor);
        $target = strtolower($target);
        MudMambersGraphStorage::removeEdge($grav, $actorName, 'following.jsonl', $target);
        MudMambersGraphStorage::removeEdge($grav, $target, 'followers.jsonl', $actorName);
    }

    public static function block(Grav $grav, UserInterface $actor, string $target): void
    {
        self::assertActor($actor);
        $actorName = MudMambersProfile::usernameOf($actor);
        $target = strtolower($target);
        if ($actorName === $target) {
            throw new \RuntimeException('Cannot block yourself.');
        }
        self::unfollow($grav, $actor, $target);
        $targetUser = MudMambersAccounts::load($grav, $target);
        if ($targetUser !== null) {
            self::unfollow($grav, $targetUser, $actorName);
        }
        MudMambersGraphStorage::appendEdge($grav, $actorName, 'blocked.jsonl', $target);
    }

    public static function unblock(Grav $grav, UserInterface $actor, string $target): void
    {
        self::assertActor($actor);
        $actorName = MudMambersProfile::usernameOf($actor);
        MudMambersGraphStorage::removeEdge($grav, $actorName, 'blocked.jsonl', strtolower($target));
    }

    public static function isFollowing(Grav $grav, string $actor, string $target): bool
    {
        return MudMambersGraphStorage::hasEdge($grav, strtolower($actor), 'following.jsonl', strtolower($target));
    }

    public static function isBlockedEitherWay(Grav $grav, string $a, string $b): bool
    {
        $a = strtolower($a);
        $b = strtolower($b);

        return MudMambersGraphStorage::hasEdge($grav, $a, 'blocked.jsonl', $b)
            || MudMambersGraphStorage::hasEdge($grav, $b, 'blocked.jsonl', $a);
    }

    public static function canViewProfile(Grav $grav, string $profileUsername, ?UserInterface $viewer): bool
    {
        if ($viewer === null || !$viewer->exists()) {
            return true;
        }
        $viewerName = MudMambersProfile::usernameOf($viewer);
        if ($viewerName === '') {
            return true;
        }

        return !self::isBlockedEitherWay($grav, $viewerName, $profileUsername);
    }

    public static function shouldHideAuthorInFeed(Grav $grav, string $author, ?UserInterface $viewer): bool
    {
        if ($viewer === null || !$viewer->exists()) {
            return false;
        }
        $viewerName = MudMambersProfile::usernameOf($viewer);
        if ($viewerName === '') {
            return false;
        }

        return self::isBlockedEitherWay($grav, $viewerName, $author);
    }

    /** @return list<string> */
    public static function followingUsernames(Grav $grav, string $username): array
    {
        return array_map(
            static fn (array $row): string => $row['user'],
            MudMambersGraphStorage::readEdges($grav, strtolower($username), 'following.jsonl')
        );
    }

    /** @return array{followers: int, following: int, is_following: bool, is_blocked: bool, can_follow: bool} */
    public static function statsForViewer(Grav $grav, string $username, ?UserInterface $viewer): array
    {
        $username = strtolower($username);
        $viewerName = ($viewer !== null && $viewer->exists()) ? MudMambersProfile::usernameOf($viewer) : '';
        $isFollowing = $viewerName !== '' && self::isFollowing($grav, $viewerName, $username);
        $isBlocked = $viewerName !== '' && MudMambersGraphStorage::hasEdge($grav, $viewerName, 'blocked.jsonl', $username);

        return [
            'followers' => count(MudMambersGraphStorage::readEdges($grav, $username, 'followers.jsonl')),
            'following' => count(MudMambersGraphStorage::readEdges($grav, $username, 'following.jsonl')),
            'is_following' => $isFollowing,
            'is_blocked' => $isBlocked,
            'can_follow' => $viewerName !== '' && $viewerName !== $username && !self::isBlockedEitherWay($grav, $viewerName, $username),
        ];
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int} */
    public static function listEdges(Grav $grav, string $username, string $type, int $page = 1, int $perPage = 24): array
    {
        $file = $type === 'followers' ? 'followers.jsonl' : 'following.jsonl';
        $edges = MudMambersGraphStorage::readEdges($grav, strtolower($username), $file);
        $items = [];
        foreach ($edges as $edge) {
            $user = MudMambersAccounts::load($grav, $edge['user']);
            if ($user === null || !MudMambersAccounts::isActiveMember($user)) {
                continue;
            }
            if (!MudMambersProfile::isPublic($user)) {
                continue;
            }
            $items[] = [
                'username' => MudMambersProfile::usernameOf($user),
                'display_name' => MudMambersProfile::displayName($user),
                'avatar' => MudMambersProfile::avatarUrl($grav, $user),
                'profile_url' => MudMambersProfile::profilePageUrl($grav, MudMambersProfile::usernameOf($user)),
                'since' => $edge['at'],
            ];
        }

        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $total = count($items);
        $pages = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($items, $offset, $perPage),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    private static function assertActor(UserInterface $actor): void
    {
        if (!$actor->exists() || !MudMambersProfile::usernameOf($actor)) {
            throw new \RuntimeException('Login required.');
        }
    }

    private static function assertTarget(Grav $grav, string $target): void
    {
        if (!MudMambersAccounts::isValidUsername($target)) {
            throw new \RuntimeException('Invalid member.');
        }
        $user = MudMambersAccounts::load($grav, $target);
        if ($user === null || !MudMambersAccounts::isActiveMember($user)) {
            throw new \RuntimeException('Member not found.');
        }
    }
}
