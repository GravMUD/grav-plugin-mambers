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
use Grav\Plugin\Mambers\MudMambersAuth;
use Grav\Plugin\Mambers\MudMambersConfig;
use Grav\Plugin\Mambers\MudMambersPermissions;
use Grav\Plugin\Mambers\MudMambersProfile;
use Grav\Plugin\Mambers\MudMambersRouter;
use Grav\Plugin\Mambers\MudMambersTheme;
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
            'onUserLoginAuthorized' => ['onUserLoginAuthorized', 0],
            PageAuthorizeEvent::class => ['onPageAuthorizeEvent', -5000],
            'onPluginsInitialized' => [['onPluginsInitializedEarly', 100000], ['syncLoginIntegration', 0]],
            'onPagesInitialized' => [['ensureAuthPages', 10000], ['onPagesInitialized', 0]],
            'onPageNotFound' => ['onPagesInitialized', 0],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigLoader' => ['onTwigLoader', 0],
            'onTwigInitialized' => ['onTwigInitialized', 0],
            'onLoginPage' => ['onLoginPage', 0],
            'onTwigSiteVariables' => [['onTwigSiteVariablesAuthSkin', -99900], ['onTwigSiteVariablesMambers', -99800]],
            'onApiBlueprintResolved' => ['onApiBlueprintResolved', 0],
            'onMudFenceRender' => ['onMudFenceRender', 0],
        ];

        $events['onApiRegisterRoutes'] = ['onApiRegisterRoutes', 0];
        $events['onApiCollectPublicRoutes'] = ['onApiCollectPublicRoutes', 0];

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

        if (!self::supportsGravApiBridge()) {
            return;
        }

        require_once __DIR__ . '/classes/MudMambersApiBridgeController.php';
        require_once __DIR__ . '/classes/MudMambersApi.php';
    }

    public function syncLoginIntegration(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        MudMambersAuth::ensureLoginConfig($this->grav);

        if (!(bool) MudMambersConfig::get($this->grav, 'sync_login_redirects', false)) {
            return;
        }

        require_once __DIR__ . '/classes/MudMambersProfile.php';

        $profileMe = MudMambersProfile::profileMeRoute($this->grav);
        $loginRoute = (string) MudMambersConfig::get($this->grav, 'redirect_anonymous_to', '/login');
        $afterLogin = (string) MudMambersConfig::get($this->grav, 'redirect_after_login', $profileMe);
        if ($afterLogin === '') {
            $afterLogin = $profileMe;
        }

        $this->config->set('plugins.login.redirect_after_login', $afterLogin);
        $this->config->set('plugins.login.route_after_login', $afterLogin);

        if (!(bool) MudMambersConfig::get($this->grav, 'public_registration', true)) {
            return;
        }

        $afterRegister = (string) MudMambersConfig::get($this->grav, 'redirect_after_registration', $loginRoute);
        if ($afterRegister === '') {
            $afterRegister = $loginRoute;
        }

        $this->config->set('plugins.login.user_registration.options.login_after_registration', (bool) MudMambersConfig::get($this->grav, 'login_after_registration', false));
        $this->config->set('plugins.login.user_registration.redirect_after_registration', $afterRegister);
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

    public function onUserLoginAuthorized(UserLoginEvent $event): void
    {
        if (!$this->isEnabled() || $event->getStatus() === UserLoginEvent::AUTHORIZATION_DENIED) {
            return;
        }

        require_once __DIR__ . '/classes/MudMambersProfile.php';

        $afterLogin = (string) MudMambersConfig::get($this->grav, 'redirect_after_login', MudMambersProfile::profileMeRoute($this->grav));
        if ($afterLogin !== '') {
            $event->defRedirect($afterLogin);
        }
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
            'name' => 'profile_bio_html',
            'type' => 'textarea',
            'label' => 'Profile bio (HTML)',
        ]);
        $fields = $this->insertFieldAfter($fields, 'profile_bio_html', [
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
        $segment = MudMambersConfig::apiRouteSegment($this->grav);
        $routes->addRoute(['GET', 'PATCH', 'POST', 'DELETE', 'OPTIONS'], '/' . $segment, $controller);
        $routes->addRoute(['GET', 'PATCH', 'POST', 'DELETE', 'OPTIONS'], '/' . $segment . '/{subpath:.+}', $controller);
    }

    public function onTwigTemplatePaths(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        MudMambersAuth::registerAuthTwigTemplatePaths($this->grav);
    }

    public function onTwigLoader(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $path = __DIR__ . '/templates';
        if (is_dir($path)) {
            $this->grav['twig']->addPath($path);
        }

        MudMambersAuth::prependAuthTwigLoaderPaths($this->grav);
    }

    public function onTwigInitialized(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $path = __DIR__ . '/templates';
        if (is_dir($path) && !in_array($path, $this->grav['twig']->twig_paths, true)) {
            $this->grav['twig']->twig_paths[] = $path;
        }
    }

    public function onLoginPage(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        MudMambersAuth::applyAuthSkin($this->grav);
    }

    public function onTwigSiteVariablesAuthSkin(): void
    {
        if (!$this->isEnabled() || $this->isAdmin()) {
            return;
        }

        MudMambersAuth::applyAuthSkin($this->grav);
    }

    public function onTwigSiteVariablesMambers(): void
    {
        if (!$this->isEnabled() || $this->isAdmin()) {
            return;
        }

        MudMambersAuth::publishTwigVars($this->grav);

        $route = MudMambersConfig::apiRouteSegment($this->grav);

        $this->grav['twig']->twig_vars['grav_mambers'] = [
            'enabled' => true,
            'name' => 'Mambers',
            'version' => '0.2.29',
            'api_route' => $route,
            'api' => MudMambersConfig::apiUrl($this->grav),
        ];
    }

    /** @param Event $event */
    public function onMudFenceRender(Event $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        require_once __DIR__ . '/classes/MudMambersFences.php';
        require_once __DIR__ . '/classes/MudMambersAuth.php';
        require_once __DIR__ . '/classes/MudMambersProfile.php';

        $data = (array) ($event['data'] ?? []);
        $data['api'] = $data['api'] ?? MudMambersConfig::apiUrl($this->grav);
        $data['login_url'] = MudMambersAuth::loginRoute($this->grav);
        $data['register_url'] = MudMambersAuth::registerRoute($this->grav);
        $data['profile_me_url'] = MudMambersProfile::profileMeUrl($this->grav);
        $data['registration_open'] = MudMambersAuth::registrationOpen($this->grav);

        $html = \Grav\Plugin\Mambers\MudMambersFences::render(
            strtolower((string) ($event['type'] ?? '')),
            (array) ($event['node'] ?? []),
            (array) ($event['attrs'] ?? []),
            (string) ($event['body'] ?? ''),
            $data
        );

        if ($html !== null && $html !== '') {
            $event['html'] = $html;
            $this->enqueueFenceAssets($html);
        }
    }

    public function onApiCollectPublicRoutes(Event $event): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $apiBase = (string) ($event['api_base'] ?? '/api/v1');
        $prefixes = (array) ($event['prefixes'] ?? []);
        $prefixes[] = rtrim($apiBase, '/') . '/' . MudMambersConfig::apiRouteSegment($this->grav);
        $prefixes[] = MudMambersConfig::publicApiPath($this->grav);
        $event['prefixes'] = $prefixes;
    }

    public function ensureAuthPages(): void
    {
        if (!$this->isEnabled() || $this->isAdmin()) {
            return;
        }

        MudMambersAuth::ensureVirtualAuthPages($this->grav);
    }

    public function onPagesInitialized(): void
    {
        if (!$this->isEnabled() || $this->isAdmin()) {
            return;
        }

        if ($this->handleProfiles()) {
            exit;
        }
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

    private function enqueueFenceAssets(string $html): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $assets = $this->grav['assets'];
        $assets->addCss('plugin://mambers/assets/mambers-profiles.css');
        $assets->addCss('plugin://mambers/assets/mud-mambers-fences.css');
        $assets->addCss('plugin://mambers/assets/mambers-auth.css');
        if (str_contains($html, 'data-mambers-activity')) {
            $assets->addCss('plugin://mambers/assets/mambers-activity.css');
            $assets->addJs('plugin://mambers/assets/mambers-activity.js', ['group' => 'bottom', 'defer' => true]);
        }
        $assets->addJs('plugin://mambers/assets/mud-mambers-fences.js', ['group' => 'bottom', 'defer' => true]);

        if (str_contains($html, 'data-forumz') && $this->isForumzEnabled()) {
            $assets->addCss('plugin://forumz/assets/forumz.css');
            $assets->addJs('plugin://forumz/assets/forumz.js', ['group' => 'bottom', 'defer' => true]);
        }
    }

    private function isForumzEnabled(): bool
    {
        return (bool) $this->grav['config']->get('plugins.forumz.enabled', $this->grav['config']->get('plugins.mud-forumz.enabled', false));
    }
}
