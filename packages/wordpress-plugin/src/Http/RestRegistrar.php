<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Http;

use JOOservices\WordPressMcp\Audit\AuditLogger;
use JOOservices\WordPressMcp\Auth\ConnectionAuthenticator;
use JOOservices\WordPressMcp\Auth\ScopeChecker;
use JOOservices\WordPressMcp\Models\Connection;
use JOOservices\WordPressMcp\Services\BrokenMediaReferenceScanner;
use JOOservices\WordPressMcp\Services\CommentService;
use JOOservices\WordPressMcp\Services\ContentService;
use JOOservices\WordPressMcp\Services\MediaOrphanScanner;
use JOOservices\WordPressMcp\Services\MediaService;
use JOOservices\WordPressMcp\Services\NavigationService;
use JOOservices\WordPressMcp\Services\PluginService;
use JOOservices\WordPressMcp\Services\PostTemplateService;
use JOOservices\WordPressMcp\Services\SeoService;
use JOOservices\WordPressMcp\Services\SettingsService;
use JOOservices\WordPressMcp\Services\StatsService;
use JOOservices\WordPressMcp\Services\SiteOperationsService;
use JOOservices\WordPressMcp\Services\SiteService;
use JOOservices\WordPressMcp\Services\RevisionService;
use JOOservices\WordPressMcp\Services\RedirectService;
use JOOservices\WordPressMcp\Services\TaxonomyService;
use JOOservices\WordPressMcp\Services\ThemeService;
use JOOservices\WordPressMcp\Services\UserService;
use JOOservices\WordPressMcp\Support\ContentTypes;
use JOOservices\WordPressMcp\Support\ErrorCodes;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class RestRegistrar
{
    private const NAMESPACE = 'chatgpt-connector/v1';

    public function register(): void
    {
        register_rest_route(self::NAMESPACE, '/site', [
            'methods' => 'GET',
            'callback' => [$this, 'site'],
            'permission_callback' => [$this, 'authenticate'],
        ]);

        register_rest_route(self::NAMESPACE, '/content', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'searchContent'],
                'permission_callback' => [$this, 'authenticate'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'createContent'],
                'permission_callback' => [$this, 'authenticate'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/content/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getContent'],
                'permission_callback' => [$this, 'authenticate'],
            ],
            [
                'methods' => 'PATCH',
                'callback' => [$this, 'updateContent'],
                'permission_callback' => [$this, 'authenticate'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'deleteContent'],
                'permission_callback' => [$this, 'authenticate'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/post-templates', [
            'methods' => 'GET',
            'callback' => [$this, 'listPostTemplates'],
            'permission_callback' => [$this, 'authenticate'],
        ]);

        register_rest_route(self::NAMESPACE, '/post-templates/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getPostTemplate'],
            'permission_callback' => [$this, 'authenticate'],
        ]);

        register_rest_route(self::NAMESPACE, '/comments', [
            'methods' => 'GET',
            'callback' => [$this, 'listComments'],
            'permission_callback' => [$this, 'authenticate'],
        ]);

        register_rest_route(self::NAMESPACE, '/comments/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getComment'],
                'permission_callback' => [$this, 'authenticate'],
            ],
            [
                'methods' => 'PATCH',
                'callback' => [$this, 'moderateComment'],
                'permission_callback' => [$this, 'authenticate'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/terms', [
            'methods' => 'GET',
            'callback' => [$this, 'listTerms'],
            'permission_callback' => [$this, 'authenticate'],
        ]);

        register_rest_route(self::NAMESPACE, '/media', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'listMedia'],
                'permission_callback' => [$this, 'authenticate'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'uploadMedia'],
                'permission_callback' => [$this, 'authenticate'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/media/orphans', [
            'methods' => 'GET',
            'callback' => [$this, 'getMediaOrphans'],
            'permission_callback' => [$this, 'authenticate'],
        ]);

        register_rest_route(self::NAMESPACE, '/media/broken-references', [
            'methods' => 'GET',
            'callback' => [$this, 'getBrokenMediaReferences'],
            'permission_callback' => [$this, 'authenticate'],
        ]);

        register_rest_route(self::NAMESPACE, '/media/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getMedia'],
                'permission_callback' => [$this, 'authenticate'],
            ],
            [
                'methods' => 'PATCH',
                'callback' => [$this, 'updateMedia'],
                'permission_callback' => [$this, 'authenticate'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'deleteMedia'],
                'permission_callback' => [$this, 'authenticate'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/settings', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getSettings'],
                'permission_callback' => [$this, 'authenticate'],
            ],
            [
                'methods' => 'PATCH',
                'callback' => [$this, 'updateSettings'],
                'permission_callback' => [$this, 'authenticate'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/navigation/menus', [
            ['methods' => 'GET', 'callback' => [$this, 'listMenus'], 'permission_callback' => [$this, 'authenticate']],
            ['methods' => 'POST', 'callback' => [$this, 'createMenu'], 'permission_callback' => [$this, 'authenticate']],
        ]);
        register_rest_route(self::NAMESPACE, '/navigation/menus/(?P<id>\d+)', [
            ['methods' => 'GET', 'callback' => [$this, 'getMenu'], 'permission_callback' => [$this, 'authenticate']],
            ['methods' => 'PATCH', 'callback' => [$this, 'updateMenu'], 'permission_callback' => [$this, 'authenticate']],
            ['methods' => 'DELETE', 'callback' => [$this, 'deleteMenu'], 'permission_callback' => [$this, 'authenticate']],
        ]);
        register_rest_route(self::NAMESPACE, '/navigation/menus/(?P<menu_id>\d+)/items', [
            'methods' => 'POST',
            'callback' => [$this, 'saveMenuItem'],
            'permission_callback' => [$this, 'authenticate'],
        ]);
        register_rest_route(self::NAMESPACE, '/navigation/menus/(?P<menu_id>\d+)/items/(?P<id>\d+)', [
            ['methods' => 'PATCH', 'callback' => [$this, 'saveMenuItem'], 'permission_callback' => [$this, 'authenticate']],
            ['methods' => 'DELETE', 'callback' => [$this, 'deleteMenuItem'], 'permission_callback' => [$this, 'authenticate']],
        ]);
        foreach (
            [
                '/navigation/locations' => ['PATCH', 'setMenuLocations'],
                '/site/health' => ['GET', 'siteHealth'],
                '/updates' => ['GET', 'updates'],
                '/updates/core' => ['POST', 'updateCore'],
                '/maintenance' => ['PATCH', 'maintenance'],
            ] as $route => [$method, $callback]
        ) {
            register_rest_route(self::NAMESPACE, $route, [
                'methods' => $method,
                'callback' => [$this, $callback],
                'permission_callback' => [$this, 'authenticate'],
            ]);
        }
        foreach (
            [
                '/content/(?P<id>\d+)/revisions' => ['GET', 'listRevisions'],
                '/revisions/(?P<id>\d+)' => ['GET', 'getRevision'],
                '/revisions/(?P<id>\d+)/restore' => ['POST', 'restoreRevision'],
                '/redirects/(?P<source>.+)' => ['DELETE', 'deleteRedirect'],
                '/redirects/not-found' => ['GET', 'notFoundLog'],
            ] as $route => [$method, $callback]
        ) {
            register_rest_route(self::NAMESPACE, $route, [
                'methods' => $method,
                'callback' => [$this, $callback],
                'permission_callback' => [$this, 'authenticate'],
            ]);
        }
        register_rest_route(self::NAMESPACE, '/redirects', [
            ['methods' => 'GET', 'callback' => [$this, 'listRedirects'], 'permission_callback' => [$this, 'authenticate']],
            ['methods' => 'POST', 'callback' => [$this, 'upsertRedirect'], 'permission_callback' => [$this, 'authenticate']],
        ]);

        register_rest_route(self::NAMESPACE, '/plugins', [
            'methods' => 'GET',
            'callback' => [$this, 'listPlugins'],
            'permission_callback' => [$this, 'authenticate'],
        ]);

        foreach (['install', 'update', 'delete'] as $action) {
            register_rest_route(self::NAMESPACE, '/plugins/' . $action, [
                'methods' => 'POST',
                'callback' => [$this, 'managePlugin'],
                'permission_callback' => [$this, 'authenticate'],
                'args' => ['action' => ['default' => $action]],
            ]);
        }

        register_rest_route(self::NAMESPACE, '/plugins/state', [
            'methods' => 'POST',
            'callback' => [$this, 'managePlugin'],
            'permission_callback' => [$this, 'authenticate'],
            'args' => ['action' => ['default' => 'state']],
        ]);

        register_rest_route(self::NAMESPACE, '/themes', [
            'methods' => 'GET',
            'callback' => [$this, 'listThemes'],
            'permission_callback' => [$this, 'authenticate'],
        ]);

        foreach (['install', 'activate', 'update', 'delete'] as $action) {
            register_rest_route(self::NAMESPACE, '/themes/' . $action, [
                'methods' => 'POST',
                'callback' => [$this, 'manageTheme'],
                'permission_callback' => [$this, 'authenticate'],
                'args' => ['action' => ['default' => $action]],
            ]);
        }

        register_rest_route(self::NAMESPACE, '/users', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'listUsers'],
                'permission_callback' => [$this, 'authenticate'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'createUser'],
                'permission_callback' => [$this, 'authenticate'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/users/(?P<id>\d+)', [
            [
                'methods' => 'PATCH',
                'callback' => [$this, 'updateUser'],
                'permission_callback' => [$this, 'authenticate'],
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'deleteUser'],
                'permission_callback' => [$this, 'authenticate'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/mcp/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'mcpStats'],
            'permission_callback' => [$this, 'authenticate'],
        ]);

        register_rest_route(self::NAMESPACE, '/mcp/logs', [
            'methods' => 'GET',
            'callback' => [$this, 'mcpLogs'],
            'permission_callback' => [$this, 'authenticate'],
        ]);

        register_rest_route(self::NAMESPACE, '/seo/robots', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getRobots'],
                'permission_callback' => [$this, 'authenticate'],
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'updateRobots'],
                'permission_callback' => [$this, 'authenticate'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/seo/audit', [
            'methods' => 'GET',
            'callback' => [$this, 'seoAudit'],
            'permission_callback' => [$this, 'authenticate'],
        ]);

        register_rest_route(self::NAMESPACE, '/seo/metadata/(?P<id>\d+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'getSeoMetadata'],
                'permission_callback' => [$this, 'authenticate'],
            ],
            [
                'methods' => 'PATCH',
                'callback' => [$this, 'updateSeoMetadata'],
                'permission_callback' => [$this, 'authenticate'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/seo/fix/(?P<id>\d+)', [
            'methods' => 'POST',
            'callback' => [$this, 'seoFix'],
            'permission_callback' => [$this, 'authenticate'],
        ]);
    }

    public function authenticate(WP_REST_Request $request): bool|WP_Error
    {
        RequestContext::reset($request);
        $result = RequestContext::requireConnection($request);

        return $result instanceof WP_Error ? $result : true;
    }

    public function site(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::userCan($connection, 'site.read')) {
            return RequestContext::deny();
        }

        $response = new WP_REST_Response((new SiteService())->get($connection));

        (new AuditLogger())->log(
            $connection->id,
            RequestContext::requestId(),
            'read',
            'site',
            null,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return $response;
    }

    public function searchContent(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        $type = ContentTypes::normalize((string) ($request->get_param('type') ?? ContentTypes::POST));

        if ($type === null) {
            return RequestContext::invalid('Unsupported content type. Use post or page.');
        }

        if (! $this->canReadType($connection, $type)) {
            return RequestContext::deny();
        }

        $service = new ContentService();
        $result = $service->search($request->get_params());

        (new AuditLogger())->log(
            $connection->id,
            RequestContext::requestId(),
            'read',
            $type,
            null,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($result);
    }

    public function getContent(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        $id = (int) $request->get_param('id');
        $post = get_post($id);

        if (! $post instanceof \WP_Post) {
            $err = ErrorCodes::error(ErrorCodes::POST_NOT_FOUND, 'Content not found.', 404);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        if (! ContentTypes::isSupported($post->post_type)) {
            return RequestContext::invalid('Unsupported content type.');
        }

        if (! $this->canReadType($connection, $post->post_type)) {
            return RequestContext::deny();
        }

        $service = new ContentService();
        $item = $service->get($id);
        $audit = new AuditLogger();

        if ($item === null) {
            $audit->log(
                $connection->id,
                RequestContext::requestId(),
                'read',
                $post->post_type,
                (string) $id,
                false,
                null,
                $this->durationMs($startedAt),
            );

            $err = ErrorCodes::error(ErrorCodes::POST_NOT_FOUND, 'Content not found.', 404);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        $audit->log(
            $connection->id,
            RequestContext::requestId(),
            'read',
            $post->post_type,
            (string) $id,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($item);
    }

    public function createContent(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        $params = $request->get_json_params();
        $payload = is_array($params) ? $params : [];
        $requestedType = (string) ($request->get_param('type') ?? ($payload['type'] ?? ContentTypes::POST));
        $type = ContentTypes::normalize($requestedType);

        if ($type === null) {
            return RequestContext::invalid('Unsupported content type. Use post or page.');
        }

        $scope = $type === ContentTypes::PAGE ? 'pages.create' : 'posts.create';

        if (! ScopeChecker::canCreateContent($connection, $type) || ! ScopeChecker::userCan($connection, $scope)) {
            return RequestContext::deny();
        }

        if (array_key_exists('featured_media', $payload) && ! $this->canEmbedMedia($connection, $payload['featured_media'])) {
            return RequestContext::deny();
        }

        if (! $this->canEmbedContentMedia($connection, $payload)) {
            return RequestContext::deny();
        }

        $canPublish = ScopeChecker::canPublishContent($connection, $type)
            && ScopeChecker::userCan($connection, $type === ContentTypes::PAGE ? 'pages.publish' : 'posts.publish');

        $payload['type'] = $type;

        $service = new ContentService();
        $result = $service->create($payload, $canPublish);
        $audit = new AuditLogger();

        if ($result['error'] !== null) {
            $audit->log(
                $connection->id,
                RequestContext::requestId(),
                'create',
                $type,
                null,
                false,
                ['error' => $result['error']],
                $this->durationMs($startedAt),
            );
            $err = ErrorCodes::error($result['error'], 'Failed to create content.', 403);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        $audit->log(
            $connection->id,
            RequestContext::requestId(),
            'create',
            $type,
            (string) ($result['post']['id'] ?? ''),
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($result['post'], 201);
    }

    public function listPostTemplates(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        $type = ContentTypes::normalize(sanitize_key((string) ($request->get_param('type') ?? ContentTypes::POST)), ContentTypes::POST);

        if ($type === null) {
            return RequestContext::invalid('Unsupported content type. Use post or page.');
        }

        $templateScope = $type === ContentTypes::PAGE ? 'pages.templates.read' : 'posts.templates.read';

        if (! ScopeChecker::canReadTemplates($connection, $type) || ! ScopeChecker::userCan($connection, $templateScope)) {
            return RequestContext::deny();
        }

        $result = (new PostTemplateService())->list($request->get_params());

        (new AuditLogger())->log(
            $connection->id,
            RequestContext::requestId(),
            'read',
            'post_template',
            null,
            true,
            ['type' => $type],
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($result);
    }

    public function getPostTemplate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        $id = (int) $request->get_param('id');
        $service = new PostTemplateService();
        $template = $service->get($id);

        if ($template === null) {
            $err = ErrorCodes::error(ErrorCodes::TEMPLATE_NOT_FOUND, 'Post template not found.', 404);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        $type = (string) ($template['for_type'] ?? ContentTypes::POST);
        $templateScope = $type === ContentTypes::PAGE ? 'pages.templates.read' : 'posts.templates.read';

        if (! ScopeChecker::canReadTemplates($connection, $type) || ! ScopeChecker::userCan($connection, $templateScope)) {
            return RequestContext::deny();
        }

        (new AuditLogger())->log(
            $connection->id,
            RequestContext::requestId(),
            'read',
            'post_template',
            (string) $id,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($template);
    }

    public function updateContent(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        $id = (int) $request->get_param('id');
        $post = get_post($id);

        if (! $post instanceof \WP_Post) {
            $err = ErrorCodes::error(ErrorCodes::POST_NOT_FOUND, 'Content not found.', 404);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        if (! ContentTypes::isSupported($post->post_type)) {
            return RequestContext::invalid('Unsupported content type.');
        }

        $type = $post->post_type;
        $scope = $type === ContentTypes::PAGE ? 'pages.update' : 'posts.update';

        if (! ScopeChecker::canUpdateContent($connection, $type) || ! ScopeChecker::userCan($connection, $scope)) {
            return RequestContext::deny();
        }

        $canPublish = ScopeChecker::canPublishContent($connection, $type)
            && ScopeChecker::userCan($connection, $type === ContentTypes::PAGE ? 'pages.publish' : 'posts.publish');

        $params = $request->get_json_params();
        $payload = is_array($params) ? $params : [];

        if (array_key_exists('featured_media', $payload) && ! $this->canEmbedMedia($connection, $payload['featured_media'])) {
            return RequestContext::deny();
        }

        if (! $this->canEmbedContentMedia($connection, $payload)) {
            return RequestContext::deny();
        }

        $service = new ContentService();
        $result = $service->update($id, $payload, $canPublish);
        $audit = new AuditLogger();

        if ($result['error'] !== null) {
            $audit->log(
                $connection->id,
                RequestContext::requestId(),
                'update',
                $type,
                (string) $id,
                false,
                ['error' => $result['error']],
                $this->durationMs($startedAt),
            );
            $status = $result['error'] === ErrorCodes::POST_NOT_FOUND ? 404 : 403;
            $err = ErrorCodes::error($result['error'], 'Failed to update content.', $status);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        $audit->log(
            $connection->id,
            RequestContext::requestId(),
            'update',
            $type,
            (string) $id,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($result['post']);
    }

    public function deleteContent(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        $id = (int) $request->get_param('id');
        $post = get_post($id);

        if (! $post instanceof \WP_Post) {
            $err = ErrorCodes::error(ErrorCodes::POST_NOT_FOUND, 'Content not found.', 404);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        if (! ContentTypes::isSupported($post->post_type)) {
            return RequestContext::invalid('Unsupported content type.');
        }

        $type = $post->post_type;
        $scope = $type === ContentTypes::PAGE ? 'pages.delete' : 'posts.delete';

        if (! ScopeChecker::canDeleteContent($connection, $type) || ! ScopeChecker::userCan($connection, $scope)) {
            return RequestContext::deny();
        }

        $force = filter_var($request->get_param('force') ?? false, FILTER_VALIDATE_BOOLEAN);
        $service = new ContentService();
        $result = $service->delete($id, $force);
        $audit = new AuditLogger();

        if ($result['error'] !== null) {
            $audit->log(
                $connection->id,
                RequestContext::requestId(),
                'delete',
                $type,
                (string) $id,
                false,
                ['error' => $result['error']],
                $this->durationMs($startedAt),
            );
            $status = $result['error'] === ErrorCodes::POST_NOT_FOUND ? 404 : 403;
            $err = ErrorCodes::error($result['error'], 'Failed to delete content.', $status);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        $audit->log(
            $connection->id,
            RequestContext::requestId(),
            'delete',
            $type,
            (string) $id,
            true,
            ['force' => $force],
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response(['deleted' => true, 'id' => $id, 'force' => $force]);
    }

    public function listPlugins(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::userCan($connection, 'plugins.read')) {
            return RequestContext::deny();
        }

        $result = (new PluginService())->list();

        (new AuditLogger())->log(
            $connection->id,
            RequestContext::requestId(),
            'read',
            'plugin',
            null,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($result);
    }

    public function managePlugin(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        $action = (string) $request->get_param('action');
        $params = $request->get_json_params();
        $payload = is_array($params) ? $params : [];

        if ($action === 'state') {
            $enabled = (bool) ($payload['enabled'] ?? false);
            $scope = $enabled ? 'plugins.activate' : 'plugins.deactivate';

            if (! ScopeChecker::userCan($connection, $scope)) {
                return RequestContext::deny();
            }

            $service = new PluginService();
            $result = $service->setState((string) ($payload['plugin'] ?? ''), $enabled);
            $auditAction = $enabled ? 'activate' : 'deactivate';
            $audit = new AuditLogger();

            if (($result['error'] ?? null) !== null) {
                $audit->log(
                    $connection->id,
                    RequestContext::requestId(),
                    $auditAction,
                    'plugin',
                    isset($payload['plugin']) ? (string) $payload['plugin'] : null,
                    false,
                    ['error' => $result['error']],
                    $this->durationMs($startedAt),
                );

                $err = ErrorCodes::error($result['error'], 'Failed to change plugin state.', 400);

                return new WP_Error($err['code'], $err['message'], $err['data']);
            }

            $audit->log(
                $connection->id,
                RequestContext::requestId(),
                $auditAction,
                'plugin',
                isset($payload['plugin']) ? (string) $payload['plugin'] : null,
                true,
                ['enabled' => $enabled],
                $this->durationMs($startedAt),
            );

            return new WP_REST_Response($result['plugin']);
        }

        $scope = match ($action) {
            'install' => 'plugins.install',
            'update' => 'plugins.update',
            'delete' => 'plugins.delete',
            default => null,
        };

        if ($scope === null || ! ScopeChecker::userCan($connection, $scope)) {
            return RequestContext::deny();
        }

        $service = new PluginService();

        $result = match ($action) {
            'install' => $service->install((string) ($payload['slug'] ?? '')),
            'update' => $service->update((string) ($payload['plugin'] ?? '')),
            'delete' => $service->delete((string) ($payload['plugin'] ?? '')),
            default => ['error' => ErrorCodes::INVALID_ARGUMENT],
        };
        $audit = new AuditLogger();

        if (($result['error'] ?? null) !== null) {
            $audit->log(
                $connection->id,
                RequestContext::requestId(),
                $action,
                'plugin',
                isset($payload['plugin']) ? (string) $payload['plugin'] : null,
                false,
                ['error' => $result['error']],
                $this->durationMs($startedAt),
            );
            $status = $result['error'] === ErrorCodes::INVALID_ARGUMENT ? 400 : 403;
            $err = ErrorCodes::error($result['error'], 'Failed to manage plugin.', $status);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        $resourceId = isset($result['plugin']['plugin'])
            ? (string) $result['plugin']['plugin']
            : (isset($payload['plugin']) ? (string) $payload['plugin'] : (string) ($payload['slug'] ?? ''));
        $audit->log(
            $connection->id,
            RequestContext::requestId(),
            $action,
            'plugin',
            $resourceId,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($result['plugin'] ?? ['deleted' => true]);
    }

    public function listThemes(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::userCan($connection, 'themes.read')) {
            return RequestContext::deny();
        }

        $result = (new ThemeService())->list();
        (new AuditLogger())->log(
            $connection->id,
            RequestContext::requestId(),
            'read',
            'theme',
            null,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($result);
    }

    public function manageTheme(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        $action = (string) $request->get_param('action');
        $scope = match ($action) {
            'install' => 'themes.install',
            'activate' => 'themes.activate',
            'update' => 'themes.update',
            'delete' => 'themes.delete',
            default => null,
        };

        if ($scope === null || ! ScopeChecker::userCan($connection, $scope)) {
            return RequestContext::deny();
        }

        $params = $request->get_json_params();
        $payload = is_array($params) ? $params : [];
        $service = new ThemeService();
        $result = match ($action) {
            'install' => $service->install((string) ($payload['slug'] ?? '')),
            'activate' => $service->activate((string) ($payload['stylesheet'] ?? '')),
            'update' => $service->update((string) ($payload['stylesheet'] ?? '')),
            'delete' => $service->delete((string) ($payload['stylesheet'] ?? '')),
            default => ['error' => ErrorCodes::INVALID_ARGUMENT],
        };

        if (($result['error'] ?? null) !== null) {
            $status = $result['error'] === ErrorCodes::INVALID_ARGUMENT ? 400 : 403;
            $err = ErrorCodes::error($result['error'], 'Failed to manage theme.', $status);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        $resourceId = (string) ($result['theme']['stylesheet'] ?? $payload['stylesheet'] ?? $payload['slug'] ?? '');
        (new AuditLogger())->log(
            $connection->id,
            RequestContext::requestId(),
            $action,
            'theme',
            $resourceId,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($result['theme'] ?? ['deleted' => true]);
    }

    public function listUsers(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::userCan($connection, 'users.read')) {
            return RequestContext::deny();
        }

        return new WP_REST_Response((new UserService())->list($request->get_params()));
    }

    public function createUser(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respondToUserMutation($request, 'users.create', 'create');
    }

    public function updateUser(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respondToUserMutation($request, 'users.update', 'update');
    }

    public function deleteUser(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->respondToUserMutation($request, 'users.delete', 'delete');
    }

    public function listComments(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::userCan($connection, 'comments.read')) {
            return RequestContext::deny();
        }

        $result = (new CommentService())->list($request->get_params());

        (new AuditLogger())->log(
            $connection->id,
            RequestContext::requestId(),
            'read',
            'comment',
            null,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($result);
    }

    public function getComment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::userCan($connection, 'comments.read')) {
            return RequestContext::deny();
        }

        $id = (int) $request->get_param('id');
        $item = (new CommentService())->get($id);
        $audit = new AuditLogger();

        if ($item === null) {
            $audit->log(
                $connection->id,
                RequestContext::requestId(),
                'read',
                'comment',
                (string) $id,
                false,
                null,
                $this->durationMs($startedAt),
            );

            $err = ErrorCodes::error(ErrorCodes::COMMENT_NOT_FOUND, 'Comment not found.', 404);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        $audit->log(
            $connection->id,
            RequestContext::requestId(),
            'read',
            'comment',
            (string) $id,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($item);
    }

    public function moderateComment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::userCan($connection, 'comments.moderate')) {
            return RequestContext::deny();
        }

        $action = sanitize_key((string) ($request->get_param('action') ?? ''));
        $result = (new CommentService())->moderate((int) $request->get_param('id'), $action);
        $audit = new AuditLogger();

        if ($result['error'] !== null) {
            $audit->log(
                $connection->id,
                RequestContext::requestId(),
                'moderate',
                'comment',
                (string) $request->get_param('id'),
                false,
                null,
                $this->durationMs($startedAt),
            );

            return RequestContext::invalid('Invalid moderation action or comment not found.');
        }

        $commentId = (string) $request->get_param('id');
        $audit->log(
            $connection->id,
            RequestContext::requestId(),
            'moderate',
            'comment',
            $commentId,
            true,
            ['action' => $action],
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($result['comment']);
    }

    public function listTerms(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::canReadTerms($connection) || ! ScopeChecker::userCan($connection, 'terms.read')) {
            return RequestContext::deny();
        }

        $items = (new TaxonomyService())->list($request->get_params());

        (new AuditLogger())->log(
            $connection->id,
            RequestContext::requestId(),
            'read',
            'term',
            null,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response(['items' => $items]);
    }

    public function listMedia(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::canReadMedia($connection) || ! ScopeChecker::userCan($connection, 'media.read')) {
            return RequestContext::deny();
        }

        $result = (new MediaService())->list($request->get_params());

        (new AuditLogger())->log(
            $connection->id,
            RequestContext::requestId(),
            'read',
            'media',
            null,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($result);
    }

    public function getMediaOrphans(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::canReadMedia($connection) || ! ScopeChecker::userCan($connection, 'media.read')) {
            return RequestContext::deny();
        }

        $result = (new MediaOrphanScanner())->cachedResult();

        (new AuditLogger())->log(
            $connection->id,
            RequestContext::requestId(),
            'read',
            'media_orphans',
            null,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($result ?? [
            'scanned_at' => null,
            'broken_attachments' => [],
            'orphan_files' => ['items' => [], 'truncated' => false],
        ]);
    }

    public function getBrokenMediaReferences(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::canReadMedia($connection) || ! ScopeChecker::userCan($connection, 'media.read')) {
            return RequestContext::deny();
        }

        $postId = $request->get_param('post_id');
        $result = (new BrokenMediaReferenceScanner())->scan($postId !== null ? (int) $postId : null);

        (new AuditLogger())->log(
            $connection->id,
            RequestContext::requestId(),
            'read',
            'media_broken_references',
            $postId !== null ? (string) $postId : null,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($result);
    }

    public function uploadMedia(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::canUploadMedia($connection) || ! ScopeChecker::userCan($connection, 'media.upload')) {
            return RequestContext::deny();
        }

        $params = $request->get_json_params();
        $payload = is_array($params) ? $params : [];
        $result = (new MediaService())->upload($payload);
        $audit = new AuditLogger();

        if ($result['error'] !== null) {
            $audit->log(
                $connection->id,
                RequestContext::requestId(),
                'upload',
                'media',
                null,
                false,
                [
                    'error' => $result['error'],
                    'error_step' => $result['error_step'],
                ],
                $this->durationMs($startedAt),
            );
            $status = match ($result['error']) {
                ErrorCodes::INVALID_ARGUMENT, ErrorCodes::MEDIA_VERIFY_FAILED => 400,
                ErrorCodes::MEDIA_UPLOAD_LIMIT_EXCEEDED => 413,
                ErrorCodes::POST_NOT_FOUND => 404,
                default => 403,
            };
            $err = ErrorCodes::error(
                $result['error'],
                'Failed to upload media.',
                $status,
                array_filter([
                    'verification_step' => $result['error_step'],
                    'verification' => $result['verification'],
                ], static fn(mixed $value): bool => $value !== null),
            );

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        $mediaId = (string) ($result['media']['id'] ?? '');
        $audit->log(
            $connection->id,
            RequestContext::requestId(),
            'upload',
            'media',
            $mediaId,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($result['media'], 201);
    }

    public function getMedia(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::canReadMedia($connection) || ! ScopeChecker::userCan($connection, 'media.read')) {
            return RequestContext::deny();
        }

        $id = (int) $request->get_param('id');
        $verify = filter_var($request->get_param('verify') ?? false, FILTER_VALIDATE_BOOLEAN);
        $item = (new MediaService())->get($id, $verify);
        $audit = new AuditLogger();

        if ($item === null) {
            $audit->log(
                $connection->id,
                RequestContext::requestId(),
                'read',
                'media',
                (string) $id,
                false,
                null,
                $this->durationMs($startedAt),
            );

            $err = ErrorCodes::error(ErrorCodes::MEDIA_NOT_FOUND, 'Media not found.', 404);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        $audit->log(
            $connection->id,
            RequestContext::requestId(),
            'read',
            'media',
            (string) $id,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($item);
    }

    public function updateMedia(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        $id = (int) $request->get_param('id');
        if (! ScopeChecker::userCan($connection, 'media.update') || ! user_can($connection->userId, 'edit_post', $id)) {
            return RequestContext::deny();
        }

        $params = $request->get_json_params();
        $result = (new MediaService())->update($id, is_array($params) ? $params : []);

        if ($result['error'] !== null) {
            $this->auditMedia($connection, 'update', $id, false, $result['error'], $startedAt);
            $status = match ($result['error']) {
                ErrorCodes::MEDIA_NOT_FOUND => 404,
                ErrorCodes::MEDIA_VERIFY_FAILED => 400,
                default => 400,
            };
            $err = ErrorCodes::error(
                $result['error'],
                'Failed to update media.',
                $status,
                array_filter(['verification_step' => $result['error_step']], static fn(mixed $value): bool => $value !== null),
            );

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        $this->auditMedia($connection, 'update', $id, true, null, $startedAt);

        return new WP_REST_Response($result['media']);
    }

    public function deleteMedia(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        $id = (int) $request->get_param('id');
        if (! ScopeChecker::userCan($connection, 'media.delete') || ! user_can($connection->userId, 'delete_post', $id)) {
            return RequestContext::deny();
        }

        $result = (new MediaService())->delete($id);

        if ($result['error'] !== null) {
            $this->auditMedia($connection, 'delete', $id, false, $result['error'], $startedAt);
            $status = $result['error'] === ErrorCodes::MEDIA_NOT_FOUND ? 404 : 403;
            $err = ErrorCodes::error($result['error'], 'Failed to delete media.', $status);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        $this->auditMedia($connection, 'delete', $id, true, null, $startedAt);

        return new WP_REST_Response(['deleted' => true, 'id' => $id]);
    }

    public function getSettings(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::userCan($connection, 'settings.read')) {
            return RequestContext::deny();
        }

        $settings = (new SettingsService())->get();
        $this->auditSettings($connection, 'read', true, null, $startedAt);

        return new WP_REST_Response($settings);
    }

    public function updateSettings(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::userCan($connection, 'settings.update')) {
            return RequestContext::deny();
        }

        $params = $request->get_json_params();
        $result = (new SettingsService())->update(is_array($params) ? $params : []);

        if ($result['error'] !== null) {
            $this->auditSettings($connection, 'update', false, $result['error'], $startedAt);
            $err = ErrorCodes::error($result['error'], 'Failed to update site settings.', 400);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        $this->auditSettings($connection, 'update', true, null, $startedAt);

        return new WP_REST_Response($result['settings']);
    }

    public function listMenus(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->menuResponse($request, 'read');
    }

    public function getMenu(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($denied = $this->requireScope($request, 'appearance.read')) instanceof WP_Error) {
            return $denied;
        }
        $result = (new NavigationService())->get((int) $request->get_param('id'));
        return $result['error'] === null ? new WP_REST_Response($result['menu']) : RequestContext::invalid('Menu not found.');
    }

    public function createMenu(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->menuResponse($request, 'create');
    }
    public function updateMenu(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->menuResponse($request, 'update');
    }
    public function deleteMenu(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        return $this->menuResponse($request, 'delete');
    }

    public function setMenuLocations(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($denied = $this->requireScope($request, 'appearance.update')) instanceof WP_Error) {
            return $denied;
        }
        $params = $request->get_json_params();
        $locations = is_array($params['locations'] ?? null) ? $params['locations'] : [];
        (new NavigationService())->setLocations(array_map('intval', $locations));
        /** @phpstan-ignore-next-line WordPress core navigation function is loaded at runtime. */
        return new WP_REST_Response(['locations' => wp_get_nav_menu_locations()]);
    }

    public function saveMenuItem(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($d = $this->requireScope($request, 'appearance.update')) instanceof WP_Error) {
            return $d;
        }
        $payload = $request->get_json_params();
        $result = (new NavigationService())->saveItem(
            (int) $request->get_param('menu_id'),
            $request->get_param('id') ? (int) $request->get_param('id') : null,
            is_array($payload) ? $payload : [],
        );
        return $result['error'] === null ? new WP_REST_Response($result['item']) : RequestContext::invalid('Invalid navigation menu item.');
    }

    public function deleteMenuItem(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($d = $this->requireScope($request, 'appearance.update')) instanceof WP_Error) {
            return $d;
        }
        return new WP_REST_Response((new NavigationService())->deleteItem((int) $request->get_param('id')));
    }

    public function siteHealth(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($denied = $this->requireScope($request, 'site.health.read')) instanceof WP_Error) {
            return $denied;
        }
        return new WP_REST_Response((new SiteOperationsService())->health());
    }

    public function updates(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($denied = $this->requireScope($request, 'updates.read')) instanceof WP_Error) {
            return $denied;
        }
        return new WP_REST_Response((new SiteOperationsService())->updates());
    }

    public function maintenance(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($denied = $this->requireScope($request, 'site.maintenance')) instanceof WP_Error) {
            return $denied;
        }
        $params = $request->get_json_params();
        return new WP_REST_Response((new SiteOperationsService())->maintenance((bool) ($params['enabled'] ?? false)));
    }

    public function updateCore(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($denied = $this->requireScope($request, 'core.update')) instanceof WP_Error) {
            return $denied;
        }
        $result = (new SiteOperationsService())->updateCore();
        return $result['error'] === null
            ? new WP_REST_Response($result)
            : RequestContext::invalid('No eligible WordPress core update is available.');
    }

    public function listRevisions(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $post = get_post((int) $request->get_param('id'));
        if (! $post instanceof \WP_Post) {
            return RequestContext::invalid('Content not found.');
        }
        $scope = $post->post_type === ContentTypes::PAGE ? 'pages.revisions.read' : 'posts.revisions.read';
        if (($denied = $this->requireScope($request, $scope)) instanceof WP_Error) {
            return $denied;
        }
        $result = (new RevisionService())->list((int) $post->ID);
        return new WP_REST_Response(['items' => $result['items']]);
    }
    public function getRevision(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $result = (new RevisionService())->get((int) $request->get_param('id'));
        if ($result['error'] !== null) {
            return RequestContext::invalid('Revision not found.');
        }
        $parent = get_post((int) $result['revision']['parent_id']);
        $scope = $parent?->post_type === ContentTypes::PAGE ? 'pages.revisions.read' : 'posts.revisions.read';
        if (($denied = $this->requireScope($request, $scope)) instanceof WP_Error) {
            return $denied;
        } return new WP_REST_Response($result['revision']);
    }
    public function restoreRevision(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $revision = (new RevisionService())->get((int) $request->get_param('id'));
        if ($revision['error'] !== null) {
            return RequestContext::invalid('Revision not found.');
        }
        $parent = get_post((int) $revision['revision']['parent_id']);
        $scope = $parent?->post_type === ContentTypes::PAGE ? 'pages.revisions.restore' : 'posts.revisions.restore';
        $connection = $this->requireScope($request, $scope);
        if ($connection instanceof WP_Error || ! $parent instanceof \WP_Post || ! user_can($connection->userId, 'edit_post', $parent->ID)) {
            return $connection instanceof WP_Error ? $connection : RequestContext::deny();
        }
        return new WP_REST_Response((new RevisionService())->restore((int) $request->get_param('id')));
    }
    public function listRedirects(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($d = $this->requireScope($request, 'redirects.read')) instanceof WP_Error) {
            return $d;
        } return new WP_REST_Response((new RedirectService())->list());
    }
    public function notFoundLog(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($d = $this->requireScope($request, 'redirects.read')) instanceof WP_Error) {
            return $d;
        } return new WP_REST_Response((new RedirectService())->notFound());
    }
    public function upsertRedirect(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($d = $this->requireScope($request, 'redirects.update')) instanceof WP_Error) {
            return $d;
        }
        $p = $request->get_json_params();
        $r = (new RedirectService())->upsert(
            (string) ($p['source'] ?? ''),
            (string) ($p['destination'] ?? ''),
            (int) ($p['status'] ?? 301),
        );
        return $r['error'] === null ? new WP_REST_Response($r['redirect']) : RequestContext::invalid('Invalid redirect.');
    }
    public function deleteRedirect(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        if (($d = $this->requireScope($request, 'redirects.update')) instanceof WP_Error) {
            return $d;
        } return new WP_REST_Response((new RedirectService())->delete('/' . ltrim((string) $request->get_param('source'), '/')));
    }

    public function mcpStats(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::userCan($connection, 'site.read')) {
            return RequestContext::deny();
        }

        $result = (new StatsService())->stats($request->get_params());

        (new AuditLogger())->log(
            $connection->id,
            RequestContext::requestId(),
            'read',
            'mcp_stats',
            null,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($result);
    }

    public function mcpLogs(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::userCan($connection, 'site.read')) {
            return RequestContext::deny();
        }

        $result = (new StatsService())->logs($request->get_params());

        (new AuditLogger())->log(
            $connection->id,
            RequestContext::requestId(),
            'read',
            'mcp_logs',
            null,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($result);
    }

    public function getRobots(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::userCan($connection, 'site.read')) {
            return RequestContext::deny();
        }

        $result = (new SeoService())->getRobots();

        (new AuditLogger())->log(
            $connection->id,
            RequestContext::requestId(),
            'read',
            'robots',
            null,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($result);
    }

    public function updateRobots(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::userCan($connection, 'seo.robots.update')) {
            return RequestContext::deny();
        }

        $params = $request->get_json_params();
        $payload = is_array($params) ? $params : [];
        $content = (string) ($payload['content'] ?? '');

        $result = (new SeoService())->updateRobots($content);
        $audit = new AuditLogger();

        if (isset($result['error'])) {
            $audit->log(
                $connection->id,
                RequestContext::requestId(),
                'seo_update',
                'robots',
                null,
                false,
                ['error' => $result['error']],
                $this->durationMs($startedAt),
            );
            $err = ErrorCodes::error((string) $result['error'], 'Failed to update robots.txt.', 500);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        $audit->log(
            $connection->id,
            RequestContext::requestId(),
            'seo_update',
            'robots',
            null,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($result);
    }

    public function seoAudit(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        $id = (int) $request->get_param('id');
        $post = get_post($id);

        if (! $post instanceof \WP_Post) {
            $err = ErrorCodes::error(ErrorCodes::POST_NOT_FOUND, 'Content not found.', 404);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        if (! $this->canReadType($connection, $post->post_type)) {
            return RequestContext::deny();
        }

        $result = (new SeoService())->audit($id);

        (new AuditLogger())->log(
            $connection->id,
            RequestContext::requestId(),
            'read',
            'seo_audit',
            (string) $id,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($result);
    }

    public function getSeoMetadata(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        $id = (int) $request->get_param('id');
        $post = get_post($id);

        if (! $post instanceof \WP_Post) {
            $err = ErrorCodes::error(ErrorCodes::POST_NOT_FOUND, 'Content not found.', 404);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        if (! $this->canReadType($connection, $post->post_type)) {
            return RequestContext::deny();
        }

        $result = (new SeoService())->getSeoMetadata($id);

        (new AuditLogger())->log(
            $connection->id,
            RequestContext::requestId(),
            'read',
            'seo_metadata',
            (string) $id,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($result);
    }

    public function updateSeoMetadata(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        $id = (int) $request->get_param('id');

        if (! $this->canUpdateContentType($connection, $id)) {
            return $this->seoWriteDenied($id);
        }

        $params = $request->get_json_params();
        $payload = is_array($params) ? $params : [];

        $result = (new SeoService())->updateSeoMetadata($id, $payload);
        $audit = new AuditLogger();

        if (isset($result['error'])) {
            $audit->log(
                $connection->id,
                RequestContext::requestId(),
                'seo_update',
                'seo_metadata',
                (string) $id,
                false,
                ['error' => $result['error']],
                $this->durationMs($startedAt),
            );
            $err = ErrorCodes::error((string) $result['error'], 'Failed to update SEO metadata.', 404);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        $audit->log(
            $connection->id,
            RequestContext::requestId(),
            'seo_update',
            'seo_metadata',
            (string) $id,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($result);
    }

    public function seoFix(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        $id = (int) $request->get_param('id');

        if (! $this->canUpdateContentType($connection, $id)) {
            return $this->seoWriteDenied($id);
        }

        $params = $request->get_json_params();
        $payload = is_array($params) ? $params : [];
        $changes = is_array($payload['changes'] ?? null) ? $payload['changes'] : [];

        $result = (new SeoService())->updateSeoMetadata($id, $changes);
        $audit = new AuditLogger();

        if (isset($result['error'])) {
            $audit->log(
                $connection->id,
                RequestContext::requestId(),
                'seo_fix',
                'seo_metadata',
                (string) $id,
                false,
                ['error' => $result['error']],
                $this->durationMs($startedAt),
            );
            $err = ErrorCodes::error((string) $result['error'], 'Failed to apply SEO fix.', 404);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        $audit->log(
            $connection->id,
            RequestContext::requestId(),
            'seo_fix',
            'seo_metadata',
            (string) $id,
            true,
            ['changes' => array_keys($changes)],
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($result);
    }

    private function canUpdateContentType(Connection|WP_Error $connection, int $postId): bool
    {
        if ($connection instanceof WP_Error) {
            return false;
        }

        $post = get_post($postId);

        if (! $post instanceof \WP_Post || ! ContentTypes::isSupported($post->post_type)) {
            return false;
        }

        $type = $post->post_type;
        $scope = $type === ContentTypes::PAGE ? 'pages.update' : 'posts.update';

        return ScopeChecker::canUpdateContent($connection, $type) && ScopeChecker::userCan($connection, $scope);
    }

    private function respondToUserMutation(WP_REST_Request $request, string $scope, string $action): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::userCan($connection, $scope)) {
            return RequestContext::deny();
        }

        $params = $request->get_json_params();
        $payload = array_merge($request->get_params(), is_array($params) ? $params : []);
        $id = (int) $request->get_param('id');

        if (
            ($action === 'create' || $action === 'update')
            && array_key_exists('role', $payload)
            && ! ScopeChecker::userCan($connection, 'users.assign_roles')
        ) {
            return RequestContext::deny();
        }

        if ($action !== 'create' && ! user_can($connection->userId, $action === 'delete' ? 'delete_user' : 'edit_user', $id)) {
            return RequestContext::deny();
        }

        $service = new UserService();
        $result = match ($action) {
            'create' => $service->create($payload),
            'update' => $service->update($id, $payload),
            'delete' => $service->delete($id, isset($payload['reassign']) ? (int) $payload['reassign'] : null),
            default => ['error' => ErrorCodes::INVALID_ARGUMENT],
        };

        if (($result['error'] ?? null) !== null) {
            $status = $result['error'] === ErrorCodes::INVALID_ARGUMENT ? 400 : 403;
            $err = ErrorCodes::error($result['error'], 'Failed to manage user.', $status);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        $resourceId = (string) ($result['user']['id'] ?? $id);
        (new AuditLogger())->log(
            $connection->id,
            RequestContext::requestId(),
            $action,
            'user',
            $resourceId,
            true,
            null,
            $this->durationMs($startedAt),
        );

        return new WP_REST_Response($result['user'] ?? ['deleted' => true]);
    }

    private function seoWriteDenied(int $postId): WP_Error
    {
        $post = get_post($postId);

        if (! $post instanceof \WP_Post) {
            $err = ErrorCodes::error(ErrorCodes::POST_NOT_FOUND, 'Content not found.', 404);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        return RequestContext::deny();
    }

    private function canEmbedMedia(Connection $connection, mixed $mediaId): bool
    {
        $id = (int) $mediaId;

        // A zero ID clears a featured image and does not grant access to another attachment.
        if ($id === 0) {
            return true;
        }

        return $id > 0
            && ScopeChecker::userCan($connection, 'media.embed')
            && get_post_type($id) === 'attachment'
            && user_can($connection->userId, 'edit_post', $id);
    }

    private function requireScope(WP_REST_Request $request, string $scope): Connection|WP_Error
    {
        $connection = $this->connection($request);
        return $connection instanceof WP_Error || ! ScopeChecker::userCan($connection, $scope) ? RequestContext::deny() : $connection;
    }

    private function menuResponse(WP_REST_Request $request, string $action): WP_REST_Response|WP_Error
    {
        $scope = $action === 'read' ? 'appearance.read' : 'appearance.update';
        if (($denied = $this->requireScope($request, $scope)) instanceof WP_Error) {
            return $denied;
        }
        $service = new NavigationService();
        $params = $request->get_json_params();
        $payload = is_array($params) ? $params : [];
        $id = (int) $request->get_param('id');
        $result = match ($action) {
            'read' => ['menu' => $service->list(), 'error' => null],
            'create' => $service->create((string) ($payload['name'] ?? '')),
            'update' => $service->update($id, (string) ($payload['name'] ?? '')),
            'delete' => $service->delete($id),
            default => ['error' => ErrorCodes::INVALID_ARGUMENT],
        };
        if (($result['error'] ?? null) !== null) {
            return RequestContext::invalid('Unable to manage navigation menu.');
        }
        return new WP_REST_Response($result['menu'] ?? ['deleted' => true]);
    }

    /** @param array<string, mixed> $payload */
    private function canEmbedContentMedia(Connection $connection, array $payload): bool
    {
        if (! isset($payload['content']) || ! is_string($payload['content'])) {
            return true;
        }

        $matches = [];
        preg_match_all('/(?:wp-image-|data-id=["\']|"id"\s*:\s*|ids=["\'])(\d+(?:\s*,\s*\d+)*)/', $payload['content'], $matches);

        $ids = [];
        foreach ($matches[1] as $match) {
            foreach (preg_split('/\s*,\s*/', $match) ?: [] as $id) {
                $ids[(int) $id] = true;
            }
        }

        foreach (array_keys($ids) as $id) {
            if (! $this->canEmbedMedia($connection, $id)) {
                return false;
            }
        }

        return true;
    }

    private function auditMedia(Connection $connection, string $action, int $id, bool $success, ?string $error, float $startedAt): void
    {
        (new AuditLogger())->log(
            $connection->id,
            RequestContext::requestId(),
            $action,
            'media',
            (string) $id,
            $success,
            $error === null ? null : ['error' => $error],
            $this->durationMs($startedAt),
        );
    }

    private function auditSettings(Connection $connection, string $action, bool $success, ?string $error, float $startedAt): void
    {
        (new AuditLogger())->log(
            $connection->id,
            RequestContext::requestId(),
            $action,
            'settings',
            null,
            $success,
            $error === null ? null : ['error' => $error],
            $this->durationMs($startedAt),
        );
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function connection(WP_REST_Request $_request): Connection|WP_Error
    {
        $connection = ConnectionAuthenticator::current();

        if ($connection === null) {
            return RequestContext::deny('Not authenticated.');
        }

        return $connection;
    }

    private function canReadType(Connection|WP_Error $connection, string $type): bool
    {
        if ($connection instanceof WP_Error) {
            return false;
        }

        if (! ContentTypes::isSupported($type)) {
            return false;
        }

        $scope = $type === ContentTypes::PAGE ? 'pages.read' : 'posts.read';

        return ScopeChecker::canReadContent($connection, $type)
            && ScopeChecker::userCan($connection, $scope);
    }
}
