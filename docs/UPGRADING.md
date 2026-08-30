# Upgrading to v1.4.0

v1.4.0 adds post templates, a verified media upload pipeline, and consolidates the MCP tool surface from 50 to 42 tools. Upgrade the WordPress plugin and the MCP server together.

## Before upgrading

1. Back up the WordPress database and `wp-content`.
2. Record the current plugin version, enabled MCP tools, and every connection's scopes.
3. Inventory ChatGPT prompts, automations, or custom clients that call MCP tools by name — several tools were renamed or merged (see below).
4. Ensure the connection user has only the WordPress capabilities it needs. Connector scopes never elevate that user.

## Upgrade the WordPress plugin

1. Deactivate the old plugin only if your deployment method requires it; do not delete the plugin data.
2. Replace `wp-content/plugins/wordpress-chatgpt` with the v1.4.0 release archive (`make plugin-release` or the GitHub release asset).
3. From that plugin directory, run `composer install --no-dev --optimize-autoloader` with the server's PHP 8.3 runtime.
4. Activate the plugin. Its database upgrade hook runs automatically.
5. In wp-admin, open **JOOservices → MCP**. Verify existing connections. Optionally create **Post Templates** under the new admin menu for structured content workflows.

## Upgrade the MCP server

1. Deploy the v1.4.0 MCP image/code with Node 24.
2. Preserve `WORDPRESS_CONNECTION_TOKEN` or `WORDPRESS_SITES`; do not put tokens in source control.
3. Rebuild/restart: `make prod-up` (or your equivalent Docker deployment).
4. Verify `/health`, then reconnect ChatGPT if its OAuth client/session was removed during deployment.
5. If you use `MCP_ENABLED_TOOLS`, add `wordpress_list_post_templates` when post templates are needed, and remove any allowlist entries for tools that no longer exist (see migration table).

## MCP tool migration (v1.3.0 → v1.4.0)

| Removed / old tool | Replacement |
| --- | --- |
| `wordpress_preview_content_update` | `wordpress_update_content` with `preview: true` |
| `wordpress_get_site_limits` | `wordpress_get_site` (limits are in the response) |
| `wordpress_get_site_settings` | `wordpress_get_site` (when connection has `settings.read`) |
| `wordpress_get_mcp_stats` | `wordpress_get_mcp_activity` with `mode: stats` |
| `wordpress_get_mcp_request_log` | `wordpress_get_mcp_activity` with `mode: logs` |
| `wordpress_activate_plugin` / `wordpress_deactivate_plugin` / `wordpress_update_plugin` / `wordpress_delete_plugin` | `wordpress_manage_plugin` with `action: state\|update\|delete` |
| `wordpress_activate_theme` / `wordpress_update_theme` / `wordpress_delete_theme` | `wordpress_manage_theme` with `action: activate\|update\|delete` |
| `wordpress_seo_audit` / `wordpress_get_seo_metadata` | `wordpress_get_seo` (`audit: true` for audit) |
| `wordpress_update_seo_metadata` / `wordpress_seo_fix` | `wordpress_update_seo` (`apply_fixes: true` for batch fixes) |
| `wordpress_list_redirects` / `wordpress_get_404_log` / `wordpress_upsert_redirect` / `wordpress_delete_redirect` | `wordpress_get_redirects` / `wordpress_manage_redirect` |

New in v1.4.0:

| Tool | Purpose |
| --- | --- |
| `wordpress_list_post_templates` | List admin-defined templates before create |
| `wordpress_create_content` | Now accepts `template_id`, `template_slug`, or `use_template` |

## Validate after upgrade

1. Call `wordpress_get_site` and confirm limits, theme summary, and `supported_capabilities`.
2. Test a read-only tool first.
3. Test `wordpress_update_content` with `preview: true` if you relied on the old preview tool.
4. Upload a small image and confirm the response includes `verification.passed: true`.
5. Check **JOOservices → Audit Log** for the request.

## Rollback

1. Disable maintenance mode if it was enabled.
2. Restore the prior plugin archive and MCP deployment image.
3. Restore the WordPress database/files backup only if the incident requires it.
4. Revoke and replace any connection token that might have been exposed.

The plugin's connection/audit tables and post templates are retained across normal plugin upgrades and downgrades.

---

# Upgrading to v1.3.0

v1.3.0 expands the connector from content operations to scoped site management. If you are already on v1.3.0, skip to the v1.4.0 section above.

## Before upgrading from v1.2.x

1. Back up the WordPress database and `wp-content`.
2. Record the current plugin version, enabled MCP tools, and every connection's scopes.
3. Ensure the connection user has only the WordPress capabilities it needs. Connector scopes never elevate that user.
4. Plan a maintenance window if you intend to enable maintenance mode or use core/plugin/theme update tools.

## Scope migration (v1.2.x → v1.3.0)

`site.manage` is no longer the permission for plugin management. Review each connection in wp-admin and explicitly select the smallest set needed:

- featured images, post images, and galleries: `media.embed`
- plugin actions: `plugins.read`, `plugins.install`, `plugins.activate`, `plugins.deactivate`, `plugins.update`, `plugins.delete`
- role changes: `users.assign_roles` in addition to user update/create
- robots: `seo.robots.update`
- health/updates/maintenance/core update: `site.health.read`, `updates.read`, `site.maintenance`, `core.update`
- revisions: matching `posts.revisions.*` or `pages.revisions.*`
- redirects/404 log: `redirects.read`, `redirects.update`

Existing tokens remain valid, but they do not automatically gain new scopes. This is intentional.
