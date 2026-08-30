# Upgrading to v1.3.0

v1.3.0 expands the connector from content operations to scoped site management. Upgrade the WordPress plugin and the MCP server together.

## Before upgrading

1. Back up the WordPress database and `wp-content`.
2. Record the current plugin version, enabled MCP tools, and every connection's scopes.
3. Ensure the connection user has only the WordPress capabilities it needs. Connector scopes never elevate that user.
4. Plan a maintenance window if you intend to enable maintenance mode or use core/plugin/theme update tools.

## Upgrade the WordPress plugin

1. Deactivate the old plugin only if your deployment method requires it; do not delete the plugin data.
2. Replace `wp-content/plugins/wordpress-chatgpt` with the v1.3.0 release archive.
3. From that plugin directory, run `composer install --no-dev --optimize-autoloader` with the server's PHP 8.3 runtime.
4. Activate the plugin. Its database upgrade hook runs automatically.
5. In wp-admin, open **JOOservices → MCP**. Verify existing connections and create a new one only if you need to rotate a token.

## Upgrade the MCP server

1. Deploy the v1.3.0 MCP image/code with Node 24.
2. Preserve `WORDPRESS_CONNECTION_TOKEN` or `WORDPRESS_SITES`; do not put tokens in source control.
3. Rebuild/restart: `make prod-up` (or your equivalent Docker deployment).
4. Verify `/health`, then reconnect ChatGPT if its OAuth client/session was removed during deployment.

## Scope migration

`site.manage` is no longer the permission for plugin management. Review each connection in wp-admin and explicitly select the smallest set needed:

- featured images, post images, and galleries: `media.embed`
- plugin actions: `plugins.read`, `plugins.install`, `plugins.activate`, `plugins.deactivate`, `plugins.update`, `plugins.delete`
- role changes: `users.assign_roles` in addition to user update/create
- robots: `seo.robots.update`
- health/updates/maintenance/core update: `site.health.read`, `updates.read`, `site.maintenance`, `core.update`
- revisions: matching `posts.revisions.*` or `pages.revisions.*`
- redirects/404 log: `redirects.read`, `redirects.update`

Existing tokens remain valid, but they do not automatically gain new scopes. This is intentional.

## Validate after upgrade

1. Call `wordpress_get_site` and confirm `supported_capabilities`.
2. Test a read-only tool first.
3. Test one draft update and featured-image assignment using `media.embed`.
4. Check **JOOservices → Audit Log** for the request.
5. Enable high-risk scopes only for dedicated maintenance connections. Every high-risk MCP tool still needs `confirm: true`.

## Rollback

1. Disable maintenance mode if it was enabled.
2. Restore the prior plugin archive and MCP deployment image.
3. Restore the WordPress database/files backup only if the incident requires it.
4. Revoke and replace any connection token that might have been exposed.

The plugin's connection/audit tables are retained across normal plugin upgrades and downgrades.
