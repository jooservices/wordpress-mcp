<?php

declare(strict_types=1);

namespace JOOservices\WordPressMcp\Http;

use JOOservices\WordPressMcp\Audit\AuditLogger;
use JOOservices\WordPressMcp\Auth\ConnectionAuthenticator;
use JOOservices\WordPressMcp\Auth\ScopeChecker;
use JOOservices\WordPressMcp\Models\Connection;
use JOOservices\WordPressMcp\Services\CommentService;
use JOOservices\WordPressMcp\Services\ContentService;
use JOOservices\WordPressMcp\Services\MediaService;
use JOOservices\WordPressMcp\Services\SeoService;
use JOOservices\WordPressMcp\Services\StatsService;
use JOOservices\WordPressMcp\Services\TaxonomyService;
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

        register_rest_route(self::NAMESPACE, '/site/limits', [
            'methods' => 'GET',
            'callback' => [$this, 'siteLimits'],
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

        register_rest_route(self::NAMESPACE, '/media/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getMedia'],
            'permission_callback' => [$this, 'authenticate'],
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

        $response = new WP_REST_Response([
            'name' => get_bloginfo('name'),
            'url' => home_url('/'),
            'wordpress_version' => get_bloginfo('version'),
            'timezone' => wp_timezone_string(),
            'supported_capabilities' => $connection->scopes,
        ]);

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

    /**
     * Reports the site's real PHP/WordPress size limits so MCP can stop
     * pre-rejecting uploads/content at an arbitrary body-size cap — WordPress
     * (and its host's php.ini) is the authoritative limit, not the MCP server.
     */
    public function siteLimits(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $startedAt = microtime(true);
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::userCan($connection, 'site.read')) {
            return RequestContext::deny();
        }

        $response = new WP_REST_Response([
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'memory_limit' => (string) ini_get('memory_limit'),
            'max_execution_time' => (string) ini_get('max_execution_time'),
            'wp_max_upload_size_bytes' => (int) wp_max_upload_size(),
        ]);

        (new AuditLogger())->log(
            $connection->id,
            RequestContext::requestId(),
            'read',
            'site_limits',
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
                ['error' => $result['error']],
                $this->durationMs($startedAt),
            );
            $status = $result['error'] === ErrorCodes::INVALID_ARGUMENT ? 400 : 403;
            $err = ErrorCodes::error($result['error'], 'Failed to upload media.', $status);

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
        $item = (new MediaService())->get($id);
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

        if (! ScopeChecker::userCan($connection, 'site.manage')) {
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

    private function seoWriteDenied(int $postId): WP_Error
    {
        $post = get_post($postId);

        if (! $post instanceof \WP_Post) {
            $err = ErrorCodes::error(ErrorCodes::POST_NOT_FOUND, 'Content not found.', 404);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        return RequestContext::deny();
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
