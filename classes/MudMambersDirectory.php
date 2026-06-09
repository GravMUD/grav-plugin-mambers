<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

use Grav\Common\Grav;

final class MudMambersDirectory
{
    /** @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int} */
    public static function listing(Grav $grav, string $search = '', int $page = 1, int $perPage = 24): array
    {
        $search = strtolower(trim($search));
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        require_once __DIR__ . '/MudMambersDirectoryCache.php';
        $items = MudMambersDirectoryCache::cards($grav);
        if ($search !== '') {
            $items = array_values(array_filter($items, static function (array $card) use ($search): bool {
                $hay = strtolower(
                    (string) ($card['username'] ?? '') . ' '
                    . (string) ($card['display_name'] ?? '') . ' '
                    . (string) ($card['profile_bio'] ?? '')
                );

                return str_contains($hay, $search);
            }));
        }

        foreach ($items as &$card) {
            unset($card['profile_bio']);
        }
        unset($card);

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
}
