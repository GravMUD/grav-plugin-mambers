<?php

declare(strict_types=1);

namespace Grav\Plugin\Mambers;

/**
 * Mambers MUD fences — embed login, directory, profiles in .mud pages.
 * Registered via onMudFenceRender from mambers.
 */
final class MudMambersFences
{
    /** @param array<string, mixed> $node */
    public static function render(string $type, array $node, array $attrs, string $body, array $data): ?string
    {
        $api = self::apiBase($attrs, $data);

        return match ($type) {
            'mambers', 'mud-mambers', 'mambers-directory' => self::renderDirectory($attrs, $data, $api),
            'mambers-featured', 'featured-mambers' => self::renderFeatured($attrs, $data, $api),
            'mambers-profile', 'member-profile' => self::renderProfile($attrs, $data, $api),
            'mambers-login', 'member-login' => self::renderLogin($attrs, $data, $api),
            'mambers-register', 'member-register' => self::renderRegister($attrs, $data, $api),
            'mambers-auth', 'member-auth' => self::renderAuth($attrs, $data, $api),
            'mambers-feed', 'member-feed' => self::renderFeed($attrs, $data, $api),
            'mambers-activity', 'member-activity' => self::renderActivity($attrs, $data, $api),
            default => null,
        };
    }

    /** @param array<string, mixed> $attrs @param array<string, mixed> $data */
    private static function renderDirectory(array $attrs, array $data, string $api): string
    {
        $limit = (string) ($attrs['limit'] ?? $data['limit'] ?? '24');
        $title = (string) ($attrs['title'] ?? $data['title'] ?? '# Mambers');
        $search = (string) ($attrs['search'] ?? $data['search'] ?? '');

        return self::mount(
            'directory',
            $api,
            [
                'limit' => $limit,
                'title' => $title,
                'search' => $search,
            ],
            'Loading members…'
        );
    }

    /** @param array<string, mixed> $attrs @param array<string, mixed> $data */
    private static function renderFeatured(array $attrs, array $data, string $api): string
    {
        $title = (string) ($attrs['title'] ?? $data['title'] ?? 'Featured Mambers');
        $users = (string) ($attrs['users'] ?? $attrs['featured'] ?? $data['users'] ?? $data['featured'] ?? '');
        $tier = (string) ($attrs['tier'] ?? $data['tier'] ?? '');
        $limit = (string) ($attrs['limit'] ?? $data['limit'] ?? '6');

        if ($users === '' && isset($data['member']) && is_array($data['member'])) {
            $users = implode(',', array_map('strval', $data['member']));
        }

        return self::mount(
            'featured',
            $api,
            [
                'title' => $title,
                'users' => $users,
                'tier' => $tier,
                'limit' => $limit,
            ],
            'Loading featured members…'
        );
    }

    /** @param array<string, mixed> $attrs @param array<string, mixed> $data */
    private static function renderProfile(array $attrs, array $data, string $api): string
    {
        $user = (string) ($attrs['user'] ?? $attrs['username'] ?? $data['user'] ?? $data['username'] ?? '');
        $forumz = self::truthy($attrs['forumz'] ?? $data['forumz'] ?? $attrs['forum_feed'] ?? $data['forum_feed'] ?? false);
        $forumzApi = trim((string) ($attrs['forumz_api'] ?? $data['forumz_api'] ?? '/api/mud-forumz'));
        $forumBoard = (string) ($attrs['board'] ?? $data['board'] ?? 'general');

        $extra = [
            'user' => $user,
            'forumz' => $forumz ? '1' : '0',
            'forumz_api' => '/' . trim($forumzApi, '/'),
            'board' => $forumBoard,
        ];

        $profile = self::mount('profile', $api, $extra, 'Loading profile…');

        if (!$forumz) {
            return $profile;
        }

        $forumzMount = '<div class="mud-forumz mud-forumz--mambers-feed" data-mud-forumz data-mode="profile" data-user="'
            . self::esc($user) . '" data-api="' . self::esc('/' . trim($forumzApi, '/'))
            . '"><p class="mud-forumz-loading">Loading forum activity…</p></div>';

        return '<section class="mud-mambers-wrap mud-mambers-wrap--profile-forumz">'
            . $profile
            . '<div class="mud-mambers-forumz-feed"><h3 class="mud-mambers-subhead">Forum activity</h3>'
            . $forumzMount
            . '</div></section>';
    }

    /** @param array<string, mixed> $attrs @param array<string, mixed> $data */
    private static function renderLogin(array $attrs, array $data, string $api): string
    {
        return self::mount(
            'login',
            $api,
            [
                'login_url' => (string) ($data['login_url'] ?? '/login'),
                'register_url' => (string) ($data['register_url'] ?? '/user_register'),
                'profile_url' => (string) ($data['profile_me_url'] ?? '/members/me'),
                'registration_open' => !empty($data['registration_open']) ? '1' : '0',
            ],
            'Loading login…'
        );
    }

    /** @param array<string, mixed> $attrs @param array<string, mixed> $data */
    private static function renderRegister(array $attrs, array $data, string $api): string
    {
        return self::mount(
            'register',
            $api,
            [
                'login_url' => (string) ($data['login_url'] ?? '/login'),
                'register_url' => (string) ($data['register_url'] ?? '/user_register'),
                'registration_open' => !empty($data['registration_open']) ? '1' : '0',
            ],
            'Loading registration…'
        );
    }

    /** @param array<string, mixed> $attrs @param array<string, mixed> $data */
    private static function renderFeed(array $attrs, array $data, string $api): string
    {
        $limit = (string) ($attrs['limit'] ?? $data['limit'] ?? '20');
        $title = (string) ($attrs['title'] ?? $data['title'] ?? 'Village feed');

        return '<section class="mud-mambers-wrap mud-mambers-wrap--feed"><h2 class="mud-mambers-subhead">' . self::esc($title)
            . '</h2><div class="mambers-activity" data-mambers-activity data-mode="site" data-api="' . self::esc($api)
            . '" data-limit="' . self::esc($limit) . '"><p class="mambers-activity-loading">Loading feed…</p></div></section>';
    }

    /** @param array<string, mixed> $attrs @param array<string, mixed> $data */
    private static function renderActivity(array $attrs, array $data, string $api): string
    {
        $user = (string) ($attrs['user'] ?? $attrs['username'] ?? $data['user'] ?? $data['username'] ?? '');

        return '<section class="mud-mambers-wrap mud-mambers-wrap--activity"><div class="mambers-activity" data-mambers-activity data-mode="profile" data-api="'
            . self::esc($api) . '" data-user="' . self::esc($user)
            . '"><p class="mambers-activity-loading">Loading activity…</p></div></section>';
    }

    /** @param array<string, mixed> $attrs @param array<string, mixed> $data */
    private static function renderAuth(array $attrs, array $data, string $api): string
    {
        return self::mount(
            'auth',
            $api,
            [
                'login_url' => (string) ($data['login_url'] ?? '/login'),
                'register_url' => (string) ($data['register_url'] ?? '/user_register'),
                'profile_url' => (string) ($data['profile_me_url'] ?? '/members/me'),
                'registration_open' => !empty($data['registration_open']) ? '1' : '0',
            ],
            'Loading auth…'
        );
    }

    /**
     * @param array<string, string> $dataAttrs
     */
    private static function mount(string $mode, string $api, array $dataAttrs, string $loading): string
    {
        $attrs = ' data-mud-mambers data-mode="' . self::esc($mode) . '" data-api="' . self::esc($api) . '"';
        foreach ($dataAttrs as $key => $value) {
            if ($value === '') {
                continue;
            }
            $attrs .= ' data-' . self::esc($key) . '="' . self::esc($value) . '"';
        }

        return '<section class="mud-mambers-wrap"><div class="mud-mambers"' . $attrs
            . '><p class="mud-mambers-loading">' . self::esc($loading) . '</p></div></section>';
    }

    /** @param array<string, mixed> $attrs @param array<string, mixed> $data */
    private static function apiBase(array $attrs, array $data): string
    {
        $api = trim((string) ($attrs['api'] ?? $data['api'] ?? '/api/v1/mud-mambers'));

        return '/' . trim($api, '/');
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
