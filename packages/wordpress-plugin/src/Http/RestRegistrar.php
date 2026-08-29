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
use JOOservices\WordPressMcp\Services\TaxonomyService;
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
    }

    public function authenticate(WP_REST_Request $request): bool|WP_Error
    {
        RequestContext::reset();
        $result = RequestContext::requireConnection($request);

        return $result instanceof WP_Error ? $result : true;
    }

    public function site(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::userCan($connection, 'site.read')) {
            return RequestContext::deny();
        }

        return new WP_REST_Response([
            'name' => get_bloginfo('name'),
            'url' => home_url('/'),
            'wordpress_version' => get_bloginfo('version'),
            'timezone' => wp_timezone_string(),
            'supported_capabilities' => $connection->scopes,
        ]);
    }

    public function searchContent(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        $type = sanitize_key((string) ($request->get_param('type') ?? 'post'));

        if (! $this->canReadType($connection, $type)) {
            return RequestContext::deny();
        }

        $service = new ContentService();

        return new WP_REST_Response($service->search($request->get_params()));
    }

    public function getContent(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        $id = (int) $request->get_param('id');
        $post = get_post($id);
        $type = $post instanceof \WP_Post ? $post->post_type : 'post';

        if (! $this->canReadType($connection, $type)) {
            return RequestContext::deny();
        }

        $service = new ContentService();
        $item = $service->get($id);

        if ($item === null) {
            $err = ErrorCodes::error(ErrorCodes::POST_NOT_FOUND, 'Content not found.', 404);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        return new WP_REST_Response($item);
    }

    public function createContent(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        $type = sanitize_key((string) ($request->get_param('type') ?? 'post'));
        $scope = $type === 'page' ? 'pages.create' : 'posts.create';

        if (! ScopeChecker::canCreateContent($connection, $type) || ! ScopeChecker::userCan($connection, $scope)) {
            return RequestContext::deny();
        }

        $canPublish = ScopeChecker::canPublishContent($connection, $type)
            && ScopeChecker::userCan($connection, $type === 'page' ? 'pages.publish' : 'posts.publish');

        $params = $request->get_json_params();
        $payload = is_array($params) ? $params : [];

        $service = new ContentService();
        $result = $service->create($payload, $canPublish);
        $audit = new AuditLogger();

        if ($result['error'] !== null) {
            $audit->log($connection->id, RequestContext::requestId(), 'create', $type, null, false, ['error' => $result['error']]);
            $err = ErrorCodes::error($result['error'], 'Failed to create content.', 403);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        $audit->log($connection->id, RequestContext::requestId(), 'create', $type, (string) ($result['post']['id'] ?? ''), true);

        return new WP_REST_Response($result['post'], 201);
    }

    public function updateContent(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        $id = (int) $request->get_param('id');
        $post = get_post($id);
        $type = $post instanceof \WP_Post ? $post->post_type : 'post';
        $scope = $type === 'page' ? 'pages.update' : 'posts.update';

        if (! ScopeChecker::canUpdateContent($connection, $type) || ! ScopeChecker::userCan($connection, $scope)) {
            return RequestContext::deny();
        }

        $canPublish = ScopeChecker::canPublishContent($connection, $type)
            && ScopeChecker::userCan($connection, $type === 'page' ? 'pages.publish' : 'posts.publish');

        $params = $request->get_json_params();
        $payload = is_array($params) ? $params : [];

        $service = new ContentService();
        $result = $service->update($id, $payload, $canPublish);
        $audit = new AuditLogger();

        if ($result['error'] !== null) {
            $audit->log($connection->id, RequestContext::requestId(), 'update', $type, (string) $id, false, ['error' => $result['error']]);
            $status = $result['error'] === ErrorCodes::POST_NOT_FOUND ? 404 : 403;
            $err = ErrorCodes::error($result['error'], 'Failed to update content.', $status);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        $audit->log($connection->id, RequestContext::requestId(), 'update', $type, (string) $id, true);

        return new WP_REST_Response($result['post']);
    }

    public function deleteContent(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        $id = (int) $request->get_param('id');
        $post = get_post($id);
        $type = $post instanceof \WP_Post ? $post->post_type : 'post';
        $scope = $type === 'page' ? 'pages.delete' : 'posts.delete';

        if (! ScopeChecker::canDeleteContent($connection, $type) || ! ScopeChecker::userCan($connection, $scope)) {
            return RequestContext::deny();
        }

        $force = filter_var($request->get_param('force') ?? false, FILTER_VALIDATE_BOOLEAN);
        $service = new ContentService();
        $result = $service->delete($id, $force);
        $audit = new AuditLogger();

        if ($result['error'] !== null) {
            $audit->log($connection->id, RequestContext::requestId(), 'delete', $type, (string) $id, false, ['error' => $result['error']]);
            $status = $result['error'] === ErrorCodes::POST_NOT_FOUND ? 404 : 403;
            $err = ErrorCodes::error($result['error'], 'Failed to delete content.', $status);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        $audit->log($connection->id, RequestContext::requestId(), 'delete', $type, (string) $id, true, ['force' => $force]);

        return new WP_REST_Response(['deleted' => true, 'id' => $id, 'force' => $force]);
    }

    public function listComments(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::userCan($connection, 'comments.read')) {
            return RequestContext::deny();
        }

        return new WP_REST_Response((new CommentService())->list($request->get_params()));
    }

    public function getComment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::userCan($connection, 'comments.read')) {
            return RequestContext::deny();
        }

        $item = (new CommentService())->get((int) $request->get_param('id'));

        if ($item === null) {
            $err = ErrorCodes::error(ErrorCodes::COMMENT_NOT_FOUND, 'Comment not found.', 404);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        return new WP_REST_Response($item);
    }

    public function moderateComment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
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
            $audit->log($connection->id, RequestContext::requestId(), 'moderate', 'comment', (string) $request->get_param('id'), false);

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
        );

        return new WP_REST_Response($result['comment']);
    }

    public function listTerms(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::userCan($connection, 'posts.read')) {
            return RequestContext::deny();
        }

        return new WP_REST_Response(['items' => (new TaxonomyService())->list($request->get_params())]);
    }

    public function listMedia(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::userCan($connection, 'media.read')) {
            return RequestContext::deny();
        }

        return new WP_REST_Response((new MediaService())->list($request->get_params()));
    }

    public function uploadMedia(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
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
            $audit->log($connection->id, RequestContext::requestId(), 'upload', 'media', null, false, ['error' => $result['error']]);
            $status = $result['error'] === ErrorCodes::INVALID_ARGUMENT ? 400 : 403;
            $err = ErrorCodes::error($result['error'], 'Failed to upload media.', $status);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        $mediaId = (string) ($result['media']['id'] ?? '');
        $audit->log($connection->id, RequestContext::requestId(), 'upload', 'media', $mediaId, true);

        return new WP_REST_Response($result['media'], 201);
    }

    public function getMedia(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $connection = $this->connection($request);

        if ($connection instanceof WP_Error) {
            return $connection;
        }

        if (! ScopeChecker::userCan($connection, 'media.read')) {
            return RequestContext::deny();
        }

        $item = (new MediaService())->get((int) $request->get_param('id'));

        if ($item === null) {
            $err = ErrorCodes::error(ErrorCodes::MEDIA_NOT_FOUND, 'Media not found.', 404);

            return new WP_Error($err['code'], $err['message'], $err['data']);
        }

        return new WP_REST_Response($item);
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

        $scope = $type === 'page' ? 'pages.read' : 'posts.read';

        return ScopeChecker::canReadContent($connection, $type)
            && ScopeChecker::userCan($connection, $scope);
    }
}
