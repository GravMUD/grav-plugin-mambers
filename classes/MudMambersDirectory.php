<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Grav;
use Grav\Common\User\Interfaces\UserInterface;

final class MudMambersDirectory
{
    /** @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int} */
    public static function listing(Grav $grav, string $search = '', int $page = 1, int $perPage = 24): array
    {
        $search = strtolower(trim($search));
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $items = [];
        foreach (MudMambersAccounts::memberAccounts($grav) as $user) {
            if (!MudMambersProfile::isPublic($user)) {
                continue;
            }

            if ($search !== '') {
                $hay = strtolower(
                    MudMambersProfile::usernameOf($user) . ' ' . MudMambersProfile::displayName($user) . ' ' . (string) $user->get('profile_bio')
                );
                if (!str_contains($hay, $search)) {
                    continue;
                }
            }

            $items[] = self::cardPayload($grav, $user);
        }

        $total = count($items);
        $pages = max(1, (int) ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($items, $offset, $perPage),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    /** @return array<string, mixed> */
    private static function cardPayload(Grav $grav, UserInterface $user): array
    {
        $bio = trim((string) $user->get('profile_bio'));

        return [
            'username' => MudMambersProfile::usernameOf($user),
            'display_name' => MudMambersProfile::displayName($user),
            'avatar' => MudMambersProfile::avatarUrl($grav, $user),
            'cover' => MudMambersProfile::coverUrl($grav, $user),
            'bio_excerpt' => MudMambersProfile::excerpt($bio, 120),
            'tier' => (string) ($user->get('member_tier') ?: 'basic'),
            'profile_url' => MudMambersProfile::profilePageUrl($grav, MudMambersProfile::usernameOf($user)),
        ];
    }
}
