<?php

namespace Grav\Plugin;

require_once __DIR__ . '/classes/MudMambersConfig.php';

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Common\User\Interfaces\UserInterface;
use Grav\Events\PermissionsRegisterEvent;
use Grav\Framework\Acl\PermissionsReader;
use Grav\Plugin\Login\Events\PageAuthorizeEvent;
use Grav\Plugin\Login\Events\UserLoginEvent;
use Grav\Plugin\Mambers\MudMambersApiBridgeController;
use Grav\Plugin\Mambers\MudMambersConfig;
use Grav\Plugin\Mambers\MudMambersPermissions;
use RocketTheme\Toolbox\Event\Event;

class MambersPlugin extends Plugin
{
    public static function getSubscribedEvents(): array
    {
        $events = [
            PermissionsRegisterEvent::class => ['onRegisterPermissions', 1000],
            'onUserLoginRegisterData' => ['onUserLoginRegisterData', 0],
            'onUserLoginRegistered' => ['onUserLoginRegistered', 0],
            'onUserLoginAuthorize' => ['onUserLoginAuthorize', 5],
            PageAuthorizeEvent::class => ['onPageAuthorizeEvent', -5000],
            'onPluginsInitialized' => [['onPluginsInitializedEarly', 100000]],
            'onPagesInitialized' => ['onPagesInitialized', 0],
            'onPageNotFound' => ['onPageNotFound', 0],
            'onTwigInitialized' => ['onTwigInitialized', 0],
            'onApiBlueprintResolved' => ['onApiBlueprintResolved', 0],
        ];

        if (self::supportsGravApiBridge()) {
            $events['onApiRegisterRoutes'] = ['onApiRegisterRoutes', 0];
            $events['onApiCollectPublicRoutes'] = ['onApiCollectPublicRoutes', 0];
        }

        return $events;
    }

    public function autoload(): ClassLoader
    {
        $loader = new ClassLoader();
        $loader->addPsr4('Grav\\Plugin\\Mambers\\', __DIR__ . '/classes');
        $loader->register(true);

        return $loader;
    }

    /** @return array<string, mixed> */
    public static function pluginConfig($grav): array
    {
        return MudMambersConfig::all($grav);
    }

    public function onRegisterPermissions(PermissionsRegisterEvent $event): void
    {
        $actions = PermissionsReader::fromYaml("plugin://{$this->name}/permissions.yaml");
        $event->permissions->addActions($actions);
    }

    public function onPluginsInitializedEarly(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        require_once __DIR__ . '/classes/MudMambersRouter.php';
        if ((new MudMambersRouter($this->grav))->maybeHandle()) {
            exit;
        }

        if (!self::supportsGravApiBridge()) {
            return;
        }

        require_once __DIR__ . '/classes/MudMambersApiBridgeController.php';
        require_once __DIR__ . '/classes/MudMambersApi.php';
    }

    public function onUserLoginRegisterData(Event $event): void
    {
        if (!$this->isEnabled() || !$this->publicRegistrationEnabled()) {
            return;
        }

        require_once __DIR__ . '/classes/MudMambersConfig.php';
        require_once __DIR__ . '/classes/MudMambersPermissions.php';

        $data = $event['data'] ?? null;
        if ($data === null) {
            return;
        }

        $cfg = self::pluginConfig($this->grav);
        $tier = MudMambersConfig::defaultTier($this->grav);
        MudMambersPermissions::applyTierToRegisterData($data, $tier, $cfg);
    }

    public function onUserLoginRegistered(Event $event): void
    {
        if (!$this->isEnabled() || !$this->publicRegistrationEnabled()) {
            return;
        }

        $user = $event['user'] ?? null;
        if (!$user instanceof UserInterface) {
            return;
        }

        if (!$user->get('member_tier')) {
            $user->set('member_tier', MudMambersConfig::defaultTier($this->grav));
        }
        if (!$user->get('member_since')) {
            $user->set('member_since', gmdate('Y-m-d H:i:s'));
        }
        if ($user->get('profile_public') === null || $user->get('profile_public') === '') {
            $user->set('profile_public', true);
        }
        $user->save();
    }

    public function onUserLoginAuthorize(UserLoginEvent $event): void
    {
        if (!$this->isEnabled() || $event->getStatus() === UserLoginEvent::AUTHORIZATION_DENIED) {
            return;
        }

        require_once __DIR__ . '/classes/MudMambersConfig.php';
        require_once __DIR__ . '/classes/MudMambersPermissions.php';

        if (!MudMambersConfig::isPro($this->grav)) {
            return;
        }

        $user = $event->getUser();
        if (!MudMambersPermissions::isMembershipExpired($user)) {
            return;
        }

        $event->setMessage('Your membership has expired. Please renew to continue.', 'error');
        $event->setStatus(UserLoginEvent::AUTHORIZATION_DENIED);
        $event->stopPropagation();
    }

    public function onPageAuthorizeEvent(PageAuthorizeEvent $event): void
    {
        if (!$this->isEnabled() || $event->isDenied()) {
            return;
        }

        require_once __DIR__ . '/classes/MudMambersConfig.php';

        $page = $event->page;
        $header = $page->header();
        $login = (array) ($header->login ?? []);
        $requiresMember = !empty($login['member']) || (($login['visibility'] ?? null) === 'member');

        if (!$requiresMember && !$this->routeRequiresMember($page->route())) {
            return;
        }

        $user = $event->user;
        if ($user->authorize('site.member')) {
            $event->allow();

            return;
        }

        $event->deny();
    }

    public function onApiBlueprintResolved(Event $event): void
    {
        if (!$this->isEnabled() || ($event['template'] ?? null) !== 'account') {
            return;
        }

        require_once __DIR__ . '/classes/MudMambersConfig.php';

        $fields = $event['fields'];
        $memberTierField = [
            'name' => 'member_tier',
            'type' => 'select',
            'label' => 'Member tier',
            'default' => 'basic',
            'options' => [
                ['value' => 'none', 'label' => 'None'],
                ['value' => 'basic', 'label' => 'Basic'],
                ['value' => 'pro', 'label' => 'Pro'],
            ],
        ];
        $memberSinceField = [
            'name' => 'member_since',
            'type' => 'datetime',
            'label' => 'Member since',
        ];

        $fields = $this->insertFieldAfter($fields, 'email', $memberTierField);
        $fields = $this->insertFieldAfter($fields, 'member_tier', $memberSinceField);
        $fields = $this->insertFieldAfter($fields, 'member_since', [
            'name' => 'profile_public',
            'type' => 'toggle',
            'label' => 'Public profile',
            'default' => 1,
        ]);
        $fields = $this->insertFieldAfter($fields, 'profile_public', [
            'name' => 'profile_bio',
            'type' => 'textarea',
            'label' => 'Profile bio',
        ]);
        $fields = $this->insertFieldAfter($fields, 'profile_bio', [
            'name' => 'profile_cover',
            'type' => 'text',
            'label' => 'Profile cover path',
            'help' => 'Relative path or URL. Cover also drives og:image on profile pages.',
        ]);
        $fields = $this->insertFieldAfter($fields, 'profile_cover', [
            'name' => 'profile_links',
            'type' => 'list',
            'label' => 'Link in bio',
            'fields' => [
                ['name' => 'title', 'type' => 'text', 'label' => 'Title'],
                ['name' => 'url', 'type' => 'text', 'label' => 'URL'],
            ],
        ]);

        if (MudMambersConfig::isPro($this->grav)) {
            $fields = $this->insertFieldAfter($fields, 'profile_links', [
                'name' => 'member_expires',
                'type' => 'datetime',
                'label' => 'Member expires',
            ]);
            $fields = $this->insertFieldAfter($fields, 'member_expires', [
                'name' => 'member_source',
                'type' => 'text',
                'label' => 'Member source',
            ]);
            $fields = $this->insertFieldAfter($fields, 'member_source', [
                'name' => 'member_notes',
                'type' => 'textarea',
                'label' => 'Member notes',
            ]);
        }

        $event['fields'] = $fields;
    }

    public function onApiRegisterRoutes(Event $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        require_once __DIR__ . '/classes/MudMambersApiBridgeController.php';

        $routes = $event['routes'];
        $controller = [MudMambersApiBridgeController::class, 'handle'];
        $routes->addRoute(['GET', 'PATCH', 'POST', 'OPTIONS'], '/mud-mambers', $controller);
        $routes->addRoute(['GET', 'PATCH', 'POST', 'OPTIONS'], '/mud-mambers/{subpath:.+}', $controller);
    }

    public function onTwigInitialized(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $path = __DIR__ . '/templates';
        if (is_dir($path)) {
            $this->grav['twig']->twig_paths[] = $path;
        }
    }

    public function onPageNotFound(): void
    {
        if (!$this->isEnabled() || $this->isAdmin()) {
            return;
        }

        if ($this->handleProfiles()) {
            exit;
        }
    }

    public function onApiCollectPublicRoutes(Event $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $apiBase = (string) ($event['api_base'] ?? '/api/v1');
        $prefixes = (array) ($event['prefixes'] ?? []);
        $prefixes[] = rtrim($apiBase, '/') . '/mud-mambers';
        $event['prefixes'] = $prefixes;
    }

    public function onPagesInitialized(): void
    {
        if (!$this->isEnabled() || $this->isAdmin()) {
            return;
        }

        if ($this->handleProfiles()) {
            exit;
        }

        $action = $this->apiAction();
        if ($action === null) {
            return;
        }

        if (class_exists(\Grav\Plugin\Api\ApiRouteCollector::class)) {
            return;
        }

        require_once __DIR__ . '/classes/MudMambersApi.php';
        (new \Grav\Plugin\Mambers\MudMambersApi($this->grav))->handle($action);
        exit;
    }

    protected function publicRegistrationEnabled(): bool
    {
        if (!(bool) MudMambersConfig::get($this->grav, 'public_registration', true)) {
            return false;
        }

        return (bool) $this->config->get('plugins.login.user_registration.enabled', false);
    }

    protected function routeRequiresMember(string $route): bool
    {
        $route = trim($route, '/');
        $patterns = (array) MudMambersConfig::get($this->grav, 'member_only_routes', []);
        foreach ($patterns as $pattern) {
            $pattern = trim((string) $pattern, '/');
            if ($pattern === '') {
                continue;
            }
            if ($route === $pattern || str_starts_with($route, $pattern . '/')) {
                return true;
            }
        }

        return false;
    }

    protected function apiAction(): ?string
    {
        $cfg = self::pluginConfig($this->grav);
        $route = trim((string) ($cfg['api_route'] ?? 'api/mud-mambers'), '/');
        $path = trim((string) $this->grav['uri']->path(), '/');

        if ($path === $route) {
            return '';
        }

        if (!str_starts_with($path, $route . '/')) {
            return null;
        }

        return trim(substr($path, strlen($route)), '/');
    }

    /** @param list<array<string, mixed>> $fields */
    /** @param array<string, mixed> $newField */
    /** @return list<array<string, mixed>> */
    private function insertFieldAfter(array $fields, string $afterName, array $newField): array
    {
        $out = [];
        $inserted = false;
        foreach ($fields as $field) {
            $out[] = $field;
            if (($field['name'] ?? null) === $afterName) {
                $out[] = $newField;
                $inserted = true;
            }
        }

        if (!$inserted) {
            $out[] = $newField;
        }

        return $out;
    }

    private static function supportsGravApiBridge(): bool
    {
        return class_exists(\Grav\Plugin\Api\ApiRouteCollector::class);
    }

    private function isEnabled(): bool
    {
        return MudMambersConfig::isEnabled($this->grav);
    }

    private function handleProfiles(): bool
    {
        require_once __DIR__ . '/classes/MudMambersRouter.php';

        return (new MudMambersRouter($this->grav))->maybeHandle();
    }
}
